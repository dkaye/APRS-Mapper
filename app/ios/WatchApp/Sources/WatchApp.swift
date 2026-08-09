/// Entry point for the MARS APRS watchOS companion.
///
/// The watch is a nearly hands-free extension of the phone's microphone and speaker:
/// it announces inbound messages (haptic, tone, spoken text), and replies by
/// push-to-talk. It reaches the messaging API two ways — relayed from the iPhone over
/// WatchConnectivity when the phone is reachable, and directly over HTTPS when it is
/// not. See README.md ("Watch") for the full design.
///
/// Phase 0 is deliberately a shell: it exists to prove the watch target builds,
/// embeds, versions, and uploads alongside the Flutter iPhone app before any feature
/// code depends on it.
import SwiftUI

@main
struct WatchApp: App {
  var body: some Scene {
    WindowGroup {
      RootView()
    }
  }
}
