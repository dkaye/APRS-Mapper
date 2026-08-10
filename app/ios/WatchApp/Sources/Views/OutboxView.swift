/// What happened to a reply that did not go out.
///
/// The badge on the Talk page is deliberately persistent — a message spoken into the
/// wrist and then silently lost is the worst failure this app has — but a warning with
/// nowhere to go trains the operator to ignore it, which costs more than the warning
/// buys. This is where it goes: what was said, where it was headed, and the two things
/// worth doing about it.
import SwiftUI

struct OutboxView: View {
  @Environment(AppState.self) private var state
  @Environment(\.dismiss) private var dismiss
  private var outbox: Outbox { Outbox.shared }

  var body: some View {
    List {
      if outbox.pending.isEmpty {
        Text("Nothing waiting")
          .font(.footnote)
          .foregroundStyle(.secondary)
      } else {
        ForEach(outbox.pending) { entry in
          Section {
            Text(entry.text)
              .font(.body)
              .lineLimit(4)

            HStack(spacing: 4) {
              Image(systemName: icon(entry.state))
              Text(caption(entry))
            }
            .font(.caption2)
            .foregroundStyle(entry.state == .failed ? .orange : .secondary)

            if entry.state == .failed {
              // Aimed at the current destination, not the one that failed, so the
              // button says where it is actually going.
              Button {
                retry(entry)
              } label: {
                Label(retryLabel, systemImage: "arrow.clockwise")
              }
              .disabled(state.destination == nil)

              Button(role: .destructive) {
                outbox.remove(entry.id)
                if outbox.pending.isEmpty { dismiss() }
              } label: {
                Label("Discard", systemImage: "trash")
              }
            }
          }
        }
      }
    }
    .navigationTitle("Outbox")
  }

  private var retryLabel: String {
    guard let d = state.destination else { return "No destination" }
    return "Retry → \(d.label)"
  }

  private func retry(_ entry: PendingSend) {
    guard let d = state.destination else { return }
    guard let fresh = outbox.retry(entry.id, aimedAt: d) else { return }
    WatchSession.shared.submit(fresh)
    WKInterfaceDevice.current().play(.click)
    if outbox.pending.isEmpty { dismiss() }
  }

  private func icon(_ s: PendingSend.State) -> String {
    switch s {
    case .failed: return "exclamationmark.triangle.fill"
    case .queued: return "clock"
    case .sending: return "arrow.up.circle"
    }
  }

  private func caption(_ e: PendingSend) -> String {
    let when = e.createdAt.formatted(date: .omitted, time: .shortened)
    switch e.state {
    case .failed:  return "Failed · to \(e.destinationLabel) · \(when)"
    case .queued:  return "Waiting to send · \(when)"
    case .sending: return "Sending · \(when)"
    }
  }
}

#Preview {
  NavigationStack { OutboxView() }.environment(AppState.shared)
}
