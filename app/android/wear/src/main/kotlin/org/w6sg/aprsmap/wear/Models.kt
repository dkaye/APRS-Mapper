/// Wire and storage types for the Wear OS companion.
///
/// Port of `app/ios/WatchApp/Sources/Models.swift`, and deliberately the same shapes with
/// the same key names: the phone composes one payload for both watches, and a field that
/// existed under a different name on one of them would be a bug nobody could see from
/// either wrist.
///
/// Everything crosses the Data Layer as a JSON string rather than as a DataMap of typed
/// values. That is one decision, made once, for the reason the Swift file records in
/// reverse: WatchConnectivity hands back NSNumber for both integers and booleans and so
/// forces hand-written decoding, and a DataMap has the same class of problem from the
/// other end — `getInt` on a value the sender put in as a long returns zero rather than
/// failing. JSON has one representation of each of these and both sides already speak it,
/// because it is what the server speaks.
package org.w6sg.aprsmap.wear

import org.json.JSONArray
import org.json.JSONObject

data class WatchMessage(
    val id: Int,
    val conversationId: Int,
    val ts: Int,
    val text: String,
    /// Already formatted by the phone ("M141 Doug", "Net Control"). The watch
    /// deliberately does not re-derive this; see MsgMessage.senderLabel in
    /// messaging_client.dart, which is the one implementation of those rules.
    val senderLabel: String,
    val broadcast: Boolean,
    val hasPhoto: Boolean,
    val isSelf: Boolean,
    /// Whether the phone alerted for this message. Sent with it, so a watch that has gone
    /// quiet since promising to speak can still raise a notification rather than leaving
    /// the message unannounced by either device.
    val phoneAnnounced: Boolean = false,
) {
    /// Spoken as two utterances with a pause between, matching the phone
    /// (messaging_screen.dart `_speakMessage`). Said as one phrase the name blurs into the
    /// opening words and the listener loses both halves; the gap gives them a beat to
    /// register who is calling before the content starts.
    ///
    /// Identical for every message, broadcasts included. Who is calling is the thing an
    /// operator needs first and it should sound the same every time; a net-wide call is
    /// still distinguished, by a double buzz, which costs no airtime.
    val announcementPhrase: String
        get() {
            val who = senderLabel.trim()
            return if (who.isEmpty()) "Message." else "From $who."
        }

    /// The content half. A photo with no caption still deserves a sentence, or the
    /// announcement would be followed by silence.
    val bodyPhrase: String
        get() = when {
            text.isNotEmpty() -> text
            hasPhoto -> "sent a photo"
            else -> ""
        }

    /// Row text when there is no message body.
    val displayText: String
        get() = when {
            text.isNotEmpty() -> text
            hasPhoto -> "📷 Photo"
            else -> ""
        }

    fun toJson(): JSONObject = JSONObject()
        .put("id", id)
        .put("conversationId", conversationId)
        .put("ts", ts)
        .put("text", text)
        .put("senderLabel", senderLabel)
        .put("broadcast", broadcast)
        .put("hasPhoto", hasPhoto)
        .put("self", isSelf)
        .put("phoneAnnounced", phoneAnnounced)

    companion object {
        /// The phone's relay shape. Returns null for anything without an id, which is the
        /// one field nothing downstream can work without.
        fun fromWire(d: JSONObject): WatchMessage? {
            if (!d.has("id")) return null
            return WatchMessage(
                id = d.optInt("id"),
                conversationId = d.optInt("conversationId", 0),
                ts = d.optInt("ts", 0),
                text = d.optString("text", ""),
                senderLabel = d.optString("senderLabel", ""),
                broadcast = d.optBoolean("broadcast", false),
                hasPhoto = d.optBoolean("hasPhoto", false),
                isSelf = d.optBoolean("self", false),
                phoneAnnounced = d.optBoolean("phoneAnnounced", false),
            )
        }

        /// The server's own shape, for the direct-poll path.
        ///
        /// The sender label has to be composed here, which duplicates the rule in
        /// `MsgMessage.senderLabel` and in `WatchMessagingClient.label(from:)` on the other
        /// watch. That is deliberate and unavoidable: on this path there is no phone in
        /// the loop to compose it. If the mobile/operator/short-id rules ever change, this
        /// is the third place that has to follow.
        fun fromServer(d: JSONObject): WatchMessage? {
            if (!d.has("id")) return null
            return WatchMessage(
                id = d.optInt("id"),
                conversationId = d.optInt("conversation_id", 0),
                ts = d.optInt("ts", 0),
                text = d.optString("text", ""),
                senderLabel = serverLabel(d),
                broadcast = d.optBoolean("broadcast", false),
                hasPhoto = d.optBoolean("photo", false),
                isSelf = false,
            )
        }

        private fun serverLabel(d: JSONObject): String {
            val kind = d.optString("from_kind", "")
            val short = d.optString("from_short", "")
            val name = d.optString("from_name", "")
            val key = d.optString("from_key", "")
            if (kind == "mobile" && short.isNotEmpty()) {
                return if (name.isNotEmpty() && name != key) "$short $name" else short
            }
            return name.ifEmpty { key }
        }
    }
}

data class WatchConversation(
    val id: Int,
    val kind: String,
    val label: String,
    val unread: Int,
    val lastId: Int,
    val previewText: String,
) {
    companion object {
        fun fromWire(d: JSONObject): WatchConversation? {
            if (!d.has("id")) return null
            return WatchConversation(
                id = d.optInt("id"),
                kind = d.optString("kind", "direct"),
                label = d.optString("label", "Conversation"),
                unread = d.optInt("unread", 0),
                lastId = d.optInt("lastId", 0),
                previewText = d.optString("previewText", ""),
            )
        }
    }
}

/// Where a reply goes. Either an existing thread or a recipient set that will create one;
/// the phone decides which and the watch just carries it.
data class Destination(
    val conversationId: Int?,
    val recipients: List<String>?,
    val label: String,
    /// When this aim was decided, unix seconds. Both devices set it, and the newer one
    /// wins — which is the whole conflict-resolution rule. It replaced a "dirty" flag that
    /// could not tell a stale echo of an old value from a genuinely newer decision
    /// arriving from the phone, and so blocked both for thirty seconds.
    val chosenAt: Int,
) {
    /// Same place, ignoring when it was chosen and what it is called.
    fun sameTarget(other: Destination?): Boolean =
        other != null && conversationId == other.conversationId && recipients == other.recipients

    fun toJson(): JSONObject = JSONObject().apply {
        conversationId?.let { put("conversationId", it) }
        recipients?.let { put("recipients", JSONArray(it)) }
        put("label", label)
        put("chosenAt", chosenAt)
    }

    companion object {
        fun fromWire(d: JSONObject?): Destination? {
            if (d == null) return null
            val cid = if (d.has("conversationId")) d.optInt("conversationId") else null
            val recipients = d.optJSONArray("recipients")?.let { arr ->
                (0 until arr.length()).map { arr.optString(it) }.filter { it.isNotEmpty() }
            }
            // Neither a thread nor anyone to address: not a destination, however well
            // labelled. Matching Destination.init?(wire:) on watchOS.
            if (cid == null && recipients.isNullOrEmpty()) return null
            return Destination(
                conversationId = cid,
                recipients = recipients,
                label = d.optString("label", ""),
                chosenAt = d.optInt("chosenAt", 0),
            )
        }
    }
}

/// A send that has not been confirmed yet. Persisted; see Outbox.kt.
data class PendingSend(
    /// Ours, not the server's. Correlates the reply that comes back — possibly out of
    /// band, long after the request — with the row that is waiting for it.
    val id: String,
    val text: String,
    val conversationId: Int?,
    val recipients: List<String>?,
    val destinationLabel: String,
    val createdAt: Long,
    val state: State,
) {
    enum class State {
        SENDING, // handed to the phone, waiting on a result
        QUEUED, // the phone acknowledged but has not finished; result comes later
        FAILED, // rejected, or we gave up waiting
    }

    fun toJson(): JSONObject = JSONObject().apply {
        put("id", id)
        put("text", text)
        conversationId?.let { put("conversationId", it) }
        recipients?.let { put("recipients", JSONArray(it)) }
        put("destinationLabel", destinationLabel)
        put("createdAt", createdAt)
        put("state", state.name)
    }

    companion object {
        fun fromJson(d: JSONObject): PendingSend? {
            val id = d.optString("id", "").ifEmpty { return null }
            val recipients = d.optJSONArray("recipients")?.let { arr ->
                (0 until arr.length()).map { arr.optString(it) }
            }
            return PendingSend(
                id = id,
                text = d.optString("text", ""),
                conversationId = if (d.has("conversationId")) d.optInt("conversationId") else null,
                recipients = recipients,
                destinationLabel = d.optString("destinationLabel", ""),
                createdAt = d.optLong("createdAt", 0L),
                state = runCatching { State.valueOf(d.optString("state")) }.getOrDefault(State.QUEUED),
            )
        }
    }
}

// ── small JSON helpers ────────────────────────────────────────────────────────

fun JSONArray.objects(): List<JSONObject> =
    (0 until length()).mapNotNull { optJSONObject(it) }
