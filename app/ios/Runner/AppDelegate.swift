import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // Before super: WatchConnectivity can launch this app into the background purely
    // to deliver a watch message, and if no delegate is assigned by the time launch
    // returns, that message is lost. Engine setup is the slow part, so it goes after.
    WatchBridge.shared.activate()
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    WatchBridge.shared.attach(messenger: engineBridge.applicationRegistrar.messenger())
  }
}
