import 'dart:async';
import 'dart:io' show Platform;
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'aprs_client.dart';
import 'background_task_handler.dart';
import 'map_config.dart';
import 'mobile_session.dart';

export 'mobile_session.dart' show JoinResult;

class BackgroundLocationService {
  final MobileSession _session = MobileSession();

  StreamSubscription<Position>? _positionSub;
  Timer? _heartbeatTimer; // main-isolate timer: session heartbeat on Android
  LatLng? _lastPosition;
  DateTime? _lastStreamUpload; // iOS: throttle for stream-based uploads
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
              activityType: ActivityType.other,
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
          FlutterForegroundTask.sendDataToTask({
            'type': 'position',
            'lat': pos.latitude,
            'lon': pos.longitude,
          });
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
    _lastStreamUpload = null;
  }

  // iOS only: called on every GPS event; uploads at most once per _uploadInterval.
  Future<void> _maybeUploadFromStream() async {
    if (!_sharingActive) return;
    final now = DateTime.now();
    if (_lastStreamUpload != null &&
        now.difference(_lastStreamUpload!) < _uploadInterval) return;
    _lastStreamUpload = now;
    await _uploadNow();
  }

  Future<JoinResult> startSharing({
    required String name,
    required String pin,
    Duration interval = MapConfig.uploadInterval,
  }) async {
    _uploadInterval = interval;
    final result = await _session.join(name: name, pin: pin);
    if (result == JoinResult.success) {
      _sharingActive = true;
      if (Platform.isAndroid) {
        await _startAndroidForegroundTask();
      } else {
        // iOS: immediate first beacon, then both the timer (foreground) and
        // the GPS stream (background) drive uploads via _maybeUploadFromStream.
        unawaited(_uploadImmediately());
        _heartbeatTimer = Timer.periodic(
          _uploadInterval,
          (_) => unawaited(_maybeUploadFromStream()),
        );
      }
    }
    return result;
  }

  Future<void> stopSharing() async {
    _sharingActive = false;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    if (Platform.isAndroid) {
      await FlutterForegroundTask.stopService();
    }
    await _session.leave();
    stopTracking();
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

    // Pass session credentials to background isolate.
    FlutterForegroundTask.sendDataToTask({
      'type': 'session',
      'callsign': _session.callsign ?? '',
      'passcode': _session.passcode,
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
    final cs = _session.callsign;
    final pc = _session.passcode;

    if (pos != null && cs != null && pc != null) {
      await AprsClient.sendPosition(
        callsign: cs,
        passcode: pc,
        lat: pos.latitude,
        lon: pos.longitude,
      );
    }

    // Session heartbeat — also detects removal (404).
    final ok = await _session.update();
    if (!ok && _sharingActive) {
      _sharingActive = false;
      _heartbeatTimer?.cancel();
      _heartbeatTimer = null;
      if (Platform.isAndroid) await FlutterForegroundTask.stopService();
      await _session.leave();
      stopTracking();
      onSessionEnded?.call();
    }
  }

  void dispose() {
    stopSharing();
    _positionController.close();
  }
}
