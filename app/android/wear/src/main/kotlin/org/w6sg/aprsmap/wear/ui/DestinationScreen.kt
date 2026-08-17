/// Switch the sticky destination from the wrist.
///
/// Only the recent conversations the phone has already sent us, plus All Trackers. The full
/// roster — stations, operators, the "(multiple)" select-all — stays on the phone: it is the
/// most intricate UI in the app and does not survive the shrink. This is for continuing a
/// thread already in play, which is what a net actually looks like.
///
/// Counterpart: `app/ios/WatchApp/Sources/Views/DestinationPickerView.swift`.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
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

@Composable
fun DestinationScreen() {
    val listState = rememberScalingLazyListState()
    Scaffold(
        timeText = { TimeText() },
        positionIndicator = { PositionIndicator(scalingLazyListState = listState) },
    ) {
        ScalingLazyColumn(state = listState, modifier = Modifier.fillMaxWidth()) {
            item { ListHeader { Text("Reply to") } }

            if (AppState.conversations.isEmpty()) {
                item {
                    Text(
                        "No recent conversations. Open one on the phone.",
                        style = MaterialTheme.typography.caption3,
                        color = MaterialTheme.colors.onSurfaceVariant,
                    )
                }
            }

            items(AppState.conversations.toList()) { c ->
                DestinationChip(
                    label = c.label,
                    detail = c.previewText,
                    selected = AppState.destination?.conversationId == c.id,
                    onClick = { AppState.chooseDestination(c.id, null, c.label) },
                )
            }

            item {
                DestinationChip(
                    label = "All Trackers",
                    detail = "Everyone on the net",
                    selected = AppState.destination?.recipients?.contains("all") == true,
                    onClick = { AppState.chooseDestination(null, listOf("all"), "All Trackers") },
                )
            }
        }
    }
}

@Composable
private fun DestinationChip(
    label: String,
    detail: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Chip(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        colors = if (selected) ChipDefaults.primaryChipColors() else ChipDefaults.secondaryChipColors(),
        label = {
            Text(
                text = if (selected) "✓ $label" else label,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        },
        secondaryLabel = if (detail.isEmpty()) null else {
            {
                Text(detail, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        },
    )
}
