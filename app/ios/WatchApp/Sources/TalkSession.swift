/// The state of one attempt to say something.
///
/// A press-and-hold reply is not instant the way a tap is: the clip has to reach the
/// phone and come back as text before there is anything to confirm. That gap is a few
/// seconds during which the user has already taken their eyes off the watch, so every
/// step of it is named and shown rather than hidden behind a spinner — if it fails,
/// they need to know whether to speak again or reach for the phone.
import Foundation
import Observation
import WatchKit

@Observable
@MainActor
final class TalkSession {
  static let shared = TalkSession()

  enum Phase: Equatable {
    case idle
    case recording
    case sending // clip on its way to the phone
    case transcribing // phone has it, recogniser running
    case failed(String)
  }

  private(set) var phase: Phase = .idle

  /// Correlates the clip we sent with the transcript that comes back.
  private(set) var clientId: String?

  /// Beyond this we stop waiting. The transfer is Bluetooth and the recogniser may
  /// hit the network, so it is generous — but not unbounded, because a spinner that
  /// never resolves is worse than an error that offers a way out.
  private static let transcriptTimeout: TimeInterval = 25

  private var watchdog: Task<Void, Never>?

  private init() {}

  var isBusy: Bool {
    switch phase {
    case .idle, .failed: return false
    case .recording, .sending, .transcribing: return true
    }
  }

  func beginRecording() {
    watchdog?.cancel()
    clientId = nil
    phase = .recording
  }

  /// The clip is captured; hand it over and start waiting for words.
  func submitted(clientId id: String) {
    clientId = id
    phase = .sending
    watchdog?.cancel()
    watchdog = Task { [weak self] in
      try? await Task.sleep(nanoseconds: UInt64(Self.transcriptTimeout * 1_000_000_000))
      guard !Task.isCancelled else { return }
      await MainActor.run { self?.fail("No reply from iPhone") }
    }
  }

  func phoneReceived() {
    guard case .sending = phase else { return }
    phase = .transcribing
  }

  /// Words the phone sent back, waiting for the confirm screen to pick them up.
  var pendingTranscript: String?

  /// How long the phone's recogniser took, and whether it ran on-device. Shown in
  /// Settings: a second on-device and eight over the network feel like the same
  /// unexplained wait from the wrist, and only one of them is worth doing anything
  /// about.
  private(set) var lastTranscribeMs: Int?
  private(set) var lastTranscribeOnDevice = false

  /// The phone's answer. Ignored unless it belongs to the attempt in flight — a
  /// slow transcript from an abandoned press must not hijack a later one.
  func deliver(clientId id: String, text: String?, error: String?,
               ms: Int? = nil, onDevice: Bool = false) {
    guard clientId == id else { return }
    watchdog?.cancel()
    clientId = nil
    if let ms { lastTranscribeMs = ms }
    lastTranscribeOnDevice = onDevice

    if let error {
      fail(error)
      return
    }
    let body = (text ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    guard !body.isEmpty else {
      fail("Didn't catch that")
      return
    }
    phase = .idle
    pendingTranscript = body
  }

  func fail(_ reason: String) {
    watchdog?.cancel()
    phase = .failed(reason)
    clientId = nil
    WKInterfaceDevice.current().play(.failure)
  }

  func reset() {
    watchdog?.cancel()
    phase = .idle
    clientId = nil
  }

  var statusText: String? {
    switch phase {
    case .idle: return nil
    case .recording: return nil // the timer is shown instead
    case .sending: return "Sending audio…"
    case .transcribing: return "Transcribing…"
    case .failed(let why): return why
    }
  }
}
