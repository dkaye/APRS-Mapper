/// How the phone reaches this watch when nothing of ours is on screen.
///
/// The Data Layer starts this service into a cold process to deliver an item, which is the
/// Wear equivalent of WatchConnectivity waking a watchOS app for a queued transfer. It is
/// the whole reason a message arriving with the wrist down can raise a notification instead
/// of being discovered an hour later.
///
/// Nothing here decides anything. Every path hands the payload to `PhoneLink.handle`, which
/// hands it to `AppState.ingest`, which is the one place that knows what is new and what
/// deserves the operator's attention. A second decision made here could only disagree.
package org.w6sg.aprsmap.wear

import android.util.Log
import com.google.android.gms.wearable.CapabilityInfo
import com.google.android.gms.wearable.DataEvent
import com.google.android.gms.wearable.DataEventBuffer
import com.google.android.gms.wearable.DataMapItem
import com.google.android.gms.wearable.MessageEvent
import com.google.android.gms.wearable.WearableListenerService
import kotlinx.coroutines.launch
import org.json.JSONObject

class PhoneListenerService : WearableListenerService() {
    private val tag = "AprsWear"

    override fun onDataChanged(events: DataEventBuffer) {
        for (event in events) {
            if (event.type != DataEvent.TYPE_CHANGED) continue
            val uri = event.dataItem.uri
            val path = uri.path ?: continue
            val json = runCatching {
                DataMapItem.fromDataItem(event.dataItem).dataMap.getString(PhoneLink.KEY_JSON)
            }.getOrNull() ?: continue
            val payload = runCatching { JSONObject(json) }.getOrNull() ?: continue

            when {
                path == PhoneLink.PATH_CONTEXT ->
                    PhoneLink.handle(payload, AppState.Source.CONTEXT)

                path.startsWith(PhoneLink.PATH_MSG_PREFIX) -> {
                    // Durable, and therefore not news. It is real — it may be the only copy
                    // that ever reached us — but it sat in a queue for however long the
                    // watch was away, and `ingest` applies the age test that keeps a
                    // backlog from being read out in one burst.
                    PhoneLink.handle(payload, AppState.Source.RELAY_QUEUED)
                    // Consumed. Left in place, the item would be replayed to every future
                    // cold start of this process, and the queue would grow for the life of
                    // the event.
                    PhoneLink.consume(uri)
                }

                else -> Log.d(tag, "ignoring data item at $path")
            }
        }
    }

    /// Live: the phone only reaches us this way while both processes are up and the link is
    /// connected. `MessageClient` fails rather than queues, which is exactly the property
    /// that makes it the fast path and the durable items above the safe one.
    override fun onMessageReceived(event: MessageEvent) {
        if (event.path != PhoneLink.PATH_LIVE) return
        val payload = runCatching {
            JSONObject(String(event.data, Charsets.UTF_8))
        }.getOrNull() ?: return
        PhoneLink.handle(payload, AppState.Source.RELAY_LIVE)
    }

    override fun onCapabilityChanged(info: CapabilityInfo) {
        if (info.name != PhoneLink.PHONE_CAPABILITY) return
        mainScope.launch { PhoneLink.refreshReachability() }
    }
}
