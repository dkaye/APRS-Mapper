/// Wire and storage types for the watch app.
///
/// Messages arrive from the phone as `[String: Any]` over WatchConnectivity, so the
/// wire decoding is hand-written — WCSession hands back NSNumber for both integers
/// and booleans, which trips up JSONDecoder-based paths. `Codable` is kept for
/// persistence to UserDefaults, where the values are already well-typed.
import Foundation

struct WatchMessage: Identifiable, Codable, Equatable {
  let id: Int
  let conversationId: Int
  let ts: Int
  let text: String
  /// Already formatted by the phone ("M141 Doug", "Net Control"). The watch
  /// deliberately does not re-derive this; see MsgMessage.senderLabel in
  /// messaging_client.dart, which is the one implementation of those rules.
  let senderLabel: String
  let broadcast: Bool
  let hasPhoto: Bool
  let isSelf: Bool

  var date: Date { Date(timeIntervalSince1970: TimeInterval(ts)) }

  /// Spoken as two utterances with a pause between, matching the phone
  /// (messaging_screen.dart `_speakMessage`). Said as one phrase the name blurs into
  /// the opening words and the listener loses both halves; the gap gives them a beat
  /// to register who is calling before the content starts.
  ///
  /// Identical for every message, broadcasts included. Who is calling is the thing an
  /// operator needs first and it should sound the same every time; a net-wide call is
  /// still distinguished, by a double buzz, which costs no airtime.
  var announcementPhrase: String {
    let who = senderLabel.trimmingCharacters(in: .whitespaces)
    return who.isEmpty ? "Message." : "Message from \(who)."
  }

  /// The content half. A photo with no caption still deserves a sentence, or the
  /// announcement would be followed by silence.
  var bodyPhrase: String {
    if !text.isEmpty { return text }
    return hasPhoto ? "sent a photo" : ""
  }

  /// Row text when there is no message body.
  var displayText: String {
    if !text.isEmpty { return text }
    return hasPhoto ? "📷 Photo" : ""
  }

  /// Declared rather than synthesized: the decoder below suppresses the memberwise
  /// initializer, and the direct-poll path needs to build one field by field from the
  /// server's own shape.
  init(id: Int, conversationId: Int, ts: Int, text: String, senderLabel: String,
       broadcast: Bool, hasPhoto: Bool, isSelf: Bool) {
    self.id = id
    self.conversationId = conversationId
    self.ts = ts
    self.text = text
    self.senderLabel = senderLabel
    self.broadcast = broadcast
    self.hasPhoto = hasPhoto
    self.isSelf = isSelf
  }

  init?(wire d: [String: Any]) {
    guard let id = d["id"] as? Int else { return nil }
    self.id = id
    conversationId = d["conversationId"] as? Int ?? 0
    ts = d["ts"] as? Int ?? 0
    text = d["text"] as? String ?? ""
    senderLabel = d["senderLabel"] as? String ?? ""
    broadcast = d["broadcast"] as? Bool ?? false
    hasPhoto = d["hasPhoto"] as? Bool ?? false
    isSelf = d["self"] as? Bool ?? false
  }
}

struct WatchConversation: Identifiable, Codable, Equatable {
  let id: Int
  let kind: String
  let label: String
  let unread: Int
  let lastId: Int
  let previewText: String

  init?(wire d: [String: Any]) {
    guard let id = d["id"] as? Int else { return nil }
    self.id = id
    kind = d["kind"] as? String ?? "direct"
    label = d["label"] as? String ?? "Conversation"
    unread = d["unread"] as? Int ?? 0
    lastId = d["lastId"] as? Int ?? 0
    previewText = d["previewText"] as? String ?? ""
  }
}

/// Where a reply goes. Either an existing thread or a recipient set that will
/// create one; the phone decides which and the watch just carries it.
struct Destination: Codable, Equatable {
  let conversationId: Int?
  let recipients: [String]?
  let label: String

  init?(wire d: [String: Any]) {
    label = d["label"] as? String ?? ""
    conversationId = d["conversationId"] as? Int
    recipients = d["recipients"] as? [String]
    if conversationId == nil && (recipients?.isEmpty ?? true) { return nil }
  }
}
