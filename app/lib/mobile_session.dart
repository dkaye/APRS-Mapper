/// HTTP client for the mobile participant session API:
/// join, update (position upload), leave, poll, message, and msghistory endpoints.
import 'dart:convert';
import 'dart:io' show Platform;
import 'dart:math';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
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

  /// Thread this arrived on. Added for the Watch relay, which replies into the
  /// same thread; 0 from a server that predates the field.
  final int conversationId;
  final String? fromShort; // M0xx, for the "M141 Dirck" label
  /// The id written out — "Cardiac" for "CAR" — from the ID-name list, or null.
  /// Server-supplied, so the watch never re-implements the labelling rules.
  final String? fromSpoken;
  final String? fromKind; // 'mobile' | 'operator'

  /// The sender's addressable identity — an operator's name, a mobile's callsign.
  /// Used to answer a broadcast, which goes back to the calling station rather than
  /// out to the whole net.
  final String? fromKey;
  final bool broadcast; // an All Trackers call, announced differently on the watch

  const InboundMessage({
    required this.id,
    required this.fromLabel,
    required this.text,
    required this.ts,
    this.conversationId = 0,
    this.fromShort,
    this.fromSpoken,
    this.fromKind,
    this.fromKey,
    this.broadcast = false,
  });
  factory InboundMessage.fromJson(Map<String, dynamic> j) => InboundMessage(
    id: (j['id'] as num?)?.toInt() ?? 0,
    fromLabel: j['from_label'] as String? ?? '',
    text: j['text'] as String? ?? '',
    ts: (j['ts'] as num?)?.toInt() ?? 0,
    conversationId: (j['conversation_id'] as num?)?.toInt() ?? 0,
    fromShort: j['from_short'] as String?,
    fromSpoken: j['from_spoken'] as String?,
    fromKind: j['from_kind'] as String?,
    fromKey: j['from_key'] as String?,
    broadcast: j['broadcast'] as bool? ?? false,
  );

  /// How the sender is shown — "M141 Dirck" for a mobile, the name for an operator,
  /// and "Cardiac Stanton" where the ID-name list gives "CAR" a written-out form.
  /// Mirrors MsgMessage.senderLabel in messaging_client.dart so both paths agree.
  String get senderLabel => _label(' ');

  /// With a comma, for speech. See MsgMessage.spokenLabel.
  String get spokenLabel => _label(', ');

  String _label(String sep) {
    final s = fromShort;
    if (fromKind == 'mobile' && s != null && s.isNotEmpty) {
      final head = (fromSpoken != null && fromSpoken!.isNotEmpty) ? fromSpoken! : s;
      return fromLabel.isNotEmpty && fromLabel != s ? '$head$sep$fromLabel' : head;
    }
    return fromLabel;
  }
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
  static const _keychain = FlutterSecureStorage(
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  /// Returns a stable device ID that survives app reinstalls (stored in Keychain on iOS).
  /// Migrates from SharedPreferences if a Keychain value isn't yet present.
  static Future<String> getDeviceId() async {
    // Keychain survives reinstalls — check it first.
    var id = await _keychain.read(key: _deviceIdKey);
    if (id != null) return id;
    // Migrate existing SharedPreferences ID (same-install upgrade path).
    final prefs = await SharedPreferences.getInstance();
    id = prefs.getString(_deviceIdKey);
    if (id == null) {
      final rng = Random.secure();
      id = List.generate(16, (_) => rng.nextInt(256))
          .map((b) => b.toRadixString(16).padLeft(2, '0'))
          .join();
    }
    await _keychain.write(key: _deviceIdKey, value: id);
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
    // Which kind of connection this device is on, which ONLY the device knows.
    //
    // The server resolves an ISP from the joining address, and on cellular that address
    // belongs to the carrier — so the lookup already gives the right answer, it just
    // cannot tell whether it is naming a carrier or somebody's home broadband. This is
    // the missing half: with it, "Comcast Cable" on a phone reads as "via WiFi: Comcast
    // Cable" instead of looking like a cellular carrier nobody has heard of.
    //
    // Not the SIM's own name, which is no longer readable: iOS deprecated CTCarrier and
    // returns "--" from 16 onwards, so a carrier name straight off the phone is not
    // available to ask for on the platform most of this fleet runs.
    //
    // WiFi wins when both are up, because that is what the OS routes over and therefore
    // whose address the server will have seen.
    try {
      final on = await Connectivity().checkConnectivity();
      if (on.contains(ConnectivityResult.wifi)) {
        info['net'] = 'wifi';
      } else if (on.contains(ConnectivityResult.mobile)) {
        info['net'] = 'cellular';
      } else if (on.contains(ConnectivityResult.ethernet)) {
        info['net'] = 'ethernet';
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
  Future<List<InboundMessage>?> update({double? lat, double? lon, double? accuracyM, DateTime? fixTime, List<int> ackIds = const [], String sharingMode = ''}) async {
    final t = token;
    if (t == null) return null;
    try {
      final body = <String, dynamic>{'token': t};
      if (lat != null && lon != null) {
        body['lat'] = lat;
        body['lon'] = lon;
        // Optional quality fields — a server that predates them ignores them,
        // and an older app that omits them still works. Lets operators tell a
        // 5 m GPS lock from a 3 km cell-tower estimate or a stale cached fix.
        if (accuracyM != null && accuracyM > 0 && accuracyM.isFinite) {
          body['acc'] = double.parse(accuracyM.toStringAsFixed(1));
        }
        if (fixTime != null) body['fix_ts'] = fixTime.millisecondsSinceEpoch ~/ 1000;
      }
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

  /// Sends a message to web operators. [to] names a specific operator; when
  /// null/empty the server delivers to all operators (legacy behavior).
  Future<String?> sendMessage(String text, {String? to}) async {
    final t = token;
    if (t == null) return 'Not connected';
    try {
      final body = <String, dynamic>{'token': t, 'text': text};
      if (to != null && to.isNotEmpty) body['to'] = to;
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=message'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 200) return null;
      try {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return data['message'] as String? ?? 'Failed to send message';
      } catch (_) {}
      return 'Failed to send message';
    } catch (_) {}
    return 'Failed to send message';
  }

  /// Names of web operators currently monitoring messages (active in the last
  /// ~60 s). Used to offer a destination picker. Returns an empty list on error
  /// or when the server predates this endpoint (older server → no picker).
  Future<List<String>> fetchWebRecipients() async {
    final t = token;
    if (t == null) return const [];
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=web_recipients'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'token': t}),
      ).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return (data['recipients'] as List<dynamic>? ?? [])
            .map((e) => e.toString())
            .where((s) => s.isNotEmpty)
            .toList();
      }
    } catch (_) {}
    return const [];
  }

  /// Validates the event password with the server. Three answers, not two.
  ///
  /// `true` accepted, `false` REFUSED — the server answers 403 and nothing else does —
  /// and `null` for "could not ask": a timeout, a dropped connection, a 500. The caller
  /// deletes the saved password on a refusal, so collapsing "could not ask" into "wrong"
  /// let one bad moment on the network throw away a password the operator then had to go
  /// and find again.
  static Future<bool?> authEventPassword(String password) async {
    try {
      final response = await http.post(
        Uri.parse('${MapConfig.serverBaseUrl}/index.php?mobile=auth'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'password': password}),
      ).timeout(const Duration(seconds: 8));
      if (response.statusCode == 200) return true;
      if (response.statusCode == 403) return false;
    } catch (_) {}
    return null;
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
