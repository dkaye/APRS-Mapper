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
      // The phone is not there. Send it ourselves if we can — queueing to a phone
      // that is switched off would leave the reply sitting until it comes back,
      // which is exactly the situation the watch is meant to cover.
      Task { @MainActor in await Self.sendDirect(entry, payload: payload) }
      return
    }

    s.sendMessage(payload, replyHandler: { reply in
      Task { @MainActor in Self.applySendResult(reply, fallbackId: entry.id) }
    }, errorHandler: { _ in
      s.transferUserInfo(payload)
      Task { @MainActor in Outbox.shared.mark(entry.id, .queued) }
    })
  }

  /// Straight to the server, with the phone's durable queue as the last resort.
  ///
  /// Only a connection failure falls back. `?messaging=send` carries no client id, so
  /// a request the server accepted but never answered cannot be told apart from one
  /// it never saw — retrying that through a second path would duplicate the message
  /// on the net. A refusal we can read is reported as a failure instead.
  @MainActor
  private static func sendDirect(_ entry: PendingSend, payload: [String: Any]) async {
    guard let token = TokenStore.load(), !AppState.shared.authExpired else {
      WCSession.default.transferUserInfo(payload)
      Outbox.shared.mark(entry.id, .queued)
      return
    }
    let client = WatchMessagingClient(base: AppState.shared.serverBase, token: token)
    do {
      let id = try await client.send(text: entry.text,
                                     conversationId: entry.conversationId,
                                     recipients: entry.recipients)
      Outbox.shared.remove(entry.id)
      WKInterfaceDevice.current().play(.success)
      // No receipt following on this path: the phone owns that poll, and it is not
      // here. The send is confirmed; delivery is not narrated.
      _ = id
    } catch MessagingError.authExpired {
      AppState.shared.authExpired = true
      TokenStore.wipe()
      Outbox.shared.mark(entry.id, .failed)
      WKInterfaceDevice.current().play(.failure)
    } catch MessagingError.network {
      WCSession.default.transferUserInfo(payload)
      Outbox.shared.mark(entry.id, .queued)
    } catch {
      Outbox.shared.mark(entry.id, .failed)
      WKInterfaceDevice.current().play(.failure)
    }
  }

  /// Ship a recorded clip to the phone for transcription.
  ///
  /// `transferFile` rather than `sendMessage`: audio does not fit in a message
  /// payload, and the transfer is durable, so a clip survives the second or two it
  /// takes to walk back into range. The phone deletes the file after transcribing;
  /// audio is never stored on either device and never reaches the server.
  /// Cap on sending a clip inline. WatchConnectivity's message payload limit is
  /// around 64 KB; at AAC 16 kHz mono this is roughly twenty seconds of speech,
  /// which covers ordinary net traffic.
  private static let maxInlineAudio = 48 * 1024

  func transferAudio(_ url: URL, clientId: String) -> Bool {
    // No isPaired check: that property is iOS-only, and from this side a session
    // that has activated is as much assurance as watchOS offers.
    guard let s = session, s.activationState == .activated else {
      try? FileManager.default.removeItem(at: url)
      return false
    }

    // Inline when we can. transferFile is durable but opportunistic -- the system
    // decides when it is worth waking the link, and the wait between releasing the
    // button and seeing words was mostly that queue rather than the recogniser.
    // sendMessage goes now, which is what a push-to-talk key has to feel like.
    if s.isReachable,
       let data = try? Data(contentsOf: url),
       data.count <= Self.maxInlineAudio {
      s.sendMessage(["type": "talkAudio", "clientId": clientId, "audio": data],
                    replyHandler: { _ in
                      try? FileManager.default.removeItem(at: url)
                    },
                    errorHandler: { _ in
                      // Keep the clip and fall back to the durable path rather than
                      // making the operator say it again.
                      s.transferFile(url, metadata: ["type": "talkAudio", "clientId": clientId])
                    })
      return true
    }

    s.transferFile(url, metadata: ["type": "talkAudio", "clientId": clientId])
    return true
  }

  @MainActor
  static func applyTranscript(_ payload: [String: Any]) {
    guard let id = payload["clientId"] as? String else { return }
    TalkSession.shared.deliver(clientId: id,
                               text: payload["text"] as? String,
                               error: payload["error"] as? String,
                               ms: payload["ms"] as? Int,
                               onDevice: payload["onDevice"] as? Bool ?? false)
  }

  @MainActor
  static func applySendResult(_ reply: [String: Any], fallbackId: String? = nil) {
    guard let id = reply["clientId"] as? String ?? fallbackId else { return }
    if reply["queued"] as? Bool == true {
      Outbox.shared.mark(id, .queued)
      return
    }
    if reply["ok"] as? Bool == true {
      // Read the words back before the entry goes, since it is the only copy of what
      // was actually sent. This is the verification that replaced the confirm
      // countdown: after the fact and audible, rather than before and on a screen
      // nobody hands-free is looking at.
      let sent = Outbox.shared.entry(id)?.text ?? ""
      Outbox.shared.remove(id)
      AppState.shared.lastSentText = sent
      WKInterfaceDevice.current().play(.success)
      Announcer.shared.announceSent(sent, repeatingWords: AppState.shared.readBackSent)
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
      case "receipt":
        // Must be matched before the message decode below: a receipt carries a
        // messageId, and anything with an id would otherwise parse as a message.
        //
        // "sent" is skipped: the watch already said so itself, with the words it
        // sent, the moment the phone confirmed. Announcing it again from the receipt
        // feed would be the same news twice.
        guard payload["stage"] as? String != "sent" else { return }
        Announcer.shared.announceReceipt(
          stage: payload["stage"] as? String ?? "",
          count: payload["count"] as? Int ?? 0,
          total: payload["total"] as? Int ?? 0)
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
    if s.isReachable {
      DirectPoller.shared.noteReachable()
    } else {
      DirectPoller.shared.evaluate()
    }
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
