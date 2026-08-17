/// Getting the operator's attention when the app is not on screen.
///
/// The Data Layer will start this app in the background to receive a message, but a
/// process with no Activity has no business grabbing audio focus and reading a stranger's
/// traffic out over whatever the operator is listening to. So a message arriving while the
/// wrist is down and the app is gone would otherwise be added silently to the list, and the
/// operator would find out later — which for net traffic is the same as not arriving.
///
/// A notification is the one alert a backgrounded Wear app can raise: haptic, notification
/// sound, and a card carrying the sender and the text. It cannot read the message aloud, so
/// this is a tap away from being heard rather than heard outright.
///
/// Counterpart: `app/ios/WatchApp/Sources/Notifier.swift`.
package org.w6sg.aprsmap.wear

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent

object Notifier {
    /// Matches the category the phone registers, so the card renders the same way whichever
    /// device raised it.
    const val CHANNEL_ID = "APRS_MSG"

    fun createChannel(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Net messages",
            // HIGH, not DEFAULT: this is the alert that stands in for speech when the app
            // is not on screen, and a channel the system is free to show silently would
            // reintroduce exactly the silent arrival it exists to prevent.
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = "Messages addressed to this tracker during an event"
            enableVibration(true)
        }
        manager.createNotificationChannel(channel)
    }

    /// Raise one alert per message.
    ///
    /// Deliberately not collapsed into "3 new messages": on a net the sender and the words
    /// are the content, and a count tells the operator nothing they can act on. The burst
    /// that would justify collapsing is already prevented upstream — only messages fresh
    /// enough to be worth announcing get here.
    fun alert(message: WatchMessage) {
        val context = WearApp.appContext
        val manager = context.getSystemService(NotificationManager::class.java) ?: return

        val open = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = Notification.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle(message.senderLabel.ifEmpty { "Message" })
            .setContentText(message.displayText)
            .setStyle(Notification.BigTextStyle().bigText(message.displayText))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setCategory(Notification.CATEGORY_MESSAGE)
            .build()

        // The message id doubles as the notification id: the same message arriving over two
        // transports replaces its own card rather than raising a second.
        runCatching { manager.notify(message.id, notification) }
    }
}
