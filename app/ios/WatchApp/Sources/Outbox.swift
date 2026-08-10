/// Sends that have not been confirmed yet.
///
/// A reply spoken into the wrist is often the last thing an operator does before
/// putting their arm back on the handlebars, so it has to survive the phone being
/// briefly out of range, the watch app being closed, and the watch being rebooted.
/// Entries persist and are retried; nothing is dropped silently.
import Foundation

struct PendingSend: Identifiable, Codable, Equatable {
  /// Ours, not the server's. Correlates the reply that comes back — possibly out of
  /// band, long after the request — with the row that is waiting for it.
  let id: String
  let text: String
  let conversationId: Int?
  let recipients: [String]?
  let destinationLabel: String
  let createdAt: Date

  enum State: String, Codable {
    case sending // handed to the phone, waiting on a result
    case queued // the phone acknowledged but has not finished; result comes later
    case failed // rejected, or we gave up waiting
  }

  var state: State
}

@Observable
@MainActor
final class Outbox {
  static let shared = Outbox()

  private(set) var pending: [PendingSend] = []

  /// After this we stop waiting on a send and mark it failed, so the row stops
  /// claiming to be in flight and the user can decide what to do.
  nonisolated static let resultTimeout: TimeInterval = 30

  private static let key = "watch.outbox"

  private init() {
    if let raw = UserDefaults.standard.data(forKey: Self.key),
       let saved = try? JSONDecoder().decode([PendingSend].self, from: raw) {
      // Anything still marked in-flight from a previous run cannot be waited on —
      // the reply handler died with the process.
      pending = saved.map { entry in
        var e = entry
        if e.state == .sending { e.state = .queued }
        return e
      }
    }
  }

  func add(_ entry: PendingSend) {
    pending.append(entry)
    persist()
  }

  func mark(_ id: String, _ state: PendingSend.State) {
    guard let i = pending.firstIndex(where: { $0.id == id }) else { return }
    pending[i].state = state
    persist()
  }

  func remove(_ id: String) {
    pending.removeAll { $0.id == id }
    persist()
  }

  func entry(_ id: String) -> PendingSend? {
    pending.first { $0.id == id }
  }

  /// Rows that never got an answer, oldest first, for a retry sweep.
  func stale(olderThan seconds: TimeInterval = resultTimeout) -> [PendingSend] {
    let cutoff = Date().addingTimeInterval(-seconds)
    return pending.filter { $0.state != .failed && $0.createdAt < cutoff }
  }

  /// Replace a failed entry with a fresh attempt aimed at wherever the reply is
  /// pointed *now*, and return it for the caller to submit.
  ///
  /// Deliberately not its original target. An entry usually failed because that
  /// target had gone — a thread from a previous event, a device that left the net —
  /// so repeating it verbatim would fail in exactly the same way. The current aim is
  /// the one the operator can see on the Talk page, which makes the retry's
  /// destination predictable rather than hidden in a saved record.
  ///
  /// A new id, not the old one: `?messaging=send` carries no client id, so the server
  /// cannot dedupe, and reusing the id would let one delivered-but-unacknowledged
  /// message and its retry both resolve the same row.
  func retry(_ id: String, aimedAt destination: Destination) -> PendingSend? {
    guard let old = pending.first(where: { $0.id == id }) else { return nil }
    remove(id)
    let fresh = PendingSend(id: UUID().uuidString,
                            text: old.text,
                            conversationId: destination.conversationId,
                            recipients: destination.recipients,
                            destinationLabel: destination.label,
                            createdAt: Date(),
                            state: .sending)
    add(fresh)
    return fresh
  }

  private func persist() {
    if let raw = try? JSONEncoder().encode(pending) {
      UserDefaults.standard.set(raw, forKey: Self.key)
    }
  }
}
