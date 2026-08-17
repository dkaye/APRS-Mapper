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
        // "Readback" is the term on the air; the UserDefaults key stays
        // watch.readBackSent so an existing setting survives the rename.
        Toggle("Readback my words", isOn: Binding(
          get: { state.readBackSent },
          set: { state.setReadBackSent($0) }
        ))
      } footer: {
        Text("""
        Reads a reply back after sending, so you can hear what was transcribed. \
        Off still confirms with "Message sent".

        Every message is read aloud, All Trackers calls included. Replies go to \
        whoever last called you.
        """)
          .font(.caption2)
      }

      Section("Status") {
        LabeledContent("Phone", value: state.phoneReachable ? "Linked" : "Unreachable")
        LabeledContent("Sharing", value: state.sharing ? "On" : "Off")
        LabeledContent("Direct polling", value: DirectPoller.shared.active ? "On" : "Off")
        // "Synced", not "Updated": this is when the phone last sent state, and it
        // was read as when the app was last updated.
        if let at = state.lastContextAt {
          LabeledContent("Synced", value: at.formatted(date: .omitted, time: .shortened))
        } else {
          LabeledContent("Synced", value: "Never")
        }
        if !state.callsign.isEmpty {
          LabeledContent("Callsign", value: state.callsign)
        }
        if let d = state.destination {
          LabeledContent("Reply to", value: d.label)
        }
        LabeledContent("Messages", value: "\(state.messages.count)")
        if let ms = TalkSession.shared.lastTranscribeMs {
          LabeledContent("Transcribe",
                         value: String(format: "%.1fs %@", Double(ms) / 1000,
                                       TalkSession.shared.lastTranscribeOnDevice ? "on-device" : "network"))
        }
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
        Button {
          Announcer.shared.runDimmedAudioTest()
        } label: {
          Label("Test audio when dimmed", systemImage: "speaker.wave.2.bubble")
        }
        .disabled(Announcer.shared.dimTestRunning)

        if let r = Announcer.shared.dimTestResult {
          Text(r).font(.caption2).foregroundStyle(.secondary)
        }
      } header: {
        Text("Diagnostics")
      } footer: {
        // This button is what disproved the app's founding assumption about the
        // wrist: that watchOS refuses the audio session to a dimmed screen, so
        // announcements had to go to the phone the moment a wrist dropped. It does
        // not — "app INACTIVE · session granted · spoke". The routing rule changed to
        // match. It stays here as the regression check, because the failure it guards
        // against is silent on both devices at once.
        Text("Starts a countdown. Lower your wrist so the screen dims, and listen. "
             + "The result says whether the session was granted and whether it spoke.")
          .font(.caption2)
      }

      Section {
        // Build time, not just the version: both apps ship as 1.22.0 (15) until
        // pubspec moves, so the marketing version cannot tell you whether a build you
        // just pushed actually landed. This can.
        Text("\(Bundle.main.versionSummary) · \(Bundle.main.buildStamp)")
          .font(.caption2)
          .foregroundStyle(.secondary)
      } footer: {
        // Setting expectations beats a silent failure the user has to diagnose
        // mid-net: watchOS simply will not let a backgrounded app make noise. Worth
        // spelling out that a dimmed screen still counts — the wrist being down is
        // the normal way to wear this, and it is the case people assume is dead.
        Text("The watch speaks while this app is on screen, including when the "
             + "screen has dimmed. If you leave the app, the iPhone takes over.")
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

  /// When this binary was compiled, as "Aug 9 14:32".
  ///
  /// Taken from the executable's own modification date rather than a baked-in
  /// constant, so it costs no build-phase script and cannot go stale.
  var buildStamp: String {
    guard let exec = executableURL,
          let date = try? FileManager.default.attributesOfItem(atPath: exec.path)[.modificationDate] as? Date
    else { return "?" }
    let f = DateFormatter()
    f.dateFormat = "MMM d HH:mm"
    return f.string(from: date)
  }
}
