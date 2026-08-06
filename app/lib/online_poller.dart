/// Polls the server periodically to detect connectivity loss and restoration.
/// Notifies listeners so the UI can show the offline banner.
import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'map_config.dart';
import 'tracker_data.dart';

enum PollerState { online, offline }

class OnlinePoller {
  final void Function(APRSData data) onData;
  final void Function(PollerState state) onStateChange;

  Timer? _timer;
  String? _etag;
  PollerState _state = PollerState.online;
  int _failCount = 0;

  static const _failThreshold = 3;

  OnlinePoller({required this.onData, required this.onStateChange});

  void start() {
    _poll();
    _timer = Timer.periodic(MapConfig.pollInterval, (_) => _poll());
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _poll() async {
    try {
      final headers = <String, String>{};
      if (_etag != null) headers['If-None-Match'] = _etag!;

      final response = await http
          .get(Uri.parse('${MapConfig.serverBaseUrl}/index.php?json'), headers: headers)
          .timeout(const Duration(seconds: 8));

      if (response.statusCode == 304) {
        _markOnline();
        return;
      }
      if (response.statusCode == 200) {
        _etag = response.headers['etag'];
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        onData(APRSData.fromJson(json));
        _markOnline();
        return;
      }
    } catch (_) {}

    _failCount++;
    if (_failCount >= _failThreshold && _state != PollerState.offline) {
      _state = PollerState.offline;
      onStateChange(PollerState.offline);
    }
  }

  void _markOnline() {
    _failCount = 0;
    if (_state != PollerState.online) {
      _state = PollerState.online;
      onStateChange(PollerState.online);
    }
  }
}
