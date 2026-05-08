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

| Section | Behaviour |
|---------|-----------|
| **Trackers** | Listed by ID. Coloured dot and elapsed time update every 5 s. |
| **Courses** | Click a name to toggle the overlay on/off. Active courses show ✓. |
| **Aid Stations** | Click to zoom to location and show name label; click again to reset. |
| **iGates** | Same as aid stations. |
| **Backgrounds** | Click to switch the base tile layer. Active layer shows ✓. |
| **Reset Map** | Restores the default centre and zoom; clears all selections. |
| **Admin** | Opens the admin configuration page. |
| **Kiosk Mode** | Reloads in kiosk mode: full screen, partial sidebar (Trackers, Aid Stations, iGates), all visible courses displayed. Footer shows Reset Map and Exit buttons. |

### Tracker Clicks (three-click cycle)

1. **First click** — Blinks the tracker dot on the map and in the sidebar for 5 seconds.
2. **Second click** — Zooms the map to the tracker's last known position (zoom 15).
3. **Third click** — Resets the map to the default view.

Clicking a different tracker at any point restarts the cycle for that tracker.

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

Tracker markers are rendered in a dedicated Leaflet pane above aid station and iGate markers,
so tracker dots are always visible even when they overlap fixed-location markers.

### Kiosk Mode

Click **Kiosk Mode** in the sidebar, or append `?kiosk=1` to the URL. Intended for unattended displays:

- Browser enters full screen automatically.
- Sidebar shows Trackers, Aid Stations, and iGates only; Courses and Backgrounds are hidden.
- Courses that were visible in normal mode remain visible; hidden courses stay hidden.
- The first background tile layer is selected automatically.
- Tracker labels and dot blinking work exactly as in normal mode.
- Footer shows small **Reset Map** and **Exit** buttons. Reset Map restores the default view and clears all selections. Exit returns to normal mode.

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

## File Permissions

| File | Required permission | Why |
|------|-------------------|-----|
| `trackers.json` | writable by daemon process | Updated every ~5 s by `aprsDaemon.php` |
| `config.yaml` | writable by web process | Saved by `admin/index.php` |
| Course files (`*.gpx`, `*.json`, etc.) | readable by web process | Served directly to browser |
