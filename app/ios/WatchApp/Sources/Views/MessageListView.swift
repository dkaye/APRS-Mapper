/// Message history, newest first.
///
/// Reversed relative to the phone's thread view on purpose: the watch is glanced at,
/// not read, so the thing most likely to be wanted is under the thumb rather than a
/// scroll away.
import SwiftUI

struct MessageListView: View {
  @Environment(AppState.self) private var state

  var body: some View {
    List {
      if state.messages.isEmpty {
        Text("No messages yet")
          .font(.caption)
          .foregroundStyle(.secondary)
      }
      ForEach(state.messages.reversed()) { m in
        NavigationLink {
          MessageDetailView(message: m)
        } label: {
          MessageRow(message: m)
        }
      }
    }
    .navigationTitle("Messages")
  }
}

struct MessageRow: View {
  let message: WatchMessage

  var body: some View {
    VStack(alignment: .leading, spacing: 2) {
      HStack(spacing: 4) {
        if message.broadcast {
          Image(systemName: "megaphone.fill")
            .font(.caption2)
            .foregroundStyle(.orange)
        }
        Text(message.senderLabel)
          .font(.caption)
          .foregroundStyle(message.broadcast ? .orange : .secondary)
        Spacer(minLength: 0)
        Text(message.date, style: .time)
          .font(.caption2)
          .foregroundStyle(.secondary)
      }
      Text(message.displayText)
        .font(.body)
        .lineLimit(2)
    }
    .padding(.vertical, 2)
  }
}
