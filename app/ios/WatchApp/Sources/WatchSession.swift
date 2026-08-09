/// Watch side of the phone link.
///
/// Counterpart to `app/ios/Runner/WatchBridge.swift`. Everything that arrives here —
/// live `sendMessage`, queued `transferUserInfo`, or the coalesced application
/// context — is handed to `AppState.ingest`, which decides what is new and what gets
/// announced. This class deliberately makes no such decisions itself.
import Foundation
import WatchConnectivity
import WatchKit

final class WatchSession: NSObject {
  static let shared = WatchSession()

  private var session: WCSession? { WCSession.isSupported() ? WCSession.default : nil }

  /// Activate as early as possible: WatchConnectivity can wake this app in the
  /// background purely to hand over a queued transfer, and anything delivered
  /// before a delegate exists is lost.
  func activate() {
    guard WCSession.isSupported() else { return }
    let s = WCSession.default
    s.delegate = self
    s.activate()
  }

  /// Fire-and-forget to the phone. Falls back to the durable queue when the phone
  /// is not reachable, so a request survives the walk back into range.
  func send(_ payload: [String: Any]) {
    guard let s = session, s.activationState == .activated else { return }
    if s.isReachable {
      s.sendMessage(payload, replyHandler: nil) { _ in
        s.transferUserInfo(payload)
      }
    } else {
      s.transferUserInfo(payload)
    }
  }

  /// Ask the phone to re-push its state — used on launch, when the watch may have
  /// missed context entirely.
  func hello() {
    send(["type": "hello"])
  }

  /// Hand a spoken reply to the phone, which owns the token and does the HTTP.
  ///
  /// A reply of `queued: true` is not a failure. The phone answers that when its
  /// Flutter engine is still starting — a background cold launch routinely takes a
  /// second or two, and WatchConnectivity's reply window is far shorter than that.
  /// The real result arrives later as a `sendResult` payload.
  func submit(_ entry: PendingSend) {
    var payload: [String: Any] = [
      "type": "send",
      "clientId": entry.id,
      "text": entry.text,
    ]
    if let c = entry.conversationId { payload["conversationId"] = c }
    if let r = entry.recipients { payload["recipients"] = r }

    guard let s = session, s.activationState == .activated, s.isReachable else {
      // Durable queue: the phone will get it when it is next reachable, and the
      // result comes back out of band whenever that happens.
      session?.transferUserInfo(payload)
      Task { @MainActor in Outbox.shared.mark(entry.id, .queued) }
      return
    }

    s.sendMessage(payload, replyHandler: { reply in
      Task { @MainActor in Self.applySendResult(reply, fallbackId: entry.id) }
    }, errorHandler: { _ in
      s.transferUserInfo(payload)
      Task { @MainActor in Outbox.shared.mark(entry.id, .queued) }
    })
  }

  /// Ship a recorded clip to the phone for transcription.
  ///
  /// `transferFile` rather than `sendMessage`: audio does not fit in a message
  /// payload, and the transfer is durable, so a clip survives the second or two it
  /// takes to walk back into range. The phone deletes the file after transcribing;
  /// audio is never stored on either device and never reaches the server.
  func transferAudio(_ url: URL, clientId: String) -> Bool {
    // No isPaired check: that property is iOS-only, and from this side a session
    // that has activated is as much assurance as watchOS offers.
    guard let s = session, s.activationState == .activated else {
      try? FileManager.default.removeItem(at: url)
      return false
    }
    s.transferFile(url, metadata: ["type": "talkAudio", "clientId": clientId])
    return true
  }

  @MainActor
  static func applyTranscript(_ payload: [String: Any]) {
    guard let id = payload["clientId"] as? String else { return }
    TalkSession.shared.deliver(clientId: id,
                               text: payload["text"] as? String,
                               error: payload["error"] as? String)
  }

  @MainActor
  static func applySendResult(_ reply: [String: Any], fallbackId: String? = nil) {
    guard let id = reply["clientId"] as? String ?? fallbackId else { return }
    if reply["queued"] as? Bool == true {
      Outbox.shared.mark(id, .queued)
      return
    }
    if reply["ok"] as? Bool == true {
      Outbox.shared.remove(id)
      WKInterfaceDevice.current().play(.success)
    } else {
      Outbox.shared.mark(id, .failed)
      WKInterfaceDevice.current().play(.failure)
    }
  }

  // ── inbound ─────────────────────────────────────────────────────────────────

  /// One entry point for every transport. A payload is either a context, a batch,
  /// or a single message; all of them end at `ingest`.
  private func handle(_ payload: [String: Any], source: AppState.Source) {
    Task { @MainActor in
      switch payload["type"] as? String {
      case "sendResult":
        Self.applySendResult(payload)
        return
      case "transcript":
        Self.applyTranscript(payload)
        return
      case "audioReceived":
        TalkSession.shared.phoneReceived()
        return
      default:
        break
      }
      if payload["v"] != nil || payload["conversations"] != nil || payload["recent"] != nil {
        AppState.shared.apply(context: payload)
        return
      }
      if let batch = payload["batch"] as? [[String: Any]] {
        AppState.shared.ingest(batch.compactMap(WatchMessage.init(wire:)), source: source)
        return
      }
      if let m = WatchMessage(wire: payload) {
        AppState.shared.ingest([m], source: source)
      }
    }
  }

  @MainActor
  private func refreshReachability(_ s: WCSession) {
    AppState.shared.phoneReachable = s.isReachable
  }
}

extension WatchSession: WCSessionDelegate {
  func session(_ session: WCSession, activationDidCompleteWith activationState: WCSessionActivationState,
               error: Error?) {
    Task { @MainActor in
      refreshReachability(session)
      // Context delivered while this app was not running is waiting here rather
      // than arriving as a callback, so it has to be collected explicitly.
      let pending = session.receivedApplicationContext
      if !pending.isEmpty { AppState.shared.apply(context: pending) }
      if activationState == .activated { hello() }
    }
  }

  func sessionReachabilityDidChange(_ session: WCSession) {
    Task { @MainActor in refreshReachability(session) }
  }

  /// Live: the phone only reaches us this way while this app is frontmost.
  func session(_ session: WCSession, didReceiveMessage message: [String: Any]) {
    handle(message, source: .relayLive)
  }

  /// Queued: the phone sent this while we were not running or not frontmost, and
  /// WatchConnectivity held it until now. It is real, but it is not news.
  func session(_ session: WCSession, didReceiveUserInfo userInfo: [String: Any] = [:]) {
    handle(userInfo, source: .relayQueued)
  }

  func session(_ session: WCSession, didReceiveApplicationContext applicationContext: [String: Any]) {
    handle(applicationContext, source: .context)
  }
}
