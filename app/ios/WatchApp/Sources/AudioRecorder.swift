/// Records the held button.
///
/// The watch cannot turn speech into text — `Speech.framework` is absent from the
/// watchOS SDK — but it can capture audio. So push-to-talk records here and the
/// iPhone, which does have a recogniser, does the transcribing. That is the only
/// arrangement that gives a real press-and-hold key: watchOS's own dictation screen
/// ends on a Done button, and on a moving vehicle that button is the whole problem.
///
/// AAC at 16 kHz mono, because these clips cross a Bluetooth link. A ten-second
/// transmission lands around 20 KB, which transfers in well under a second; 44.1 kHz
/// stereo would be twenty times that for no gain, since speech recognisers downsample
/// to 16 kHz anyway.
import AVFoundation
import Foundation
import Observation

@Observable
@MainActor
final class AudioRecorder {
  static let shared = AudioRecorder()

  /// Shorter than this and the press was a mis-tap, not an attempt to talk.
  static let minimumHold: TimeInterval = 0.4
  /// A stuck button must not hold the microphone open indefinitely.
  static let maximumHold: TimeInterval = 60

  private(set) var isRecording = false
  private(set) var elapsed: TimeInterval = 0
  private(set) var granted: Bool?

  private var recorder: AVAudioRecorder?
  private var startedAt: Date?
  private var ticker: Task<Void, Never>?

  private init() {}

  var permissionKnown: Bool { granted != nil }

  /// True once the operator has actively refused, as opposed to never having been
  /// asked. The two look identical from `granted == false` and need opposite advice:
  /// one is fixed by asking, the other only in Settings.
  private(set) var denied = false

  /// Ask for the microphone, or read back an answer already given.
  ///
  /// `AVAudioApplication`, not `AVAudioSession.requestRecordPermission`. The latter is
  /// deprecated as of watchOS 10 — which is this target's minimum — and on a Series 9
  /// it did not raise the prompt at all. The symptom is confusing rather than obvious:
  /// the app silently falls back to the dictation screen forever, and the toggle in
  /// Settings › Privacy › Microphone stays greyed out, because watchOS only enables it
  /// once an app has actually asked.
  ///
  /// The current state is read first so a refusal already on file is known without
  /// prompting again — the system only ever shows that alert once.
  func requestPermission() async {
    switch AVAudioApplication.shared.recordPermission {
    case .granted:
      granted = true
      denied = false
    case .denied:
      granted = false
      denied = true
    default:                     // .undetermined — never asked, so ask
      let ok = await withCheckedContinuation { (c: CheckedContinuation<Bool, Never>) in
        AVAudioApplication.requestRecordPermission { c.resume(returning: $0) }
      }
      granted = ok
      denied = !ok
    }
  }

  /// Opens the microphone. Returns false if it could not start, so the caller can
  /// fall back to the dictation screen rather than silently swallowing the press.
  func start() async -> Bool {
    guard !isRecording, granted == true else { return false }

    // Never record ourselves: a message being read aloud would land in the clip.
    Announcer.shared.stop()

    let session = AVAudioSession.sharedInstance()
    do {
      try session.setCategory(.record, mode: .measurement)
    } catch {
      return false
    }

    let activated = await withCheckedContinuation { (c: CheckedContinuation<Bool, Never>) in
      session.activate(options: []) { ok, _ in c.resume(returning: ok) }
    }
    guard activated else {
      release()
      return false
    }

    let url = FileManager.default.temporaryDirectory
      .appendingPathComponent("talk-\(UUID().uuidString).m4a")
    let settings: [String: Any] = [
      AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
      AVSampleRateKey: 16000.0,
      AVNumberOfChannelsKey: 1,
      AVEncoderAudioQualityKey: AVAudioQuality.medium.rawValue,
    ]

    do {
      let r = try AVAudioRecorder(url: url, settings: settings)
      guard r.record() else {
        release()
        return false
      }
      recorder = r
      startedAt = Date()
      elapsed = 0
      isRecording = true
      startTicking()
      return true
    } catch {
      release()
      return false
    }
  }

  /// Returns the clip, or nil when the press was too short or nothing was captured.
  /// The caller owns the file and is responsible for deleting it.
  func stop() -> URL? {
    guard let r = recorder else { return nil }
    let held = startedAt.map { Date().timeIntervalSince($0) } ?? 0
    let url = r.url
    r.stop()
    release()

    guard held >= Self.minimumHold else {
      try? FileManager.default.removeItem(at: url)
      return nil
    }
    let size = (try? FileManager.default.attributesOfItem(atPath: url.path)[.size] as? Int) ?? 0
    guard (size ?? 0) > 0 else {
      try? FileManager.default.removeItem(at: url)
      return nil
    }
    return url
  }

  func cancel() {
    guard let r = recorder else { return }
    let url = r.url
    r.stop()
    release()
    try? FileManager.default.removeItem(at: url)
  }

  private func startTicking() {
    ticker?.cancel()
    ticker = Task { [weak self] in
      while !Task.isCancelled {
        try? await Task.sleep(nanoseconds: 200_000_000)
        guard let self, let started = await self.startedAt else { return }
        let t = Date().timeIntervalSince(started)
        await MainActor.run {
          self.elapsed = t
          if t >= Self.maximumHold { _ = self.stop() }
        }
      }
    }
  }

  private func release() {
    ticker?.cancel()
    ticker = nil
    recorder = nil
    startedAt = nil
    isRecording = false
    // Hand the route back before the announcer wants it: record-to-playback on
    // watchOS is slow and fails outright if the session is still ours.
    try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
  }
}
