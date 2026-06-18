import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'map_config.dart';
import 'remote_config.dart';

class ConfigService {
  static const _cacheFile = 'config_cache.json';

  Future<RemoteConfig> load() async {
    try {
      final response = await http
          .get(Uri.parse('${MapConfig.serverBaseUrl}/index.php?config'))
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        await _saveCache(response.body);
        return RemoteConfig.fromJson(json);
      }
    } catch (_) {}
    return await _loadCache() ?? RemoteConfig.defaults;
  }

  Future<void> _saveCache(String body) async {
    try {
      final dir = await getApplicationDocumentsDirectory();
      await File('${dir.path}/$_cacheFile').writeAsString(body);
    } catch (_) {}
  }

  Future<RemoteConfig?> _loadCache() async {
    try {
      final dir = await getApplicationDocumentsDirectory();
      final file = File('${dir.path}/$_cacheFile');
      if (await file.exists()) {
        final json = jsonDecode(await file.readAsString()) as Map<String, dynamic>;
        return RemoteConfig.fromJson(json);
      }
    } catch (_) {}
    return null;
  }
}
