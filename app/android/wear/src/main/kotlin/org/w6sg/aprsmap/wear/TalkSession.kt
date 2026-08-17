/// The state of one attempt to say something.
///
/// Shorter-lived than its watchOS namesake, and deliberately so. There the clip has to
/// reach the phone and come back as text before there is anything to send, which is a
/// multi-second gap with three named stages and three ways to fail. Here the recogniser is
/// on the wrist (see SpeechCapture.kt), so the only wait is the recogniser finishing its
/// last word — but it is still a wait during which the operator has already taken their
/// eyes off the watch, so it is still named and shown rather than hidden behind a spinner.
///
/// Counterpart: `app/ios/WatchApp/Sources/TalkSession.swift`.
package org.w6sg.aprsmap.wear

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

object TalkSession {
    sealed interface Phase {
        object Idle : Phase
        object Listening : Phase
        object Transcribing : Phase
        data class Failed(val reason: String) : Phase
    }

    var phase by mutableStateOf<Phase>(Phase.Idle)
        private set

    /// Beyond this we stop waiting. Unbounded is worse than an error that offers a way out:
    /// a spinner that never resolves during a net is indistinguishable from a dead app.
    private const val RESULT_TIMEOUT_MS = 12_000L

    private var watchdog: Job? = null

    val isBusy: Boolean
        get() = phase is Phase.Listening || phase is Phase.Transcribing

    /// Words the recogniser produced, waiting for the Talk screen to pick them up and send.
    var pendingTranscript by mutableStateOf<String?>(null)

    fun beginListening() {
        watchdog?.cancel()
        phase = Phase.Listening
    }

    /// The finger is up and the recogniser is finishing. Start the clock: from here the app
    /// is waiting on something it does not control.
    fun transcribing() {
        if (phase !is Phase.Listening && phase !is Phase.Transcribing) return
        phase = Phase.Transcribing
        watchdog?.cancel()
        watchdog = mainScope.launch {
            delay(RESULT_TIMEOUT_MS)
            fail("No words came back")
        }
    }

    fun deliver(text: String) {
        watchdog?.cancel()
        val body = text.trim()
        if (body.isEmpty()) {
            fail("Didn't catch that")
            return
        }
        phase = Phase.Idle
        pendingTranscript = body
    }

    fun fail(reason: String) {
        watchdog?.cancel()
        phase = Phase.Failed(reason)
        Haptics.failure()
    }

    fun reset() {
        watchdog?.cancel()
        phase = Phase.Idle
    }

    /// What to put under the button. Null while listening — the timer is shown instead.
    val statusText: String?
        get() = when (val p = phase) {
            Phase.Idle -> null
            Phase.Listening -> null
            Phase.Transcribing -> "Transcribing…"
            is Phase.Failed -> p.reason
        }
}
