/// Entry point for the APRS Map Wear OS companion.
///
/// The watch is a nearly hands-free extension of the phone's microphone and speaker: it
/// announces inbound messages (haptic, tone, spoken text), and replies by push-to-talk. It
/// reaches the messaging API relayed from the phone over the Data Layer, and directly over
/// HTTPS when the phone is unreachable. See README.md ("Wear OS Companion") for the whole
/// design, and `app/ios/WatchApp` for the watchOS twin this is a port of.
///
/// **Two different questions, and one flag used to answer both.** The watchOS port learned
/// this the expensive way and the lesson transfers exactly:
///
///   - *May I speak?* — while the Activity is STARTED, ambient included. A dimmed screen is
///     still this app on the display, audio focus is still granted, and a lowered wrist is
///     the normal way to wear a watch. Tying this to RESUMED would hand every announcement
///     to the phone for most of a net.
///   - *Should I poll on my own?* — only while RESUMED and out of ambient. That is a battery
///     question, not an audio one: the poller runs an HTTP request every few seconds, only
///     ever when the phone is unreachable, and running it all day behind a lowered wrist is
///     a different bargain from running it while somebody is looking.
///
/// Merging them means a change made for one reason silently moves the other.
package org.w6sg.aprsmap.wear

import android.Manifest
import android.Manifest.permission.POST_NOTIFICATIONS
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.wear.ambient.AmbientLifecycleObserver
import org.w6sg.aprsmap.wear.ui.RootScreen

class MainActivity : ComponentActivity() {

    /// Both permissions, one dialog sequence.
    ///
    /// The microphone is push-to-talk. Notifications are what stands in for speech when a
    /// message arrives with the app off screen — without the grant on API 33+ that alert is
    /// posted and silently dropped, and the operator's only symptom is a message they never
    /// heard about, which is the exact failure this app exists to prevent.
    private val askForPermissions =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
            SpeechCapture.refreshPermission(this)
            // Refused, as opposed to never asked. Only the first can be fixed in system
            // Settings, and the Talk screen says so — before this distinction existed the
            // button simply sat there dim with no explanation.
            result[Manifest.permission.RECORD_AUDIO]?.let { SpeechCapture.denied = !it }
        }

    private val ambient = AmbientLifecycleObserver(
        this,
        object : AmbientLifecycleObserver.AmbientLifecycleCallback {
            override fun onEnterAmbient(ambientDetails: AmbientLifecycleObserver.AmbientDetails) {
                // Still on screen, still able to speak. Only the poller stands down.
                AppState.isActive = false
                DirectPoller.evaluate()
            }

            override fun onExitAmbient() {
                AppState.isActive = true
                DirectPoller.evaluate()
            }

            override fun onUpdateAmbient() {}
        },
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Ambient rather than the watch face. Without this the operator lowers their wrist
        // mid-net and the app is replaced entirely — no announcement, no Talk button, and a
        // phone that has been told the watch will handle it.
        lifecycle.addObserver(ambient)

        AppState.load()
        SpeechCapture.refreshPermission(this)
        // At launch, not at first press or first message. The system dialog cannot appear
        // while the app is in ambient or gone, which is exactly when the first
        // press-and-hold of a net — and the first message the watch has to raise itself —
        // are likely to happen.
        val wanted = buildList {
            if (SpeechCapture.granted != true && !SpeechCapture.denied) {
                add(Manifest.permission.RECORD_AUDIO)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                ContextCompat.checkSelfPermission(this@MainActivity, POST_NOTIFICATIONS) !=
                PackageManager.PERMISSION_GRANTED
            ) {
                add(POST_NOTIFICATIONS)
            }
        }
        if (wanted.isNotEmpty()) askForPermissions.launch(wanted.toTypedArray())

        setContent { RootScreen() }
    }

    override fun onStart() {
        super.onStart()
        AppState.reportCanAnnounce(true)
        // Ask the phone to re-push: while we were away its token, destination or
        // conversation list may all have moved on.
        PhoneLink.hello()
    }

    override fun onResume() {
        super.onResume()
        AppState.isActive = true
        DirectPoller.evaluate()
        SpeechCapture.refreshPermission(this)
    }

    override fun onPause() {
        super.onPause()
        AppState.isActive = false
        DirectPoller.evaluate()
        // A held button that survives the app leaving the foreground would keep the
        // microphone open with nobody watching the timer.
        if (SpeechCapture.isListening) SpeechCapture.cancel()
    }

    override fun onStop() {
        super.onStop()
        AppState.reportCanAnnounce(false)
        // Now nothing can be heard, and a half-spoken queue resuming minutes later would be
        // worse than silence.
        Announcer.stop()
    }

    fun requestMicrophone() {
        askForPermissions.launch(arrayOf(Manifest.permission.RECORD_AUDIO))
    }
}
