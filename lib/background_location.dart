import 'dart:async';
import 'dart:io' show Platform;
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'map_config.dart';
import 'mobile_session.dart';

class BackgroundLocationService {
  final MobileSession _session = MobileSession();

  StreamSubscription<Position>? _positionSub;
  Timer? _uploadTimer;
  LatLng? _lastPosition;

  bool get isSharing => _session.active;
  String? get trackerId => _session.trackerId;

  // Stream exposed for the UI to consume (optional — CurrentLocationLayer
  // has its own internal stream; this is available if callers want to share one)
  final _positionController = StreamController<Position>.broadcast();
  Stream<Position> get positionStream => _positionController.stream;

  /// Start background location tracking.
  ///
  /// Returns true if the stream started successfully. Permission must already
  /// be granted before calling this.
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
          : const LocationSettings(
              accuracy: LocationAccuracy.best,
              distanceFilter: 5,
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

  /// Join the mobile tracker session with name + PIN. Starts periodic uploads.
  Future<bool> startSharing({required String name, required String pin}) async {
    final joined = await _session.join(name: name, pin: pin);
    if (!joined) return false;
    _startUploadTimer();
    return true;
  }

  /// Stop sharing and remove this tracker from the server map.
  Future<void> stopSharing() async {
    _uploadTimer?.cancel();
    _uploadTimer = null;
    await _session.leave();
  }

  void _startUploadTimer() {
    _uploadTimer?.cancel();
    // Upload immediately, then every uploadInterval
    _uploadNow();
    _uploadTimer = Timer.periodic(MapConfig.uploadInterval, (_) => _uploadNow());
  }

  Future<void> _uploadNow() async {
    final pos = _lastPosition;
    if (pos == null) return;
    await _session.update(pos.latitude, pos.longitude);
  }

  void dispose() {
    stopSharing();
    stopTracking();
    _positionController.close();
  }
}
