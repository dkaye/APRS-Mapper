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
    // Ask for speech permission at launch, not on the first push-to-talk clip: the
    // prompt cannot appear while backgrounded, and asking per clip put a system
    // call in the path between releasing the button and seeing words.
    WatchBridge.shared.primeSpeechAuthorization()
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    WatchBridge.shared.attach(messenger: engineBridge.applicationRegistrar.messenger())
  }
}
