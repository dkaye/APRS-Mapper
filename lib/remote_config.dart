import 'map_config.dart';

class BackgroundLayer {
  final String name;
  final String url;

  const BackgroundLayer({required this.name, required this.url});

  factory BackgroundLayer.fromJson(Map<String, dynamic> j) => BackgroundLayer(
        name: j['name'] as String? ?? '',
        url: j['url'] as String? ?? '',
      );

  List<String> get subdomains =>
      url.contains('{s}') ? ['a', 'b', 'c'] : const [];

  // flutter_map uses {x}/{y} but ESRI uses {y}/{x} — the URL template handles it directly
  String get flutterUrl => url.replaceAll('{s}', '{s}');
}

class CourseConfig {
  final String name;
  final String file;
  final String color;
  final bool visible;

  const CourseConfig({
    required this.name,
    required this.file,
    required this.color,
    required this.visible,
  });

  factory CourseConfig.fromJson(Map<String, dynamic> j) => CourseConfig(
        name: j['name'] as String? ?? '',
        file: j['file'] as String? ?? '',
        color: j['color'] as String? ?? '#2196f3',
        visible: j['visible'] as bool? ?? true,
      );
}

class FixedMarker {
  final String name;
  final String callsign;
  final double lat;
  final double lon;

  const FixedMarker({
    required this.name,
    required this.callsign,
    required this.lat,
    required this.lon,
  });

  factory FixedMarker.fromJson(Map<String, dynamic> j) => FixedMarker(
        name: j['name'] as String? ?? '',
        callsign: j['callsign'] as String? ?? '',
        lat: (j['lat'] as num?)?.toDouble() ?? 0.0,
        lon: (j['lon'] as num?)?.toDouble() ?? 0.0,
      );
}

class RemoteConfig {
  final String event;
  final String attribution;
  final String copyright;
  final String helpHtml;
  final List<CourseConfig> courses;
  final List<BackgroundLayer> backgrounds;
  final List<FixedMarker> aidStations;
  final List<FixedMarker> igates;
  final double mapLat;
  final double mapLon;
  final double mapZoom;
  final bool mobileEnabled;
  final String legend;
  // Beacon settings per activity mode [walk, drive, stationary]
  final List<int> beaconIntervalsSec;
  final List<double> beaconDistancesMi;
  // Offline tile download config (falls back to MapConfig constants if absent)
  final double? offlineRadiusMiles;
  final int offlineMaxZoom;
  final String offlineTileUrl;

  const RemoteConfig({
    required this.event,
    required this.attribution,
    required this.copyright,
    required this.helpHtml,
    required this.courses,
    required this.backgrounds,
    required this.aidStations,
    required this.igates,
    required this.mapLat,
    required this.mapLon,
    required this.mapZoom,
    required this.mobileEnabled,
    required this.legend,
    required this.beaconIntervalsSec,
    required this.beaconDistancesMi,
    this.offlineRadiusMiles,
    required this.offlineMaxZoom,
    required this.offlineTileUrl,
  });

  factory RemoteConfig.fromJson(Map<String, dynamic> j) {
    final map = j['map'] as Map<String, dynamic>? ?? {};
    final omRaw = j['offline_map'];
    final om = omRaw is Map<String, dynamic> ? omRaw : <String, dynamic>{};

    return RemoteConfig(
      event: j['event'] as String? ?? '',
      attribution: j['attribution'] as String? ?? '© OpenStreetMap contributors',
      copyright: j['copyright'] as String? ?? '',
      helpHtml: j['help'] as String? ?? '',
      legend: j['legend'] as String? ?? '',
      courses: (j['courses'] as List? ?? [])
          .map((c) => CourseConfig.fromJson(c as Map<String, dynamic>))
          .toList(),
      backgrounds: (j['backgrounds'] as List? ?? [])
          .map((b) => BackgroundLayer.fromJson(b as Map<String, dynamic>))
          .where((b) => b.url.isNotEmpty)
          .toList(),
      aidStations: (j['aidstations'] as List? ?? [])
          .map((a) => FixedMarker.fromJson(a as Map<String, dynamic>))
          .where((a) => a.lat != 0.0 || a.lon != 0.0)
          .toList(),
      igates: (j['igates'] as List? ?? [])
          .map((g) => FixedMarker.fromJson(g as Map<String, dynamic>))
          .where((g) => g.lat != 0.0 || g.lon != 0.0)
          .toList(),
      mapLat: (map['lat'] as num?)?.toDouble() ?? 37.970,
      mapLon: (map['lon'] as num?)?.toDouble() ?? -122.620,
      mapZoom: (map['zoom'] as num?)?.toDouble() ?? MapConfig.initialZoom,
      mobileEnabled: j['mobile_enabled'] as bool? ?? false,
      beaconIntervalsSec: _parseBeaconIntervals(j['mobile_beacons']),
      beaconDistancesMi:  _parseBeaconDistances(j['mobile_beacons']),
      offlineRadiusMiles: (om['radius'] as num?)?.toDouble(),
      offlineMaxZoom: (om['max_zoom'] as num?)?.toInt() ?? MapConfig.downloadMaxZoom,
      offlineTileUrl: (om['url'] as String? ?? '').isNotEmpty
          ? om['url'] as String
          : MapConfig.tileUrl,
    );
  }

  static RemoteConfig get defaults => RemoteConfig(
        event: '',
        attribution: '© OpenStreetMap contributors',
        copyright: '',
        helpHtml: '',
        legend: '',
        courses: const [],
        backgrounds: const [],
        aidStations: const [],
        igates: const [],
        mapLat: 37.970,
        mapLon: -122.620,
        mapZoom: MapConfig.initialZoom,
        mobileEnabled: false,
        beaconIntervalsSec: const [60, 30, 15, 120],
        beaconDistancesMi:  const [0.2, 0.2, 0.2, 1.0],
        offlineMaxZoom: MapConfig.downloadMaxZoom,
        offlineTileUrl: MapConfig.tileUrl,
      );
}

List<int> _parseBeaconIntervals(dynamic bc) {
  if (bc is! Map) return const [60, 30, 15, 120];
  return [
    (bc['walk_interval']  as num?)?.toInt() ?? 60,
    (bc['cycle_interval'] as num?)?.toInt() ?? 30,
    (bc['drive_interval'] as num?)?.toInt() ?? 15,
    (bc['stat_interval']  as num?)?.toInt() ?? 120,
  ];
}

List<double> _parseBeaconDistances(dynamic bc) {
  if (bc is! Map) return const [0.2, 0.2, 0.2, 1.0];
  return [
    (bc['walk_distance']  as num?)?.toDouble() ?? 0.2,
    (bc['cycle_distance'] as num?)?.toDouble() ?? 0.2,
    (bc['drive_distance'] as num?)?.toDouble() ?? 0.2,
    (bc['stat_distance']  as num?)?.toDouble() ?? 1.0,
  ];
}
