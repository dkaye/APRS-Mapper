/// Haptic, tone, then speech — the watch's whole output path.
///
/// Order matters. The haptic goes first and always, because it is the only signal that
/// survives a muted stream, a wrist turned away, and no audio route at all. The tone and
/// the speech are best-effort on top of it: with no Bluetooth device connected Wear OS
/// routes this to the built-in speaker, which some watches do not have at all, which
/// Theater Mode kills outright, and which is quiet in the sort of noisy environment this
/// app exists for.
///
/// Announcements are serialized. Two messages a second apart must not produce
/// "From A / From B / text of A / text of B" — the same reasoning as the phone's chained
/// utterances in messaging_screen.dart.
///
/// Counterpart: `app/ios/WatchApp/Sources/Announcer.swift`. Same rules and the same
/// numbers; the machinery underneath is TextToSpeech and AudioManager rather than
/// AVSpeechSynthesizer and AVAudioSession.
package org.w6sg.aprsmap.wear

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFocusRequest
import android.media.AudioManager
import android.media.MediaPlayer
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import java.util.Locale
import java.util.UUID
import kotlin.math.min
import kotlin.math.roundToInt

object Announcer {
    private const val TAG = "AprsWear"

    /// One tone per this window however many messages land, so a burst does not become a
    /// machine-gun.
    private const val MIN_TONE_INTERVAL_MS = 2_000L

    /// How old a message may be, by the time it was SENT, before announcing it does more
    /// harm than good. The same five minutes the phone uses, deliberately — one number that
    /// can be explained in a sentence: you will not hear anything older than this.
    const val MAX_AGE_SEC = 300L

    /// Characters of speech per second, for the countdown on the Stop control. Rough on
    /// purpose — it exists to answer "wait, or stop it", not to be a clock.
    private const val CHARS_PER_SECOND = 14.0

    /// What is left to say. Read by the Stop control, which is the only way to interrupt an
    /// announcement the automatic rules judged worth making.
    var pendingCount by mutableStateOf(0)
        private set
    var pendingSeconds by mutableStateOf(0)
        private set

    /// Result of the ambient-screen audio test — see runAmbientAudioTest().
    var dimTestResult by mutableStateOf<String?>(null)
        private set
    var dimTestRunning by mutableStateOf(false)
        private set

    private data class Announcement(
        val doubleHaptic: Boolean,
        val utterances: List<String>,
        /// When the thing being announced was SENT, for the age rule. Zero means "not a
        /// message" — a receipt or a status line, which is about something the operator just
        /// did and is never stale.
        val ts: Long = 0,
        /// Receipts get no alert tone: they answer something the user just did, rather than
        /// interrupting them with something new, and a tone per state change would mean
        /// three chimes for every reply.
        val tone: Boolean = true,
    ) {
        val estSeconds: Double
            get() = utterances.sumOf { it.length / CHARS_PER_SECOND } + 0.5
    }

    private val queue = ArrayDeque<Announcement>()

    /// What is being spoken right now. Held separately because it has already been taken off
    /// the queue: counting it but not its duration is why the button read "Speaking · 0s"
    /// for every single announcement — the one case where the queue is empty and something
    /// is still being said, which is also the commonest case.
    private var current: Announcement? = null
    private var drainJob: Job? = null
    private var lastToneAt = 0L

    private var player: MediaPlayer? = null

    // ── entry point ─────────────────────────────────────────────────────────────

    fun enqueue(messages: List<WatchMessage>, ignoreAge: Boolean = false) {
        if (messages.isEmpty()) return

        // Anything older than the window is not announced at all. After the wrist has been
        // out of range for an hour, everything arrives at once and every one of them is
        // "new" — dating them is the only test that distinguishes a message worth
        // interrupting somebody for from a backlog worth reading on screen. The messages
        // themselves are kept and listed; only the announcement is dropped.
        val now = System.currentTimeMillis() / 1000
        val fresh = messages
            .filter { ignoreAge || now - it.ts <= MAX_AGE_SEC }
            .sortedBy { it.id }
        if (fresh.isEmpty()) return

        // A net-wide call gets a distinct double buzz so the wrist alone tells the operator
        // whether something was addressed to them.
        val anyBroadcast = fresh.any { it.broadcast }

        // One announcement per message rather than one for the batch, so the Stop control
        // can show a true count and stopping takes effect at the next message rather than at
        // the end of a single long utterance list.
        for (m in fresh) {
            queue.addLast(
                Announcement(
                    doubleHaptic = anyBroadcast && m.broadcast,
                    utterances = listOf(m.announcementPhrase, m.bodyPhrase).filter { it.isNotEmpty() },
                    // A deliberate replay is never stale, whatever its timestamp says.
                    ts = if (ignoreAge) 0 else m.ts.toLong(),
                )
            )
        }
        publish()
        drain()
    }

    /// Confirms a reply went out, optionally repeating the words.
    ///
    /// The confirmation itself is not optional — it is the first of the three states an
    /// operator tracks, alongside delivered and read. Only whether it carries the text is a
    /// preference: repeating it is the one transcription check that works with eyes on the
    /// road, and it is also more airtime during a busy net.
    ///
    /// No tone either way: this answers something the operator just did rather than
    /// interrupting them with something new.
    fun announceSent(text: String, repeatingWords: Boolean) {
        val phrase = if (repeatingWords && text.isNotEmpty()) "Sent. $text" else "Message sent."
        queue.addLast(Announcement(doubleHaptic = false, utterances = listOf(phrase), tone = false))
        publish()
        drain()
    }

    /// Confirms the fate of a reply the user just spoke: sent, delivered, read.
    ///
    /// Announced aloud because the whole point of talking into the wrist is that the
    /// operator is not looking at it — a checkmark they never see confirms nothing. The
    /// wording matches the phone's ack label, including the "N of M" form for a group, so
    /// the two devices never disagree about what "delivered" means.
    fun announceReceipt(stage: String, count: Int, total: Int) {
        val phrase = when (stage) {
            "sent" -> "Message sent."
            "delivered" -> if (total > 1) "Message delivered to $count of $total." else "Message delivered."
            "read" -> if (total > 1) "Message read by $count of $total." else "Message read."
            else -> return
        }
        if (stage == "read") Haptics.success() else Haptics.click()
        queue.addLast(Announcement(doubleHaptic = false, utterances = listOf(phrase), tone = false))
        publish()
        drain()
    }

    fun stop() {
        queue.clear()
        current = null
        publish()
        drainJob?.cancel()
        drainJob = null
        Speech.stop()
        runCatching { player?.stop() }
        releasePlayer()
        abandonFocus()
    }

    /// Keeps the Stop control's count and countdown current. Called wherever the queue
    /// changes — appending, draining, or being emptied.
    private fun publish() {
        val all = listOfNotNull(current) + queue
        pendingCount = all.size
        pendingSeconds = all.sumOf { it.estSeconds }.roundToInt()
    }

    // ── the serial drain ────────────────────────────────────────────────────────

    private fun drain() {
        if (drainJob?.isActive == true) return
        drainJob = mainScope.launch {
            while (queue.isNotEmpty()) {
                val next = queue.removeFirst()
                // Re-checked here, not only on the way in: an announcement can sit behind a
                // long one and go stale while it waits, and saying it then is the same
                // mistake as saying it after an outage.
                //
                // Before `current` is set, not after. Setting it first and then skipping
                // would leave the discarded announcement showing on the Stop button until
                // something else replaced it — and if it were the last one, for good.
                if (next.ts > 0 && System.currentTimeMillis() / 1000 - next.ts > MAX_AGE_SEC) {
                    publish()
                    continue
                }
                current = next
                publish()
                play(next)
                current = null
                publish()
            }
            abandonFocus()
            drainJob = null
            publish()
        }
    }

    private suspend fun play(a: Announcement) {
        // Receipts have already buzzed with a haptic that suits their meaning; a second
        // generic notification buzz would just make a confirmation feel like new traffic.
        if (a.tone) Haptics.notification(double = a.doubleHaptic)

        val wantsAudio = (a.tone && shouldPlayTone()) || a.utterances.isNotEmpty()
        val ready = wantsAudio && requestFocus() && Speech.ready()
        AppState.reportAudioUnavailable(wantsAudio && !ready)
        if (!ready) return // haptic already fired; that is the guaranteed part

        if (a.tone && shouldPlayTone(consume = true)) {
            playTone()
        }

        a.utterances.forEachIndexed { index, text ->
            // The same 500 ms the phone leaves between "From X." and the text
            // (messaging_screen.dart _kSpeakGap), so all three devices sound like one app.
            if (index > 0) delay(500)
            Speech.speak(text)
        }
    }

    private fun shouldPlayTone(consume: Boolean = false): Boolean {
        val now = System.currentTimeMillis()
        if (now - lastToneAt < MIN_TONE_INTERVAL_MS) return false
        if (consume) lastToneAt = now
        return true
    }

    private suspend fun playTone() {
        val context = WearApp.appContext
        // The four-argument overload, because attributes have to be set before the player
        // prepares and `create(context, resId)` returns one already prepared — setting them
        // afterwards throws IllegalStateException, and the tone would vanish with only a
        // logcat line to say why.
        val p = runCatching {
            MediaPlayer.create(
                context,
                R.raw.message,
                speechAttributes,
                audioManager().generateAudioSessionId(),
            )
        }.getOrNull() ?: return
        player = p
        runCatching { p.start() }
        // Bounded so a device with no speaker cannot stall the queue behind a clip that
        // will never finish. Two seconds is the same cap the watchOS port uses.
        delay(min(p.duration.toLong().coerceAtLeast(0), 2_000L))
        releasePlayer()
    }

    private fun releasePlayer() {
        runCatching { player?.release() }
        player = null
    }

    // ── audio focus ─────────────────────────────────────────────────────────────

    private val speechAttributes: AudioAttributes = AudioAttributes.Builder()
        .setUsage(AudioAttributes.USAGE_ASSISTANCE_ACCESSIBILITY)
        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
        .build()

    private var focusRequest: AudioFocusRequest? = null

    private fun audioManager(): AudioManager =
        WearApp.appContext.getSystemService(Context.AUDIO_SERVICE) as AudioManager

    /// Duck whatever else is playing rather than stopping it. An operator with navigation
    /// or music running should hear the message over the top and get their audio back
    /// afterwards — the same bargain `.duckOthers` strikes on the other platform.
    private fun requestFocus(): Boolean {
        if (focusRequest != null) return true
        val request = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK)
            .setAudioAttributes(speechAttributes)
            .setWillPauseWhenDucked(false)
            .build()
        val granted = audioManager().requestAudioFocus(request) ==
            AudioManager.AUDIOFOCUS_REQUEST_GRANTED
        if (granted) focusRequest = request
        return granted
    }

    /// Hand the route back so ducked navigation or music comes up again.
    private fun abandonFocus() {
        focusRequest?.let { audioManager().abandonAudioFocusRequest(it) }
        focusRequest = null
    }

    // ── the ambient-screen test ─────────────────────────────────────────────────

    /// Wait, then try to speak, and record exactly what happened.
    ///
    /// The delay is the whole point: it exists so the operator can lower their wrist and let
    /// the watch enter ambient before the attempt. Measuring this while somebody is looking
    /// at the watch would measure the case we already know works.
    ///
    /// The question cannot be settled by reading documentation or reasoning about lifecycle
    /// callbacks — does this watch actually let the app speak once the screen has dimmed?
    /// The same question on watchOS was answered by assumption for a year, wrongly, and it
    /// cost that app most of its usefulness on the wrist. Wear OS hardware varies more than
    /// Apple's does — some watches have no speaker at all — so it matters more here, not
    /// less.
    ///
    /// Three things are recorded separately, because they fail independently and the remedy
    /// differs for each: whether the app still counted as on-screen, whether audio focus was
    /// granted, and whether the utterance actually finished rather than being cut off the
    /// instant it began. On the wrist all three sound identical — silence.
    fun runAmbientAudioTest(seconds: Int = 12) {
        if (dimTestRunning) return
        dimTestRunning = true
        mainScope.launch {
            for (remaining in seconds downTo 1) {
                dimTestResult = "Lower your wrist — testing in ${remaining}s"
                delay(1_000)
            }
            val wasActive = AppState.isActive
            val promised = AppState.canAnnounce
            val started = System.currentTimeMillis()
            val focused = requestFocus()
            val engine = focused && Speech.ready()
            var finished = false
            if (engine) {
                finished = Speech.speak(
                    "Audio test. If you heard this with the screen dimmed, the watch can speak in ambient."
                )
            }
            abandonFocus()
            val took = (System.currentTimeMillis() - started) / 1000.0
            val appPart = (if (wasActive) "app active" else "app AMBIENT") +
                " · " + (if (promised) "will speak" else "defers to phone")
            val focusPart = if (focused) "focus granted" else "focus REFUSED"
            val enginePart = when {
                !focused -> "not attempted"
                !engine -> "no TTS engine"
                finished -> "spoke"
                else -> "cut off"
            }
            dimTestResult = "$appPart · $focusPart · $enginePart · " +
                String.format(Locale.US, "%.1fs", took)
            dimTestRunning = false
        }
    }
}

/// The text-to-speech engine, and waiting for one utterance before starting the next.
///
/// Every path is watchdogged. If the engine never reports back — no audio route, a route
/// yanked mid-sentence, a service that died — the await would never complete, and because
/// the drain is serial that would wedge every future announcement for the life of the
/// process. A wedged announcer is indistinguishable from a dead app during a net, so a
/// missed callback has to degrade to a missed sentence rather than a missed shift.
private object Speech {
    private const val TAG = "AprsWear"

    private var engine: TextToSpeech? = null
    private var initialized: CompletableDeferred<Boolean>? = null
    private val waiting = HashMap<String, CompletableDeferred<Boolean>>()

    /// True once an engine exists and has a voice. Creating it is asynchronous and can
    /// fail outright on a watch with no TTS data installed, which is a real configuration
    /// and not an error worth crashing over — the haptic still fires.
    suspend fun ready(): Boolean {
        initialized?.let { return it.await() }
        val gate = CompletableDeferred<Boolean>()
        initialized = gate
        // Configured after the gate rather than inside the init callback: that callback can
        // fire before the constructor has returned, so anything it touches through `engine`
        // may still be null.
        val tts = TextToSpeech(WearApp.appContext) { status ->
            gate.complete(status == TextToSpeech.SUCCESS)
        }
        engine = tts
        tts.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) {}
            override fun onDone(utteranceId: String?) = finish(utteranceId, true)

            @Deprecated("Required by the base class; the API 21+ overload delegates here.")
            override fun onError(utteranceId: String?) = finish(utteranceId, false)
            override fun onError(utteranceId: String?, errorCode: Int) = finish(utteranceId, false)
            override fun onStop(utteranceId: String?, interrupted: Boolean) = finish(utteranceId, false)
        })
        if (!gate.await()) {
            Log.w(TAG, "no text-to-speech engine on this watch; haptics only")
            return false
        }
        tts.language = Locale.US
        // The phone uses flutter_tts rate 0.5, which lands near the platform default; a
        // touch under keeps every device sounding like the same app.
        tts.setSpeechRate(0.9f)
        return true
    }

    /// Returns whether the utterance actually finished. Used by the ambient test, which has
    /// to tell "spoke" from "cut off"; the drain itself does not care.
    suspend fun speak(text: String): Boolean {
        if (text.isEmpty()) return true
        val tts = engine ?: return false
        val id = UUID.randomUUID().toString()
        val gate = CompletableDeferred<Boolean>()
        synchronized(waiting) { waiting[id] = gate }
        val queued = tts.speak(text, TextToSpeech.QUEUE_ADD, null, id)
        if (queued != TextToSpeech.SUCCESS) {
            synchronized(waiting) { waiting.remove(id) }
            return false
        }
        // Generous upper bound: roughly two words a second plus a fixed allowance, so a
        // normal sentence never trips it and a stuck one does not hang around.
        val words = text.split(' ').count { it.isNotBlank() }.coerceAtLeast(1)
        val limitMs = min(45_000L, 5_000L + words * 500L)
        val done = withTimeoutOrNull(limitMs) { gate.await() }
        if (done == null) {
            Log.w(TAG, "utterance never reported back; stopping the engine")
            runCatching { tts.stop() }
            synchronized(waiting) { waiting.remove(id) }
            return false
        }
        return done
    }

    fun stop() {
        runCatching { engine?.stop() }
        synchronized(waiting) {
            waiting.values.forEach { it.complete(false) }
            waiting.clear()
        }
    }

    private fun finish(utteranceId: String?, ok: Boolean) {
        val id = utteranceId ?: return
        synchronized(waiting) { waiting.remove(id) }?.complete(ok)
    }
}
