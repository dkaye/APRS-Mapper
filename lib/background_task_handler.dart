/// Android foreground service isolate entry point for flutter_foreground_task.
/// Beaconing is handled entirely by the main isolate; this file exists only
/// to satisfy the foreground service requirement and keep the process alive.
import 'dart:async';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';

/// Entry point for the Android foreground service isolate.
/// Beaconing is handled entirely by the main isolate; this handler exists
/// only to satisfy the foreground service requirement and keep the process alive.
@pragma('vm:entry-point')
void backgroundTaskEntryPoint() {
  FlutterForegroundTask.setTaskHandler(LocationTaskHandler());
}

class LocationTaskHandler extends TaskHandler {
  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {}

  @override
  void onRepeatEvent(DateTime timestamp) {}

  @override
  void onReceiveData(Object data) {}

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {}
}
