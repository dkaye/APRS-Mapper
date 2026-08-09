/// Haptic, tone, then speech — the watch's whole output path.
///
/// Order matters. The haptic goes first and always, because it is the only signal
/// that survives Silent Mode, a wrist turned away, and no audio route at all. The
/// tone and the speech are best-effort on top of it: with no Bluetooth device
/// connected watchOS routes `.playback` to the built-in speaker, which Silent Mode
/// and the cover-to-mute gesture both kill, and which is quiet in the sort of noisy
/// environment this app exists for.
///
/// Announcements are serialized. Two messages a second apart must not produce
/// "Message from A / Message from B / text of A / text of B" — the same reasoning as
/// the phone's chained utterances in messaging_screen.dart.
import AVFoundation
import Foundation
import WatchKit

@MainActor
final class Announcer {
  static let shared = Announcer()

  /// One tone per this window however many messages land, so a burst does not
  /// become a machine-gun.
  private static let minToneInterval: TimeInterval = 2

  /// Above this many at once we summarize instead of reading everything. Nobody
  /// wants a twenty-message backlog read to them.
  private static let summarizeAbove = 3

  private let synth = AVSpeechSynthesizer()
  private let speechDelegate = SpeechDelegate()
  private var player: AVAudioPlayer?

  private var queue: [Announcement] = []
  private var draining = false
  private var lastToneAt: Date?

  private struct Announcement {
    let doubleHaptic: Bool
    let utterances: [String]
    /// Receipts get no alert tone: they answer something the user just did, rather
    /// than interrupting them with something new, and a tone per state change would
    /// mean three chimes for every reply.
    var tone = true
  }

  private init() {
    synth.delegate = speechDelegate
    if let url = Bundle.main.url(forResource: "message", withExtension: "wav") {
      player = try? AVAudioPlayer(contentsOf: url)
      player?.prepareToPlay()
    }
  }

  // ── entry point ─────────────────────────────────────────────────────────────

  func enqueue(_ messages: [WatchMessage], speak: Bool) {
    guard !messages.isEmpty else { return }
    let ordered = messages.sorted { $0.id < $1.id }

    // A net-wide call gets a distinct double buzz so the wrist alone tells the
    // operator whether something was addressed to them.
    let doubleHaptic = ordered.contains(where: \.broadcast)

    var utterances: [String] = []
    if speak {
      if ordered.count > Self.summarizeAbove {
        let newest = ordered.suffix(2)
        utterances.append("\(ordered.count) new messages.")
        utterances.append(contentsOf: newest.flatMap(Self.phrases))
        let rest = ordered.count - newest.count
        if rest > 0 { utterances.append("and \(rest) more.") }
      } else {
        utterances = ordered.flatMap(Self.phrases)
      }
    }

    queue.append(Announcement(doubleHaptic: doubleHaptic, utterances: utterances))
    drain()
  }

  /// Confirms the fate of a reply the user just spoke: sent, delivered, read.
  ///
  /// Announced aloud because the whole point of talking into the wrist is that the
  /// operator is not looking at it — a checkmark they never see confirms nothing.
  /// The wording matches the phone's ack label, including the "N of M" form for a
  /// group, so the two devices never disagree about what "delivered" means.
  func announceReceipt(stage: String, count: Int, total: Int, speak: Bool) {
    let phrase: String
    switch stage {
    case "sent":
      phrase = "Message sent."
    case "delivered":
      phrase = total > 1 ? "Message delivered to \(count) of \(total)." : "Message delivered."
    case "read":
      phrase = total > 1 ? "Message read by \(count) of \(total)." : "Message read."
    default:
      return
    }
    WKInterfaceDevice.current().play(stage == "read" ? .success : .click)
    guard speak else { return } // haptic still lands with read-aloud off
    queue.append(Announcement(doubleHaptic: false, utterances: [phrase], tone: false))
    drain()
  }

  private static func phrases(for m: WatchMessage) -> [String] {
    [m.announcementPhrase, m.bodyPhrase].filter { !$0.isEmpty }
  }

  func stop() {
    queue.removeAll()
    synth.stopSpeaking(at: .immediate)
    player?.stop()
    deactivateAudio()
  }

  // ── the serial drain ────────────────────────────────────────────────────────

  private func drain() {
    guard !draining else { return }
    draining = true
    Task { [weak self] in
      guard let self else { return }
      while !queue.isEmpty {
        let next = queue.removeFirst()
        await play(next)
      }
      deactivateAudio()
      draining = false
    }
  }

  private func play(_ a: Announcement) async {
    // Receipts have already buzzed with a haptic that suits their meaning; a second
    // generic notification buzz would just make a confirmation feel like new traffic.
    if a.tone { haptic(double: a.doubleHaptic) }

    let wantsAudio = (a.tone && shouldPlayTone()) || !a.utterances.isEmpty
    let ready = wantsAudio ? await activateAudio() : false
    AppState.shared.audioUnavailable = wantsAudio && !ready
    guard ready else { return } // haptic already fired; that is the guaranteed part

    if a.tone, shouldPlayTone(consume: true), let p = player {
      p.currentTime = 0
      p.play()
      try? await Task.sleep(nanoseconds: UInt64(min(p.duration, 2.0) * 1_000_000_000))
    }

    for (index, text) in a.utterances.enumerated() {
      // The same 500 ms the phone leaves between "Message from X." and the text
      // (messaging_screen.dart _kSpeakGap), so both devices sound like one app.
      if index > 0 { try? await Task.sleep(nanoseconds: 500_000_000) }
      await speak(text)
    }
  }

  private func haptic(double: Bool) {
    let device = WKInterfaceDevice.current()
    device.play(.notification)
    guard double else { return }
    Task {
      try? await Task.sleep(nanoseconds: 300_000_000)
      device.play(.notification)
    }
  }

  private func shouldPlayTone(consume: Bool = false) -> Bool {
    if let last = lastToneAt, Date().timeIntervalSince(last) < Self.minToneInterval {
      return false
    }
    if consume { lastToneAt = Date() }
    return true
  }

  // ── audio session ───────────────────────────────────────────────────────────

  /// watchOS activates its audio session asynchronously and can refuse when there
  /// is no route available, so this is a request rather than a setting.
  private func activateAudio() async -> Bool {
    let session = AVAudioSession.sharedInstance()
    do {
      try session.setCategory(.playback, mode: .spokenAudio, options: [.duckOthers])
    } catch {
      return false
    }
    return await withCheckedContinuation { continuation in
      session.activate(options: []) { activated, _ in
        continuation.resume(returning: activated)
      }
    }
  }

  /// Hand the route back so ducked navigation or music comes up again.
  private func deactivateAudio() {
    try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
  }

  private func speak(_ text: String) async {
    guard !text.isEmpty else { return }
    let utterance = AVSpeechUtterance(string: text)
    utterance.voice = AVSpeechSynthesisVoice(language: "en-US")
    // The phone uses flutter_tts rate 0.5, which lands near the platform default;
    // a touch under keeps the two devices sounding like the same app.
    utterance.rate = AVSpeechUtteranceDefaultSpeechRate * 0.9
    await speechDelegate.speak(utterance, on: synth)
  }
}

/// Bridges AVSpeechSynthesizer's delegate callbacks to async/await so the drain can
/// wait for one utterance before starting the next.
///
/// Every path is watchdogged. If the synthesizer never reports back — no audio route,
/// a route yanked mid-sentence, a session another app has taken — the continuation
/// would never resume, and because the drain is serial that would wedge every future
/// announcement for the life of the process. A wedged announcer is indistinguishable
/// from a dead app during a net, so a missed callback has to degrade to a missed
/// sentence rather than a missed shift.
@MainActor
private final class SpeechDelegate: NSObject, AVSpeechSynthesizerDelegate {
  private var continuation: CheckedContinuation<Void, Never>?
  private var watchdog: Task<Void, Never>?

  func speak(_ utterance: AVSpeechUtterance, on synth: AVSpeechSynthesizer) async {
    await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in
      continuation = c
      watchdog = Task { [weak self] in
        let limit = Self.expectedDuration(of: utterance.speechString)
        try? await Task.sleep(nanoseconds: UInt64(limit * 1_000_000_000))
        guard !Task.isCancelled else { return }
        synth.stopSpeaking(at: .immediate)
        self?.finish()
      }
      synth.speak(utterance)
    }
  }

  /// Generous upper bound: roughly two words a second plus a fixed allowance, so a
  /// normal sentence never trips it and a stuck one does not hang around.
  private static func expectedDuration(of text: String) -> TimeInterval {
    let words = max(1, text.split(separator: " ").count)
    return min(45, 5 + Double(words) / 2.0)
  }

  private func finish() {
    watchdog?.cancel()
    watchdog = nil
    continuation?.resume()
    continuation = nil
  }

  nonisolated func speechSynthesizer(_ s: AVSpeechSynthesizer, didFinish utterance: AVSpeechUtterance) {
    Task { @MainActor in finish() }
  }

  nonisolated func speechSynthesizer(_ s: AVSpeechSynthesizer, didCancel utterance: AVSpeechUtterance) {
    Task { @MainActor in finish() }
  }
}
