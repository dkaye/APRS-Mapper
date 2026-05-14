# APRS Tracker Map

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
                        │
                        ▼
                   index.php  ◀── browser polls every 5 s
                        │
                   admin/index.php  (config editor)
```

| File | Role |
|------|------|
| `aprsDaemon.php` | Background daemon; connects to APRS-IS, decodes packets, writes `trackers.json` |
| `config.yaml` | Master configuration — trackers, map view, backgrounds, courses, aid stations, iGates |
| `config_parse.php` | Shared minimal YAML parser (no PHP extension required) |
| `trackers.json` | Live tracker state written by daemon, read by browser via `?json` endpoint |
| `index.php` | Leaflet map page; also serves `?json` and `?config` API endpoints |
| `admin/index.php` | Password-protected web GUI for editing `config.yaml` |

---

## Requirements

- PHP 8.x CLI (daemon) and web (Apache/Nginx on the Pi)
- PHP `sockets` extension enabled
- Web-accessible directory writable by the web process (for `trackers.json` and `config.yaml`)
- Network access to an APRS-IS server (default: `noam.aprs2.net:14580`)

---

## Running the Daemon

```bash
php aprsDaemon.php
```

The daemon runs in the foreground. Use a process manager (systemd, screen, tmux) to keep it
running. It reconnects automatically when `config.yaml` changes and the tracker list differs.

**Command-line options** (all optional):

| Option | Default | Description |
|--------|---------|-------------|
| `server=<host>` | `noam.aprs2.net` | APRS-IS server hostname |
| `config=<file>` | `config.yaml` | Path to configuration file |
| `trackerstatus=<file>` | `trackers.json` | Path to tracker status output file |
| `sleep=<seconds>` | `5` | Seconds to sleep between packet reads |
| `debug` | off | Print every received APRS line to stdout |

Example:

```bash
php aprsDaemon.php server=rotate.aprs2.net sleep=3 debug
```

**Status colours** written to `trackers.json`:

| Colour | Meaning |
|--------|---------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

---

## Configuration (`config.yaml`)

Both `aprsDaemon.php` and `index.php` monitor `config.yaml` via `filemtime`; changes take
effect within ~5 seconds without restarting the daemon or reloading the browser.

### `trackers`

One entry per callsign to track.

```yaml
trackers:
  - callsign: W6SG-4    # APRS callsign
    id: S2              # short label shown on the map marker and in the sidebar
    name: Rob           # full name shown in the sidebar
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
Files must exist in the same directory as `config.yaml`.

```yaml
courses:
  - name: 13 Mile
    file: 13_Mile.geojson
```

Supported extensions: `.gpx` `.kml` `.geojson` `.json`

### `aidstations`

Fixed locations shown as black dots on the map and listed in the sidebar.
Clicking a name in the sidebar zooms the map to that location and shows a permanent label;
clicking again resets the map.

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

## Web Interface

Open `http://<host>/` in a browser.

### Sidebar

Each section heading has a checkbox on the right. Uncheck to collapse that section to its title only, saving vertical space. State is saved across page reloads.

| Section | Behaviour |
|---------|-----------|
| **Trackers** | Listed by ID. Coloured dot and elapsed time update every 5 s. |
| **Courses** | Click a name to toggle the overlay on/off. Click the colour swatch to change the course colour. |
| **Aid Stations** | Click to zoom to location and show name label; click again to reset. |
| **iGates** | Same as aid stations. |
| **Backgrounds** | Click to switch the base tile layer. Active layer shows ✓. |
| **Reset Map** | Restores the default centre and zoom; clears all selections and the Origin marker. |
| **Admin** | Opens the admin configuration page. |
| **Kiosk Mode** | Reloads in kiosk mode (see below). |
| **Clients** | Opens a modal showing Apache worker stats and connected client IP addresses. |

### Tracker Clicks (three-click cycle)

1. **First click** — Blinks the tracker dot on the map and in the sidebar for 5 seconds. Also displays the tracker's beacon history: up to 5 recent positions as small dots, connected by a dotted line with directional arrows showing direction of travel. History dots and line blink in sync with the main tracker icon. Hovering over a history dot shows the elapsed time (e.g., "3m 45s ago").
2. **Second click** — Zooms the map to the tracker's last known position (zoom 15).
3. **Third click** — Resets the map to the default view and clears the history dots and line.

Clicking a different tracker at any point restarts the cycle for that tracker, clearing history from the previous tracker.

**No Location Received** — If a tracker has never reported a position, clicking its sidebar entry shows a toast notification saying "No location received" instead of zooming to a non-existent position.

### Origin, Distance, and Bearing

Right-clicking anywhere on the map places a red **Origin** marker at that point. Hovering over
the Origin marker (or tapping it on mobile) shows its latitude/longitude with a copy-to-clipboard
button. Right-clicking again moves the Origin to the new location.

Once an Origin is set, **left-clicking** anywhere on the map shows a popup at the clicked point
with the straight-line distance (in miles) and bearing from the Origin — for example,
`2.4 mi · 143° SE`. This makes it easy to measure range and direction from a fixed reference
point to any tracker or map feature.

The Origin marker is removed by clicking **Reset Map**.

### Z-ordering

Map layers are rendered in the following order, top to bottom:

1. **Trackers** — always on top
2. **Aid Stations**
3. **Courses**
4. **iGates** — always underneath the others

### Kiosk Mode

Click **Kiosk Mode** in the sidebar, or append `?kiosk=1` to the URL. Intended for unattended displays:

- Browser enters full screen automatically.
- Sidebar shows Trackers, Aid Stations, and iGates only; Courses and Backgrounds are hidden.
- Courses that were visible in normal mode remain visible; hidden courses stay hidden.
- The first background tile layer is selected automatically.
- Tracker labels and dot blinking work exactly as in normal mode.
- Footer shows three small buttons:
  - **Sidebar** — shows or hides the sidebar. State is remembered across kiosk sessions.
  - **Reset Map** — restores the default view and clears all selections and the Origin marker.
  - **Exit** — returns to normal mode.

---

## Admin Page

Open `http://<host>/admin/` in a browser.

The admin page allows editing all sections of `config.yaml`:

- **Trackers** — add, remove, or reorder callsigns, IDs, and names
- **Map** — centre latitude, longitude, and default zoom
- **Backgrounds** — tile layer name, URL template, and attribution
- **Aid Stations** — name, latitude, longitude
- **iGates** — name, latitude, longitude
- **Courses** — name and filename

Click **Save** to write changes to `config.yaml`. The daemon and browser pick up changes
automatically within ~5 seconds. Click **Load** to discard unsaved edits and reload from disk.

Latitude and longitude fields do not show spinner arrows. Values are validated client-side
before saving; out-of-range values (lat outside ±90°, lon outside ±180°) are reported before
the save request is sent.

### Event Management

Multiple independent event configurations can be stored and activated without editing `config.yaml` directly.

**Save As** — Click **Save As...** to save the current configuration as a new event. A dialog prompts for a new event name; names must be 1–80 characters and may contain letters, digits, spaces, underscores, hyphens, and periods. The new event directory is created at `events/<EventName>/` with an `event.yaml` file.

If the event name already exists, a confirmation dialog appears with a blinking warning: "Warning: You are about to overwrite the event file `<filename>` for `<event>`. This will permanently replace the existing configuration." Click **Cancel** to abort or **Overwrite** to proceed.

**Load** — Click **Load...** to see a list of all saved events, sorted by name. Clicking an event name loads that event's configuration and activates it (updates the `config.yaml` symlink and touches the target file to notify the daemon of the change).

When a tracker is deleted from the configuration and **Save** or **Overwrite** is clicked, the tracker's beacon history (if any exists) is automatically removed from `tracker_history.yaml`.

---

## API Endpoints

The web interface serves several JSON endpoints. All endpoints return `Cache-Control: no-store` to prevent browsers from serving stale cached data.

### `?json`

Returns the current state of all tracked callsigns: position, timestamp, and colour (green/blue/red based on age).

**ETag and 304 caching:** If the client sends an `If-None-Match` header with the previous ETag, and the file has not changed, the server responds with HTTP 304 Not Modified. This saves bandwidth on the 5-second polling interval.

Example response:
```json
{
  "KO6JDL-2": {
    "lat": 37.892345,
    "lon": -122.598456,
    "ts": 1778549300,
    "colour": "green"
  }
}
```

### `?config`

Returns the parsed `config.yaml` file: all configuration sections (trackers, map, backgrounds, courses, aid stations, iGates).

### `?history`

Returns beacon history for all tracked callsigns: up to 5 most-recent positions per tracker with timestamps.

**ETag and 304 caching:** Like `?json`, uses HTTP 304 Not Modified to avoid re-transmitting unchanged history.

Example response:
```json
{
  "KO6JDL-2": [
    {"lat": 37.892345, "lon": -122.598456, "ts": 1778549300},
    {"lat": 37.891234, "lon": -122.597345, "ts": 1778549000}
  ]
}
```

---

## Event Directory Structure and Beacon History

Each event configuration is stored in its own directory under `events/`:

```
events/
├── Critical Mass 2026/
│   ├── event.yaml              # event configuration (same format as config.yaml)
│   └── tracker_history.yaml    # auto-generated; contains beacon history
├── Dipsea 2026/
│   ├── event.yaml
│   └── tracker_history.yaml
└── Test Event/
    ├── event.yaml
    └── tracker_history.yaml
```

### `tracker_history.yaml`

Auto-generated by `aprsDaemon.php`. Stores the 5 most-recent beacon positions for each tracker, updated with every incoming APRS packet.

Format:
```yaml
# Auto-generated by aprsDaemon — do not edit
KO6JDL-2:
  - lat: 37.892345
    lon: -122.598456
    ts: 1778549300
  - lat: 37.891234
    lon: -122.597345
    ts: 1778549000
N6XN-7:
  - lat: 38.083833
    lon: -122.565167
    ts: 1778343432
```

History is not committed to git (see `.gitignore`). When an event is switched via the admin page, the daemon loads the new event's `tracker_history.yaml`. When a tracker is deleted and the configuration is saved, its history is automatically pruned from the file.

---

## APRS Packet Decoding

`aprsDaemon.php` decodes three position formats:

| Format | Description |
|--------|-------------|
| **Uncompressed** | `DDmm.mmN/DDDmm.mmW` — degrees and decimal minutes with N/S/E/W |
| **Compressed Base91** | 4-byte Base91 encoded latitude and longitude in the payload |
| **Mic-E** | Latitude encoded in the 6-character AX.25 destination field; longitude in payload bytes 1–3; E/W determined by destination byte 5 per the APRS spec |

Packets that do not contain a recognisable position are still used to update the
"last heard" timestamp for the callsign.

---

## File Permissions and Ownership Architecture

### User and Group Ownership

Both the daemon (`aprsDaemon.php`) and web server (PHP running in Apache/Nginx) run as the `www-data` user with group `www-data`. This eliminates permission conflicts: files created by the daemon are automatically writable by the web server, and vice versa.

To check file ownership:
```bash
ls -l /var/www/html/config.yaml /var/www/html/trackers.json
```

Both should show `www-data www-data`.

### Systemd Service Configuration

The daemon runs as a systemd service (see `aprs-daemon.service`):

```ini
[Service]
User=www-data
Group=www-data
UMask=0002
WorkingDirectory=/var/www/html
```

**UMask=0002** ensures that new directories are created with mode `0775` (rwxrwxr-x) and new files with mode `0664` (rw-rw-r--), allowing both the daemon and web server to read and write.

### File Permissions

| File | Required permission | Why |
|------|-------------------|-----|
| `trackers.json` | writable by www-data | Updated every ~5 s by `aprsDaemon.php` |
| `config.yaml` | writable by www-data | Saved by `admin/index.php` |
| `events/` directory | writable by www-data | Subdirectories created by `admin/index.php` |
| `events/<Event>/event.yaml` | writable by www-data | Saved by `admin/index.php` |
| `events/<Event>/tracker_history.yaml` | writable by www-data | Written by `aprsDaemon.php` |
| Course files (`*.gpx`, `*.json`, etc.) | readable by www-data | Served directly to browser |

### Automatic Repairs

If permission issues occur (e.g., files created with wrong ownership), repair with:
```bash
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R u+w,g+w /var/www/html/events/
```

---

## Utilities and Tools

### `pi-tools/start-aprs.desktop`

A desktop launcher file for the display Pi. Launches Chromium in normal mode (not full-screen kiosk mode) pointing to `http://localhost:8080/`.

**Installation on the display Pi:**
```bash
cp pi-tools/start-aprs.desktop ~/Desktop/
chmod +x ~/Desktop/start-aprs.desktop
```

Double-click the desktop icon to launch the map in normal mode. This allows window switching and browser controls while still displaying the APRS map.

### `backup-from-pi.sh`

A utility script to back up event data from the aprs-pi server to the development machine. Backs up the `events/` and `configs/` directories using `rsync`.

**Usage:**
```bash
bash backup-from-pi.sh
```

A timestamped backup directory is created at `/Users/doug/aprs-backups/YYYYMMDD-HHMMSS/` containing copies of `events/` and `configs/` from the server.

**Note:** This script is not synced to the Pi by `sync-to-pi.sh` (excluded via `.gitignore`). It is meant to run locally on the development machine only.

---

## Implementation Details

### Beacon History Storage and Retrieval

`aprsDaemon.php` maintains an in-memory array `$trackerHistory[callsign]` (max 5 entries per tracker) and writes the entire history to `tracker_history.yaml` each time a new beacon arrives. The YAML format is hand-parsed (regex-based, safe because the daemon controls the format):

- At startup, `readTrackerHistoryFile()` parses the file into memory.
- Each incoming APRS packet prepends a new entry and enforces the 5-entry limit.
- On config reload (when a new event is activated), `resolveHistoryPath()` and `readTrackerHistoryFile()` load the new event's history.

`index.php` serves history via the `?history` endpoint, which re-parses the file (with ETag caching) to ensure the browser always has the latest data.

### Blinking Synchronization

When a tracker is clicked, `triggerBlink(callsign)` applies the CSS class `blinking` to the main tracker icon. The same class is applied to history dots (L.circleMarker elements), the dotted line (L.polyline), and directional arrows (L.marker with SVG divIcon) as they are created. All elements share the same `@keyframes blink` animation (opacity oscillating over 5 seconds), ensuring visual synchronization.

### Admin Helper Functions

`admin/index.php` provides two PHP helper functions for consistent AJAX response handling:

- **`respondJson($data, $statusCode=200)`** — sets `Content-Type: application/json`, HTTP status code, outputs JSON, and exits.
- **`respondError($message, $statusCode=500)`** — sets `Content-Type: application/json`, outputs an error object with a `message` key, and exits.

These eliminate repetitive boilerplate across the 11 AJAX endpoints and ensure consistent error formatting.
