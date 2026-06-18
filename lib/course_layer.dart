import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:gpx/gpx.dart';
import 'package:http/http.dart' as http;
import 'package:latlong2/latlong.dart';
import 'map_config.dart';
import 'remote_config.dart';

class CourseLayer extends StatefulWidget {
  final List<CourseConfig> courses;

  const CourseLayer({super.key, required this.courses});

  @override
  State<CourseLayer> createState() => _CourseLayerState();
}

class _CourseLayerState extends State<CourseLayer> {
  List<Polyline> _polylines = [];

  @override
  void initState() {
    super.initState();
    _loadCourses();
  }

  @override
  void didUpdateWidget(CourseLayer old) {
    super.didUpdateWidget(old);
    if (old.courses != widget.courses) _loadCourses();
  }

  Future<void> _loadCourses() async {
    final polylines = <Polyline>[];
    for (final course in widget.courses) {
      if (!course.visible || course.file.isEmpty) continue;
      try {
        final points = await _fetchCourse(course.file);
        if (points.isNotEmpty) {
          polylines.add(Polyline(
            points: points,
            color: _parseColor(course.color),
            strokeWidth: 3.0,
          ));
        }
      } catch (e) {
        assert(() { debugPrint('CourseLayer: failed to load ${course.file}: $e'); return true; }());
      }
    }
    if (mounted) setState(() => _polylines = polylines);
  }

  Future<List<LatLng>> _fetchCourse(String file) async {
    final url = '${MapConfig.serverBaseUrl}/$file';
    final response = await http
        .get(Uri.parse(url))
        .timeout(const Duration(seconds: 20));
    if (response.statusCode != 200) return [];
    final lower = file.toLowerCase();
    if (lower.endsWith('.gpx')) return _parseGpx(response.body);
    return _parseGeoJson(response.body);
  }

  List<LatLng> _parseGeoJson(String body) {
    final points = <LatLng>[];
    final data = jsonDecode(body) as Map<String, dynamic>;
    final features = data['features'] as List? ?? [];
    for (final f in features) {
      final geom = (f as Map<String, dynamic>)['geometry'] as Map<String, dynamic>?;
      if (geom == null) continue;
      final type = geom['type'] as String?;
      final coords = geom['coordinates'] as List?;
      if (coords == null) continue;
      if (type == 'LineString') {
        points.addAll(_coordsToLatLng(coords));
      } else if (type == 'MultiLineString') {
        for (final seg in coords) {
          points.addAll(_coordsToLatLng(seg as List));
        }
      }
    }
    return points;
  }

  List<LatLng> _coordsToLatLng(List coords) =>
      coords.map((c) => LatLng((c[1] as num).toDouble(), (c[0] as num).toDouble())).toList();

  List<LatLng> _parseGpx(String body) {
    final gpx = GpxReader().fromString(body);
    final points = <LatLng>[];
    for (final trk in gpx.trks) {
      for (final seg in trk.trksegs) {
        points.addAll(
          seg.trkpts
              .where((p) => p.lat != null && p.lon != null)
              .map((p) => LatLng(p.lat!, p.lon!)),
        );
      }
    }
    return points;
  }

  Color _parseColor(String hex) {
    final clean = hex.replaceAll('#', '');
    if (clean.length != 6) return Colors.blue;
    return Color(int.parse('FF$clean', radix: 16));
  }

  @override
  Widget build(BuildContext context) => PolylineLayer(polylines: _polylines);
}
