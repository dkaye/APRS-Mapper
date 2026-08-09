/// Talk — the reply screen.
///
/// Tap, speak into watchOS's own dictation screen, tap Done, and it sends itself.
///
/// Why a tap rather than a press-and-hold: `Speech.framework` does not exist on
/// watchOS, so this app has no way to run a recognizer, open the microphone, or show
/// words appearing as they are spoken. The only sanctioned route to dictated text is
/// to ask the system for it — `TextFieldLink` puts up Apple's input screen, which
/// owns the microphone and the transcription and hands back a finished string. That
/// screen ends on its own Done button, so the start and end of listening are Apple's
/// to define, not ours. Everything after Done is ours again, which is why the send
/// still needs no further tap.
import SwiftUI
import WatchKit

struct PTTView: View {
  @Environment(AppState.self) private var state

  @State private var captured = ""
  @State private var showConfirm = false
  private var canTalk: Bool { state.sharing && state.destination != nil }

  private func accept(_ text: String?) {
    let body = (text ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    guard !body.isEmpty else { return }
    captured = body
    showConfirm = true
  }

  var body: some View {
    VStack(spacing: 6) {
      Text(state.destination?.label ?? "No destination")
        .font(.caption)
        .foregroundStyle(state.destination == nil ? .orange : .secondary)
        .lineLimit(1)

      if canTalk {
        TextFieldLink(prompt: Text("Reply")) {
          talkButton
        } onSubmit: { accept($0) }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
      } else {
        talkButton
          .opacity(0.35)
          .frame(maxWidth: .infinity, maxHeight: .infinity)
      }

      Text(prompt)
        .font(.caption2)
        .foregroundStyle(.secondary)
        .lineLimit(2)
        .multilineTextAlignment(.center)
    }
    .padding(.horizontal, 4)
    .sheet(isPresented: $showConfirm) {
      ConfirmSendView(text: $captured, isPresented: $showConfirm)
    }
    .navigationTitle("Talk")
  }

  private var talkButton: some View {
    ZStack {
      Circle().fill(Color.accentColor.opacity(0.2))
      Circle().strokeBorder(Color.accentColor, lineWidth: 3)
      VStack(spacing: 2) {
        Image(systemName: "mic.fill")
          .font(.title2)
        Text("Talk")
          .font(.caption2)
          .foregroundStyle(.secondary)
      }
    }
  }

  /// Says why the button is dead rather than leaving the user pressing a dim circle.
  private var prompt: String {
    if !state.sharing { return "Start sharing on iPhone" }
    if state.destination == nil { return "Choose a destination below" }
    return "Tap, speak, then Done"
  }
}

// Which input method the system offers is not ours to choose. TextFieldLink takes
// only a prompt and a label, and watchOS reopens on whichever method was last used —
// the keyboard, for anyone who has ever typed on the Watch. WatchKit's older
// presentTextInputController(withSuggestions: nil, allowedInputMode: .plain) used to
// open dictation directly and was tried here; on watchOS 26 it made no difference,
// so the extra code path was removed rather than left in looking load-bearing.
//
// In practice: the input screen's lower-right button switches method, and dictation
// is behind it. Once chosen, the system remembers.
