/// One message in full, with a deliberate replay.
///
/// "Speak again" exists because the announcement is easy to miss — the watch may
/// have been off-wrist, the speaker muted, or a truck going past. It bypasses the
/// launch watermark since the user is explicitly asking for it.
import SwiftUI

struct MessageDetailView: View {
  @Environment(AppState.self) private var state
  let message: WatchMessage

  var body: some View {
    ScrollView {
      VStack(alignment: .leading, spacing: 8) {
        if message.broadcast {
          Label("All Trackers", systemImage: "megaphone.fill")
            .font(.caption2)
            .foregroundStyle(.orange)
        }
        Text(message.senderLabel)
          .font(.headline)
        Text(message.date, format: .dateTime.hour().minute())
          .font(.caption2)
          .foregroundStyle(.secondary)
        Text(message.displayText)
          .font(.body)
        Button {
          state.speakAgain(message)
        } label: {
          Label("Speak again", systemImage: "speaker.wave.2.fill")
        }
        .buttonStyle(.bordered)
        .padding(.top, 4)
      }
      .frame(maxWidth: .infinity, alignment: .leading)
      .padding(.horizontal, 4)
    }
    .navigationTitle(message.senderLabel)
    .navigationBarTitleDisplayMode(.inline)
  }
}
