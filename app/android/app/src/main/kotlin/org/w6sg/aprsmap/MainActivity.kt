package org.w6sg.aprsmap

import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import org.w6sg.aprsmap.watch.WatchBridge

class MainActivity : FlutterActivity() {
    /// Before the engine, deliberately. `WatchBridge.init` registers the capability listener
    /// and takes a first reading of the link, so the state Dart gets back from its `ready`
    /// call a moment later describes a watch that has already been looked for rather than
    /// one nobody has asked about yet.
    override fun onCreate(savedInstanceState: Bundle?) {
        WatchBridge.init(applicationContext)
        super.onCreate(savedInstanceState)
    }

    /// The Wear OS companion's half of `org.marsaprs/watch`. The iOS app does this from
    /// `AppDelegate`; there is no equivalent hook here, and `configureFlutterEngine` is the
    /// first point at which a binary messenger exists.
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        WatchBridge.attach(flutterEngine.dartExecutor.binaryMessenger)
    }

    override fun onResume() {
        super.onResume()
        // Cheap, and it covers the case no callback does: the operator installed or paired a
        // watch while this app was in the background, and nothing about that reaches us as
        // an event we have registered for.
        WatchBridge.refreshState()
    }
}
