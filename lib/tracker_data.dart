import 'package:latlong2/latlong.dart';

class TrackerData {
  final String id;
  final String callsign;
  final String name;
  final double lat;
  final double lon;
  final String color; // "green", "blue", "red"
  final String time;  // human-readable age, e.g. "2m 30s"
  final int lastUpdate; // unix timestamp
  final bool mobile;

  const TrackerData({
    required this.id,
    required this.callsign,
    required this.name,
    required this.lat,
    required this.lon,
    required this.color,
    required this.time,
    required this.lastUpdate,
    required this.mobile,
  });

  LatLng get latLng => LatLng(lat, lon);

  factory TrackerData.fromJson(Map<String, dynamic> j) => TrackerData(
        id: j['id'] as String? ?? '',
        callsign: j['callsign'] as String? ?? '',
        name: j['name'] as String? ?? '',
        lat: (j['lat'] as num?)?.toDouble() ?? 0.0,
        lon: (j['lon'] as num?)?.toDouble() ?? 0.0,
        color: j['color'] as String? ?? 'red',
        time: j['time'] as String? ?? '',
        lastUpdate: (j['lastUpdate'] as num?)?.toInt() ?? 0,
        mobile: j['mobile'] as bool? ?? false,
      );
}

class APRSData {
  final List<TrackerData> trackers;

  const APRSData({required this.trackers});

  factory APRSData.fromJson(Map<String, dynamic> j) => APRSData(
        trackers: (j['trackers'] as List? ?? [])
            .map((t) => TrackerData.fromJson(t as Map<String, dynamic>))
            .where((t) => t.lat != 0.0 || t.lon != 0.0)
            .toList(),
      );

  static APRSData get empty => const APRSData(trackers: []);
}
