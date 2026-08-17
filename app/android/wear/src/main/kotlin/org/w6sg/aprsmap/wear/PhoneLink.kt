/// Watch side of the phone link.
///
/// Counterpart to `app/android/app/src/main/kotlin/org/w6sg/aprsmap/watch/WatchBridge.kt`,
/// and the direct analogue of `app/ios/WatchApp/Sources/WatchSession.swift`. Everything
/// that arrives here — a live `MessageClient` push, a durable `DataClient` item, or the
/// context — is handed to `AppState.ingest`, which decides what is new and what gets
/// announced. This object deliberately makes no such decisions itself.
///
/// **The transports, and which WatchConnectivity idea each one replaces.** The Data Layer
/// has three clients where watchOS has one session, and mapping them wrongly is the easiest
/// way to build a watch that works on the bench and goes silent in the field:
///
/// | watchOS                    | here                                    | why |
/// |----------------------------|-----------------------------------------|-----|
/// | `sendMessage`              | `MessageClient` → `/aprs/tx`            | fast, and fails outright when the other side is not connected |
/// | `transferUserInfo`         | `DataClient` item at `/aprs/txq/<uuid>` | durable, survives both processes dying, delivered on reconnect |
/// | `updateApplicationContext` | `DataClient` item at `/aprs/context`    | one path, latest value wins, syncs to a watch that was not running |
/// | `transferFile`             | — | not needed: this watch transcribes its own speech |
/// | `isReachable`              | `CapabilityClient` + node.isNearby      | there is no session to ask |
package org.w6sg.aprsmap.wear

import android.content.Context
import android.util.Log
import com.google.android.gms.wearable.CapabilityClient
import com.google.android.gms.wearable.CapabilityInfo
import com.google.android.gms.wearable.DataMapItem
import com.google.android.gms.wearable.PutDataMapRequest
import com.google.android.gms.wearable.Wearable
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.tasks.await
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

object PhoneLink {
    private const val TAG = "AprsWear"

    /// What the phone app advertises. Declared in the phone module's `res/values/wear.xml`;
    /// the watch's own is in this module's copy of that file. Both names are wire contract.
    const val PHONE_CAPABILITY = "aprs_map_phone"

    /// Phone → watch.
    const val PATH_CONTEXT = "/aprs/context"
    const val PATH_LIVE = "/aprs/live"
    const val PATH_MSG_PREFIX = "/aprs/msg/"

    /// Watch → phone.
    private const val PATH_TX = "/aprs/tx"
    private const val PATH_TX_QUEUE_PREFIX = "/aprs/txq/"

    /// The single key inside every DataItem. See Models.kt for why one JSON string rather
    /// than a DataMap of typed fields.
    const val KEY_JSON = "json"

    private var phoneNodeId: String? = null
    private var started = false

    fun start(context: Context) {
        if (started) return
        started = true
        val capability = Wearable.getCapabilityClient(context)
        capability.addListener(::onCapabilityChanged, PHONE_CAPABILITY)
        mainScope.launch {
            refreshReachability()
            // The context that arrived while this app was not running is sitting in the
            // Data Layer rather than waiting as a callback, so it has to be collected
            // explicitly — the same reason watchOS reads `receivedApplicationContext` on
            // activation instead of trusting the delegate.
            collectPendingItems(context)
            hello()
        }
    }

    private fun onCapabilityChanged(info: CapabilityInfo) {
        onMain {
            phoneNodeId = pick(info)
            applyReachable(phoneNodeId != null)
        }
    }

    suspend fun refreshReachability() {
        val info = runCatching {
            Wearable.getCapabilityClient(WearApp.appContext)
                .getCapability(PHONE_CAPABILITY, CapabilityClient.FILTER_REACHABLE)
                .await()
        }.getOrNull()
        phoneNodeId = info?.let(::pick)
        applyReachable(phoneNodeId != null)
    }

    /// Nearby first. A node reachable only over the cloud is technically addressable but the
    /// round trip is seconds, which is worse than the watch simply polling the server itself
    /// — and treating it as "the phone is here" would suppress exactly that fallback.
    private fun pick(info: CapabilityInfo): String? =
        info.nodes.firstOrNull { it.isNearby }?.id

    private fun applyReachable(reachable: Boolean) {
        AppState.phoneReachable = reachable
        if (reachable) DirectPoller.noteReachable() else DirectPoller.evaluate()
    }

    /// Anything the Data Layer already holds for us. Items are not re-delivered as events
    /// when a process starts, so a watch that was killed and relaunched would otherwise show
    /// nothing until the phone next pushed.
    private suspend fun collectPendingItems(context: Context) {
        val items = runCatching {
            Wearable.getDataClient(context).dataItems.await()
        }.getOrNull() ?: return
        try {
            for (item in items) {
                val path = item.uri.path ?: continue
                if (!path.startsWith("/aprs/")) continue
                val json = DataMapItem.fromDataItem(item).dataMap.getString(KEY_JSON) ?: continue
                val payload = runCatching { JSONObject(json) }.getOrNull() ?: continue
                when {
                    path == PATH_CONTEXT -> handle(payload, AppState.Source.CONTEXT)
                    path.startsWith(PATH_MSG_PREFIX) -> handle(payload, AppState.Source.RELAY_QUEUED)
                }
            }
        } finally {
            items.release()
        }
    }

    // ── watch → phone ───────────────────────────────────────────────────────────

    /// Fire-and-forget to the phone. Falls back to the durable queue when the phone is not
    /// reachable, so a request survives the walk back into range.
    fun send(payload: JSONObject) {
        mainScope.launch {
            val node = phoneNodeId
            val bytes = payload.toString().toByteArray(Charsets.UTF_8)
            if (node != null) {
                val sent = runCatching {
                    Wearable.getMessageClient(WearApp.appContext)
                        .sendMessage(node, PATH_TX, bytes).await()
                }.isSuccess
                if (sent) return@launch
            }
            queueDurable(payload)
        }
    }

    /// The durable path. A unique path per item, because the Data Layer only notifies on
    /// *change* — two identical payloads written to one path is one event, and the second
    /// request would vanish without a trace.
    private suspend fun queueDurable(payload: JSONObject) {
        runCatching {
            val request = PutDataMapRequest.create(PATH_TX_QUEUE_PREFIX + UUID.randomUUID())
            request.dataMap.putString(KEY_JSON, payload.toString())
            Wearable.getDataClient(WearApp.appContext)
                .putDataItem(request.asPutDataRequest().setUrgent())
                .await()
        }.onFailure { Log.w(TAG, "durable queue failed: ${it.message}") }
    }

    /// Ask the phone to re-push its state — used on launch, when the watch may have missed
    /// context entirely.
    fun hello() {
        send(JSONObject().put("type", "hello"))
        // Restate it on every wake: the phone decides whether to announce a message itself
        // based on this, and a stale answer means either silence or a duet.
        AppState.reportAudioState()
    }

    /// Hand a reply to the phone, which owns the token and does the HTTP.
    ///
    /// A reply of `queued: true` is not a failure. The phone answers that when its Flutter
    /// engine is still starting — a background cold launch routinely takes a second or two,
    /// and the message round trip is far shorter than that. The real result arrives later as
    /// a `sendResult` payload.
    fun submit(entry: PendingSend) {
        val payload = JSONObject()
            .put("type", "send")
            .put("clientId", entry.id)
            .put("text", entry.text)
        entry.conversationId?.let { payload.put("conversationId", it) }
        entry.recipients?.let { payload.put("recipients", JSONArray(it)) }

        mainScope.launch {
            val node = phoneNodeId
            if (node == null) {
                // The phone is not there. Send it ourselves if we can — queueing to a phone
                // that is switched off would leave the reply sitting until it comes back,
                // which is exactly the situation the watch is meant to cover.
                sendDirect(entry, payload)
                return@launch
            }
            val ok = runCatching {
                Wearable.getMessageClient(WearApp.appContext)
                    .sendMessage(node, PATH_TX, payload.toString().toByteArray(Charsets.UTF_8))
                    .await()
            }.isSuccess
            if (!ok) {
                queueDurable(payload)
                Outbox.mark(entry.id, PendingSend.State.QUEUED)
                return@launch
            }
            // Success here means the phone received the bytes, not that the message went
            // out. The real answer comes back as `sendResult`, so wait for it — but not
            // forever.
            //
            // The phone may have taken delivery in a process with no Flutter engine in it,
            // in which case nothing will act on the reply until somebody opens the phone
            // app. A row stuck on "Sending" is the worst possible report of that: it says
            // the message is in flight when it is parked. QUEUED, not FAILED, because it may
            // well still go — and deliberately not re-sent by the direct path, since
            // `?messaging=send` carries no client id and the server cannot dedupe, so a
            // retry of something already accepted would put it on the net twice. The Outbox
            // shows it, and the operator decides.
            delay(Outbox.RESULT_TIMEOUT_MS)
            if (Outbox.entry(entry.id)?.state == PendingSend.State.SENDING) {
                Outbox.mark(entry.id, PendingSend.State.QUEUED)
            }
        }
    }

    /// Straight to the server, with the phone's durable queue as the last resort.
    ///
    /// Only a connection failure falls back. `?messaging=send` carries no client id, so a
    /// request the server accepted but never answered cannot be told apart from one it never
    /// saw — retrying that through a second path would duplicate the message on the net. A
    /// refusal we can read is reported as a failure instead.
    private suspend fun sendDirect(entry: PendingSend, payload: JSONObject) {
        val token = TokenStore.load()
        if (token == null || AppState.authExpired) {
            queueDurable(payload)
            Outbox.mark(entry.id, PendingSend.State.QUEUED)
            return
        }
        val client = MessagingClient(AppState.serverBase, token)
        try {
            client.send(entry.text, entry.conversationId, entry.recipients)
            Outbox.remove(entry.id)
            Haptics.success()
            // No receipt following on this path: the phone owns that poll, and it is not
            // here. The send is confirmed; delivery is not narrated.
        } catch (e: MessagingError.AuthExpired) {
            AppState.authExpired = true
            TokenStore.wipe()
            Outbox.mark(entry.id, PendingSend.State.FAILED)
            Haptics.failure()
        } catch (e: MessagingError.Network) {
            queueDurable(payload)
            Outbox.mark(entry.id, PendingSend.State.QUEUED)
        } catch (e: Exception) {
            Outbox.mark(entry.id, PendingSend.State.FAILED)
            Haptics.failure()
        }
    }

    // ── inbound ─────────────────────────────────────────────────────────────────

    /// One entry point for every transport. A payload is either a context, a batch, or a
    /// single message; all of them end at `ingest`.
    ///
    /// Called from `PhoneListenerService`, which may be running in a process with no
    /// Activity at all — so nothing here may assume a UI exists.
    fun handle(payload: JSONObject, source: AppState.Source) {
        onMain {
            when (payload.optString("type")) {
                "sendResult" -> {
                    applySendResult(payload)
                    return@onMain
                }
                "receipt" -> {
                    // Must be matched before the message decode below: a receipt carries a
                    // messageId, and anything with an id would otherwise parse as a message.
                    //
                    // "sent" is skipped: the watch already said so itself, with the words it
                    // sent, the moment the phone confirmed. Announcing it again from the
                    // receipt feed would be the same news twice.
                    if (payload.optString("stage") == "sent") return@onMain
                    Announcer.announceReceipt(
                        stage = payload.optString("stage"),
                        count = payload.optInt("count", 0),
                        total = payload.optInt("total", 0),
                    )
                    return@onMain
                }
            }
            if (payload.has("v") || payload.has("conversations") || payload.has("recent")) {
                AppState.applyContext(payload)
                return@onMain
            }
            payload.optJSONArray("batch")?.let { batch ->
                AppState.ingest(batch.objects().mapNotNull(WatchMessage::fromWire), source)
                return@onMain
            }
            WatchMessage.fromWire(payload)?.let { AppState.ingest(listOf(it), source) }
        }
    }

    fun applySendResult(reply: JSONObject, fallbackId: String? = null) {
        val id = reply.optString("clientId").ifEmpty { fallbackId } ?: return
        if (reply.optBoolean("queued", false)) {
            Outbox.mark(id, PendingSend.State.QUEUED)
            return
        }
        if (reply.optBoolean("ok", false)) {
            // Read the words back before the entry goes, since it is the only copy of what
            // was actually sent. This is the verification that replaced the confirm
            // countdown: after the fact and audible, rather than before and on a screen
            // nobody hands-free is looking at.
            val sent = Outbox.entry(id)?.text ?: ""
            Outbox.remove(id)
            AppState.lastSentText = sent
            Haptics.success()
            Announcer.announceSent(sent, repeatingWords = AppState.readBackSent)
        } else {
            Outbox.mark(id, PendingSend.State.FAILED)
            Haptics.failure()
        }
    }

    /// Delete a durable item once it has been ingested, so the queue does not grow without
    /// bound and a relaunch does not replay the whole of it.
    fun consume(uri: android.net.Uri) {
        mainScope.launch {
            withContext(Dispatchers.IO) {
                runCatching { Wearable.getDataClient(WearApp.appContext).deleteDataItems(uri).await() }
            }
        }
    }
}
