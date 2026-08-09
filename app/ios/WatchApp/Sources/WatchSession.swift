/// Watch side of the phone link.
///
/// Counterpart to `app/ios/Runner/WatchBridge.swift`. Everything that arrives here —
/// live `sendMessage`, queued `transferUserInfo`, or the coalesced application
/// context — is handed to `AppState.ingest`, which decides what is new and what gets
/// announced. This class deliberately makes no such decisions itself.
import Foundation
import WatchConnectivity

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

  // ── inbound ─────────────────────────────────────────────────────────────────

  /// One entry point for every transport. A payload is either a context, a batch,
  /// or a single message; all of them end at `ingest`.
  private func handle(_ payload: [String: Any], source: AppState.Source) {
    Task { @MainActor in
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
