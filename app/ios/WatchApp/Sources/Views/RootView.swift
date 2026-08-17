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
import WatchKit

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
///
/// It is a link, not a label. The badge is deliberately persistent, and a warning with
/// nowhere to go is one the operator learns to ignore — which costs more than the
/// warning was worth. Tapping it opens the Outbox, where the reply can be sent again
/// or thrown away.
struct OutboxBadge: View {
  private var outbox: Outbox { Outbox.shared }

  var body: some View {
    let pending = outbox.pending
    if !pending.isEmpty {
      let failed = pending.filter { $0.state == .failed }.count
      NavigationLink {
        OutboxView()
      } label: {
        HStack(spacing: 2) {
          Image(systemName: failed > 0 ? "exclamationmark.triangle.fill" : "arrow.up.circle")
          Text("\(failed > 0 ? failed : pending.count)")
        }
        .font(.caption2)
        .foregroundStyle(failed > 0 ? .orange : .secondary)
      }
      // Plain, and sized to its content: the default link chrome would put a full-width
      // rounded button in a status line that is otherwise one small line of text.
      .buttonStyle(.plain)
      .fixedSize()
    }
  }
}

#Preview {
  RootView().environment(AppState.shared)
}

/// Stop whatever the wrist is saying, and say how much is left.
///
/// The same control the phone has, for the same reason: the automatic rules — five
/// minutes by message time, oldest first, nothing interrupts — are judgement, and
/// judgement is sometimes wrong. An operator who has just walked back to the radio does
/// not want to hear what they missed, however recent it technically is.
///
/// A count AND a duration, because neither answers "wait, or stop it" on its own: four
/// messages could be fifteen seconds or a minute and a half, and that is the whole
/// decision. The duration matters more on a wrist than on a phone, because there is
/// nowhere else to look while it talks.
///
/// Present only while something is queued. Screen space is scarcer here than anywhere
/// else in the system, and an idle control is a row the operator has to scroll past.
///
/// Lives in this file rather than its own because the watch target lists its sources
/// explicitly in the Xcode project — a new file would compile nowhere and fail silently.
struct AnnouncerStopButton: View {
  private var announcer: Announcer { Announcer.shared }

  private var label: String {
    let n = announcer.pendingCount
    let s = announcer.pendingSeconds
    let time = s < 60 ? "\(s)s" : "\(s / 60)m \(s % 60)s"
    // One with nothing behind it is not a queue and does not need counting.
    return n <= 1 ? "Speaking · \(time)" : "\(n) to say · \(time)"
  }

  var body: some View {
    if announcer.pendingCount > 0 {
      Button(role: .destructive) {
        Announcer.shared.stop()
        WKInterfaceDevice.current().play(.stop)
      } label: {
        HStack(spacing: 6) {
          Image(systemName: "stop.circle.fill")
          Text(label).font(.caption2).lineLimit(1)
        }
      }
      .buttonStyle(.bordered)
    }
  }
}
