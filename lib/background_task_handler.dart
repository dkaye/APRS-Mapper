import 'dart:async';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'aprs_client.dart';

/// Entry point for the background Dart isolate on Android.
/// Called by the OS when the foreground service starts.
@pragma('vm:entry-point')
void backgroundTaskEntryPoint() {
  FlutterForegroundTask.setTaskHandler(LocationTaskHandler());
}

/// Runs in the background isolate. Receives session credentials and GPS
/// positions from the main isolate via [onReceiveData], then sends an
/// APRS-IS position packet on every [onRepeatEvent] tick OR immediately
/// when a 'force_upload' (distance-triggered) message arrives.
///
/// To simulate timer reset after a distance-triggered beacon, [onRepeatEvent]
/// skips the upload if a beacon was sent within [_intervalMs] — this prevents
/// a double-beacon when the OS timer fires shortly after a force_upload.
class LocationTaskHandler extends TaskHandler {
  String? _callsign;
  int? _passcode;
  double? _lat;
  double? _lon;
  int _intervalMs = 30000; // overridden by 'session' message
  DateTime? _lastBeacon;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {}

  @override
  void onRepeatEvent(DateTime timestamp) {
    // Skip this tick if a distance-triggered beacon fired recently enough
    // that we're still inside the configured interval window. This simulates
    // resetting the timer after a force_upload.
    final lb = _lastBeacon;
    if (lb != null && timestamp.difference(lb).inMilliseconds < _intervalMs) return;
    _sendBeacon();
  }

  void _sendBeacon() {
    final callsign = _callsign;
    final passcode = _passcode;
    final lat = _lat;
    final lon = _lon;
    if (callsign == null || passcode == null || lat == null || lon == null) return;
    _lastBeacon = DateTime.now();
    unawaited(AprsClient.sendPosition(
      callsign: callsign,
      passcode: passcode,
      lat: lat,
      lon: lon,
    ));
  }

  @override
  void onReceiveData(Object data) {
    if (data is! Map<String, dynamic>) return;
    switch (data['type'] as String?) {
      case 'session':
        _callsign = data['callsign'] as String?;
        _passcode = (data['passcode'] as num?)?.toInt();
        _intervalMs = (data['intervalMs'] as num?)?.toInt() ?? _intervalMs;
      case 'position':
        _lat = (data['lat'] as num?)?.toDouble();
        _lon = (data['lon'] as num?)?.toDouble();
      case 'force_upload':
        // Distance-triggered beacon: send immediately, bypassing the timer check.
        _sendBeacon();
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {}
}
