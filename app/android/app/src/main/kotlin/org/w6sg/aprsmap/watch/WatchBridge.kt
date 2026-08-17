/// Phone side of the Wear OS bridge.
///
/// Owns the Data Layer clients, owns the two Flutter channels, and buffers across the gap
/// between them. Counterpart of `app/ios/Runner/WatchBridge.swift`, talking to the same Dart
/// file (`app/lib/watch_bridge.dart`) over the same channel names and the same payloads — so
/// that one Dart implementation drives both watches and neither can drift.
///
/// **Where this differs from the iOS bridge, and why.**
///
/// 1. *No reply handlers.* WatchConnectivity's `sendMessage` carries a reply channel with a
///    short, unforgiving window, which is why the Swift file has a `replyWatchdog` and a map
///    of held-open handlers. `MessageClient` has none: a message is delivered or it is not.
///    Every answer therefore travels the same way every other push does, as a `sendResult`
///    addressed by `clientId`. That is the path the Swift side already falls back to, so the
///    watch needs no special case — it only ever loses the fast case.
///
/// 2. *No speech recognition.* The Apple Watch has no recogniser and ships audio here to be
///    transcribed. Wear OS watches recognise speech themselves, so there is no `talkAudio`
///    path, no `transcript` reply, and no microphone permission on this side. See
///    `app/android/wear/src/main/kotlin/org/w6sg/aprsmap/wear/SpeechCapture.kt`.
///
/// 3. *A cold process does not start Dart.* WatchConnectivity relaunches the whole iOS app
///    in the background to deliver a message, so `pendingToDart` there is an in-memory queue
///    across a gap of a second or two. Here `WearListenerService` starts a bare process with
///    no Flutter engine in it, and nothing will create one. So the queue is on disk, and it
///    is replayed when Dart next calls `ready` — see README.md ("Wear OS Companion") for
///    what that means for a reply spoken while the phone app is not running.
package org.w6sg.aprsmap.watch

import android.content.Context
import android.net.Uri
import android.util.Log
import com.google.android.gms.tasks.Tasks
import com.google.android.gms.wearable.CapabilityClient
import com.google.android.gms.wearable.CapabilityInfo
import com.google.android.gms.wearable.PutDataMapRequest
import com.google.android.gms.wearable.PutDataRequest
import com.google.android.gms.wearable.Wearable
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.EventChannel
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Executors

object WatchBridge : EventChannel.StreamHandler {
    private const val TAG = "watch"

    private const val METHOD_CHANNEL = "org.marsaprs/watch"
    private const val EVENT_CHANNEL = "org.marsaprs/watch/events"

    /// The watch advertises this. Absent means the watch app is not installed.
    const val WEAR_CAPABILITY = "aprs_map_wear"

    /// Phone → watch.
    private const val PATH_CONTEXT = "/aprs/context"
    private const val PATH_LIVE = "/aprs/live"
    private const val PATH_MSG_PREFIX = "/aprs/msg/"

    /// Watch → phone.
    const val PATH_TX = "/aprs/tx"
    const val PATH_TX_QUEUE_PREFIX = "/aprs/txq/"

    const val KEY_JSON = "json"

    /// Durable phone → watch items kept alive at once. The watch deletes each on ingest, so
    /// this only bounds what a watch that has been away for hours comes back to; the same
    /// twenty the context's `recent` list carries.
    private const val DURABLE_CAP = 20

    private const val PREFS = "aprs_watch_bridge"
    private const val KEY_PENDING = "pendingToDart"

    /// Events that arrived before Dart called `ready`. On disk, not in memory: the process
    /// that received them may never run Flutter at all.
    private const val PENDING_CAP = 64

    private lateinit var appContext: Context
    private var methodChannel: MethodChannel? = null
    private var eventSink: EventChannel.EventSink? = null

    /// Last context we pushed, re-sent when the watch becomes reachable again.
    private var lastContext: JSONObject? = null

    /// Durable item paths we have written, oldest first, so old ones can be pruned.
    private val durablePaths = ArrayDeque<String>()

    private val io = Executors.newSingleThreadExecutor()

    private var paired = false
    private var appInstalled = false
    private var reachable = false
    private var watchNodeId: String? = null

    // ── lifecycle ───────────────────────────────────────────────────────────────

    private var listening = false

    /// Called from `MainActivity.onCreate`, and from `WearListenerService` on every callback.
    ///
    /// Both, because either can be the first thing alive in this process: the Activity on a
    /// normal launch, the service when the Data Layer starts us cold to hand over a message.
    /// Idempotent, so the second caller costs nothing.
    @Synchronized
    fun init(context: Context) {
        if (!::appContext.isInitialized) appContext = context.applicationContext
        if (listening) return
        listening = true
        Wearable.getCapabilityClient(appContext)
            .addListener(::onCapabilityChanged, WEAR_CAPABILITY)
        refreshState()
    }

    /// Called once the Flutter engine exists. Safe to call again after an engine restart;
    /// the channels are simply rebuilt.
    fun attach(messenger: BinaryMessenger) {
        MethodChannel(messenger, METHOD_CHANNEL).also { channel ->
            channel.setMethodCallHandler(::handle)
            methodChannel = channel
        }
        EventChannel(messenger, EVENT_CHANNEL).setStreamHandler(this)
    }

    // ── Dart → here ─────────────────────────────────────────────────────────────

    private fun handle(call: MethodCall, result: MethodChannel.Result) {
        val args = (call.arguments as? Map<*, *>)?.let(::toJson) ?: JSONObject()
        when (call.method) {
            "ready" -> {
                flushPendingToDart()
                refreshState()
                result.success(stateMap())
            }

            "status" -> result.success(stateMap())

            "setContext" -> {
                lastContext = args
                result.success(mapOf("ok" to pushContext(args)))
            }

            "pushMessage" -> result.success(mapOf("transport" to push(args)))

            "pushMessages" -> {
                val messages = args.optJSONArray("messages") ?: JSONArray()
                // One envelope, not N: each durable item is a separate wake of the watch
                // app, and a catch-up burst would wake it many times over.
                val transport = if (messages.length() == 0) {
                    "none"
                } else {
                    push(JSONObject().put("batch", messages))
                }
                result.success(mapOf("transport" to transport))
            }

            "sendResult" -> {
                push(args.put("type", "sendResult"))
                result.success(mapOf("ok" to true))
            }

            else -> result.notImplemented()
        }
    }

    private fun stateMap(): Map<String, Any> = mapOf(
        "supported" to true,
        "paired" to paired,
        "appInstalled" to appInstalled,
        "reachable" to reachable,
        "activated" to true,
    )

    // ── here → watch ────────────────────────────────────────────────────────────

    /// Latest-value-wins state: token, destination, conversation list, speak flag. Survives
    /// the watch app not running, and coalesces if we push faster than the link drains.
    /// Returns false when there is nothing to talk to.
    private fun pushContext(dict: JSONObject): Boolean {
        if (!::appContext.isInitialized) return false
        // One fixed path, so a second push replaces the first rather than queueing behind
        // it. That is the whole of `updateApplicationContext` expressed in the Data Layer.
        putDurable(PATH_CONTEXT, dict, prune = false)
        Log.d(TAG, "context pushed (sharing=${dict.opt("sharing")} lastId=${dict.opt("lastId")})")
        return true
    }

    /// A message (or a `batch` of them, or a receipt, or a send result).
    ///
    /// `MessageClient` is the low-latency path when the watch app is up; a `DataClient` item
    /// is the durable one — it survives both processes dying and starts the watch's listener
    /// service in the background to receive. The watch dedupes by message id, so the
    /// fallback racing the fast path is harmless.
    /// Returns the transport it will use, not the transport that succeeded. The Data Layer
    /// calls all block, and this is invoked from the Flutter method-channel thread, which is
    /// the main thread — so the work happens on `io` and the answer to Dart is a prediction.
    /// Dart only logs it; the watch is the thing that reports what actually arrived.
    private fun push(payload: JSONObject): String {
        if (!::appContext.isInitialized) return "none"
        val node = watchNodeId
        // A receipt is about something happening right now. Delivered twenty minutes late,
        // after the wrist comes back into range, "message delivered" is not news — it is a
        // spoken interruption about a message the operator has long since moved on from. The
        // fast path or nothing.
        val receipt = payload.optString("type") == "receipt"
        if (node == null && receipt) {
            Log.d(TAG, "receipt dropped: watch not reachable")
            return "none"
        }

        if (node != null) {
            val bytes = payload.toString().toByteArray(Charsets.UTF_8)
            io.execute {
                val ok = runCatching {
                    Tasks.await(
                        Wearable.getMessageClient(appContext).sendMessage(node, PATH_LIVE, bytes)
                    )
                }.isSuccess
                if (ok || receipt) return@execute
                Log.d(TAG, "live push failed, falling back to a durable item")
                putDurable(PATH_MSG_PREFIX + durableSuffix(payload), payload, prune = true)
            }
            return "message"
        }

        putDurable(PATH_MSG_PREFIX + durableSuffix(payload), payload, prune = true)
        return "dataItem"
    }

    /// A stable, unique path per payload. Stable so that the same message pushed twice is
    /// one item rather than two; unique so that two different messages never collide, which
    /// would silently drop the second — the Data Layer only notifies on change.
    private fun durableSuffix(payload: JSONObject): String = when {
        payload.has("id") -> payload.optInt("id").toString()
        payload.has("clientId") -> "s" + payload.optString("clientId")
        payload.has("batch") -> {
            val batch = payload.optJSONArray("batch") ?: JSONArray()
            var max = 0
            for (i in 0 until batch.length()) {
                max = maxOf(max, batch.optJSONObject(i)?.optInt("id") ?: 0)
            }
            "b$max"
        }
        else -> "x" + System.currentTimeMillis()
    }

    /// A host-less `wear://` URI, which the Data Layer reads as "this path on every node".
    /// Building it by hand risks `wear:///aprs/...` versus `wear://aprs/...`, and the second
    /// silently matches nothing.
    private fun wearUri(path: String): Uri = Uri.Builder()
        .scheme(PutDataRequest.WEAR_URI_SCHEME)
        .path(path)
        .build()

    private fun putDurable(path: String, payload: JSONObject, prune: Boolean) {
        io.execute {
            runCatching {
                val request = PutDataMapRequest.create(path)
                request.dataMap.putString(KEY_JSON, payload.toString())
                Tasks.await(
                    Wearable.getDataClient(appContext)
                        .putDataItem(request.asPutDataRequest().setUrgent())
                )
            }.onFailure { Log.w(TAG, "putDataItem $path failed: ${it.message}") }

            if (!prune) return@execute
            synchronized(durablePaths) {
                durablePaths.remove(path)
                durablePaths.addLast(path)
                while (durablePaths.size > DURABLE_CAP) {
                    val old = durablePaths.removeFirst()
                    runCatching {
                        Tasks.await(
                            Wearable.getDataClient(appContext).deleteDataItems(wearUri(old))
                        )
                    }
                }
            }
        }
    }

    // ── watch → Dart ────────────────────────────────────────────────────────────

    /// Route an inbound watch payload to Dart, or to disk if Dart is not there.
    ///
    /// Called from `WearListenerService`, which may be the only thing alive in this process.
    fun receive(payload: JSONObject) {
        val event = if (payload.has("type")) payload else JSONObject(payload.toString()).put("type", "unknown")
        val sink = eventSink
        if (sink != null) {
            android.os.Handler(android.os.Looper.getMainLooper()).post {
                runCatching { sink.success(toMap(event)) }
            }
            return
        }

        // Dart is not listening. Park it, and — for a send — tell the watch so, rather than
        // leaving its Outbox row claiming to be in flight forever.
        parkForDart(event)
        val clientId = event.optString("clientId")
        if (event.optString("type") == "send" && clientId.isNotEmpty()) {
            push(
                JSONObject()
                    .put("type", "sendResult")
                    .put("clientId", clientId)
                    .put("queued", true)
            )
        }
    }

    private fun parkForDart(event: JSONObject) {
        if (!::appContext.isInitialized) return
        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val existing = runCatching { JSONArray(prefs.getString(KEY_PENDING, "[]")) }
            .getOrDefault(JSONArray())
        val out = JSONArray()
        // Cap the buffer: a watch that keeps talking to an app that never runs must not grow
        // this without bound.
        val start = maxOf(0, existing.length() - (PENDING_CAP - 1))
        for (i in start until existing.length()) out.put(existing.get(i))
        out.put(event)
        prefs.edit().putString(KEY_PENDING, out.toString()).apply()
    }

    private fun flushPendingToDart() {
        val sink = eventSink ?: return
        if (!::appContext.isInitialized) return
        val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val queued = runCatching { JSONArray(prefs.getString(KEY_PENDING, "[]")) }
            .getOrDefault(JSONArray())
        if (queued.length() == 0) return
        prefs.edit().remove(KEY_PENDING).apply()
        for (i in 0 until queued.length()) {
            val event = queued.optJSONObject(i) ?: continue
            runCatching { sink.success(toMap(event)) }
        }
    }

    // ── link state ──────────────────────────────────────────────────────────────

    private fun onCapabilityChanged(info: CapabilityInfo) {
        applyCapability(info)
        emitWatchState()
        // The watch stops its own direct polling the moment we are reachable, so it needs
        // current state immediately rather than at the next natural push.
        if (reachable) lastContext?.let { pushContext(it) }
    }

    private fun applyCapability(info: CapabilityInfo) {
        appInstalled = info.nodes.isNotEmpty()
        val nearby = info.nodes.firstOrNull { it.isNearby }
        watchNodeId = nearby?.id
        reachable = nearby != null
    }

    fun refreshState() {
        if (!::appContext.isInitialized) return
        io.execute {
            runCatching {
                paired = Tasks.await(Wearable.getNodeClient(appContext).connectedNodes).isNotEmpty()
                val info = Tasks.await(
                    Wearable.getCapabilityClient(appContext)
                        .getCapability(WEAR_CAPABILITY, CapabilityClient.FILTER_ALL)
                )
                applyCapability(info)
            }.onFailure { Log.w(TAG, "state refresh failed: ${it.message}") }
            emitWatchState()
        }
    }

    private fun emitWatchState() {
        val sink = eventSink ?: return
        val event = stateMap().toMutableMap()
        event["type"] = "watchState"
        android.os.Handler(android.os.Looper.getMainLooper()).post {
            runCatching { sink.success(event) }
        }
    }

    // ── EventChannel.StreamHandler ──────────────────────────────────────────────

    override fun onListen(arguments: Any?, events: EventChannel.EventSink?) {
        eventSink = events
        flushPendingToDart()
        emitWatchState()
    }

    override fun onCancel(arguments: Any?) {
        eventSink = null
    }

    // ── JSON ⇄ Flutter's standard codec ─────────────────────────────────────────

    /// Flutter's codec hands Dart maps over as `Map<*, *>` of boxed primitives. Everything
    /// downstream of here speaks JSON, because the Data Layer, the watch and the server all
    /// do — so the conversion happens once, at the boundary, rather than being repeated at
    /// every field.
    private fun toJson(map: Map<*, *>): JSONObject {
        val out = JSONObject()
        for ((key, value) in map) {
            val name = key as? String ?: continue
            // Dropped rather than written as JSON null. Absent means null by contract on the
            // watch side, and the context is always a complete snapshot, so dropping them
            // loses nothing — while a null that reaches `optInt` comes back as 0, which is a
            // valid conversation id and would aim a reply at nothing.
            if (value == null) continue
            out.put(name, toJsonValue(value))
        }
        return out
    }

    private fun toJsonValue(value: Any?): Any = when (value) {
        null -> JSONObject.NULL
        is Map<*, *> -> toJson(value)
        is List<*> -> JSONArray().also { array ->
            value.forEach { array.put(toJsonValue(it)) }
        }
        else -> value
    }

    private fun toMap(obj: JSONObject): Map<String, Any?> {
        val out = HashMap<String, Any?>()
        for (key in obj.keys()) out[key] = fromJsonValue(obj.get(key))
        return out
    }

    private fun fromJsonValue(value: Any?): Any? = when (value) {
        JSONObject.NULL, null -> null
        is JSONObject -> toMap(value)
        is JSONArray -> (0 until value.length()).map { fromJsonValue(value.get(it)) }
        else -> value
    }
}
