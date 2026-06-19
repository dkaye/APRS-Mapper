import 'dart:convert';
import 'package:http/http.dart' as http;
import 'map_config.dart';

enum JoinResult { success, wrongPin, failed }

class MobileSession {
  String? token;
  String? trackerId;
  String? callsign;
  int? passcode;

  bool get active => token != null;

  Future<JoinResult> join({required String name, required String pin}) async {
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=join'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'name': name, 'pin': pin}),
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
