/// Switch the sticky destination from the wrist.
///
/// Only the recent conversations the phone has already sent us, plus All Trackers.
/// The full roster — stations, operators, the "(multiple)" select-all — stays on the
/// phone: it is the most intricate UI in the app and does not survive the shrink.
/// This is for continuing a thread already in play, which is what a net actually
/// looks like.
import SwiftUI
import WatchKit

struct DestinationPickerView: View {
  @Environment(AppState.self) private var state

  var body: some View {
    List {
      if state.conversations.isEmpty {
        Text("No recent conversations. Open one on iPhone.")
          .font(.caption2)
          .foregroundStyle(.secondary)
      }

      ForEach(state.conversations) { c in
        Button {
          state.chooseDestination(conversationId: c.id, recipients: nil, label: c.label)
        } label: {
          row(label: c.label, detail: c.previewText,
              selected: state.destination?.conversationId == c.id)
        }
      }

      Section {
        Button {
          state.chooseDestination(conversationId: nil, recipients: ["all"], label: "All Trackers")
        } label: {
          row(label: "All Trackers", detail: "Everyone on the net",
              selected: state.destination?.recipients?.contains("all") == true)
        }
      }
    }
    .navigationTitle("Reply to")
  }

  private func row(label: String, detail: String, selected: Bool) -> some View {
    HStack(spacing: 6) {
      VStack(alignment: .leading, spacing: 1) {
        Text(label)
          .font(.body)
          .lineLimit(1)
        if !detail.isEmpty {
          Text(detail)
            .font(.caption2)
            .foregroundStyle(.secondary)
            .lineLimit(1)
        }
      }
      Spacer(minLength: 0)
      if selected {
        Image(systemName: "checkmark.circle.fill")
          .foregroundStyle(.tint)
      }
    }
  }
}
