import 'map_config.dart';

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

class RemoteConfig {
  final String event;
  final String attribution;
  final String copyright;
  final String helpHtml;
  final List<CourseConfig> courses;
  final double mapLat;
  final double mapLon;
  final double mapZoom;
  final bool mobileEnabled;

  const RemoteConfig({
    required this.event,
    required this.attribution,
    required this.copyright,
    required this.helpHtml,
    required this.courses,
    required this.mapLat,
    required this.mapLon,
    required this.mapZoom,
    required this.mobileEnabled,
  });

  // Parses marsaprs ?config JSON response
  factory RemoteConfig.fromJson(Map<String, dynamic> j) {
    final map = j['map'] as Map<String, dynamic>? ?? {};
    return RemoteConfig(
      event: j['event'] as String? ?? '',
      attribution: j['attribution'] as String? ?? '© OpenStreetMap contributors',
      copyright: j['copyright'] as String? ?? '',
      helpHtml: j['help'] as String? ?? '',
      courses: (j['courses'] as List? ?? [])
          .map((c) => CourseConfig.fromJson(c as Map<String, dynamic>))
          .toList(),
      mapLat: (map['lat'] as num?)?.toDouble() ?? MapConfig.center.latitude,
      mapLon: (map['lon'] as num?)?.toDouble() ?? MapConfig.center.longitude,
      mapZoom: (map['zoom'] as num?)?.toDouble() ?? MapConfig.initialZoom,
      mobileEnabled: j['mobile_enabled'] as bool? ?? false,
    );
  }

  static RemoteConfig get defaults => RemoteConfig(
        event: '',
        attribution: '© OpenStreetMap contributors',
        copyright: '',
        helpHtml: '',
        courses: const [],
        mapLat: MapConfig.center.latitude,
        mapLon: MapConfig.center.longitude,
        mapZoom: MapConfig.initialZoom,
        mobileEnabled: false,
      );
}
