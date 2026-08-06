/// Flutter Map layer that fetches GPX/KML/GeoJSON course files from the server
/// in parallel and renders them as color-coded polylines on the map.
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
  final _cache = <String, List<LatLng>>{};

  @override
  void initState() {
    super.initState();
    _loadCourses();
  }

  @override
  void didUpdateWidget(CourseLayer old) {
    super.didUpdateWidget(old);
    if (!_courseListEqual(old.courses, widget.courses)) _loadCourses();
  }

  bool _courseListEqual(List<CourseConfig> a, List<CourseConfig> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i].file != b[i].file || a[i].visible != b[i].visible || a[i].color != b[i].color) return false;
    }
    return true;
  }

  Future<void> _loadCourses() async {
    // Show any already-cached courses immediately.
    if (mounted) setState(_rebuildPolylines);

    // Fetch all uncached courses in parallel; update the map as each arrives.
    final pending = widget.courses
        .where((c) => c.visible && c.file.isNotEmpty && !_cache.containsKey(c.file))
        .toList();

    await Future.wait(pending.map((course) async {
      try {
        final pts = await _fetchCourse(course.file);
        if (pts.isNotEmpty) {
          _cache[course.file] = pts;
          if (mounted) setState(_rebuildPolylines);
        }
      } catch (_) {}
    }));
  }

  void _rebuildPolylines() {
    _polylines = [
      for (final c in widget.courses)
        if (c.visible && c.file.isNotEmpty)
          if (_cache[c.file]?.isNotEmpty ?? false)
            Polyline(
              points: _cache[c.file]!,
              color: _parseColor(c.color),
              strokeWidth: 3.0,
            ),
    ];
  }

  Future<List<LatLng>> _fetchCourse(String file) async {
    final uri = Uri.parse(Uri.encodeFull('${MapConfig.serverBaseUrl}/$file'));
    final response = await http
        .get(uri)
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
