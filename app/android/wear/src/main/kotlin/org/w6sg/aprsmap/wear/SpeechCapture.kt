/// Turning the held button into words.
///
/// **This is where the Wear port stops being a translation of the watchOS one.** watchOS
/// has no speech recogniser at all — `Speech.framework` is absent from the SDK — so the
/// Apple Watch records AAC, ships the clip to the iPhone over WatchConnectivity, waits for
/// the iPhone to transcribe it, and gets text back. That is three network hops and a
/// `TalkSession` state machine with a "Sending audio…" phase, and it does not work at all
/// when the phone is away.
///
/// Wear OS has a recogniser on the watch. So this records nothing, transfers nothing, and
/// asks the phone for nothing: press, speak, release, send. It is faster, it keeps the
/// operator's traffic on the wrist, and — the part that matters during an event — it is the
/// one piece of push-to-talk that keeps working when the phone is out of range, which is
/// precisely when somebody is most likely to be talking into their wrist.
///
/// On-device first, and not only for speed. This is a ham operator's net traffic; there is
/// no reason to hand it to Google if the watch can do the work itself. The same choice the
/// iPhone side makes in `WatchBridge.transcribe`.
///
/// The gesture is a press-and-hold, not a tap. Wear OS's own dictation screen ends on a
/// confirm button, and on a moving vehicle that button is the whole problem.
package org.w6sg.aprsmap.wear

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

object SpeechCapture {
    private const val TAG = "AprsWear"

    /// Shorter than this and the press was a mis-tap, not an attempt to talk.
    const val MINIMUM_HOLD_MS = 400L

    /// A stuck button must not hold the microphone open indefinitely.
    const val MAXIMUM_HOLD_MS = 60_000L

    var isListening by mutableStateOf(false)
        private set
    var elapsedMs by mutableStateOf(0L)
        private set

    /// What has been heard so far. Shown while the button is held, because a recogniser
    /// that is listening and one that has silently died look identical otherwise.
    var partial by mutableStateOf("")
        private set

    var granted by mutableStateOf<Boolean?>(null)
        private set

    /// True once the operator has actively refused, as opposed to never having been asked.
    /// The two look identical from `granted == false` and need opposite advice: one is fixed
    /// by asking, the other only in Settings.
    var denied by mutableStateOf(false)

    /// Whether this watch has a recogniser at all. Wear OS does not guarantee one, and a
    /// button that does nothing with no explanation is worse than a button that says why.
    var recognizerAvailable by mutableStateOf(true)
        private set

    /// Whether the last attempt ran without the network. Reported in Settings for the same
    /// reason the iPhone reports it: a second on-device and eight over the network feel like
    /// the same unexplained wait from the wrist, and only one of them is worth doing
    /// anything about.
    var lastOnDevice by mutableStateOf(false)
        private set
    var lastMs by mutableStateOf<Int?>(null)
        private set

    private var recognizer: SpeechRecognizer? = null
    private var ticker: Job? = null
    private var startedAt = 0L
    private var onDevice = false

    val permissionKnown: Boolean get() = granted != null

    fun refreshPermission(context: Context = WearApp.appContext) {
        val ok = ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) ==
            PackageManager.PERMISSION_GRANTED
        granted = ok
        if (ok) denied = false
        recognizerAvailable = SpeechRecognizer.isRecognitionAvailable(context) ||
            (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
                SpeechRecognizer.isOnDeviceRecognitionAvailable(context))
    }

    /// Open the microphone. Must be called on the main thread — SpeechRecognizer requires
    /// it and throws otherwise, which on a watch surfaces as a button that works in debug
    /// and crashes in the field.
    fun begin(): Boolean {
        if (isListening) return false
        val context = WearApp.appContext
        if (granted != true) return false

        // Never record ourselves: a message being read aloud would land in the recogniser.
        Announcer.stop()

        val engine = create(context) ?: run {
            recognizerAvailable = false
            return false
        }
        recognizer = engine
        engine.setRecognitionListener(Listener)

        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, "en-US")
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
            putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, context.packageName)
            // The recogniser's own end-of-speech detection is the enemy here. A push-to-talk
            // key ends when the finger lifts, not when the speaker pauses to think, and the
            // default silence timeouts cut an operator off mid-sentence. Pushed out far
            // enough that the release is always what stops it.
            putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS, 30_000L)
            putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_POSSIBLY_COMPLETE_SILENCE_LENGTH_MILLIS, 30_000L)
            putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_MINIMUM_LENGTH_MILLIS, 1_000L)
            // No EXTRA_PREFER_OFFLINE on the general recogniser. It sounds like the private
            // choice, but on a watch with no offline model installed it does not fall back
            // — it returns ERROR_NO_MATCH, and push-to-talk fails in a way that reads as
            // "you mumbled". Privacy on this path comes from the on-device recogniser above
            // when the watch actually has one.
        }

        return try {
            engine.startListening(intent)
            partial = ""
            startedAt = System.currentTimeMillis()
            elapsedMs = 0
            isListening = true
            startTicking()
            true
        } catch (e: Exception) {
            Log.w(TAG, "startListening refused: ${e.message}")
            release()
            false
        }
    }

    /// On-device where the watch has it. Falls back to the general recogniser, which on most
    /// watches is Google's and needs the network.
    private fun create(context: Context): SpeechRecognizer? = try {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            SpeechRecognizer.isOnDeviceRecognitionAvailable(context)
        ) {
            onDevice = true
            SpeechRecognizer.createOnDeviceSpeechRecognizer(context)
        } else if (SpeechRecognizer.isRecognitionAvailable(context)) {
            onDevice = false
            SpeechRecognizer.createSpeechRecognizer(context)
        } else {
            null
        }
    } catch (e: Exception) {
        Log.w(TAG, "no recogniser: ${e.message}")
        null
    }

    /// Finger up. Returns false when the press was too short to be speech, in which case
    /// nothing is said rather than nagging about a mis-tap.
    fun end(): Boolean {
        if (!isListening) return false
        val held = System.currentTimeMillis() - startedAt
        isListening = false
        ticker?.cancel()
        ticker = null

        if (held < MINIMUM_HOLD_MS) {
            cancel()
            return false
        }
        // stopListening, not cancel: it tells the recogniser that the audio has ended and
        // to produce a final result from what it already has. Cancelling throws it away.
        runCatching { recognizer?.stopListening() }
        return true
    }

    fun cancel() {
        isListening = false
        ticker?.cancel()
        ticker = null
        runCatching { recognizer?.cancel() }
        release()
    }

    private fun release() {
        runCatching { recognizer?.destroy() }
        recognizer = null
        isListening = false
    }

    private fun startTicking() {
        ticker?.cancel()
        ticker = mainScope.launch {
            while (isActive) {
                delay(100)
                elapsedMs = System.currentTimeMillis() - startedAt
                if (elapsedMs >= MAXIMUM_HOLD_MS) {
                    end()
                    return@launch
                }
            }
        }
    }

    // ── the recogniser's side of the conversation ───────────────────────────────

    private object Listener : RecognitionListener {
        /// When the recogniser said it was ready, so the figure reported in Settings is the
        /// wait the operator actually experienced rather than the length of their sentence.
        private var readyAt = 0L

        override fun onReadyForSpeech(params: Bundle?) {
            readyAt = System.currentTimeMillis()
        }

        override fun onBeginningOfSpeech() {}
        override fun onRmsChanged(rmsdB: Float) {}
        override fun onBufferReceived(buffer: ByteArray?) {}

        override fun onEndOfSpeech() {
            onMain { TalkSession.transcribing() }
        }

        override fun onPartialResults(partialResults: Bundle?) {
            val text = firstResult(partialResults) ?: return
            onMain { SpeechCapture.partial = text }
        }

        override fun onResults(results: Bundle?) {
            val text = firstResult(results)
            onMain {
                SpeechCapture.lastMs = (System.currentTimeMillis() - readyAt).toInt()
                SpeechCapture.lastOnDevice = SpeechCapture.onDevice
                SpeechCapture.release()
                if (text.isNullOrBlank()) TalkSession.fail("Didn't catch that")
                else TalkSession.deliver(text.trim())
            }
        }

        override fun onError(error: Int) {
            onMain {
                SpeechCapture.release()
                // A no-match or a timeout after the finger has already lifted is not worth
                // an error banner — the operator said nothing, and they know it. Anything
                // else is a real failure they need to see, because the alternative is a
                // reply they believe went out and did not.
                when (error) {
                    SpeechRecognizer.ERROR_NO_MATCH,
                    SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> TalkSession.fail("Didn't catch that")

                    SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> {
                        SpeechCapture.granted = false
                        SpeechCapture.denied = true
                        TalkSession.fail("Microphone not allowed")
                    }

                    SpeechRecognizer.ERROR_NETWORK,
                    SpeechRecognizer.ERROR_NETWORK_TIMEOUT ->
                        TalkSession.fail("No network for speech")

                    else -> TalkSession.fail("Speech failed ($error)")
                }
            }
        }

        override fun onEvent(eventType: Int, params: Bundle?) {}

        private fun firstResult(bundle: Bundle?): String? =
            bundle?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull()
    }
}
