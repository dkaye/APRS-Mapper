/// Sends that have not been confirmed yet.
///
/// A reply spoken into the wrist is often the last thing an operator does before putting
/// their arm back on the handlebars, so it has to survive the phone being briefly out of
/// range, the watch app being closed, and the watch being rebooted. Entries persist and
/// are retried; nothing is dropped silently.
///
/// Counterpart: `app/ios/WatchApp/Sources/Outbox.swift`.
package org.w6sg.aprsmap.wear

import androidx.compose.runtime.mutableStateListOf
import org.json.JSONArray
import java.util.UUID

object Outbox {
    val pending = mutableStateListOf<PendingSend>()

    /// After this we stop waiting on a send and mark it failed, so the row stops claiming
    /// to be in flight and the user can decide what to do.
    const val RESULT_TIMEOUT_MS = 30_000L

    private var loaded = false

    fun load() {
        if (loaded) return
        loaded = true
        val raw = Prefs.get().getString(Prefs.OUTBOX, null) ?: return
        val saved = runCatching { JSONArray(raw) }.getOrNull() ?: return
        // Anything still marked in-flight from a previous run cannot be waited on — the
        // reply handler died with the process.
        pending += saved.objects().mapNotNull(PendingSend::fromJson).map {
            if (it.state == PendingSend.State.SENDING) it.copy(state = PendingSend.State.QUEUED) else it
        }
    }

    fun add(entry: PendingSend) {
        pending += entry
        persist()
    }

    fun mark(id: String, state: PendingSend.State) {
        val i = pending.indexOfFirst { it.id == id }
        if (i < 0) return
        pending[i] = pending[i].copy(state = state)
        persist()
    }

    fun remove(id: String) {
        pending.removeAll { it.id == id }
        persist()
    }

    fun entry(id: String): PendingSend? = pending.firstOrNull { it.id == id }

    /// Rows that never got an answer, oldest first, for a retry sweep.
    fun stale(olderThanMs: Long = RESULT_TIMEOUT_MS): List<PendingSend> {
        val cutoff = System.currentTimeMillis() - olderThanMs
        return pending.filter { it.state != PendingSend.State.FAILED && it.createdAt < cutoff }
    }

    /// Replace a failed entry with a fresh attempt aimed at wherever the reply is pointed
    /// *now*, and return it for the caller to submit.
    ///
    /// Deliberately not its original target. An entry usually failed because that target
    /// had gone — a thread from a previous event, a device that left the net — so repeating
    /// it verbatim would fail in exactly the same way. The current aim is the one the
    /// operator can see on the Talk page, which makes the retry's destination predictable
    /// rather than hidden in a saved record.
    ///
    /// A new id, not the old one: `?messaging=send` carries no client id, so the server
    /// cannot dedupe, and reusing the id would let one delivered-but-unacknowledged message
    /// and its retry both resolve the same row.
    fun retry(id: String, aimedAt: Destination): PendingSend? {
        val old = pending.firstOrNull { it.id == id } ?: return null
        remove(id)
        val fresh = PendingSend(
            id = UUID.randomUUID().toString(),
            text = old.text,
            conversationId = aimedAt.conversationId,
            recipients = aimedAt.recipients,
            destinationLabel = aimedAt.label,
            createdAt = System.currentTimeMillis(),
            state = PendingSend.State.SENDING,
        )
        add(fresh)
        return fresh
    }

    private fun persist() {
        val array = JSONArray()
        pending.forEach { array.put(it.toJson()) }
        Prefs.get().edit().putString(Prefs.OUTBOX, array.toString()).apply()
    }
}
