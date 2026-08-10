/// Phone side of the Apple Watch bridge.
///
/// Owns the `WCSession`, owns the two Flutter channels, and buffers across the gap
/// between them. The gap is the whole reason this class exists: WatchConnectivity can
/// launch this app into the background purely to deliver a watch message, and at that
/// moment the Flutter engine may not exist yet, let alone have run `main()`. Anything
/// arriving before Dart is ready is parked in `pendingToDart` and replayed when it is.
///
/// Wire format is documented in README.md ("Apple Watch Companion"). Dart counterpart:
/// `app/lib/watch_bridge.dart`. Watch counterpart: `app/ios/WatchApp/Sources/WatchSession.swift`.
import Flutter
import Foundation
import Speech
import WatchConnectivity

final class WatchBridge: NSObject {
  static let shared = WatchBridge()

  private static let methodChannelName = "org.marsaprs/watch"
  private static let eventChannelName = "org.marsaprs/watch/events"

  /// How long a watch `sendMessage` reply handler may be held open while we wait for
  /// Dart to perform the send. WatchConnectivity's own reply window is short and
  /// unforgiving, so we answer "queued" well before it expires rather than risk the
  /// watch seeing a transport error for a send that is actually in flight.
  private static let replyWatchdog: TimeInterval = 8

  private var methodChannel: FlutterMethodChannel?
  private var eventSink: FlutterEventSink?

  /// Events that arrived before Dart called `ready`. Ordered; replayed verbatim.
  private var pendingToDart: [[String: Any]] = []
  /// clientId → the watch's reply handler, until Dart reports the result.
  private var pendingReplies: [String: ([String: Any]) -> Void] = [:]
  /// Last context we pushed, re-sent when the watch becomes reachable again.
  private var lastContext: [String: Any]?

  private var session: WCSession? { WCSession.isSupported() ? WCSession.default : nil }

  // ── lifecycle ───────────────────────────────────────────────────────────────

  /// Must be called from `didFinishLaunchingWithOptions` *before* anything slow.
  /// If no delegate is assigned by the time launch returns, a message that woke the
  /// app is dropped and the watch's reply handler times out.
  func activate() {
    guard WCSession.isSupported() else { return } // false on iPad
    let s = WCSession.default
    s.delegate = self
    s.activate()
  }

  /// Called once the implicit Flutter engine exists. Safe to call again after an
  /// engine restart; the channels are simply rebuilt.
  func attach(messenger: FlutterBinaryMessenger) {
    let method = FlutterMethodChannel(name: Self.methodChannelName, binaryMessenger: messenger)
    method.setMethodCallHandler { [weak self] call, result in
      self?.handle(call: call, result: result)
    }
    methodChannel = method

    FlutterEventChannel(name: Self.eventChannelName, binaryMessenger: messenger)
      .setStreamHandler(self)
  }

  // ── Dart → here ─────────────────────────────────────────────────────────────

  private func handle(call: FlutterMethodCall, result: @escaping FlutterResult) {
    let args = call.arguments as? [String: Any] ?? [:]
    switch call.method {
    case "ready":
      flushPendingToDart()
      result(state())
    case "status":
      result(state())
    case "setContext":
      lastContext = args
      result(["ok": pushContext(args)])
    case "pushMessage":
      result(["transport": push(message: args)])
    case "pushMessages":
      let msgs = args["messages"] as? [[String: Any]] ?? []
      // One envelope, not N: transferUserInfo is FIFO but each call is a separate
      // wake of the watch app, and a catch-up burst would wake it many times over.
      result(["transport": msgs.isEmpty ? "none" : push(message: ["batch": msgs])])
    case "sendResult":
      deliver(sendResult: args)
      result(["ok": true])
    default:
      result(FlutterMethodNotImplemented)
    }
  }

  private func state() -> [String: Any] {
    guard let s = session else {
      return ["supported": false, "paired": false, "appInstalled": false,
              "reachable": false, "activated": false]
    }
    return ["supported": true,
            "paired": s.isPaired,
            "appInstalled": s.isWatchAppInstalled,
            "reachable": s.isReachable,
            "activated": s.activationState == .activated]
  }

  // ── here → watch ────────────────────────────────────────────────────────────

  /// Latest-value-wins state: token, destination, conversation list, speak flag.
  /// Survives the watch app not running, and coalesces if we push faster than the
  /// link drains. Returns false when there is nothing to talk to.
  @discardableResult
  private func pushContext(_ dict: [String: Any]) -> Bool {
    guard let s = session, s.activationState == .activated, s.isPaired else {
      NSLog("[watch] context skipped (activated=\(session?.activationState == .activated) paired=\(session?.isPaired ?? false))")
      return false
    }
    do {
      try s.updateApplicationContext(sanitized(dict))
      NSLog("[watch] context pushed (sharing=\(dict["sharing"] ?? "?") lastId=\(dict["lastId"] ?? "?"))")
      return true
    } catch {
      NSLog("[watch] updateApplicationContext failed: \(error.localizedDescription)")
      return false
    }
  }

  /// A message (or a `batch` of them). `sendMessage` is the low-latency path when the
  /// watch app is up; `transferUserInfo` is the durable one — FIFO, persisted across
  /// termination on both sides, and it wakes the watch app in the background to
  /// receive. The watch dedupes by message id, so the fallback racing the fast path
  /// is harmless.
  private func push(message: [String: Any]) -> String {
    guard let s = session, s.activationState == .activated,
          s.isPaired, s.isWatchAppInstalled else { return "none" }
    let payload = sanitized(message)
    let id = (payload["id"] as? Int).map(String.init) ?? "batch"
    if s.isReachable {
      NSLog("[watch] push id=\(id) via sendMessage")
      s.sendMessage(payload, replyHandler: nil) { error in
        NSLog("[watch] sendMessage id=\(id) failed, queueing: \(error.localizedDescription)")
        s.transferUserInfo(payload)
      }
      return "sendMessage"
    }
    // Not reachable means the watch app is not frontmost. The transfer is durable
    // and will be delivered when it next runs, but it will arrive silently — this
    // log line is what distinguishes "the relay never fired" from "the watch was
    // not listening", which look identical from the wrist.
    NSLog("[watch] push id=\(id) via transferUserInfo (watch app not frontmost)")
    s.transferUserInfo(payload)
    return "userInfo"
  }

  /// WatchConnectivity only accepts property-list types, and `NSNull` is not one —
  /// a single null anywhere makes the whole call fail, taking every other key with
  /// it. Flutter encodes a Dart `null` as `NSNull`, and the fields most likely to be
  /// null (`token`, `destination`, a sender's short id) are null exactly when the
  /// user has not started sharing or opened a thread yet, so the failure lands on a
  /// first run and looks like the watch is simply dead. Absent means null by
  /// contract on the watch side, so dropping them loses nothing.
  private func sanitized(_ dict: [String: Any]) -> [String: Any] {
    var out: [String: Any] = [:]
    for (key, value) in dict {
      if value is NSNull { continue }
      if let nested = value as? [String: Any] {
        out[key] = sanitized(nested)
      } else if let list = value as? [[String: Any]] {
        out[key] = list.map(sanitized)
      } else {
        out[key] = value
      }
    }
    return out
  }

  /// The out-of-band answer to a watch send we could not answer inline.
  private func deliver(sendResult: [String: Any]) {
    var payload = sendResult
    payload["type"] = "sendResult"
    guard let clientId = sendResult["clientId"] as? String else {
      _ = push(message: payload)
      return
    }
    if let reply = pendingReplies.removeValue(forKey: clientId) {
      reply(payload) // still inside the watch's reply window
    } else {
      _ = push(message: payload) // watchdog already answered "queued"
    }
  }

  // ── watch → Dart ────────────────────────────────────────────────────────────

  private func emit(_ event: [String: Any]) {
    if let sink = eventSink {
      sink(event)
    } else {
      // Cap the buffer: a watch that keeps talking to an app that never finishes
      // launching must not grow this without bound.
      if pendingToDart.count >= 64 { pendingToDart.removeFirst() }
      pendingToDart.append(event)
    }
  }

  private func flushPendingToDart() {
    guard let sink = eventSink, !pendingToDart.isEmpty else { return }
    let queued = pendingToDart
    pendingToDart.removeAll()
    for e in queued { sink(e) }
  }

  /// Route an inbound watch payload to Dart, holding `reply` open until Dart answers
  /// or the watchdog fires — whichever comes first.
  private func route(_ payload: [String: Any], reply: (([String: Any]) -> Void)?) {
    // A push-to-talk clip sent inline rather than as a file transfer. Handled here
    // and never forwarded to Dart: turning audio into text is this side's job and
    // needs no Flutter engine, which matters because this can arrive while the app
    // is still starting in the background.
    if payload["type"] as? String == "talkAudio",
       let audio = payload["audio"] as? Data,
       let id = payload["clientId"] as? String {
      reply?(["received": true])
      receiveInlineAudio(audio, clientId: id)
      return
    }

    var event = payload
    let clientId = payload["clientId"] as? String

    if let reply, let clientId {
      pendingReplies[clientId] = reply
      DispatchQueue.main.asyncAfter(deadline: .now() + Self.replyWatchdog) { [weak self] in
        guard let self, let pending = self.pendingReplies.removeValue(forKey: clientId) else { return }
        // Not a failure. Dart is still working; the real answer arrives out of band.
        pending(["type": "sendResult", "clientId": clientId, "queued": true])
      }
    } else if let reply {
      reply(["ok": true])
    }

    if event["type"] == nil { event["type"] = "unknown" }
    emit(event)
  }

  private func emitWatchState() {
    var e = state()
    e["type"] = "watchState"
    emit(e)
  }
}

// ── WCSessionDelegate ─────────────────────────────────────────────────────────

extension WatchBridge: WCSessionDelegate {
  func session(_ session: WCSession, activationDidCompleteWith activationState: WCSessionActivationState,
               error: Error?) {
    if let error { NSLog("[watch] activation failed: \(error.localizedDescription)") }
    DispatchQueue.main.async {
      self.emitWatchState()
      if activationState == .activated, let ctx = self.lastContext { self.pushContext(ctx) }
    }
  }

  func sessionDidBecomeInactive(_ session: WCSession) {}

  /// Required on iOS: the user switched to a different paired watch. Without
  /// re-activating, this app is deaf to the new one for the rest of its life.
  func sessionDidDeactivate(_ session: WCSession) {
    WCSession.default.activate()
  }

  func sessionWatchStateDidChange(_ session: WCSession) {
    DispatchQueue.main.async { self.emitWatchState() }
  }

  func sessionReachabilityDidChange(_ session: WCSession) {
    DispatchQueue.main.async {
      self.emitWatchState()
      // The watch stops its own direct polling the moment we are reachable, so it
      // needs current state immediately rather than at the next natural push.
      if session.isReachable, let ctx = self.lastContext { self.pushContext(ctx) }
    }
  }

  func session(_ session: WCSession, didReceiveMessage message: [String: Any]) {
    DispatchQueue.main.async { self.route(message, reply: nil) }
  }

  func session(_ session: WCSession, didReceiveMessage message: [String: Any],
               replyHandler: @escaping ([String: Any]) -> Void) {
    DispatchQueue.main.async { self.route(message, reply: replyHandler) }
  }

  func session(_ session: WCSession, didReceiveUserInfo userInfo: [String: Any] = [:]) {
    DispatchQueue.main.async { self.route(userInfo, reply: nil) }
  }

  func session(_ session: WCSession, didReceiveApplicationContext applicationContext: [String: Any]) {
    DispatchQueue.main.async { self.route(applicationContext, reply: nil) }
  }

  /// A push-to-talk clip recorded on the watch.
  ///
  /// The watch has no speech recogniser — `Speech.framework` does not exist on
  /// watchOS — so it records and this side listens. The file must be copied out
  /// synchronously here; WatchConnectivity deletes the original as soon as this
  /// method returns.
  func session(_ session: WCSession, didReceive file: WCSessionFile) {
    guard file.metadata?["type"] as? String == "talkAudio",
          let clientId = file.metadata?["clientId"] as? String else { return }

    let copy = FileManager.default.temporaryDirectory
      .appendingPathComponent("talk-\(clientId).m4a")
    try? FileManager.default.removeItem(at: copy)
    do {
      try FileManager.default.copyItem(at: file.fileURL, to: copy)
    } catch {
      replyTranscript(clientId: clientId, text: nil, error: "Audio lost in transfer", ms: 0, onDevice: false)
      return
    }

    // Tell the watch we have it, so its "Sending audio…" can become
    // "Transcribing…" rather than sitting on a stale label.
    _ = push(message: ["type": "audioReceived", "clientId": clientId])
    transcribe(copy, clientId: clientId)
  }
}

// ── speech recognition (this side only) ───────────────────────────────────────

extension WatchBridge {
  private static let recognizer = SFSpeechRecognizer(locale: Locale(identifier: "en-US"))

  /// A clip that arrived in a message payload rather than as a file.
  func receiveInlineAudio(_ audio: Data, clientId: String) {
    let url = FileManager.default.temporaryDirectory
      .appendingPathComponent("talk-\(clientId).m4a")
    do {
      try audio.write(to: url)
    } catch {
      replyTranscript(clientId: clientId, text: nil, error: "Audio lost in transfer", ms: 0, onDevice: false)
      return
    }
    _ = push(message: ["type": "audioReceived", "clientId": clientId])
    transcribe(url, clientId: clientId)
  }

  /// Ask once, at launch, rather than per clip. `requestAuthorization` is cheap after
  /// the first grant but not free, and it sat in the path between the operator
  /// releasing the button and seeing words.
  func primeSpeechAuthorization() {
    guard SFSpeechRecognizer.authorizationStatus() == .notDetermined else { return }
    SFSpeechRecognizer.requestAuthorization { _ in }
  }

  private func transcribe(_ url: URL, clientId: String) {
    let startedAt = Date()
    let finish: (String?, String?, Bool) -> Void = { [weak self] text, error, onDevice in
      try? FileManager.default.removeItem(at: url) // audio is never kept
      let ms = Int(Date().timeIntervalSince(startedAt) * 1000)
      NSLog("[watch] transcribe \(clientId) took \(ms)ms onDevice=\(onDevice) error=\(error ?? "-")")
      self?.replyTranscript(clientId: clientId, text: text, error: error, ms: ms, onDevice: onDevice)
    }

    guard SFSpeechRecognizer.authorizationStatus() == .authorized else {
      // Only ask here if it was never asked — the prompt cannot appear while
      // backgrounded, so failing fast beats hanging on a sheet nobody can see.
      SFSpeechRecognizer.requestAuthorization { status in
        DispatchQueue.main.async {
          if status == .authorized { self.transcribe(url, clientId: clientId) }
          else { finish(nil, "Speech recognition not allowed", false) }
        }
      }
      return
    }
    guard let recognizer = Self.recognizer, recognizer.isAvailable else {
      finish(nil, "Recognizer unavailable", false)
      return
    }

    // Keep the audio on this device when the model is present. It is a ham
    // operator's traffic; there is no reason to hand it to Apple if the phone can do
    // the work itself — and a network round trip is the slower of the two.
    let onDevice = recognizer.supportsOnDeviceRecognition
    let request = SFSpeechURLRecognitionRequest(url: url)
    request.requiresOnDeviceRecognition = onDevice
    request.taskHint = .dictation
    request.shouldReportPartialResults = false // we only ever use the final text

    recognizer.recognitionTask(with: request) { result, error in
      guard let result else {
        if error != nil { finish(nil, "Didn't catch that", onDevice) }
        return
      }
      guard result.isFinal else { return }
      finish(result.bestTranscription.formattedString, nil, onDevice)
    }
  }

  private func replyTranscript(clientId: String, text: String?, error: String?,
                               ms: Int, onDevice: Bool) {
    var payload: [String: Any] = ["type": "transcript", "clientId": clientId,
                                  "ms": ms, "onDevice": onDevice]
    if let text { payload["text"] = text }
    if let error { payload["error"] = error }
    _ = push(message: payload)
  }
}

// ── FlutterStreamHandler ──────────────────────────────────────────────────────

extension WatchBridge: FlutterStreamHandler {
  func onListen(withArguments arguments: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? {
    eventSink = events
    flushPendingToDart()
    return nil
  }

  func onCancel(withArguments arguments: Any?) -> FlutterError? {
    eventSink = nil
    return nil
  }
}
