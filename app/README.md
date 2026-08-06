# APRS Map — iOS & Android App

**Author:** Doug Kaye (K6DRK) · **Copyright:** 2026 Doug Kaye. All Rights Reserved.

**Version:** 1.21.1+14 · August 4, 2026

A Flutter iOS and Android application for the MARS APRS system. Displays live tracker positions from the marsaprs.org server and supports mobile location sharing over APRS-IS.

## Features

- Live tracker map polling `marsaprs.org` every 5 seconds
- Native sidebar drawer with collapsible sections: Trackers, Courses, Aid Stations, iGates, Backgrounds
- **Share Location** — broadcasts GPS position to the APRS network via the MARS server; enter name (pre-filled from last session) and PIN, then tap an activity chip to start sharing immediately; button shows **Sharing** (green) while active and opens a mode-change/stop panel when tapped; activity type (Walk/Run = 60 s · Cycle = 30 s · Drive = 15 s · Stationary = 2 min) sets the upload interval; all modes also trigger an immediate upload when the device moves ≥ the configured distance threshold; mode can be switched without stopping the session
- **Auto-resume sharing** — if the app is closed and reopened while sharing was active, sharing resumes automatically with the same callsign
- **Distance-triggered beaconing** — sends an immediate beacon when the device moves ≥ the configured distance threshold (default 0.2 miles for Walk/Run, Cycle, and Drive; 1.0 mile for Stationary); resets the upload timer
- **Background location** — continues reporting when the screen locks (foreground service on Android; Always permission on iOS)
- **Two-way messaging** — while sharing, receive messages from net control operators; incoming messages play a sound and show a pop-up dialog; reply from the notification or tap the Message button in the drawer footer to compose; message history auto-scrolls to the most recent message
- **Live breadcrumb colors** — trail color matches the tracker's current staleness color (green/blue/red) and updates in real time
- Mobile tracker markers displayed as rounded squares; fixed trackers displayed as circles
- **Tracker ID labels** shown next to each map marker for at-a-glance identification
- Tap tracker in drawer to show breadcrumb trail (dashed line with directional arrows, auto-refreshes as tracker moves); long-press to also zoom
- **Save Map** — saves current map position and zoom as personal default
- **Help** button in drawer footer opens a modal with app info (organization, version, event, callsign, map attribution, copyright) and buttons for Quick Start guide, User Guide, and bug/suggestion tickets
- **Exit** button (iOS/Android) to close the app from the drawer footer

## Architecture

| Component | File | Purpose |
|-----------|------|---------|
| Map screen | `lib/map_screen.dart` | WebView host; JS bridge; native tracker/breadcrumb overlay |
| Menu drawer | `lib/menu_drawer.dart` | Native Flutter sidebar; Help modal (app info + Quick Start/User Guide/ticket buttons); Save Map + Exit buttons |
| Quick Start | `lib/help_screen.dart` | Built-in Quick Start guide; displayed on first launch; accessible via Help → Quick Start |
| Remote config | `lib/remote_config.dart` | Polls `?config` endpoint |
| Tracker layer | `lib/tracker_layer.dart` | Native map markers with ID labels (square for mobile, circle for fixed) |
| Arrow painter | `lib/arrow_painter.dart` | CustomPainter for breadcrumb directional arrows |
| Course layer | `lib/course_layer.dart` | Course polyline overlay; parallel fetch |
| Background location | `lib/background_location.dart` | `geolocator` stream; timer + distance-triggered uploads; `_deliveredMsgIds` Set deduplicates messages from concurrent upload and poll paths |
| Background task handler | `lib/background_task_handler.dart` | Android foreground service stub; keeps process alive; all beaconing handled by main isolate |
| Mobile session | `lib/mobile_session.dart` | `?mobile=join/update/leave/message/poll/msghistory` API calls; `InboundMessage` class |
| APRS client | `lib/aprs_client.dart` | Legacy — TCP socket to APRS-IS; no longer used (server-side injection replaced direct TCP) |
| Map config | `lib/map_config.dart` | Constants (server URL) |

## Location Sharing Flow

```
User taps Share Location
  → _ensureBackgroundPermissions()     (Android: notification + battery opt;
                                        iOS: verify Always location permission)
  → User selects activity: Walk/Run (60 s) · Cycle (30 s) · Drive (15 s) · Stationary (2 min)
  → MobileSession.join()               POST ?mobile=join {name, pin, sharing_mode}
      ← {token, callsign, passcode}    e.g. callsign=K6DRK-01
  → BackgroundLocationService.startTracking()
      → Geolocator.getPositionStream()
  → Android only: FlutterForegroundTask.startService() — notification only, no-op handler
  → Upload triggered by _heartbeatTimer (timed interval) OR GPS event (distance ≥ threshold):
      Both platforms:
        _heartbeatTimer fires at configured interval
        _maybeUploadFromStream() on every GPS event (uploads if moved ≥ threshold OR time elapsed)
        → MobileSession.update(lat, lon)    POST ?mobile=update {token, lat, lon}
              ← 200 ok  (or 404 → stops sharing)
              → server injectAprsPacket() via local TCP 14580
  → aprsDaemon picks up beacon; skips breadcrumb if position < 100 ft from last
  → Map shows K6DRK-01 like any other tracker (rounded square marker)

App restart while sharing was active:
  → resumeSharing() reads SharedPreferences (token, callsign, interval, activity mode)
  → Attempts token reuse via MobileSession.update(); if stale, re-joins silently
  → Sharing resumes with same callsign and activity mode; snackbar notifies user
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
- `USE_FULL_SCREEN_INTENT` — allows full-screen notifications on incoming messages (Android 14+ requires runtime grant via `requestFullScreenIntentPermission()`)
- `VIBRATE` — vibration pattern on incoming message notifications
- `SYSTEM_ALERT_WINDOW` — "Draw over other apps"; when granted, `FlutterForegroundTask.launchApp()` can bring the app to the foreground on an incoming message; the app prompts the user to grant this on first launch

Two foreground services run while sharing: `GeolocatorLocationService` (from the `geolocator` package) keeps the GPS stream and Dart isolate alive; `FlutterForegroundTask` provides a persistent notification and prevents Android from killing the process. The `FlutterForegroundTask` handler (`background_task_handler.dart`) is a no-op stub — all beaconing is done by the main isolate via `_heartbeatTimer` and `_maybeUploadFromStream()`. A persistent notification ("APRS Map — Sharing your location") confirms the service is running; verify by waking the screen and swiping down from the top.

### iOS

Requires `UIBackgroundModes: [location, audio]` in `Info.plist` and `allowBackgroundLocationUpdates: true` on the location manager. The `audio` mode is required for the message tone to play when the app is backgrounded. The app prompts for "Always" location permission when sharing starts; "While Using" is not sufficient for background updates.

When sharing starts, `_primeAudioSession()` configures and activates the `AVAudioSession` (category `.playback`, mode `.default`, option `.duckOthers`) while the app is still in the foreground. iOS requires the session to have been activated in the foreground at least once before it will permit background audio playback.

Both iOS and Android use the same `_heartbeatTimer` for timed uploads and `_maybeUploadFromStream()` for distance-triggered uploads from the GPS event stream. On iOS, the GPS stream keeps the Dart isolate continuously alive between wakeups, so `Timer.periodic` fires reliably without additional workarounds.

**Why both platforms use server-side APRS injection:** Both iOS and Android POST position data to `?mobile=update` rather than sending raw TCP packets directly to APRS-IS. On iOS, `NSURLSession`-based HTTP is explicitly supported for background network tasks while raw `dart:io Socket` TCP is not reliable in background. Unifying Android to the same path keeps beaconing logic in the main isolate, eliminates the Android background task handler, and simplifies the overall architecture.

## Messaging

While sharing location, the app supports bidirectional text messaging with web operators.

### Receiving Messages

The `BackgroundLocationService` can receive messages via two concurrent paths:

- `_pollMessages()` — polls `?mobile=poll` every 30 seconds
- `_uploadNow()` — pending messages arrive in the `?mobile=update` response body on each beacon

Both paths may receive the same message before either sends an acknowledgement. `_deliveredMsgIds` (a `Set<int>` in `background_location.dart`) deduplicates across both paths:

```dart
if (_deliveredMsgIds.add(m.id)) onMessageReceived?.call(m);
```

`onMessageReceived` is wired in `map_screen.dart` to `_handleInboundMessage()`, which:

1. Plays the message tone **3×** back-to-back (via `ConcatenatingAudioSource`) on the alarm audio stream to bypass silent mode (Android) or the playback stream (iOS).
2. If the app is **foregrounded**: shows an `AlertDialog` immediately with the sender's name, message text, and **Reply** / **Close** actions.
3. If the app is **backgrounded**:
   - Queues the message in `_pendingMessages`.
   - **Android**: posts a full-screen `flutter_local_notifications` notification and, if `SYSTEM_ALERT_WINDOW` permission is granted, calls `FlutterForegroundTask.launchApp()` to bring the app to the foreground.
   - **iOS**: posts a Time Sensitive local notification; tapping it brings the app to the foreground.
   - On resume, `didChangeAppLifecycleState` flushes `_pendingMessages` via `addPostFrameCallback`, showing dialogs in order once the navigator is settled.

### Sending Messages

The **Message** button (chat icon) in the drawer footer, shown while sharing is active, opens `_showSendMessageDialog()`. This dialog renders the last 10 messages as a scrollable thread (gray box, auto-scrolled to bottom) above a `Divider` and a 4-line `TextField`. Sending POSTs `{token, text}` to `?mobile=message`.

Outgoing messages are acknowledged to the server via `ack_ids` on the next `?mobile=update` or `?mobile=poll` call, removing them from the server's pending queue.

---

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

The companion server is at `../map/`. See `../map/README.MD` for full technical documentation.
