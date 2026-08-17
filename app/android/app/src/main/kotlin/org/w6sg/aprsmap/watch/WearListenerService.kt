/// How a watch reaches this phone when the app is not on screen.
///
/// The Data Layer starts this service — and, if necessary, this whole process — to deliver
/// a message from the wrist. It is the Android counterpart of WatchConnectivity launching
/// the iOS app in the background, with one consequential difference: no Flutter engine
/// exists here and nothing will create one. So everything this service receives is handed to
/// `WatchBridge.receive`, which forwards it to Dart when Dart is listening and parks it on
/// disk when it is not.
///
/// Nothing here decides anything. A second decision made in this file could only disagree
/// with `watch_bridge.dart`, which is the one place that knows what a `send` or a
/// `destination` means.
package org.w6sg.aprsmap.watch

import android.util.Log
import com.google.android.gms.tasks.Tasks
import com.google.android.gms.wearable.CapabilityInfo
import com.google.android.gms.wearable.DataEvent
import com.google.android.gms.wearable.DataEventBuffer
import com.google.android.gms.wearable.DataMapItem
import com.google.android.gms.wearable.MessageEvent
import com.google.android.gms.wearable.Wearable
import com.google.android.gms.wearable.WearableListenerService
import org.json.JSONObject

class WearListenerService : WearableListenerService() {
    private val tag = "watch"

    override fun onCreate() {
        super.onCreate()
        // This may be the first thing alive in the process. The bridge needs a context
        // before it can park anything for Dart, and it is idempotent when the Activity got
        // here first.
        WatchBridge.init(applicationContext)
    }

    /// The fast path: the watch app is running and the link is connected.
    override fun onMessageReceived(event: MessageEvent) {
        if (event.path != WatchBridge.PATH_TX) return
        val payload = decode(String(event.data, Charsets.UTF_8)) ?: return
        WatchBridge.receive(payload)
    }

    /// The durable path: the watch queued this while we were unreachable, and the Data Layer
    /// held it until now.
    override fun onDataChanged(events: DataEventBuffer) {
        for (event in events) {
            if (event.type != DataEvent.TYPE_CHANGED) continue
            val uri = event.dataItem.uri
            val path = uri.path ?: continue
            if (!path.startsWith(WatchBridge.PATH_TX_QUEUE_PREFIX)) continue

            val json = runCatching {
                DataMapItem.fromDataItem(event.dataItem).dataMap.getString(WatchBridge.KEY_JSON)
            }.getOrNull() ?: continue
            decode(json)?.let { WatchBridge.receive(it) }

            // Consumed. Left in place, the item would be replayed to every future cold start
            // of this process, and a reply spoken once would be sent again and again.
            runCatching { Tasks.await(Wearable.getDataClient(this).deleteDataItems(uri)) }
                .onFailure { Log.w(tag, "could not clear $path: ${it.message}") }
        }
    }

    override fun onCapabilityChanged(info: CapabilityInfo) {
        if (info.name != WatchBridge.WEAR_CAPABILITY) return
        WatchBridge.refreshState()
    }

    private fun decode(text: String): JSONObject? =
        runCatching { JSONObject(text) }.getOrElse {
            Log.w(tag, "undecodable payload from the watch: ${it.message}")
            null
        }
}
