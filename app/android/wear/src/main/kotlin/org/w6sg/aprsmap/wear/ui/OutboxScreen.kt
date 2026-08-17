/// What happened to a reply that did not go out.
///
/// The badge on the Talk page is deliberately persistent — a message spoken into the wrist
/// and then silently lost is the worst failure this app has — but a warning with nowhere to
/// go trains the operator to ignore it, which costs more than the warning buys. This is
/// where it goes: what was said, where it was headed, and the two things worth doing about
/// it.
///
/// Counterpart: `app/ios/WatchApp/Sources/Views/OutboxView.swift`.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.wear.compose.foundation.lazy.ScalingLazyColumn
import androidx.wear.compose.foundation.lazy.items
import androidx.wear.compose.foundation.lazy.rememberScalingLazyListState
import androidx.wear.compose.material.Chip
import androidx.wear.compose.material.ChipDefaults
import androidx.wear.compose.material.ListHeader
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.material.PositionIndicator
import androidx.wear.compose.material.Scaffold
import androidx.wear.compose.material.Text
import androidx.wear.compose.material.TimeText
import org.w6sg.aprsmap.wear.AppState
import org.w6sg.aprsmap.wear.Haptics
import org.w6sg.aprsmap.wear.Outbox
import org.w6sg.aprsmap.wear.PendingSend
import org.w6sg.aprsmap.wear.PhoneLink
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private val shortTime = SimpleDateFormat("h:mm a", Locale.US)

@Composable
fun OutboxScreen(onDone: () -> Unit) {
    val listState = rememberScalingLazyListState()
    Scaffold(
        timeText = { TimeText() },
        positionIndicator = { PositionIndicator(scalingLazyListState = listState) },
    ) {
        ScalingLazyColumn(state = listState, modifier = Modifier.fillMaxWidth()) {
            item { ListHeader { Text("Outbox") } }

            if (Outbox.pending.isEmpty()) {
                item {
                    Text(
                        "Nothing waiting",
                        style = MaterialTheme.typography.caption2,
                        color = MaterialTheme.colors.onSurfaceVariant,
                    )
                }
            }

            // One block per entry, not one column of texts followed by one column of
            // buttons: on a screen this size the operator has to be able to see which words
            // the Retry underneath belongs to without scrolling back up.
            items(Outbox.pending.toList()) { entry ->
                val destination = AppState.destination
                Column(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
                    verticalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Text(entry.text, style = MaterialTheme.typography.body2, maxLines = 4)
                    Text(
                        caption(entry),
                        style = MaterialTheme.typography.caption3,
                        color = if (entry.state == PendingSend.State.FAILED) Color(0xFFFF9F0A)
                        else MaterialTheme.colors.onSurfaceVariant,
                    )

                    if (entry.state == PendingSend.State.FAILED) {
                        // Aimed at the current destination, not the one that failed, so the
                        // button says where it is actually going.
                        Chip(
                            onClick = {
                                val target = destination ?: return@Chip
                                val fresh = Outbox.retry(entry.id, target) ?: return@Chip
                                PhoneLink.submit(fresh)
                                Haptics.click()
                                if (Outbox.pending.isEmpty()) onDone()
                            },
                            enabled = destination != null,
                            modifier = Modifier.fillMaxWidth(),
                            colors = ChipDefaults.primaryChipColors(),
                            label = {
                                Text(
                                    if (destination == null) "No destination"
                                    else "Retry → ${destination.label}",
                                    maxLines = 1,
                                )
                            },
                        )
                        Chip(
                            onClick = {
                                Outbox.remove(entry.id)
                                if (Outbox.pending.isEmpty()) onDone()
                            },
                            modifier = Modifier.fillMaxWidth(),
                            colors = ChipDefaults.secondaryChipColors(),
                            label = { Text("Discard") },
                        )
                    }
                }
            }
        }
    }
}

private fun caption(e: PendingSend): String {
    val when_ = shortTime.format(Date(e.createdAt))
    return when (e.state) {
        PendingSend.State.FAILED -> "Failed · to ${e.destinationLabel} · $when_"
        PendingSend.State.QUEUED -> "Waiting to send · $when_"
        PendingSend.State.SENDING -> "Sending · $when_"
    }
}
