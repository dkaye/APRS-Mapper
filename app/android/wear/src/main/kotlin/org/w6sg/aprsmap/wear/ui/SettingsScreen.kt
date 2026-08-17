/// Watch settings and link diagnostics.
///
/// The readback toggle is the only preference here. Everything else on this page is
/// diagnosis: a watch that is not alerting and a net that is quiet look identical from the
/// wrist, and during an event those are very different problems.
///
/// Counterpart: `app/ios/WatchApp/Sources/Views/SettingsView.swift`.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.wear.compose.foundation.lazy.ScalingLazyColumn
import androidx.wear.compose.foundation.lazy.rememberScalingLazyListState
import androidx.wear.compose.material.Chip
import androidx.wear.compose.material.ChipDefaults
import androidx.wear.compose.material.ListHeader
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.material.PositionIndicator
import androidx.wear.compose.material.Scaffold
import androidx.wear.compose.material.Switch
import androidx.wear.compose.material.Text
import androidx.wear.compose.material.TimeText
import androidx.wear.compose.material.ToggleChip
import androidx.wear.compose.material.ToggleChipDefaults
import org.w6sg.aprsmap.wear.Announcer
import org.w6sg.aprsmap.wear.AppState
import org.w6sg.aprsmap.wear.DirectPoller
import org.w6sg.aprsmap.wear.SpeechCapture
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private val clock = SimpleDateFormat("h:mm a", Locale.US)
private val clockSeconds = SimpleDateFormat("h:mm:ss a", Locale.US)

@Composable
fun SettingsScreen() {
    val listState = rememberScalingLazyListState()
    val context = LocalContext.current

    Scaffold(
        timeText = { TimeText() },
        positionIndicator = { PositionIndicator(scalingLazyListState = listState) },
    ) {
        ScalingLazyColumn(state = listState, modifier = Modifier.fillMaxWidth()) {
            item { ListHeader { Text("Settings") } }

            item {
                // "Readback" is the term on the air; the stored key stays watch.readBackSent
                // so an existing setting survives the rename.
                ToggleChip(
                    checked = AppState.readBackSent,
                    onCheckedChange = { AppState.updateReadBackSent(it) },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("Readback my words") },
                    toggleControl = { Switch(checked = AppState.readBackSent) },
                    colors = ToggleChipDefaults.toggleChipColors(),
                )
            }

            item {
                Footnote(
                    "Reads a reply back after sending, so you can hear what was " +
                        "transcribed. Off still confirms with \"Message sent\".\n\n" +
                        "Every message is read aloud, All Trackers calls included. " +
                        "Replies go to whoever last called you."
                )
            }

            item { ListHeader { Text("Status") } }
            item { Line("Phone", if (AppState.phoneReachable) "Linked" else "Unreachable") }
            item { Line("Sharing", if (AppState.sharing) "On" else "Off") }
            item { Line("Direct polling", if (DirectPoller.active) "On" else "Off") }
            item {
                // "Synced", not "Updated": this is when the phone last sent state, and it
                // was read as when the app was last updated.
                Line("Synced", AppState.lastContextAt?.let { clock.format(Date(it)) } ?: "Never")
            }
            if (AppState.callsign.isNotEmpty()) {
                item { Line("Callsign", AppState.callsign) }
            }
            AppState.destination?.let { d ->
                item { Line("Reply to", d.label) }
            }
            item { Line("Messages", "${AppState.messages.size}") }
            item {
                Line(
                    "Speech",
                    when {
                        !SpeechCapture.recognizerAvailable -> "None on this watch"
                        SpeechCapture.granted != true -> "No microphone"
                        SpeechCapture.lastMs == null ->
                            if (SpeechCapture.lastOnDevice) "on-device" else "ready"
                        else -> String.format(
                            Locale.US,
                            "%.1fs %s",
                            SpeechCapture.lastMs!! / 1000.0,
                            if (SpeechCapture.lastOnDevice) "on-device" else "network",
                        )
                    },
                )
            }
            item {
                Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp)) {
                    Text(
                        "Last message",
                        style = MaterialTheme.typography.caption3,
                        color = MaterialTheme.colors.onSurfaceVariant,
                    )
                    val a = AppState.lastArrival
                    if (a == null) {
                        Text(
                            "None yet",
                            style = MaterialTheme.typography.caption2,
                            color = MaterialTheme.colors.onSurfaceVariant,
                        )
                    } else {
                        Text(
                            "${clockSeconds.format(Date(a.at))} · ${a.detail}",
                            style = MaterialTheme.typography.caption2,
                            color = if (a.wasHeard) Color(0xFF30D158) else Color(0xFFFF9F0A),
                        )
                    }
                }
            }

            item { ListHeader { Text("Diagnostics") } }
            item {
                Chip(
                    onClick = { Announcer.runAmbientAudioTest() },
                    enabled = !Announcer.dimTestRunning,
                    modifier = Modifier.fillMaxWidth(),
                    colors = ChipDefaults.secondaryChipColors(),
                    label = { Text("Test audio when dimmed") },
                )
            }
            Announcer.dimTestResult?.let { result ->
                item {
                    Text(
                        result,
                        style = MaterialTheme.typography.caption3,
                        color = MaterialTheme.colors.onSurfaceVariant,
                        modifier = Modifier.padding(horizontal = 8.dp),
                    )
                }
            }
            item {
                // The button that guards the assumption. On watchOS it disproved the claim
                // that a dimmed screen means silence — the app had been handing every
                // announcement to the phone for most of a net on the strength of it. Wear
                // hardware varies more than Apple's does, so the same failure is more likely
                // here, not less, and it is silent on both devices at once.
                Footnote(
                    "Starts a countdown. Lower your wrist so the watch dims, and listen. " +
                        "The result says whether audio focus was granted and whether it spoke."
                )
            }

            item { Line("Version", versionSummary(context)) }
            item {
                Footnote(
                    "The watch speaks while this app is on screen, including in ambient. " +
                        "If you leave the app, the phone takes over."
                )
            }
        }
    }
}

@Composable
private fun Line(label: String, value: String) {
    Column(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 2.dp),
        verticalArrangement = Arrangement.spacedBy(1.dp),
    ) {
        Text(
            label,
            style = MaterialTheme.typography.caption3,
            color = MaterialTheme.colors.onSurfaceVariant,
        )
        Text(value, style = MaterialTheme.typography.caption1)
    }
}

@Composable
private fun Footnote(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.caption3,
        color = MaterialTheme.colors.onSurfaceVariant,
        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
    )
}

/// "1.23.0 (100063)" — the name comes from pubspec.yaml, so this also confirms at a glance
/// that the watch app and the phone app shipped together. The build number carries the
/// +100000 offset the Play release needs; see wear/build.gradle.kts.
private fun versionSummary(context: android.content.Context): String = runCatching {
    val info = context.packageManager.getPackageInfo(context.packageName, 0)
    val code = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.P) {
        info.longVersionCode
    } else {
        @Suppress("DEPRECATION") info.versionCode.toLong()
    }
    "${info.versionName} ($code)"
}.getOrElse { "?" }
