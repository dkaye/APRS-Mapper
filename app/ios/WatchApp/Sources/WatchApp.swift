/// Entry point for the MARS APRS watchOS companion.
///
/// The watch is a nearly hands-free extension of the phone's microphone and speaker:
/// it announces inbound messages (haptic, tone, spoken text), and — from Phase 2 —
/// replies by push-to-talk. It reaches the messaging API relayed from the iPhone over
/// WatchConnectivity, and (Phase 3) directly over HTTPS when the phone is unreachable.
/// See README.md ("Apple Watch Companion") for the whole design.
import SwiftUI

@main
struct WatchApp: App {
  @WKApplicationDelegateAdaptor(WatchAppDelegate.self) private var delegate
  @Environment(\.scenePhase) private var scenePhase

  var body: some Scene {
    WindowGroup {
      NavigationStack {
        RootView()
      }
      .environment(AppState.shared)
    }
    .onChange(of: scenePhase) { _, phase in
      let active = phase == .active
      AppState.shared.isActive = active
      if active {
        // Ask the phone to re-push: while we were away its token, destination or
        // conversation list may all have moved on.
        WatchSession.shared.hello()
      } else {
        // Nothing can be heard once we leave the foreground, and a half-spoken
        // queue resuming minutes later would be worse than silence.
        Announcer.shared.stop()
      }
    }
  }
}

final class WatchAppDelegate: NSObject, WKApplicationDelegate {
  func applicationDidFinishLaunching() {
    // First thing: WatchConnectivity can wake this app in the background purely to
    // hand over queued messages, and anything delivered before the session has a
    // delegate is lost. Touching AppState here is also what snapshots the launch
    // watermark, so nothing already on disk gets announced.
    _ = AppState.shared
    WatchSession.shared.activate()
  }
}
