/// Single source of truth for the watch app, and the only funnel into the Announcer.
///
/// Everything that can produce a message — the live MessageClient path, the durable
/// DataClient path, the application context, and the watch's own direct poll — converges
/// on `ingest`. That is what makes "exactly one tone per message" a property of the design
/// rather than something each caller has to remember.
///
/// Port of `app/ios/WatchApp/Sources/AppState.swift`. The rules are the same rules; where
/// this file diverges it says so, and it diverges only where Wear OS genuinely differs
/// from watchOS rather than where it merely spells things differently.
package org.w6sg.aprsmap.wear

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import org.json.JSONArray
import org.json.JSONObject

object AppState {
    /// Ascending by id, capped. Ordering is by id and never by ts, matching the server's
    /// `ORDER BY m.id` — a device with a skewed clock must not be able to reorder a net's
    /// traffic.
    val messages = mutableStateListOf<WatchMessage>()
    val conversations = mutableStateListOf<WatchConversation>()

    var destination by mutableStateOf<Destination?>(null)
        private set

    /// Say the words back after a reply goes out.
    ///
    /// The one thing left to choose. It is the only verification of a transcription that
    /// survives the use case — the confirm screen it replaced showed the text before
    /// sending, which protects nobody whose eyes are on the road — but it is also the
    /// operator's own words taking up airtime they were not listening for. Off by default;
    /// the send is still confirmed either way.
    ///
    /// Everything else that used to be optional is not. Messages are always announced,
    /// always read aloud, and always announced the same way, broadcasts included: a watch
    /// that might or might not tell you something, depending on switches set hours
    /// earlier, is worse than no watch at all during a net.
    var readBackSent by mutableStateOf(false)
        private set

    /// What went out last, shown on the Talk page for a glance down.
    var lastSentText by mutableStateOf("")

    /// Link + session state, shown in Settings so a user can tell "the watch is not
    /// alerting" apart from "nothing has been sent".
    var phoneReachable by mutableStateOf(false)
    var sharing by mutableStateOf(false)
    var callsign by mutableStateOf("")
    /// Set by the Announcer when audio focus was wanted and refused, or when the watch
    /// has no text-to-speech engine at all. Reported to the phone, which will not defer
    /// to a wrist that cannot be heard — see reportAudioState().
    var audioUnavailable by mutableStateOf(false)
        private set

    fun reportAudioUnavailable(value: Boolean) {
        if (audioUnavailable == value) return
        audioUnavailable = value
        reportAudioState()
    }

    /// Where the watch talks when the phone cannot. Handed over in the context so a server
    /// move needs no watch release.
    ///
    /// Persisted, unlike on watchOS. The direct poller can run in a process the phone has
    /// never spoken to in this lifetime — the Data Layer starts us cold — and defaulting to
    /// a compiled-in host would quietly poll the wrong server after a move.
    var serverBase by mutableStateOf("https://marsaprs.org")
        private set

    /// The server rejected our token. Terminal until the phone issues another — retrying a
    /// 403 only produces more 403s.
    var authExpired by mutableStateOf(false)

    /// When the phone last got a full state snapshot through. Surfaced in Settings because
    /// "never" and "twenty minutes ago" are the two symptoms that distinguish a broken link
    /// from a quiet net, and without it both just look like a dead app.
    var lastContextAt by mutableStateOf<Long?>(null)

    var lastId by mutableStateOf(0)
        private set

    /// Ids already seen, so the same message arriving over two transports is one event.
    /// Capped and trimmed from the low end — ids only ever increase.
    private val seenIds = HashSet<Int>()

    /// `lastId` as it stood when the app launched. Anything at or below this was already on
    /// disk, so it is displayed but never announced.
    private var launchWatermark = 0

    /// Whether this app is genuinely on screen and interactive.
    ///
    /// Narrower than `canAnnounce` on purpose — see there. This drives the direct poller,
    /// which is a battery question rather than an audio one.
    var isActive by mutableStateOf(false)

    /// Whether this app can currently make a sound — the only authority on the question,
    /// and what the phone defers to when deciding whether to announce a message itself.
    ///
    /// True while the Activity is STARTED, which on Wear OS includes ambient mode: the
    /// screen is dimmed, the app is still the thing on the display, and audio still plays.
    /// That is the same conclusion the watchOS port reached the hard way — see
    /// `Announcer.runDimmedAudioTest()` — and it matters for the same reason. A lowered
    /// wrist is the normal way to wear a watch, so a rule that goes mute on dimming goes
    /// mute for most of a net, and the operator ends up listening to their pocket.
    ///
    /// False once STOPPED. There the app is not on screen at all, the system is free to
    /// kill the process, and promising otherwise leaves the phone deferring to a watch
    /// that stays silent.
    var canAnnounce by mutableStateOf(false)
        private set

    fun reportCanAnnounce(value: Boolean) {
        if (canAnnounce == value) return
        canAnnounce = value
        reportAudioState()
    }

    /// Both facts, in one payload, whenever either moves.
    ///
    /// `canAnnounce` answers "am I on screen?", which is not the same question as "did I
    /// make a sound?" — and the phone was deciding on the first while needing the second.
    /// The gap is wider here than on watchOS: Wear hardware varies, plenty of watches ship
    /// with no speaker at all, and a watch with no text-to-speech data installed is
    /// resident, willing and completely inaudible. The phone would defer to it anyway, and
    /// the operator would hear nothing from either device with nothing to say why.
    ///
    /// Sent together rather than as two messages, so the phone can never hold a fresh
    /// value of one beside a stale value of the other.
    ///
    /// Retrospective, and that is a real limit rather than an oversight: whether focus
    /// will be granted is not knowable until something is announced, so the first message
    /// after audio goes away is still lost to the wrist. What this fixes is every message
    /// after it.
    fun reportAudioState() {
        PhoneLink.send(
            JSONObject()
                .put("type", "canAnnounce")
                .put("enabled", canAnnounce)
                .put("audioOk", !audioUnavailable)
        )
    }

    /// How stale a message may be and still be announced.
    ///
    /// This, not the launch watermark, is what stops a backlog being read out. The durable
    /// DataClient path is exactly that: items that land while the watch app is closed are
    /// delivered in a burst the moment it next runs, as ordinary relayed messages with ids
    /// above the watermark. Without an age test they would all be read aloud at once — and
    /// whether the coalesced context happened to arrive first, marking them seen, is a race
    /// we must not depend on. Anything older than this is shown in the list and can be
    /// replayed deliberately.
    private const val MAX_ANNOUNCE_AGE_SEC = 120L

    enum class Source {
        RELAY_LIVE, // MessageClient — this app was running when the phone sent it
        RELAY_QUEUED, // DataClient — held durably until we next ran
        DIRECT_POLL, // the watch fetched it itself
        CONTEXT, // a snapshot for display; never announced
    }

    /// How the most recent message got here, and whether anything was heard.
    ///
    /// Surfaced in Settings because "the relay is broken" and "the watch was asleep so it
    /// queued and arrived silently" produce exactly the same experience — a message that
    /// shows up late and without a sound — and there is otherwise no way to tell them apart
    /// from the wrist.
    data class Arrival(
        val at: Long,
        val live: Boolean,
        val announced: Boolean,
        val notified: Boolean,
    ) {
        /// Green only when the operator actually got something — heard it, or was buzzed by
        /// a notification. Silent arrival is the one state worth flagging.
        val wasHeard: Boolean get() = announced || notified

        val detail: String
            get() = when {
                announced -> if (live) "live, spoken" else "queued, spoken"
                notified -> if (live) "live, notified" else "queued, notified"
                else -> if (live) "live, silent" else "queued, silent"
            }
    }

    var lastArrival by mutableStateOf<Arrival?>(null)

    private const val MESSAGES_CAP = 100
    private const val SEEN_CAP = 500

    private var loaded = false

    fun load() {
        if (loaded) return
        loaded = true
        val p = Prefs.get()
        lastId = p.getInt(Prefs.LAST_ID, 0)
        launchWatermark = lastId
        readBackSent = p.getBoolean(Prefs.READ_BACK, false)
        serverBase = p.getString(Prefs.SERVER_BASE, null)?.ifEmpty { null } ?: serverBase
        p.getString(Prefs.MESSAGES, null)?.let { raw ->
            runCatching { JSONArray(raw) }.getOrNull()?.let { arr ->
                messages += arr.objects().mapNotNull(WatchMessage::fromWire)
            }
        }
        p.getString(Prefs.SEEN_IDS, null)?.let { raw ->
            runCatching { JSONArray(raw) }.getOrNull()?.let { arr ->
                for (i in 0 until arr.length()) seenIds += arr.optInt(i)
            }
        }
        p.getString(Prefs.DESTINATION, null)?.let { raw ->
            destination = runCatching { Destination.fromWire(JSONObject(raw)) }.getOrNull()
        }
        Outbox.load()
    }

    // ── the funnel ──────────────────────────────────────────────────────────────

    fun ingest(incoming: List<WatchMessage>, source: Source) {
        if (incoming.isEmpty()) return

        val fresh = incoming.filter { seenIds.add(it.id) }
        if (fresh.isEmpty()) return

        messages += fresh
        messages.sortBy { it.id }
        if (messages.size > MESSAGES_CAP) {
            repeat(messages.size - MESSAGES_CAP) { messages.removeAt(0) }
        }
        lastId = maxOf(lastId, fresh.maxOf { it.id })
        trimSeen()
        persist()

        // Worth the operator's attention. Whether that attention is speech or a
        // notification depends only on whether this app happens to be on screen.
        val now = System.currentTimeMillis() / 1000
        val alertable = fresh.filter { m ->
            source != Source.CONTEXT &&
                m.id > launchWatermark &&
                now - m.ts <= MAX_ANNOUNCE_AGE_SEC &&
                !m.isSelf &&
                // Already announced by the phone. Queued deliveries flush the moment this
                // app comes forward, so without this the operator raises their wrist and
                // hears a rerun of everything they just heard from their pocket.
                !m.phoneAnnounced
        }
        if (source != Source.CONTEXT) {
            lastArrival = Arrival(
                at = System.currentTimeMillis(),
                live = source == Source.RELAY_LIVE,
                announced = canAnnounce && alertable.isNotEmpty(),
                notified = !canAnnounce && alertable.isNotEmpty(),
            )
        }

        // Aim the reply at whoever just called — but only for messages this watch fetched
        // itself. The phone re-aims whenever it relays (WatchBridge._aimAt), and a message
        // that arrived by direct poll never passed through the phone, so nothing aimed at
        // all: the reply still pointed wherever it last pointed. After an event change that
        // is a thread which no longer exists, leaving the operator hearing a call they have
        // no way to answer.
        //
        // Broadcasts are deliberately left alone. WatchMessage carries no sender key, so
        // the only thing available to aim at is the broadcast thread itself, and a spoken
        // "copy that" going to every tracker is a worse default than not moving the aim —
        // the same conservative choice the phone makes when it has no key either.
        if (source == Source.DIRECT_POLL) {
            val call = fresh.lastOrNull { !it.isSelf && !it.broadcast && it.conversationId > 0 }
            if (call != null && destination?.conversationId != call.conversationId) {
                chooseDestination(call.conversationId, null, call.senderLabel)
            }
        }

        if (alertable.isEmpty()) return
        if (canAnnounce) {
            Announcer.enqueue(alertable)
        } else {
            // Not on screen, so nothing can be spoken here. Everything still in `alertable`
            // is a message the phone did not announce — either we fetched it ourselves
            // because the phone was unreachable, or it deliberately left it to us on the
            // strength of a promise we have since invalidated by going quiet. A
            // notification is all that is left, and better than silence.
            alertable.forEach { Notifier.alert(it) }
        }
    }

    // ── state from the phone ────────────────────────────────────────────────────

    fun applyContext(context: JSONObject) {
        context.optString("callsign").takeIf { context.has("callsign") }?.let { callsign = it }
        context.optString("serverBase").takeIf { it.isNotEmpty() }?.let {
            serverBase = it
            Prefs.get().edit().putString(Prefs.SERVER_BASE, it).apply()
        }
        sharing = context.optBoolean("sharing", false)

        // The token is absent from the payload rather than null when there is none. Absent,
        // or sharing stopped, means the watch should not be holding a copy at all.
        val token = context.optString("token", "")
        if (sharing && token.isNotEmpty()) {
            TokenStore.save(token)
            authExpired = false
        } else {
            TokenStore.wipe()
        }
        DirectPoller.evaluate()

        // Absent means none. The context is always a complete snapshot, so "key missing" is
        // the only way the phone can express "no destination".
        val incoming = Destination.fromWire(context.optJSONObject("destination"))

        // The newer decision wins, whoever made it. An arriving message re-aims the reply
        // at whoever just called; a pick on this wrist re-aims it deliberately; and a
        // context that was already in flight when the wrist chose is simply older and
        // loses. Timestamps decide it, because "which of these two happened last" is the
        // actual question and nothing else answers it.
        if (incoming != null && incoming.chosenAt >= (destination?.chosenAt ?: 0)) {
            destination = incoming
        } else if (incoming == null && destination != null && context.has("sharing") && !sharing) {
            // Sharing stopped: there is nowhere to reply to any more.
            destination = null
        }
        lastContextAt = System.currentTimeMillis()

        context.optJSONArray("conversations")?.let { raw ->
            conversations.clear()
            conversations += raw.objects().mapNotNull(WatchConversation::fromWire)
        }

        // Always CONTEXT: a snapshot of what the phone already has is history, not news,
        // and announcing it would read out the backlog this design avoids.
        context.optJSONArray("recent")?.let { raw ->
            ingest(raw.objects().mapNotNull(WatchMessage::fromWire), Source.CONTEXT)
        }
        persist()
    }

    fun updateReadBackSent(on: Boolean) {
        readBackSent = on
        persist()
    }

    /// Send a reply straight out. There is no confirm step: a countdown showing the
    /// transcription protects only an operator who is looking at their wrist, which is the
    /// opposite of the situation push-to-talk exists for. Verification happens afterwards
    /// instead, by saying the words back.
    fun sendReply(text: String) {
        val body = text.trim()
        val dest = destination
        if (body.isEmpty() || dest == null) return
        val entry = PendingSend(
            id = java.util.UUID.randomUUID().toString(),
            text = body,
            conversationId = dest.conversationId,
            recipients = dest.recipients,
            destinationLabel = dest.label,
            createdAt = System.currentTimeMillis(),
            state = PendingSend.State.SENDING,
        )
        Outbox.add(entry)
        PhoneLink.submit(entry)
    }

    /// Change the sticky destination from the wrist and tell the phone, so the two agree
    /// about where the next reply goes. The phone remains authoritative — it echoes the
    /// choice back in the next context, which is what confirms it landed.
    fun chooseDestination(conversationId: Int?, recipients: List<String>?, label: String) {
        // Stamped here and sent with the choice, so the phone echoes our timestamp rather
        // than minting a newer one — otherwise the echo would always look like the more
        // recent decision and this pick could never be superseded correctly.
        val chosenAt = (System.currentTimeMillis() / 1000).toInt()
        val next = Destination(conversationId, recipients, label, chosenAt)
        destination = next
        persist()
        Haptics.click()
        PhoneLink.send(next.toJson().put("type", "destination"))
    }

    /// Deliberate replay of one message, from the detail screen. Bypasses the watermark
    /// because the user asked for it explicitly.
    fun speakAgain(m: WatchMessage) {
        Announcer.stop()
        Announcer.enqueue(listOf(m), ignoreAge = true)
    }

    // ── persistence ─────────────────────────────────────────────────────────────

    private fun trimSeen() {
        if (seenIds.size <= SEEN_CAP) return
        val keep = seenIds.sorted().takeLast(SEEN_CAP)
        seenIds.clear()
        seenIds += keep
    }

    private fun persist() {
        val e = Prefs.get().edit()
        e.putInt(Prefs.LAST_ID, lastId)
        e.putBoolean(Prefs.READ_BACK, readBackSent)
        e.putString(Prefs.SEEN_IDS, JSONArray().also { a -> seenIds.forEach(a::put) }.toString())
        e.putString(Prefs.MESSAGES, JSONArray().also { a -> messages.forEach { a.put(it.toJson()) } }.toString())
        val d = destination
        if (d != null) e.putString(Prefs.DESTINATION, d.toJson().toString())
        else e.remove(Prefs.DESTINATION)
        e.apply()
    }
}
