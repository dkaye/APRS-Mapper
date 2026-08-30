/// Flutter Map layer that fetches GPX/KML/GeoJSON course files from the server in
/// parallel and renders them on the map, colour-coded per course.
///
/// A course is not always a line. GeoJSON Point features are a perfectly ordinary way to
/// describe a course -- the Dipsea's mile markers are exactly that, one Point per marker
/// with its name in `properties.title` -- and until 2026-08-30 this parsed only
/// LineString and MultiLineString. A markers file therefore produced an empty point list
/// and drew nothing at all, silently: the course appeared in the sidebar, could be
/// switched on, and never showed up. The web map had always drawn them.
///
/// Points are NOT appended to the line. Joining mile markers in file order would draw a
/// polyline through them that looks like a route and is not one.
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

/// What one course file drew: a path, standalone points, or both.
class _Course {
  final List<LatLng> line;
  final List<({LatLng at, String label})> points;
  const _Course(this.line, this.points);
  bool get isEmpty => line.isEmpty && points.isEmpty;
}

class _CourseLayerState extends State<CourseLayer> {
  List<Polyline> _polylines = [];
  List<Marker> _markers = [];
  final _cache = <String, _Course>{};

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
        final drawn = await _fetchCourse(course.file);
        if (!drawn.isEmpty) {
          _cache[course.file] = drawn;
          if (mounted) setState(_rebuildPolylines);
        }
      } catch (_) {}
    }));
  }

  void _rebuildPolylines() {
    _polylines = [
      for (final c in widget.courses)
        if (c.visible && c.file.isNotEmpty)
          if ((_cache[c.file]?.line.length ?? 0) > 1)
            Polyline(
              points: _cache[c.file]!.line,
              color: _parseColor(c.color),
              strokeWidth: 3.0,
            ),
    ];
    _markers = [
      for (final c in widget.courses)
        if (c.visible && c.file.isNotEmpty)
          for (final p in _cache[c.file]?.points ?? const [])
            _courseMarker(p.at, p.label, _parseColor(c.color)),
    ];
  }

  /// A course point: a filled dot with its name beside it.
  ///
  /// The label matters more than it looks. These are mile markers and aid stops, and an
  /// operator hearing "at marker four" needs to find marker four -- an unlabelled dot
  /// would say only that something is there.
  ///
  /// Not tappable. Everything else on this map that responds to a tap is a station or a
  /// place somebody can be, and a course point is neither; making it tappable would put
  /// a target over the traffic underneath it.
  Marker _courseMarker(LatLng at, String label, Color color) => Marker(
        point: at,
        width: 92,
        height: 26,
        alignment: Alignment.centerLeft,
        child: IgnorePointer(
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 9,
              height: 9,
              decoration: BoxDecoration(
                color: color,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 1.5),
              ),
            ),
            if (label.isNotEmpty) ...[
              const SizedBox(width: 3),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: color,
                    // The map underneath is arbitrary, so the text carries its own
                    // contrast rather than relying on what it happens to sit on.
                    shadows: const [
                      Shadow(blurRadius: 2, color: Colors.white),
                      Shadow(blurRadius: 4, color: Colors.white),
                    ],
                  ),
                ),
              ),
            ],
          ]),
        ),
      );

  Future<_Course> _fetchCourse(String file) async {
    final uri = Uri.parse(Uri.encodeFull('${MapConfig.serverBaseUrl}/$file'));
    final response = await http
        .get(uri)
        .timeout(const Duration(seconds: 20));
    if (response.statusCode != 200) return const _Course([], []);
    final lower = file.toLowerCase();
    if (lower.endsWith('.gpx')) return _Course(_parseGpx(response.body), const []);
    return _parseGeoJson(response.body);
  }

  _Course _parseGeoJson(String body) {
    final line = <LatLng>[];
    final pts = <({LatLng at, String label})>[];
    final data = jsonDecode(body) as Map<String, dynamic>;
    final features = data['features'] as List? ?? [];
    for (final f in features) {
      final feat = f as Map<String, dynamic>;
      final geom = feat['geometry'] as Map<String, dynamic>?;
      if (geom == null) continue;
      final type = geom['type'] as String?;
      final coords = geom['coordinates'] as List?;
      if (coords == null) continue;
      if (type == 'LineString') {
        line.addAll(_coordsToLatLng(coords));
      } else if (type == 'MultiLineString') {
        for (final seg in coords) {
          line.addAll(_coordsToLatLng(seg as List));
        }
      } else if (type == 'Point') {
        // [lon, lat] and then whatever else the authoring tool put there -- the Dipsea
        // file carries [lon, lat, 0, 0]. Anything past the first two is ignored rather
        // than assumed to be elevation.
        if (coords.length >= 2) {
          pts.add((
            at: LatLng((coords[1] as num).toDouble(), (coords[0] as num).toDouble()),
            label: _pointLabel(feat['properties']),
          ));
        }
      } else if (type == 'MultiPoint') {
        for (final c in coords) {
          final one = c as List;
          if (one.length >= 2) {
            pts.add((
              at: LatLng((one[1] as num).toDouble(), (one[0] as num).toDouble()),
              label: _pointLabel(feat['properties']),
            ));
          }
        }
      }
    }
    return _Course(line, pts);
  }

  /// What to write beside a course point.
  ///
  /// `title` is what the tool that drew the Dipsea markers writes; `name` is what most
  /// other GeoJSON producers write. Both are tried rather than picking one, because a
  /// course file arrives from whatever somebody happened to draw it in.
  String _pointLabel(Object? props) {
    if (props is! Map) return '';
    for (final k in const ['title', 'name', 'Name', 'label']) {
      final v = props[k];
      if (v is String && v.trim().isNotEmpty) return v.trim();
    }
    return '';
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
  Widget build(BuildContext context) => Stack(children: [
        PolylineLayer(polylines: _polylines),
        // Above the lines, so a marker sitting on its own course is not drawn under it.
        if (_markers.isNotEmpty) MarkerLayer(markers: _markers),
      ]);
}
