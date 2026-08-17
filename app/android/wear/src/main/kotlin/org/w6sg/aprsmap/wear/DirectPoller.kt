/// Polling marsaprs.org from the wrist when the phone cannot.
///
/// The whole arbitration is one rule, stated once and not negotiated: the watch polls only
/// when it has a token, is frontmost, and the phone has been unreachable for a quarter of a
/// minute. There are no leases, no tie-breaks and no handshake. The phone is authoritative
/// whenever it is there; this exists for when it is not.
///
/// The fifteen-second delay matters. Reachability flaps constantly as a wrist drops and
/// lifts, and a poller that started on every flap would spend the battery it is meant to be
/// saving. Polling never runs in the background either — the system would not keep the loop
/// alive, and an app that cannot make a sound has nothing to do with the result.
///
/// Counterpart: `app/ios/WatchApp/Sources/DirectPoller.swift`.
package org.w6sg.aprsmap.wear

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import org.json.JSONObject

object DirectPoller {
    var active by mutableStateOf(false)
        private set

    /// How long the phone must have been gone before we take over.
    private const val TAKEOVER_DELAY_MS = 15_000L

    /// Matches MapConfig.pollInterval on the phone.
    private const val INTERVAL_MS = 5_000L

    /// After this many consecutive failures we assume there is no network and stop hammering
    /// a radio that is not going to answer.
    private const val BACKOFF_AFTER = 3
    private const val BACKOFF_INTERVAL_MS = 30_000L

    private var lastReachableAt = System.currentTimeMillis()
    private var loop: Job? = null
    private var failures = 0

    fun noteReachable() {
        lastReachableAt = System.currentTimeMillis()
        evaluate()
    }

    /// Called whenever anything that feeds the rule changes: reachability, foreground, the
    /// token, or sharing state.
    fun evaluate() {
        val reachable = AppState.phoneReachable
        val eligible = TokenStore.load() != null &&
            !AppState.authExpired &&
            AppState.isActive &&
            !reachable &&
            System.currentTimeMillis() - lastReachableAt > TAKEOVER_DELAY_MS

        if (eligible) start() else stop(handingBack = reachable)
    }

    private fun start() {
        if (loop != null) return
        active = true
        failures = 0
        loop = mainScope.launch {
            while (isActive) {
                val wait = tick()
                delay(wait)
            }
        }
    }

    private fun stop(handingBack: Boolean) {
        if (loop == null) return
        loop?.cancel()
        loop = null
        active = false
        if (!handingBack) return
        // The phone knows the real watermark; ask it to fill anything missed rather than
        // trusting our own idea of where we got to.
        PhoneLink.send(JSONObject().put("type", "sync").put("sinceId", AppState.lastId))
    }

    /// One poll. Returns how long to wait before the next.
    private suspend fun tick(): Long {
        val token = TokenStore.load() ?: run {
            stop(handingBack = false)
            return INTERVAL_MS
        }
        val client = MessagingClient(AppState.serverBase, token)
        return try {
            val result = client.poll(AppState.lastId)
            failures = 0
            if (result.messages.isNotEmpty()) {
                AppState.ingest(result.messages, AppState.Source.DIRECT_POLL)
            }
            INTERVAL_MS
        } catch (e: MessagingError.AuthExpired) {
            // Never retry a 403. The token is dead and only the phone can issue another.
            AppState.authExpired = true
            TokenStore.wipe()
            stop(handingBack = false)
            INTERVAL_MS
        } catch (e: Exception) {
            failures += 1
            if (failures >= BACKOFF_AFTER) BACKOFF_INTERVAL_MS else INTERVAL_MS
        }
    }
}
