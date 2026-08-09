/// Watch settings and link diagnostics.
///
/// The read-aloud toggle is the same setting as the phone's speaker button — flipping
/// it here writes through to the phone's `aprs_msg_speak` preference, and a flip on
/// the phone comes back in the next application context. Two switches that disagreed
/// about what "speak" means would be worse than one.
import SwiftUI

struct SettingsView: View {
  @Environment(AppState.self) private var state

  var body: some View {
    List {
      Section {
        Toggle("Read aloud", isOn: Binding(
          get: { state.speakEnabled },
          set: { state.setSpeak($0) }
        ))
        Toggle("Announce broadcasts", isOn: Binding(
          get: { state.announceBroadcasts },
          set: { state.setAnnounceBroadcasts($0) }
        ))
      } footer: {
        Text("Alerts buzz and play a tone even with read aloud off. Wear headphones for reliable speech.")
          .font(.caption2)
      }

      Section("Status") {
        LabeledContent("Phone", value: state.phoneReachable ? "Linked" : "Unreachable")
        LabeledContent("Sharing", value: state.sharing ? "On" : "Off")
        LabeledContent("Direct polling", value: DirectPoller.shared.active ? "On" : "Off")
        if let at = state.lastContextAt {
          LabeledContent("Updated", value: at.formatted(date: .omitted, time: .shortened))
        } else {
          LabeledContent("Updated", value: "Never")
        }
        if !state.callsign.isEmpty {
          LabeledContent("Callsign", value: state.callsign)
        }
        if let d = state.destination {
          LabeledContent("Reply to", value: d.label)
        }
        LabeledContent("Messages", value: "\(state.messages.count)")
        // Deliberately not LabeledContent: on watchOS its trailing view is squeezed
        // onto one line and a second line of detail is clipped away entirely.
        VStack(alignment: .leading, spacing: 1) {
          Text("Last message")
            .font(.caption2)
            .foregroundStyle(.secondary)
          if let a = state.lastArrival {
            Text("\(a.at.formatted(date: .omitted, time: .standard)) · \(a.detail)")
              .font(.footnote)
              .foregroundStyle(a.wasHeard ? .green : .orange)
          } else {
            Text("None yet")
              .font(.footnote)
              .foregroundStyle(.secondary)
          }
        }
      }

      Section {
        Text(Bundle.main.versionSummary)
          .font(.caption2)
          .foregroundStyle(.secondary)
      } footer: {
        // Setting expectations beats a silent failure the user has to diagnose
        // mid-net: watchOS simply will not let a backgrounded app make noise.
        Text("The watch can only speak while this app is on screen.")
          .font(.caption2)
      }
    }
    .navigationTitle("Settings")
  }
}

extension Bundle {
  /// "1.22.0 (15)" — both come from pubspec.yaml, so this also confirms at a glance
  /// that the watch app and the iPhone app shipped together.
  var versionSummary: String {
    let name = infoDictionary?["CFBundleShortVersionString"] as? String ?? "?"
    let build = infoDictionary?["CFBundleVersion"] as? String ?? "?"
    return "\(name) (\(build))"
  }
}
