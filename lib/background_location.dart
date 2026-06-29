import 'dart:async';
import 'dart:io' show Platform;
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'background_task_handler.dart';
import 'map_config.dart';
import 'mobile_session.dart';

export 'mobile_session.dart' show JoinResult, InboundMessage;

class BackgroundLocationService {
  static const _kMiToM = 1609.344;

  // SharedPreferences keys for session persistence across app restarts.
  static const _kPrefActive = 'sharing_active';
  static const _kPrefCallsign = 'sharing_callsign';
  static const _kPrefPasscode = 'sharing_passcode';
  static const _kPrefToken = 'sharing_token';
  static const _kPrefTrackerId = 'sharing_tracker_id';
  static const _kPrefIntervalMs = 'sharing_interval_ms';
  static const _kPrefDistThreshold = 'sharing_dist_threshold_mi';
  static const _kPrefName = 'sharing_name';
  static const _kPrefPin = 'sharing_pin';
  static const _kPrefActivityMode = 'sharing_activity_mode';

  final MobileSession _session = MobileSession();

  StreamSubscription<Position>? _positionSub;
  Timer? _heartbeatTimer;
  Timer? _msgPollTimer;
  LatLng? _lastPosition;
  LatLng? _lastUploadPosition;
  DateTime? _lastUploadTime;
  bool _sharingActive = false;

  Duration _uploadInterval = MapConfig.uploadInterval;
  double _distanceThresholdM = 321.869; // 0.2 miles default

  int _activityMode = -1;
  int get activityMode => _activityMode;

  String _pendingSharingMode = '';

  String? _trackerName;

  bool get isSharing => _session.active;
  String? get trackerId => _session.trackerId;
  String? get callsign => _session.callsign;
  String? get trackerName => _trackerName;
  MobileSession get session => _session;

  void Function()? onSessionEnded;
  void Function()? onBeaconSent;
  void Function(InboundMessage)? onMessageReceived;
  void Function(List<InboundMessage>)? onHistoryLoaded;

  final List<int> _pendingAckIds = [];
  final Set<int> _deliveredMsgIds = {}; // dedup across poll + update paths

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
        if (_sharingActive) {
          unawaited(_maybeUploadFromStream());
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

  // Called on every GPS event. Uploads when moved >= distance threshold OR
  // enough time has elapsed since the last beacon.
  Future<void> _maybeUploadFromStream() async {
    if (!_sharingActive) return;
    final pos = _lastPosition;
    if (pos == null) return;

    final lp = _lastUploadPosition;
    final lu = _lastUploadTime;
    final movedEnough = lp == null || _metersFrom(lp, pos) >= _distanceThresholdM;
    final timeElapsed = lu == null ||
        DateTime.now().difference(lu) >= _uploadInterval;

    if (!movedEnough && !timeElapsed) return;
    await _uploadNow();
  }

  double _metersFrom(LatLng a, LatLng b) => Geolocator.distanceBetween(
      a.latitude, a.longitude, b.latitude, b.longitude);

  Future<JoinResult> startSharing({
    required String name,
    required String pin,
    Duration interval = MapConfig.uploadInterval,
    double distanceThresholdMiles = 0.2,
    String sharingMode = '',
    int activityModeIndex = -1,
  }) async {
    _uploadInterval = interval;
    _distanceThresholdM = distanceThresholdMiles * _kMiToM;
    _activityMode = activityModeIndex;
    final result = await _session.join(name: name, pin: pin, sharingMode: sharingMode);
    if (result == JoinResult.success) {
      _trackerName = name;
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
    final distMi = prefs.getDouble(_kPrefDistThreshold) ?? 0.2;
    final name = prefs.getString(_kPrefName) ?? '';
    final pin  = prefs.getString(_kPrefPin) ?? '';
    _activityMode = prefs.getInt(_kPrefActivityMode) ?? -1;

    if (callsign.isEmpty || passcode == 0) {
      unawaited(_clearSession());
      return false;
    }

    _uploadInterval = Duration(milliseconds: intervalMs);
    _distanceThresholdM = distMi * _kMiToM;
    _trackerName = name.isNotEmpty ? name : null;

    // Try the saved token first — cheapest path, no re-auth needed.
    if (token.isNotEmpty) {
      _session.restoreToken(
        token: token, callsign: callsign, passcode: passcode, trackerId: trackerId,
      );
      final ok = await _session.update();
      if (ok != null) {
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
    }
    unawaited(_uploadImmediately());
    unawaited(_loadHistory());
    _heartbeatTimer = Timer.periodic(
      _uploadInterval,
      (_) async { if (_sharingActive) await _uploadNow(); },
    );
    _msgPollTimer = Timer.periodic(
      const Duration(seconds: 10),
      (_) async { if (_sharingActive) await _pollMessages(); },
    );
  }

  Future<void> _loadHistory() async {
    final msgs = await _session.fetchHistory();
    if (msgs.isNotEmpty) onHistoryLoaded?.call(msgs);
  }

  /// Updates the beacon interval on a running session (no-op if unchanged).
  void updateInterval(Duration newInterval) {
    if (newInterval == _uploadInterval) return;
    _uploadInterval = newInterval;
    if (!_sharingActive) return;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = Timer.periodic(
      _uploadInterval,
      (_) async { if (_sharingActive) await _uploadNow(); },
    );
  }

  void updateDistanceThreshold(double miles) {
    _distanceThresholdM = miles * _kMiToM;
  }

  Future<void> saveActivityMode(int mode) async {
    _activityMode = mode;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_kPrefActivityMode, mode);
  }

  Future<void> changeActivityMode(int mode, Duration interval, double distMi, String sharingMode) async {
    _activityMode = mode;
    _distanceThresholdM = distMi * _kMiToM;
    _pendingSharingMode = sharingMode;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_kPrefActivityMode, mode);
    await prefs.setInt(_kPrefIntervalMs, interval.inMilliseconds);
    await prefs.setDouble(_kPrefDistThreshold, distMi);
    updateInterval(interval); // reschedules heartbeat timer
    if (_sharingActive) unawaited(_uploadNow()); // send new mode immediately
  }

  Future<void> _saveSession(String name, String pin) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPrefActive, true);
    await prefs.setString(_kPrefCallsign, _session.callsign ?? '');
    await prefs.setInt(_kPrefPasscode, _session.passcode ?? 0);
    await prefs.setString(_kPrefToken, _session.token ?? '');
    await prefs.setString(_kPrefTrackerId, _session.trackerId ?? '');
    await prefs.setInt(_kPrefIntervalMs, _uploadInterval.inMilliseconds);
    await prefs.setDouble(_kPrefDistThreshold, _distanceThresholdM / _kMiToM);
    await prefs.setString(_kPrefName, name);
    await prefs.setString(_kPrefPin, pin);
    await prefs.setInt(_kPrefActivityMode, _activityMode);
  }

  Future<void> _clearSession() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPrefActive, false);
  }

  Future<void> _pollMessages() async {
    final ackIds = List<int>.from(_pendingAckIds);
    _pendingAckIds.clear();
    final msgs = await _session.pollMessages(ackIds: ackIds);
    if (msgs == null && _sharingActive) {
      // Session ended — let the next _uploadNow() handle the cleanup
      _pendingAckIds.addAll(ackIds); // restore so update() can also try
      return;
    }
    if (msgs != null) {
      for (final m in msgs) {
        _pendingAckIds.add(m.id);
        if (_deliveredMsgIds.add(m.id)) onMessageReceived?.call(m);
      }
    }
  }

  Future<void> stopSharing() async {
    _sharingActive = false;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    _msgPollTimer?.cancel();
    _msgPollTimer = null;
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

    // Session heartbeat — also detects removal (404).
    // Position is passed to the server on all platforms; server injects to APRS-IS.
    final ackIds = List<int>.from(_pendingAckIds);
    _pendingAckIds.clear();
    final modeToSend = _pendingSharingMode;
    _pendingSharingMode = '';
    final msgs = await _session.update(
      lat: pos?.latitude,
      lon: pos?.longitude,
      ackIds: ackIds,
      sharingMode: modeToSend,
    );
    if (msgs == null && _sharingActive) {
      _sharingActive = false;
      _heartbeatTimer?.cancel();
      _heartbeatTimer = null;
      if (Platform.isAndroid) await FlutterForegroundTask.stopService();
      await _session.leave();
      onSessionEnded?.call();
    } else if (msgs != null) {
      onBeaconSent?.call();
      for (final m in msgs) {
        _pendingAckIds.add(m.id);
        if (_deliveredMsgIds.add(m.id)) onMessageReceived?.call(m);
      }
    }
  }

  void dispose() {
    stopSharing();
    stopTracking();
    _positionController.close();
  }
}
