import 'dart:convert';
import 'dart:math';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'map_config.dart';

enum JoinResult { success, wrongPin, failed }

class MobileSession {
  String? token;
  String? trackerId;
  String? callsign;
  int? passcode;

  bool get active => token != null;

  static const _deviceIdKey = 'aprs_device_id';

  /// Returns a stable random ID for this installation, creating one on first call.
  static Future<String> getDeviceId() async {
    final prefs = await SharedPreferences.getInstance();
    var id = prefs.getString(_deviceIdKey);
    if (id == null) {
      final rng = Random.secure();
      id = List.generate(16, (_) => rng.nextInt(256))
          .map((b) => b.toRadixString(16).padLeft(2, '0'))
          .join();
      await prefs.setString(_deviceIdKey, id);
    }
    return id;
  }

  Future<JoinResult> join({required String name, required String pin}) async {
    try {
      final deviceId = await getDeviceId();
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=join'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'name': name, 'pin': pin, 'device_id': deviceId}),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        token = data['token'] as String?;
        trackerId = data['id'] as String?;
        callsign = data['callsign'] as String?;
        passcode = data['passcode'] as int?;
        return token != null ? JoinResult.success : JoinResult.failed;
      }
      if (response.statusCode == 403) return JoinResult.wrongPin;
    } catch (_) {}
    return JoinResult.failed;
  }

  /// Heartbeat-only update — no lat/lon. Returns false if session is gone (404).
  Future<bool> update() async {
    final t = token;
    if (t == null) return false;
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=update'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t}),
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 404) return false;
    } catch (_) {}
    return true;
  }

  Future<void> leave() async {
    final t = token;
    token = null;
    trackerId = null;
    callsign = null;
    passcode = null;
    if (t == null) return;
    try {
      await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=leave'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t}),
      ).timeout(const Duration(seconds: 8));
    } catch (_) {}
  }
}
