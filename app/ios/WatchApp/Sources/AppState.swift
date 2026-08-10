/// Single source of truth for the watch app, and the only funnel into the Announcer.
///
/// Everything that can produce a message — the WatchConnectivity fast path, the
/// queued transferUserInfo path, the application context, and (from Phase 3) the
/// watch's own direct poll — converges on `ingest`. That is what makes "exactly one
/// tone per message" a property of the design rather than something each caller has
/// to remember.
import Foundation
import Observation
import WatchKit

@Observable
final class AppState {
  @MainActor static let shared = AppState()

  /// Ascending by id, capped. Ordering is by id and never by ts, matching the
  /// server's `ORDER BY m.id` — a device with a skewed clock must not be able to
  /// reorder a net's traffic.
  private(set) var messages: [WatchMessage] = []
  private(set) var conversations: [WatchConversation] = []
  private(set) var destination: Destination?

  /// Say the words back after a reply goes out.
  ///
  /// The one thing left to choose. It is the only verification of a transcription
  /// that survives the use case — the confirm screen it replaced showed the text
  /// before sending, which protects nobody whose eyes are on the road — but it is
  /// also the operator's own words taking up airtime they were not listening for.
  /// Off by default; the send is still confirmed either way.
  ///
  /// Everything else that used to be optional is not. Messages are always announced,
  /// always read aloud, and always announced the same way, broadcasts included: a
  /// watch that might or might not tell you something, depending on switches set
  /// hours earlier, is worse than no watch at all during a net.
  var readBackSent = false

  /// What went out last, shown on the Talk page for a glance down.
  var lastSentText = ""

  /// Link + session state, shown in Settings so a user can tell "the watch is not
  /// alerting" apart from "nothing has been sent".
  var phoneReachable = false
  var sharing = false
  var callsign = ""
  var audioUnavailable = false

  /// Where the watch talks when the phone cannot. Handed over in the context so a
  /// server move needs no watch release.
  var serverBase = "https://marsaprs.org"

  /// The server rejected our token. Terminal until the phone issues another —
  /// retrying a 403 only produces more 403s.
  var authExpired = false

  /// When the phone last got a full state snapshot through. Surfaced in Settings
  /// because "never" and "twenty minutes ago" are the two symptoms that distinguish
  /// a broken link from a quiet net, and without it both just look like a dead app.
  var lastContextAt: Date?

  private(set) var lastId = 0

  /// Ids already seen, so the same message arriving over two transports is one
  /// event. Capped and trimmed from the low end — ids only ever increase.
  private var seenIds: Set<Int> = []

  /// `lastId` as it stood when the app launched. Anything at or below this was
  /// already on disk, so it is displayed but never announced.
  private var launchWatermark = 0

  /// Whether this app can currently speak — set from the scene phase, and the only
  /// authority on the question.
  ///
  /// The phone used to infer it from WatchConnectivity reachability, which is a
  /// different thing: a watch showing a dimmed screen with this app still resident is
  /// unreachable but perfectly able to talk. The two devices disagreed, so both
  /// announced. It is reported now rather than guessed at.
  var isActive = false {
    didSet {
      guard isActive != oldValue else { return }
      WatchSession.shared.send(["type": "canAnnounce", "enabled": isActive])
    }
  }

  /// How stale a message may be and still be announced.
  ///
  /// This, not the launch watermark, is what stops a backlog being read out.
  /// `transferUserInfo` is a durable queue: messages that arrive while the watch app
  /// is closed are delivered in a burst the moment it next runs, as ordinary relayed
  /// messages with ids above the watermark. Without an age test they would all be
  /// read aloud at once — and whether the coalesced application context happened to
  /// land first, marking them seen, is a race we must not depend on. Anything older
  /// than this is shown in the list and can be replayed deliberately.
  private static let maxAnnounceAge: TimeInterval = 120

  enum Source {
    case relayLive // sendMessage — this app was frontmost when the phone sent it
    case relayQueued // transferUserInfo — held in the durable queue until we next ran
    case directPoll // the watch fetched it itself
    case context // a snapshot for display; never announced
  }

  /// How the most recent message got here, and whether anything was heard.
  ///
  /// Surfaced in Settings because "the relay is broken" and "the watch was asleep so
  /// it queued and arrived silently" produce exactly the same experience — a message
  /// that shows up late and without a sound — and there is otherwise no way to tell
  /// them apart from the wrist.
  struct Arrival {
    let at: Date
    let live: Bool
    let announced: Bool
    let notified: Bool

    /// Green only when the operator actually got something — heard it, or was buzzed
    /// by a notification. Silent arrival is the one state worth flagging.
    var wasHeard: Bool { announced || notified }

    var detail: String {
      if announced { return live ? "live, spoken" : "queued, spoken" }
      if notified { return live ? "live, notified" : "queued, notified" }
      return live ? "live, silent" : "queued, silent"
    }
  }

  var lastArrival: Arrival?

  private static let messagesCap = 100
  private static let seenCap = 500

  private enum Key {
    static let messages = "watch.messages"
    static let lastId = "watch.lastId"
    static let seenIds = "watch.seenIds"
    static let readBack = "watch.readBackSent"
    static let destination = "watch.destination"
  }

  @MainActor
  private init() {
    let d = UserDefaults.standard
    lastId = d.integer(forKey: Key.lastId)
    launchWatermark = lastId
    readBackSent = d.object(forKey: Key.readBack) as? Bool ?? false
    if let raw = d.data(forKey: Key.messages),
       let saved = try? JSONDecoder().decode([WatchMessage].self, from: raw) {
      messages = saved
    }
    if let ids = d.array(forKey: Key.seenIds) as? [Int] {
      seenIds = Set(ids)
    }
    if let raw = d.data(forKey: Key.destination),
       let saved = try? JSONDecoder().decode(Destination.self, from: raw) {
      destination = saved
    }
  }

  // ── the funnel ──────────────────────────────────────────────────────────────

  @MainActor
  func ingest(_ incoming: [WatchMessage], source: Source) {
    guard !incoming.isEmpty else { return }

    var fresh: [WatchMessage] = []
    for m in incoming where !seenIds.contains(m.id) {
      seenIds.insert(m.id)
      fresh.append(m)
    }
    guard !fresh.isEmpty else { return }

    messages.append(contentsOf: fresh)
    messages.sort { $0.id < $1.id }
    if messages.count > Self.messagesCap {
      messages.removeFirst(messages.count - Self.messagesCap)
    }
    lastId = max(lastId, fresh.map(\.id).max() ?? lastId)
    trimSeen()
    persist()

    // Worth the operator's attention. Whether that attention is speech or a
    // notification depends only on whether this app happens to be on screen.
    let now = Date().timeIntervalSince1970
    let alertable = fresh.filter { m in
      source != .context
        && m.id > launchWatermark
        && now - TimeInterval(m.ts) <= Self.maxAnnounceAge
        && !m.isSelf
        // Already announced by the phone. Queued transfers flush the moment this app
        // comes forward, so without this the operator raises their wrist and hears a
        // rerun of everything they just heard from their pocket.
        && !m.phoneAnnounced
    }
    if source != .context {
      lastArrival = Arrival(at: Date(), live: source == .relayLive,
                            announced: isActive && !alertable.isEmpty,
                            notified: !isActive && !alertable.isEmpty)
    }

    // Aim the reply at whoever just called — but only for messages this watch fetched
    // itself. The phone re-aims whenever it relays (WatchBridge._aimAt), and a message
    // that arrived by direct poll never passed through the phone, so nothing aimed at
    // all: the reply still pointed wherever it last pointed. After an event change that
    // is a thread which no longer exists, leaving the operator hearing a call they have
    // no way to answer.
    //
    // Broadcasts are deliberately left alone. WatchMessage carries no sender key, so
    // the only thing available to aim at is the broadcast thread itself, and a spoken
    // "copy that" going to every tracker is a worse default than not moving the aim —
    // the same conservative choice the phone makes when it has no key either.
    if source == .directPoll,
       let call = fresh.last(where: { !$0.isSelf && !$0.broadcast && $0.conversationId > 0 }),
       destination?.conversationId != call.conversationId {
      chooseDestination(conversationId: call.conversationId, recipients: nil,
                        label: call.senderLabel)
    }

    guard !alertable.isEmpty else { return }
    if isActive {
      Announcer.shared.enqueue(alertable)
    } else {
      // Backgrounded, so nothing can be spoken here. Everything still in `alertable`
      // is a message the phone did not announce — either we fetched it ourselves
      // because the phone was unreachable, or it deliberately left it to us on the
      // strength of a promise we have since invalidated by going quiet. A
      // notification is all that is left, and better than silence.
      for m in alertable { Notifier.alert(m) }
    }
  }

  // ── state from the phone ────────────────────────────────────────────────────

  @MainActor
  func apply(context: [String: Any]) {
    if let c = context["callsign"] as? String { callsign = c }
    if let b = context["serverBase"] as? String, !b.isEmpty { serverBase = b }
    sharing = context["sharing"] as? Bool ?? false

    // The token is absent from the payload rather than null when there is none —
    // WatchConnectivity cannot carry a null. Absent, or sharing stopped, means the
    // watch should not be holding a copy at all.
    if sharing, let token = context["token"] as? String, !token.isEmpty {
      TokenStore.save(token)
      authExpired = false
    } else {
      TokenStore.wipe()
    }
    DirectPoller.shared.evaluate()

    // Absent means none. The context is always a complete snapshot, and null values
    // cannot cross WatchConnectivity at all, so "key missing" is the only way the
    // phone can express "no destination".
    let incoming = (context["destination"] as? [String: Any]).flatMap(Destination.init(wire:))

    // The newer decision wins, whoever made it. An arriving message re-aims the reply
    // at whoever just called; a pick on this wrist re-aims it deliberately; and a
    // context that was already in flight when the wrist chose is simply older and
    // loses. Timestamps decide it, because "which of these two happened last" is the
    // actual question and nothing else answers it.
    if let incoming, incoming.chosenAt >= (destination?.chosenAt ?? 0) {
      destination = incoming
    } else if incoming == nil, destination != nil, context.keys.contains("sharing"),
              !(context["sharing"] as? Bool ?? false) {
      // Sharing stopped: there is nowhere to reply to any more.
      destination = nil
    }
    lastContextAt = Date()

    if let raw = context["conversations"] as? [[String: Any]] {
      conversations = raw.compactMap(WatchConversation.init(wire:))
    }

    // Always .context: a snapshot of what the phone already has is history, not
    // news, and announcing it would read out the backlog this design avoids.
    if let raw = context["recent"] as? [[String: Any]] {
      ingest(raw.compactMap(WatchMessage.init(wire:)), source: .context)
    }
    persist()
  }

  @MainActor
  func setReadBackSent(_ on: Bool) {
    readBackSent = on
    persist()
  }

  /// Send a reply straight out. There is no confirm step: a countdown showing the
  /// transcription protects only an operator who is looking at their wrist, which is
  /// the opposite of the situation push-to-talk exists for. Verification happens
  /// afterwards instead, by saying the words back.
  @MainActor
  func sendReply(_ text: String) {
    let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !body.isEmpty, let destination else { return }
    let entry = PendingSend(
      id: UUID().uuidString,
      text: body,
      conversationId: destination.conversationId,
      recipients: destination.recipients,
      destinationLabel: destination.label,
      createdAt: Date(),
      state: .sending
    )
    Outbox.shared.add(entry)
    WatchSession.shared.submit(entry)
  }

  /// Change the sticky destination from the wrist and tell the phone, so the two
  /// agree about where the next reply goes. The phone remains authoritative — it
  /// echoes the choice back in the next context, which is what confirms it landed.
  @MainActor
  func chooseDestination(conversationId: Int?, recipients: [String]?, label: String) {
    // Stamped here and sent with the choice, so the phone echoes our timestamp rather
    // than minting a newer one — otherwise the echo would always look like the more
    // recent decision and this pick could never be superseded correctly.
    var wire: [String: Any] = [
      "type": "destination",
      "label": label,
      "chosenAt": Int(Date().timeIntervalSince1970),
    ]
    if let c = conversationId { wire["conversationId"] = c }
    if let r = recipients { wire["recipients"] = r }
    destination = Destination(wire: wire)
    persist()
    WKInterfaceDevice.current().play(.click)
    WatchSession.shared.send(wire)
  }

  /// Deliberate replay of one message, from the detail screen. Bypasses the
  /// watermark because the user asked for it explicitly.
  @MainActor
  func speakAgain(_ m: WatchMessage) {
    Announcer.shared.stop()
    Announcer.shared.enqueue([m])
  }

  // ── persistence ─────────────────────────────────────────────────────────────

  private func trimSeen() {
    guard seenIds.count > Self.seenCap else { return }
    let keep = seenIds.sorted().suffix(Self.seenCap)
    seenIds = Set(keep)
  }

  private func persist() {
    let d = UserDefaults.standard
    d.set(lastId, forKey: Key.lastId)
    d.set(readBackSent, forKey: Key.readBack)
    d.set(Array(seenIds), forKey: Key.seenIds)
    if let raw = try? JSONEncoder().encode(messages) { d.set(raw, forKey: Key.messages) }
    if let dest = destination, let raw = try? JSONEncoder().encode(dest) {
      d.set(raw, forKey: Key.destination)
    } else if destination == nil {
      d.removeObject(forKey: Key.destination)
    }
  }
}
