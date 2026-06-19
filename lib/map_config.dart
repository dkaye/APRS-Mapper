import 'package:latlong2/latlong.dart';

class MapConfig {
  // marsaprs web root — map/ syncs directly to /var/www/html/, so no subdirectory
  static const String serverBaseUrl = 'https://marsaprs.org';

  static const String tileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  static const String storeName = 'aprsMapStore';

  // Marin County — center near San Rafael
  static final LatLng center = LatLng(37.970, -122.620);

  // Marin County bounding box: Golden Gate Bridge (S) to Novato (N), Point Reyes (W) to Richmond Bridge (E)
  static final LatLng downloadSW = LatLng(37.835, -122.970);
  static final LatLng downloadNE = LatLng(38.200, -122.427);

  // Zoom levels to download — 10 (overview) through 15 (trail detail) ≈ 5,500 tiles
  static const int downloadMinZoom = 10;
  static const int downloadMaxZoom = 15;

  static const double minZoom = 8.0;
  static const double maxZoom = 18.0;
  static const double initialZoom = 12.0;

  // Tracker polling interval
  static const Duration pollInterval = Duration(seconds: 5);

  // Location upload interval when sharing
  static const Duration uploadInterval = Duration(seconds: 60);
}
