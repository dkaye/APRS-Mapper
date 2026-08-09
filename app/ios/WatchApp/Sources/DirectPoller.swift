/// Polling marsaprs.org from the wrist when the phone cannot.
///
/// The whole arbitration is one rule, stated once and not negotiated: the watch polls
/// only when it has a token, is frontmost, and the phone has been unreachable for a
/// quarter of a minute. There are no leases, no tie-breaks and no handshake. The
/// phone is authoritative whenever it is there; this exists for when it is not.
///
/// The fifteen-second delay matters. Reachability flaps constantly as a wrist drops
/// and lifts, and a poller that started on every flap would spend the battery it is
/// meant to be saving. Polling never runs in the background either — watchOS would
/// not run the timer, and an app that cannot make a sound has nothing to do with the
/// result.
import Foundation
import Observation
import WatchConnectivity

@Observable
@MainActor
final class DirectPoller {
  static let shared = DirectPoller()

  private(set) var active = false

  /// How long the phone must have been gone before we take over.
  private static let takeoverDelay: TimeInterval = 15
  /// Matches MapConfig.pollInterval on the phone.
  private static let interval: TimeInterval = 5
  /// After this many consecutive failures we assume there is no network and stop
  /// hammering a radio that is not going to answer.
  private static let backoffAfter = 3
  private static let backoffInterval: TimeInterval = 30

  private var lastReachableAt = Date()
  private var loop: Task<Void, Never>?
  private var failures = 0

  private init() {}

  func noteReachable() {
    lastReachableAt = Date()
    evaluate()
  }

  /// Called whenever anything that feeds the rule changes: reachability, foreground,
  /// the token, or sharing state.
  func evaluate() {
    let state = AppState.shared
    let reachable = WCSession.isSupported() ? WCSession.default.isReachable : false
    let eligible = TokenStore.load() != nil
      && !state.authExpired
      && state.isActive
      && !reachable
      && Date().timeIntervalSince(lastReachableAt) > Self.takeoverDelay

    if eligible {
      start()
    } else {
      stop(handingBack: reachable)
    }
  }

  private func start() {
    guard loop == nil else { return }
    active = true
    failures = 0
    loop = Task { [weak self] in
      while !Task.isCancelled {
        guard let self else { return }
        let wait = await self.tick()
        try? await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000))
      }
    }
  }

  private func stop(handingBack: Bool) {
    guard loop != nil else { return }
    loop?.cancel()
    loop = nil
    active = false
    guard handingBack else { return }
    // The phone knows the real watermark; ask it to fill anything missed rather than
    // trusting our own idea of where we got to.
    WatchSession.shared.send(["type": "sync", "sinceId": AppState.shared.lastId])
  }

  /// One poll. Returns how long to wait before the next.
  private func tick() async -> TimeInterval {
    guard let token = TokenStore.load() else {
      stop(handingBack: false)
      return Self.interval
    }
    let client = WatchMessagingClient(base: AppState.shared.serverBase, token: token)
    do {
      let result = try await client.poll(sinceId: AppState.shared.lastId)
      failures = 0
      if !result.messages.isEmpty {
        AppState.shared.ingest(result.messages, source: .directPoll)
      }
      return Self.interval
    } catch MessagingError.authExpired {
      // Never retry a 403. The token is dead and only the phone can issue another.
      AppState.shared.authExpired = true
      TokenStore.wipe()
      stop(handingBack: false)
      return Self.interval
    } catch {
      failures += 1
      return failures >= Self.backoffAfter ? Self.backoffInterval : Self.interval
    }
  }
}
