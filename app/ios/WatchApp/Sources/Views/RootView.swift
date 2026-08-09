/// Placeholder root view for the Phase 0 build-system spike.
///
/// Phase 1 replaces this with a vertical-page TabView: push-to-talk, messages, and
/// the sticky destination picker.
import SwiftUI

struct RootView: View {
  var body: some View {
    VStack(spacing: 6) {
      Image(systemName: "antenna.radiowaves.left.and.right")
        .font(.largeTitle)
        .foregroundStyle(.tint)
      Text("MARS APRS")
        .font(.headline)
      Text(Bundle.main.versionSummary)
        .font(.caption2)
        .foregroundStyle(.secondary)
    }
    .padding()
  }
}

extension Bundle {
  /// "1.22.0 (15)" — shown so a tester can confirm at a glance that the watch app
  /// and the iPhone app came out of the same pubspec.yaml version.
  var versionSummary: String {
    let name = infoDictionary?["CFBundleShortVersionString"] as? String ?? "?"
    let build = infoDictionary?["CFBundleVersion"] as? String ?? "?"
    return "\(name) (\(build))"
  }
}

#Preview {
  RootView()
}
