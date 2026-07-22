# MARS APRS System

**Author:** Doug Kaye (K6DRK) · **Copyright:** 2026 Doug Kaye. All Rights Reserved.

**Version:** Server & Displays (v1.20.1); Mobile App (v1.20.1); iGates (v5.0)

---

## Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [NetBird VPN](#netbird-vpn)
4. [iGates (v5.0)](#igates-v50)
5. [APRS Server (v1.20.1)](#aprs-server-v1201)
   - [Cloudflare Tunnel](#cloudflare-tunnel)
6. [Display Pis (v1.20.1)](#display-pis-v1201)
7. [Mobile Apps (v1.20.1)](#mobile-apps-v1201)
   - [Architecture](#app-architecture) · [Location Sharing Flow](#location-sharing-flow) · [Smart Track](#smart-track) · [Building & Distributing](#building-distributing) · [Background Location](#background-location)
8. [User Interfaces](#user-interfaces)
9. [Authentication](#authentication)
10. [Analyzer](#analyzer)
   - [Architecture](#analyzer-architecture) · [Authentication](#analyzer-authentication) · [Beacon Recording](#beacon-recording) · [Map & Controls](#map--controls) · [Key Files](#analyzer-key-files) · [Services](#analyzer-services) · [API Endpoints](#analyzer-api-endpoints)
11. [Backup, Recovery and Updates](#backup-recovery-and-updates)
   - [Server Pi](#server-pi) · [Display Pis](#display-pis) · [iGates](#igates)
12. [Log Rotation](#log-rotation)
13. [Building & Deploying Devices](#building-deploying-devices)
    - [NetBird Setup Keys](#netbird-setup-keys) · [APRS Server](#aprs-server) · [Display Pis & iGates](#display-pis-igates)
14. [Creating New Master Images](#creating-new-master-images)
    - [APRS Server](#aprs-server_1) · [Display Pis](#display-pis_1) · [iGates](#igates_1)
15. [Supporting Systems](#supporting-systems)
    - [NetBird Status Monitor](#netbird-status-monitor) · [WiFi Manager](#wifi-manager)
16. [Appendix](#appendix)
    - [File Formats](#file-formats) · [Server](#server) · [Display Pi](#display-pi) · [iGate](#igate) · [Pi-Tools](#pi-tools)
17. [Testing](#testing)

---

## Overview

This document is intended for a technical audience who want to understand the inner workings of our APRS system. Users are encouraged to view the User Guide at [https://marsaprs.org/userguide.html](https://marsaprs.org/userguide.html?back=/readme.html).

The MARS APRS System provides real-time position tracking for MARS (Marin Amateur Radio
Society) public-service events. Operators carry APRS trackers that
transmit their GPS position over radio. The system receives those transmissions, publishes
them to a live web map, and gives net control a browser-based view of all tracker positions
updated every five seconds.

Three Raspberry Pi device types work together. **iGates** are devices that
receive APRS transmissions on 144.39 MHz and forward decoded packets to the APRS-IS internet
network. The **APRS Server** pulls packets from APRS-IS, maintains tracker state, and serves
the web map and all supporting tools. **Display Pis** are screens at net control and
other locations that show the live map in fullscreen. Anyone is free to connect to [https://marsaprs.org](https://marsaprs.org) to view the current default event. We expect this will be used by aid station, rest stop and other personnel as well as anyone interested in the events.

All devices are connected over a NetBird WireGuard VPN, which provides remote SSH access,
device health monitoring, and nightly configuration distribution — without requiring port
forwarding or static public IP addresses.

---

## System Architecture

```
APRS Radio (144.39 MHz)
      │
      ▼
┌─────────────────────┐
│  iGate  (×N)        │  Pi Zero 2 W · v5.0
│  RTL-SDR dongle     │
│  direwolf TNC       │
│  direwatch.py       │
└──────────┬──────────┘
           │ TCP 14580
           ▼
┌──────────────────────────────────────┐     ┌──────────────────────────────┐
│           APRS-IS Network            │◀────│  Mobile App  (iOS/Android)   │
│         noam.aprs2.net:14580         │     │  Flutter v1.20.1               │
└────────────────┬─────────────────────┘     │  TCP 14580 (inject position) │
                 │ TCP 14580                 └──────────────┬───────────────┘
┌────────────────▼─────────────────────┐                    │ HTTPS (map + config + session)
│       APRS Server  (aprs-pi)         │  Pi 4 · v1.20.1      │
│  aprsDaemon.php → trackers.json      │◀───────────────────┘
│  Apache + PHP · netbird/ · wifi/     │
│  marsaprs.org  (Cloudflare Tunnel)   │
└──┬───────────────────────────────────┘
   │ HTTPS via Cloudflare
┌──▼─────────────────────┐
│  Display Pi  (×2)      │  Pi 4 · v1.20.1
│  Chromium fullscreen   │
│  marsaprs.org          │
└────────────────────────┘

```

| Component | Runs on | Purpose |
|-----------|---------|---------|
| `direwolf` | iGate | AX.25 TNC; decodes RF packets; forwards to APRS-IS |
| `direwatch.py` | iGate | Drives TFT status display |
| `aprsDaemon.php` | Server | Pulls from APRS-IS; writes `trackers.json`, `igates.json`, `aidstations.json` |
| `index.php` | Server | Serves the live map; JSON polling endpoint |
| `admin/index.php` | Server | Admin UI; event and tracker management |
| `netbird-poller.py` | Server | Polls all Pi devices over VPN; writes `stats.json` |
| `wifi/` | Server | Master WiFi credential store; distributes to all Pis |
| Chromium | Display Pi | Fullscreen kiosk pointing at `marsaprs.org` |
| Flutter app | iOS / Android | Native map + location sharing client; injects APRS positions directly to APRS-IS |

---

## NetBird VPN

[NetBird](https://netbird.io) is a WireGuard-based mesh VPN. Every MARS Pi device — iGates
at remote locations, the server, and display Pis — is enrolled in the same NetBird network
and gets a stable private IP address (100.x.x.x range) that never changes regardless of
which WiFi network the device is on.

**Why NetBird:**

By using NetBird addresses for all our devices, they can communicate with one another without regard to how they're connected to the internet. NetBird can find them wherever they are. It also allows us to reach them from a laptop or other NetBird-capable device for maintenance, diagnostics, etc. We specifically use NetBird addressing for gathering statistics and operational data from devices in the field.

- **Remote SSH** — connect to any Pi from anywhere with no port forwarding or public IP
- **Device monitoring** — the NetBird status page polls every device on its VPN IP
- **iGate enable/disable** — each iGate queries `marsaprs.org/netbird/?hostname=<call>`
  every 5 minutes to check whether NetBird should be enabled or not
- **WiFi credential distribution** — `auto-update.sh` downloads `wifi.yaml` from the server
  over the VPN each night and applies it via `nmcli`
  
**The Problem with NetBird:**
In order for NetBird to keep track of devices, those devices must frequently announce themselves to the NetBird servers. Unfortunately, this generates a great deal of internet traffic -- more than the traffic generated by our devices themselves. In some permanent iGate locations we use cellular hotspots to connect to the internet. Our hotspot provider gives us 1GB/month free of charge. The problem is that the NetBird traffic can easily surpass that limit.

We have therefore implemented a system that allows us to turn NetBird on and off remotely. Each device contacts the marsaprs.org server every five minutes to see if should turn its NetBird service on or off. This is controlled by the WiFi admin page on our server. A user with the admin password can use this admin page to enable or disable NetBird on any remote device. The device will act on any changes at its next five-minute interval. Note that we generally keep NetBird off for any permanently located iGate that uses a cellular hotspot. This isn't necessary for temporary or "guerrilla" iGates since they're usually on for less than 24 hours per event.

---

## iGates (v5.0)

An iGate receives APRS radio packets and forwards them to the APRS-IS network. Each iGate
is a Raspberry Pi Zero 2 W with a USB RTL-SDR dongle listening on 144.39 MHz. An optional
1.3-inch TFT display shows boot status and countdown timers for fault conditions.

**Architecture:**

```
144.39 MHz RF
      │
      ▼
RTL-SDR USB dongle
      │
      ▼
direwolf  (TNC + iGate daemon)
      │
      ├──▶ APRS-IS  (noam.aprs2.net:14580)
      │
      └──▶ direwatch.service  (display manager)
                 │
                 ├──▶ TFT display (status screens)
                 └──▶ UDP port 1235 → server (health monitoring)

igate-watchdog.sh  (cron, every minute)
      ├── SDR presence check → dw-nosdr.py (every 2 min, then 2-min countdown + reboot)
      └── Internet check (every 5 min) → dw-nointernet.py (2-min countdown + reboot)
```

**Watchdog:**

`igate-watchdog.sh` runs every minute via cron and handles three health checks:

- **SDR** — runs `lsusb` looking for RTL-SDR USB IDs (`0bda:2838`, `0bda:2832`, `RTL28`). If the SDR is absent, logs the event and (if a TFT display is attached) launches `dw-nosdr.py`, which shows a 2-minute countdown and reboots. If the SDR reappears while direwolf is down, restarts direwolf immediately.
- **IP address** — logs a warning if the device has no IP address.
- **Internet** — every 5 minutes, if NetBird is connected, pings `8.8.8.8`. On failure, launches `dw-nointernet.py` (same 2-minute countdown + reboot pattern).

The watchdog suppresses all checks while `dw-startup.py` is running (boot sequence) or while a reboot is already pending (`/tmp/aprs-rebooting`). TFT presence is detected by reading GPIO 23: `pinctrl get 23 | grep -q hi`.

Log: `/var/log/direwolf/watchdog.log`

**Key files on the iGate Pi:**

| File | Location | Purpose |
|------|----------|---------|
| `direwolf.conf` | `/home/pi/` | TNC frequency, callsign, APRS-IS login, filter |
| `configure.sh` | `/home/pi/` | Interactive configuration wizard |
| `auto-update.sh` | `/home/pi/` | Nightly script + WiFi credential update |
| `igate-watchdog.sh` | `/home/pi/` | Cron watchdog (SDR + internet checks) |
| `direwatch.py` | `/home/pi/direwatch/` | APRS-IS connection + TFT display manager |
| `dw-startup.py` | `/home/pi/direwatch/` | Boot sequence display (stats + SDR check) |
| `dw-nosdr.py` | `/home/pi/direwatch/` | "No SDR found" countdown display |
| `dw-nointernet.py` | `/home/pi/direwatch/` | "No internet" countdown display |
| `StatsRequestListener.php` | `/home/pi/` | UDP responder for NetBird monitor |

**SSH:** `ssh pi@<ip>` · Password: `guacamole`

---

## APRS Server (v1.20.1)

The server is a Raspberry Pi 4 running Apache and PHP. It receives APRS packets from
APRS-IS, maintains live tracker state, serves the web map and admin tools, and hosts the
NetBird monitor and WiFi credential manager. It is reachable at `marsaprs.org` via a
Cloudflare Tunnel — no inbound port forwarding or static IP required.

Note that anyone can always view the then-current event at https://marsaprs.org. No pasword is required.

**Architecture:**

```
APRS-IS (noam.aprs2.net:14580)
      │ TCP
      ▼
aprsDaemon.php  ──writes──▶  trackers.json
      │                            │
      └── reads ──▶ config.yaml ◀──┘
            │  (symlink → events/<Event>/event.yaml)
            ▼
       index.php  ◀── browser polls every 5 s
            │
       admin/index.php  (event + tracker management)

netbird/daemon.php  ──UDP──▶  all Pi devices (port 1235)
       └──writes──▶  netbird/stats.json

wifi/index.php  (credential editor)
       └──▶  wifi.yaml  (downloaded nightly by all Pis)
```

**Key files:**

| File | Role |
|------|------|
| `aprsDaemon.php` | Background daemon; APRS-IS connection; writes `trackers.json`, `igates.json`, `aidstations.json` |
| `config.yaml` | Symlink → active event's `event.yaml` |
| `trackers.json` | Live tracker state (daemon writes, browser reads) |
| `igates.json` | iGate last-beacon timestamps (daemon writes, browser reads) |
| `aidstations.json` | Aid station last-beacon timestamps (daemon writes, browser reads) |
| `index.php` | Map page + JSON/config/history endpoints |
| `admin/index.php` | Admin UI; all admin API endpoints |
| `events/<Name>/event.yaml` | Per-event configuration |
| `mobile_trackers.json` | Active mobile participant sessions (token, callsign, last update, `pending_msgs` queue, `aprs_lat`/`aprs_lon`/`aprs_ts` for APRS-IS dedup) |
| `events/<E>/messages.json` | Persistent message log (both directions); appended by `?messaging=send` and `?mobile=message` |
| `/run/aprs/web_sessions.json` | Active web operator tokens (RAM disk; cleared on reboot) |

Each event's configuration lives in `events/<EventName>/event.yaml`. `config.yaml` is a
symlink to the active event's file.

### Cloudflare Tunnel

All traffic to `marsaprs.org` is routed through a Cloudflare Tunnel. The Pi establishes
an outbound HTTPS connection to Cloudflare's edge — no inbound firewall rules needed, and
the Pi works on any network including cellular.

```
Browser → Cloudflare edge (marsaprs.org)
                  │ tunnel
          cloudflared (aprs-pi) → Apache :80
```

Tunnel ID: `b11404f2-7497-4822-92b4-f75db418a1fe`

Config at `/etc/cloudflared/config.yml` (repo: `server/cloudflared/config.yml`):

```yaml
ingress:
  - hostname: marsaprs.org
    service: http://localhost:80
  - service: http_status:404
```

To reinstall with a new token:

```bash
sudo cloudflared service uninstall
sudo cloudflared service install <new-token>
sudo systemctl start cloudflared
```

The tunnel token is obtained from the **Cloudflare Zero Trust dashboard**:
*Networks → Tunnels → \<tunnel\> → Configure → Install and run connector*

---

## Display Pis (v1.20.1)

A display Pi is a Raspberry Pi 4 running Chromium in fullscreen mode, pointed at
`marsaprs.org`. It is a read-only display device — no long-term local configuration or data storage.
Two display Pis are in use: **NetControl** (operator screen) and **BigTV** (large audience
screen).

**Architecture:**

```
marsaprs.org  (via WiFi + Cloudflare)
      │ HTTPS
      ▼
Chromium  (fullscreen kiosk)
      │ localhost:8080
      ├──▶ kill-server.py   (handles /exit from kiosk mode)
      │
aprs-monitor.sh   (polls marsaprs.org every 30 s;
      │             relaunches Chromium via localhost:8080
      │             if server is unreachable)
wifi-watchdog.sh  (checks WiFi every 30 s;
                   calls wifi-restored.sh on reconnect)
```

**Autostart:** Chromium launches at boot via `~/start-kiosk.sh`, called from the LXDE
autostart file (`~/.config/lxsession/rpd-x/autostart`). The wrapper reads `~/autologin.txt`,
sets cursor size via `XCURSOR_SIZE=48`, and passes `--user-data-dir=/tmp/chromium` to keep
Chromium cache/state in RAM (tmpfs).

**Operator auto-login:** If `~/autologin.txt` exists, `start-kiosk.sh` appends `?autologin`
to the URL; if line 1 contains an operator name, it also appends `&operator=<name>` for
automatic messaging subscription. The server handles `?autologin` by setting a PHP session
for the current event and redirecting to the clean URL, with messaging credentials embedded
as JS globals for auto-subscribe.

**Cursor size:** 48 px via `XCURSOR_SIZE=48` in `start-kiosk.sh` (applied before Chromium),
reinforced by `~/.Xresources` (`Xcursor.size: 48`) loaded via `@xrdb` in the LXDE autostart.

**SD card I/O:** Chromium profile to `/tmp/chromium` (tmpfs); journald `Storage=volatile`
keeps journal entries in RAM. Both configured via `files.tar.gz` and `install.sh`.

**SSH:** `ssh pi@<ip>` · Password: `guacamole` · VNC: `vnc://<ip>:5901`

For details on using the map, see [USERGUIDE.MD](https://marsaprs.org/userguide.html?back=/readme.html).

---

## Mobile Apps (v1.20.1)

Native iOS and Android apps are available as an alternative to the web map. The apps provide the same live tracker display as the web map, and support background location sharing — GPS position continues to be reported even when the screen is locked or the app is not in the foreground.

**Repository:** `/Users/doug/aprs-map` (separate from this marsaprs repo)

### App Architecture

The app is a Flutter application with a hybrid architecture:

```
┌────────────────────────────────────────────────┐
│                 Flutter App                    │
│                                                │
│  ┌──────────────────────────────────────────┐  │
│  │  WebView  →  marsaprs.org/index.php      │  │  reads tracker data, config, courses
│  └──────────────────────────────────────────┘  │
│                                                │
│  ┌───────────────────┐  ┌────────────────────┐ │
│  │  Native Drawer    │  │  BackgroundLocation │ │
│  │  menu_drawer.dart │  │  Service           │ │
│  │  tracker_layer    │  │  background_       │ │
│  │  .dart            │  │  location.dart     │ │
│  └───────────────────┘  └────────┬───────────┘ │
└────────────────────────────────  │  ───────────┘
                                   │
              ┌────────────────────┼──────────────────────┐
              │ TCP 14580          │ HTTPS                 │
              ▼                    ▼                       │
    APRS-IS (noam)        marsaprs.org                    │
    inject position       ?mobile=join/update/leave       │
    packet                heartbeat + session mgmt        │
```

| File | Purpose |
|------|---------|
| `lib/map_screen.dart` | Root widget; hosts the WebView, JS bridge, native tracker/breadcrumb overlay |
| `lib/help_screen.dart` | Built-in Quick Start guide; displayed on first launch; accessible via Help → Quick Start in the drawer footer |
| `lib/menu_drawer.dart` | Native Flutter sidebar drawer; Help footer button shows modal (app info + Quick Start/User Guide/ticket buttons) |
| `lib/tracker_layer.dart` | Native map marker overlay; squares for mobile, circles for fixed; ID label next to each dot |
| `lib/arrow_painter.dart` | CustomPainter for breadcrumb directional arrows; isolated to avoid `Path<LatLng>` collision |
| `lib/course_layer.dart` | Course polyline overlay; fetches all courses in parallel |
| `lib/background_location.dart` | GPS stream; `_heartbeatTimer` (timed uploads) + `_maybeUploadFromStream()` (distance-triggered); calls `MobileSession` |
| `lib/background_task_handler.dart` | Android foreground service stub; no-op handler; keeps process alive |
| `lib/mobile_session.dart` | HTTP client for `?mobile=join/update/leave` API |
| `lib/aprs_client.dart` | Legacy — TCP socket to APRS-IS; no longer used (server-side injection replaced direct TCP) |
| `lib/remote_config.dart` | Polls `?config` endpoint; parses event configuration |
| `lib/map_config.dart` | Constants: server URL |
| `android/app/src/main/AndroidManifest.xml` | Android permissions |
| `ios/Runner/Info.plist` | iOS background mode declaration |

### Location Sharing Flow

Both iOS and Android use the same upload path: position data is POSTed to the MARS server, which injects the APRS packet via its own persistent TCP connection to APRS-IS.

```
User taps Share Location
  → _ensureBackgroundPermissions()     (Android: notification + battery opt;
                                        iOS: verify Always location permission)
  → MobileSession.join()               POST ?mobile=join {name, pin, sharing_mode: "unknown"}
      ← {token, callsign, passcode}    e.g. callsign=K6DRK-01
  → BackgroundLocationService.startTracking()
      → Geolocator.getPositionStream() (single stream; AppleSettings on iOS with
                                        allowBackgroundLocationUpdates: true;
                                        AndroidSettings on Android)
  → Android only: FlutterForegroundTask.startService() — persistent notification;
                  background_task_handler.dart is a no-op stub
  → Upload triggered by _heartbeatTimer (timed) OR GPS event (distance ≥ threshold):
      Both platforms:
        _heartbeatTimer fires at configured interval
        _maybeUploadFromStream() on every GPS event (uploads if moved ≥ threshold
            OR configured interval elapsed)
        → MobileSession.update(lat, lon)    POST ?mobile=update {token, lat, lon}
              ← 200 ok  (or 404 → stops sharing)
              → server injectAprsPacket() via local TCP 14580
                    → "K6DRK-01>APRS,TCPIP*:!3751.72N/12232.64W>Mobile/Alice\r\n"
  → aproDaemon receives packet from APRS-IS
      → distance filter: skip if new position < 100 ft from last breadcrumb
      → writes K6DRK-01 to trackers.json with mobile=true
  → WebView polls ?json every 5 s → map updates

App restart while sharing was active:
  → resumeSharing() reads SharedPreferences (token, callsign, interval, activity mode)
  → Attempts token reuse via MobileSession.update(); if stale, re-joins silently
  → Sharing resumes with same callsign and activity mode; snackbar notifies user
```

### Smart Track

Smart Track is the automatic beacon-interval algorithm in the native app (iOS/Android), Apple Watch app, and web map. It monitors GPS speed and adjusts the upload frequency without any input from the user.

**Unknown (?) mode — startup phase:**

Every session begins in **unknown** mode (shown as **?** in the web sidebar). The app joins the server with `sharing_mode: "unknown"` and stays in this phase until Smart Track collects enough GPS readings to make a confident initial determination — typically within 90 seconds. When the mode is first set, the app waits 15 seconds before sending the first real-mode beacon so the sidebar briefly shows **?** before the actual activity icon appears.

**Speed thresholds:**

| Mode | Speed | Interval |
|------|-------|----------|
| Stationary | ≤ 1.0 m/s (2.2 mph) | 120 s |
| Walk / Run | ≤ 4.5 m/s (10 mph) | 60 s |
| Cycle | ≤ 11.0 m/s (25 mph) | 30 s |
| Drive | > 11.0 m/s | 15 s |

**Debounce — preventing false mode changes:**

A mode switch only occurs after a run of consecutive GPS samples all agree on the new mode. The required run length:
- Walk/Run/Cycle/Drive: 15 consecutive samples
- Stationary: 20 consecutive samples, plus at least 5 minutes of wall-clock time since last movement

A timer also fires every 30 seconds to catch the case where the device stops moving but GPS events stall. If no movement has been detected for 5 minutes (or 90 seconds during startup) the mode switches directly to stationary without waiting for GPS events.

**Startup fast-window:**

During the first 10 GPS readings of a new session, only 3 consecutive matching samples are needed to set the initial mode (instead of 15–20). This lets Smart Track make a confident initial guess within 30–60 seconds of starting rather than waiting several minutes. Once those 10 readings have been processed, the full debounce requirements apply.

**GPS noise defense:**

Two mechanisms prevent GPS jitter from causing false mode transitions:

1. **Accuracy gate:** Any GPS reading with horizontal accuracy worse than 20 m is excluded when updating the last-movement timestamp. A brief poor-fix cannot reset the stationary timer.
2. **Movement confirmation guard:** Three consecutive readings must all exceed the stationary threshold (> 1.0 m/s with accuracy ≤ 20 m) before the last-movement timestamp is updated. A single speed spike from GPS noise cannot prevent the stationary timer from eventually firing.

**Implementation:**

The algorithm runs identically in `lib/map_screen.dart` (Flutter iOS/Android), `Sources/BeaconService.swift` (Apple Watch), and `index.php` (web JS). Key functions: `_processSpeedSample` / `processSpeedSample` / `_autoDetectFromSpeed` for sample-based detection; `_checkStationaryByTime` / `checkStationaryByTime` for the timer-based fallback.

### Building & Distributing

#### iOS

```bash
cd /Users/doug/aprs-map
flutter pub get
flutter build ios --release --no-codesign
```

Open `ios/Runner.xcworkspace` in Xcode. To distribute via TestFlight:

1. Bump `version` in `pubspec.yaml` (e.g. `1.14.0+2` — the build number after `+` must increase with each upload).
2. `flutter build ios --release --no-codesign`
3. Xcode → Product → Archive → Distribute App → TestFlight (internal).
4. Wait for the build status in App Store Connect to leave **Processing** before testers can install it. This typically takes 5–15 minutes.

Testers must have TestFlight installed (free, from the App Store). They install the app by opening the invitation email and tapping **View in TestFlight**. New testers may be prompted for a redeem code from the invitation. For updates, testers open TestFlight and tap **Update**.

#### Android

```bash
cd /Users/doug/aprs-map
flutter pub get
flutter build apk --release
```

The signed APK is at `build/app/outputs/flutter-apk/app-release.apk`. This is a
**universal** APK (arm64-v8a, armeabi-v7a, x86_64 in one file) — do not use
`--split-per-abi` for direct download, since the recipient would have to know their
device's architecture and the wrong pick fails to install.

**Publishing it** — copy to the Pi under a versioned name; the download page picks up
the newest automatically:

```bash
cp build/app/outputs/flutter-apk/app-release.apk ~/Downloads/aprs-map-<version>-<build>.apk
rsync -avz ~/Downloads/aprs-map-<version>-<build>.apk pi@192.168.0.180:/var/www/html/android/
```

| URL | Purpose |
|---|---|
| `https://marsaprs.org/android/` | Landing page — version, size, SHA-256, install steps |
| `https://marsaprs.org/android/download.php` | **Permanent** download link; 302s to the newest APK |

`map/android/` holds `index.php`, `download.php` and `_apk.php`; the APKs themselves are
gitignored and live only on the Pi. The filename must match
`aprs-map-<major>.<minor>.<patch>-<build>.apk` or it is ignored, and the highest build
number wins. The stable URL redirects rather than being a fixed filename that gets
overwritten, so Cloudflare and browser caches can't pin an old release to it — and old
versions stay downloadable at their own paths.

**Release signing** requires `android/key.properties` (gitignored):

```
storePassword=<password>
keyPassword=<password>
keyAlias=<alias>
storeFile=<absolute path to .jks or .p12>
```

`android/app/build.gradle.kts` reads this file automatically. If absent, the release build falls back to debug signing.

**`compileSdk` note:** `objectbox_flutter_libs` in `~/.pub-cache` may hardcode `compileSdkVersion 31`. Patch it to `36` to match `build.gradle.kts`.

To distribute: share the APK via Google Drive or email. Testers tap the download link, open the file with **Package Installer**, and if Android warns about an unknown source, tap **More details** → **Install anyway**.

#### Submitting to Google Play Store

**One-time setup:**

1. Create a Google Play Developer account at [play.google.com/console](https://play.google.com/console) ($25 one-time fee).
2. Generate a signing keystore (only done once — never change it after first publish):
   ```bash
   keytool -genkey -v -keystore ~/aprs-map-release.jks \
     -alias aprs-map -keyalg RSA -keysize 2048 -validity 10000 \
     -dname "CN=Doug Kaye, OU=MARS, O=W6SG, L=Marin, ST=CA, C=US"
   ```
3. Create `android/key.properties` (gitignored — back up the passwords and `.jks` file securely):
   ```
   storePassword=<store-password>
   keyPassword=<key-password>
   keyAlias=aprs-map
   storeFile=/Users/doug/aprs-map-release.jks
   ```

**Each release:**

1. Bump `version` in `pubspec.yaml` (e.g. `1.20.0+9` → `1.20.1+10` — the build number after `+` must increase with each upload).
2. Build a signed App Bundle (AAB):
   ```bash
   flutter build appbundle --release
   ```
   Output: `build/app/outputs/bundle/release/app-release.aab`
3. In Play Console → **Your app** → **Testing → Internal testing** → **Create new release** → upload the `.aab`.
4. After internal testing, promote the release through **Closed testing → Open testing → Production** using the **Promote release** button.
5. Google typically reviews new production releases within 1–3 days.

**Store listing assets required (one-time, update as needed):**
- Short description (80 chars), full description (4000 chars)
- At least 2 phone screenshots (minimum 320px on shortest side)
- Feature graphic: 1024 × 500 px PNG or JPG
- App icon: 512 × 512 px PNG (must match the launcher icon)
- Content rating questionnaire (set category to **Utilities** or **Tools**)

#### Submitting to Apple App Store Connect

**One-time setup:**

1. Enroll in the **Apple Developer Program** at [developer.apple.com](https://developer.apple.com) ($99/year).
2. In App Store Connect ([appstoreconnect.apple.com](https://appstoreconnect.apple.com)) → **Apps** → **+** → **New App**:
   - Platform: iOS
   - Bundle ID: `org.w6sg.aprsmap` (must match `ios/Runner.xcodeproj`)
   - SKU: `aprsmap` (any unique string)
3. In Xcode → **Signing & Capabilities**: set Team to your Apple Developer account; let Xcode manage provisioning profiles automatically.

**Each release:**

1. Bump `version` in `pubspec.yaml` (build number after `+` must increase with each upload).
2. Build the iOS app:
   ```bash
   flutter build ios --release --no-codesign
   ```
3. Open `ios/Runner.xcworkspace` in Xcode.
4. **Product → Archive**. When complete, the Organizer opens automatically.
5. Click **Distribute App → App Store Connect → Upload**.
6. In App Store Connect → **Your app → TestFlight**: the build appears within minutes; full App Review takes 5–30 minutes before testers can install it.
7. To submit for **App Store production review**:
   - Go to **Your app → App Store → + Version**
   - Fill in "What's New", select the build, submit for review.
   - Apple review typically takes 1–3 days.

**Store listing assets required (one-time, update as needed):**
- App description, keywords, support URL, marketing URL
- Screenshots for iPhone 6.9" display (required) and iPad 13" (required if supporting iPad)
- App icon: 1024 × 1024 px PNG (no alpha channel)
- Privacy policy URL (required for apps that collect location data)

### Background Location

Background location is implemented differently on each platform because of how each OS handles suspended processes.

#### Android

Two foreground services run while sharing: `GeolocatorLocationService` (from the `geolocator` package) keeps the GPS stream and Dart isolate alive; `FlutterForegroundTask` (from `flutter_foreground_task`) provides a persistent notification and prevents Android from killing the process. The `FlutterForegroundTask` handler (`background_task_handler.dart`) is a no-op stub — all beaconing is done by the main isolate via `_heartbeatTimer` and `_maybeUploadFromStream()`. A persistent notification is required — without it Android 13+ treats the service as having no notification and kills it when the screen locks.

**Required permissions in `AndroidManifest.xml`:**

| Permission | Why |
|-----------|-----|
| `INTERNET` | Network access — not injected automatically in release builds |
| `ACCESS_FINE_LOCATION` | GPS |
| `ACCESS_BACKGROUND_LOCATION` | Location while screen is locked (Android 10+) |
| `FOREGROUND_SERVICE` | Run a foreground service |
| `FOREGROUND_SERVICE_LOCATION` | Foreground service of type `location` (Android 14+) |
| `WAKE_LOCK` | Keep CPU active between GPS fixes |
| `POST_NOTIFICATIONS` | Show the foreground service notification (Android 13+); without this the service is killed on screen lock |
| `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` | Prompt the user to exempt the app from battery killing |

At runtime, `_ensureBackgroundPermissions()` in `map_screen.dart` requests `POST_NOTIFICATIONS` and `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` before starting a sharing session.

Samsung One UI is particularly aggressive — users must also set the app to **Unrestricted** battery mode: Settings → Apps → APRS Map → Battery → Unrestricted.

#### iOS

`Info.plist` declares `UIBackgroundModes: location`. The `CLLocationManager` flag `allowsBackgroundLocationUpdates` is set to `true` via `AppleSettings(allowBackgroundLocationUpdates: true)` in the geolocator stream configuration. "Always" location permission is required — "While Using" is not sufficient. When active, a white location arrow appears in the iOS status bar.

**Single GPS stream — critical constraint:** `geolocator_apple` only supports one active event-channel listener at a time. If a second `Geolocator.getPositionStream()` call is made while one is already active, `PositionStreamHandler.onListenWithArguments` returns an error and the second call silently fails — `allowsBackgroundLocationUpdates` is never set to `true` on the underlying `CLLocationManager`, and the background location indicator never appears. The fix: `startTracking()` in `BackgroundLocationService` owns the single Geolocator stream (with `AppleSettings`), and the map's blue-dot layer subscribes to `_bgLocation.positionStream` (a Dart broadcast stream fed by that same stream) rather than opening its own.

**Upload mechanism:** Both iOS and Android use the same `_heartbeatTimer` for timed uploads and `_maybeUploadFromStream()` for distance-triggered uploads. On iOS, the GPS event stream keeps the Dart isolate continuously alive, so `Timer.periodic` fires reliably. `_maybeUploadFromStream()` provides an additional trigger: it uploads immediately when the device has moved ≥ the configured distance threshold since the last upload, resetting the timer to avoid a duplicate beacon shortly after.

**Why both platforms use server-side APRS injection:** Both iOS and Android POST position data to `?mobile=update` rather than sending raw TCP packets directly to APRS-IS. On iOS, `NSURLSession`-based HTTP is explicitly supported for background network tasks while raw `dart:io Socket` TCP connections are not reliable in background. Unifying Android to the same path keeps all beaconing logic in the main isolate, makes `background_task_handler.dart` a no-op, and simplifies the overall architecture.

---

## User Interfaces

Six browser-based interfaces run on `marsaprs.org`. All are served by Apache on the server
Pi, accessible at `https://marsaprs.org/<path>`.

| Interface | URL | Access | Purpose |
|-----------|-----|--------|---------|
| **Map** | `/` | Public | Live tracker positions on an interactive Leaflet map |
| **Map Admin** | `/admin/` | User account | Event configuration, tracker list, course and aid station management |
| **Analyzer** | `/analyzer/` | User account | Beacon recording, playback, and analysis for the current event |
| **NetBird Monitor** | `/netbird/` | User account | Real-time health status of all Pi devices |
| **NetBird Admin** | `/netbird/admin.php` | User account | Add/remove devices, enable/disable, SSH terminal |
| **WiFi Manager** | `/wifi/` | User account | Edit the shared WiFi credential list distributed to all Pis |
| **Tickets** | `/tickets/admin.php` | User account | Bug report and suggestion ticket management |

**Map** — Shows tracker positions updated every 5 seconds. Sidebar lists trackers (with
elapsed time and breadcrumb history), courses, aid stations, iGates, and map backgrounds.
Hovering a tracker or breadcrumb dot shows its APRS path (iGates/digipeaters the packet
traveled). Breadcrumbs are filtered: consecutive duplicate positions and positions within
100 feet of the previous breadcrumb are suppressed. The breadcrumb trail shows up to 10
positions as dots on a dashed line with directional arrows; the trail updates automatically
as the selected tracker moves. Aid station and iGate tooltips include an optional callsign.
A scale bar in the lower-right corner toggles between miles/feet and kilometers/meters when
clicked. Kiosk mode removes controls for unattended display use.

**Map Admin** — Requires `admin.view` or `admin.edit`. Manages all event configuration: tracker callsigns and
IDs, GPX/KML/GeoJSON course overlays, aid station and iGate locations (with optional APRS
callsign), map default view, and background tile layers. Supports multiple named events;
switching events is instant. Users with only `admin.view` see all data read-only.
See [Appendix — Server — Admin Interface](#admin-interface) for full details.

**Analyzer** — Requires `analyzer.view`. A Flask/gunicorn web app served at
`/analyzer/` via Apache mod_proxy. Runs `analyzer-daemon` to record APRS beacons from
APRS-IS into a local SQLite database for the current event. Displays recorded beacons on
an interactive Leaflet map with controls for filtering by tracker and iGate, toggling radio
vs. cellular beacons and course overlays, adjusting the auto-refresh rate (30 s–5 min per
client), and scrubbing a time-range slider. Daemon start/stop and data erasure require `analyzer.admin`.

**NetBird Monitor** — Requires `netbird.view`. Polls all registered Pi devices every 60 seconds (default; adjustable) over
the NetBird VPN and shows online/offline/enabling/disabled status. Includes an SSH terminal
for online devices. Poll/refresh sliders and the Admin button are shown only to users with `netbird.admin`.
See [Supporting Systems — NetBird Status Monitor](#netbird-status-monitor) for full details.

**NetBird Admin** — Requires `netbird.admin`. Manages the device list in `addresses.yaml`: add, edit, delete, enable,
and disable devices. Access to the SSH terminal for any online device.

**WiFi Manager** — Requires `wifi.admin` to edit; accessible read-only to users with `netbird.view`.
Edits `wifi.yaml`, the master list of WiFi networks distributed to all Pis nightly. Auto-saves on every change; no manual save step.

**Tickets** — Requires `tickets.manage`. View and manage bug reports and suggestions submitted via the in-app ticket form.

---

## Authentication

All protected browser interfaces use a shared named-account system. A single sign-in at
`/auth/login.php` establishes a session that is recognized by every tool — Admin, Analyzer,
NetBird, WiFi, and Tickets — without re-entering credentials.

### User Accounts

Accounts are stored in an SQLite database at `/var/lib/marsaprs/users.db` (outside the
web root, owned by `www-data`). Each account holds a username, display name, and bcrypt
password hash, plus an active flag. Inactive accounts cannot log in.

### Session Cookie

After a successful login the server inserts a row into the `sessions` table with a
64-character hex token and sends it to the browser as the `marsaprs_session` cookie
(`httponly`, `secure`, `SameSite=Lax`, 24-hour TTL). Both PHP pages and the Python
Flask analyzer validate the same cookie against the same database.

### Permissions

Access is controlled by flat permission strings. Each user is granted exactly the
permissions they need; there are no implicit roles or inheritance.

| Permission | Guards |
|---|---|
| `admin.view` | `/admin/` — read-only view of all event config, trackers, aid stations, iGates |
| `admin.edit` | `/admin/` — full edit and save |
| `admin.set_default` | **Save as Default Event** action |
| `admin.delete_event` | **Delete** event action |
| `analyzer.view` | `/analyzer/` — event map and beacon data |
| `analyzer.admin` | Daemon start/stop; Erase All Data |
| `netbird.view` | `/netbird/` — status page; read-only WiFi page |
| `netbird.admin` | `/netbird/admin.php`; poll/refresh sliders; full WiFi edit |
| `wifi.admin` | `/wifi/` — edit WiFi credentials |
| `tickets.manage` | `/tickets/admin.php` — ticket list and management |
| `messages.delete_all` | **Delete All Messages** button in the map's All Messages window |
| `users.manage` | `/auth/users.php` — create/edit/delete accounts and permissions |

The authoritative list is `KNOWN_PERMISSIONS` in `server/www/auth/users.php`; a permission
missing from it cannot be granted in the UI. Pages outside the auth tree guard themselves with
`require_once __DIR__.'/auth/auth.php'` then `has_permission(...)` — `map/index.php` does this
lazily (`msgHasAuthPermission()`) so the public map never opens `users.db` for anonymous
visitors.

### Login Flow

Every protected page passes unauthenticated requests through
`require_permission($perm)`, which redirects to `/auth/login.php?next=<original-url>`.
After a successful login the browser is sent back to the originally requested page.
Logging out (`/auth/logout.php`) deletes the session row and clears the cookie.

Users with `admin.view` but not `admin.edit` see the Admin page in read-only mode:
all data is displayed but edit controls, save buttons, and import/export actions are
hidden. The same permission-aware rendering applies to the NetBird status page (sliders
and Admin button hidden for `netbird.view`-only users) and the WiFi page (edit controls
and drag-to-reorder hidden).

### User Management

Users with `users.manage` access `/auth/users.php` (linked from the Admin page header
as **Users**). From there they can create accounts, reset passwords, toggle active status,
and assign or revoke individual permissions.

### Key Files

| File | Location | Purpose |
|---|---|---|
| `auth.php` | `/var/www/html/auth/` | Shared PHP library: `current_user()`, `has_permission()`, `require_permission()`, `create_session()`, `destroy_session()` |
| `login.php` | `/var/www/html/auth/` | Login form (GET) and credential validator (POST) |
| `logout.php` | `/var/www/html/auth/` | Destroys session, redirects to login |
| `users.php` | `/var/www/html/auth/` | User management UI (requires `users.manage`) |
| `init_db.php` | `/var/www/html/auth/` | Idempotent DB bootstrap; creates first admin account when no users exist |
| `auth_db.py` | `/home/pi/analyzer/src/` | Python mirror of `auth.php`; validates `marsaprs_session` cookie in Flask |
| `users.db` | `/var/lib/marsaprs/` | SQLite database: `users`, `permissions`, `sessions` tables |

### Event Lock Password

The event-level **lock** feature (🔒 in the Admin page) still uses `admin/password.txt`
as a separate credential. This is distinct from user-account authentication — it controls
whether a specific event's YAML can be overwritten, not who can log in. All other
admin actions are gated by the user-account permission system.

---

## Messaging System

The messaging system allows web operators to exchange text messages with active mobile tracker participants in real time. It is enabled by setting `messaging_password` in the event's `event.yaml` (via the Admin page).

### Data Storage

| File | Location | Purpose |
|------|----------|---------|
| `messages.json` | `events/<EventName>/` | Persistent log of all messages (both directions) |
| `messages.json.counter` | `events/<EventName>/` | Message-ID high-water mark; survives **Delete All Messages** |
| `web_sessions.json` | `/run/aprs/` (RAM disk) | Active web operator sessions; cleared on server reboot |
| `pending_msgs` | field in `mobile_trackers.json` | Queue of undelivered messages for each mobile participant |

**`messages.json` entry format:**
```json
{
  "id": 5,
  "ts": 1750000000,
  "from": "MARSQ-83",
  "from_label": "James",
  "to": "web",
  "to_label": "web",
  "text": "Arrived at Aid 3",
  "broadcast": false,
  "lat": 37.9012,
  "lon": -122.5487,
  "pos_ts": 1749999940
}
```
Direction: `from: "web"` = operator → tracker; `from: <callsign>` = tracker → web. `to` is
`web` (any operator), an operator's name (per-operator addressing), a tracker callsign, or
`*` with `broadcast: true`.

`lat`/`lon`/`pos_ts` appear only on messages **from** a mobile tracker: the sender's most
recent beacon (`aprs_lat`/`aprs_lon`/`aprs_ts` from `mobile_trackers.json`), stamped on at
send time so operators can see where someone was. `pos_ts` dates the fix separately from the
message, because a tracker briefly out of coverage may send a message whose position is
minutes old — the UI flags that rather than presenting a stale pin as fact.

**Message IDs must never go backwards.** Every operator browser polls with a `since_id`
watermark, so if IDs restarted at 1 after a wipe, all existing watermarks would exceed any
new message and clients would go permanently deaf. `msgNextId()` therefore takes
`max(highest id in file, counter file) + 1` and rewrites the sidecar counter, so
**Delete All Messages** keeps the sequence intact. (IDs are per-event, so switching events
still resets them — a latent instance of the same hazard.)

The log is **not size-capped**: it grows for the life of an event, and every append rewrites
the whole array under an exclusive lock.

### Web Operator Flow

1. Operator clicks the **Messaging** button → subscribe modal → POST `?messaging=subscribe {name, password}` → receives token
2. Operator opens the compose modal and picks a recipient from the **To:** dropdown — `All Trackers` (default) plus every mobile tracker in the live feed (`t.mobile`, i.e. mobile-only and hybrid; radio-only trackers cannot receive). Right-clicking a tracker in the sidebar opens the modal pre-addressed to it. → POST `?messaging=send`
3. Server appends to `messages.json` and queues the message in the tracker's `pending_msgs`
4. Operator polls `?messaging=poll?web_token=...&since_id=N` every 5 seconds
5. When a mobile tracker sends a reply, it appears in the poll response → notification modal

The incoming notification modal shows the full conversation thread (12 px) above the new message (15 px, slightly larger) in a 480 px-wide window. History load fires at most one modal per page load (the most recent unnotified message).

**Conversation scoping.** `?messaging=history` returns the *entire* log, including other
operators' traffic, so both thread views filter through `_msgInvolvesMe()` — a message counts
as yours if it is addressed to `web`, addressed to you by name, or was sent by you. Because
every operator's messages carry `from: "web"`, "sent by me" is decided by comparing
`from_label` against your subscribed name; two operators sharing a name are indistinguishable.
The same filter gates the replay-on-return, which would otherwise pop up modals for messages
trackers sent to *other* operators.

**Live refresh.** The 5-second poll re-renders the compose thread in place when the modal is
open (`_renderComposeThread()`), preserving scroll position unless already at the bottom. An
arrival that continues the conversation on screen re-renders the notification modal
immediately; one from a different sender stays queued so the message being read is not yanked
away.

The compose modal footer has three links:
- **View all messages** — opens the full-log window (below).
- **Change my name** — inline panel; POSTs to `?messaging=rename`; updates display name for future messages.
- **Disable messaging** — clears the token and localStorage entry, stops the poll timer, resets the UI to the Messaging button (unsubscribed state).

### All Messages Window

Opened from the compose footer; fetches `?messaging=history` (the whole log, unfiltered) into
a scrollable table of Time / location pin / From / To / Message. Trackers render as
`ID Name` via a `callsign → id` map built as the legend updates; `web` renders as `Operator`,
`*` as `All Trackers`. Broadcasts are row-tinted.

- **Location pin** — shown when the row has `lat`/`lon`. Closes the log and the compose modal,
  drops a marker on the main Leaflet map with a popup (sender, time, text), and pans there at
  zoom ≥ 15. The marker self-removes on `popupclose`. Handing off to the real map rather than
  embedding a mini-map keeps course, aid stations and live trackers as context at no extra
  tile cost.
- **Export CSV** — `ID, Time, UTC, From, From Callsign, To, To Callsign, Broadcast, Latitude,
  Longitude, Message`, UTF-8 with BOM for Excel.
- **Delete All Messages** — POST `?messaging=delete_all`. Requires **both** an active messaging
  subscription **and** a signed-in account holding `messages.delete_all`; the button is hidden
  otherwise (`can_delete_all` in the history response) and the endpoint re-checks. Truncates
  `messages.json` to `[]`, preserves the ID counter, and clears every tracker's `pending_msgs`
  so queued messages don't surface on phones afterwards.

**Auto-subscribe for Display Pi operators:** When `window._aprsAutoMsgPw` is embedded in the page (via `?autologin&operator=<name>` → PHP session → HTML `<script>` tag), `_autoSubscribe()` runs silently on page load. The token is stored only in memory (not localStorage), so removing line 1 from `~/autologin.txt` and rebooting the Pi cleanly unsubscribes.

### Mobile Participant Flow

1. Messages queued in `pending_msgs` are delivered in the `?mobile=update` response body
2. A separate `?mobile=poll` call runs every 30 seconds as a lightweight check
3. Both paths may return the same message before an ack is sent; `_deliveredMsgIds` (a `Set<int>` in `background_location.dart`) deduplicates at the Flutter layer
4. Flutter shows a sound + dialog; tap **Reply** to POST `?mobile=message {token, text}`
5. On the next update or poll, `ack_ids` are sent to remove delivered messages from `pending_msgs`

**Recipient selection (Send Message sheet).** `?mobile=web_recipients` lists the operators
monitoring messages. One operator → auto-selected. Several → the last operator this user chose
is pre-selected (persisted in `SharedPreferences` as `last_msg_recipient`), so a repeat message
needs no dropdown interaction. The sticky default is only honoured while that operator is still
in the live list; if they have gone off-watch the field clears and the user must pick again,
rather than addressing someone who is no longer listening. Replies stay addressed to the sender
of the message being replied to and do not change the default.

**Landscape layout.** On iPad the on-screen keyboard takes roughly half the screen, so the
inbound-message dialog goes wide-and-short in landscape: a wider box, trimmed padding, a
2-line reply field with the character counter hidden, and — critically — a `Flexible` history
pane, so it surrenders height to the keyboard instead of overflowing the dialog.

---

## Analyzer

The Analyzer is a separate Flask web application served at `/analyzer/` that records APRS beacons into a local SQLite database during an event and provides an interactive playback and analysis map. It is the only interface that preserves a historical record of all positions received — the main map only retains the 10 most recent breadcrumbs per tracker. The Analyzer is intended for post-event analysis, coverage review, and real-time monitoring of beacon reception quality.

### Analyzer Architecture

```
APRS-IS (noam.aprs2.net:14580)
      │ TCP  (filtered to tracked callsigns)
      ▼
aprs_daemon.py  ──writes──▶  aprs.db  (SQLite)
                                   │
                             flask_app.py  ◀── browser polls on auto-refresh timer
                                   │
                             gunicorn  (127.0.0.1:5001, 2 workers)
                                   │
                          Apache mod_proxy  (/analyzer/ → 5001)
```

Two systemd services work together:

| Service | Unit file | Purpose |
|---------|-----------|---------|
| `analyzer` | `analyzer.service` | gunicorn serving `flask_app:app` |
| `analyzer-daemon` | `analyzer-daemon.service` | `aprs_daemon.py` recording beacons to SQLite |

`analyzer-daemon` is controlled from the Analyzer UI — it does not start automatically at boot. The `analyzer` (web app) service starts at boot and is always available even when no recording is taking place.

`flask_app.py` uses `ProxyFix(app.wsgi_app, x_prefix=1)` so that the `/analyzer/` path prefix is correctly stripped before routing.

### Analyzer Authentication

The Analyzer uses the shared user-account system described in [Authentication](#authentication).
Access is gated on two permissions:

| Permission | Required for |
|---|---|
| `analyzer.view` | All pages and API endpoints |
| `analyzer.admin` | `POST /api/daemon` (start/stop); `POST /api/flush` (erase data) |

The `marsaprs_session` cookie is validated by `auth_db.py` against `/var/lib/marsaprs/users.db`
on every request. Unauthenticated requests are redirected to `/auth/login.php`. The
**Collect Data** checkbox is disabled in the UI for users who lack `analyzer.admin`;
no password modal is shown.

### Beacon Recording

`aprs_daemon.py` connects to `noam.aprs2.net:14580` with an APRS-IS filter string built from all callsigns in the current event's configuration (trackers from `config.yaml`, mobile participants from `mobile_trackers.json`). It is managed by `analyzer-daemon.service` and controlled from the Analyzer UI by users with the admin password.

**What is recorded:** For each received packet that matches a tracked callsign: callsign, latitude, longitude, Unix timestamp, receiving station (iGate), and the full APRS path string. Stored in the `beacons` table of `aprs.db` (SQLite), keyed to the current event by `event_id`.

**Deduplication:** Consecutive beacons for the same callsign at the same position within a short interval are collapsed during the `get_ordered_deduplicated_beacons()` query so they don't clutter the playback trail.

**Aid stations as iGates:** Aid stations and rest stops that have an APRS callsign configured in the Admin UI are treated as iGates on the Analyzer map — their coordinates are loaded from `config.yaml`, their received packets are displayed with red receiver lines, and they appear as map markers alongside regular iGates.

**Data persistence:** Beacon data is never deleted automatically. It persists across daemon restarts, page reloads, and server reboots until an operator explicitly uses **Erase All Data** (admin password + two-step confirmation). The SQLite database is excluded from the deploy rsync so a new deployment never wipes event data.

### Map & Controls

The Analyzer map is a Leaflet map using the same tile backgrounds configured in the event. All recorded beacons for the current event are loaded on page load and re-fetched on every auto-refresh cycle.

**Beacon rendering:**

| Beacon type | Color | Line |
|-------------|-------|------|
| Radio (via iGate) | Red dot | Red line from beacon → iGate |
| Cellular (mobile participant) | Green dot | Green line between consecutive positions |

**Show Full Path** extends radio receiver lines through all intermediate digipeaters in the APRS path, not just the final iGate. When a single iGate is selected, lines route to that iGate/digipeater specifically.

**Name labels** (Show Names checkbox, default on): Each tracker's name is displayed in a black-on-white label at its last known position. iGate and aid station names are shown as permanent Leaflet tooltips on their map markers.

**Controls modal** (gear icon, lower-left corner):

*Left column — Display*

| Control | Function |
|---------|----------|
| Show All Times | Toggle time tooltip visibility on all beacon dots |
| Show Full Path | Draw lines through all digipeaters, not just the final iGate |
| Show Radio Beacons 🔴 | Show/hide all radio (APRS) beacon dots and lines |
| Show Cellular Beacons 🟢 | Show/hide all cellular (mobile app) beacon dots and lines |
| Show Courses | Toggle GPX/KML/GeoJSON course overlays |
| Show Names | Toggle name labels for trackers, iGates, and aid stations |
| Auto-Refresh | Slider: poll interval from 30 s to 5 min (per-client; does not affect recording) |
| Beacon Time Range | Two sliders: trim the displayed beacon window by index; each slider shows the actual date and time of the first/last beacon in range |

*Right column — Filtering*

| Control | Function |
|---------|----------|
| Trackers | Multi-select list of all tracked callsigns. Hybrid trackers (mobile + ham radio) appear as a single entry; selecting one shows both callsign streams. ⌘/Ctrl+click for multiple. |
| IGates | Multi-select list of iGates and digipeaters. Filtering to one iGate shows only beacons received by that station. |
| Cellular Carrier | Multi-select: All · AT&T · Comcast · Space Exploration · T-Mobile · Verizon · Other. Filters cellular beacons by the carrier stored in `device_info.carrier` in `mobile_trackers.json`. Radio beacons are unaffected. |

*Footer buttons*

| Button | Function |
|--------|----------|
| Save Map Position | Saves the current map center and zoom to `localStorage` as the default view for this event |
| Erase All Data… | Deletes all beacon rows for the current event from SQLite (requires admin password + two-step confirmation) |

### Analyzer Key Files

All files live under `/home/pi/analyzer/` on the server Pi.

| File | Purpose |
|------|---------|
| `src/flask_app.py` | Flask application: routes, auth, config loading, beacon enrichment, template rendering |
| `src/aprs_daemon.py` | APRS-IS listener; inserts beacons into SQLite; reads tracker list from `config.yaml` and `mobile_trackers.json` |
| `src/aprs_db.py` | SQLite wrapper: event management, beacon insert, ordered/deduplicated beacon fetch, recording time range queries |
| `src/aprs.db` | SQLite database; excluded from deploys |
| `src/templates/event_map.html` | Main map page (Leaflet + controls modal + all JS filtering/drawing logic) |
| `src/auth_db.py` | Python auth library: validates `marsaprs_session` cookie against `users.db` |

Configuration is read live from `/var/www/html/admin/config.yaml` (the active event symlink) and `/var/www/html/mobile_trackers.json` on every page load — no restart needed when the event or tracker list changes.

### Analyzer Services

```bash
# Flask web app (always on)
sudo systemctl status analyzer
sudo systemctl restart analyzer
sudo journalctl -u analyzer -f

# Beacon recording daemon (started/stopped from the UI)
sudo systemctl status analyzer-daemon
sudo systemctl start analyzer-daemon
sudo systemctl stop analyzer-daemon
sudo journalctl -u analyzer-daemon -f
```

Logs: `/var/log/analyzer/analyzer.log` and `/var/log/analyzer/daemon.log`

The daemon restarts automatically on failure (30 s delay, 5 retries per 5 minutes). The web app restarts automatically on failure (10 s delay).

### Analyzer API Endpoints

All endpoints require a valid `marsaprs_session` cookie with at least `analyzer.view`.
Admin endpoints additionally require `analyzer.admin`.

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/` | GET | `analyzer.view` | Redirect to current event URL |
| `/event/<name>` | GET | `analyzer.view` | Main map page; always redirects to the current yaml event |
| `/api/event_beacons/<name>` | GET | `analyzer.view` | All deduplicated beacons for the named event as JSON |
| `/api/check_admin` | GET | `analyzer.view` | Returns `{"ok": true}` if user has `analyzer.admin` |
| `/api/daemon` | GET | `analyzer.view` | Returns `{"running": true/false}` — current daemon status |
| `/api/daemon` | POST | `analyzer.admin` | `{"action": "start"\|"stop"}` — starts or stops `analyzer-daemon` |
| `/api/flush` | POST | `analyzer.admin` | Deletes all beacon rows for the current event |

---

## Backup, Recovery and Updates

### Server Pi

The server is backed up nightly to an FTP server using `aprs-backup.sh` (requires `lftp`).
The backup includes event configs, tracker history, and the WiFi credential file.

To restore: run `aprs-recover.sh`, which downloads the latest backup from the FTP server
and restores files in place.

### Display Pis

Each display Pi runs `auto-update.sh` nightly at 4:00 am. It downloads a tar archive of
updated scripts from the server, applies them via rsync, updates WiFi credentials, and
reboots at 4:10 am to pick up any changes.

To force an immediate update:

```bash
ssh pi@<ip> /home/pi/auto-update.sh
```

There is no backup for the display Pis since their data are all temporary.

### iGates

Each iGate runs `auto-update.sh` nightly at 4:00 am. It downloads updated direwatch
scripts and the WiFi credential list from the server, applies them, and restarts affected
services. If `direwolf.conf` has changed it restarts direwolf.

To force an immediate update:

```bash
ssh pi@<ip> /home/pi/auto-update.sh
```

There is no backup for the iGates since their data are all temporary.

---

## Log Rotation

Log rotation is configured via `/etc/logrotate.d/aprs`, installed by `install.sh` on iGates and the server.

**iGates** — logs in `/var/log/direwolf/`:

| File | Written by |
|------|-----------|
| `console.log` | direwolf (RF decoding, APRS-IS forwarding) |
| `watchdog.log` | `igate-watchdog.sh` (SDR, IP, internet checks) |

**Display Pis** — logs in `/home/pi/`:

| File | Written by |
|------|-----------|
| `update.log` | `auto-update.sh` (nightly update) |

**Server** — multiple locations:

| File | Written by |
|------|-----------|
| `/var/log/aprs-daemon/daemon.log` | `aprsDaemon.php` (APRS-IS connection, packet processing) |
| `/var/log/netbird-poller.log` | `netbird-poller.py` (NetBird device polling) |
| `/var/log/aprs-backup.log` | `aprs-backup.sh` (nightly FTP backup) |
| `/var/log/boot.log` | System boot messages (kernel + service startup output) |

All three APRS-specific logs are covered by `/etc/logrotate.d/aprs` (installed by `install.sh`). `boot.log` is managed by the system's default logrotate config. Logs are rotated daily, compressed, and seven days of history are retained.

---

## Building & Deploying Devices

Master SD card images for each device type are stored on the FTP server: [ftp://ftp.w6sg.net/APRS-SD-Masters](ftp://ftp.w6sg.net/APRS-SD-Masters). They include
the results of running `install.sh` but not `configure.sh` — so packages, services, and
scripts are pre-installed, but site-specific settings (callsign, location, hostname) are
set when deploying each individual device.

### NetBird Setup Keys

As part of configuring any device, you will need a NetBird key. At the moment, the only way to get one is to email Doug Kaye at [doug@rds.com](mailto:doug@rds.com). 

### APRS Server

The server master image is a complete, fully-configured copy of the running server. Flashing
it to a replacement Pi produces a working server immediately — no further steps needed.

1. Download and flash the server master image from the FTP server using **Apple Pi Baker** or Raspberry Pi Imager.
2. Insert SD card and boot. The server is ready.
3. Configuration should only be necessary if something (such as a NetBird address) has changed from the standard setup.

### Display Pis & iGates

1. Download and flash the display Pi master image.
2. Insert SD card, connect to your local network, and boot.
3. Find the Pi's IP address (from your router or `arp -a <hostname>`).
4. SSH in: `ssh pi@<ip>` (password: `guacamole`)
5. Run the configuration wizard:
   ```bash
   /home/pi/configure.sh
   ```
   Expected hostnames: `NetControl` and `BigTV`.
6. Reboot when prompted.

---

## Creating New Master Images

### APRS Server

The server master is simply a snapshot of the current running server. No install script is
needed (and none is available — the script would be served by the very Pi being built).

1. Shut down aprs-pi cleanly: `sudo shutdown -h now`
2. Remove the SD card and copy it using **Apple Pi Baker** or `dd`.
3. Reinstall the original card and reboot.

### Display Pis

1. Flash a fresh **Raspberry Pi OS Desktop (64-bit, Trixie)** to an SD card using
   Raspberry Pi Imager. In the OS customization dialog set:
   - Model: **Pi 4**
   - Hostname: `displayClone`
   - Username / password: `pi` / `guacamole`
   - WiFi: your local network
   - SSH: enabled
   - Timezone: as appropriate
2. Insert the card into a Pi 4 and boot.
3. Find the IP and SSH in.
4. Download and run the install script:
   ```bash
   bash <(curl -fsSL https://marsaprs.org/display/install.sh) 2>&1 | tee install.log
   ```
5. **Do not run `configure.sh`** — that step is done when deploying each device.
6. Shut down: `sudo shutdown -h now`
7. Copy the SD card using **Apple Pi Baker** — this is the new display Pi master.
8. Upload the image file to **`ftp.w6sg.net/APRS-SD-Masters`** for later cloning.

### iGates

1. Flash a fresh **Raspberry Pi OS Desktop (64-bit, Trixie)** to an SD card using
   Raspberry Pi Imager. In the OS customization dialog set:
   - Model: **Pi Zero 2 W**
   - Hostname: `trackerClone`
   - Username / password: `pi` / `guacamole`
   - WiFi: your local network
   - SSH: enabled
   - Timezone: as appropriate
2. Insert the card into a Pi Zero 2 W and boot.
3. Find the IP and SSH in.
4. Download and run the install script:
   ```bash
   bash <(curl -fsSL https://marsaprs.org/igate/install.sh) 2>&1 | tee install.log
   ```
5. **Do not run `configure.sh`** — that step is done when deploying each device.
6. Shut down: `sudo shutdown -h now`
7. Copy the SD card using **Apple Pi Baker** — this is the new iGate master.
8. Upload the image file to **`ftp.w6sg.net/APRS-SD-Masters`** for later cloning.

---

## Supporting Systems

### NetBird Status Monitor

A web application at `/netbird/` that monitors all registered Pi devices over the NetBird
VPN, showing real-time health status and providing a browser-based SSH terminal.

#### Architecture

```
addresses.yaml  (device list, credentials)
toggle_state.json  (per-device toggle timestamps)
      │
      ├──▶  netbird-poller.py  ──writes──▶  stats.json
      │        │  UDP poll every N seconds
      │        ▼
      │     each device's NetBird IP : 1235
      │
      ├──▶  api.php  ◀── browser polls every N seconds
      │        │  merges YAML + stats.json + toggle_state.json
      │        ▼
      │     index.php  (main status page)
      │
      └──▶  admin.php  (device management)
               │
               ├──▶  save.php  (CRUD + toggle)
               └──▶  ssh_term.php  ──SSE──▶  ssh_stream.php
                                                   │  proc_open
                                                   ▼
                                              ssh_relay.py  (paramiko)
                                                   │  NetBird VPN
                                                   ▼
                                              remote device
```

| File | Role |
|------|------|
| `netbird-poller.py` | UDP polling daemon (Python); sends requests to enabled devices, writes `stats.json` |
| `api.php` | JSON endpoint; merges `addresses.yaml`, `stats.json`, and `toggle_state.json`; computes `pending_until` server-side |
| `index.php` | Main status page; password-protected; polls `api.php` every N seconds |
| `admin.php` | Device list CRUD; toggle switches; SSH terminal launch |
| `save.php` | Handles all CRUD actions and toggle; computes and returns `pending_until` on toggle |
| `yaml_lib.php` | Custom YAML parser/writer (no `php-yaml` extension required) |
| `addresses.yaml` | Device configuration: name, host, ip, group, enabled, ssh\_user, ssh\_pass |
| `stats.json` | Live poller output; read by `api.php` |
| `toggle_state.json` | Per-device toggle timestamps (Unix epoch); written by `save.php` on every enable/disable |
| `ssh_term.php` | Browser SSH terminal popup (xterm.js) |
| `ssh_stream.php` | SSE relay; spawns `ssh_relay.py` via `proc_open` |
| `ssh_relay.py` | Python/paramiko SSH relay; reads input queue, writes output to stdout |
| `ssh_input.php` | Receives keystrokes from browser; appends to per-session queue file |
| `ssh_resize.php` | Receives terminal resize events; writes to per-session resize file |

#### Device Configuration (`addresses.yaml`)

```yaml
- name: Muir Woods
  host: MARS-2            # APRS callsign / hostname used for status lookups
  ip: 100.101.197.70      # NetBird IP
  group: "via internet (WiFi or Ethernet)"
  enabled: true
  ssh_user: pi            # stored when first SSH login succeeds
  ssh_pass: ""            # stored only if "Remember password" is checked
```

The `group` field becomes a section header in both the admin and main pages. The `host`
field is used for the hostname self-reporting endpoint. `ssh_user` and `ssh_pass` are
written by `save.php` when a user authenticates via the SSH terminal.

#### Status States

| Status | Condition | Color |
|--------|-----------|--------|
| **Online** | Device responded to the last UDP poll | Green |
| **Pending** | Device was recently enabled or disabled; waiting for the change to take effect | Blue |
| **Offline** | Enabled but no response after the pending period has fully elapsed | Red |
| **Disabled** | `enabled: false` in `addresses.yaml`, and not in a pending window | Gray |

**Pending** applies to both transitions:

- **Enable (off → on):** Devices check whether to start NetBird on 5-minute clock boundaries (0:00, 0:05, 0:10, …). After the server sets a device enabled, it won't respond via its NetBird address until ~30 seconds after the next 5-minute boundary. Once past that deadline, the system allows 3 additional polling intervals for the device to respond before declaring it Offline.
- **Disable (on → off):** Similarly, a disabled device will keep responding until ~30 seconds past the next 5-minute boundary. The status shows Pending until the deadline passes, rather than showing Disabled prematurely while the device is still reachable.

`pending_until` is computed entirely server-side by `api.php` and `save.php`:

```php
$deadline     = (int)(ceil($toggled_at / 300) * 300) + 30;
$pending_until = $enabled
    ? $deadline + ($repeat_seconds * 3)   // enable: extra time for 3 missed polls
    : $deadline;                           // disable: deadline only
```

The browser compares `Date.now() / 1000 < pending_until` and renders the Pending badge accordingly. No scheduling logic lives in the client.

#### Stats Response Format

Each Pi runs `stats-listener.php` (iGate: `stats-listener.php`; display Pi / server: `StatsRequestListener.php`) as a systemd service listening on UDP port 1235. When polled by `daemon.php`, it responds with a single line of plain text.

**Full response** (default):
```
<hostname>  Load=<load>  Temp=<temp>  Mem=<disk>  Throttled=<throttled>  <NetBird-IP>  SSID=<ssid>  [Low Voltage][High Temp]
```

**Short response** (when the request contains the word `short`):
```
<hostname> Load=<load> <temp> Throttled=<throttled> SSID=<ssid> [Low Voltage][High Temp]
```

| Field | Source | Notes |
|-------|--------|-------|
| `hostname` | `hostname` command | |
| `Load` | `uptime` | 1, 5, and 15-minute load averages |
| `Temp` | `vcgencmd measure_temp` | e.g. `temp=47.8'C` |
| `Mem` | `du -sh ~` | Home directory size; proxy for total disk use |
| `Throttled` | `vcgencmd get_throttled` | Hex bitmask; `0x00000` = normal |
| `<NetBird-IP>` | `netbird status` | Omitted in short form |
| `SSID` | `nmcli` | WiFi network name, or `<Ethernet>` |
| `Low Voltage` | Throttled bit 0 | Appended only when set |
| `High Temp` | Throttled bit 3 | Appended only when set |

Example response as shown on the NetBird Status page:
```
0.02,0.06,0.11  40.4'C  128M  TerraceLan2  Low Voltage
```

#### Immediate Status Propagation

`api.php` merges `addresses.yaml`, `stats.json`, and `toggle_state.json` on every request,
so toggling a device in the admin page is reflected on the status page on its very next poll
— no poller cycle needed. `save.php` also returns `pending_until` directly in the toggle
response so the admin page can render the correct Pending state immediately, before the next
poll completes. A `BroadcastChannel('aprs_netbird')` message carries `pending_until` to the
status page if it is open in the same browser.

#### Hostname Self-Reporting Endpoint

```
GET /netbird/?hostname=<callsign>
```

No authentication required. Returns a plain-text integer:

| Value | Meaning |
|-------|---------|
| `1` | Device is known and `enabled: true` |
| `0` | Device is known and `enabled: false` |
| `-1` | Device not found in `addresses.yaml` |

iGates use this to determine whether to transmit APRS beacons. They poll it on 5-minute
clock boundaries.

#### SSH Terminal

The SSH button (admin page, active when device is Online) opens a popup with a full
terminal powered by xterm.js.

**Security:** If both `ssh_user` and `ssh_pass` are stored, PHP mints a one-time session
token server-side — the password is never sent to the browser. If credentials are missing,
a login form is shown; on success the credentials are saved to `addresses.yaml`.

**Relay architecture:**
1. `ssh_stream.php` spawns `ssh_relay.py` via PHP `proc_open`.
2. `ssh_relay.py` opens a paramiko PTY shell to the device.
3. SSH output → base64 lines → PHP → SSE → browser → xterm.js.
4. Keystrokes → `ssh_input.php` → queue file → relay → SSH channel.
5. Terminal resize events → `ssh_resize.php` → `chan.resize_pty(cols, rows)`.

**Dependency:** `sudo apt-get install python3-paramiko`

#### Poller (`netbird-poller.py`)

Run by `netbird-daemon.service`. Polls enabled devices every `repeat_seconds` (default 60)
when there are active viewers. Writes responses to `stats.json` via atomic rename. Requires
3 consecutive missed polls before marking a device Offline.

```bash
sudo systemctl status netbird-daemon
sudo systemctl restart netbird-daemon
sudo journalctl -u netbird-daemon -f
```

Log: `/var/www/html/netbird/daemon.log`

#### API: `api.php`

No authentication required. Polled by the main page every 15 seconds.

`GET api.php` — returns full device status for all devices:

```json
{
  "last_send_ts": 1779080795,
  "repeat_seconds": 60,
  "daemon_running": true,
  "devices": [
    {
      "ip": "100.101.197.70",
      "hostname": "MARS-2",
      "name": "Muir Woods",
      "group": "via internet (WiFi or Ethernet)",
      "enabled": true,
      "online": true,
      "pending_until": null,
      "last_request": 1779080795,
      "last_response": 1779080795,
      "response_data": "MARS-2 Load=0.19 Temp=47.8°C ..."
    }
  ]
}
```

`pending_until` is a Unix timestamp (or `null`). The browser renders Pending while `Date.now() / 1000 < pending_until`.

#### API: `save.php`

| Action | Auth | Method | Key Parameters | Description |
|--------|------|--------|----------------|-------------|
| `add_device` | required | POST | name, host, ip, group, enabled | Append device |
| `update_device` | required | POST | orig\_ip, name, host, ip, group, enabled | Edit device |
| `delete_device` | required | POST | ip | Remove device |
| `toggle_device` | required | POST | ip | Flip `enabled`; returns `{ok, enabled, pending_until}` |
| `save_config` | open | POST | repeat\_seconds | Update poll interval |
| `save_ssh_creds` | required | POST | ip, ssh\_user, ssh\_pass | Update credentials |

---

### WiFi Manager

A web application at `/wifi/` for managing the shared list of WiFi credentials distributed
to all MARS Pi devices nightly.

#### Architecture

```
/var/www/html/wifi/wifi.yaml  (on aprs-pi — master copy)
     │
     ├──▶  /igate/wifi/get.php?token=<token>    ──▶  iGates
     ├──▶  /display/wifi/get.php?token=<token>  ──▶  Display Pis
     └──▶  update-wifi.php                      ──▶  Server Pi (nightly via cron)
```

| File | Role |
|------|------|
| `server/www/wifi/index.php` | Web UI for viewing and editing the credential list |
| `server/www/wifi/get.php` | Token-authenticated download endpoint for iGates |
| `display/www/wifi/get.php` | Token-authenticated download endpoint for display Pis |
| `/home/pi/update-wifi.php` | Applies `wifi.yaml` via `nmcli` on each Pi |
| `/home/pi/.wifi-token` | Shared authentication token (required on all Pi types) |

Every edit and drag-drop reorder immediately writes `wifi.yaml` via the `?save` endpoint.
There is no manual save step.

#### Update Schedule

| Device | When credentials update |
|--------|------------------------|
| iGates | Nightly at 4:00 am via `auto-update.sh` |
| Display Pis | Nightly at 4:01 am via `auto-update.sh` |
| Server Pi | Nightly at 4:00 am via cron |

Note that display Pis (Net Control and Big TV) are likely not running at 4am and therefore must be updated manually.

#### Token Authentication

Each Pi has `/home/pi/.wifi-token`. Download requests without a valid token return HTTP 403.
If the token is missing from the server:

```bash
tar -xzf /var/www/html/igate/files.tar.gz -C /tmp ./home/.wifi-token
cp /tmp/home/.wifi-token /home/pi/.wifi-token
```

#### Safe Download Pattern

`auto-update.sh` downloads to a temp file and validates content before replacing the live copy:

```bash
wget -qO /tmp/wifi.yaml.new "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)"
grep -q "^- name:" /tmp/wifi.yaml.new && mv /tmp/wifi.yaml.new /home/pi/wifi.yaml
```

`update-wifi.php` exits without making any `nmcli` changes if zero entries are parsed,
preventing accidental deletion of all configured networks.

---

## Appendix

---

### File Formats

#### `event.yaml`

Each event's configuration lives in `events/<EventName>/event.yaml`. `config.yaml` is a
symlink to the active event's file. `aprsDaemon.php` and `index.php` monitor it via
`filemtime`; changes take effect within ~5 seconds without restarting the daemon.

##### `event`

```yaml
event: Dipsea 2026
```

##### `legend`

Optional HTML displayed in the lower-left corner of the map in kiosk mode (ie, for the Big TV). The value is injected as `innerHTML`, so any HTML tags, inline styles, and attributes are supported. In the YAML file, use `\n` inside a quoted string to break across lines for readability.

```yaml
legend: "<b>Race Day — June 14</b><br>Start: 7:00 AM at Mill Valley"
```

##### `trackers`

```yaml
trackers:
  - callsign: W6SG-4    # APRS callsign
    id: S2              # short label on the map marker and sidebar
    name: Rob           # full name shown in the sidebar
```

##### `tracker_style`

```yaml
tracker_style:
  icon: circle          # circle, square, diamond, triangle, star, cross, person
  label_color: "#000000"
```

Marker size scales with zoom level: full at zoom ≥ 14, down to 30 % at zoom 7 and below.

##### `map`

```yaml
map:
  lat: 37.87255
  lon: -122.544079
  zoom: 13
```

##### `backgrounds`

```yaml
backgrounds:
  - name: OpenStreetMap
    url: https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png
    attribution: "&copy; <a href='https://www.openstreetmap.org/copyright'>OpenStreetMap</a> contributors"
    max_zoom: 19
```

`max_zoom` caps the Leaflet zoom level for this tile layer. Omit to default to 19.

##### `courses`

GPX, KML, GeoJSON, or JSON overlays. Files must be in the event directory.

```yaml
courses:
  - name: 13 Mile
    file: events/Dipsea 2026/13_Mile.geojson
    color: "#2196f3"
```

Supported extensions: `.gpx` `.kml` `.geojson` `.json`

##### `aidstations`

```yaml
aidstations:
  - name: Muir Woods Clubhouse
    lat: 37.89799
    lon: -122.56667
```

##### `igates`

```yaml
igates:
  - name: Pt. Reyes Station
    lat: 38.068051
    lon: -122.808013
```

##### `section_visibility`

Default visibility of each sidebar section on page load.

```yaml
section_visibility:
  trackers: true
  courses: true
  aidstations: true
  igates: true
  backgrounds: true
```

##### `mobile`

Controls mobile location sharing for participants.

```yaml
mobile:
  enabled: true              # show Share Location button in the mobile drawer
  pin: "1234"                # PIN participants must enter (omit to require no PIN)
  root: K6DRK                # base APRS callsign; participants get <root>-01, -02, etc.
  messaging_password: zippy  # enables Messages button on web map; omit to disable messaging
```

When `enabled` is true, any visitor on a mobile device sees a **Share Location** button in the drawer footer. Participants enter their name (pre-filled from localStorage on return visits) and the PIN (both shown as plain text), then tap **Share Location** to start sharing in **unknown** (?) mode. **Smart Track** automatically determines the activity and adjusts the beacon interval based on GPS speed within ~90 seconds (see [Smart Track](#smart-track)). The button changes to **Sharing** while active; tapping it opens a panel to stop sharing. The server assigns a unique callsign (`<root>-NN`) and a session token. Position updates are injected directly into the APRS-IS network, so mobile participants appear on this map and on external APRS sites (aprs.fi, CalTopo) exactly like any other tracker. Session state is stored in `mobile_trackers.json`.

---

### Server

#### Requirements

- PHP 8.x CLI (daemon) and web (Apache)
- PHP `sockets` extension enabled
- Web root (`/var/www/html/`) writable by `www-data` group
- Network access to `noam.aprs2.net:14580`
- `python3-paramiko` for the SSH terminal relay

#### Configuration (`config.yaml`)

`config.yaml` is a symlink to the active event's `event.yaml`. Changes take effect within
~5 seconds without restarting the daemon. See [File Formats](#file-formats) for the full
`event.yaml` field reference.

#### Event Directory Structure

Each event lives in `events/<EventName>/`. `config.yaml` is a symlink to the active event.

```
events/
├── Dipsea 2026/
│   ├── event.yaml              # event configuration
│   ├── Dipsea.json             # course overlay
│   └── tracker_history.yaml    # auto-generated; not committed to git
└── Marin Ultra Challenge 2026/
    ├── event.yaml
    ├── 13_Mile.geojson
    ├── 18_Mile.json
    └── 6_Mile.json
config.yaml → events/Dipsea 2026/event.yaml   (symlink)
```

`tracker_history.yaml` — auto-generated by `aprsDaemon.php`. Stores the 10 most-recent
beacon positions per tracker. Pruned when a tracker is removed from the config. Also pruned for a mobile participant's callsign when they start a new session (`?mobile=join`), so stale breadcrumbs from a prior session never appear.

**Startup ordering note:** `loadMobileSessions()` must run before `readTrackerHistoryFile()` at daemon startup. `loadMobileSessions()` calls `unset($trackerHistory[$cs])` for any callsign not yet in `$trackers`; if history were loaded first, all mobile callsigns would be cleared (they are absent from `$trackers` until `loadMobileSessions()` adds them). Running sessions load first makes the `unset()` calls harmless (the array is still empty).

**Minimum-distance filter:** Before appending a new breadcrumb, the daemon computes the haversine distance from the previous breadcrumb. If the new position is within 30.48 m (100 feet), it is discarded — `lastUpdate` is still updated (so the tracker doesn't appear stale), but no new breadcrumb is added and `trackers.json` position fields are not changed. This filter applies to both regular and mobile trackers and covers both web and native-app clients, since both read the same server-side files.

**APRS-IS injection deduplication:** `?mobile=update` now gates each call to `injectAprsPacket()` with a server-side dedup check. The last injected position and timestamp (`aprs_lat`, `aprs_lon`, `aprs_ts`) are stored per tracker in `mobile_trackers.json`. A new packet is injected only when the tracker has moved ≥ 30 m from the last injected position **or** ≥ 5 minutes have elapsed since the last injection (heartbeat). This does not affect what is stored, displayed, or returned to clients — every `?mobile=update` is still processed fully; only the outbound TCP connection to `noam.aprs2.net` is skipped when the position hasn't changed meaningfully.

#### Running the Daemon

**Direct (foreground):**

```bash
php aprsDaemon.php
```

| Option | Default | Description |
|--------|---------|-------------|
| `server=<host>` | `noam.aprs2.net` | APRS-IS server hostname |
| `config=<file>` | `config.yaml` | Path to configuration file |
| `trackerstatus=<file>` | `trackers.json` | Path to tracker status output file |
| `debug` | off | Print every received APRS line to stdout |

**Via systemd:**

```bash
sudo systemctl status aprs-daemon
sudo journalctl -u aprs-daemon -f
sudo systemctl restart aprs-daemon
```

Log: `/var/log/aprs-daemon/daemon.log`

The wrapper script (`aprs-daemon.sh`) handles PID-file-based duplicate-start protection.
systemd restarts the daemon on failure (10 s delay, 5 retries per 5 minutes).

**Tracker colors:**

| Color | Condition |
|--------|-----------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

**APRS packet decoding:**

| Format | Description |
|--------|-------------|
| Uncompressed | `DDmm.mmN/DDDmm.mmW` — degrees and decimal minutes |
| Compressed Base91 | 4-byte Base91-encoded lat/lon in the payload |
| Mic-E | Latitude in the 6-character AX.25 destination field; longitude in payload |

The daemon maintains a 60-second socket receive timeout; if no data arrives (e.g., network
change) the socket is closed and a new connection established automatically.

A **watchdog** check runs at the top of every main loop iteration. If no data has been written in more than 3 minutes (i.e., the APRS-IS connection stays `ESTABLISHED` but delivers no packets), the daemon reconnects and resets the timer. This prevents a silent multi-hour freeze that occurs when the APRS-IS server goes quiet without closing the TCP connection.

```php
// At the top of the while(TRUE) loop:
if ((time() - $lastWriteTime) > 180) {
    connectToAprsServer();
    $lastWriteTime = time();
}
```

#### Web Interface

The map is at `https://marsaprs.org/`. Layout adapts automatically:

- **Desktop** — fixed sidebar on the left; map fills the rest.
- **Touch/mobile** — map fills the screen; slide-in drawer opened with the ⚙ icon.

**Mobile full-screen:** On iOS and iPadOS, `index.php` shows a one-time nudge at the bottom of the screen on first visit. Safari users see "tap Share ⬆ → Add to Home Screen"; Chrome (`CriOS`) users see "tap ⋯ → Add to Home Screen". Dismissing the nudge via ✕ sets `localStorage['a2hs-dismissed']` so it never reappears. Once installed as a home screen app (standalone mode), the page runs without address bar or tab bar, enabled by the `apple-mobile-web-app-capable` and `apple-mobile-web-app-status-bar-style` meta tags. On Android Chrome, the address bar auto-hides on scroll; no nudge is needed.

**Sidebar sections (desktop):** Each section header has a visibility checkbox (show/hide map
objects) and a click-to-collapse toggle. Sidebar width is adjustable by dragging the divider;
width is saved in `localStorage`.

**Desktop lower-right corner controls:** On non-touch screens, two Leaflet controls appear in the lower-right corner of the map (above the zoom controls):
- **↺ Reset Map** — resets the map view, clears breadcrumbs and the Origin marker (same as the sidebar Reset Map button).
- **⊕ My Location** — requests the browser's geolocation and pans to the result; blinks while locating; shows an error tooltip if geolocation fails or times out (8 s timeout).

**Mobile drawer:** Sections are collapsible accordions. Visibility checkboxes work the same
as desktop. Footer buttons: Save Map, Admin, User Guide, Kiosk Mode, Clients, and (when `mobile.enabled`) Share Location / Sharing. The **About** section of the drawer shows the user's assigned APRS callsign while sharing is active. The floating reset button (↺) pinned to the map also closes the drawer if it is open before recentering.

**Mobile tracker markers:** Mobile participants (sharing via Share Location) are rendered as **rounded squares** on both the map and in the sidebar, distinguishing them from regular APRS trackers (which use the configured icon shape). The shape is determined by `t.mobile` in the tracker JSON. In `index.php`, the icon is selected as `t.mobile ? 'square' : trackerStyle.icon`; the sidebar dot gets `border-radius: 3px` for mobile and `50%` for others. In the native Flutter app, each marker also shows the **tracker ID** as a text label directly next to the dot on the map.

**Tracker clicks (desktop — three-click cycle):**
1. Blink tracker + show beacon history (up to 10 positions as dots + dashed polyline with directional arrows; trail auto-refreshes as tracker moves)
2. Zoom to tracker's last position (zoom 15)
3. Reset map to default view

**Tracker touches (mobile):**
- Short tap — blink + show history; drawer stays open
- Long press (≥ 500 ms) — blink + history + close drawer + zoom to position

**Origin, distance, bearing:** Right-click sets a red Origin marker. Left-click anywhere
shows distance (miles) and bearing from Origin. Origin clears on Reset Map.

**Map layer z-order (top to bottom):**

| Layer | z-index |
|-------|---------|
| Trackers | 450 |
| Aid Stations | 430 |
| Courses | 410 |
| iGates | 390 |

**Kiosk mode:** `?kiosk=1` or click **Kiosk Mode**. Shows Trackers, Aid Stations, and
iGates only. Footer: Sidebar toggle, Reset Map, Exit (navigates to `localhost:8080/exit`).

**Live polling:** The browser polls `?json` every 5 seconds when viewing the default event
and when the tracker list has not been locally edited. Only tracker positions and timestamps
are updated; all other config sections are left unchanged by polls.

#### Admin Interface

Open `https://marsaprs.org/admin/`. Requires a user account with `admin.view` or
`admin.edit` (see [Authentication](#authentication)). Session lasts 24 hours.

**Editable sections:** Trackers (callsign, ID, name), Tracker Style (icon, color), Map
Default View, Default Section Visibility, Backgrounds, Courses, Aid Stations, iGates, Legend, Mobile Tracking (enabled, PIN, root callsign; messaging password; participant list with rename/block/remove), Beacon Settings (upload interval and distance threshold per activity mode).

**Messages modal:** The 💬 Messages button opens a modal showing the full message thread for the current event, with Export (.txt) and **Clear Thread** buttons. Clear Thread deletes `messages.json` after a two-click confirmation (click once to arm, click again within 4 seconds to confirm).

**Tracker Δ column:** Each tracker row shows a read-only Δ field — the minimum interval
between consecutive beacons in the last 10 received, displayed as M:SS. Shows `—` until at
least 2 beacons have been received. The column refreshes automatically every 30 seconds;
the last refresh time is shown next to the Trackers section heading. Deleting and re-adding
a tracker resets its history — avoid this while trying to verify the beacon interval.

**Header/footer buttons:**

| Button | What it does |
|--------|-------------|
| **Update** | Saves local-only sections to `localStorage`; returns to map. Does not write to server. |
| **Save** | Writes config to the current event's `event.yaml`. Does not change the active event symlink. |
| **Save as Default Event** | Writes config + re-points `config.yaml` symlink. All users pick up changes within ~5 s. |
| **Exit** | Leaves without saving. |
| **Sign Out** | Destroys the session. |

**Local vs server sections:**

| Classification | Sections |
|----------------|---------|
| Local (Update saves) | Event Name, Legend, Tracker Style, Map Default View |
| Server-only | Trackers, Backgrounds, Courses, Aid Stations, iGates |

**Event management:** Save as Default Event creates or overwrites a named event directory and re-points
the symlink. Load shows all events newest-first; Load & Activate switches the active event.
Delete removes the event directory permanently. Events can be locked (`locked: true` in
`event.yaml`); locked events reject saves without the admin password.

**Import / Export:**

| Section | Export | Import |
|---------|--------|--------|
| Event | YAML | YAML |
| Trackers | YAML, CSV | YAML, CSV |
| Aid Stations | YAML, CSV, GPX | YAML, CSV, GPX, KML, GeoJSON, JSON |
| iGates | YAML, CSV, GPX | YAML, CSV, GPX, KML, GeoJSON, JSON |
| Courses | YAML, CSV | YAML, CSV, GPX, KML, GeoJSON, JSON |

**Smart lat/lon paste:** Pasting a combined coordinate string (e.g., `37.7749, -122.4194`
from Google Maps) into either lat or lon field auto-splits and populates both fields.

**Tile Provider Browser:** The ⊞ button opens a grid of all free Leaflet tile providers
with thumbnail previews. Clicking a provider inserts it into Backgrounds with all fields
pre-filled.

**Manage Location Files:** Sortable table of course files across all events. Upload
(drag-and-drop or file picker), rename, delete, or add to the current event.

#### API Endpoints — `index.php`

All endpoints return `Cache-Control: no-store` unless noted. ETag/304 caching is used
for `?json`, `?config`, and `?history`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?json` | GET | Current tracker state from `trackers.json`, plus iGate and aid station last-beacon timestamps |
| `?config` | GET | Parsed active configuration (all sections) |
| `?history` | GET | Beacon history — 5 most-recent positions per tracker |
| `?clientstatus` | GET | Apache worker stats and connected client IPs |
| `?mobile=join` | POST | Create a mobile participant session; assigns callsign and returns token. Clears any prior history for that callsign. |
| `?mobile=update` | POST | Heartbeat + inject APRS-IS position packet; refreshes session timestamp. Accepts `ack_ids` to clear delivered messages. Returns pending messages in response body. Returns 404 if the session was removed or blocked. |
| `?mobile=leave` | POST | End the participant session and remove from `mobile_trackers.json`. |
| `?mobile=message` | POST | Send a text message from a mobile participant to web operators. Body: `{token, text, to?}` — `to` is an operator name for per-operator addressing, omitted (legacy) → `web` (all operators). Stamps the sender's latest beacon onto the log entry as `lat`/`lon`/`pos_ts`. Returns `{ok, id}`. |
| `?mobile=poll` | POST | Lightweight message poll without updating position. Body: `{token, ack_ids?}`. Returns `{messages: [...]}` or 404. |
| `?mobile=msghistory` | POST | Fetch full message history for the session callsign. Body: `{token}`. Returns `{messages: [...]}` oldest-first. |
| `?mobile=auth` | POST | Validate the event password. Body: `{password}`. Returns 200 if accepted. |
| `?messaging=subscribe` | POST | Subscribe as a web operator. Body: `{name, password}`. Returns `{token, name}` or `{error}`. |
| `?messaging=send` | POST | Send a message from a web operator to a mobile tracker. Body: `{web_token, to, text}`. `to` is a callsign or `"*"` for broadcast, taken from the compose **To:** dropdown. |
| `?messaging=poll` | GET | Poll for new messages directed to web operators. Params: `web_token`, `since_id`. Returns `{messages, last_id}`. |
| `?messaging=rename` | POST | Update operator's display name. Body: `{web_token, name}`. Updates `web_sessions.json` and returns `{ok}`. |
| `?messaging=history` | GET/POST | Full `messages.json` for the current event (unfiltered — includes other operators' traffic). Param/body: `web_token`. Returns `{messages, last_id, can_delete_all}`. |
| `?messaging=delete_all` | POST | Erase the message log. Body: `{web_token}`. Requires a valid subscription **and** a signed-in account with `messages.delete_all`; otherwise 403. Preserves the ID counter and clears all `pending_msgs`. Returns `{ok, deleted}`. |
| `?autologin` | GET | Set a PHP session for the current event (no password required); if `operator` param is set, also sets the operator name in session. Redirects to clean URL. |

#### API Endpoints — `admin/index.php`

All endpoints require an active session (HTTP 401 if not authenticated).

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?logout` | GET | Destroy session and redirect to login |
| `?load` | GET | Active event config as JSON |
| `?save` | POST | Write full config to `config.yaml` |
| `?versions` | GET | All saved events, newest-first |
| `?saveonly` | POST | Write config to named event; do not change symlink |
| `?saveversion` | POST | Write config + re-point `config.yaml` symlink |
| `?loadversion&name=<N>` | GET | Named event's config as JSON |
| `?deleteversion` | POST | Delete event directory |
| `?locationfiles` | GET | Course files in the active event directory |
| `?alllocationfiles` | GET | Course files across all events |
| `?upload` | POST | Upload a course file to the active event directory |
| `?renamefile` | POST | Rename a course file |
| `?deletefile` | POST | Delete a course file |
| `?setactiveevent` | POST | Re-point `config.yaml` without saving content |
| `?bglib` | GET | Deduplicated background tile layers from all events |
| `?beacondeltas` | GET | Min inter-beacon gap per tracker callsign (from `tracker_history.yaml`) |
| `?togglelock` | POST | Lock or unlock an event (requires password) |
| `?messages` | GET | Return the full `messages.json` log for the current event as JSON. |
| `?delete_messages` | GET | Delete `messages.json` for the current event. Used by the "Clear Thread" button in the Messages modal. |

#### File Permissions

Web root `/var/www/html/` is owned `pi:www-data` with mode `775`.
The daemon runs as `www-data` with `UMask=0002`; files it creates get mode `664`.

| File | Needs write access | Created by |
|------|-------------------|------------|
| `trackers.json` | www-data (daemon) | daemon on first run |
| `igates.json` | www-data (daemon) | daemon on first iGate activity |
| `aidstations.json` | www-data (daemon) | daemon on first aid station activity |
| `config.yaml` | www-data (admin page) | manual / admin Save as Default Event |
| `events/<E>/event.yaml` | www-data (admin page) | admin Save as Default Event |
| `events/<E>/tracker_history.yaml` | www-data (daemon) | daemon on first beacon |
| Course files (`*.gpx`, etc.) | www-data (upload) | admin upload or rsync |

To repair ownership after manual file operations:

```bash
sudo chown -R pi:www-data /var/www/html/events/
sudo chmod -R g+w /var/www/html/events/
```

---

### Display Pi

#### Services

All services start automatically at boot via systemd.

| Service | Port | Script | Purpose |
|---------|------|--------|---------|
| `x11vnc` | 5901 | — | VNC remote desktop (password: guacamole) |
| `kill-server` | 8080 (localhost) | `kill-server.py` | `GET /exit` kills Chromium |
| `stats-listener` | 1235 UDP | `StatsRequestListener.php` | Responds to health-check polls |
| `aprs-monitor` | — | `aprs-monitor.sh` | Polls `marsaprs.org` every 30 s; relaunches Chromium if unreachable |
| `wifi-watchdog` | — | `wifi-watchdog.sh` | Checks WiFi every 30 s; calls `wifi-restored.sh` on reconnect |
| `lightdm` | — | — | X11 display manager; required for Chromium and VNC |

#### Crontab

```
# MARS APRS Display Pi
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * * /home/pi/check-netbird.sh >> /tmp/checknetbird.log 2>&1
# Enable NetBird after any reboot
@reboot /home/pi/netbird-up.sh
# Nightly auto-update at 4:01am
1 4 * * * /home/pi/auto-update.sh >> /home/pi/update.log 2>&1
# Nightly reboot at 4:10am (after updates)
10 4 * * * sudo reboot
```

#### Kiosk Autostart

LXDE autostart (`/home/pi/.config/lxsession/rpd-x/autostart`) calls `~/start-kiosk.sh`
rather than launching Chromium directly:

```
@xrdb -merge ~/.Xresources
@/home/pi/start-kiosk.sh
```

`~/start-kiosk.sh` handles URL construction (auto-login), cursor size, and Chromium launch:

```bash
#!/bin/bash
URL="https://marsaprs.org/"
if [ -f ~/autologin.txt ]; then
    mapfile -t lines < ~/autologin.txt
    operator="${lines[0]:-}"
    URL="https://marsaprs.org/?autologin"
    if [ -n "$operator" ]; then
        enc_op=$(python3 -c "import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))" "$operator")
        URL="${URL}&operator=${enc_op}"
    fi
fi
export XCURSOR_SIZE=48
rm -f ~/.config/chromium/Singleton*
exec chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars \
    --disable-dev-shm-usage --incognito \
    --disable-features=BlockInsecurePrivateNetworkRequests \
    --user-data-dir=/tmp/chromium \
    "$URL"
```

#### Useful Commands

```bash
# Check all services
sudo systemctl status x11vnc kill-server stats-listener aprs-monitor wifi-watchdog

# Restart Chromium in kiosk mode
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority \
  chromium --password-store=basic --kiosk --noerrdialogs \
  --disable-infobars --disable-dev-shm-usage --incognito \
  --disable-features=BlockInsecurePrivateNetworkRequests \
  --user-data-dir=/tmp/chromium \
  'https://marsaprs.org/' &
```

**`add-wifi.php`** — Adds a WiFi network to `wifi.conf` on the local device. Accepts name, SSID, and password as arguments (or prompts interactively). Hashes the password via `wpa_passphrase` and appends the entry to `wifi.conf`, then calls `update-wifi.php` to apply it immediately. Available on all Pi types.

```bash
php /home/pi/add-wifi.php "Home Network" "MySSID" "MyPassword"
```

**Note:** The addition applies immediately but will be overwritten the next time `auto-update.sh` runs (nightly). Use the WiFi Manager web UI (`/wifi/`) to make permanent changes.

---

### iGate

#### Crontab

```
# Health watchdog: SDR + IP every minute, internet every 5 min
* * * * *    /home/pi/igate-watchdog.sh
# Nightly update at 4:01am
1 4 * * *    /home/pi/auto-update.sh
# Nightly reboot at 4:10am (after updates)
10 4 * * *   sudo reboot
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * *  /home/pi/check-netbird.sh
# Enable NetBird after any reboot
@reboot      /home/pi/netbird-up.sh
```

#### direwolf.conf Key Directives

| Directive | Example | Purpose |
|-----------|---------|---------|
| `MYCALL` | `MYCALL MARS-5` | Station callsign used on RF and APRS-IS |
| `IGLOGIN` | `IGLOGIN K6DRK 12345` | APRS-IS login callsign and passcode |
| `PBEACON` | `PBEACON lat=37.96 long=-122.54 comment="iGate 5.0 by K6DRK, Richmond CA"` | Position beacon |
| `FILTER` | `FILTER 0 IG t/p & ! d/*` | APRS-IS server-side packet filter |

Run `/home/pi/configure.sh` to set all of these interactively.

#### File Permissions

All iGate scripts live in `/home/pi/`, owned by `pi`. The direwolf log directory
(`/var/log/direwolf/`) is owned by `pi` for the watchdog to write to.

---

### Pi-Tools

These utility scripts live in their device source directories on the Mac
(`display/home/`, `display/systemd/`, `server/bin/`, `server/home/`,
`server/systemd/`) and are installed to `/home/pi/`, `/usr/local/bin/`, and
`/etc/systemd/system/` by `install.sh` as part of the standard device setup.

#### All Pis

**`add-wifi.php`** — Adds a WiFi network to the local `wifi.conf`. Takes name, SSID, and password as arguments or prompts interactively. Hashes the password with `wpa_passphrase`, appends the entry, and calls `update-wifi.php` to apply it. The change is immediate but will be overwritten by the next nightly `auto-update.sh` — use the WiFi Manager web UI for permanent additions.

**`wifi-watchdog.sh` / `wifi-watchdog.service`** — Generic WiFi watchdog. Checks
connectivity every 30 seconds; calls `/usr/local/bin/wifi-lost.sh` on loss and
`/usr/local/bin/wifi-restored.sh` on restore. `install.sh` installs and enables
both. To install manually after the script has been placed in `/home/pi/`:

```bash
sudo cp ~/wifi-watchdog.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/wifi-watchdog.sh
sudo systemctl daemon-reload && sudo systemctl enable --now wifi-watchdog
```

#### Display Pi

**`set-hostname.sh`** — Changes the hostname everywhere it needs to be set so the change
persists across reboots (updates `/boot/firmware/user-data`, `/etc/hostname`, `/etc/hosts`,
kernel UTS name, and `hostnamectl`).

```bash
sudo ~/set-hostname.sh <new-hostname>
exec $SHELL   # refresh the shell prompt
```

**`kill-server.py`** — Minimal Python HTTP server on `localhost:8080`. `GET /` returns
a "Connecting…" page that auto-redirects when `marsaprs.org` is reachable. `GET /exit`
kills Chromium. Used by the map's kiosk Exit button (via `window.location.href` to bypass
Chrome's Private Network Access preflight).

**`aprs-monitor.sh`** — Polls `marsaprs.org` every 30 seconds. If unreachable, kills
Chromium and relaunches it pointing to `localhost:8080`. When the site is reachable again,
the connecting page auto-redirects.

**`wifi-restored.sh`** (display Pi hook) — Called by `wifi-watchdog.sh` when WiFi is
restored. Kills Chromium and relaunches it via `localhost:8080`.

**`start-aprs.desktop`** — Desktop launcher; kills any running Chromium and relaunches
in kiosk mode. `install.sh` places it in `/home/pi/`; copy it to the Desktop manually:

```bash
cp ~/start-aprs.desktop ~/Desktop/start-aprs.desktop
chmod 644 ~/Desktop/start-aprs.desktop   # must NOT be executable on Trixie
```

On Pi OS Trixie, also suppress the "Executable Script" dialog system-wide:

```bash
mkdir -p ~/.config/libfm
printf '[config]\nquick_exec=1\n' > ~/.config/libfm/libfm.conf
```

#### Server Pi

**`wifi-restored.sh`** — Called by `wifi-watchdog.sh` when WiFi is restored on
aprs-pi. Restarts the APRS daemon to reconnect to APRS-IS on the new interface.

**`aprs-daemon.service` / `aprs-daemon.sh`** — Systemd service unit and wrapper script
for the APRS daemon. See [Running the Daemon](#running-the-daemon).

---

## Testing

A regression test suite covers the logic that is testable without live hardware: APRS parsers,
YAML config read/write, tracker history file I/O, and the pure JavaScript utility functions.
Hardware-dependent code (radio, SDR, GPIO, TFT display) is not tested here.

### Prerequisites

Install PHPUnit (PHP tests) and Jest (JavaScript tests) once:

```bash
cd ~/marsaprs/map
composer install                # installs PHPUnit into map/vendor/

cd tests/js
npm install                     # installs Jest into map/tests/js/node_modules/
```

Both tools are listed as dev dependencies (`composer.json` / `map/tests/js/package.json`) and
are excluded from the production server via `sync-to-pi.sh`.

### Running the PHP tests

From `map/`:

```bash
./vendor/bin/phpunit -c tests/phpunit.xml
```

Expected output: `OK (88 tests, 156 assertions)`

| Test class | What it covers |
|------------|----------------|
| `AprsParserTest` | `parseAprsPosition()` — uncompressed, Base91 compressed, and Mic-E position formats; malformed packets |
| `ConfigParseTest` | `parseConfigYaml()` and `yamlScalar()` — all config sections, edge cases, missing files |
| `YamlLibTest` | `yaml_lib.php` — `yamlVal`, `yamlStr`, `loadDevices`/`saveDevices` roundtrip |
| `TrackerHistoryTest` | `readTrackerHistoryFile()` / `writeTrackerHistoryFile()` — roundtrip, 10-entry cap, missing file |
| `AdminConfigTest` | `buildConfigYaml()` → `parseConfigYaml()` roundtrip — all sections, special chars, booleans |

Test files live in `map/tests/php/`. Fixtures (sample YAML files) are in `map/tests/fixtures/`.

### Running the JavaScript tests

From `map/tests/js/`:

```bash
npx jest
```

Expected output: `Tests: 59 passed, 59 total`

| Function tested | Cases |
|-----------------|-------|
| `esc(s)` | All five HTML special characters; null/undefined coerced to `''` |
| `relativeTime(ts)` | "just now", seconds, minutes, hours; boundary conditions at 10 s, 60 s, 3600 s |
| `haversineDistance()` | Same point = 0; SF→LA; SF→NYC; pole-to-pole |
| `bearingTo()` | Due N/S/E/W; result always in [0°, 360°) |
| `compassDir()` | All 16 cardinal/intercardinal points; 359° wraps to N |
| `formatAprsPath()` | Q-code expansion; digipeated hops; unknown hops; HTML escaping; empty/null path |

The JS functions (`esc`, `relativeTime`, `haversineDistance`, `bearingTo`, `compassDir`,
`Q_LABELS`, `formatAprsPath`) live in `map/utils.js` and are loaded by `map/index.php`
via `<script src="utils.js">`. The `module.exports` shim at the bottom of `utils.js`
allows Jest to import them in Node without a browser.

### Scope

| In scope | Out of scope |
|----------|-------------|
| APRS position parsers | APRS-IS socket connection |
| YAML config read/write | iGate (RTL-SDR, direwolf, GPIO) |
| Tracker history file I/O | Display Pi (TFT screen) |
| Admin config serialiser/parser roundtrip | Leaflet map UI interactions |
| Pure JS utility functions | — |
