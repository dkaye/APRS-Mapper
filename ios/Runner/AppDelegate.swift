import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }

  // Tesla-style reminder when the user CLOSES the app (swipes it away) while it
  // was actively sharing. iOS only calls applicationWillTerminate for an app that
  // is still running in the background — which for us means location sharing is
  // active — never for a plain suspended app. The Flutter side writes the
  // `aprs_is_sharing` flag (SharedPreferences stores it under the "flutter."
  // prefix) each time the app is backgrounded, so we know whether to nudge.
  override func applicationWillTerminate(_ application: UIApplication) {
    if UserDefaults.standard.bool(forKey: "flutter.aprs_is_sharing") {
      let content = UNMutableNotificationContent()
      content.title = "APRS Map"
      content.body = "Keep the app open if you want to be tracked or receive messages."
      content.sound = .default
      let request = UNNotificationRequest(
        identifier: "aprs-keep-open", content: content, trigger: nil)
      UNUserNotificationCenter.current().add(request, withCompletionHandler: nil)
    }
    super.applicationWillTerminate(application)
  }
}
