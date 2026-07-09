/// Data model for a single tracker's state: callsign, name, position,
/// breadcrumb history, color, and mobile/hybrid flags.
import 'package:latlong2/latlong.dart';

class TrackerData {
  final String id;
  final String callsign;
  final String name;
  final double? lat;  // null until first APRS beacon received
  final double? lon;
  final String color;
  final String time;
  final int lastUpdate;
  final bool mobile;
  final String sharingMode; // 'walk_run' | 'cycle' | 'drive' | 'stationary' | ''
  final String? hamCallsign;

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
    this.sharingMode = '',
    this.hamCallsign,
  });

  bool get hasPosition => lat != null && lon != null;
  LatLng get latLng => LatLng(lat!, lon!);

  factory TrackerData.fromJson(Map<String, dynamic> j) => TrackerData(
        id: j['id'] as String? ?? '',
        callsign: j['callsign'] as String? ?? '',
        name: j['name'] as String? ?? '',
        lat: (j['lat'] as num?)?.toDouble(),
        lon: (j['lon'] as num?)?.toDouble(),
        color: j['color'] as String? ?? 'red',
        time: j['time'] as String? ?? '',
        lastUpdate: (j['lastUpdate'] as num?)?.toInt() ?? 0,
        mobile: j['mobile'] as bool? ?? false,
        sharingMode: j['sharing_mode'] as String? ?? '',
        hamCallsign: j['ham_callsign'] as String?,
      );
}

class APRSData {
  final List<TrackerData> trackers;
  final int blinkDuration; // seconds
  final List<int>?    beaconIntervalsSec; // [walk, drive, stat]; null = unchanged
  final List<double>? beaconDistancesMi;

  const APRSData({
    required this.trackers,
    this.blinkDuration = 5,
    this.beaconIntervalsSec,
    this.beaconDistancesMi,
  });

  factory APRSData.fromJson(Map<String, dynamic> j) {
    final bc = j['mobile_beacons'];
    return APRSData(
      trackers: (j['trackers'] as List? ?? [])
          .map((t) => TrackerData.fromJson(t as Map<String, dynamic>))
          .toList(),
      blinkDuration: (j['blink_duration'] as num?)?.toInt() ?? 5,
      beaconIntervalsSec: bc is Map ? [
        (bc['walk_interval']  as num?)?.toInt() ?? 60,
        (bc['cycle_interval'] as num?)?.toInt() ?? 30,
        (bc['drive_interval'] as num?)?.toInt() ?? 15,
        (bc['stat_interval']  as num?)?.toInt() ?? 120,
      ] : null,
      beaconDistancesMi: bc is Map ? [
        (bc['walk_distance']  as num?)?.toDouble() ?? 0.2,
        (bc['cycle_distance'] as num?)?.toDouble() ?? 0.2,
        (bc['drive_distance'] as num?)?.toDouble() ?? 0.2,
        (bc['stat_distance']  as num?)?.toDouble() ?? 1.0,
      ] : null,
    );
  }

  static APRSData get empty => const APRSData(trackers: []);
}
