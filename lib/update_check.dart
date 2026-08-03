/// Soft "update available" check for the mobile apps.
///
/// Fetches a small version manifest from the server (app_version.php), compares
/// the latest released build for this platform against the running build, and —
/// if a newer one exists — surfaces a dismissable prompt (see [UpdateInfo]).
///
/// Distribution differs per platform, so the "Update" action differs:
///   - iOS     → open the App Store listing (once the app is published and
///               `store_url` is set in the manifest; until then this is a no-op).
///   - Android → open the APK download URL on the server.
///
/// This is intentionally *soft*: never blocking, silent on any error (offline,
/// malformed manifest), and honours a per-version "Later" dismissal.
import 'dart:convert';
import 'dart:io' show Platform;
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';

class UpdateInfo {
  final String version; // marketing version, e.g. "1.22.0"
  final int build; // build number (the +N), monotonic across releases
  final String url; // App Store URL (iOS) or APK URL (Android)
  final String notes; // optional "what's new" line
  const UpdateInfo({required this.version, required this.build, required this.url, this.notes = ''});
}

class UpdateChecker {
  static const _manifestUrl = 'https://marsaprs.org/app_version.php';

  /// Returns update info when a newer build is available for this platform,
  /// otherwise null (including on any error — the check must never disrupt use).
  static Future<UpdateInfo?> check() async {
    try {
      final pkg = await PackageInfo.fromPlatform();
      final curBuild = int.tryParse(pkg.buildNumber) ?? 0;
      final resp = await http.get(Uri.parse(_manifestUrl)).timeout(const Duration(seconds: 8));
      if (resp.statusCode != 200) return null;
      final m = jsonDecode(resp.body) as Map<String, dynamic>;
      final plat = (Platform.isIOS ? m['ios'] : m['android']) as Map<String, dynamic>?;
      if (plat == null) return null;
      final latestBuild = (plat['build'] as num?)?.toInt() ?? 0;
      if (latestBuild <= curBuild) return null; // up to date (or ahead)
      final url = ((Platform.isIOS ? plat['store_url'] : plat['apk_url']) as String? ?? '').trim();
      if (url.isEmpty) return null; // e.g. iOS not yet on the App Store
      return UpdateInfo(
        version: (plat['latest'] as String?) ?? '',
        build: latestBuild,
        url: url,
        notes: ((m['notes'] as String?) ?? '').trim(),
      );
    } catch (_) {
      return null;
    }
  }
}
