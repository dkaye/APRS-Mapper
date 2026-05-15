# APRS Tracker Map — User Guide

**Version 1.7 beta · May 14, 2026**

---

## The Concept of Events

When a user first comes to the website, they will see a map configured for the current *default event*. 
A user with admin privileges can switch to a different current event, which all new visitors will then see, using an administrative page described later.

You are free to make a variety of changes to how the map and various objects appear and are treated. So long as you don't Save your view (which requires that administration privilege and password) the changes you make are stricly local and won't affect what other users see.

---

## Main Map

Open the map at [marsaprs.org](https://marsaprs.org). The sidebar on the
left lists all sections; each section can be collapsed by unchecking the box on its right
edge.

---

### Tracker List

Each tracker appears in the sidebar, sorted alphabetically by ID, with a colored dot, the ID (such as "H1"), a full name, and the
time since its last beacon was received.

| Dot color | Meaning |
|------------|---------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

The tracker's marker on the map and sidebar entry both update every 5 seconds.

#### Clicking a tracker (three-click cycle)

1. **First click** — The tracker's map marker and sidebar entry blink for 5 seconds.
   Up to 5 recent beacon positions appear as "breadrumbs" on the map as small dots connected by a dotted
   line with directional arrows. The dots blink in sync with the tracker marker. Hover
   over any dot to see how long ago that position was received (e.g., "3m 45s ago").

2. **Second click** — The map zooms to the tracker's last known position.

3. **Third click** — The map resets to the default view and hides the history dots
   and line.

Clicking a different tracker while one is selected immediately switches focus to the new
one and hides the previous tracker's history.

If a tracker has not yet reported a position, a notification appears instead of zooming.

---

### Courses

Each course entry in the sidebar is a named route overlay (GPX, KML, or GeoJSON file).

- **Click the ✓* next to the course name to toggle the overlay on or off.

Multiple courses can be visible simultaneously.

---

### Aid Stations and iGates

Aid stations appear as black dots on the map; iGates appear as smaller black dots.

- **Click an entry in the sidebar** to zoom the map to that location and show a label.
- **Click it again** to dismiss the label and reset the map.
- **Hover over a dot** to see to what it refers.

---

### Backgrounds

The Backgrounds section lists the available tile layers (e.g., OpenStreetMap, satellite,
topo). Click any entry to switch to that base map. The active layer shows a ✓.

---

### Origin Marker — Distance and Bearing

**Right-click** anywhere on the map to place a red **Origin** marker.

- Hover over the Origin to see its latitude and longitude. Click the copy button in the
  tooltip to copy the coordinates to your clipboard.
- Right-click again to move the Origin to a new location.
- After placing an Origin, **left-click** anywhere on the map to see a popup showing the
  straight-line distance and bearing from the Origin — for example, `2.4 mi · 143° SE`.

The Origin is cleared by **Reset Map**.

---

### Reset Map

Click **Reset Map** in the sidebar to:

- Return the map to its default position and zoom level.
- Clear any tracker history dots (breadcrumbs).
- Clear the Origin marker.

---

### Save Map

Click **Save Map** in the sidebar to save the current map position and zoom level as your
personal default view. This default is stored in your browser and persists across sessions.

After saving, **Reset Map** will return to this saved position rather than the event's
default. 

---

### Kiosk Mode

Click **Kiosk Mode** in the sidebar to switch to a
simplified display layout, which is designed for the publicly viewable display:

- The sidebar shows Trackers, Aid Stations, and iGates only.
- Course visibility from normal mode carries over.
- Three buttons appear at the bottom of the screen:
  - **Sidebar** — show or hide the sidebar; state is remembered.
  - **Reset Map** — same as the sidebar Reset Map button.
  - **Exit** — returns to normal mode.

---

### Clients

Click **Clients** in the page footer to open a panel showing:

- Current Apache server load (busy and idle workers, requests per second, uptime).
- The IP addresses of connected clients and how many connections each has open.

---

## Admin Page

Click on the Admin button and enter the password when prompted. After logging in,
you can edit the configuration for the currently active event. As mentioned earlier, all changes will be strictly local unless and until you use Save for All Users... or Activate to publish them for others.

The header and footer each contain two action buttons:

- **Update** — Saves your changes locally to this browser and returns to the map
  immediately. The map reflects the new settings within seconds, without saving to the
  server. See [The Concept of Events](#the-concept-of-events) above.
- **Save for All Users…** — Saves the full configuration to the server. All browsers viewing the same event
  pick up the new configuration within about 5 seconds.

There are three additional buttons in the header of the Admin page:

- **Exit** (far right of the header) leaves the admin page without saving. 
- **Sign Out** end your admin session.
- **Load...** presents a lis of previously saved events from which you can choose. Loading an event has no affect on other users.

---

### Local vs. Server Changes

Some sections can be applied locally to your browser without affecting the server or other
users. Other sections require **Save for All Users** to take effect for everyone.

| Section | Where it applies |
|---------|-----------------|
| Event Name | Local (Update) |
| Legend | Local (Update) |
| Tracker Style | Local (Update) |
| Map Default View | Local (Update) — also updates your personal default view |
| Trackers | Server only (Save for All Users) |
| Map Backgrounds | Server only (Save for All Users) |
| Courses | Server only (Save for All Users) |
| Aid Stations | Server only (Save for All Users) |
| iGates | Server only (Save for All Users) |

If you click **Update** after editing a server-only section, a warning appears:
*"Some changes can't be applied locally. Use Save for All Users... for global changes."*

- **OK** — saves only the local-only changes and returns to the map; server-only edits
  are discarded.
- **Save for All Users…** — opens the full save dialog so you can commit everything.
- **Cancel** — returns to the editor without saving anything.

---

### Editing the Configuration

The page is organised into sections matching the event's configuration. Edit any field and
click **Save for All Users...** when finished, or **Update** to apply local-only changes.
Click **Load** to discard unsaved changes and reload from the server.

If any field contains an invalid value, the offending field is highlighted in red and a
summary of errors is shown. The save is not performed until all errors are corrected.

#### Trackers

Add, remove, and reorder the trackers to follow during an event.

| Field | Description |
|-------|-------------|
| Callsign | The tracker's APRS callsign (e.g., `KO6JDL-2`) |
| ID | Short label shown on the map marker and in the sidebar (e.g., `S2`) |
| Name | Full name shown in the sidebar (e.g., `Rob`) |

Use the **+** button to add a tracker. Use the **×** button to remove one. Drag the handle
on the left to reorder.

#### Tracker Style

Controls the appearance of all tracker markers on the map.

| Field | Options |
|-------|---------|
| Icon | circle, square, diamond, triangle, star, cross, person |
| Size | Marker radius in pixels (4–20) |
| Label color | Hex color for the ID/name label on the map |

#### Map Default View

Sets the default center and zoom level used on page load and after Reset Map.

| Field | Description |
|-------|-------------|
| Latitude | Decimal degrees (positive = North) |
| Longitude | Decimal degrees (positive = East) |
| Zoom | Map zoom level (higher = more zoomed in) |

Click **Use Current Map** to fill in the fields from the current map position (requires
the map to have been opened in the same browser). 

Changes to Map Default View saved with **Update** immediately become your personal default
view on the map — the same as using the **Save Map** button on the main page.

#### Backgrounds

Each background entry is a map tile layer. Provide a name, the tile URL template (using
`{z}`, `{x}`, `{y}` placeholders), and the attribution HTML required by the tile provider. Additional maps may be found at [Leaflet](https://leaflet-extras.github.io/leaflet-providers/preview/index.html).

Click the **library** button to pick from tile layers already used in other events.

#### Courses

Lists the course overlays for this event. Each entry shows the file name and a color
swatch. To add courses, use **Manage Location Files** (see below).

#### Aid Stations and iGates

Fixed locations shown on the map. Each entry has a name, latitude, and longitude.

#### Legend

Free-text shown in the lower-left corner of the map in kiosk mode, below the event name. Use `\n`
for line breaks.

---

### Event Management

#### Save for All Users…

Saves the current (possibly unsaved) configuration as a named event and makes it the active
event immediately. A dialog prompts for the event name. After saving, the new configuration
is live for all users within about 5 seconds.

If the name already exists, a blinking warning is shown before the overwrite is confirmed.

#### Load

Opens a list of all saved events, sorted newest-first. From this dialog:

- **Click an event name** to load it into the editor for review without activating it.
- **Load & Activate** makes the selected event the current event immediately. The map and
  switch switch to the new configuration within ~5 seconds.
- **Delete** permanently removes the event and all its files. **This cannot be undone.**

---

### Manage Location Files

Click the **Manage Location Files** button to open the file manager panel. This panel shows
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

The uploaded file is saved into the active event's directory and appears immediately in
the table and the Courses section.

#### Renaming and deleting files

Each row in the table has **Rename** and **Delete** buttons. Deleting a file permanently
removes it from disk. If the file is used by any event, that course entry will be
automatically removed the next time that event is loaded or saved.
