/// The pieces every page shares.
///
/// Counterpart to the small views that live at the bottom of
/// `app/ios/WatchApp/Sources/Views/RootView.swift`, and here for the same reason: a status
/// line, an outbox badge and a stop button that behaved differently on different pages
/// would be three chances to disagree about the one thing the operator is trying to read.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.wear.compose.material.Chip
import androidx.wear.compose.material.ChipDefaults
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.material.Text
import org.w6sg.aprsmap.wear.Announcer
import org.w6sg.aprsmap.wear.AppState
import org.w6sg.aprsmap.wear.DirectPoller
import org.w6sg.aprsmap.wear.Haptics
import org.w6sg.aprsmap.wear.Outbox
import org.w6sg.aprsmap.wear.PendingSend

/// One line saying whether messages can actually reach this watch right now.
///
/// Not decoration: a watch that has lost the phone looks exactly like a quiet net, and
/// during an event those are very different situations.
@Composable
fun StatusLine(onOpenOutbox: () -> Unit) {
    val text = when {
        AppState.authExpired -> "Reconnect on phone"
        !AppState.sharing -> "Not sharing — start on phone"
        AppState.phoneReachable ->
            if (AppState.audioUnavailable) "Linked · no audio" else "Phone linked"
        // Worth distinguishing: "on its own and working" is a very different state from
        // "cut off", and from the wrist they otherwise look identical.
        DirectPoller.active -> "Direct — phone away"
        else -> "Phone unreachable"
    }
    val color = when {
        AppState.authExpired || !AppState.sharing -> Color(0xFFFF9F0A)
        AppState.phoneReachable -> Color(0xFF30D158)
        DirectPoller.active -> Color(0xFF0A84FF)
        else -> Color(0xFF8E8E93)
    }

    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Spacer(Modifier.size(6.dp).clip(CircleShape).background(color))
        Text(
            text = text,
            style = MaterialTheme.typography.caption3,
            color = MaterialTheme.colors.onSurfaceVariant,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f, fill = false),
        )
        Spacer(Modifier.width(2.dp))
        OutboxBadge(onOpenOutbox)
    }
}

/// Shown only when a reply has not been confirmed yet. A message spoken into the wrist and
/// then silently lost is the worst failure this app has, so an unresolved send stays visible
/// rather than disappearing optimistically.
///
/// It is a link, not a label. The badge is deliberately persistent, and a warning with
/// nowhere to go is one the operator learns to ignore — which costs more than the warning
/// was worth. Tapping it opens the Outbox, where the reply can be sent again or thrown away.
@Composable
fun OutboxBadge(onOpen: () -> Unit) {
    val pending = Outbox.pending
    if (pending.isEmpty()) return
    val failed = pending.count { it.state == PendingSend.State.FAILED }
    Text(
        text = if (failed > 0) "⚠ $failed" else "↑ ${pending.size}",
        style = MaterialTheme.typography.caption3,
        color = if (failed > 0) Color(0xFFFF9F0A) else MaterialTheme.colors.onSurfaceVariant,
        modifier = Modifier.clickable(onClick = onOpen),
    )
}

/// Stop whatever the wrist is saying, and say how much is left.
///
/// The same control the phone has, for the same reason: the automatic rules — five minutes
/// by message time, oldest first, nothing interrupts — are judgement, and judgement is
/// sometimes wrong. An operator who has just walked back to the radio does not want to hear
/// what they missed, however recent it technically is.
///
/// A count AND a duration, because neither answers "wait, or stop it" on its own: four
/// messages could be fifteen seconds or a minute and a half, and that is the whole decision.
/// The duration matters more on a wrist than on a phone, because there is nowhere else to
/// look while it talks.
///
/// Present only while something is queued. Screen space is scarcer here than anywhere else
/// in the system, and an idle control is a row the operator has to scroll past.
@Composable
fun AnnouncerStopButton() {
    val n = Announcer.pendingCount
    if (n <= 0) return
    val s = Announcer.pendingSeconds
    val time = if (s < 60) "${s}s" else "${s / 60}m ${s % 60}s"
    // One with nothing behind it is not a queue and does not need counting.
    val label = if (n <= 1) "Speaking · $time" else "$n to say · $time"

    Chip(
        onClick = {
            Announcer.stop()
            Haptics.stop()
        },
        label = {
            Text(label, style = MaterialTheme.typography.caption2, maxLines = 1)
        },
        colors = ChipDefaults.secondaryChipColors(),
        modifier = Modifier.height(32.dp),
    )
}
