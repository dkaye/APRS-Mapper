/// Top level of the watch UI.
///
/// Phase 1 is a receiver: the newest message, the message list, and settings. The
/// push-to-talk page becomes the first tab in Phase 2.
import SwiftUI

struct RootView: View {
  @Environment(AppState.self) private var state

  var body: some View {
    // Talk first: it is the page the operator wants under their thumb when the wrist
    // comes up, and the one they must not have to navigate to.
    TabView {
      PTTView()
      LatestView()
      MessageListView()
      DestinationPickerView()
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
        OutboxLine()

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
    .navigationTitle("APRS Map")
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

/// Shown only when a reply has not been confirmed yet. A message spoken into the
/// wrist and then silently lost is the worst failure this app has, so an unresolved
/// send stays visible rather than disappearing optimistically.
struct OutboxLine: View {
  private var outbox: Outbox { Outbox.shared }

  var body: some View {
    let pending = outbox.pending
    if !pending.isEmpty {
      let failed = pending.filter { $0.state == .failed }.count
      HStack(spacing: 4) {
        Image(systemName: failed > 0 ? "exclamationmark.triangle.fill" : "arrow.up.circle")
          .font(.caption2)
        Text(failed > 0 ? "\(failed) not sent" : "Sending \(pending.count)…")
          .font(.caption2)
      }
      .foregroundStyle(failed > 0 ? .orange : .secondary)
    }
  }
}

#Preview {
  RootView().environment(AppState.shared)
}
