import 'dart:async';
import 'dart:io' show Platform;
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'aprs_client.dart';
import 'background_task_handler.dart';
import 'map_config.dart';
import 'mobile_session.dart';

export 'mobile_session.dart' show JoinResult;

class BackgroundLocationService {
  // 0.1 miles in metres — triggers an immediate beacon when exceeded.
  static const _kDistanceTriggerM = 160.934;

  // SharedPreferences keys for session persistence across app restarts.
  static const _kPrefActive = 'sharing_active';
  static const _kPrefCallsign = 'sharing_callsign';
  static const _kPrefPasscode = 'sharing_passcode';
  static const _kPrefToken = 'sharing_token';
  static const _kPrefTrackerId = 'sharing_tracker_id';
  static const _kPrefIntervalMs = 'sharing_interval_ms';
  static const _kPrefName = 'sharing_name';
  static const _kPrefPin = 'sharing_pin';

  final MobileSession _session = MobileSession();

  StreamSubscription<Position>? _positionSub;
  Timer? _heartbeatTimer;
  LatLng? _lastPosition;
  LatLng? _lastUploadPosition;
  DateTime? _lastUploadTime;
  bool _sharingActive = false;

  Duration _uploadInterval = MapConfig.uploadInterval;

  bool get isSharing => _session.active;
  String? get trackerId => _session.trackerId;
  String? get callsign => _session.callsign;

  void Function()? onSessionEnded;

  final _positionController = StreamController<Position>.broadcast();
  Stream<Position> get positionStream => _positionController.stream;

  Future<bool> startTracking() async {
    if (_positionSub != null) return true;
    try {
      final settings = Platform.isIOS
          ? AppleSettings(
              accuracy: LocationAccuracy.best,
              distanceFilter: 0,
              activityType: ActivityType.fitness,
              allowBackgroundLocationUpdates: true,
              pauseLocationUpdatesAutomatically: false,
              showBackgroundLocationIndicator: true,
            )
          : AndroidSettings(
              accuracy: LocationAccuracy.best,
              distanceFilter: 5,
            );

      _positionSub = Geolocator.getPositionStream(locationSettings: settings)
          .listen((pos) {
        _lastPosition = LatLng(pos.latitude, pos.longitude);
        _positionController.add(pos);
        if (Platform.isIOS) {
          unawaited(_maybeUploadFromStream());
        } else if (_sharingActive) {
          final cur = LatLng(pos.latitude, pos.longitude);
          FlutterForegroundTask.sendDataToTask({
            'type': 'position',
            'lat': pos.latitude,
            'lon': pos.longitude,
          });
          // Distance-triggered immediate beacon: moved >= 0.1 miles since last upload.
          final lp = _lastUploadPosition;
          if (lp == null || _metersFrom(lp, cur) >= _kDistanceTriggerM) {
            _lastUploadPosition = cur;
            FlutterForegroundTask.sendDataToTask({'type': 'force_upload'});
          }
        }
      });
      return true;
    } catch (_) {
      return false;
    }
  }

  void stopTracking() {
    _positionSub?.cancel();
    _positionSub = null;
    _lastPosition = null;
    _lastUploadPosition = null;
    _lastUploadTime = null;
  }

  // iOS only: called on every GPS event. Uploads when moved >= 0.1 miles OR
  // >= 60 seconds have passed since the last beacon. This is the primary
  // keepalive mechanism on iOS — Timer.periodic is unreliable when the Dart
  // isolate is suspended, but GPS events wake the isolate.
  Future<void> _maybeUploadFromStream() async {
    if (!_sharingActive) return;
    final pos = _lastPosition;
    if (pos == null) return;

    final lp = _lastUploadPosition;
    final lu = _lastUploadTime;
    final movedEnough = lp == null || _metersFrom(lp, pos) >= _kDistanceTriggerM;
    final timeElapsed = lu == null ||
        DateTime.now().difference(lu).inSeconds >= 60;

    if (!movedEnough && !timeElapsed) return;
    await _uploadNow();
  }

  double _metersFrom(LatLng a, LatLng b) => Geolocator.distanceBetween(
      a.latitude, a.longitude, b.latitude, b.longitude);

  Future<JoinResult> startSharing({
    required String name,
    required String pin,
    Duration interval = MapConfig.uploadInterval,
  }) async {
    _uploadInterval = interval;
    final result = await _session.join(name: name, pin: pin);
    if (result == JoinResult.success) {
      unawaited(_saveSession(name, pin));
      await _activateSharing();
    }
    return result;
  }

  /// Tries to resume a previously active session on app startup.
  /// Attempts token reuse first; if the token is stale, re-joins silently
  /// with the saved name+PIN (which should return the same callsign via
  /// device_id).  Returns true if sharing is successfully resumed.
  Future<bool> resumeSharing() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_kPrefActive) != true) return false;

    final callsign  = prefs.getString(_kPrefCallsign) ?? '';
    final passcode  = prefs.getInt(_kPrefPasscode) ?? 0;
    final token     = prefs.getString(_kPrefToken) ?? '';
    final trackerId = prefs.getString(_kPrefTrackerId);
    final intervalMs = prefs.getInt(_kPrefIntervalMs) ?? MapConfig.uploadInterval.inMilliseconds;
    final name = prefs.getString(_kPrefName) ?? '';
    final pin  = prefs.getString(_kPrefPin) ?? '';

    if (callsign.isEmpty || passcode == 0) {
      unawaited(_clearSession());
      return false;
    }

    _uploadInterval = Duration(milliseconds: intervalMs);

    // Try the saved token first — cheapest path, no re-auth needed.
    if (token.isNotEmpty) {
      _session.restoreToken(
        token: token, callsign: callsign, passcode: passcode, trackerId: trackerId,
      );
      final ok = await _session.update();
      if (ok) {
        await _activateSharing();
        return true;
      }
      _session.clearToken();
    }

    // Token expired — re-join silently with saved credentials.
    if (name.isNotEmpty && pin.isNotEmpty) {
      final result = await _session.join(name: name, pin: pin);
      if (result == JoinResult.success) {
        unawaited(_saveSession(name, pin));
        await _activateSharing();
        return true;
      }
    }

    // Could not resume; clear saved state so the next start is clean.
    unawaited(_clearSession());
    return false;
  }

  /// Starts beacon timers/foreground-task once _session is already populated.
  Future<void> _activateSharing() async {
    _sharingActive = true;
    if (Platform.isAndroid) {
      await _startAndroidForegroundTask();
    } else {
      unawaited(_uploadImmediately());
      // Guaranteed 60-second keepalive: sends an APRS beacon unconditionally
      // so the tracker never goes stale when the device is stationary.
      _heartbeatTimer = Timer.periodic(
        const Duration(seconds: 60),
        (_) async { if (_sharingActive) await _uploadNow(); },
      );
    }
  }

  Future<void> _saveSession(String name, String pin) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPrefActive, true);
    await prefs.setString(_kPrefCallsign, _session.callsign ?? '');
    await prefs.setInt(_kPrefPasscode, _session.passcode ?? 0);
    await prefs.setString(_kPrefToken, _session.token ?? '');
    await prefs.setString(_kPrefTrackerId, _session.trackerId ?? '');
    await prefs.setInt(_kPrefIntervalMs, _uploadInterval.inMilliseconds);
    await prefs.setString(_kPrefName, name);
    await prefs.setString(_kPrefPin, pin);
  }

  Future<void> _clearSession() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPrefActive, false);
  }

  Future<void> stopSharing() async {
    _sharingActive = false;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    if (Platform.isAndroid) {
      await FlutterForegroundTask.stopService();
    }
    await _session.leave();
    unawaited(_clearSession());
    // GPS stream stays alive so the map's blue dot keeps working.
    // Actual stream teardown happens in dispose().
  }

  // ── Android: foreground task + main-isolate heartbeat ────────────────────

  Future<void> _startAndroidForegroundTask() async {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: 'aprs_map_location',
        channelName: 'APRS Map Location',
        channelDescription: 'Sharing your location with MARS APRS',
        onlyAlertOnce: true,
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        eventAction: ForegroundTaskEventAction.repeat(
          _uploadInterval.inMilliseconds,
        ),
        allowWakeLock: true,
        autoRunOnBoot: false,
      ),
    );

    await FlutterForegroundTask.startService(
      serviceTypes: [ForegroundServiceTypes.location],
      serviceId: 256,
      notificationTitle: 'APRS Map',
      notificationText: 'Sharing your location',
      callback: backgroundTaskEntryPoint,
    );

    // Pass session credentials and upload interval to background isolate.
    FlutterForegroundTask.sendDataToTask({
      'type': 'session',
      'callsign': _session.callsign ?? '',
      'passcode': _session.passcode,
      'intervalMs': _uploadInterval.inMilliseconds,
    });

    // Pass current position if already known.
    final pos = _lastPosition;
    if (pos != null) {
      FlutterForegroundTask.sendDataToTask({
        'type': 'position',
        'lat': pos.latitude,
        'lon': pos.longitude,
      });
    }

    // Send one immediate beacon from main isolate too (fast first report).
    unawaited(_uploadImmediately());

    // Periodic heartbeat so the server knows the session is alive.
    // flutter_foreground_task keeps the main Dart isolate running on Android.
    _heartbeatTimer = Timer.periodic(_uploadInterval, (_) async {
      final ok = await _session.update();
      if (!ok && _sharingActive) {
        _sharingActive = false;
        _heartbeatTimer?.cancel();
        _heartbeatTimer = null;
        await FlutterForegroundTask.stopService();
        await _session.leave();
        stopTracking();
        onSessionEnded?.call();
      }
    });
  }

  // ── Shared upload helpers ─────────────────────────────────────────────────

  // Sends the first beacon as soon as a GPS position is available.
  Future<void> _uploadImmediately() async {
    if (_lastPosition == null) {
      try {
        await _positionController.stream.first
            .timeout(const Duration(seconds: 5));
      } catch (_) {
        // Live fix timed out — fall back to OS-cached last position so the
        // first beacon isn't delayed while GPS acquires a fresh satellite fix.
        final cached = await Geolocator.getLastKnownPosition();
        if (cached != null) {
          _lastPosition = LatLng(cached.latitude, cached.longitude);
        }
      }
    }
    await _uploadNow();
  }

  /// Call when network connectivity is restored while sharing is active.
  void triggerUpload() {
    if (_sharingActive) unawaited(_uploadNow());
  }

  Future<void> _uploadNow() async {
    final pos = _lastPosition;
    if (pos != null) _lastUploadPosition = pos;
    _lastUploadTime = DateTime.now();
    final cs = _session.callsign;
    final pc = _session.passcode;

    if (pos != null && cs != null && pc != null) {
      if (Platform.isAndroid) {
        // Android: direct TCP to APRS-IS works fine in foreground service.
        await AprsClient.sendPosition(
          callsign: cs,
          passcode: pc,
          lat: pos.latitude,
          lon: pos.longitude,
        );
      }
      // iOS: raw TCP sockets are blocked in background; lat/lon is passed
      // in the session update below so the server injects to APRS-IS.
    }

    // Session heartbeat — also detects removal (404).
    // On iOS, include position so the server can inject to APRS-IS.
    final ok = await _session.update(
      lat: Platform.isIOS ? pos?.latitude : null,
      lon: Platform.isIOS ? pos?.longitude : null,
    );
    if (!ok && _sharingActive) {
      _sharingActive = false;
      _heartbeatTimer?.cancel();
      _heartbeatTimer = null;
      if (Platform.isAndroid) await FlutterForegroundTask.stopService();
      await _session.leave();
      onSessionEnded?.call();
    }
  }

  void dispose() {
    stopSharing();
    stopTracking();
    _positionController.close();
  }
}
