/// Process entry point for the Wear OS companion.
///
/// This runs before anything else in the app, including `PhoneListenerService` — which
/// matters, because the Data Layer can start that service into a cold process purely to
/// hand over a message, with no Activity involved at all. Everything the singletons need
/// in order to be usable from that path has to be in place by the time this returns.
///
/// The counterpart on the other platform is `WatchAppDelegate.applicationDidFinishLaunching`
/// in `app/ios/WatchApp/Sources/WatchApp.swift`, and the ordering rule is the same one:
/// touch AppState first, so the launch watermark is snapshotted before any message can be
/// ingested, and nothing already on disk is announced.
package org.w6sg.aprsmap.wear

import android.app.Application
import android.content.Context
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/// Where every state change happens.
///
/// The singletons below hold Compose snapshot state, and the things that write to them —
/// a Data Layer callback, an HTTP poll, a speech result — all arrive on background
/// threads. Funnelling the writes through one main-thread scope is the equivalent of the
/// `@MainActor` annotations the Swift port carries, and it is enforced the same way: by
/// convention, at the point of the write, not by the type system.
val mainScope = CoroutineScope(Dispatchers.Main.immediate + SupervisorJob())

fun onMain(block: () -> Unit) {
    mainScope.launch { block() }
}

class WearApp : Application() {
    override fun onCreate() {
        super.onCreate()
        appContext = applicationContext

        // First, and for the reason above: constructing AppState reads lastId off disk and
        // freezes it as the launch watermark. A message arriving one millisecond later is
        // then correctly new, and the hundred already stored are correctly not.
        AppState.load()
        Notifier.createChannel(this)
        PhoneLink.start(this)
    }

    companion object {
        /// Set before any singleton can be touched. Deliberately a plain field rather than
        /// a lazy lookup: a Data Layer callback arriving in a cold process is the first
        /// thing that reads it, and that path must not depend on an Activity ever existing.
        lateinit var appContext: Context
            private set
    }
}

/// Ordinary preferences — everything that is not the token.
///
/// The keys match `AppState.Key` on watchOS one for one, so the two watch apps store the
/// same things under the same names and a bug report from either wrist reads the same way.
object Prefs {
    private const val FILE = "aprs_wear"

    const val MESSAGES = "watch.messages"
    const val LAST_ID = "watch.lastId"
    const val SEEN_IDS = "watch.seenIds"
    const val READ_BACK = "watch.readBackSent"
    const val DESTINATION = "watch.destination"
    const val OUTBOX = "watch.outbox"
    const val SERVER_BASE = "watch.serverBase"

    fun get(context: Context = WearApp.appContext) =
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE)
}
