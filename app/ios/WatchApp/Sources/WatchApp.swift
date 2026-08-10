/// Entry point for the APRS Map watchOS companion.
///
/// The watch is a nearly hands-free extension of the phone's microphone and speaker:
/// it announces inbound messages (haptic, tone, spoken text), and — from Phase 2 —
/// replies by push-to-talk. It reaches the messaging API relayed from the iPhone over
/// WatchConnectivity, and (Phase 3) directly over HTTPS when the phone is unreachable.
/// See README.md ("Apple Watch Companion") for the whole design.
import SwiftUI
import WatchKit

@main
struct WatchApp: App {
  @WKApplicationDelegateAdaptor(WatchAppDelegate.self) private var delegate
  @Environment(\.scenePhase) private var scenePhase

  var body: some Scene {
    WindowGroup {
      // No NavigationStack here: each page owns its own, so their titles and pushes
      // do not fight over one shared stack.
      RootView()
        .environment(AppState.shared)
    }
    .onChange(of: scenePhase) { _, phase in
      // Only `.active` counts as able to speak, and the distinction matters because
      // two different questions were being answered by one flag.
      //
      // "May I START announcing?" — only when genuinely on screen. `.inactive` covers
      // a dimmed always-on display and a lowered wrist alike, and watchOS refuses the
      // audio session for the latter. Claiming otherwise made the watch promise to
      // speak, fail silently, and leave the phone deferring to it: a message that
      // reached neither device.
      //
      // "Must I ABANDON one already speaking?" — only on `.background`, handled
      // below. A message that began while the operator was looking finishes even as
      // the screen dims, which is what stopped them being cut off mid-sentence.
      AppState.shared.isActive = phase == .active
      // Polling on our own is a foreground-only activity: watchOS would not run the
      // timer in the background, and an app that cannot make a sound has nothing to
      // do with the result.
      DirectPoller.shared.evaluate()
      switch phase {
      case .active:
        // Ask the phone to re-push: while we were away its token, destination or
        // conversation list may all have moved on.
        WatchSession.shared.hello()
      case .background:
        // Now nothing can be heard, and a half-spoken queue resuming minutes later
        // would be worse than silence.
        Announcer.shared.stop()
      default:
        break
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
    // From the actual state, not from onChange: SwiftUI's onChange does not fire for
    // the value a scene launches with, so this stayed false through an entire
    // foreground session until the app was first backgrounded — and a message
    // arriving before that was never spoken.
    AppState.shared.isActive = WKApplication.shared().applicationState == .active
    WatchSession.shared.activate()
    // At launch, because the prompt cannot appear while backgrounded — which is
    // exactly when the first message the watch needs to raise is likely to arrive.
    Notifier.requestAuthorization()
  }
}
