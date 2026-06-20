import 'dart:async';
import 'dart:convert';
import 'dart:math' show Point;
import 'package:flutter/gestures.dart' show PointerPanZoomUpdateEvent;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_map_location_marker/flutter_map_location_marker.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'background_location.dart';
import 'config_service.dart';
import 'course_layer.dart';
import 'download_screen.dart';
import 'fixed_marker_layer.dart';
import 'map_config.dart';
import 'menu_drawer.dart';
import 'online_poller.dart';
import 'remote_config.dart';
import 'tracker_data.dart';
import 'tracker_layer.dart';
import 'widgets/mode_indicator.dart';
import 'widgets/offline_banner.dart';
import 'widgets/permission_denied_view.dart';

enum _LocationState { checking, granted, denied, permanentlyDenied }

class MapScreen extends StatefulWidget {
  final RemoteConfig config;

  const MapScreen({super.key, required this.config});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  _LocationState _locationState = _LocationState.checking;
  final _mapController = MapController();
  LatLng? _lastUserLatLng;
  StreamSubscription<Position>? _positionSub;
  late RemoteConfig _config;

  // Online tracker state
  List<TrackerData> _trackers = [];
  bool _isOnline = true;
  late final OnlinePoller _poller;

  // Background / tile layer
  String _tileUrl = MapConfig.tileUrl;
  List<String> _tileSubdomains = const [];

  // Section and course visibility
  Map<String, bool> _sectionVisible = {
    'trackers': true,
    'courses': true,
    'aidstations': true,
    'igates': true,
  };
  Map<String, bool> _courseVisible = {};

  // Selection / blink
  String? _selectedId;
  Set<String> _blinkingIds = {};
  bool _blinkOn = true;
  Timer? _blinkTimer;

  // Breadcrumb trail for selected tracker
  List<LatLng> _trailPoints = [];
  Color _trailColor = Colors.grey;

  // Background location / sharing
  final _bgLocation = BackgroundLocationService();
  bool _isSharing = false;

  @override
  void initState() {
    super.initState();
    _config = widget.config;
    _initCourseVisibility();
    _requestPermission();
    _poller = OnlinePoller(
      onData: (data) {
        if (!mounted) return;
        final prev = _trackers;
        final updated = data.trackers
            .where((t) {
              final old = prev.where((o) => o.id == t.id).firstOrNull;
              return old != null && old.lastUpdate != t.lastUpdate;
            })
            .map((t) => t.id)
            .toSet();
        setState(() => _trackers = data.trackers);
        if (updated.isNotEmpty) _triggerBlink({..._blinkingIds, ...updated});
      },
      onStateChange: (state) {
        if (mounted) setState(() => _isOnline = state == PollerState.online);
      },
    );
    _poller.start();
    _bgLocation.onSessionEnded = () {
      if (!mounted) return;
      setState(() => _isSharing = false);
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Session Ended'),
          content: const Text('Your location sharing session has ended. Tap Share Location to rejoin.'),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
    };
  }

  void _initCourseVisibility() {
    _courseVisible = {
      for (final c in _config.courses) c.file: c.visible,
    };
  }

  @override
  void dispose() {
    _poller.stop();
    _positionSub?.cancel();
    _blinkTimer?.cancel();
    _bgLocation.dispose();
    _mapController.dispose();
    super.dispose();
  }

  // ── Location permission ───────────────────────────────────────────────────

  Future<void> _requestPermission() async {
    try {
      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (!mounted) return;
      if (permission == LocationPermission.always ||
          permission == LocationPermission.whileInUse) {
        setState(() => _locationState = _LocationState.granted);
        _startPositionStream();
        await _bgLocation.startTracking();
      } else if (permission == LocationPermission.deniedForever) {
        setState(() => _locationState = _LocationState.permanentlyDenied);
      } else {
        setState(() => _locationState = _LocationState.denied);
      }
    } catch (_) {
      if (mounted) setState(() => _locationState = _LocationState.denied);
    }
  }

  void _startPositionStream() {
    _positionSub = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, distanceFilter: 2),
    ).listen((pos) {
      _lastUserLatLng = LatLng(pos.latitude, pos.longitude);
    });
  }

  // ── Location sharing ──────────────────────────────────────────────────────

  Future<void> _toggleSharing() async {
    if (_isSharing) {
      await _bgLocation.stopSharing();
      if (mounted) setState(() => _isSharing = false);
      return;
    }
    if (!mounted) return;
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      final granted = await Geolocator.requestPermission();
      if (granted == LocationPermission.denied || granted == LocationPermission.deniedForever) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Location access is required to share your location.'),
        ));
        return;
      }
    }
    if (!_bgLocation.isSharing) await _bgLocation.startTracking();
    if (mounted) await _showShareDialog();
  }

  /// Shows the Share Location dialog. Handles join attempts inline —
  /// wrong PIN shows an error inside the dialog; success closes it and
  /// shows the callsign info modal; network failure closes it and shows
  /// a separate error dialog.
  Future<void> _showShareDialog() async {
    final nameCtl = TextEditingController();
    final pinCtl = TextEditingController();
    final pinFocus = FocusNode();
    String? errorText;
    bool loading = false;

    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          Future<void> submit() async {
            final name = nameCtl.text.trim();
            final pin = pinCtl.text.trim();
            if (name.isEmpty || pin.isEmpty) return;
            setDialogState(() { loading = true; errorText = null; });
            final joinResult = await _bgLocation.startSharing(name: name, pin: pin);
            if (!ctx.mounted) return;
            if (joinResult == JoinResult.success) {
              Navigator.pop(ctx);
              if (mounted) setState(() => _isSharing = true);
              await _showSharingStartedDialog(_bgLocation.callsign ?? '');
            } else if (joinResult == JoinResult.wrongPin) {
              setDialogState(() {
                errorText = 'Incorrect PIN. Please try again.';
                loading = false;
              });
            } else {
              Navigator.pop(ctx);
              if (mounted) await showDialog<void>(
                context: context,
                builder: (ctx2) => AlertDialog(
                  title: const Text('Could Not Connect'),
                  content: const Text('Could not reach the server. Check your connection and try again.'),
                  actions: [TextButton(onPressed: () => Navigator.pop(ctx2), child: const Text('OK'))],
                ),
              );
            }
          }

          return AlertDialog(
            title: const Text('Share Location'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameCtl,
                  decoration: const InputDecoration(labelText: 'Your First Name'),
                  textCapitalization: TextCapitalization.words,
                  onSubmitted: (_) => pinFocus.requestFocus(),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: pinCtl,
                  focusNode: pinFocus,
                  decoration: const InputDecoration(labelText: 'PIN'),
                  keyboardType: TextInputType.number,
                  obscureText: true,
                  onSubmitted: (_) => submit(),
                ),
                if (errorText != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    errorText!,
                    style: const TextStyle(color: Colors.red, fontSize: 13),
                  ),
                ],
              ],
            ),
            actions: [
              TextButton(onPressed: loading ? null : () => Navigator.pop(ctx), child: const Text('Cancel')),
              TextButton(
                onPressed: loading ? null : submit,
                child: loading
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Join'),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _showSharingStartedDialog(String cs) async {
    if (!mounted || cs.isEmpty) return;
    final aprsUrl = Uri.parse('https://aprs.fi/#!call=$cs');
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Location Sharing Started'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Your location is now shared using callsign $cs.'),
            const SizedBox(height: 12),
            const Text(
              'In addition to this map, you can track your position on aprs.fi:',
            ),
            const SizedBox(height: 4),
            GestureDetector(
              onTap: () => launchUrl(aprsUrl, mode: LaunchMode.externalApplication),
              child: Text(
                'aprs.fi/?call=$cs',
                style: const TextStyle(
                  color: Colors.blue,
                  decoration: TextDecoration.underline,
                ),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'The callsign $cs can also be entered in CalTopo.com to show your position there.',
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  // ── Map controls ──────────────────────────────────────────────────────────

  void _handleRecenter() {
    final userPos = _lastUserLatLng;
    if (userPos == null) return;
    _mapController.move(userPos, 14.0);
    _mapController.rotate(0);
  }

  void _handleReset() {
    _mapController.move(LatLng(_config.mapLat, _config.mapLon), _config.mapZoom);
    _mapController.rotate(0);
  }

  void _triggerBlink(Set<String> ids) {
    _blinkTimer?.cancel();
    setState(() { _blinkingIds = ids; _blinkOn = true; });
    int count = 0;
    _blinkTimer = Timer.periodic(const Duration(milliseconds: 500), (t) {
      if (!mounted) { t.cancel(); return; }
      count++;
      if (count >= 10) {
        t.cancel();
        setState(() { _blinkOn = true; _blinkingIds = {}; });
        return;
      }
      setState(() => _blinkOn = !_blinkOn);
    });
  }

  void _selectTracker(TrackerData t, {bool zoom = false}) {
    if (!t.hasPosition) {
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(t.name.isNotEmpty ? t.name : t.id),
          content: const Text(
            'No location data has been received for this tracker yet.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('OK'),
            ),
          ],
        ),
      );
      return;
    }
    setState(() {
      _selectedId = t.id;
      _trailPoints = [];
    });
    final newZoom = zoom
        ? _mapController.camera.zoom.clamp(14.0, MapConfig.maxZoom)
        : _mapController.camera.zoom;
    _mapController.move(t.latLng, newZoom);
    _triggerBlink({t.id});
    _fetchTrail(t.callsign, _trackerColor(t.color));
  }

  void _selectFixed(FixedMarker m) {
    setState(() {
      _selectedId = m.name;
      _trailPoints = [];
    });
    _mapController.move(LatLng(m.lat, m.lon), _mapController.camera.zoom);
    _triggerBlink({m.name});
  }

  Color _trackerColor(String color) {
    switch (color) {
      case 'green': return const Color(0xFF43A047);
      case 'blue':  return const Color(0xFF1E88E5);
      default:      return const Color(0xFFE53935);
    }
  }

  Future<void> _fetchTrail(String callsign, Color color) async {
    if (!_isOnline || callsign.isEmpty) return;
    try {
      final resp = await http.get(Uri.parse('${MapConfig.serverBaseUrl}/index.php?history'));
      if (resp.statusCode != 200) return;
      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      final raw = (data[callsign] as List? ?? []).cast<Map<String, dynamic>>();
      // Drop consecutive duplicate positions
      final deduped = <Map<String, dynamic>>[];
      for (var i = 0; i < raw.length; i++) {
        if (i == 0 || raw[i]['lat'] != raw[i - 1]['lat'] || raw[i]['lon'] != raw[i - 1]['lon']) {
          deduped.add(raw[i]);
        }
      }
      // Reverse from newest-first to oldest-first
      final points = deduped.reversed.map((e) => LatLng(
            (e['lat'] as num).toDouble(),
            (e['lon'] as num).toDouble(),
          )).toList();
      if (mounted) setState(() {
        _trailPoints = points;
        _trailColor = color;
      });
    } catch (_) {}
  }

  Future<void> _reloadConfig() async {
    final fresh = await ConfigService().load();
    if (!mounted) return;
    setState(() {
      _config = fresh;
      _initCourseVisibility();
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Config reloaded'), duration: Duration(seconds: 2)),
    );
  }

  Future<void> _refreshTiles() async {
    Navigator.push(context,
        MaterialPageRoute(builder: (_) => DownloadScreen(config: _config, forceRefresh: true)));
  }

  void _handleTrackpadZoom(PointerPanZoomUpdateEvent event) {
    final camera = _mapController.camera;
    final newZoom = (camera.zoom - event.panDelta.dy * 0.01)
        .clamp(MapConfig.minZoom, MapConfig.maxZoom);
    if ((newZoom - camera.zoom).abs() < 0.001) return;
    final newCenter = camera.focusedZoomCenter(
      Point(event.localPosition.dx, event.localPosition.dy),
      newZoom,
    );
    _mapController.move(newCenter, newZoom);
  }

  void _changeBackground(BackgroundLayer bg) {
    setState(() {
      _tileUrl = bg.url;
      _tileSubdomains = bg.subdomains;
    });
  }

  void _setSectionVisible(String key, bool visible) =>
      setState(() => _sectionVisible[key] = visible);

  void _setCourseVisible(String file, bool visible) =>
      setState(() => _courseVisible[file] = visible);

  List<CourseConfig> get _visibleCourses {
    if (!(_sectionVisible['courses'] ?? true)) return const [];
    return _config.courses
        .map((c) => CourseConfig(
              name: c.name,
              file: c.file,
              color: c.color,
              visible: _courseVisible[c.file] ?? c.visible,
            ))
        .toList();
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawer: MenuDrawer(
        config: _config,
        trackers: _trackers,
        isSharing: _isSharing,
        sharingCallsign: _isSharing ? _bgLocation.callsign : null,
        isOnline: _isOnline,
        selectedId: _selectedId,
        selectedBgUrl: _tileUrl,
        sectionVisible: _sectionVisible,
        courseVisible: _courseVisible,
        onTrackerTap: (t) => _selectTracker(t),
        onTrackerLongPress: (t) => _selectTracker(t, zoom: true),
        onFixedTap: (m) => _selectFixed(m),
        onBackgroundChange: _changeBackground,
        onSectionVisibility: _setSectionVisible,
        onCourseVisibility: _setCourseVisible,
        onReload: _reloadConfig,
        onShareToggle: _toggleSharing,
        onResetMap: _handleReset,
        onRefreshTiles: _refreshTiles,
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_locationState == _LocationState.checking) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_locationState == _LocationState.permanentlyDenied) {
      return const PermissionDeniedView(permanent: true);
    }

    final showTrackers = _sectionVisible['trackers'] ?? true;
    final showAid = _sectionVisible['aidstations'] ?? true;
    final showIgates = _sectionVisible['igates'] ?? true;

    return Builder(
      builder: (context) => Stack(
        children: [
          Listener(
            onPointerPanZoomUpdate: _handleTrackpadZoom,
            child: FlutterMap(
              mapController: _mapController,
              options: MapOptions(
                initialCenter: LatLng(_config.mapLat, _config.mapLon),
                initialZoom: _config.mapZoom,
                minZoom: MapConfig.minZoom,
                maxZoom: MapConfig.maxZoom,
                backgroundColor: Colors.black,
                interactionOptions: InteractionOptions(
                  flags: InteractiveFlag.all & ~InteractiveFlag.pinchMove,
                ),
                cameraConstraint: CameraConstraint.containCenter(
                  bounds: LatLngBounds(MapConfig.downloadSW, MapConfig.downloadNE),
                ),
                onMapEvent: (_) {},
              ),
              children: [
                TileLayer(
                  urlTemplate: _tileUrl,
                  subdomains: _tileSubdomains,
                  userAgentPackageName: 'org.marsaprs.aprs_map',
                  tileProvider: FMTCStore(MapConfig.storeName).getTileProvider(),
                ),
                CourseLayer(courses: _visibleCourses),
                if (_trailPoints.length > 1)
                  PolylineLayer(polylines: [
                    Polyline(
                      points: _trailPoints,
                      color: _trailColor.withOpacity(0.65),
                      strokeWidth: 2.0,
                      pattern: const StrokePattern.dotted(),
                    ),
                  ]),
                if (_trailPoints.isNotEmpty)
                  CircleLayer(circles: _trailPoints.map((pt) => CircleMarker(
                    point: pt,
                    radius: 5,
                    color: _trailColor.withOpacity(0.5),
                    borderStrokeWidth: 1.5,
                    borderColor: _trailColor,
                  )).toList()),
                if (showIgates && _config.igates.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.igates,
                    isIgate: true,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                  ),
                if (showAid && _config.aidStations.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.aidStations,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                  ),
                if (showTrackers && _isOnline)
                  TrackerLayer(
                    trackers: _trackers,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                  ),
                if (_locationState == _LocationState.granted)
                  CurrentLocationLayer(
                    style: const LocationMarkerStyle(
                      marker: DefaultLocationMarker(),
                      markerSize: Size(20, 20),
                      accuracyCircleColor: Color(0x1A2196F3),
                      headingSectorColor: Color(0x802196F3),
                    ),
                  ),
              ],
            ),
          ),

          if (!_isOnline) const OfflineBanner(),

          // Menu button — top left
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Material(
                color: Colors.white,
                shape: const CircleBorder(),
                elevation: 4,
                child: IconButton(
                  icon: const Icon(Icons.menu),
                  onPressed: () => Scaffold.of(context).openDrawer(),
                ),
              ),
            ),
          ),

          // Mode indicator — top right
          Positioned(
            top: 0,
            right: 0,
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(0, 8, 12, 0),
                child: ModeIndicator(online: _isOnline),
              ),
            ),
          ),

          // Sharing badge — below mode indicator
          if (_isSharing && _isOnline)
            Positioned(
              top: 0,
              right: 0,
              child: SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(0, 36, 12, 0),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: Colors.red[700],
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Text('Sharing',
                        style: TextStyle(color: Colors.white, fontSize: 11)),
                  ),
                ),
              ),
            ),

          // Reset map button — bottom right, above recenter
          Positioned(
            bottom: 92,
            right: 20,
            child: SafeArea(
              child: Material(
                color: Colors.white,
                shape: const CircleBorder(),
                elevation: 4,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: _handleReset,
                  child: const SizedBox(
                    width: 44,
                    height: 44,
                    child: Icon(Icons.restart_alt, color: Colors.grey, size: 22),
                  ),
                ),
              ),
            ),
          ),

          // Re-center button — bottom right
          Positioned(
            bottom: 24,
            right: 16,
            child: SafeArea(
              child: GestureDetector(
                onTap: _handleRecenter,
                onLongPress: _handleReset,
                child: Material(
                  color: Colors.white,
                  shape: const CircleBorder(),
                  elevation: 4,
                  child: Container(
                    width: 56,
                    height: 56,
                    alignment: Alignment.center,
                    child: Icon(
                      Icons.my_location,
                      color: _locationState == _LocationState.granted
                          ? Colors.blue[700]
                          : Colors.grey,
                    ),
                  ),
                ),
              ),
            ),
          ),

          // Location denied toast
          if (_locationState == _LocationState.denied)
            Positioned(
              bottom: 90,
              left: 16,
              right: 80,
              child: Material(
                borderRadius: BorderRadius.circular(8),
                color: Colors.black87,
                child: const Padding(
                  padding: EdgeInsets.all(12),
                  child: Text(
                    'Location access denied — your position won\'t be shown.',
                    style: TextStyle(color: Colors.white),
                    textAlign: TextAlign.center,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
