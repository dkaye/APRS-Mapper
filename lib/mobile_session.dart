import 'dart:convert';
import 'package:http/http.dart' as http;
import 'map_config.dart';

class MobileSession {
  String? token;
  String? trackerId;

  bool get active => token != null;

  Future<bool> join({required String name, required String pin}) async {
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
        return token != null;
      }
    } catch (_) {}
    return false;
  }

  Future<void> update(double lat, double lon) async {
    final t = token;
    if (t == null) return;
    try {
      await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=update'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t, 'lat': lat, 'lon': lon}),
      ).timeout(const Duration(seconds: 8));
    } catch (_) {}
  }

  Future<void> leave() async {
    final t = token;
    token = null;
    trackerId = null;
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
