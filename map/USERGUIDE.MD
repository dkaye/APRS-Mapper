# APRS Tracker Map — User Guide

**Version 1.22.1 · August 10, 2026**

---

This document is a guide for users of the MARS APRS system. For technical details on the architecture and software see the [README](https://marsaprs.org/readme.html?back=/userguide.html) file.

## Contents

- [The Concept of Events](#the-concept-of-events)
- [Full-Screen on Mobile Devices](#full-screen-on-mobile-devices-s)
- [Native App (iOS & Android)](#native-app-ios-android)
  - [Getting the App](#getting-the-app)
  - [App Drawer](#app-drawer)
  - [Tapping markers on the map](#tapping-markers-on-the-map)
  - [Reload Tiles](#reload-tiles)
  - [Share Location in the App](#share-location-in-the-app)
  - [Limitations](#limitations)
  - [Apple Watch (iPhone only)](#apple-watch-iphone-only)
- [Main Map](#main-map)
  - [Sidebar (L)](#sidebar-l)
  - [Sidebar (S)](#sidebar-s)
    - [Opening the Drawer](#opening-the-drawer)
    - [Drawer Sections](#drawer-sections)
  - [Tracker List](#tracker-list-ls)
    - [Clicking a tracker (desktop — three-click cycle)](#clicking-a-tracker-l)
    - [Tapping a tracker (mobile — tap vs. long press)](#tapping-a-tracker-s)
  - [Courses](#courses-ls)
  - [Aid Stations and iGates](#aid-stations-and-igates-l-s)
  - [Backgrounds](#backgrounds-ls)
  - [Share Location (S)](#share-location-s)
  - [Messaging (L,S)](#messaging-l-s)
  - [Origin Marker — Distance and Bearing](#origin-marker-distance-and-bearing-ls)
  - [Reset Map](#reset-map-ls)
  - [Save Map](#save-map-ls)
  - [Kiosk Mode](#kiosk-mode-l)
  - [Clients](#clients-l)
- [Signing In](#signing-in)
- [Admin Page](#admin-page-ls)
  - [Editing the Configuration](#editing-the-configuration-ls)
    - [Trackers](#trackers)
    - [Tracker Style](#tracker-style)
    - [Default Section Visibility](#default-section-visibility)
    - [Map Default View](#map-default-view)
    - [Import and Export](#import-and-export)
    - [Backgrounds](#backgrounds)
    - [Courses](#courses)
    - [Aid Stations and iGates](#aid-stations-and-igates)
    - [Legend](#legend)
    - [Mobile Tracking](#mobile-tracking)
  - [Event Management](#event-management)
    - [Save](#save)
    - [Save as Default Event](#save-as-default-event)
    - [Load](#load)
  - [Manage Course Files](#manage-course-files)
    - [Adding a course to the current event](#adding-a-course-to-the-current-event)
    - [Uploading a new file](#uploading-a-new-file)
- [Analyzer](#analyzer)
- [NetBird Status Monitor](#netbird-status-monitor-ls)
  - [Accessing the Monitor](#accessing-the-monitor)
  - [Status Page](#status-page)
    - [Summary Bar](#summary-bar)
    - [Device Table](#device-table)
    - [Status Meanings](#status-meanings)
  - [Device Admin Page](#device-admin-page)
    - [Enabling and Disabling Devices](#enabling-and-disabling-devices)
    - [Adding, Editing, and Deleting Devices](#adding-editing-and-deleting-devices)
- [WiFi Manager](#wifi-manager)
- [Transcriber Channels](#transcriber-channels)
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

You are free to make a variety of changes to how the map and various objects appear and are treated. So long as you don't Save your view (which requires an admin account) the changes you make are strictly local and won't affect what other users see.

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

1. On your Android phone or tablet, go to **[marsaprs.org/android](https://marsaprs.org/android/)**
   and tap **Download the App**. This link always serves the current release, so it is
   worth bookmarking.
2. When prompted, open the file with **Package Installer**.
3. If Android warns that the app is from an unknown source, tap **More details** → **Install anyway**.
4. Tap **Open** when installation completes.

To update later, download from the same link and install over the top — your settings are kept.

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

Each section header carries an eye icon (👁) on the right that shows or hides everything in
that section on the map. The **Trackers** header has two extra eyes to the left of it that
control what the map labels say rather than hiding markers: the first for the tracker **ID**,
the second for the **Name**. A dimmed, slashed eye means that part is off — with both on a
label reads `M083 James`, with only the ID eye on it reads `M083`, and with both off the
tracker labels disappear while the markers remain. All of these are remembered between
launches.

The drawer footer has buttons:

| Button | Action |
|--------|--------|
| **Share Location** / **Sharing** | When not sharing: opens the location sharing dialog. When sharing (shown in green): opens a panel to stop sharing. Only shown when the event administrator has enabled mobile tracking. |
| **Save Map** | Save the current map position and zoom as your personal default. |
| **Reload Tiles** | Download fresh map tiles for the current view. See [Reload Tiles](#reload-tiles) below. |
| **Help** | Opens a panel showing organization, app version and build number, event name, your assigned callsign (while sharing), map attribution, and copyright — with buttons for **Quick Start** (built-in guide), **User Guide**, and **Submit a Bug or Suggestion**. |
| **Exit** | Close the app. |

### Tapping markers on the map

**Short tap** on any tracker, igate, or aid station marker opens a detail panel from the bottom of the screen showing the name, callsign, time since last beacon, and GPS coordinates. Tap anywhere outside the panel to dismiss it.

**Long-press** any marker (tracker, igate, or aid station) to open Google Maps in the browser, centered on that location.

Each tracker's map marker shows the **tracker ID** (such as "H1") as a label next to the dot, so you can identify trackers at a glance without opening the drawer.

### Reload Tiles

The **Reload Tiles** button in the drawer footer downloads and caches map tiles for the area currently visible on screen. Use this before heading into areas with poor cell coverage to ensure the map background stays visible when you go offline. Cached tiles are used automatically when the network is unavailable. Map tiles are served through the MARS server, which keeps a permanent copy of event areas, so this download is fast and reliable even at the start of a busy event.

### Share Location in the App

The key advantage of the native app over the web map is that **location sharing continues in the background** — your GPS position keeps updating even when the screen is locked or you switch to another app. You do not need to keep the screen on or stay in the app.

**Starting:**

1. Tap **Share Location** in the drawer footer.
2. Enter your first name (pre-filled if you have shared before) and the event PIN. Both are shown as plain text.
3. *Optional — Ham Radio Callsign:* If you are also transmitting via an APRS-capable ham radio, tap **Ham Radio Callsign?** to expand the field and enter your base callsign (e.g., `W6SG`) and SSID (e.g., `4`). When set, you become a **hybrid tracker** — your position data comes from both the app and your radio, and your marker on the map is shown as a triangle. Leave this collapsed if you are using the app only.
4. Tap **Share Location**. Sharing begins immediately.
5. A confirmation shows your assigned APRS callsign. Your name appears in the Trackers list and you are visible on the map.

**Smart Track** — The app automatically adjusts the beacon frequency based on your GPS speed. Sharing starts in **unknown** (?) mode and within about 90 seconds Smart Track determines whether you are stationary, walking/running, cycling, or driving, and sets the appropriate interval: stationary (2 min) · walk/run (60 s) · cycle (30 s) · drive (15 s). No action is needed on your part. Beacon intervals and distance thresholds are configured by the event administrator and may differ from these defaults.

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

### Apple Watch (iPhone only)

The iPhone app includes an **Apple Watch** companion. Install it from the iPhone's **Watch** app if it does not appear by itself: **My Watch → APRS Map → Show App on Apple Watch**.

The watch does **messaging only**. It never shares your position and never beacons — that stays with the phone.

**What each device does.** The phone is the loudspeaker and the watch is the microphone. Your phone announces arriving messages aloud even from a pocket, so you hear who is calling without looking at anything; the watch's job is to let you answer. If the watch app happens to be on screen when a message arrives, it reads it out instead — only one of them ever speaks.

**Answering.** Press and hold the **Talk** button, speak, and let go. Releasing sends it — there is no confirmation step to find and no button to hunt for afterwards. The name above the button is where your reply is going.

**Where your reply goes.** The destination follows the traffic: whoever last messaged you becomes the target, so the announcement you just heard is also a statement of who you are about to answer. Swipe to the **Reply to** page to aim somewhere else. Answering a message sent to All Trackers goes back to the station that called, not to the whole net — broadcasting stays deliberate.

**Four pages**, swiped sideways: **Talk**, **Messages** (what has arrived), **Reply to** (choose a destination), and **Settings**.

**If a reply does not go out** — the phone is off, out of range, or the destination has gone — a small orange warning appears at the top of the Talk page with a count. Tap it to open the **Outbox**: it shows what you said, and offers **Retry** (which sends it to your current destination) or **Discard**. Nothing you speak is thrown away silently.

**When the phone is away.** The watch keeps working on its own over WiFi or cellular, polling for messages and sending replies directly. The status line tells you which is happening: *Phone linked*, *Direct — phone away*, or *Phone unreachable*.

**One limitation worth knowing.** watchOS only lets an app make sound while it is actually on screen. With your phone switched off or out of range **and** the watch app not showing, nothing will reach you until you raise your wrist — at which point the watch catches up and reads out what it missed. Whenever the phone is alive, it covers this.

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

The sidebar on the left lists all sections. Each section header has these controls:

- **Label eyes** (to the left of the checkbox) — these choose what the *labels on the map*
  say for that section. They never hide the markers themselves. **Trackers** has one eye for
  the tracker **ID** and one for the **Name**; **Aid/Rest Stops** and **iGates** each have one
  for the **Name** and one for the **Callsign**. A dimmed, slashed eye means that part is
  switched off. With both tracker eyes on a label reads `M083 James`; with only the ID eye on
  it reads `M083`. Turn both off and that section's labels disappear while the markers stay
  put — useful when a crowded course turns into a wall of text. Your choices are remembered in
  your browser; an administrator can set the starting state for first-time visitors with
  **Save as Default Event**.
- **Visibility checkbox** (right side) — when checked, that section's objects are shown on
  the map; when unchecked, they are hidden and the section's rows dim to indicate this.
  Individual Courses have their own checkbox.
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

**Activity icons (mobile trackers):** A small icon appears next to each mobile tracker's name showing its current activity mode: a walking figure (walk/run), bicycle (cycle), car (drive), or pin (stationary). A **?** is shown briefly when a tracker first starts sharing, while Smart Track takes its initial GPS readings. This typically resolves within 90 seconds.

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

**Native app:** tapping a tracker in the sidebar also forces its full **ID and name** to show as a label on the map — even if that label is normally hidden — reverting to your configured label settings on the next action.

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

**Hidden layers (eyeball off):** Selecting an aid station or iGate reveals it even when its layer is hidden. On the web map, a second click on a sidebar entry shows the object's full label, zooms to it, and blinks it — even if the eyeball is off — reverting on your next action. In the native app, tapping a hidden iGate or aid station displays it on the map as though its eyeball were on, again reverting on your next action.

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
3. Optionally expand the **Ham Radio** section to enter your base callsign and SSID if you are also transmitting via an APRS ham radio.
4. Tap **Share Location**. Sharing begins immediately.
5. A confirmation shows your assigned APRS callsign (e.g., `K6DRK-01`). You will appear in the Trackers list and be visible on the map. Your callsign is also shown in the **About** section of the drawer while sharing is active.

Your position is transmitted at the current interval and appears on this map and on external APRS sites such as [aprs.fi](https://aprs.fi) and CalTopo.

**Smart Track** — The map automatically adjusts the beacon frequency based on your GPS speed. Sharing starts in **unknown** (?) mode and within about 90 seconds Smart Track determines whether you are stationary, walking/running, cycling, or driving, and sets the appropriate interval: stationary (2 min) · walk/run (60 s) · cycle (30 s) · drive (15 s). Beacon intervals are configured by the event administrator and may differ from these defaults.

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

**App updates:** When a newer version of the native app is available, it shows a dismissable **Update available** prompt on launch. Tap **Update** to get it from the App Store (iOS) or download the latest APK (Android), or **Later** to postpone — updates are never forced.

---

### Messaging (L, S)

When the event administrator has enabled messaging, net control operators and mobile tracker participants can exchange real-time text messages — one-to-one, in named **groups**, or broadcast to everyone. Messaging now works like a familiar chat app: persistent conversations, delivery and read receipts, optional photo attachments, and text that can be read aloud.

#### For Operators (web map)

An **Operator** is any web user who has subscribed to the messaging channel for the current event.

**Subscribing:**

1. Click the **Messaging** button in the sidebar (it appears when messaging is enabled and you are not yet subscribed).
2. Enter your **name** (shown to everyone you message) and the **messaging password** (provided by the event administrator).
3. Click **Subscribe**. Your subscription is remembered in the browser, so on future visits you are re-subscribed automatically without re-entering the password.

**The chat panel:**

Messaging opens as a **chat panel docked to the right of the map**, in two parts:

- **Conversations list** — every conversation you are part of, each with the other party's ID and name and an unread badge. Click one to open its thread.

  Two entries are **pinned at the top** and always present: **All Trackers**, for broadcasting to everyone, and the **Event Log** (below). Neither has to be composed or scrolled to once ordinary traffic builds up.

  Below them, conversations whose other end has not been seen for a day are **hidden**, on the same 24-hour rule the New message list uses — you are looking for someone to talk to, and a station that left the event yesterday is not that. Anything unread, and whatever thread you have open, stays regardless, so the list cannot empty out under you mid-read.
- **Resizing** — drag the **left edge** of the message window to make it wider or narrower, and drag the **divider** between the conversation list and the messages to rebalance them. Both are remembered for next time.

**Thread + composer** — the selected conversation's messages, newest at the bottom, with an **always-visible message box** that is never covered by an incoming message. New messages appear live as they arrive — nothing to reload. Only your own conversations are shown; traffic between other operators and trackers is not.

**Starting a conversation or group:**

Click **New message** to see the list of **mobile trackers**. Pick one recipient for a direct chat, several for a **group**, or choose **All Trackers** to broadcast to every mobile participant. You can also click a tracker in the sidebar to open a conversation already addressed to that participant. Radio-only trackers are not listed — they cannot receive messages.

The list shows only participants you can actually reach: anyone whose app has checked in within the last 24 hours. Someone temporarily out of coverage still appears and can still be messaged — their phone receives everything the moment it reconnects — but people from a previous event drop off and no longer clutter the list.

**One person carrying two phones:**

A volunteer sometimes carries two devices — a phone and a spare, or an iPhone and an Android. Each device is a separate session, so without help they would appear twice in the list and you would never be sure which one to use.

Instead, **two devices showing the same ID and the same name are treated as one person** and appear as a single row marked *2 devices*. Message them once and **both phones receive it** — so it doesn't matter which one they happen to be holding. When they reply, the message appears as coming from that one person, whichever device they used, and everything stays in a single conversation.

This works because you control the **ID** — the short label like `CRD` or `LKL` that you set on the Admin page. Give a volunteer's second phone the same ID and name as the first, and from that moment they are one recipient. Their callsigns stay separate and unchanged behind the scenes, so APRS and the map are unaffected.

If you had already been messaging one of the phones on its own, **that conversation is not lost** — its history moves into the merged conversation, so you keep one thread with the whole story rather than two.

**Several people sharing one ID:**

When one ID covers *different* people — say `LKL Dirck` and `LKL Jerry` are both at Lake Lagunitas — they each keep their own row, and you get one extra option: **LKL (multiple)**. Choose it to reach everyone at that location in a single message. Pick a person's own row instead when you want just them.

The list is alphabetical by ID, and each **(multiple)** row sits directly above the people it covers — who are **indented beneath it**, with a small gap before the next location, so each station reads as one cluster:

```
CM (multiple)     2 people at CM
    CM Fred                        Online
    CM Liz                         Offline

CRD Stanton       2 devices        Online

LKL (multiple)    2 people at LKL
    LKL Dirck                      Online
    LKL Jerry                      Offline
```

The **(multiple)** row also tells you how many people it reaches, and a person carrying two phones shows *2 devices* on their single row.

**Checking a location checks everyone in it.** Tick **CM (multiple)** and all of CM's people are ticked with it. You can then untick anyone you want to leave out — the others stay ticked, and the **(multiple)** row simply clears to show you are no longer sending to the whole location. Tick that last person again and the group row fills back in. Unticking **(multiple)** clears the whole location at once.

The right-hand column shows **Online** (green) or **Offline** (red) for each person. Someone carrying two phones counts as Online if *either* phone has checked in recently — they are reachable, whichever one they are holding. **(multiple)** rows show no status, since they stand for several people at once. Offline is not a barrier: the message is delivered the moment that phone reconnects.

Receipts reflect this. For one person with two phones, you see the ordinary **Delivered ✓** / **Read ✓✓** as soon as either phone has it — it is still one person. For an **(multiple)** conversation you see the group wording, **Delivered to 2 of 3**, because those really are separate people.

**Sending:**

Type up to 280 characters and press **Send**. To attach a **photo**, click the **Attach** (📷) button, choose an image, and send it with or without text; recipients see a thumbnail they can click to view full-size.

**Voice:** Click the **microphone** button to dictate a message instead of typing (uses your browser's speech recognition; best in Chrome/Edge).

**Delivery & read receipts:** Each message you send shows its status — **Delivered ✓** when it reaches the recipient's device and **Read ✓✓** once opened. In a group the status reads **Delivered to 2 of 3** / **Read by 1 of 3**.

**Copying a message:** Each message carries a small **copy** icon beside its timestamp. It copies **the message text only** — no sender, no timestamp, no receipt — which is what belongs in a log entry, an email, or an incident report. The icon turns into a green tick to confirm.

**Read aloud:** Arriving messages are **spoken aloud by default** — you are usually watching the map rather than the message panel. Toggle the **Read aloud** (speaker) button to mute; your choice is remembered. Each is announced as *“Message from CRD Stanton”*, then a short pause, then the message itself — so you know who is calling without looking at the screen, and the name does not run into the first words — handy for a hands-free net control station. When it is off, a short alert **tone** plays instead; a volume slider sets its level (0 mutes).

**Settings** (in the panel): **Change my name**, adjust the alert volume, open **View all** messages, or **Sign out** of messaging.

**View all messages:**

Click **View all** to see the complete message log for the event — every message, in both directions, including other operators' conversations — as a scrollable, **searchable** feed. Type in the search box to filter by text, name, or ID, and click any message to jump into that conversation's thread and reply. Messages sent from a mobile tracker show a **location pin** — click it to centre the map on where the sender last reported (with a popup showing the sender, time, and message; if their position predates the message, the popup says so). Operators signed in with the **Delete All Messages** permission also see:

- **Export CSV** — download the whole log with full timestamps (local and UTC), callsigns, and the sender's coordinates where recorded.
- **Delete All Messages** — permanently erase the log for everyone. It asks for confirmation and cannot be undone, so export first if you need a record.

**The Event Log:**

Not everything worth recording is worth sending to anyone. Times, arrivals, decisions, a vehicle description passed over the radio — the net's own account of itself. The **Event Log** is where that goes, so it lives beside the traffic instead of on paper next to the keyboard.

Write an entry with the **📋 Log** button beside **New message**, or press **Ctrl+L** (**⌘L** on a Mac) from anywhere with the panel open — the cursor lands in the box ready to type, so an entry can be made mid-net without reaching for the mouse.

Entries are **stored and sent nowhere**. No tracker receives them, no phone or watch chimes, and no receipt comes back. They appear only in the pinned **Event Log** thread, each stamped with **who wrote it** — the log is shared between operators and gets read later by someone reconstructing what happened, so every entry names its author, including your own.

Mobile trackers never see the log, and it appears in **View all messages** alongside everything else, marked as going to *Log*, so an exported CSV carries the log and the traffic together in one timeline.

**Using two screens:**

With two monitors, the map can have one and messaging the other, instead of the panel covering the map exactly when both matter.

Choose **Open messages in a separate window** from the panel's settings menu and drag the new window to the second monitor. It fills that window completely — the map, sidebar and controls are gone — and the browser remembers where you put it. Choosing it again focuses the window you already have rather than opening another.

The two windows share your sign-in automatically, so there is nothing to log into twice. **Open it from the map window's menu**, though — a messages window opened from a bookmark, another browser, or a different profile has no sign-in to inherit and will say so.

They also work together:

- **Only one of them speaks.** While the messages window is open, it does all the reading aloud and alert tones and the map window stays silent, so a message is never announced twice. Close it and the map window takes the job back.
- **A message's location pin drives the *other* screen** — click it and the map centres and drops the marker over there, where you can see it.
- **Right-clicking a tracker on the map opens the compose over there too**, in that person's existing conversation, rather than sliding a panel across the map you are looking at.

To fill the whole monitor without browser chrome, use your browser's full-screen key (**F11**, or **⌃⌘F** on a Mac).

**Operator auto-login (Display Pis without keyboards):**

Operators using a Display Pi such as NetControl can be subscribed automatically at boot — no keyboard needed. The system administrator configures this via `~/autologin.txt` on the Pi; see your administrator for setup details.

---

#### For Mobile Tracker Participants (native app)

The app includes a full **chat screen** that mirrors the operators' panel. Open it with the **Message** (💬) button in the drawer footer (visible while sharing).

**Conversations:** The screen opens on your **Conversations** list, each entry showing the other party and an unread count. Tap one to open its thread, or tap **New message** to **Start a conversation** with an operator — or **Start a group** with several people. Each participant shows **Online** / **Offline** presence, and the picker opens with your **previous recipients already ticked** — during an event you usually message the same station repeatedly. **Cancel** closes it without starting anything.

The people list shows only those you can reach — operators and participants active in the last 24 hours — so names left over from earlier events no longer appear. **Operators are listed first**, separated by a line from the trackers below, and each group is alphabetical. As on the operator panel, someone carrying **two phones** appears once and receives your message on both, a location covering several people offers an **(multiple)** option that reaches all of them, and the right-hand column reads **Online** (green) or **Offline** (red).

**Sending:** Type up to 280 characters in the message box and tap **Send**. Tap **Attach photo** to **Take a photo** or **Choose from library**; the image sends with or without text, and either party can tap a photo to view it full-screen.

**Receipts:** Your sent messages show **Delivered ✓** and **Read ✓✓** (or **Delivered to N of M** / **Read by N of M** in a group).

**Read aloud:** Arriving messages are **spoken aloud by default** — this is a net-control tool and you are usually not watching the screen. Each is announced as *“Message from CRD Stanton”*, then a short pause, then the message. Tap the **speaker** icon to mute; your choice is remembered.

**When the app is in the background:** A message **notification** appears with a distinct, insistent **alert sound** so you don't miss it while the app is backgrounded or the screen is locked.

- **Android** — tap the notification (or the app is brought forward automatically if "Draw over other apps" is granted) to open the conversation.
- **iOS** — tap the notification banner to open the app on that conversation.

If several messages arrived while you were away, they are waiting in their conversations when you return.

---

### Origin Marker — Distance and Bearing (L,S)

Use this to measure distance and direction between two locations.

**Right-click** anywhere on the map to place a red **Origin** marker. On a laptop trackpad with no right button, **Ctrl-click** (Windows/Linux) or **⌘-click** (Mac) does the same. (Use a long click on mobile devices.)

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

## Signing In

The administrative tools — Admin page, Analyzer, NetBird Monitor, WiFi Manager, and Tickets — are accessible only to users with a personal account. Each account has a username, a password, and a set of permissions that determine which tools and actions are available.

To sign in, click **Admin** (or navigate directly to any protected page). You will be taken to the login page at `marsaprs.org/auth/login.php`. Enter your username and password and click **Sign In**. Your session lasts 24 hours; you will not be asked to sign in again unless you sign out or the session expires.

To sign out, click **Sign out** in the header of any admin page. Your session is immediately ended.

If you need an account or your password has been forgotten, contact the system administrator.

---

## Admin Page (L,S)

Click the **Admin** button to go to the admin page. If you are not already signed in, you will be taken to the login page first and returned to the Admin page after signing in. Once signed in, you can edit the configuration for the currently default event. As mentioned earlier, all changes will be strictly local unless and until you use Save as Default Event... to publish them for others to see.

What you see on the Admin page depends on your account permissions. Users with full edit access (**admin.edit**) can modify and save all sections. Users with view-only access (**admin.view**) can see all event configuration, tracker information, aid stations, and iGates, but editing controls are hidden.

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

#### Adding a course to the current event

The rightmost column shows either a green ✓ (the file is already in the current event) or
an **Add** button. Clicking **Add** adds the course to the current event's course list.
The change is local until you save.

If the file comes from a different event, it is automatically copied into the current
event's directory when you save.

#### Uploading a new file

Click **Upload** (or drag and drop a file onto the panel) to upload a new course file.
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
| **Hide** | Toggle a mobile tracker's map visibility. When on, the tracker is hidden from the map but still appears in the sidebar and tracker list, dimmed; default is off (visible). Hiding is about map clutter, not access: a hidden tracker keeps reporting, can still be messaged, and still shows how long ago it was heard from. **The native app honours this too** — the marker disappears there within a poll, and if the hidden tracker happened to be selected, its breadcrumb trail is cleared with it. |
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
event is locked (🔒), the event password is required before the save proceeds.

#### Load

Opens a list of all saved events, sorted newest-first. From this dialog:

- **Click an event name** to load it into the editor for review without activating it.
- **Load** (next to an event) makes the selected event the current event for this user only immediately. The map and
  switch switch to the new configuration within ~5 seconds.
- **Delete** permanently removes the event and all its files from the server. **This cannot be undone.**

#### Locking an Event

Each event in the Save and Load dialogs has a lock icon (🔒). Clicking it prompts for
the event password and toggles the lock state. A locked event (🔒, shown in red) cannot
be overwritten by Save or Save as Default Event without entering the event password first.
Use this to protect a production event from accidental edits while still allowing it to be
loaded and previewed.

---

## Analyzer

The Analyzer is a separate web page at `marsaprs.org/analyzer/` for recording and reviewing APRS beacon data from an event. It is intended for use by net control operators. Access requires the event password.

### Accessing the Analyzer

Navigate to `https://marsaprs.org/analyzer/`. You will be prompted for the event password. After entering the correct password, you are taken directly to the current event's analyzer map. Your session remains active for 24 hours.

### Analyzer Map

The analyzer map looks similar to the main map and inherits the same map background and starting position that you last used on the main map. The map updates automatically at the configured refresh rate (see below).

### Controls

Click **Controls** in the lower-left corner of the map to open the controls modal.

#### Recording

| Field | Description |
|-------|-------------|
| **Collect Data** | Check to start recording APRS beacons into the analyzer database; uncheck to stop. This control is only active for users with analyzer admin privileges; others see it as a read-only indicator. |
| **Started** | The date and time data collection began for the current event. |
| **Stopped** | The date and time data collection was most recently stopped (shown only when the daemon is not running). |

#### Display

| Checkbox | Default | Effect |
|----------|---------|--------|
| **Show Radio Beacons** | ✓ | Show beacons from radio (APRS) trackers on the map |
| **Show Cellular Beacons** | ✓ | Show beacons from mobile (cellular) participants |
| **Show Courses** | ✓ | Overlay the event course routes on the map |

**Auto-Refresh** — A slider sets how often the map automatically refreshes beacon data, from 30 seconds to 5 minutes. The default is 1 minute. This setting is per-browser and remembered across sessions.

#### Trackers and iGates

Two multi-select lists let you filter the displayed beacons by tracker and by receiving iGate. Select **All** (the default) to show beacons from all trackers or through all iGates. Select individual entries to narrow the view.

#### Time Range

Two sliders set the start and end of the time window displayed on the map. Drag the sliders to focus on a specific portion of the event.

#### Save Map Position

Click **Save Map Position** to save the current map center and zoom as your personal default for the Analyzer. This is stored per-browser and does not affect other users or the main map.

#### Erase All Data

Click **Erase All Data…** to permanently delete all recorded beacons for the current event. This requires analyzer admin privileges and two confirmation steps. **This cannot be undone.**

---

## NetBird Status Monitor (L,S)

The NetBird Status Monitor is a separate tool for tracking the online/offline state of the
network devices that support the APRS system — iGates, digipeaters, and similar
equipment. It is independent of the tracker map.

---

**The Problem with NetBird:*
In order for NetBird to keep track of devices, those devices must frequently announce themselves to the NetBird servers. Unfortunately, this generates a great deal of internet traffic -- more than the traffic generated by our devices themselves. In some permanent iGate locations we use cellular hotspots to connect to the internet. Our hotspot provider gives us 1GB/month free of charge. The problem is that the NetBird traffic can easily surpass that limit.

We have therefore implemented a system that allows us to turn NetBird on and off remotely. Each device contacts the marsaprs.org server every five minutes to see if should turn its NetBird service on or off. A user with NetBird admin privileges can use the NetBird Admin page to enable or disable any remote device. The device will act on any changes at its next five-minute interval. Note that we generally keep NetBird off for any permanently located iGate that uses a cellular hotspot. This isn't necessary for temporary or "guerrilla" iGates since they're usually on for less than 24 hours per event.

### Accessing the Monitor

There are two pages, both requiring a user account:

| Page | URL | Required permission |
|------|-----|---------------------|
| Status | `/netbird/` | NetBird view |
| Admin | `/netbird/admin.php` | NetBird admin |

All pages share the same sign-in. If you are not already signed in, you will be taken to the login page and returned automatically after signing in. Users with only NetBird view access see the status page in read-only mode — poll/refresh sliders and the Admin button are not shown.

---

### Status Page

The status page polls the server automatically and updates in real time. Two sliders in the header control timing:

Users with NetBird admin privileges see two sliders in the header:

- **Poll** — how often the daemon queries each device (15 s – 5 min). Your setting is remembered across sessions.
- **Refresh** — how often the browser fetches the latest results (2 – 30 s). Your setting is remembered across sessions.

Progress bars next to each slider show how far through the current interval you are.

The page stops polling after 20 minutes of inactivity and shows a **Polling Paused** overlay. Click **Resume** to restart. (View-only users see the current state at page load only; no live updates.)

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
by all MARS Pi devices — iGates, display Pis, and the server. Full editing requires a user
account with WiFi admin privileges. Users with NetBird view privileges can open the page in
read-only mode (no add, edit, delete, or reorder).

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

## Transcriber Channels

A **Transcriber** is a small computer with a radio receiver that listens to a voice frequency and writes down what it hears. Each transmission appears in the **Event Log** by itself, attributed to the frequency it was heard on:

```
Net Control  → Log
  0930 net opened

146.520  → Log
  "aid three we have a rider down"
```

You do not have to do anything for this to happen. The page at **marsaprs.org/transcriber/** is where the frequencies are set up, and most of the time it is left alone.

### Setting up a channel

**Devices** are the receivers themselves — one row per box. The **Host** must match that computer's name exactly, because that is how it identifies itself when it asks for its settings.

**Channels** are the frequencies. One row per frequency, and a single receiver can cover several at once.

| Field | What it is |
|-------|-----------|
| **ID** | A short unique name, such as `rx1-146520`. It appears in the log and cannot be reused. |
| **Device** | Which receiver listens on this frequency. |
| **Label** | What the log shows as the author — `146.520`. Make it something you will recognise mid-event. |
| **Frequency** | In hertz: 146.520 MHz is `146520000`. |
| **Serial** | The USB serial of that receiver's radio stick. Not a slot number — the sticks change order when the machine restarts, and two channels swapping frequencies is not something you would notice until the log was already wrong. |
| **Squelch** | How loud a signal must be before it counts. Too low and it transcribes hiss; too high and it clips the start of every transmission. Start at 0 and raise it only if noise is getting through. |
| **Model** | `tiny` keeps up in real time; `base` is more accurate but falls behind on a busy frequency. |
| **On** | Turn a channel off to stop it logging immediately, without deleting its setup. |

Changes save as you make them — there is no save button.

### Tokens

Each row shows whether a token is set. Tokens are never displayed after they are created, so **New…** issues a fresh one and shows it once — copy it then.

There are two kinds, and they do different jobs. A **config token** only lets a receiver collect its settings, and goes in a file on that machine. A **log token** only lets a channel write to the log. Neither can be used to read messages, so a receiver left unattended at an aid station is not a way into the net's traffic.

Issuing a new token stops the old one working straight away.

### What to expect from it

**It will get things wrong.** Radio voice is compressed and noisy, and callsigns and phonetics are exactly what a general-purpose transcriber is worst at — expect the sense of a message to survive and names to come out mangled. It is a record of what was said, not a transcript you would read into evidence.

It is deliberately cautious about what it writes down. Very short transmissions are ignored, and so are the stock phrases these systems produce when fed static — an empty log is more useful than one filling with things nobody said. A frequency with an open or stuck carrier is skipped rather than transcribed.

**Nothing is sent to anyone.** Entries go into the log and no further: no tracker receives them, no phone or watch chimes.

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
   (requires a user account with WiFi admin privileges).
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
