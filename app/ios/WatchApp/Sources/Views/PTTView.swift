/// Talk — press and hold to reply.
///
/// Hold the button, speak, let go. The clip goes to the iPhone, which transcribes it
/// and sends it. There is no confirm step: a countdown showing the transcription only
/// protects an operator who is looking at their wrist, which is the opposite of the
/// situation push-to-talk exists for. Verification happens afterwards instead — the
/// watch says back what it sent, which works with eyes on the road.
///
/// The gesture is a `DragGesture` with zero minimum distance rather than a
/// `LongPressGesture`. A long press fires once after its threshold and reports
/// nothing about release, which is precisely the half a PTT key needs; drag reports
/// both edges, so a finger sliding on a moving vehicle keeps transmitting instead of
/// cutting out.
///
/// Dictation remains as the fallback, because recording only works with the phone in
/// range — the recogniser lives there. When the phone is unreachable the button
/// becomes watchOS's dictation screen instead, which is slower to use but works on
/// the watch alone.
import SwiftUI
import WatchKit

struct PTTView: View {
  @Environment(AppState.self) private var state
  private var recorder: AudioRecorder { AudioRecorder.shared }
  private var talk: TalkSession { TalkSession.shared }

  private var hasDestination: Bool { state.sharing && state.destination != nil }

  /// Recording needs the phone: it holds the recogniser and the token.
  private var canRecord: Bool {
    hasDestination && state.phoneReachable && recorder.granted == true
  }

  var body: some View {
    VStack(spacing: 4) {
      StatusLine()
      // Only visible while something is queued — see AnnouncerStopButton. Placed high,
      // above the Talk button, because it is a thing you reach for in a hurry.
      AnnouncerStopButton()
      Text(state.destination?.label ?? "No destination")
        .font(.caption)
        .foregroundStyle(state.destination == nil ? .orange : .secondary)
        .lineLimit(1)

      button
        .frame(maxWidth: .infinity, maxHeight: .infinity)

      Text(prompt)
        .font(.caption2)
        .foregroundStyle(talkFailed ? .orange : .secondary)
        .lineLimit(2)
        .multilineTextAlignment(.center)
    }
    .padding(.horizontal, 4)
    .navigationTitle("Talk")
    .task {
      if !recorder.permissionKnown { await recorder.requestPermission() }
    }
    // The transcript arrives asynchronously, long after the finger has left.
    .onChange(of: talk.pendingTranscript) { _, text in
      guard let text, !text.isEmpty else { return }
      talk.pendingTranscript = nil
      state.sendReply(text)
    }
  }

  @ViewBuilder
  private var button: some View {
    if canRecord {
      face
        .contentShape(Circle())
        .gesture(
          DragGesture(minimumDistance: 0)
            .onChanged { _ in beginHold() }
            .onEnded { _ in endHold() }
        )
    } else if hasDestination {
      // No phone, or no microphone permission — fall back to the system dictation
      // screen, which needs neither. Its Done button is the confirmation, so this
      // sends straight out too.
      TextFieldLink(prompt: Text("Reply")) {
        face
      } onSubmit: { text in
        state.sendReply(text)
      }
      .buttonStyle(.plain)
    } else {
      face.opacity(0.35)
    }
  }

  private var face: some View {
    ZStack {
      Circle().fill(fillColor)
      Circle().strokeBorder(strokeColor, lineWidth: 3)
      VStack(spacing: 2) {
        Image(systemName: recorder.isRecording ? "waveform" : "mic.fill")
          .font(.title2)
        if recorder.isRecording {
          Text(String(format: "%.1fs", recorder.elapsed))
            .font(.caption2)
            .monospacedDigit()
        } else if talk.isBusy {
          ProgressView().controlSize(.mini)
        } else {
          Text(canRecord ? "Hold" : "Talk")
            .font(.caption2)
            .foregroundStyle(.secondary)
        }
      }
    }
  }

  private var fillColor: Color {
    recorder.isRecording ? Color.red.opacity(0.35) : Color.accentColor.opacity(0.2)
  }

  private var strokeColor: Color {
    recorder.isRecording ? .red : .accentColor
  }

  private var talkFailed: Bool {
    if case .failed = talk.phase { return true }
    return false
  }

  private func beginHold() {
    guard !recorder.isRecording, !talk.isBusy else { return }
    talk.reset()
    Task {
      guard await recorder.start() else {
        talk.fail("Microphone unavailable")
        return
      }
      talk.beginRecording()
      WKInterfaceDevice.current().play(.start)
    }
  }

  private func endHold() {
    guard recorder.isRecording else { return }
    WKInterfaceDevice.current().play(.stop)
    guard let url = recorder.stop() else {
      talk.reset() // too short to be speech; say nothing rather than nag
      return
    }
    let id = UUID().uuidString
    if WatchSession.shared.transferAudio(url, clientId: id) {
      talk.submitted(clientId: id)
    } else {
      talk.fail("iPhone unreachable")
    }
  }

  /// Says what is happening, what just went out, or why the button is dead — never a
  /// dim circle with no explanation.
  private var prompt: String {
    if !state.sharing { return "Start sharing on iPhone" }
    if state.destination == nil { return "Swipe to Reply to and pick one" }
    if let status = talk.statusText { return status }
    if recorder.isRecording { return "Release to send" }
    // Refused is different from not-yet-asked, and only the first can be fixed in
    // Settings. Before this distinction existed the app fell back to the dictation
    // screen and said nothing at all about why, which is how a greyed-out Microphone
    // toggle went unexplained.
    if recorder.denied { return "Allow Microphone in Watch Settings" }
    if recorder.granted == false { return "Microphone unavailable" }
    if !state.phoneReachable { return "iPhone away — tap to dictate" }
    if !state.lastSentText.isEmpty { return "Sent: \(state.lastSentText)" }
    return "Hold to talk"
  }
}
