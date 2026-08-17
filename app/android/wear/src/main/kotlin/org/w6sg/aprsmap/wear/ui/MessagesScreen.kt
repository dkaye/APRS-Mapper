/// Message history, newest first, and one message in full.
///
/// Reversed relative to the phone's thread view on purpose: the watch is glanced at, not
/// read, so the thing most likely to be wanted is under the thumb rather than a scroll away.
///
/// "Speak again" on the detail screen exists because the announcement is easy to miss — the
/// watch may have been off-wrist, the stream muted, or a truck going past. It bypasses the
/// launch watermark and the five-minute age rule, since the user is explicitly asking for it.
///
/// Counterparts: `MessageListView.swift` and `MessageDetailView.swift`.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.wear.compose.foundation.lazy.ScalingLazyColumn
import androidx.wear.compose.foundation.lazy.items
import androidx.wear.compose.foundation.lazy.rememberScalingLazyListState
import androidx.wear.compose.material.Chip
import androidx.wear.compose.material.ChipDefaults
import androidx.wear.compose.material.CompactChip
import androidx.wear.compose.material.ListHeader
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.material.PositionIndicator
import androidx.wear.compose.material.Scaffold
import androidx.wear.compose.material.Text
import androidx.wear.compose.material.TimeText
import org.w6sg.aprsmap.wear.AppState
import org.w6sg.aprsmap.wear.WatchMessage
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private val timeFormat = SimpleDateFormat("h:mm a", Locale.US)

fun WatchMessage.timeLabel(): String = timeFormat.format(Date(ts * 1000L))

@Composable
fun MessageListScreen(onOpen: (WatchMessage) -> Unit) {
    val listState = rememberScalingLazyListState()
    Scaffold(
        timeText = { TimeText() },
        positionIndicator = { PositionIndicator(scalingLazyListState = listState) },
    ) {
        ScalingLazyColumn(state = listState, modifier = Modifier.fillMaxWidth()) {
            item { ListHeader { Text("Messages") } }
            if (AppState.messages.isEmpty()) {
                item {
                    Text(
                        "No messages yet",
                        style = MaterialTheme.typography.caption2,
                        color = MaterialTheme.colors.onSurfaceVariant,
                    )
                }
            }
            // Newest first, so the message most likely to be wanted is under the thumb
            // rather than a scroll away.
            items(AppState.messages.reversed()) { m ->
                Chip(
                    onClick = { onOpen(m) },
                    colors = ChipDefaults.secondaryChipColors(),
                    modifier = Modifier.fillMaxWidth(),
                    label = {
                        Text(
                            m.displayText,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                            style = MaterialTheme.typography.body2,
                        )
                    },
                    secondaryLabel = {
                        Row(
                            horizontalArrangement = Arrangement.spacedBy(4.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(
                                text = if (m.broadcast) "📣 ${m.senderLabel}" else m.senderLabel,
                                style = MaterialTheme.typography.caption3,
                                color = if (m.broadcast) Color(0xFFFF9F0A)
                                else MaterialTheme.colors.onSurfaceVariant,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.weight(1f, fill = false),
                            )
                            Text(
                                m.timeLabel(),
                                style = MaterialTheme.typography.caption3,
                                color = MaterialTheme.colors.onSurfaceVariant,
                            )
                        }
                    },
                )
            }
        }
    }
}

@Composable
fun MessageDetailScreen(message: WatchMessage) {
    val listState = rememberScalingLazyListState()
    Scaffold(
        timeText = { TimeText() },
        positionIndicator = { PositionIndicator(scalingLazyListState = listState) },
    ) {
        ScalingLazyColumn(state = listState, modifier = Modifier.fillMaxWidth()) {
            item {
                Column(
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp),
                    verticalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    if (message.broadcast) {
                        Text(
                            "📣 All Trackers",
                            style = MaterialTheme.typography.caption3,
                            color = Color(0xFFFF9F0A),
                        )
                    }
                    Text(message.senderLabel, style = MaterialTheme.typography.title3)
                    Text(
                        message.timeLabel(),
                        style = MaterialTheme.typography.caption3,
                        color = MaterialTheme.colors.onSurfaceVariant,
                    )
                    Text(message.displayText, style = MaterialTheme.typography.body1)
                }
            }
            item {
                CompactChip(
                    onClick = { AppState.speakAgain(message) },
                    label = { Text("Speak again") },
                )
            }
        }
    }
}
