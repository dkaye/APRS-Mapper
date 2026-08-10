/// Getting the operator's attention when the app is not on screen.
///
/// watchOS will wake this app in the background to receive a queued message, but it
/// will not let it make a sound — audio belongs to whatever is frontmost. So a
/// message arriving while the wrist is down was silently added to the list and the
/// operator found out later, which for net traffic is the same as not arriving.
///
/// A local notification is the one alert a backgrounded watch app can raise: haptic,
/// notification sound, and a long look carrying the sender and the text. It cannot
/// read the message aloud — only a frontmost app or an extended runtime session can
/// do that — so this is a tap away from being heard rather than heard outright.
import Foundation
import UserNotifications

enum Notifier {
  /// Matches the category the phone registers, so a long look renders the same way
  /// whichever device raised it.
  static let category = "APRS_MSG"

  /// Ask at launch. The prompt cannot appear while backgrounded, which is exactly
  /// when the first message is likely to arrive.
  ///
  /// The answer is reported to the phone, which stops raising its own notification
  /// only once the wrist has said it can raise one. Assuming rather than asking would
  /// mean a denied permission here leaves the operator with no alert on either
  /// device — silently, and only discovered during a net.
  static func requestAuthorization() {
    UNUserNotificationCenter.current()
      .requestAuthorization(options: [.alert, .sound]) { granted, _ in
        reportCapability(granted)
      }
  }

  /// Re-check on every foreground: permission can be revoked in Settings long after
  /// it was granted, and the phone would otherwise stay quiet on its behalf forever.
  static func refreshCapability() {
    UNUserNotificationCenter.current().getNotificationSettings { settings in
      reportCapability(settings.authorizationStatus == .authorized
                        || settings.authorizationStatus == .provisional)
    }
  }

  private static func reportCapability(_ canAlert: Bool) {
    WatchSession.shared.send(["type": "canAlert", "enabled": canAlert])
  }

  /// Raise one alert per message.
  ///
  /// Deliberately not collapsed into "3 new messages": on a net the sender and the
  /// words are the content, and a count tells the operator nothing they can act on.
  /// The burst that would justify collapsing is already prevented upstream — only
  /// messages fresh enough to be worth announcing get here.
  static func alert(_ message: WatchMessage) {
    let content = UNMutableNotificationContent()
    content.title = message.senderLabel.isEmpty ? "Message" : message.senderLabel
    content.body = message.displayText
    content.sound = .default
    content.categoryIdentifier = category
    // The id doubles as dedupe: the same message arriving over two transports
    // replaces its own notification rather than raising a second.
    let request = UNNotificationRequest(identifier: "msg-\(message.id)",
                                        content: content,
                                        trigger: nil) // deliver now
    UNUserNotificationCenter.current().add(request)
  }
}
