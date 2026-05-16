# APRS Tracker Map

**Version 1.8 beta**

Real-time APRS position tracking displayed on an interactive Leaflet map. A PHP daemon
connects to the APRS-IS network, decodes incoming packets, and writes position data to a
JSON file that the browser polls every five seconds.

**Author:** Doug Kaye (K6DRK)  
**Copyright:** 2026 Doug Kaye. All Rights Reserved.

---

## System Architecture

```
APRS-IS Network
      │  TCP port 14580
      ▼
aprsDaemon.php  ──writes──▶  trackers.json
      │                            │
      └── reads ──▶ config.yaml ◀──┘
            │  (symlink → events/<EventName>/event.yaml)
            ▼
       index.php  ◀── browser polls every 5 s
            │
       admin/index.php  (config editor)
```

| File | Role |
|------|------|
| `aprsDaemon.php` | Background daemon; connects to APRS-IS, decodes packets, writes `trackers.json` and `tracker_history.yaml` |
| `config.yaml` | Symlink → active event's `event.yaml`; shared by daemon and web server |
| `config_parse.php` | Shared minimal YAML parser (no PHP YAML extension required) |
| `trackers.json` | Live tracker state written by daemon, read by browser via `?json` endpoint |
| `index.php` | Leaflet map page; serves `?json`, `?config`, `?history`, and `?clientstatus` endpoints |
| `admin/index.php` | Password-protected web GUI for editing `config.yaml`; serves all admin API endpoints |

---

## Requirements

- PHP 8.x CLI (daemon) and web (Apache on the Pi)
- PHP `sockets` extension enabled
- Web-accessible directory writable by the web process (for `trackers.json`, `config.yaml`, and event files)
- Network access to an APRS-IS server (default: `noam.aprs2.net:14580`)

---

## Configuration (`config.yaml`)

`config.yaml` is a symlink pointing to the active event's `event.yaml` file (e.g.,
`events/Dipsea 2026/event.yaml`). Both `aprsDaemon.php` and `index.php` monitor it via
`filemtime`; changes take effect within ~5 seconds without restarting the daemon or
reloading the browser.

### `event`

Name of the event displayed in the lower-left corner of the map.

```yaml
event: Dipsea 2026
```

### `legend`

Optional multi-line text displayed in the lower-left corner of the map, below the event name.

```yaml
legend: "Saturday, June 14, 2026\nMill Valley to Stinson Beach"
```

### `trackers`

One entry per callsign to track.

```yaml
trackers:
  - callsign: W6SG-4    # APRS callsign
    id: S2              # short label shown on the map marker and in the sidebar
    name: Rob           # full name shown in the sidebar
```

### `tracker_style`

Controls the appearance of all tracker markers on the map.

```yaml
tracker_style:
  icon: circle          # circle, square, diamond, triangle, star, cross, person
  size: 8               # marker radius in pixels, 4–20
  label_color: "#000000"  # hex color for the id/name label on the map
```

### `map`

Default map centre and zoom level used on page load and after the map is reset.

```yaml
map:
  lat: 37.87255         # decimal degrees, positive = North
  lon: -122.544079      # decimal degrees, positive = East
  zoom: 13              # Leaflet zoom (0=world … 19=max)
```

### `backgrounds`

Tile layers listed in the sidebar. Clicking one switches the base map.

```yaml
backgrounds:
  - name: OpenStreetMap
    url: https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png
    attribution: "&copy; <a href='https://www.openstreetmap.org/copyright'>OpenStreetMap</a> contributors"
```

### `courses`

GPX, KML, GeoJSON, or JSON overlays. Multiple courses can be active simultaneously.
Files must live in the same event directory as `event.yaml`.

```yaml
courses:
  - name: 13 Mile
    file: events/Dipsea 2026/13_Mile.geojson
    color: "#2196f3"    # optional; hex color for the course line
```

Supported extensions: `.gpx` `.kml` `.geojson` `.json`

Course entries are automatically reconciled with the event directory on every admin load
or save: entries whose files are missing from the directory are dropped; files present in
the directory but absent from the YAML are auto-added.

### `aidstations`

Fixed locations shown as black dots on the map and listed in the sidebar.

```yaml
aidstations:
  - name: Muir Woods Clubhouse
    lat: 37.89799
    lon: -122.56667
```

### `igates`

APRS iGate stations. Same display and click behaviour as aid stations.

```yaml
igates:
  - name: Pt. Reyes Station
    lat: 38.068051
    lon: -122.808013
```

---

## Running the Daemon

### Direct (foreground)

```bash
php aprsDaemon.php
```

**Command-line options** (all optional):

| Option | Default | Description |
|--------|---------|-------------|
| `server=<host>` | `noam.aprs2.net` | APRS-IS server hostname |
| `config=<file>` | `config.yaml` | Path to configuration file |
| `trackerstatus=<file>` | `trackers.json` | Path to tracker status output file |
| `sleep=<seconds>` | `5` | Seconds between packet reads |
| `debug` | off | Print every received APRS line to stdout |

Example:

```bash
php aprsDaemon.php server=rotate.aprs2.net sleep=3 debug
```

### Via systemd (production)

```bash
sudo cp aprs-daemon.service /etc/systemd/system/
sudo cp aprs-daemon.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/aprs-daemon.sh
sudo systemctl daemon-reload
sudo systemctl enable --now aprs-daemon
```

The wrapper script (`aprs-daemon.sh`) handles PID-file-based duplicate-start protection and
structured log lines. systemd restarts the daemon on failure (10 s delay, 5 retries per 5
minutes before giving up). Logs go to `/var/log/aprs-daemon/daemon.log`.

Useful commands:

```bash
sudo systemctl status aprs-daemon
sudo journalctl -u aprs-daemon -f
sudo systemctl restart aprs-daemon
```

### Tracker Colours

`aprsDaemon.php` sets a `colour` field in `trackers.json` based on how long ago a position
was last received:

| Colour | Condition |
|--------|-----------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

### APRS Packet Decoding

The daemon decodes three position formats:

| Format | Description |
|--------|-------------|
| **Uncompressed** | `DDmm.mmN/DDDmm.mmW` — degrees and decimal minutes with N/S/E/W suffix |
| **Compressed Base91** | 4-byte Base91-encoded latitude and longitude in the payload |
| **Mic-E** | Latitude encoded in the 6-character AX.25 destination field; longitude in payload bytes 1–3 |

Packets that do not contain a recognisable position still update the "last heard" timestamp.

The daemon maintains a 60-second socket receive timeout (`SO_RCVTIMEO`). If no data arrives
for 60 seconds (e.g., due to a network change), the socket is closed and a new connection is
established automatically.

---

## Event Directory Structure

Each event configuration lives in its own subdirectory under `events/`. `config.yaml` is a
symlink to whichever event is currently active.

```
events/
├── Dipsea 2026/
│   ├── event.yaml              # event configuration (same format as config.yaml)
│   ├── Dipsea.json             # course overlay file
│   └── tracker_history.yaml    # auto-generated beacon history (not committed to git)
├── Marin Ultra Challenge 2026/
│   ├── event.yaml
│   ├── 13_Mile.geojson
│   ├── 18_Mile.json
│   └── 6_Mile.json
└── ...
config.yaml → events/Dipsea 2026/event.yaml   (symlink)
```

### `tracker_history.yaml`

Auto-generated by `aprsDaemon.php`. Stores the 10 most-recent beacon positions per tracker,
updated with every incoming APRS packet. The `?history` endpoint serves the 5 most recent.

```yaml
# Auto-generated by aprsDaemon — do not edit
KO6JDL-2:
  - lat: 37.892345
    lon: -122.598456
    ts: 1778549300
  - lat: 37.891234
    lon: -122.597345
    ts: 1778549000
```

When a tracker is deleted from the configuration and the config is saved, its history is
automatically pruned from this file. When the active event is switched, the daemon loads
the new event's `tracker_history.yaml`. History files are excluded from git.

---

## Web Interface

Open `http://<host>/` in a browser. The layout adapts automatically to the device:

- **Desktop (mouse/trackpad)** — fixed sidebar on the left; map fills the remainder.
- **Touch devices (phones and tablets, any orientation)** — map fills the full screen; a slide-in drawer is opened with the gear icon (⚙) in the upper-right corner.

### Sidebar (desktop)

Each section has a collapse checkbox on its right edge. Uncheck to hide that section's
content and save vertical space. Collapse state is saved across page reloads.

### Mobile Drawer (touch devices)

Tap the gear icon to open the drawer. Sections are collapsible accordions; tap a section header to expand it. Four footer buttons (Reset Map, Save Map, Admin, User Guide) are always visible at the bottom of the drawer.

| Section | Behaviour |
|---------|-----------|
| **Trackers** | Listed by ID. Coloured dot and elapsed time update every 5 s. |
| **Courses** | Click a name to toggle the overlay on/off. Click the colour swatch to change the course colour. |
| **Aid Stations** | Click to zoom to that location and show a name label; click again to reset. |
| **iGates** | Same as aid stations. |
| **Backgrounds** | Click to switch the base tile layer. Active layer shows ✓. |
| **Reset Map** | Restores default centre and zoom; clears all selections and the Origin marker. |
| **Save Map** | Saves the current map position and zoom as a personal browser-local default. Persists across sessions; overrides the server's map default. |
| **Admin** | Opens the admin configuration page. |
| **Kiosk Mode** | Switches the UI layout to kiosk mode (see below). |
| **Clients** | Opens a modal showing Apache worker stats and connected client IPs. |

### Tracker Clicks (three-click cycle)

1. **First click** — Blinks the tracker dot on the map and in the sidebar for 5 seconds.
   Displays the tracker's beacon history: up to 5 recent positions as small dots connected
   by a dotted polyline with directional arrows. History dots and the line blink in sync
   with the tracker icon. Hovering a history dot shows elapsed time (e.g., "3m 45s ago").
2. **Second click** — Zooms the map to the tracker's last known position (zoom 15).
3. **Third click** — Resets the map to the default view and clears the history dots and line.

Clicking a different tracker restarts the cycle for that tracker and clears the previous
tracker's history. If a tracker has never reported a position, a toast notification is
shown instead of zooming.

### Origin, Distance, and Bearing

Right-clicking anywhere on the map places a red **Origin** marker. Hovering or tapping it
shows its lat/lon with a copy-to-clipboard button. Right-clicking again moves it.

Once an Origin is set, left-clicking anywhere on the map shows a popup with the straight-line
distance (miles) and bearing from the Origin — e.g., `2.4 mi · 143° SE`.

The Origin is cleared by **Reset Map**.

### Map Layer Z-Order

1. Trackers — always on top
2. Aid Stations
3. Courses
4. iGates — always underneath

### Kiosk View Mode

Click **Kiosk Mode** in the sidebar, or add `?kiosk=1` to the URL. This is an app-level UI
mode distinct from the browser's fullscreen (`--kiosk`) mode:

- Sidebar shows Trackers, Aid Stations, and iGates only; Courses and Backgrounds are hidden.
- Course visibility state carries over from normal mode.
- The first background tile layer is selected automatically.
- Footer shows three small buttons:
  - **Sidebar** — toggles sidebar visibility; state persists across sessions.
  - **Reset Map** — restores default view and clears all selections.
  - **Exit** — returns to normal mode. On the display Pi, navigates to
    `http://localhost:8080/exit` which kills Chromium so the OS can restart it cleanly.

---

## Admin Page

Open `http://<host>/admin/` in a browser. Password is stored in plain text in
`admin/password.txt`; edit that file directly on the server to change it.

The admin page edits all sections of the active event's configuration:

- **Trackers** — callsign, ID, name; add, remove, reorder
- **Tracker Style** — icon shape, size, label colour
- **Map Default View** — default centre latitude/longitude and zoom level
- **Backgrounds** — tile layer name, URL template, and attribution HTML
- **Courses** — name, file path, colour; add via Manage Location Files
- **Aid Stations** — name, latitude, longitude
- **iGates** — name, latitude, longitude
- **Legend** — free-text label shown on the map

### Header and Footer Buttons

- **Update** — Saves local-only sections to localStorage and returns to the map. Server
  polling is suppressed so the locally applied config persists. Does not write to the
  server.
- **Save for All Users…** — Writes the full configuration to the active event's
  `event.yaml` via `?saveversion`. Daemon and browsers pick up changes within ~5 seconds.
- **Exit** — Leaves the admin page without saving.
- **Sign Out** — Destroys the session.

### Local vs. Server Sections

The admin tracks which sections have been modified. Sections are classified:

| Classification | Sections |
|----------------|---------|
| **Local** (Update saves) | Event Name, Legend, Tracker Style, Map Default View |
| **Server-only** (Save for All Users required) | Trackers, Backgrounds, Courses, Aid Stations, iGates |

If Update is clicked when a server-only section has been edited, a modal warns the user.
Choosing **OK** saves only the local sections and discards the server-only edits in
localStorage; the form still shows the edits so the user can continue to **Save for All
Users**. Choosing **Save for All Users…** opens the full save dialog immediately.

Map Default View changes saved via Update also write to `aprs_default_view` in localStorage,
so the new map default takes effect on the main map page immediately (same effect as the
**Save Map** button).

**Load** discards unsaved edits and reloads from disk (or from `aprs_current_event` in
localStorage if a locally-applied event is active).

Latitude and longitude fields suppress the browser's number spinners. Values are validated
before the save request is sent; out-of-range values are reported per field.

### Event Management

**Save for All Users…** saves the current (possibly unsaved) configuration as a named event.
A dialog prompts for the event name (1–80 characters; letters, digits, spaces, hyphens,
underscores, periods). The event directory `events/<EventName>/` is created if needed.
If the name already exists, a blinking overwrite warning is shown.

After saving, `config.yaml` is re-pointed (via symlink) to the new event, making it the
active event. All users and the daemon pick up the change within ~5 seconds.

**Load…** shows all saved events sorted newest-first. Clicking an event name loads it
into the editor for local review. Clicking **Load & Activate** makes it the active event
immediately (updates the symlink and touches the file so the daemon reloads within 5 s).

**Delete** (in the Load dialog) permanently removes the event directory.

### Manage Location Files

Opened from the **Manage Location Files** button. Shows a sortable table of all course
files across all events — columns: **Name**, **Event**, **Last Modified**. Click any column
header to sort; click again to reverse.

The **In Event** column shows either a green ✓ (course already in the current event) or an
**Add** button. Clicking **Add** inserts the course into the current event's local course
list. The change is local until **Save for All Users…** is clicked; at that point the file
is copied into the target event's directory if it came from a different event.

Files can be uploaded (drag-and-drop or file picker), renamed, and deleted from this panel.
Supported extensions: `.gpx` `.kml` `.geojson` `.json`

---

## API Endpoints — `index.php`

All endpoints return `Cache-Control: no-store` unless noted.

### `GET ?json`

Returns the current tracker state from `trackers.json`.

**ETag / 304 caching:** responds 304 Not Modified if the file's mtime hasn't changed since
the client's last request (`If-None-Match` header).

```json
{
  "KO6JDL-2": { "lat": 37.892345, "lon": -122.598456, "ts": 1778549300, "colour": "green" }
}
```

### `GET ?config`

Returns the parsed active configuration: event name, legend, tracker_style, map, trackers,
backgrounds, courses, aidstations, igates.

**ETag / 304 caching:** uses `config.yaml` mtime.

### `GET ?history`

Returns beacon history for all trackers: the 5 most-recent positions per callsign.

**ETag / 304 caching:** uses `tracker_history.yaml` mtime.

```json
{
  "KO6JDL-2": [
    { "lat": 37.892345, "lon": -122.598456, "ts": 1778549300 },
    { "lat": 37.891234, "lon": -122.597345, "ts": 1778549000 }
  ]
}
```

### `GET ?clientstatus`

Returns Apache server stats and connected client IPs. Used by the **Clients** modal.

```json
{
  "busy": 3,
  "idle": 47,
  "rps": 1.23,
  "uptime": "1 day 4 hours",
  "clients": { "192.168.0.36": 2, "10.0.0.5": 1 },
  "total": 3
}
```

Reads from Apache's `mod_status` (`/server-status?auto`) and `ss -tn` socket list.

---

## API Endpoints — `admin/index.php`

All admin endpoints require an active session (password login). Unauthenticated requests
return HTTP 401 JSON. Session is established by POSTing the password to the login form.

### `GET ?logout`

Destroys the session and redirects to the login form.

### `GET ?load`

Returns the active event's configuration as JSON, including a `_filename` key (the event
directory name) and `_exists` flags on each course entry indicating whether the file is
present on disk. Course entries are reconciled against the event directory before responding.

### `POST ?save`

Accepts a JSON body with the full configuration, validates it, and writes it to `config.yaml`
(which, via the symlink, writes the active event's `event.yaml`). Returns `{"ok": true}` or
`{"errors": [...]}` with HTTP 422 on validation failure. Prunes deleted trackers from
`tracker_history.yaml`.

### `GET ?versions`

Returns a list of all saved events sorted newest-first.

```json
[
  { "name": "Dipsea 2026", "eventName": "Dipsea 2026", "mtime": 1778549300, "active": true },
  { "name": "Marin Ultra Challenge 2026", "eventName": "Marin Ultra Challenge 2026", "mtime": 1778100000, "active": false }
]
```

### `POST ?saveversion`

Accepts `{ "name": "<EventName>", "cfg": { ... } }`. Creates `events/<EventName>/` if it
doesn't exist, copies any cross-event course files into the target directory, reconciles
the courses section against the directory, writes `event.yaml`, and re-points `config.yaml`
to the new event. Returns `{"ok": true}`.

### `GET ?loadversion&name=<EventName>`

Returns the named event's configuration as JSON, with courses reconciled against the event
directory.

### `POST ?deleteversion`

Accepts `{ "name": "<EventName>" }`. Recursively deletes the event directory.

### `GET ?locationfiles`

Returns course files in the currently active event directory.

```json
[
  { "path": "events/Dipsea 2026/Dipsea.json", "mtime": 1778000000 }
]
```

### `GET ?alllocationfiles`

Returns course files across all event directories.

```json
[
  { "name": "Dipsea.json", "event": "Dipsea 2026", "path": "events/Dipsea 2026/Dipsea.json", "mtime": 1778000000, "current": true },
  { "name": "13_Mile.geojson", "event": "Marin Ultra Challenge 2026", "path": "events/...", "mtime": 1777000000, "current": false }
]
```

Sorted alphabetically by filename.

### `POST ?upload`

Accepts a `multipart/form-data` POST with a `file` field. Saves the file into the active
event directory with unsafe characters replaced by underscores. Returns
`{ "ok": true, "file": "events/<EventName>/<filename>" }`.

Allowed extensions: `.gpx` `.kml` `.geojson` `.json`

### `POST ?renamefile`

Accepts `{ "oldName": "<filename>", "newName": "<filename>" }`. Renames a file within the
active event directory. Returns `{ "ok": true, "oldFile": "...", "newFile": "..." }`.
Returns 409 if the new name is already in use.

### `POST ?deletefile`

Accepts `{ "name": "<filename>" }`. Permanently deletes a course file from the active event
directory.

### `POST ?setactiveevent`

Accepts `{ "name": "<EventName>" }`. Re-points `config.yaml` to the named event's
`event.yaml` without modifying the event's content. The daemon reloads within ~5 seconds.

### `GET ?bglib`

Returns a deduplicated list of all background tile layers found across every event's
`event.yaml`, sorted alphabetically by name. Used to populate the background library
picker in the admin UI.

```json
[
  { "name": "OpenStreetMap", "url": "https://{s}.tile.openstreetmap.org/...", "attribution": "..." }
]
```

---

## Deployment

### Syncing code to aprs-pi

```bash
bash sync-to-pi.sh
```

Pushes all PHP, JS, CSS, and course files to `aprs-pi:/var/www/html/` via rsync. Protects
server-side data from being overwritten:

| Excluded from sync | Reason |
|--------------------|--------|
| `config.yaml` | Live symlink on the Pi; syncing would break it |
| `trackers.json` | Live data written by daemon |
| `events/` | All event configs and history live on the Pi |
| `aprs-daemon.service`, `aprs-daemon.sh` | Installed as system files, not web files |
| `temp/`, `.git/` | Not needed on server |

### Backing up from aprs-pi

```bash
bash backup-from-pi.sh
```

Pulls `events/` and `configs/` from `aprs-pi:/var/www/html/` into a timestamped directory
at `/Users/doug/aprs-backups/YYYYMMDD-HHMMSS/`. Run locally; not synced to the Pi.

---

## File Permissions

The web root on aprs-pi is `/var/www/html/`, owned `pi:www-data` with mode `775`.
This allows the `pi` user to write files via rsync, while the `www-data` group (used by
Apache and the daemon) can read and execute.

The daemon (`aprs-daemon.service`) runs as `www-data` with `UMask=0002`, so files it
creates (trackers.json, tracker_history.yaml, event.yaml) get mode `664` (rw-rw-r--),
readable and writable by both `www-data` user and group.

| File | Needs write access | Created by |
|------|-------------------|------------|
| `trackers.json` | www-data (daemon) | daemon on first run |
| `config.yaml` | www-data (admin page) | manual / admin Save As |
| `events/<Event>/event.yaml` | www-data (admin page) | admin Save As |
| `events/<Event>/tracker_history.yaml` | www-data (daemon) | daemon on first beacon |
| Course files (`*.gpx`, etc.) | www-data (upload) | admin upload or rsync |

To repair ownership after manual file operations:

```bash
sudo chown -R pi:www-data /var/www/html/events/
sudo chmod -R g+w /var/www/html/events/
```

---

## Pi-Tools

Scripts in the `pi-tools/` directory are deployed manually to each Pi as needed. They are
not synced by `sync-to-pi.sh`.

### `aprs-daemon.service` / `aprs-daemon.sh`

Systemd service unit and wrapper script for running the daemon. See
[Running the Daemon](#running-the-daemon) above.

### `set-hostname.sh`

Changes the system hostname on the **display Pi** everywhere it needs to be set, so the
change persists across reboots. Run as root:

```bash
sudo set-hostname.sh <new-hostname>
```

Updates:
- `/boot/firmware/user-data` — cloud-init reads this on every boot and would otherwise
  overwrite `/etc/hostname`; the script patches the `hostname:` line in place.
- `/etc/hostname`
- `/etc/hosts` — replaces all occurrences of the old hostname
- Kernel UTS name (`hostname <new>`) — takes effect immediately in the shell prompt
- `hostnamectl set-hostname` — updates systemd's view

After running, execute `exec $SHELL` to refresh the current shell prompt without logging
out.

### `kill-server.py`

A minimal Python HTTP server that runs on `localhost:8080` on the **display Pi**. Serves
two routes:

| Path | Behaviour |
|------|-----------|
| `GET /` | Returns a "Connecting…" page with animated dots; JS polls `marsaprs.org` and auto-redirects when reachable |
| `GET /exit` | Kills all Chromium processes (`pkill chromium`) and responds `ok` |

The APRS map's kiosk **Exit** button navigates to `http://localhost:8080/exit` (via
`window.location.href`, not `fetch`, to bypass Chrome's Private Network Access preflight).
This lets the OS restart Chromium cleanly.

### `aprs-monitor.sh`

Runs on the **display Pi**. Polls `marsaprs.org` every 30 seconds. If the site becomes
unreachable, kills Chromium and relaunches it pointing to `localhost:8080` (the "Connecting"
page). When the site becomes reachable again, the connecting page's JavaScript auto-redirects.

### `wifi-watchdog.sh` / `wifi-watchdog.service`

Generic NetworkManager-based WiFi watchdog. Checks connectivity every 30 seconds. On loss,
calls `/usr/local/bin/wifi-lost.sh` if it exists. On restore, calls
`/usr/local/bin/wifi-restored.sh`.

Deploy the same on any Pi; customise only the hook scripts for that device.

```bash
sudo cp wifi-watchdog.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/wifi-watchdog.sh
sudo cp wifi-watchdog.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now wifi-watchdog
```

### `wifi-restored.sh` (display Pi hook)

Called by `wifi-watchdog.sh` when WiFi is restored on the **display Pi**. Kills Chromium
and relaunches it in kiosk mode pointing to `localhost:8080`. The connecting page then
waits for `marsaprs.org` to be reachable and auto-redirects.

### `wifi-restored-aprs-pi.sh` (aprs-pi hook)

Called by `wifi-watchdog.sh` when WiFi is restored on **aprs-pi**. Restarts the APRS
daemon (so it reconnects to APRS-IS on the new network interface) and restarts the
No-IP DUC service (for DDNS update).

### `start-aprs.desktop`

Desktop launcher for the display Pi. Kills any running Chromium and relaunches it in
fullscreen kiosk display mode (`--kiosk` browser flag) pointing to `marsaprs.org`.

```bash
cp pi-tools/start-aprs.desktop ~/Desktop/
chmod +x ~/Desktop/start-aprs.desktop
```

Double-click the icon to reload the APRS map in fullscreen kiosk display mode without rebooting.

---

## Infrastructure

The system runs on two Raspberry Pi devices:

| Device | Role |
|--------|------|
| **aprs-pi** | Runs Apache + PHP, aprsDaemon.php, and Cloudflare Tunnel. Reachable at `marsaprs.org`. |
| **display Pi** | Dedicated kiosk screen. Chromium fullscreen, always showing `marsaprs.org`. |

`marsaprs.org` is served via a Cloudflare Tunnel (no port forwarding required). The tunnel
works on any network — home LAN, cellular hotspot, event venue WiFi — without router
configuration.

Cloudflare Tunnel ID: `b11404f2-7497-4822-92b4-f75db418a1fe`
