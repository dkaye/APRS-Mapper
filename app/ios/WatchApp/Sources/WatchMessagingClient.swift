/// The watch talking to marsaprs.org on its own.
///
/// Used only when the phone is unreachable. A Swift port of the slice of
/// `app/lib/messaging_client.dart` the watch needs — the same endpoints, the same
/// JSON, and the same contract that a 403 means the token is dead rather than that
/// the request should be retried.
///
/// Ordering is by `id` and never by `ts`, matching the server's `ORDER BY m.id` and
/// the Dart client. A device with a skewed clock must not be able to reorder a net's
/// traffic.
import Foundation

enum MessagingError: Error {
  case authExpired // 403: the token is no longer valid, and never will be again
  case network // could not reach the server at all
  case server(String)
}

struct WatchMessagingClient {
  let base: String
  let token: String

  private var session: URLSession {
    let config = URLSessionConfiguration.default
    config.timeoutIntervalForRequest = 10
    // Fail fast rather than parking the request: the caller wants to fall back, and
    // a request that waits for connectivity looks identical to one that hung.
    config.waitsForConnectivity = false
    config.allowsCellularAccess = true
    return URLSession(configuration: config)
  }

  private func post(_ action: String, _ body: [String: Any]) async throws -> [String: Any] {
    guard let url = URL(string: "\(base)/index.php?messaging=\(action)") else {
      throw MessagingError.network
    }
    var request = URLRequest(url: url)
    request.httpMethod = "POST"
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    var payload = body
    payload["token"] = token
    request.httpBody = try? JSONSerialization.data(withJSONObject: payload)

    let data: Data
    let response: URLResponse
    do {
      (data, response) = try await session.data(for: request)
    } catch {
      throw MessagingError.network
    }
    if (response as? HTTPURLResponse)?.statusCode == 403 { throw MessagingError.authExpired }
    guard let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
      throw MessagingError.server("Bad response")
    }
    if let error = object["error"] as? String { throw MessagingError.server(error) }
    return object
  }

  func poll(sinceId: Int) async throws -> (messages: [WatchMessage], lastId: Int) {
    let object = try await post("poll", ["since_id": sinceId])
    let raw = object["messages"] as? [[String: Any]] ?? []
    let messages = raw.compactMap(WatchMessage.init(server:)).sorted { $0.id < $1.id }
    return (messages, object["last_id"] as? Int ?? sinceId)
  }

  /// Returns the new message id. Recipients create a thread, a conversation id
  /// continues one; the caller supplies exactly one of them.
  @discardableResult
  func send(text: String, conversationId: Int?, recipients: [String]?) async throws -> Int? {
    var body: [String: Any] = ["text": text]
    if let conversationId { body["conversation_id"] = conversationId }
    if let recipients { body["recipients"] = recipients }
    let object = try await post("send", body)
    return object["id"] as? Int
  }
}

extension WatchMessage {
  /// Built from the server's own shape rather than the phone's relay shape.
  ///
  /// The sender label has to be composed here, which duplicates the rule in
  /// `MsgMessage.senderLabel`. That is deliberate and unavoidable: on this path there
  /// is no phone in the loop to compose it. If the mobile/operator/short-id rules
  /// ever change, this is the second place that has to follow.
  init?(server d: [String: Any]) {
    guard let id = d["id"] as? Int else { return nil }
    self.init(
      id: id,
      conversationId: d["conversation_id"] as? Int ?? 0,
      ts: d["ts"] as? Int ?? 0,
      text: d["text"] as? String ?? "",
      senderLabel: Self.label(from: d),
      broadcast: d["broadcast"] as? Bool ?? false,
      hasPhoto: d["photo"] as? Bool ?? false,
      isSelf: false
    )
  }

  private static func label(from d: [String: Any]) -> String {
    let kind = d["from_kind"] as? String
    let short = d["from_short"] as? String ?? ""
    let name = d["from_name"] as? String ?? ""
    let key = d["from_key"] as? String ?? ""
    if kind == "mobile", !short.isEmpty {
      return (!name.isEmpty && name != key) ? "\(short) \(name)" : short
    }
    return name.isEmpty ? key : name
  }
}
