/// Talk — press and hold to reply.
///
/// Hold the button, speak, let go. The words are recognised on the watch and sent. There is
/// no confirm step: a countdown showing the transcription only protects an operator who is
/// looking at their wrist, which is the opposite of the situation push-to-talk exists for.
/// Verification happens afterwards instead — the watch says back what it sent, which works
/// with eyes on the road.
///
/// The gesture is a raw pointer loop rather than `detectTapGestures` or a long-press
/// detector. Both of those decide for themselves when a press has become something else: a
/// long press fires once after its threshold and reports nothing about release, and a tap
/// detector cancels when the finger drifts past the touch slop. On a moving vehicle the
/// finger always drifts, and a push-to-talk key that stops transmitting because the road
/// was bumpy is worse than no key at all. Down starts it; up ends it; nothing in between.
///
/// Counterpart: `app/ios/WatchApp/Sources/Views/PTTView.swift`.
package org.w6sg.aprsmap.wear.ui

import android.app.Activity
import android.content.Intent
import android.speech.RecognizerIntent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.material.Scaffold
import androidx.wear.compose.material.Text
import androidx.wear.compose.material.TimeText
import org.w6sg.aprsmap.wear.AppState
import org.w6sg.aprsmap.wear.Haptics
import org.w6sg.aprsmap.wear.MainActivity
import org.w6sg.aprsmap.wear.SpeechCapture
import org.w6sg.aprsmap.wear.TalkSession
import java.util.Locale

@Composable
fun TalkScreen(onOpenOutbox: () -> Unit) {
    val context = LocalContext.current
    val hasDestination = AppState.sharing && AppState.destination != null

    /// Recording needs a recogniser and the microphone. Unlike the watchOS twin it does NOT
    /// need the phone — the recogniser is on this wrist, which is what keeps push-to-talk
    /// working during exactly the outage it exists for.
    val canTalk = hasDestination &&
        SpeechCapture.granted == true &&
        SpeechCapture.recognizerAvailable

    // The system dictation screen, for a watch with no usable recogniser of our own or a
    // microphone we were refused. Slower to use — it ends on a confirm button, which is the
    // whole problem push-to-talk solves — but it works where the button cannot.
    val dictate = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode != Activity.RESULT_OK) return@rememberLauncherForActivityResult
        val text = result.data
            ?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)
            ?.firstOrNull()
            ?.trim()
            .orEmpty()
        if (text.isNotEmpty()) AppState.sendReply(text)
    }

    // The transcript arrives asynchronously, after the finger has left.
    LaunchedEffect(TalkSession.pendingTranscript) {
        val text = TalkSession.pendingTranscript
        if (!text.isNullOrEmpty()) {
            TalkSession.pendingTranscript = null
            AppState.sendReply(text)
        }
    }

    Scaffold(timeText = { TimeText() }) {
        Column(
            modifier = Modifier.fillMaxSize().padding(horizontal = 8.dp, vertical = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            StatusLine(onOpenOutbox)
            // Only visible while something is queued — see AnnouncerStopButton. Placed high,
            // above the Talk button, because it is a thing you reach for in a hurry.
            AnnouncerStopButton()

            Text(
                text = AppState.destination?.label ?: "No destination",
                style = MaterialTheme.typography.caption2,
                color = if (AppState.destination == null) Color(0xFFFF9F0A)
                else MaterialTheme.colors.onSurfaceVariant,
                maxLines = 1,
            )

            Box(
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentAlignment = Alignment.Center,
            ) {
                TalkButton(
                    canTalk = canTalk,
                    enabled = hasDestination,
                    onDictate = {
                        if (!hasDestination) return@TalkButton
                        if (SpeechCapture.denied) {
                            (context as? MainActivity)?.requestMicrophone()
                            return@TalkButton
                        }
                        dictate.launch(
                            Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).putExtra(
                                RecognizerIntent.EXTRA_LANGUAGE_MODEL,
                                RecognizerIntent.LANGUAGE_MODEL_FREE_FORM,
                            )
                        )
                    },
                )
            }

            Text(
                text = prompt(hasDestination, canTalk),
                style = MaterialTheme.typography.caption3,
                color = if (TalkSession.phase is TalkSession.Phase.Failed) Color(0xFFFF9F0A)
                else MaterialTheme.colors.onSurfaceVariant,
                maxLines = 2,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun TalkButton(canTalk: Boolean, enabled: Boolean, onDictate: () -> Unit) {
    val listening = SpeechCapture.isListening
    val accent = MaterialTheme.colors.primary
    val fill = if (listening) Color(0x59FF453A) else accent.copy(alpha = 0.2f)
    val stroke = if (listening) Color(0xFFFF453A) else accent

    val press = when {
        canTalk -> Modifier.pointerInput(canTalk) {
            awaitEachGesture {
                awaitFirstDown(requireUnconsumed = false)
                beginHold()
                // Drain every event until the finger is genuinely off the glass. No slop
                // test, no timeout: this is a key, and a key is down until it is up.
                do {
                    val event = awaitPointerEvent()
                } while (event.changes.any { it.pressed })
                endHold()
            }
        }
        // The dictation fallback is a tap, not a hold. Its own screen owns the microphone
        // from there, so holding the button would only mean holding a button that has
        // already handed control away.
        enabled -> Modifier.clickable { onDictate() }
        else -> Modifier
    }

    Box(
        modifier = Modifier
            .size(96.dp)
            .clip(CircleShape)
            .background(fill)
            .border(3.dp, stroke, CircleShape)
            .alpha(if (enabled) 1f else 0.35f)
            .then(press),
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                text = if (listening) "◉" else "🎙",
                style = MaterialTheme.typography.title2,
            )
            when {
                listening -> Text(
                    text = String.format(Locale.US, "%.1fs", SpeechCapture.elapsedMs / 1000.0),
                    style = MaterialTheme.typography.caption3,
                )

                TalkSession.isBusy -> Text("…", style = MaterialTheme.typography.caption3)

                else -> Text(
                    text = if (canTalk) "Hold" else "Talk",
                    style = MaterialTheme.typography.caption3,
                    color = MaterialTheme.colors.onSurfaceVariant,
                )
            }
        }
    }
}

private fun beginHold() {
    if (SpeechCapture.isListening || TalkSession.isBusy) return
    TalkSession.reset()
    if (SpeechCapture.begin()) {
        TalkSession.beginListening()
        Haptics.start()
    } else {
        TalkSession.fail("Microphone unavailable")
    }
}

private fun endHold() {
    if (!SpeechCapture.isListening) return
    Haptics.stop()
    // False means the press was too short to be speech: say nothing rather than nag.
    if (SpeechCapture.end()) TalkSession.transcribing() else TalkSession.reset()
}

/// Says what is happening, what just went out, or why the button is dead — never a dim
/// circle with no explanation.
private fun prompt(hasDestination: Boolean, canTalk: Boolean): String {
    if (!AppState.sharing) return "Start sharing on phone"
    if (!hasDestination) return "Swipe to Reply to and pick one"
    if (SpeechCapture.isListening) {
        return SpeechCapture.partial.ifEmpty { "Release to send" }
    }
    TalkSession.statusText?.let { return it }
    // Refused is different from not-yet-asked, and only the first can be fixed in Settings.
    if (SpeechCapture.denied) return "Allow Microphone, then tap"
    if (!SpeechCapture.recognizerAvailable) return "No speech engine — tap to dictate"
    if (!canTalk) return "Tap to dictate"
    if (AppState.lastSentText.isNotEmpty()) return "Sent: ${AppState.lastSentText}"
    return "Hold to talk"
}
