class MapConfig {
  // marsaprs web root — map/ syncs directly to /var/www/html/, so no subdirectory
  static const String serverBaseUrl = 'https://marsaprs.org';

  static const String tileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  static const String storeName = 'aprsMapStore';

  // Offline tile download defaults (overridden by server config)
  static const double downloadRadiusMiles = 8.0;
  static const int    downloadMinZoom     = 10;
  static const int    downloadMaxZoom     = 14;

  static const double minZoom = 8.0;
  static const double maxZoom = 18.0;
  static const double initialZoom = 12.0;

  // Tracker polling interval
  static const Duration pollInterval = Duration(seconds: 5);

  // Location upload interval when sharing
  static const Duration uploadInterval = Duration(seconds: 60);
}
