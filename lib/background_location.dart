import 'dart:async';
import 'dart:io' show Platform;
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'aprs_client.dart';
import 'map_config.dart';
import 'mobile_session.dart';

export 'mobile_session.dart' show JoinResult;

class BackgroundLocationService {
  final MobileSession _session = MobileSession();

  StreamSubscription<Position>? _positionSub;
  Timer? _uploadTimer;
  LatLng? _lastPosition;

  bool get isSharing => _session.active;
  String? get trackerId => _session.trackerId;
  String? get callsign => _session.callsign;

  /// Called when the server reports the session no longer exists (404 on update).
  void Function()? onSessionEnded;

  final _positionController = StreamController<Position>.broadcast();
  Stream<Position> get positionStream => _positionController.stream;

  Future<bool> startTracking() async {
    if (_positionSub != null) return true;
    try {
      final settings = Platform.isIOS
          ? AppleSettings(
              accuracy: LocationAccuracy.best,
              distanceFilter: 5,
              activityType: ActivityType.fitness,
              allowBackgroundLocationUpdates: true,
              pauseLocationUpdatesAutomatically: false,
              showBackgroundLocationIndicator: true,
            )
          : AndroidSettings(
              accuracy: LocationAccuracy.best,
              distanceFilter: 5,
              foregroundNotificationConfig: const ForegroundNotificationConfig(
                notificationTitle: 'APRS Map',
                notificationText: 'Sharing your location',
                enableWakeLock: true,
              ),
            );

      _positionSub = Geolocator.getPositionStream(locationSettings: settings)
          .listen((pos) {
        _lastPosition = LatLng(pos.latitude, pos.longitude);
        _positionController.add(pos);
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
  }

  Future<JoinResult> startSharing({required String name, required String pin}) async {
    final result = await _session.join(name: name, pin: pin);
    if (result == JoinResult.success) _startUploadTimer();
    return result;
  }

  Future<void> stopSharing() async {
    _uploadTimer?.cancel();
    _uploadTimer = null;
    await _session.leave();
  }

  void _startUploadTimer() {
    _uploadTimer?.cancel();
    _uploadNow();
    _uploadTimer = Timer.periodic(MapConfig.uploadInterval, (_) => _uploadNow());
  }

  /// Call when network connectivity is restored while sharing is active.
  void triggerUpload() {
    if (_uploadTimer != null) _uploadNow();
  }

  Future<void> _uploadNow() async {
    final pos = _lastPosition;
    final cs = _session.callsign;
    final pc = _session.passcode;

    // Send position directly to APRS-IS
    if (pos != null && cs != null && pc != null) {
      await AprsClient.sendPosition(
        callsign: cs,
        passcode: pc,
        lat: pos.latitude,
        lon: pos.longitude,
      );
    }

    // Heartbeat to server — refreshes session and detects removal (404)
    final ok = await _session.update();
    if (!ok) {
      _uploadTimer?.cancel();
      _uploadTimer = null;
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
