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
/// "From A / From B / text of A / text of B" — the same reasoning as
/// the phone's chained utterances in messaging_screen.dart.
import AVFoundation
import Foundation
import Observation
import WatchKit

@Observable
@MainActor
final class Announcer {
  static let shared = Announcer()

  /// One tone per this window however many messages land, so a burst does not
  /// become a machine-gun.
  private static let minToneInterval: TimeInterval = 2

  /// How old a message may be, by the time it was SENT, before announcing it does more
  /// harm than good. The same five minutes the phone uses, deliberately — one number
  /// that can be explained in a sentence: you will not hear anything older than this.
  ///
  /// This replaces a summarize-above-three rule, which counted the backlog instead of
  /// dating it and so got both cases wrong: three stale messages were read out in full,
  /// and four fresh ones were reduced to a count.
  static let maxAge: TimeInterval = 300

  /// Characters of speech per second, for the countdown on the Stop control. Rough on
  /// purpose — it exists to answer "wait, or stop it", not to be a clock.
  private static let charsPerSecond = 14.0

  /// The silence after "From Dirck." — the name and the message are two separate facts
  /// and running them together loses both halves. The same 500 ms the phone leaves
  /// (`_kSpeakGap` in app/lib/speaker.dart), so every device sounds like one app.
  private static let announceGap: TimeInterval = 0.5

  /// The silence between one sentence of a message and the next. Half the gap above,
  /// and deliberately: two sentences of one message are the same fact continuing, and
  /// the voice already pauses at a period on its own — a further 500 ms on top of that
  /// reads as the speaker having lost their place rather than as punctuation.
  private static let sentenceGap: TimeInterval = 0.25

  /// What is left to say. Read by the Stop control, which is the only way to interrupt
  /// an announcement the automatic rules judged worth making.
  private(set) var pendingCount = 0
  private(set) var pendingSeconds = 0

  /// Result of the dimmed-screen audio test — see runDimmedAudioTest().
  private(set) var dimTestResult: String?
  private(set) var dimTestRunning = false

  private let synth = AVSpeechSynthesizer()
  private let speechDelegate = SpeechDelegate()
  private var player: AVAudioPlayer?

  private var queue: [Announcement] = []
  /// What is being spoken right now. Held separately because it has already been taken
  /// off the queue: counting it but not its duration is why the button read
  /// "Speaking · 0s" for every single announcement — the one case where the queue is
  /// empty and something is still being said, which is also the commonest case.
  private var current: Announcement?
  private var draining = false
  private var lastToneAt: Date?

  /// One thing to say and the silence before saying it.
  ///
  /// The gap travels with the utterance rather than being a rule inside the drain,
  /// because the boundaries within one announcement do not all mean the same thing:
  /// after the sender's name it is half a second, between sentences a quarter. A drain
  /// that decided by index could not tell the two apart once a message with no sender
  /// label shifted everything up by one.
  private struct Utterance {
    let text: String
    let gap: TimeInterval
    init(_ text: String, gap: TimeInterval = 0) {
      self.text = text
      self.gap = gap
    }
  }

  private struct Announcement {
    let doubleHaptic: Bool
    let utterances: [Utterance]
    /// When the thing being announced was SENT, for the age rule. Zero means "not a
    /// message" — a receipt or a status line, which is about something the operator
    /// just did and is never stale.
    var ts: TimeInterval = 0
    /// The gaps are counted, not approximated by a flat half second as they were when
    /// there was only ever one of them. A four-sentence message is a second of silence
    /// on its own, and the Stop control's countdown is the only thing telling an
    /// operator whether to wait it out.
    var estSeconds: Double {
      utterances.reduce(0) {
        $0 + Double($1.text.count) / Announcer.charsPerSecond + $1.gap
      }
    }
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

  func enqueue(_ messages: [WatchMessage]) {
    guard !messages.isEmpty else { return }

    // Anything older than the window is not announced at all. After the wrist has been
    // out of range for an hour, everything arrives at once and every one of them is
    // "new" — dating them is the only test that distinguishes a message worth
    // interrupting somebody for from a backlog worth reading on screen. The messages
    // themselves are kept and listed; only the announcement is dropped.
    let now = Date().timeIntervalSince1970
    let fresh = messages
      .filter { now - TimeInterval($0.ts) <= Self.maxAge }
      .sorted { $0.id < $1.id }
    guard !fresh.isEmpty else { return }

    // A net-wide call gets a distinct double buzz so the wrist alone tells the
    // operator whether something was addressed to them.
    let doubleHaptic = fresh.contains(where: \.broadcast)

    // One announcement per message rather than one for the batch, so the Stop control
    // can show a true count and stopping takes effect at the next message rather than at
    // the end of a single long utterance list.
    for m in fresh {
      queue.append(Announcement(doubleHaptic: doubleHaptic && m.broadcast,
                                utterances: Self.phrases(for: m),
                                ts: TimeInterval(m.ts)))
    }
    publish()
    drain()
  }

  /// Keeps the Stop control's count and countdown current. Called wherever the queue
  /// changes — appending, draining, or being emptied.
  private func publish() {
    let all = (current.map { [$0] } ?? []) + queue
    pendingCount = all.count
    pendingSeconds = Int(all.reduce(0) { $0 + $1.estSeconds }.rounded())
  }

  /// Confirms a reply went out, optionally repeating the words.
  ///
  /// The confirmation itself is not optional — it is the first of the three states an
  /// operator tracks, alongside delivered and read. Only whether it carries the text
  /// is a preference: repeating it is the one transcription check that works with
  /// eyes on the road, and it is also more airtime during a busy net.
  ///
  /// No tone either way: this answers something the operator just did rather than
  /// interrupting them with something new.
  func announceSent(_ text: String, repeatingWords: Bool) {
    let phrase = repeatingWords && !text.isEmpty ? "Sent. \(text)" : "Message sent."
    queue.append(Announcement(doubleHaptic: false, utterances: [Utterance(phrase)], tone: false))
    drain()
  }

  /// Confirms the fate of a reply the user just spoke: sent, delivered, read.
  ///
  /// Announced aloud because the whole point of talking into the wrist is that the
  /// operator is not looking at it — a checkmark they never see confirms nothing.
  /// The wording matches the phone's ack label, including the "N of M" form for a
  /// group, so the two devices never disagree about what "delivered" means.
  func announceReceipt(stage: String, count: Int, total: Int) {
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
    queue.append(Announcement(doubleHaptic: false, utterances: [Utterance(phrase)], tone: false))
    drain()
  }

  /// The announcement, then the message one sentence at a time.
  ///
  /// One utterance per sentence, so the silence between them is real rather than
  /// whatever prosody the voice happens to put at a period. The first body sentence
  /// follows the sender's name and takes the longer gap; the rest follow each other
  /// and take the shorter one. With no sender label there is no announcement, and the
  /// first sentence correctly takes no gap at all.
  private static func phrases(for m: WatchMessage) -> [Utterance] {
    var out: [Utterance] = []
    if !m.announcementPhrase.isEmpty {
      out.append(Utterance(m.announcementPhrase))
    }
    for (i, sentence) in sentences(m.bodyPhrase).enumerated() {
      let gap = out.isEmpty ? 0 : (i == 0 ? announceGap : sentenceGap)
      out.append(Utterance(sentence, gap: gap))
    }
    return out
  }

  /// Where one sentence ends and the next begins: terminal punctuation, any closing
  /// quote or bracket after it, then whitespace.
  ///
  /// The two characters required in front of the punctuation are what keep initials
  /// together — "J. Kaye" is one phrase, not two. Requiring whitespace after it does
  /// the same for decimals, so "146.520" is never split down the middle, which matters
  /// on a channel where that is most of what gets said. An abbreviation ("Mt. Tam")
  /// does split, and is the accepted cost: a quarter second in the wrong place.
  private static let sentenceEnd = try? NSRegularExpression(
    pattern: "([0-9A-Za-z]{2}[.!?]+[\"\'\u{201D}\u{2019})\\]]*)\\s+")

  /// One message as the phrases it should be spoken in, empty if there is nothing to
  /// say. Never returns a fragment that is only whitespace.
  ///
  /// Scanned rather than split on the pattern, because the punctuation belongs to the
  /// sentence it ends: cut after group 1 and resume after the whitespace, so "Go ahead."
  /// keeps its period and the voice keeps the pause it already makes there.
  ///
  /// The same loop is written three more times — `splitSentences` in utils.js and in
  /// app/lib/speaker.dart, and in the Wear Announcer.kt — so all four devices break a
  /// message in the same places. Tested in map/tests/js/utils.test.js.
  static func sentences(_ text: String) -> [String] {
    let whole = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !whole.isEmpty else { return [] }
    guard let re = sentenceEnd else { return [whole] }
    let ns = whole as NSString
    var out: [String] = []
    var start = 0
    re.enumerateMatches(in: whole, range: NSRange(location: 0, length: ns.length)) { m, _, _ in
      guard let m else { return }
      let cut = m.range(at: 1).location + m.range(at: 1).length
      let piece = ns.substring(with: NSRange(location: start, length: cut - start))
        .trimmingCharacters(in: .whitespacesAndNewlines)
      if !piece.isEmpty { out.append(piece) }
      start = m.range.location + m.range.length
    }
    if start < ns.length {
      let piece = ns.substring(from: start)
        .trimmingCharacters(in: .whitespacesAndNewlines)
      if !piece.isEmpty { out.append(piece) }
    }
    return out.isEmpty ? [whole] : out
  }

  func stop() {
    queue.removeAll()
    current = nil
    publish()
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
        // Re-checked here, not only on the way in: an announcement can sit behind a
        // long one and go stale while it waits, and saying it then is the same mistake
        // as saying it after an outage.
        //
        // Before `current` is set, not after. Setting it first and then skipping would
        // leave the discarded announcement showing on the Stop button until something
        // else replaced it — and if it were the last one, for good.
        if next.ts > 0, Date().timeIntervalSince1970 - next.ts > Self.maxAge {
          publish()
          continue
        }
        current = next
        publish()
        await play(next)
        current = nil
        publish()
      }
      deactivateAudio()
      draining = false
      publish()
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

    for u in a.utterances {
      // The silence each utterance asked for — half a second after the sender's name,
      // a quarter between sentences. See Utterance and phrases(for:).
      if u.gap > 0 {
        try? await Task.sleep(nanoseconds: UInt64(u.gap * 1_000_000_000))
      }
      await speak(u.text)
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

  // ── the dimmed-screen test ──────────────────────────────────────────────────

  /// Wait, then try to speak, and record exactly what happened.
  ///
  /// The delay is the whole point: it exists so the operator can lower their wrist and
  /// let the screen dim before the attempt. Measuring this while somebody is looking at
  /// the watch would measure the case we already know works.
  ///
  /// The question cannot be settled by reading documentation or reasoning about scene
  /// phases — does watchOS actually let this app speak once the screen has dimmed? The
  /// app currently assumes not and hands the announcement to the phone. If that
  /// assumption is wrong, an operator wearing the watch during a net is hearing their
  /// pocket instead of their wrist for no reason.
  ///
  /// Three things are recorded separately, because they fail independently and the
  /// remedy differs for each: whether the app was still active, whether the audio
  /// session was granted, and whether the utterance actually finished rather than being
  /// cut off the instant it began. On the wrist all three sound identical — silence.
  func runDimmedAudioTest(after seconds: Int = 12) {
    guard !dimTestRunning else { return }
    dimTestRunning = true
    Task { [weak self] in
      guard let self else { return }
      for remaining in stride(from: seconds, to: 0, by: -1) {
        dimTestResult = "Lower your wrist — testing in \(remaining)s"
        try? await Task.sleep(nanoseconds: 1_000_000_000)
      }
      let wasActive = AppState.shared.isActive
      let promised = AppState.shared.canAnnounce
      let started = Date()
      let activated = await activateAudio()
      var finished = false
      if activated {
        let u = AVSpeechUtterance(string:
          "Audio test. If you heard this with the screen off, the watch can speak while dimmed.")
        u.voice = AVSpeechSynthesisVoice(language: "en-US")
        await speechDelegate.speak(u, on: synth)
        finished = true
      }
      deactivateAudio()
      let took = Date().timeIntervalSince(started)
      // Both, because they now mean different things and the gap between them is the
      // interesting part. "app INACTIVE · will speak · session granted · spoke" is the
      // dimmed case working as intended; "app INACTIVE · will speak · session REFUSED"
      // would mean this app is promising the phone something it cannot deliver, which
      // is the one result that must never stand.
      let appPart = (wasActive ? "app active" : "app INACTIVE")
        + " · " + (promised ? "will speak" : "defers to phone")
      let sessionPart = activated ? "session granted" : "session REFUSED"
      let speechPart = activated ? (finished ? "spoke" : "cut off") : "not attempted"
      dimTestResult = appPart + " · " + sessionPart + " · " + speechPart
        + " · " + String(format: "%.1fs", took)
      dimTestRunning = false
    }
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
