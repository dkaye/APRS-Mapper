/// Top level of the watch UI.
///
/// Four pages, swiped horizontally, with Talk first — it is what the operator wants
/// under their thumb when the wrist comes up, and the one they must not have to
/// navigate to.
///
/// Horizontal paging, not vertical: the Digital Crown scrolls whatever is on screen,
/// so a vertically-paged TabView fights every page that has content taller than the
/// display and the other pages become unreachable. Swiping sideways leaves the crown
/// free to do the one thing it is good at.
import SwiftUI

struct RootView: View {
  var body: some View {
    TabView {
      NavigationStack { PTTView() }
      NavigationStack { MessageListView() }
      NavigationStack { DestinationPickerView() }
      NavigationStack { SettingsView() }
    }
    .tabViewStyle(.page)
  }
}

/// One line saying whether messages can actually reach this watch right now.
///
/// Not decoration: a watch that has lost the phone looks exactly like a quiet net,
/// and during an event those are very different situations.
struct StatusLine: View {
  @Environment(AppState.self) private var state

  private var text: String {
    if state.authExpired { return "Reconnect on iPhone" }
    if !state.sharing { return "Not sharing — start on iPhone" }
    if state.phoneReachable {
      return state.audioUnavailable ? "Linked · no audio" : "Phone linked"
    }
    // Worth distinguishing: "on its own and working" is a very different state from
    // "cut off", and from the wrist they otherwise look identical.
    return DirectPoller.shared.active ? "Direct — phone away" : "Phone unreachable"
  }

  private var color: Color {
    if state.authExpired || !state.sharing { return .orange }
    if state.phoneReachable { return .green }
    return DirectPoller.shared.active ? .blue : .secondary
  }

  var body: some View {
    HStack(spacing: 4) {
      Circle().fill(color).frame(width: 6, height: 6)
      Text(text)
        .font(.caption2)
        .foregroundStyle(.secondary)
        .lineLimit(1)
      Spacer(minLength: 0)
      OutboxBadge()
    }
  }
}

/// Shown only when a reply has not been confirmed yet. A message spoken into the
/// wrist and then silently lost is the worst failure this app has, so an unresolved
/// send stays visible rather than disappearing optimistically.
struct OutboxBadge: View {
  private var outbox: Outbox { Outbox.shared }

  var body: some View {
    let pending = outbox.pending
    if !pending.isEmpty {
      let failed = pending.filter { $0.state == .failed }.count
      HStack(spacing: 2) {
        Image(systemName: failed > 0 ? "exclamationmark.triangle.fill" : "arrow.up.circle")
        Text("\(failed > 0 ? failed : pending.count)")
      }
      .font(.caption2)
      .foregroundStyle(failed > 0 ? .orange : .secondary)
    }
  }
}

#Preview {
  RootView().environment(AppState.shared)
}
