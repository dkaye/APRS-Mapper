import 'dart:convert';
import 'dart:io' show Platform;
import 'dart:math';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'map_config.dart';

enum JoinResult { success, wrongPin, callsignError, failed }

class InboundMessage {
  final int id;
  final String fromLabel;
  final String text;
  final int ts;
  const InboundMessage({required this.id, required this.fromLabel, required this.text, required this.ts});
  factory InboundMessage.fromJson(Map<String, dynamic> j) => InboundMessage(
    id: (j['id'] as num?)?.toInt() ?? 0,
    fromLabel: j['from_label'] as String? ?? '',
    text: j['text'] as String? ?? '',
    ts: (j['ts'] as num?)?.toInt() ?? 0,
  );
}

class MobileSession {
  String? token;
  String? trackerId;
  String? callsign;
  int? passcode;
  String? lastJoinError;
  String? pendingSetMode; // set_mode delivered by last update(); cleared after each call

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

  static Future<Map<String, String>> _collectDeviceInfo() async {
    final info = <String, String>{};
    try {
      final pkg = await PackageInfo.fromPlatform();
      info['app'] = '${pkg.version}+${pkg.buildNumber}';
    } catch (_) {}
    try {
      if (Platform.isIOS) {
        final d = await DeviceInfoPlugin().iosInfo;
        info['os'] = 'iOS ${d.systemVersion}';
        info['model'] = d.utsname.machine;
      } else if (Platform.isAndroid) {
        final d = await DeviceInfoPlugin().androidInfo;
        info['os'] = 'Android ${d.version.release}';
        info['model'] = d.model;
        info['manufacturer'] = d.manufacturer;
      }
    } catch (_) {}
    return info;
  }

  Future<JoinResult> join({
    required String name,
    required String pin,
    String sharingMode = '',
    String hamRoot = '',
    int hamSsid = 0,
  }) async {
    try {
      final deviceId = await getDeviceId();
      final deviceInfo = await _collectDeviceInfo();
      final body = <String, dynamic>{'name': name, 'pin': pin, 'device_id': deviceId, 'device_info': deviceInfo};
      if (sharingMode.isNotEmpty) body['sharing_mode'] = sharingMode;
      if (hamRoot.isNotEmpty) { body['ham_root'] = hamRoot.toUpperCase(); body['ham_ssid'] = hamSsid; }
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=join'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        token = data['token'] as String?;
        trackerId = data['id'] as String?;
        callsign = data['callsign'] as String?;
        passcode = data['passcode'] as int?;
        lastJoinError = null;
        return token != null ? JoinResult.success : JoinResult.failed;
      }
      if (response.statusCode == 403) return JoinResult.wrongPin;
      if (response.statusCode == 422 || response.statusCode == 409) {
        try {
          final data = jsonDecode(response.body) as Map<String, dynamic>;
          lastJoinError = data['error'] as String? ?? 'Invalid callsign';
        } catch (_) { lastJoinError = 'Invalid callsign'; }
        return JoinResult.callsignError;
      }
    } catch (_) {}
    return JoinResult.failed;
  }

  /// Heartbeat update. On iOS, include lat/lon so the server injects to APRS-IS
  /// (raw TCP sockets are blocked in iOS background; HTTP is not).
  /// Returns null if session is gone (404), otherwise list of pending messages.
  Future<List<InboundMessage>?> update({double? lat, double? lon, List<int> ackIds = const [], String sharingMode = ''}) async {
    final t = token;
    if (t == null) return null;
    try {
      final body = <String, dynamic>{'token': t};
      if (lat != null && lon != null) { body['lat'] = lat; body['lon'] = lon; }
      if (ackIds.isNotEmpty) body['ack_ids'] = ackIds;
      if (sharingMode.isNotEmpty) body['sharing_mode'] = sharingMode;
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=update'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 404) return null;
      try {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        pendingSetMode = data['set_mode'] as String?;
        final msgs = (data['messages'] as List<dynamic>? ?? [])
            .map((m) => InboundMessage.fromJson(m as Map<String, dynamic>))
            .toList();
        return msgs;
      } catch (_) { pendingSetMode = null; return []; }
    } catch (_) {}
    return [];
  }

  /// Fetches the full message history for this callsign from the server.
  /// Returns list of messages (oldest first), or empty list on error.
  Future<List<InboundMessage>> fetchHistory() async {
    final t = token;
    if (t == null) return [];
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=msghistory'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t}),
      ).timeout(const Duration(seconds: 10));
      if (response.statusCode != 200) return [];
      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return (data['messages'] as List<dynamic>? ?? [])
          .map((m) => InboundMessage.fromJson(m as Map<String, dynamic>))
          .toList();
    } catch (_) {}
    return [];
  }

  /// Lightweight poll — checks for pending messages without updating position.
  /// Returns new messages (may be empty), or null on session-not-found.
  Future<List<InboundMessage>?> pollMessages({List<int> ackIds = const []}) async {
    final t = token;
    if (t == null) return null;
    try {
      final body = <String, dynamic>{'token': t};
      if (ackIds.isNotEmpty) body['ack_ids'] = ackIds;
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=poll'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 404) return null;
      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return (data['messages'] as List<dynamic>? ?? [])
          .map((m) => InboundMessage.fromJson(m as Map<String, dynamic>))
          .toList();
    } catch (_) {}
    return [];
  }

  Future<bool> sendMessage(String text) async {
    final t = token;
    if (t == null) return false;
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=message'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t, 'text': text}),
      ).timeout(const Duration(seconds: 8));
      return response.statusCode == 200;
    } catch (_) {}
    return false;
  }

  /// Validates the event password with the server.
  /// Returns true if accepted (or no password is required), false if wrong.
  static Future<bool> authEventPassword(String password) async {
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=auth'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'password': password}),
      ).timeout(const Duration(seconds: 8));
      return response.statusCode == 200;
    } catch (_) {}
    return false;
  }

  /// Restores an in-memory session from previously saved credentials.
  /// Does NOT verify the token — call update() after this to check liveness.
  void restoreToken({
    required String token,
    required String callsign,
    required int passcode,
    String? trackerId,
  }) {
    this.token = token;
    this.callsign = callsign;
    this.passcode = passcode;
    this.trackerId = trackerId;
  }

  void clearToken() {
    token = null;
    callsign = null;
    passcode = null;
    trackerId = null;
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
