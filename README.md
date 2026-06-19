# APRS Map — iOS App

**Author:** Doug Kaye (K6DRK) · **Copyright:** 2026 Doug Kaye. All Rights Reserved.

A Flutter iOS application for the MARS APRS system. Displays live tracker positions from the marsaprs.org server and supports mobile location sharing over APRS-IS.

## Features

- Live tracker map (Leaflet-based web view polling `marsaprs.org` every 5 seconds)
- Sidebar with collapsible sections: Trackers, Courses, Aid Stations, iGates, Backgrounds
- Share Location — broadcasts GPS position to the APRS network via APRS-IS
- Background location updates with Wake Lock to keep the screen on while sharing
- Tap tracker to blink and show breadcrumb history; long-press to zoom
- Offline tile caching via tile refresh

## Architecture

The app is a Flutter wrapper around the `marsaprs.org` web UI (`index.php`), rendered in a `WebView`. Native code handles:

| Component | File | Purpose |
|-----------|------|---------|
| Map screen | `lib/map_screen.dart` | WebView host; JS bridge for sharing state |
| Menu drawer | `lib/menu_drawer.dart` | Native Flutter sidebar |
| Remote config | `lib/remote_config.dart` | Polls `?config` endpoint |
| Background location | `lib/background_location.dart` | `geolocator` stream + upload timer |
| Mobile session | `lib/mobile_session.dart` | `?mobile=join/update/leave` API calls |
| APRS client | `lib/aprs_client.dart` | TCP socket → `noam.aprs2.net:14580` |
| Map config | `lib/map_config.dart` | Constants (upload interval, server URL) |

## Location Sharing Flow

```
User taps Share Location
  → MobileSession.join()  (POST ?mobile=join)
      ← token + callsign (e.g. K6DRK-01)
  → BackgroundLocationService.startTracking()  (geolocator stream)
  → Upload timer fires every 60 s
      → AprsClient.sendPosition()  (TCP APRS-IS packet)
      → MobileSession.update()     (POST ?mobile=update — heartbeat)
  → aprsDaemon picks up beacon from APRS-IS → trackers.json
  → Map shows K6DRK-01 like any other tracker
```

## Building

Requires Flutter 3.x and Xcode 15+.

```bash
flutter pub get
flutter build ios
```

Open `ios/Runner.xcworkspace` in Xcode to archive and distribute.

## Server

The companion server is at `/Users/doug/marsaprs/map/`. See `marsaprs/map/README.MD` for full technical documentation.
