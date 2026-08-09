/// Review and auto-send.
///
/// The countdown is what makes this hands-free: the common case is a correct
/// transcript, and requiring a tap to confirm every reply would put the operator's
/// hand back on the watch for every transmission. So it sends itself, and the window
/// exists only to catch the wrong ones. Cancel keeps the text, because a
/// misrecognized message is usually worth editing rather than re-speaking.
import SwiftUI
import WatchKit

struct ConfirmSendView: View {
  @Environment(AppState.self) private var state
  @Binding var text: String
  @Binding var isPresented: Bool

  /// Long enough to read a short line and react, short enough not to feel like a
  /// confirmation dialog.
  private static let window: TimeInterval = 3

  @State private var remaining = Self.window
  @State private var ticker: Task<Void, Never>?

  var body: some View {
    VStack(spacing: 6) {
      HStack(spacing: 4) {
        Image(systemName: "arrow.up.circle.fill")
          .foregroundStyle(.tint)
        Text(state.destination?.label ?? "No destination")
          .font(.caption2)
          .foregroundStyle(.secondary)
          .lineLimit(1)
      }

      ScrollView {
        Text(text)
          .font(.body)
          .frame(maxWidth: .infinity, alignment: .leading)
      }

      HStack(spacing: 6) {
        Button(role: .cancel) {
          cancel()
        } label: {
          Image(systemName: "xmark")
        }
        .buttonStyle(.bordered)

        Button {
          send()
        } label: {
          Text(remaining > 0 ? "Send \(Int(remaining.rounded(.up)))" : "Send")
            .lineLimit(1)
        }
        .buttonStyle(.borderedProminent)

        TextFieldLink(prompt: Text("Edit")) {
          Image(systemName: "pencil")
        } onSubmit: { edited in
          // Editing is a deliberate act, so it stops the clock rather than racing it.
          ticker?.cancel()
          text = edited
          remaining = 0
        }
        .buttonStyle(.bordered)
      }
    }
    .padding(.horizontal, 4)
    .onAppear(perform: startCountdown)
    .onDisappear { ticker?.cancel() }
  }

  private func startCountdown() {
    ticker?.cancel()
    remaining = Self.window
    ticker = Task {
      while remaining > 0 {
        try? await Task.sleep(nanoseconds: 200_000_000)
        if Task.isCancelled { return }
        remaining = max(0, remaining - 0.2)
        // One tick per whole second, so the wrist counts down without needing eyes.
        if remaining > 0, abs(remaining.rounded() - remaining) < 0.001 {
          WKInterfaceDevice.current().play(.click)
        }
      }
      if !Task.isCancelled { send() }
    }
  }

  private func send() {
    ticker?.cancel()
    let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !body.isEmpty, let destination = state.destination else {
      isPresented = false
      return
    }
    let entry = PendingSend(
      id: UUID().uuidString,
      text: body,
      conversationId: destination.conversationId,
      recipients: destination.recipients,
      destinationLabel: destination.label,
      createdAt: Date(),
      state: .sending
    )
    Outbox.shared.add(entry)
    WatchSession.shared.submit(entry)
    text = ""
    isPresented = false
  }

  private func cancel() {
    ticker?.cancel()
    WKInterfaceDevice.current().play(.retry)
    isPresented = false
  }
}
