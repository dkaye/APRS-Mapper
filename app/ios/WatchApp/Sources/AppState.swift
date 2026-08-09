/// Single source of truth for the watch app, and the only funnel into the Announcer.
///
/// Everything that can produce a message — the WatchConnectivity fast path, the
/// queued transferUserInfo path, the application context, and (from Phase 3) the
/// watch's own direct poll — converges on `ingest`. That is what makes "exactly one
/// tone per message" a property of the design rather than something each caller has
/// to remember.
import Foundation
import Observation

@Observable
final class AppState {
  @MainActor static let shared = AppState()

  /// Ascending by id, capped. Ordering is by id and never by ts, matching the
  /// server's `ORDER BY m.id` — a device with a skewed clock must not be able to
  /// reorder a net's traffic.
  private(set) var messages: [WatchMessage] = []
  private(set) var conversations: [WatchConversation] = []
  private(set) var destination: Destination?

  var speakEnabled = true
  var announceBroadcasts = true

  /// Link + session state, shown in Settings so a user can tell "the watch is not
  /// alerting" apart from "nothing has been sent".
  var phoneReachable = false
  var sharing = false
  var callsign = ""
  var audioUnavailable = false

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

  /// Whether this app is frontmost. watchOS only lets a frontmost app make noise,
  /// so announcing at any other time would fire a haptic into a void and, worse,
  /// desynchronize the announcer's queue from what the user actually heard.
  var isActive = false

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

  /// Suppressed while the microphone is open (Phase 2) — never read a message
  /// aloud into an open mic.
  var isRecording = false

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

    /// Green only when the user actually heard something; everything else is a
    /// state worth explaining rather than a success.
    var wasHeard: Bool { live && announced }

    var detail: String {
      if !live { return "queued, silent" }
      return announced ? "live, spoken" : "live, silent"
    }
  }

  var lastArrival: Arrival?

  private static let messagesCap = 100
  private static let seenCap = 500

  private enum Key {
    static let messages = "watch.messages"
    static let lastId = "watch.lastId"
    static let seenIds = "watch.seenIds"
    static let speak = "watch.speak"
    static let broadcasts = "watch.announceBroadcasts"
    static let destination = "watch.destination"
  }

  @MainActor
  private init() {
    let d = UserDefaults.standard
    lastId = d.integer(forKey: Key.lastId)
    launchWatermark = lastId
    speakEnabled = d.object(forKey: Key.speak) as? Bool ?? true
    announceBroadcasts = d.object(forKey: Key.broadcasts) as? Bool ?? true
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

    let now = Date().timeIntervalSince1970
    let announceable = fresh.filter { m in
      source != .context
        && isActive
        && m.id > launchWatermark
        && now - TimeInterval(m.ts) <= Self.maxAnnounceAge
        && !m.isSelf
        && !isRecording
        && (announceBroadcasts || !m.broadcast)
    }
    if source != .context {
      lastArrival = Arrival(at: Date(), live: source == .relayLive,
                            announced: !announceable.isEmpty)
    }

    guard !announceable.isEmpty else { return }
    Announcer.shared.enqueue(announceable, speak: speakEnabled)
  }

  // ── state from the phone ────────────────────────────────────────────────────

  @MainActor
  func apply(context: [String: Any]) {
    if let s = context["speak"] as? Bool { speakEnabled = s }
    if let c = context["callsign"] as? String { callsign = c }
    sharing = context["sharing"] as? Bool ?? false

    // Absent means none. The context is always a complete snapshot, and null values
    // cannot cross WatchConnectivity at all, so "key missing" is the only way the
    // phone can express "no destination".
    destination = (context["destination"] as? [String: Any]).flatMap(Destination.init(wire:))
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
  func setSpeak(_ on: Bool) {
    speakEnabled = on
    if !on { Announcer.shared.stop() }
    persist()
    WatchSession.shared.send(["type": "speak", "enabled": on])
  }

  @MainActor
  func setAnnounceBroadcasts(_ on: Bool) {
    announceBroadcasts = on
    persist()
  }

  /// Deliberate replay of one message, from the detail screen. Bypasses the
  /// watermark because the user asked for it explicitly.
  @MainActor
  func speakAgain(_ m: WatchMessage) {
    Announcer.shared.stop()
    Announcer.shared.enqueue([m], speak: true)
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
    d.set(speakEnabled, forKey: Key.speak)
    d.set(announceBroadcasts, forKey: Key.broadcasts)
    d.set(Array(seenIds), forKey: Key.seenIds)
    if let raw = try? JSONEncoder().encode(messages) { d.set(raw, forKey: Key.messages) }
    if let dest = destination, let raw = try? JSONEncoder().encode(dest) {
      d.set(raw, forKey: Key.destination)
    } else if destination == nil {
      d.removeObject(forKey: Key.destination)
    }
  }
}
