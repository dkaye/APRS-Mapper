# APRS Tracker Map — User Guide

**Version 1.17.2 · June 29, 2026**

---

This document is a guide for users of the MARS APRS system. For technical details on the architecture and software see the [README](https://marsaprs.org/readme.html?back=/userguide.html) file.

## Contents

- [The Concept of Events](#the-concept-of-events)
- [Full-Screen on Mobile Devices](#full-screen-on-mobile-devices-s)
- [Native App (iOS & Android)](#native-app-ios-android)
  - [Getting the App](#getting-the-app)
  - [App Drawer](#app-drawer)
  - [Tracker Detail](#tracker-detail)
  - [Reload Tiles](#reload-tiles)
  - [Share Location in the App](#share-location-in-the-app)
  - [Limitations](#limitations)
- [Main Map](#main-map)
  - [Sidebar (L)](#sidebar)
  - [Sidebar (S)](#sidebar-1)
    - [Opening the Drawer](#opening-the-drawer)
    - [Drawer Sections](#drawer-sections)
  - [Tracker List](#tracker-list)
    - [Clicking a tracker (desktop — three-click cycle)](#clicking-a-tracker)
    - [Tapping a tracker (mobile — tap vs. long press)](#tapping-a-tracker)
  - [Courses](#courses)
  - [Aid Stations and iGates](#aid-stations-and-igates)
  - [Backgrounds](#backgrounds)
  - [Share Location (S)](#share-location-s)
  - [Messaging (L,S)](#messaging-ls)
  - [Origin Marker — Distance and Bearing](#origin-marker--distance-and-bearing)
  - [Reset Map](#reset-map)
  - [Save Map](#save-map)
  - [Kiosk Mode](#kiosk-mode)
  - [Clients](#clients)
- [Admin Page](#admin-page)
  - [Editing the Configuration](#editing-the-configuration)
    - [Trackers](#trackers)
    - [Tracker Style](#tracker-style)
    - [Default Section Visibility](#default-section-visibility)
    - [Map Default View](#map-default-view)
    - [Import and Export](#import-and-export)
    - [Backgrounds](#backgrounds-1)
    - [Courses](#courses-1)
    - [Aid Stations and iGates](#aid-stations-and-igates-1)
    - [Legend](#legend)
    - [Mobile Tracking](#mobile-tracking)
  - [Event Management](#event-management)
    - [Save](#save)
    - [Save as Default Event](#save-as-default-event)
    - [Load](#load)
  - [Manage Course Files](#manage-course-files)
    - [Adding a course to the current event](#adding-a-course-to-the-current-event)
    - [Uploading a new file](#uploading-a-new-file)
- [NetBird Status Monitor](#netbird-status-monitor)
  - [Accessing the Monitor](#accessing-the-monitor)
  - [Status Page](#status-page)
    - [Summary Bar](#summary-bar)
    - [Device Table](#device-table)
    - [Status Meanings](#status-meanings)
  - [Device Admin Page](#device-admin-page)
    - [Enabling and Disabling Devices](#enabling-and-disabling-devices)
    - [Adding, Editing, and Deleting Devices](#adding-editing-and-deleting-devices)
    - [SSH Terminal](#ssh-terminal)
  - [Hostname Self-Reporting](#hostname-self-reporting)
- [WiFi Manager](#wifi-manager)
- [Cloudflare Tunnel](#cloudflare-tunnel)
- [Troubleshooting](#troubleshooting)
  - [Is my iGate working?](#is-my-igate-working)
  - [Are you receiving my tracker?](#are-you-receiving-my-tracker)
  - [How do I add a new WiFi hotspot in the field?](#how-do-i-add-a-new-wifi-hotspot-in-the-field)
  - [How do I determine how frequently a tracker is beaconing?](#how-do-i-determine-how-frequently-a-tracker-is-beaconing)

---

## The Concept of Events

When you first come to the website, you will see a map configured for the current **default event**. 
A user with admin privileges can switch to a different default event, which all new visitors will then see, using an administrative page described later.

You are free to make a variety of changes to how the map and various objects appear and are treated. So long as you don't Save your view (which requires that administration privilege and password) the changes you make are strictly local and won't affect what other users see.

## Large vs. Small Screens

Instructions that refer to large screens (desktops or laptops) are indicated by the letter *(L)*. Instructions for smaller screens (phones and tablets) are indicated by an *(S)*.

---

## Full-Screen on Mobile Devices (S)

The map is designed to run full-screen on phones and tablets, with no browser address bar or tab bar visible. How to achieve this depends on your device and browser.

### iOS and iPadOS — Safari

The first time you visit the site, a nudge appears at the bottom of the screen:

> **For full-screen: tap Share ⬆ → Add to Home Screen**

Tap **Share ⬆** in the Safari toolbar, choose **Add to Home Screen**, and confirm. From then on, opening the site from your home screen runs it as a standalone app — no address bar, no tab bar.

Tap **✕** on the nudge to dismiss it permanently. It will not appear again.

### iOS and iPadOS — Chrome

The same nudge appears, but the instruction reads:

> **For full-screen: tap ⋯ → Add to Home Screen**

After adding it to your home screen, the site opens in full-screen standalone mode just like Safari.

### Android — Chrome

Chrome on Android automatically hides the address bar as you scroll, so no nudge is needed. For a permanently full-screen experience, tap **⋮** (the three-dot menu) → **Add to Home Screen** to pin the site as a home screen app.

---

## Native App (iOS & Android)

In addition to the web map at [marsaprs.org](https://marsaprs.org), a native app is available for iPhone and Android. It provides the same live tracker map and location sharing as the web map, with one key advantage: **location sharing continues in the background** — your GPS position keeps updating even when the screen is locked or you switch to another app.

### Getting the App

#### iPhone / iPad

1. Install **TestFlight** from the App Store (free, by Apple).
2. Open the invitation email from the event coordinator and tap **View in TestFlight**.
3. If you are a new TestFlight user, enter the redeem code from the email when prompted.
4. Tap **Install** to install the app.

For future updates, open TestFlight and tap **Update** next to APRS Map.

#### Android

1. Open the email from the event coordinator and tap the APK download link.
2. When prompted, open the file with **Package Installer**.
3. If Android warns that the app is from an unknown source, tap **More details** → **Install anyway**.
4. Tap **Open** when installation completes.

The first time you run the app it will ask for location permission — tap **Allow**. When you first tap Share Location the app will also ask for notification and battery permissions; grant both (see [Share Location in the App](#share-location-in-the-app)).

**Event password:** Each time the app opens you will be prompted for the event password (provided by the event administrator). The password is shown as plain text so you can see what you are typing.

**First launch:** The first time the app opens it displays a **Quick Start** guide with a brief overview of the main features. Tap **Continue** in the top bar (or the Continue button at the bottom) to proceed to the map. You can return to the Quick Start at any time by tapping **Help** in the drawer footer, then **Quick Start**.

### App Drawer

The app has its own native drawer — separate from the web map's ⚙ gear drawer. Open it by **tapping the ☰ menu icon** in the upper-left corner of the screen, or by **swiping right from the left edge**.

The drawer header shows the app name and the current event. The body has collapsible sections:

| Section | Contents |
|---------|----------|
| **Trackers** | Colored dot, ID, name, and elapsed time. **Tap** to close the drawer, center the map, and blink the marker; the breadcrumb trail appears on the map. **Long-press** to do the same and also zoom in. |
| **Courses** | Tap the eye icon (👁) to show or hide each course overlay. |
| **Backgrounds** | Tap an entry to switch the base tile layer. |
| **Aid Stations** | **Tap** to close the drawer, center the map, and blink the marker. **Long-press** to do the same and also zoom in. |
| **iGates** | **Tap** to close the drawer, center the map, and blink the marker. **Long-press** to do the same and also zoom in. |

The drawer footer has buttons:

| Button | Action |
|--------|--------|
| **Share Location** / **Sharing** | When not sharing: opens the location sharing dialog. When sharing (shown in green): opens a panel where you can switch activity mode or stop sharing. Only shown when the event administrator has enabled mobile tracking. |
| **Save Map** | Save the current map position and zoom as your personal default. |
| **Reload Tiles** | Download fresh map tiles for the current view. See [Reload Tiles](#reload-tiles) below. |
| **Help** | Opens a panel showing organization, app version and build number, event name, your assigned callsign (while sharing), map attribution, and copyright — with buttons for **Quick Start** (built-in guide), **User Guide**, and **Submit a Bug or Suggestion**. |
| **Exit** | Close the app. |

### Tapping markers on the map

**Short tap** on any tracker, igate, or aid station marker opens a detail panel from the bottom of the screen showing the name, callsign, time since last beacon, and GPS coordinates. Tap anywhere outside the panel to dismiss it.

**Long-press** any marker (tracker, igate, or aid station) to open Google Maps in the browser, centered on that location.

Each tracker's map marker shows the **tracker ID** (such as "H1") as a label next to the dot, so you can identify trackers at a glance without opening the drawer.

### Reload Tiles

The **Reload Tiles** button in the drawer footer downloads and caches map tiles for the area currently visible on screen. Use this before heading into areas with poor cell coverage to ensure the map background stays visible when you go offline. Cached tiles are used automatically when the network is unavailable.

### Share Location in the App

The key advantage of the native app over the web map is that **location sharing continues in the background** — your GPS position keeps updating even when the screen is locked or you switch to another app. You do not need to keep the screen on or stay in the app.

**Starting:**

1. Tap **Share Location** in the drawer footer.
2. Enter your first name (pre-filled if you have shared before) and the event PIN. Both are shown as plain text.
3. Tap an **activity** button — this immediately starts sharing and closes the dialog:
   - **Walk / Run** — transmits every 60 seconds.
   - **Cycle** — transmits every 30 seconds, or immediately when you move ≥ 0.2 miles.
   - **Drive** — transmits every 15 seconds, or immediately when you move ≥ 0.2 miles.
   - **Stationary** — transmits every 2 minutes. Use when you are at a fixed post.
4. A confirmation shows your assigned APRS callsign. Your name appears in the Trackers list and you are visible on the map.

Beacon intervals and distance thresholds are configured by the event administrator and may differ from the defaults above.

**Changing activity mode while sharing:**

Tap the **Sharing** button (green) in the drawer footer. A panel appears with the activity mode buttons and a **Stop Sharing** button. Tap a different activity to switch modes immediately — your sharing session continues uninterrupted.

**Stopping:**

Tap **Sharing** (green) in the drawer footer, then tap **Stop Sharing**.

**Auto-resume:** If you close and reopen the app while sharing was active, sharing resumes automatically with the same callsign and activity mode. A brief notification confirms it.

**Distance-triggered upload:** In addition to the timed interval, the app sends an immediate beacon whenever you move past the configured distance threshold since your last upload. After a distance-triggered upload the timer resets, so you will not receive a redundant beacon moments later.

**First-time setup — Android:** When you tap Share Location for the first time, the app will prompt for two permissions:

1. **Notifications** — grant this so the foreground service notification can appear. Without it, Android will stop the background location service when the screen locks.
2. **Battery optimization** — tap **Allow** so Android does not put the app to sleep between GPS updates.

A persistent notification ("APRS Map — Sharing your location") appears in the notification bar while sharing is active. To confirm it is running: wake the screen and swipe down from the top.

**First-time setup — iOS:** When you first tap Share Location, iOS may ask to upgrade your location access from "While Using" to "Always". Tap **Change to Always Allow** — this is required for the app to report your position while the screen is off.

### Limitations

The native app does not include every feature available in the browser. The following are only available on the web map:

| Feature | Where to find it |
|---------|-----------------|
| Admin page | `marsaprs.org/admin/` in a browser |
| Kiosk Mode | Web map footer |
| Origin marker (distance & bearing) | Right-click on the web map |
| Clients panel | Web map sidebar footer |

---

## Main Map

Open the map at [marsaprs.org](https://marsaprs.org). 

### Map controls

- **Zoom buttons** — the **+** / **−** buttons in the upper-left zoom the map in and out.
- **Distance scale** — a small scale bar in the lower-right corner shows the current map
  scale. **Click (or tap) the scale** to switch between imperial (miles / feet) and metric
  (kilometers / meters). Your choice stays in effect as you pan and zoom.
- **My Location** (⊕, desktop only) — a crosshair button in the lower-right corner of the map. Click it to pan the map to your current location. It blinks while locating; if your browser denies location access or the request times out, a brief explanation appears above the button.
- **Reset Map** (↺, desktop only) — a circular arrow button just above the My Location button. Resets the map to its default view, clears breadcrumbs, and clears the Origin marker. Same as the Reset Map button in the sidebar footer.

### Sidebar (L) 

The sidebar on the left lists all sections. Each section header has two controls:

- **Visibility checkbox** (right side) — when checked, that section's objects are shown on
  the map; when unchecked, they are hidden and the section's rows dim to indicate this.
- **Click the header text** — collapses or expands the section's list to save vertical
  space. Collapse state is remembered across page reloads.

The sidebar width is adjustable: drag the divider bar between the sidebar and the map. The
width is saved in your browser and never affects other users.

### Sidebar (S)

On phones and tablets (any small device with a touch screen), the map takes up the full screen and the sidebar is replaced by a slide-in drawer.

#### Opening the Drawer

Tap the **gear icon** (⚙) in the upper-right corner of the screen to open the drawer. Tap anywhere outside the drawer, or tap the gear icon again, to close it.

#### Drawer Sections

The drawer is organized into collapsible sections. Tap a section header to expand it; the
previously open section closes automatically. Each section header also has a **visibility
checkbox**: when checked, that section's objects appear on the map; when unchecked, they
are hidden and the rows dim. Tapping the checkbox toggles visibility without collapsing or
expanding the section.

| Section | Contents |
|---------|----------|
| **Trackers** | Colored dot, ID, name, elapsed time. **Short tap** blinks and shows breadcrumb history. **Long press** blinks, shows history, closes the drawer, and zooms to the tracker. |
| **Courses** | Toggle course overlays on/off. |
| **Backgrounds** | Switch the base tile layer. |
| **Aid Stations** | Tap to zoom to an aid station and show its label. If the station has a callsign configured, its entry blinks green when a tracker beacon is received through it. Hidden if none are configured. |
| **iGates** | Same as Aid Stations. Hidden if none are configured. |
| **Help** | Version, organization, and map attribution. Shows your assigned APRS callsign while you are sharing your location. Also contains buttons for the User Guide and to submit a bug or suggestion. |

The footer of the drawer contains action buttons arranged in a grid: **Help**, **Save Map**, **Kiosk Mode**, **Admin**, and (when enabled by the administrator) **Share Location** / **Sharing**. When subscribed to messaging, a **Broadcast** button also appears.

The **Trackers** section is open by default when the drawer is first displayed.

---

### Tracker List (L,S)

Each tracker appears in the sidebar, sorted by ID, with a colored dot, the ID (such as "H1"), a full name, and the
time since its last beacon was received.

| Dot color | Meaning |
|------------|---------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

**Marker shapes:** Regular trackers use the shape configured by the administrator (circle, square, diamond, etc.). Mobile participants sharing their location via the **Share Location** feature appear as a **rounded square** (both on the map and in the sidebar) to distinguish them from fixed APRS trackers.

When a tracker has not been heard for more than 5 minutes its sidebar entry shows **stale** in red instead of an elapsed time. Hovering over the marker on the map shows the actual elapsed time (e.g., ">2h 15m ago").

The tracker's marker on the map and sidebar entry both update every 5 seconds.

#### Clicking a tracker (L)

1. **First click** — The tracker's map marker and sidebar entry blink for 5 seconds.
   Up to 10 recent beacon positions appear as "breadcrumbs" on the map as small dots connected by a dashed
   line with directional arrows. The dots blink in sync with the tracker marker. Hover
   over any dot to see when that position was received and, if available, the APRS path
   it traveled (iGates and digipeaters it passed through). Consecutive duplicate beacons
   (the same position reported twice in a row) are collapsed into a single breadcrumb. Positions
   that are less than 100 feet from the previous breadcrumb are also suppressed — only meaningful
   movement creates a new dot. The breadcrumb trail updates automatically as the tracker moves. The trail color matches the tracker's current staleness color (green/blue/red) and updates in real time.

2. **Second click** — The map zooms to the tracker's last known position.

3. **Third click** — The map resets to the default view and hides the history dots
   and breadcrumbs.

Clicking a different tracker while one is selected immediately switches focus to the new
one and hides the previous tracker's history.

If a tracker has not yet reported a position, a notification appears instead of zooming.

#### Tapping a tracker (S)

On phones and tablets the tracker list uses touch gestures instead of a click cycle:

- **Short tap** — Closes the drawer, centers the map on the tracker, and blinks its marker. The breadcrumb trail (up to 10 positions) appears as dots with directional arrows. Tap any breadcrumb dot to see when that position was received and its APRS path.
- **Long press (hold ~½ second)** — Same as a short tap, and also zooms the map in to the tracker's last known position.

The same tap / long-press behavior applies to **Aid Stations** and **iGates** in the drawer.

To open **Google Maps** centered on any tracker, igate, or aid station, **long-press its marker on the map** (not in the drawer).

Tapping a different tracker switches focus to that tracker.

---

### Courses (L,S)

Each course entry in the sidebar is a named route overlay (GPX, KML, or GeoJSON file).

- **Click the ✓** next to the course name to toggle the overlay on or off.

Multiple courses can be visible simultaneously. When they overlap, the course listed **first in the sidebar appears on top**; courses lower in the list are drawn underneath. To change the stacking order, reorder the courses in the Admin page.

Each course is displayed in its configured color and line style (solid, dashed, dotted, or dash-dot). These are set in the Admin page.

Courses always appear above iGates but below Aid Stations and Trackers, regardless of background or zoom level.

---

### Aid Stations and iGates (L, S)

Aid stations and iGates appear as black dots on the map.

- **Hover over a dot** to see its name (and APRS callsign, if configured) in a white label.
- **Click an entry in the sidebar** to make the label permanent and zoom the map to that location.
- **Click it again** to dismiss the label and reset the map.

When an aid station or iGate has a callsign configured, its sidebar entry **blinks green for 5 seconds** each time a listed tracker's beacon is received through it (i.e., the station's callsign appears in the APRS-IS q-construct path of the packet). An iGate and an aid station may share the same callsign; both entries will blink independently.

In kiosk mode, aid station names are shown permanently as floating black labels with transparent backgrounds.

---

### Backgrounds (L,S)

The Backgrounds section lists the available tile layers (e.g., OpenStreetMap, satellite,
topo). Click any entry to switch to that base map. The active layer shows a ✓.

Some tile providers only support a limited zoom level. If you switch to one and were zoomed
in beyond its maximum, the map automatically zooms out to the highest level that provider supports.

---

### Share Location (S)

When the event administrator has enabled mobile location sharing, a **Share Location** button appears in the drawer footer. This lets you broadcast your GPS position to the APRS network so other participants can see you on the map.

Location sharing is available on:
- **The web map** (marsaprs.org) — works in any mobile browser
- **The native iOS/Android app** — provides background location updates (see below)

**Starting:**

1. Tap **Share Location** at the bottom of the drawer.
2. Enter your first name (pre-filled if you have shared before) and the event PIN. Both fields are shown as plain text.
3. Tap an **activity** — this immediately starts sharing and closes the dialog:
   - **Walk / Run** — transmits every 60 s
   - **Cycle** — transmits every 30 s, or immediately when you move ≥ 0.2 mi
   - **Drive** — transmits every 15 s, or immediately when you move ≥ 0.2 mi
   - **Stationary** — transmits every 2 min (use when at a fixed post)
4. A confirmation shows your assigned APRS callsign (e.g., `K6DRK-01`). You will appear in the Trackers list and be visible on the map. Your callsign is also shown in the **Help** panel while sharing is active.

Your position is transmitted at the chosen interval and appears on this map and on external APRS sites such as [aprs.fi](https://aprs.fi) and CalTopo.

**Changing activity mode while sharing:**

Tap the **Sharing** button in the drawer footer. A panel appears with the activity buttons and a **Stop Sharing** button. Tap a different activity to switch modes immediately — your sharing session continues uninterrupted.

**Stopping:**

Tap **Sharing** in the drawer footer, then tap **Stop Sharing**.

**Callsign persistence:** The system remembers your device across sessions. If you stop sharing and start again you will be assigned the **same callsign** as before, so your track history is continuous.

**Auto-resume (native app only):** If the app is closed or restarted while sharing was active, sharing resumes automatically when the app reopens — no need to re-enter your name and PIN. A brief notification confirms the resume.

**Notes:**

- If the PIN you entered is incorrect, you will see an error — check the PIN with the event administrator.
- If an administrator ends your session remotely, you will see a notification and the button will return to **Share Location**.
- Starting a new session with the same callsign automatically clears any old breadcrumb dots from your previous session.

**Web map (browser):**
- A red **Sharing** badge appears in the upper-right corner of the map while you are sharing, and blinks briefly each time a beacon is sent.
- The screen stays on while you are sharing (the browser's Wake Lock prevents sleep). The screen dims after 30 seconds of no interaction to save battery; tap anywhere to restore full brightness.
- If the browser is sent to the background or the screen locks, position updates will stop.

**Native iOS/Android app:**
- Location sharing continues in the background while the screen is locked. You do not need to keep the app in the foreground.
- **Android:** When you first tap Share Location, the app may ask for permission to show notifications and to run without battery restrictions. Grant both — they are required for the app to keep running in the background when the screen is off. A notification ("APRS Map — Sharing your location") will appear in the notification bar while sharing is active; this confirms the background service is running. To confirm the notification is visible, wake the screen and swipe down from the top.
- **iOS:** When you first tap Share Location, iOS may ask to upgrade your location permission from "While Using" to "Always". Tap **Change to Always Allow** — this is required for the app to send your location while the screen is locked.

---

### Messaging (L, S)

When the event administrator has enabled messaging, net control operators can exchange real-time text messages with active mobile tracker participants.

#### For Operators (web map)

An **Operator** is any web user who has subscribed to the messaging channel for the current event.

**Subscribing:**

1. Click the **Messaging** button in the sidebar (it appears when messaging is enabled for the current event and you are not yet subscribed).
2. Enter your **name** (shown to mobile participants when you send a message) and the **messaging password** (provided by the event administrator).
3. Click **Subscribe**. The Messaging button is replaced by a **Broadcast** button, and you will start receiving messages.

Your subscription is remembered in the browser. On future visits you will be re-subscribed automatically without re-entering the password.

**Sending a message to a mobile tracker:**

Right-click (or Ctrl+click) a tracker entry in the sidebar. A compose window opens pre-addressed to that participant, showing the recent conversation thread above the text field. Type up to 280 characters and click **Send**.

The compose window footer also has two options:
- **Change my name** — update the name shown to mobile participants for future messages.
- **Disable messaging** — unsubscribe and clear your session. The Messaging button reappears so you can re-subscribe later.

**Receiving messages:**

When a mobile tracker sends a message, a notification window appears showing the sender's name and message text. The recent conversation thread is shown in smaller text above the new message (which appears slightly larger for easy spotting). Click **Reply** to compose a response, or **Close** to dismiss.

**Operator auto-login (Display Pis without keyboards):**

Operators using a Display Pi such as NetControl can be subscribed automatically at boot — no keyboard needed. The system administrator configures this via `~/autologin.txt` on the Pi; see your administrator for setup details.

---

#### For Mobile Tracker Participants (native app)

While sharing your location, messaging is available from the drawer.

**Receiving messages:**

Incoming messages play a three-repeat tone and show a pop-up dialog with the sender's name and text. Tap **Reply** to respond directly from the notification, or **Close** to dismiss.

**When the app is in the background:**
- **Android** — the app is brought to the foreground automatically (if "Draw over other apps" permission has been granted), and the message dialog opens. A notification also appears in case the app cannot surface immediately.
- **iOS** — a notification banner appears. Tap it to open the app; the message dialog opens automatically when the app comes to the foreground.

In both cases, if multiple messages arrived while backgrounded, the dialogs open in sequence when you return to the app.

**Sending messages:**

Tap the **Message** (💬) button in the drawer footer (visible while sharing). A compose window opens showing the recent conversation thread, scrolled to the most recent message. Type up to 280 characters and tap **Send**.

---

### Origin Marker — Distance and Bearing (L,S)

Use this to measure distance and direction between two locations.

**Right-click** anywhere on the map to place a red **Origin** marker. (Use a long click on mobile devices.)

- Hover over the Origin to see its latitude and longitude. Click the copy button in the
  tooltip to copy the coordinates to your clipboard.
- Right-click again to move the Origin to a new location.
- After placing an Origin, **left-click** (short click on mobile devices) anywhere on the map to see a popup showing the
  straight-line distance and bearing from the Origin — for example, `2.4 mi · 143° SE`.

The Origin is cleared by **Reset Map**.

---

### Reset Map (L,S)

Click **Reset Map** in the sidebar footer (or press the **Escape** key on large-screen devices) to:

- Return the map to its default position and zoom level.
- Clear any tracker history dots (breadcrumbs).
- Clear the Origin marker.

On **desktop**, there is also a ↺ Reset Map button in the lower-right corner of the map (above the zoom controls) as an alternative to the sidebar button.

On **mobile**, the **Reset Map** button in the drawer footer and the circular reset icon (↺) that floats on the map both work the same way. Either one closes the drawer if it is open, recenters the map, and clears history dots and the Origin marker.

---

### Save Map (L,S)

Click **Save Map** in the sidebar to save the current map position and zoom level as your
personal default view. This default is stored in your browser and persists across sessions.

After saving, **Reset Map** will return to this saved position rather than the event's
default. 

---

### Kiosk Mode (L)

Click **Kiosk Mode** in the sidebar to switch to a
simplified display layout, which is designed for the publicly viewable display:

- The sidebar shows Trackers, Aid Stations, and iGates only.
- Course visibility from normal mode carries over.
- Three buttons appear at the bottom of the screen:
  - **Sidebar** — show or hide the sidebar; state is remembered.
  - **Reset Map** — same as the sidebar Reset Map button.
  - **Exit** — returns to normal mode.

---

### User Guide (L,S)

You got here, didn't you?

---

### Clients (L)

Click **Clients** in the page footer to open a panel showing:

- Current Apache server load (busy and idle workers, requests per second, uptime).
- The IP addresses of connected clients and how many connections each has open.

---

## Admin Page (L,S)

Click on the Admin button and enter the password when prompted. The login form also has a **Your name for logging** field — enter your name so that admin actions (saving events, NetBird changes, etc.) are attributed to you in the server logs. Your name is saved in your browser and pre-filled on future visits. After logging in, you can edit the configuration for the currently default event. Your session remains active for 12 hours; you will not be asked for the password again if you return to the admin page within that timeframe. As mentioned earlier, all changes will be strictly local unless and until you use Save as Default Event... to publish them for others to see.

The header and footer each contain action buttons:

- **Update** — Saves your changes locally to this browser and returns to the map
  immediately. The map reflects the new settings within seconds, without saving to the
  server. See [The Concept of Events](#the-concept-of-events) above.
- **Save** — Writes the full configuration to the current event's file on the server,
  without changing which event is the default. Use this to save your work on an event
  that is not (or not yet) the default event.
- **Save as Default Event** — Saves the full configuration to the server and makes it the
  default event for all users. It will be displayed for all users who subsequently load the website.

There are four additional buttons in the header of the Admin page:

- **Exit** (far right of the header) leaves the admin page without saving.
- **Sign Out** ends your admin session.
- **💬 Messages** — opens a modal showing the full message log for the current event. An **Export** button saves the thread as a `.txt` file. A **Clear Thread** button (red-tinted) deletes the message log after a two-click confirmation: click once to arm ("Confirm Delete?" appears) and click again within 4 seconds to delete. The button resets automatically if you don't confirm in time.
- **Load...** presents a list of previously saved events from which you can choose. Loading an event has no effect on other users. In order to set the default event, use Save as Default Event.

---

### Editing the Configuration (L,S)

The page is organized into sections matching the event's configuration. Edit any field and
click **Save as Default Event...** when finished, or **Update** to apply local-only changes.
Click **Load** to discard unsaved changes and reload from the server.

If any field contains an invalid value, the offending field is highlighted in red and a
summary of errors is shown. The save is not performed until all errors are corrected.

#### Trackers

Add and remove the trackers to follow during an event.

| Field | Description |
|-------|-------------|
| Callsign | The tracker's APRS callsign (e.g., `KO6JDL-2`) |
| ID | Short label shown on the map marker and in the sidebar (e.g., `S2`) |
| Name | Full name shown in the sidebar (e.g., `Rob`) |
| Δ | Read-only diagnostic: the shortest interval between any two consecutive beacons in the last 10 received, shown as M:SS. Displays `—` if fewer than 2 beacons have been received. Refreshes automatically every 30 seconds; the last refresh time is shown next to the Trackers heading. |

Use the **+** button to add a tracker. Use the **×** button to remove one. 

#### Tracker Style

Controls the appearance of all tracker markers on the map.

| Field | Options |
|-------|---------|
| Icon | circle, square, diamond, triangle, star, cross, person |
| Label color | Hex color for the ID/name label on the map |

Marker size scales automatically with zoom level and cannot be set manually.

#### Default Section Visibility

Sets the initial visibility of each section when the event is loaded. Each section
(Trackers, Courses, Aid Stations, iGates, Backgrounds) has a checkbox; uncheck any to
hide those objects on the map by default. These defaults are saved with the event when you
use **Save as Default Event** and are loaded by all browsers on first page load. Users can
override the visibility locally during their session using the sidebar checkboxes.

#### Map Default View

Sets the default center and zoom level (range 0..19) used on page load and after Reset Map.

| Field | Description |
|-------|-------------|
| Latitude | Decimal degrees (positive = North) |
| Longitude | Decimal degrees (positive = East) |
| Zoom | Map zoom level (higher = more zoomed in) |

Click **Use Current Map** to fill in the fields from the current map position (requires
the map to have been opened in the same browser). 

Changes to Map Default View saved with **Update** immediately become your personal default
view on the map — the same as using the **Save Map** button on the main page.

#### Import and Export

The **Event**, **Trackers**, **Aid Stations**, **iGates**, and **Courses** section headers
each have a **↓** (export) and **↑** (import) button.

**Exporting** — clicking **↓** shows a format menu:

| Section | Export formats |
|---------|---------------|
| Event | YAML only (entire configuration) |
| Trackers | YAML, CSV (`callsign, id, name`) |
| Aid Stations | YAML, CSV (`name, callsign, lat, lon`), GPX |
| iGates | YAML, CSV (`name, callsign, lat, lon`), GPX |
| Courses | YAML, CSV (`name, file, color`) |

YAML filenames include the event name and today's date (e.g., `Dipsea_2026_trackers_20260614.yaml`). GPX files contain one waypoint per entry (callsign stored in the `<cmt>` field) and can be opened in GPS apps and mapping tools.

**Importing** — supported formats by section:

| Section | Import formats |
|---------|---------------|
| Event | YAML |
| Trackers | YAML, CSV |
| Aid Stations | YAML, CSV, GPX, KML, GeoJSON, JSON |
| iGates | YAML, CSV, GPX, KML, GeoJSON, JSON |
| Courses | YAML, CSV, GPX, KML, GeoJSON, JSON |

CSV imports use the first row as column headers (case-insensitive). For Aid Stations and iGates, a `callsign` column is optional — rows without it import fine. GPX imports read the callsign from the `<cmt>` field if present; KML reads it from `<description>`; GeoJSON reads a `callsign` property. Waypoints are added as new entries. Courses location files (GPX/KML/GeoJSON/JSON) are uploaded to the server and a course entry is created automatically; CSV course files (`name, file, color`) add entries without uploading. Multiple files can be selected at once.

Importing always *adds* entries and never removes or overwrites existing ones. Duplicate entries (same name, callsign, or file path) are skipped automatically.

For `.yaml` files: if the file has no type marker, a confirmation dialog appears. If you try to import a file meant for a different section, the import is blocked.

Importing does not save to the server. Use **Save as Default Event…** afterward.

#### Backgrounds

Each background entry is a map tile layer. Provide a name, the tile URL template (using
`{z}`, `{x}`, `{y}` placeholders), and the attribution HTML required by the tile provider.

**⊞ Browse Providers** — Click the grid icon (⊞) in the section header to open a tile
provider browser showing thumbnail previews of all free Leaflet providers. Providers
already added to this event are marked **Added**. Click any provider to insert it into the
list with all fields pre-filled (name, URL, attribution, and maximum zoom).

**Add Background** — Click to pick from tile layers already used in other events (your
background library). Fields are pre-filled from the saved layer.

An optional **Max Zoom** field caps how far in the user can zoom when this tile layer is
active. This is pre-filled automatically when using the provider browser.

#### Courses

Lists the course overlays for this event. Each entry has:

| Field | Description |
|-------|-------------|
| Name | Label shown in the sidebar |
| File | The overlay file (GPX, KML, or GeoJSON) |
| Color | Color swatch — click to pick a color for the course line and markers |
| Style | Four visual buttons showing the actual line pattern in the course color: **solid**, **dashed**, **dotted**, or **dash-dot**. Click one to select it. |

To add courses, use **Manage Location Files** (see below).

#### Manage Course Files

Click the **Manage Location Files** button to open the course manager panel. This panel shows
every course file across all events in a sortable table with columns for file name, event,
and last modified date. Click any column header to sort; click again to reverse the order.

**Adding a course to the current event -* The rightmost column shows either a green ✓ (the file is already in the current event) or
an **Add** button. Clicking **Add** adds the course to the current event's course list.
The change is local until you save.

If the file comes from a different event, it is automatically copied into the current
event's directory when you save.

**Uploading a new file -** Click **Upload** (or drag and drop a file onto the panel) to upload a new course file.
Supported formats: `.gpx`, `.kml`, `.geojson`, `.json`.

The uploaded file is saved into the default event's directory and appears immediately in
the table and the Courses section.

#### Aid Stations and iGates

Fixed locations shown on the map. Each entry has a name, an optional APRS callsign, a latitude, and a longitude. When a callsign is set it appears alongside the name in the map tooltip (on hover or after clicking the sidebar entry); it is not shown in the sidebar list itself. The callsign is also used for live activity detection: whenever a listed tracker's APRS packet is received through that station (the callsign appears in the packet's APRS-IS q-construct path), the station's sidebar entry blinks green for 5 seconds.

**Smart coordinate paste** — you can paste a combined latitude/longitude string (such as
`37.7749, -122.4194` copied from Google Maps) into either the lat or lon field and both
fields will be filled in automatically. Comma, semicolon, slash, and space separators are
recognized, as are N/S/E/W direction letters.

#### Legend

HTML displayed in the lower-left corner of the map in kiosk mode. The field accepts any HTML — use tags like `<b>`, `<br>`, `<a>`, `<img>`, and inline styles to format the content.

Example:
```html
<b>Race Day — June 14</b><br>Start: 7:00 AM at Mill Valley
```

#### Mobile Tracking

Controls whether participants can share their GPS position from their phone or tablet.

| Field | Description |
|-------|-------------|
| **Enable mobile location sharing** | When checked, the **Share Location** button appears in the mobile drawer for all visitors. |
| **PIN code** | Participants must enter this code to join. Leave blank to allow anyone to share without a PIN. |
| **Root callsign** | The base APRS callsign used to assign participant callsigns (e.g., `K6DRK`). Participants are assigned `K6DRK-01`, `K6DRK-02`, etc., in order of joining. |
| **Messaging password** | When set, enables real-time messaging between web operators and mobile participants. The Messages (💬) button appears in the map toolbar for web users. Operators subscribe with this password and a display name of their choosing. Leave blank to disable messaging entirely. |

Below the settings is the **active participant list**, which refreshes automatically. Each row shows the participant's name, assigned callsign, and the time of their last position update. Three actions are available per row:

| Button | Action |
|--------|--------|
| **Rename** | Change the participant's displayed name on the map. |
| **Block** | Prevent the participant from sending further position updates. They remain in the list until removed. |
| **Remove** | End the session immediately and remove the participant from the list and the map. |

#### Beacon Settings

Sets the upload interval and distance threshold for each activity mode. These values apply to both the native app and the web map.

| Mode | Default interval | Default distance |
|------|-----------------|-----------------|
| Walk / Run | 60 s | 0.2 mi |
| Cycle | 30 s | 0.2 mi |
| Drive | 15 s | 0.2 mi |
| Stationary | 2 min | 1.0 mi |

Use the **Default** button on each row to restore factory defaults. Changes take effect for active sessions within 5 seconds (the server pushes updated settings on the next map poll).

Mobile Tracking settings are saved to the server when you use **Save** or **Save as Default Event**.

---

### Event Management

#### Save

Writes the current configuration directly to the event's file on the server without
changing the active (default) event. Use this to update an event you are working on
without disrupting what other users are currently seeing.

If no event has been saved yet (new unsaved event), **Save** opens the **Save as Default
Event** dialog to name it first.

#### Save as Default Event

Saves the current (possibly unsaved) configuration as a named event and makes it the active
event immediately. A dialog prompts for the event name (or to overwrite an existing one).
After saving, the new configuration is live within about 5 seconds for all users who subsequently load the website.

If the name already exists, a warning is shown before the overwrite is confirmed. If the
event is locked (🔒), the admin password is required before the save proceeds.

#### Load

Opens a list of all saved events, sorted newest-first. From this dialog:

- **Click an event name** to load it into the editor for review without activating it.
- **Load** (next to an event) makes the selected event the current event for this user only immediately. The map and
  switch switch to the new configuration within ~5 seconds.
- **Delete** permanently removes the event and all its files from the server. **This cannot be undone.**

#### Locking an Event

Each event in the Save and Load dialogs has a lock icon (🔒). Clicking it prompts for
the admin password and toggles the lock state. A locked event (🔒, shown in red) cannot
be overwritten by Save or Save as Default Event without entering the admin password first.
Use this to protect a production event from accidental edits while still allowing it to be
loaded and previewed.

---

## NetBird Status Monitor (L,S)

The NetBird Status Monitor is a separate tool for tracking the online/offline state of the
network devices that support the APRS system — iGates, digipeaters, and similar
equipment. It is independent of the tracker map.

---

**The Problem with NetBird:*
In order for NetBird to keep track of devices, those devices must frequently announce themselves to the NetBird servers. Unfortunately, this generates a great deal of internet traffic -- more than the traffic generated by our devices themselves. In some permanent iGate locations we use cellular hotspots to connect to the internet. Our hotspot provider gives us 1GB/month free of charge. The problem is that the NetBird traffic can easily surpass that limit.

We have therefore implemented a system that allows us to turn NetBird on and off remotely. Each device contacts the marsaprs.org server every five minutes to see if should turn its NetBird service on or off. This is controlled by the WiFi admin page on our server. A user with the admin password can use this admin page to enable or disable NetBird on any remote device. The device will act on any changes at its next five-minute interval. Note that we generally keep NetBird off for any permanently located iGate that uses a cellular hotspot. This isn't necessary for temporary or "guerrilla" iGates since they're usually on for less than 24 hours per event.

### Accessing the Monitor

There are two password-protected pages:

| Page | URL |
|------|-----|
| Status | `/netbird/` |
| Admin | `/netbird/admin.php` |

Both pages share the same login as the map Admin page. Visiting `/netbird/admin.php` will redirect to the Admin login if you are not already signed in, then return you to the NetBird admin automatically.

---

### Status Page

The status page polls the server automatically and updates in real time. Two sliders in the header control timing:

- **Poll** — how often the daemon queries each device (15 s – 5 min). Your setting is remembered across sessions.
- **Refresh** — how often the browser fetches the latest results (2 – 30 s). Your setting is remembered across sessions.

Progress bars next to each slider show how far through the current interval you are.

The page stops polling after 20 minutes of inactivity and shows a **Polling Paused** overlay. Click **Resume** to restart.

#### Summary Bar

Five boxes appear at the top of the page showing a count for each state:

| Box | Color | Meaning |
|-----|-------|---------|
| **Total** | Black | Sum of all four state boxes |
| **Online** | Green | Devices actively responding to polls via NetBird |
| **Pending** | Blue | Devices recently enabled or disabled; waiting for the change to take effect |
| **Offline** | Red | Devices enabled but not responding after the pending period has elapsed |
| **Disabled** | Gray | Devices excluded from polling (NetBird is off and no pending transition) |

#### Device Table

Each row shows one monitored device:

| Column | Description |
|--------|-------------|
| Device | Name and hostname |
| IP Address | The NetBird IP address being polled |
| Group | Logical grouping (e.g., "iGate", "Digipeater") |
| Status | Current state badge |
| Last Seen | How long ago the device last responded |

#### Status Meanings

| Status | Badge color | What it means |
|--------|-------------|---------------|
| **Online** | Green | The device responded to the most recent NetBird poll |
| **Pending** | Blue | The device was recently enabled or disabled; the system is waiting to confirm the change |
| **Offline** | Red | The device is enabled but has not responded after the pending period has fully elapsed |
| **Disabled** | Gray | The device's NetBird has been switched off and the transition is complete |

**Pending** applies to both enabling and disabling a device, because NetBird changes take
effect on 5-minute clock boundaries (0:00, 0:05, 0:10, …), not immediately:

- **Enabling** — after the server marks a device enabled, the device won't connect via
  NetBird until ~30 seconds past the next 5-minute boundary. The status shows Pending
  during that window, then continues showing Pending for up to three more polling intervals
  while waiting for the first response. Only after all of that time has passed without a
  response does the status switch to Offline.

- **Disabling** — after the server marks a device disabled, it will keep responding to
  polls until ~30 seconds past the next 5-minute boundary. The status shows Pending during
  that window rather than immediately showing Disabled, since the device is still reachable.

---

### Device Admin Page

The admin page (`/netbird/admin.php`) allows operators to manage the device list and
control which devices are polled.

#### Enabling and Disabling Devices

Each device row has an **On/Off** toggle switch in the first column. Flipping the switch
takes effect immediately on the server, but the device itself acts on the change at its
next 5-minute clock boundary. Both the admin and status pages reflect the change instantly
— no need to wait for the next automatic poll.

- **On** — the device is added to the polling cycle. Status shows **Pending** (blue).
  Expect it to change to **Online** roughly 30 seconds after the next 5-minute boundary.
  If no response arrives even after that deadline plus three polling intervals, status
  changes to **Offline**.

- **Off** — the device is removed from the polling cycle. Status shows **Pending** (blue)
  until ~30 seconds past the next 5-minute boundary, then switches to **Disabled** (gray).
  The device may continue to respond to polls during this window — that is normal.

In the worst case (e.g., a change made just after a 5-minute boundary), the transition can
take just under 5 minutes to complete.

#### Adding, Editing, and Deleting Devices

Use the buttons at the bottom of the table to manage the device list:

- **Add Device** — opens a form to enter the device name, hostname, NetBird IP address, group, and
  initial enabled state. The device is added to the list and saved immediately.
- **Edit** (pencil icon) — opens an inline form to modify any field for an existing device.
  The NetBird IP address is used as the unique identifier; changing it updates the record in place.
- **Delete** (×) — permanently removes the device from the list after confirmation. This
  cannot be undone.

#### Row Buttons

Each device row has up to four action buttons:

| Button | Condition | Action |
|--------|-----------|--------|
| **ssh** | Online + enabled | Opens a browser-based SSH terminal |
| **Web** | Online + enabled | Opens the device's web interface in a new tab (`http://<ip>`) |
| **Edit** | Always | Opens an inline form to edit device fields |
| **Del** | Always | Deletes the device after confirmation |

---

## WiFi Manager

We try to keep track of all the SSIDs and passwords for all the WiFi access points we use. They're maintained in an ever-growing file, which is organized in order or priority. When one of our devices boots, it starts at the top of the list and works its way down until it finds an SSID to which it can connect. The WiFi manager is used to edit and re-order this list.

It's important to keep at least one device such as a mobile phone's hotspot at the top of the list. These are "recovery hotspots". The idea is that we can always get a device to connect to a recovery hotspot in case we need to perform some manual maintenance.

The WiFi Manager at `marsaprs.org/wifi/` manages the shared list of WiFi credentials used
by all MARS Pi devices — iGates, display Pis, and the server. It requires admin login (same
session as the map admin page).

### Managing Credentials

The manager lists all configured SSIDs. Use it to add new WiFi networks or remove ones
that are no longer needed.

### Applying Changes

Changes are saved automatically to `wifi.yaml` whenever you commit an edit or reorder an
entry — no manual save step is needed. iGates and display Pis pick up the new credentials
nightly at 4 am; to apply immediately on a field device, SSH in and run
`/home/pi/auto-update.sh`.

### Adding a New SSID in the Field

It sometimes happens that we show up for an event for which we don't know the WiFi credentials in advance. To solve this, first turn on a recovery hotspot such as a mobile phone's personal hotspot. (This is why we keep them at the top of the list.) Next, connect a laptop to the same SSID. You will need to know the IP address of the device you're trying to configure. Most of our devices will display their IP address when they boot. Then login into the device from the laptop and run this command on the device:

`/home/pi/add-wifi.php`

You will be asked for the Name (pick something like "Stinson Community Center"), the SSID, and the password (8–63 characters). The script will append the hotspot to the end of the list (i.e., at the lowest priority). **Remember to turn off the recovery hotspot** so the device won't reconnect to it when rebooted. Then reboot the device.

**Note that the appended hotspot is temporary. It will be deleted when a new list is downloaded from the server.**

---

## Cloudflare Tunnel

`marsaprs.org` is published to the internet via a **Cloudflare Tunnel** — a lightweight
outbound connector (`cloudflared`) that runs on the server Pi and maintains a persistent
connection to Cloudflare's global network. This means:

- **No port forwarding or static IP is required.** The Pi can be on any network, including
  a hotspot or LTE connection, and the site remains reachable at `marsaprs.org`.
- **Traffic is proxied by Cloudflare.** All requests pass through Cloudflare before
  reaching the Pi, providing DDoS protection and TLS termination at the edge.
- **The tunnel is transparent to users.** The URL, behavior, and session handling are
  identical to a conventionally hosted site.

### Setup

The tunnel is configured once during initial server setup via `configure.sh`, which prompts
for a token from the Cloudflare Zero Trust dashboard (*Networks → Tunnels → Configure →
Install and run connector*). After that it runs automatically as a systemd service and
reconnects on reboot or network changes without any manual intervention.

### Checking tunnel status

```bash
sudo systemctl status cloudflared
```

If the tunnel is down, the site will be unreachable even though Apache is running
normally on the Pi. Restarting the service usually resolves transient connectivity issues:

```bash
sudo systemctl restart cloudflared
```

---

## Troubleshooting

### Is my iGate working?

**Quick check — external:**
Go to [aprs.fi](https://aprs.fi) and search for the iGate's callsign (e.g. `K6DRK-6`).
If it shows a packet received in the last few minutes, the iGate is reaching the APRS
network.

**If the iGate is not appearing on aprs.fi:**

1. **Check the NetBird Status page.** On the MARS APRS map [marsaprs.org](https://marsaprs.org)
   click on Admin→NetBird. If the iGate is listed, you should see *Online* next to the iGate's ID.
   If the iGate isn't on the list, you can add it via Admin→NetBird→Admin using the iGate's NetBird address.
   If the iGate is listed, but shows as *Disabled*, go to Admin→NetBird→Admin and turn it on. Nothing will happen until
   35 seconds after the next five-minute interval (i.e., 5, 10, 15, etc., minutes past the hour).
   If the iGate can't be reached, its status will change to *Offline*.
   
2. **Check internet connectivity.** The iGate must have internet access to forward packets
   to APRS-IS. If the Admin→NetBird page doesn't show *Online* after the above steps, have the
   operator check whether the cellular hotspot (if one is being used) is on and has a cellular connection.
   
3. **Reboot the iGate.** Regardless of whether the iGate is connected via a cellular hotspot or a local WiFi access point,
   have the operator reboot the iGate and check for an IP address and SSID on the diagnostic display, which appears 1-2 minutes
   after a reboot and stays on for about 15 seconds. 
   If the iGate bypasses the diagnostic screen, the iGate has no internet connection.

4. **Verify the SDR is recognized.** If the SDR isn't connected or has malfunctioned, the iGate will display *No SDR Found*
   and will wait until one is found or reboot. Tell the operator to check the USB connection to the SDR.


### Are you receiving my tracker?

**Quick check:**
Open [marsaprs.org](https://marsaprs.org) and find the callsign (or assigned ID/name) in
the *Trackers* list. 

**See where your packets were received:**
Click the tracker's name in the tracker list (or tap it on mobile) to show its breadcrumb history —
the last 10 beacon positions as dots on the map. Hover (desktop) or tap (mobile) any dot to
see the timestamp and the *APRS path*: which iGates and digipeaters the beacons traveled
through before reaching the internet.

**If the tracker is not appearing or is stuck on red** (>5 minutes since last beacon received):

1. **Is it in a good location?** Assuming the tracker has been properly configured, it's likely not close enough to an iGate or digipeater to be received.

2. **Check aprs.fi.** Go to [aprs.fi](https://aprs.fi) and search for the tracker's callsign. If
   packets appear there but not on the event map, the callsign in the event configuration
   may not match. If they don't appear on aprs.fi either, the issue is RF or
   transmitter-side.

3. **Check the tracker's configuration:** 

    * Confirm the transmitter is on 144.390 MHz.

    * Check that the tracker's GPS is on.

    * Check that the tracker is receiving latitude/longitude from the GPS.
   
    * Check that the tracker's APRS function is using GPS for location.
	
    * Check the tracker's beaconing period. Ideally, use ***smart beaconing*** which will adjust the beaconing period based on 
  travel speed, etc. If that's not available, use 30 seconds for a vehicle, 60 seconds for a bicycle or 90 seconds for a
  hiker/runner. 60 seconds is a good compromise for all uses.
  
    * Check the tracker's callsign such as MARS-3.


### How do I add a new WiFi hotspot in the field?

The iGates and display Pis download a shared WiFi credential list from the server every
night at 4:00 AM. There are two ways to add a new hotspot:

#### Permanent — via the web admin (preferred)

1. Log in to the **WiFi Manager** at [marsaprs.org/wifi/](https://marsaprs.org/wifi/)
   (requires the admin password).
2. Enter the network **Name** (a label for your records), **SSID**, and **password**.
3. Click **Add**. The new network is saved immediately and will be distributed to all
   field devices at the next nightly update (4:00 AM).

Networks at the top of the list have higher priority. Drag to reorder if needed.

**N.B.**: Be careful when reordering the list. We purposely keep cellphone hotspots at or near the top
so they can be used as *recovery hotspots*, superseding other devices farther down in the list.

#### Temporary — directly on a field device

Use this when you are on-site and need a device to connect to a new hotspot before the next nightly sync.

1. Connect your laptop to the device via a *recovery hotspot* (such as the personal-hotspot
   entry at the top of the WiFi list) or any other hotspot on the list.
2. Get the device's IP address. (The NetControl and BigTV devices show their IP address when they reboot.)
3. SSH to the device: `ssh pi@<device-ip>`
4. Run: `/home/pi/add-wifi.php`
5. Enter the network name, SSID, and password when prompted.
6. The device will connect immediately.
7. **Turn off the recovery hotspot** once the device has connected to the new network.

**N.B.**: Hotspots added this way are *temporary*. At 4:00 AM the device will
re-download the master WiFi list from the server and the locally added entry will be
removed unless you also added it via the web admin (step above).

### How do I determine how frequently a tracker is beaconing?

#### Quickest method — Admin page

Log in to the **Map Admin** page at [marsaprs.org/admin/](https://marsaprs.org/admin/)
and look at the **Trackers** table. The **Δ** column shows the shortest observed interval
between any two consecutive beacons in the last 10 received, in M:SS format. For example,
**4:05** means the tracker was heard twice within 4 minutes and 5 seconds — a reliable
indicator of the configured beacon rate. The table auto-refreshes every 30 seconds.

> **Δ shows `—`** if fewer than two beacons have been received yet.

#### On the map — breadcrumb timestamps

Click the tracker name in the sidebar (or tap on mobile) to show breadcrumb history. Hover
(desktop) or tap (mobile) any dot to see the exact time that beacon was received. Compare
the times on two adjacent dots to estimate the interval.

#### External reference — aprs.fi

Go to [aprs.fi](https://aprs.fi) and search the tracker's callsign. The packet log shows
timestamps for every packet that reached APRS-IS, giving a complete view of the beacon
cadence independent of the event map.
