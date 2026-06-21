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
/// APRS-IS position packet on every [onRepeatEvent] tick.
class LocationTaskHandler extends TaskHandler {
  String? _callsign;
  int? _passcode;
  double? _lat;
  double? _lon;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {}

  @override
  void onRepeatEvent(DateTime timestamp) {
    final callsign = _callsign;
    final passcode = _passcode;
    final lat = _lat;
    final lon = _lon;
    if (callsign == null || passcode == null || lat == null || lon == null) return;
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
      case 'position':
        _lat = (data['lat'] as num?)?.toDouble();
        _lon = (data['lon'] as num?)?.toDouble();
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {}
}
