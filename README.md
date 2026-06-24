# APRS Map — iOS & Android App

**Author:** Doug Kaye (K6DRK) · **Copyright:** 2026 Doug Kaye. All Rights Reserved.

**Version:** 1.16.3+1

A Flutter iOS and Android application for the MARS APRS system. Displays live tracker positions from the marsaprs.org server and supports mobile location sharing over APRS-IS.

## Features

- Live tracker map polling `marsaprs.org` every 5 seconds
- Native sidebar drawer with collapsible sections: Trackers, Courses, Aid Stations, iGates, Backgrounds, About
- **About section** — shows Organization, Application version (loaded at runtime from `package_info_plus`), Event, assigned Callsign, Map Data link, and Copyright
- **Share Location** — broadcasts GPS position to the APRS network via APRS-IS; activity type (Walk/Run = 60 s, Drive/Cycle = 15 s) sets the upload interval
- **Auto-resume sharing** — if the app is closed and reopened while sharing was active, sharing resumes automatically with the same callsign
- **Distance-triggered beaconing** — sends an immediate beacon when the device moves ≥ 0.1 miles (resets the upload timer); in addition to the normal timed interval
- **Background location** — continues reporting when the screen locks (foreground service on Android; Always permission on iOS)
- **Live breadcrumb colors** — trail color matches the tracker's current staleness color (green/blue/red) and updates in real time
- Mobile tracker markers displayed as rounded squares; fixed trackers displayed as circles
- **Tracker ID labels** shown next to each map marker for at-a-glance identification
- Tap tracker in drawer to show breadcrumb trail (dashed line with directional arrows, auto-refreshes as tracker moves); long-press to also zoom
- **Save Map** — saves current map position and zoom as personal default
- **Exit** button (iOS/Android) to close the app from the drawer footer

## Architecture

| Component | File | Purpose |
|-----------|------|---------|
| Map screen | `lib/map_screen.dart` | WebView host; JS bridge; native tracker/breadcrumb overlay |
| Menu drawer | `lib/menu_drawer.dart` | Native Flutter sidebar; Save Map + Exit buttons |
| Remote config | `lib/remote_config.dart` | Polls `?config` endpoint |
| Tracker layer | `lib/tracker_layer.dart` | Native map markers with ID labels (square for mobile, circle for fixed) |
| Arrow painter | `lib/arrow_painter.dart` | CustomPainter for breadcrumb directional arrows |
| Course layer | `lib/course_layer.dart` | Course polyline overlay; parallel fetch |
| Background location | `lib/background_location.dart` | `geolocator` stream; timer + distance-triggered uploads |
| Background task handler | `lib/background_task_handler.dart` | Android foreground task; timer-skip guard after distance uploads |
| Mobile session | `lib/mobile_session.dart` | `?mobile=join/update/leave` API calls |
| APRS client | `lib/aprs_client.dart` | TCP socket → `noam.aprs2.net:14580` |
| Map config | `lib/map_config.dart` | Constants (server URL) |

## Location Sharing Flow

```
User taps Share Location
  → _ensureBackgroundPermissions()  (requests notification + battery opt. on Android;
                                     upgrades to Always location on iOS)
  → User selects activity: Walk/Run (60 s interval) or Drive/Cycle (15 s interval)
  → MobileSession.join()  (POST ?mobile=join with device_id)
      ← token + callsign (e.g. K6DRK-01) — same callsign reused for this device
  → BackgroundLocationService.startTracking()  (geolocator stream)
  → Upload triggered by timer OR distance (≥ 0.1 mi since last upload):
      iOS:  every GPS event → _maybeUploadFromStream() checks elapsed time or distance
      Android: onRepeatEvent() checks timer; GPS listener sends 'force_upload' on distance
      → AprsClient.sendPosition()  (TCP APRS-IS packet)
      → MobileSession.update()     (POST ?mobile=update — heartbeat)
  → aprsDaemon picks up beacon; skips breadcrumb if position < 100 ft from last
  → Map shows K6DRK-01 like any other tracker (rounded square marker)

App restart while sharing was active:
  → resumeSharing() reads SharedPreferences (token, callsign, interval)
  → Attempts token reuse via MobileSession.update(); if stale, re-joins silently
  → Sharing resumes with same callsign; snackbar notifies user
```

## Device Identity and Callsign Persistence

Each app installation generates a stable random `device_id` (stored in `SharedPreferences`) that is sent on every join. The server uses this to reuse the same APRS callsign slot (e.g. `K6DRK-01`) across stop/start and app restarts — the slot is kept for 30 days after the last session. Uninstalling the app generates a new `device_id` on reinstall, assigning a fresh slot.

Anonymous sessions (web map, older app builds without `device_id`) are deleted immediately on leave and their slot freed.

## Background Location

### Android

Requires the following permissions in `AndroidManifest.xml`:
- `INTERNET` — required for any network access (not injected automatically in release builds)
- `ACCESS_FINE_LOCATION`, `ACCESS_BACKGROUND_LOCATION`
- `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`
- `POST_NOTIFICATIONS` — required on Android 13+ for the foreground service notification to appear; without it Android will kill the service when the screen locks
- `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` — allows prompting the user to exempt the app from battery killing

The `GeolocatorLocationService` runs as a foreground service, keeping the CPU awake while sharing. A persistent notification ("APRS Map — Sharing your location") confirms it is running. Verify it is active: wake the screen and swipe down from the top.

### iOS

Requires `UIBackgroundModes: location` in `Info.plist` and `allowBackgroundLocationUpdates: true` on the location manager. The app prompts for "Always" location permission when sharing starts; "While Using" is not sufficient for background updates.

Because `Timer.periodic` does not fire when the Dart isolate is suspended, iOS uploads are triggered from the GPS stream callback (`_maybeUploadFromStream()`). The callback uploads whenever the configured interval has elapsed **or** the device has moved ≥ 0.1 miles since the last upload, then resets both the timestamp and the reference position. After a distance-triggered upload the timer effectively resets, preventing a duplicate beacon a few seconds later.

## Building

### iOS

Requires Flutter 3.x and Xcode 15+.

```bash
flutter pub get
flutter build ios --release --no-codesign
```

Open `ios/Runner.xcworkspace` in Xcode to archive and distribute via TestFlight or App Store.

### Android

```bash
flutter pub get
flutter build apk --release       # unsigned APK
flutter build apk --release       # signed APK (if key.properties exists)
```

**Release signing:** Create `android/key.properties` (gitignored):

```
storePassword=<password>
keyPassword=<password>
keyAlias=<alias>
storeFile=<path to .jks or .p12>
```

`android/app/build.gradle.kts` reads this file automatically. If it is absent, the release build falls back to debug signing.

**`compileSdk` note:** If `objectbox_flutter_libs` in `~/.pub-cache` hardcodes `compileSdkVersion 31`, patch it to `36` (or match `build.gradle.kts`).

## Server

The companion server is at `/Users/doug/marsaprs/map/`. See `marsaprs/map/README.MD` for full technical documentation.
