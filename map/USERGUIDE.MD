# APRS Tracker Map — User Guide

**Version 1.12 beta · May 22, 2026**

---

## Contents

- [The Concept of Events](#the-concept-of-events)
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

---

## The Concept of Events

When a user first comes to the website, they will see a map configured for the current **default event**. 
A user with admin privileges can switch to a different default event, which all new visitors will then see, using an administrative page described later.

You are free to make a variety of changes to how the map and various objects appear and are treated. So long as you don't Save your view (which requires that administration privilege and password) the changes you make are strictly local and won't affect what other users see.

## Large vs. Small Screens

Instructions that refer to large screens (desktops or laptops) are indicated by the letter *(L)*. Instructions for smaller screens (phones and tablets) are indicated by an *(S)*.

---

## Main Map

Open the map at [marsaprs.org](https://marsaprs.org). 

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
| **Aid Stations** | Tap to zoom to an aid station and show its label. Hidden if none are configured. |
| **iGates** | Same as Aid Stations. Hidden if none are configured. |
| **About** | Version, organization, and map attribution. |

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

The tracker's marker on the map and sidebar entry both update every 5 seconds.

#### Clicking a tracker (L)

1. **First click** — The tracker's map marker and sidebar entry blink for 5 seconds.
   Up to 5 recent beacon positions appear as "breadcrumbs" on the map as small dots connected by a dotted
   line with directional arrows. The dots blink in sync with the tracker marker. Hover
   over any dot to see how long ago that position was received (e.g., "3m 45s ago").

2. **Second click** — The map zooms to the tracker's last known position.

3. **Third click** — The map resets to the default view and hides the history dots
   and breadcrumbs.

Clicking a different tracker while one is selected immediately switches focus to the new
one and hides the previous tracker's history.

If a tracker has not yet reported a position, a notification appears instead of zooming.

#### Tapping a tracker (S)

On phones and tablets the tracker list uses touch gestures instead of a click cycle:

- **Short tap** — The tracker blinks and its breadcrumb history appears on the map. The
  drawer stays open. Tapping the same tracker again repeats the blink; there is no
  second- or third-tap action.
- **Long press (hold ~½ second)** — Same as a short tap, and additionally closes the
  drawer and zooms the map to the tracker's last known position.

Tapping a different tracker switches focus to that tracker.

---

### Courses (L,S)

Each course entry in the sidebar is a named route overlay (GPX, KML, or GeoJSON file).

- **Click the ✓** next to the course name to toggle the overlay on or off.

Multiple courses can be visible simultaneously. When they overlap, the course listed **first in the sidebar appears on top**; courses lower in the list are drawn underneath. To change the stacking order, reorder the courses in the Admin page.

Courses always appear above iGates but below Aid Stations and Trackers, regardless of background or zoom level.

---

### Aid Stations and iGates (L, S)

Aid stations appear as black dots on the map; iGates appear as smaller black dots.

- **Click an entry in the sidebar** to zoom the map to that location and show a label.
- **Click it again** to dismiss the label and reset the map.
- **Hover over a dot** to see to what it refers.

---

### Backgrounds (L,S)

The Backgrounds section lists the available tile layers (e.g., OpenStreetMap, satellite,
topo). Click any entry to switch to that base map. The active layer shows a ✓.

Some tile providers only support a limited zoom level. If you switch to one and were zoomed
in beyond its maximum, the map automatically zooms out to the highest level that provider supports.

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

Click **Reset Map** in the sidebar (or press the **Escape** key) to:

- Return the map to its default position and zoom level.
- Clear any tracker history dots (breadcrumbs).
- Clear the Origin marker.

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

Click on the Admin button and enter the password when prompted. After logging in,
you can edit the configuration for the currently default event. Your session remains active
for 12 hours; you will not be asked for the password again if you return to the admin page
within that window. As mentioned earlier, all changes will be strictly local unless and until you use Save as Default Event... or Activate to publish them for others.

The header and footer each contain action buttons:

- **Update** — Saves your changes locally to this browser and returns to the map
  immediately. The map reflects the new settings within seconds, without saving to the
  server. See [The Concept of Events](#the-concept-of-events) above.
- **Save** — Writes the full configuration to the current event's file on the server,
  without changing which event is the default. Use this to save your work on an event
  that is not (or not yet) the default event.
- **Save as Default Event** — Saves the full configuration to the server and makes it the
  default event for all users. It will be displayed for all users who subsequently load the website.

There are three additional buttons in the header of the Admin page:

- **Exit** (far right of the header) leaves the admin page without saving.
- **Sign Out** ends your admin session.
- **Load...** presents a list of previously saved events from which you can choose. Loading an event has no effect on other users.

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
| Δ | Read-only diagnostic: the shortest interval between any two consecutive beacons in the last 10 received, shown as M:SS. Displays — if fewer than 2 beacons have been received. |

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
| Aid Stations | YAML, CSV (`name, lat, lon`), GPX |
| iGates | YAML, CSV (`name, lat, lon`), GPX |
| Courses | YAML, CSV (`name, file, color`) |

YAML filenames include the event name and today's date (e.g., `Dipsea_2026_trackers_20260614.yaml`). GPX files contain one waypoint per entry and can be opened in GPS apps and mapping tools.

**Importing** — supported formats by section:

| Section | Import formats |
|---------|---------------|
| Event | YAML |
| Trackers | YAML, CSV |
| Aid Stations | YAML, CSV, GPX, KML, GeoJSON, JSON |
| iGates | YAML, CSV, GPX, KML, GeoJSON, JSON |
| Courses | YAML, CSV, GPX, KML, GeoJSON, JSON |

CSV imports use the first row as column headers (case-insensitive). Aid Stations / iGates: waypoints are added as new entries. Courses location files (GPX/KML/GeoJSON/JSON) are uploaded to the server and a course entry is created automatically; CSV course files (`name, file, color`) add entries without uploading. Multiple files can be selected at once.

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

Lists the course overlays for this event. Each entry shows the file name and a color
swatch. To add courses, use **Manage Location Files** (see below).

#### Manage Course Files

Click the **Manage Location Files** button to open the course manager panel. This panel shows
every course file across all events in a sortable table with columns for file name, event,
and last modified date. Click any column header to sort; click again to reverse the order.

##### Adding a course to the current event

The rightmost column shows either a green ✓ (the file is already in the current event) or
an **Add** button. Clicking **Add** adds the course to the current event's course list.
The change is local until you save.

If the file comes from a different event, it is automatically copied into the current
event's directory when you save.

##### Uploading a new file

Click **Upload** (or drag and drop a file onto the panel) to upload a new course file.
Supported formats: `.gpx`, `.kml`, `.geojson`, `.json`.

The uploaded file is saved into the default event's directory and appears immediately in
the table and the Courses section.

#### Aid Stations and iGates

Fixed locations shown on the map. Each entry has a name, latitude, and longitude.

**Smart coordinate paste** — you can paste a combined latitude/longitude string (such as
`37.7749, -122.4194` copied from Google Maps) into either the lat or lon field and both
fields will be filled in automatically. Comma, semicolon, slash, and space separators are
recognised, as are N/S/E/W direction letters.

#### Legend

Free-text shown in the lower-left corner of the map in kiosk mode, below the event name. Use `\n`
for line breaks.

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
After saving, the new configuration is live within about 5 seconds for all users who then load the website.

If the name already exists, a warning is shown before the overwrite is confirmed. If the
event is locked (🔒), the admin password is required before the save proceeds.

#### Load

Opens a list of all saved events, sorted newest-first. From this dialog:

- **Click an event name** to load it into the editor for review without activating it.
- **Load** (next to an event) makes the selected event the current event for this user only immediately. The map and
  switch switch to the new configuration within ~5 seconds.
- **Delete** permanently removes the event and all its files. **This cannot be undone.**

#### Locking an Event

Each event in the Save and Load dialogs has a lock icon (🔒/🔓). Clicking it prompts for
the admin password and toggles the lock state. A locked event (🔒, shown in red) cannot
be overwritten by Save or Save as Default Event without entering the admin password first.
Use this to protect a production event from accidental edits while still allowing it to be
loaded and previewed.

---

## NetBird Status Monitor (L,S)

The Device Status Monitor is a separate tool for tracking the online/offline state of the
network devices that support the APRS system — radios, iGates, digipeaters, and similar
equipment. It is independent of the tracker map.

---

### Accessing the Monitor

There are two pages:

| Page | URL | Access |
|------|-----|--------|
| Status | `/netbird/` | **Public** — no login required |
| Admin | `/netbird/admin.php` | Password-protected |

The status page is open to anyone. The admin page requires a password; once authenticated,
your session stays active for 12 hours. Logging in to either the NetBird admin page or the
main map admin page authenticates you for both.

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
| **Online** | Green | Devices actively responding to polls |
| **Pending** | Blue | Devices recently switched on, waiting for first response |
| **Offline** | Red | Devices enabled but not responding |
| **Disabled** | Gray | Devices excluded from polling |

#### Device Table

Each row shows one monitored device:

| Column | Description |
|--------|-------------|
| Device | Name and hostname |
| IP Address | The IP address being polled |
| Group | Logical grouping (e.g., "iGate", "Digipeater") |
| Status | Current state badge |
| Last Seen | How long ago the device last responded |

#### Status Meanings

| Status | Badge color | What it means |
|--------|-------------|---------------|
| **Online** | Green | The device responded to the most recent poll |
| **Pending** | Blue | The device was just switched on; the system is waiting for it to respond |
| **Offline** | Red | The device is enabled but has not responded within the grace period |
| **Disabled** | Gray | The device has been switched off in the admin page and is not being polled |

**Pending** — when a device is first switched on, it may take time to boot and connect.
The badge shows **Pending** (blue) until the daemon receives a response, so operators
are not alarmed by a brief startup delay.

---

### Device Admin Page

The admin page (`/netbird/admin.php`) allows operators to manage the device list and
control which devices are polled.

#### Enabling and Disabling Devices

Each device row has an **On/Off** toggle switch in the first column. Flipping the switch:

- **On** — the device is added to the polling cycle. The Status column immediately shows
  **Enabling** (blue) while the system waits for the first response. The main status page
  also updates instantly — no need to wait for the next automatic poll.
- **Off** — the device is removed from the polling cycle. Status immediately shows
  **Disabled** (gray) on both the admin and main pages.

#### Adding, Editing, and Deleting Devices

Use the buttons at the bottom of the table to manage the device list:

- **Add Device** — opens a form to enter the device name, hostname, IP address, group, and
  initial enabled state. The device is added to the list and saved immediately.
- **Edit** (pencil icon) — opens an inline form to modify any field for an existing device.
  The IP address is used as the unique identifier; changing it updates the record in place.
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

#### SSH Terminal

If SSH credentials have been saved for a device, clicking **ssh** opens a terminal.
The button is grayed out if the device is not currently Online.

Clicking **ssh** opens a browser-based terminal window connected to that device via SSH.
The terminal fills the popup window and resizes automatically if you resize the window.

When you exit the SSH session (type `exit`, press Ctrl-D, or the connection closes for
any reason), the terminal window closes automatically.

**Saving credentials** — SSH credentials are stored per-device in the server configuration.
Check **Remember password** when prompted to save the password alongside the username.
If a password is not saved, you will be prompted for it each time you open an SSH session.

---

### Hostname Self-Reporting

Devices can report their own hostname by making an HTTP request to the server. This
allows the status page to display the device's self-reported hostname alongside the
configured name, which can be useful when IP addresses are assigned by DHCP.

To enable self-reporting, configure a device to periodically call:

```
GET /netbird/checkin.php?ip=<device-ip>
```

The server records the timestamp and hostname. No authentication is required for
this endpoint — it is intended for device-initiated calls.

---

## WiFi Manager

The WiFi Manager at `marsaprs.org/wifi/` manages the shared list of WiFi credentials used
by all MARS Pi devices — iGates, display Pis, and the server. Requires admin login (same
session as the map admin page).

### Managing Credentials

The manager lists all configured SSIDs. Use it to add new WiFi networks or remove ones
that are no longer needed.

### Applying Changes

Changes are saved automatically to `wifi.yaml` whenever you commit an edit or reorder an
entry — no manual save step is needed. iGates and display Pis pick up the new credentials
nightly at 4 am; to apply immediately on a field device, SSH in and run
`/home/pi/auto-update.sh`.
