/// Top level of the watch UI.
///
/// Four pages, swiped horizontally, with Talk first — it is what the operator wants under
/// their thumb when the wrist comes up, and the one they must not have to navigate to.
///
/// Horizontal paging, not vertical: the rotary crown scrolls whatever is on screen, so a
/// vertically-paged layout fights every page with content taller than the display and the
/// other pages become unreachable. Swiping sideways leaves the crown free to do the one
/// thing it is good at.
///
/// The detail screens hang off a `SwipeDismissableNavHost` rather than being pages of their
/// own. On Wear a swipe from the left edge means "back", and a nav host is what makes that
/// gesture mean it — a page the operator can only leave by swiping the right way is a page
/// they will get stuck on with their eyes on the road.
///
/// Counterpart: `app/ios/WatchApp/Sources/Views/RootView.swift`.
package org.w6sg.aprsmap.wear.ui

import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.navigation.NavController
import androidx.navigation.NavType
import androidx.navigation.navArgument
import androidx.wear.compose.material.MaterialTheme
import androidx.wear.compose.navigation.SwipeDismissableNavHost
import androidx.wear.compose.navigation.composable
import androidx.wear.compose.navigation.rememberSwipeDismissableNavController
import org.w6sg.aprsmap.wear.AppState

private const val ROUTE_HOME = "home"
private const val ROUTE_OUTBOX = "outbox"
private const val ROUTE_MESSAGE = "message"

@Composable
fun RootScreen() {
    MaterialTheme {
        val nav = rememberSwipeDismissableNavController()
        SwipeDismissableNavHost(navController = nav, startDestination = ROUTE_HOME) {
            composable(ROUTE_HOME) { Home(nav) }
            composable(ROUTE_OUTBOX) { OutboxScreen(onDone = { nav.popBackStack() }) }
            composable(
                "$ROUTE_MESSAGE/{id}",
                arguments = listOf(navArgument("id") { type = NavType.IntType }),
            ) { entry ->
                val id = entry.arguments?.getInt("id") ?: 0
                // Looked up rather than passed. The list is capped and trimmed from the low
                // end while this screen is open, so a captured copy could outlive the entry
                // it describes; a lookup that comes back empty simply closes.
                val message = AppState.messages.firstOrNull { it.id == id }
                if (message == null) nav.popBackStack() else MessageDetailScreen(message)
            }
        }
    }
}

@Composable
private fun Home(nav: NavController) {
    val pager = rememberPagerState(pageCount = { 4 })
    HorizontalPager(state = pager, modifier = Modifier) { page ->
        when (page) {
            0 -> TalkScreen(onOpenOutbox = { nav.navigate(ROUTE_OUTBOX) })
            1 -> MessageListScreen(onOpen = { nav.navigate("$ROUTE_MESSAGE/${it.id}") })
            2 -> DestinationScreen()
            else -> SettingsScreen()
        }
    }
}
