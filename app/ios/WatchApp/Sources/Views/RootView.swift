/// Top level of the watch UI.
///
/// Phase 1 is a receiver: the newest message, the message list, and settings. The
/// push-to-talk page becomes the first tab in Phase 2.
import SwiftUI

struct RootView: View {
  @Environment(AppState.self) private var state

  var body: some View {
    TabView {
      LatestView()
      MessageListView()
      SettingsView()
    }
    .tabViewStyle(.verticalPage)
  }
}

/// The glanceable screen: who called, what they said, and whether the watch is in
/// a state where it can alert at all. That last part is not decoration — a silent
/// watch during a net is indistinguishable from a quiet net unless we say so.
struct LatestView: View {
  @Environment(AppState.self) private var state

  private var latest: WatchMessage? { state.messages.last }

  var body: some View {
    ScrollView {
      VStack(alignment: .leading, spacing: 8) {
        StatusLine()

        if let m = latest {
          Text(m.senderLabel)
            .font(.headline)
            .foregroundStyle(m.broadcast ? .orange : .primary)
          if m.broadcast {
            Text("All Trackers")
              .font(.caption2)
              .foregroundStyle(.orange)
          }
          Text(m.displayText)
            .font(.body)
          Text(m.date, style: .time)
            .font(.caption2)
            .foregroundStyle(.secondary)
          Button {
            state.speakAgain(m)
          } label: {
            Label("Speak again", systemImage: "speaker.wave.2.fill")
          }
          .buttonStyle(.bordered)
          .padding(.top, 4)
        } else {
          Text("No messages yet")
            .font(.body)
            .foregroundStyle(.secondary)
            .padding(.top, 12)
        }
      }
      .frame(maxWidth: .infinity, alignment: .leading)
      .padding(.horizontal, 4)
    }
    .navigationTitle("MARS APRS")
  }
}

/// One line saying whether messages can actually reach this watch right now.
struct StatusLine: View {
  @Environment(AppState.self) private var state

  private var text: String {
    if !state.sharing { return "Not sharing — start on iPhone" }
    if state.audioUnavailable { return "Phone linked · no audio route" }
    return state.phoneReachable ? "Phone linked" : "Phone unreachable"
  }

  private var color: Color {
    if !state.sharing { return .orange }
    return state.phoneReachable ? .green : .secondary
  }

  var body: some View {
    HStack(spacing: 4) {
      Circle().fill(color).frame(width: 6, height: 6)
      Text(text)
        .font(.caption2)
        .foregroundStyle(.secondary)
    }
  }
}

#Preview {
  RootView().environment(AppState.shared)
}
