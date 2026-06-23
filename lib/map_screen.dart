import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;
import 'dart:math' as math;
import 'package:flutter/gestures.dart' show PointerPanZoomUpdateEvent;
import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_map_location_marker/flutter_map_location_marker.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'arrow_painter.dart';
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
  final LatLng? initialCenter;

  const MapScreen({super.key, required this.config, this.initialCenter});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  _LocationState _locationState = _LocationState.checking;
  final _mapController = MapController();
  LatLng? _lastUserLatLng;
  StreamSubscription<Position>? _positionSub;
  Stream<LocationMarkerPosition?>? _locationMarkerStream;
  late RemoteConfig _config;

  // Online tracker state
  List<TrackerData> _trackers = [];
  bool _isOnline = true;
  late final OnlinePoller _poller;

  // Background / tile layer
  String _tileUrl = MapConfig.tileUrl;
  List<String> _tileSubdomains = const [];
  // Created once so TileLayer doesn't reset its cache on every poller setState.
  final _tileProvider = FMTCStore(MapConfig.storeName).getTileProvider();

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
  int _selectionClickCount = 0;
  Set<String> _blinkingIds = {};
  bool _blinkOn = true;
  Timer? _blinkTimer;

  // Saved map position (restored when reset button tapped)
  LatLng? _savedCenter;
  double? _savedZoom;
  double? _savedRotation;

  // Scale bar
  bool _scaleImperial = true;
  double _scaleZoom = 0;

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
        // Compute updated trail color before setState so it lands in one frame.
        Color? newTrailColor;
        String? refetchCallsign;
        if (_selectedId != null) {
          final sel = data.trackers.where((t) => t.id == _selectedId).firstOrNull;
          if (sel != null) {
            final c = _trackerColor(sel.color);
            if (c != _trailColor) newTrailColor = c;
            if (updated.contains(_selectedId)) refetchCallsign = sel.callsign;
          }
        }
        setState(() {
          _trackers = data.trackers;
          if (newTrailColor != null) _trailColor = newTrailColor;
        });
        if (updated.isNotEmpty) _triggerBlink({..._blinkingIds, ...updated});
        if (refetchCallsign != null) _fetchTrail(refetchCallsign);
      },
      onStateChange: (state) {
        if (!mounted) return;
        setState(() => _isOnline = state == PollerState.online);
        if (state == PollerState.online && _bgLocation.isSharing) {
          _bgLocation.triggerUpload();
        }
      },
    );
    _poller.start();
    _loadSavedMap();
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
        _startPositionStream(); // blue dot only — background tracking starts at share time
        unawaited(_maybeResumeSharing());
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
    // distanceFilter: 5 — controls how often CurrentLocationLayer redraws.
    // Without an explicit stream, CurrentLocationLayer creates its own with
    // distanceFilter: 0, which keeps the Flutter display link active at 1 Hz
    // when the beaconing GPS stream puts iOS into navigation mode, preventing
    // auto-lock. Sharing the same stream here avoids a second CLLocationManager.
    final gpsStream = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.best,
        distanceFilter: 5,
      ),
    );
    _positionSub = gpsStream.listen((pos) {
      _lastUserLatLng = LatLng(pos.latitude, pos.longitude);
    });
    _locationMarkerStream = const LocationMarkerDataStreamFactory()
        .fromGeolocatorPositionStream(stream: gpsStream);
  }

  // ── Background permission setup ───────────────────────────────────────────

  /// Ensures the OS won't kill location tracking when the screen locks.
  /// Called once when the user first starts sharing.
  Future<void> _ensureBackgroundPermissions() async {
    if (Platform.isAndroid) {
      // Android 13+: must grant notification permission for the foreground
      // service notification to appear; without it Android kills the service.
      if (await Permission.notification.isDenied) {
        await Permission.notification.request();
      }
      // Ask the user to exempt this app from battery optimization so the
      // foreground service isn't throttled or killed while screen is off.
      if (await Permission.ignoreBatteryOptimizations.isDenied) {
        await Permission.ignoreBatteryOptimizations.request();
      }
    } else if (Platform.isIOS) {
      // iOS needs "Always" location permission for background updates.
      // If the user only granted "When In Use", prompt to upgrade.
      final current = await Geolocator.checkPermission();
      if (current == LocationPermission.whileInUse) {
        await Geolocator.requestPermission();
      }
    }
  }

  // ── Location sharing ──────────────────────────────────────────────────────

  /// Auto-resumes sharing on app startup if the user was sharing when the app
  /// was last closed. Silently reuses the saved token (or re-joins if expired).
  Future<void> _maybeResumeSharing() async {
    if (!mounted) return;
    await _ensureBackgroundPermissions();
    await _bgLocation.startTracking();
    final resumed = await _bgLocation.resumeSharing();
    if (!mounted) return;
    if (resumed) {
      setState(() => _isSharing = true);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Location sharing resumed'),
        duration: Duration(seconds: 3),
      ));
    }
  }

  Future<void> _toggleSharing() async {
    if (_isSharing) {
      await _bgLocation.stopSharing();
      if (mounted) setState(() => _isSharing = false);
      return;
    }
    if (!mounted) return;
    if (!_isOnline) {
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('No Connection'),
          content: const Text('You are offline. Connect to the internet to share your location.'),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
      return;
    }
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
    bool ridingMode = false; // false = Walk/Run (60 s), true = Ride/Drive (15 s)

    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          Future<void> submit() async {
            final name = nameCtl.text.trim();
            final pin = pinCtl.text.trim();
            if (name.isEmpty) {
              setDialogState(() => errorText = 'Please enter your first name.');
              return;
            }
            if (pin.isEmpty) return;
            setDialogState(() { loading = true; errorText = null; });
            await _ensureBackgroundPermissions();
            await _bgLocation.startTracking(); // starts GPS + foreground service
            final joinResult = await _bgLocation.startSharing(
              name: name,
              pin: pin,
              interval: Duration(seconds: ridingMode ? 15 : 60),
            );
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
            content: SingleChildScrollView(
              child: Column(
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
                const SizedBox(height: 12),
                const Text('Activity',
                    style: TextStyle(fontSize: 12, color: Colors.grey)),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  children: [
                    ChoiceChip(
                      label: const Text('Walk / Run'),
                      selected: !ridingMode,
                      onSelected: (_) => setDialogState(() => ridingMode = false),
                    ),
                    ChoiceChip(
                      label: const Text('Ride / Drive'),
                      selected: ridingMode,
                      onSelected: (_) => setDialogState(() => ridingMode = true),
                    ),
                  ],
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
            ),
            actions: [
              TextButton(onPressed: loading ? null : () => Navigator.pop(ctx), child: const Text('Cancel')),
              TextButton(
                onPressed: loading ? null : submit,
                child: loading
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Share'),
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
    if (_savedCenter != null) {
      _mapController.move(_savedCenter!, _savedZoom ?? _config.mapZoom);
      _mapController.rotate(_savedRotation ?? 0);
    } else {
      _mapController.move(
        widget.initialCenter ?? LatLng(_config.mapLat, _config.mapLon),
        _config.mapZoom,
      );
      _mapController.rotate(0);
    }
  }

  Future<void> _handleSaveMap() async {
    final camera = _mapController.camera;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble('map_saved_lat', camera.center.latitude);
    await prefs.setDouble('map_saved_lon', camera.center.longitude);
    await prefs.setDouble('map_saved_zoom', camera.zoom);
    await prefs.setDouble('map_saved_rotation', camera.rotation);
    if (!mounted) return;
    setState(() {
      _savedCenter = camera.center;
      _savedZoom = camera.zoom;
      _savedRotation = camera.rotation;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Map position saved'), duration: Duration(seconds: 2)),
    );
  }

  Future<void> _loadSavedMap() async {
    final prefs = await SharedPreferences.getInstance();
    final lat = prefs.getDouble('map_saved_lat');
    final lon = prefs.getDouble('map_saved_lon');
    final zoom = prefs.getDouble('map_saved_zoom');
    final rot = prefs.getDouble('map_saved_rotation');
    if (!mounted || lat == null || lon == null) return;
    setState(() {
      _savedCenter = LatLng(lat, lon);
      _savedZoom = zoom;
      _savedRotation = rot;
    });
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
      _trailColor = _trackerColor(t.color);
    });
    _selectionClickCount = 1;
    final newZoom = zoom
        ? _mapController.camera.zoom.clamp(14.0, MapConfig.maxZoom)
        : _mapController.camera.zoom;
    _mapController.move(t.latLng, newZoom);
    _triggerBlink({t.id});
    _fetchTrail(t.callsign);
  }

  void _selectFixed(FixedMarker m) {
    setState(() {
      _selectedId = m.name;
      _trailPoints = [];
    });
    _selectionClickCount = 1;
    _mapController.move(LatLng(m.lat, m.lon), _mapController.camera.zoom);
    _triggerBlink({m.name});
  }

  // ── Fixed marker tap cycle (matches web 3-tap cycle) ─────────────────────

  void _onFixedTap(FixedMarker m) {
    if (_selectedId == m.name && _selectionClickCount == 1) {
      _selectionClickCount = 2;
      _mapController.move(LatLng(m.lat, m.lon), 15.0);
    } else if (_selectedId == m.name && _selectionClickCount >= 2) {
      _selectionClickCount = 0;
      setState(() { _selectedId = null; _trailPoints = []; });
      _handleReset();
    } else {
      _selectFixed(m);
    }
  }

  void _onFixedLongPress(FixedMarker m) {
    _selectFixed(m);
    _selectionClickCount = 2;
    _mapController.move(LatLng(m.lat, m.lon), 15.0);
  }

  Color _trackerColor(String color) {
    switch (color) {
      case 'green': return const Color(0xFF43A047);
      case 'blue':  return const Color(0xFF1E88E5);
      default:      return const Color(0xFFE53935);
    }
  }

  // Bearing in radians from p1 to p2, clockwise from north.
  double _bearingRad(LatLng p1, LatLng p2) {
    final lat1 = p1.latitude * math.pi / 180;
    final lat2 = p2.latitude * math.pi / 180;
    final dLon = (p2.longitude - p1.longitude) * math.pi / 180;
    final y = math.sin(dLon) * math.cos(lat2);
    final x = math.cos(lat1) * math.sin(lat2) -
        math.sin(lat1) * math.cos(lat2) * math.cos(dLon);
    return math.atan2(y, x);
  }

  Future<void> _fetchTrail(String callsign) async {
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
      if (mounted) setState(() => _trailPoints = points);
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
    final fresh = await ConfigService().load();
    if (!mounted) return;
    setState(() {
      _config = fresh;
      _initCourseVisibility();
    });
    Navigator.pushReplacement(context,
        MaterialPageRoute(builder: (_) => DownloadScreen(config: fresh, forceRefresh: true)));
  }

  void _handleTrackpadZoom(PointerPanZoomUpdateEvent event) {
    final camera = _mapController.camera;
    final newZoom = (camera.zoom - event.panDelta.dy * 0.01)
        .clamp(MapConfig.minZoom, MapConfig.maxZoom);
    if ((newZoom - camera.zoom).abs() < 0.001) return;
    final newCenter = camera.focusedZoomCenter(
      math.Point(event.localPosition.dx, event.localPosition.dy),
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

  // ── Scale bar ─────────────────────────────────────────────────────────────

  double _roundScaleNum(double n) {
    if (n <= 0) return 1;
    final pow10 = math.pow(10, (math.log(n) / math.ln10).floor()).toDouble();
    final d = n / pow10;
    if (d >= 10) return 10 * pow10;
    if (d >= 5)  return 5  * pow10;
    if (d >= 3)  return 3  * pow10;
    if (d >= 2)  return 2  * pow10;
    return pow10;
  }

  Widget _buildScaleBar() {
    try {
      const maxW = 100.0;
      final cam = _mapController.camera;
      final mpp = 156543.03392 *
          math.cos(cam.center.latitude * math.pi / 180) /
          math.pow(2, cam.zoom);
      final maxMeters = maxW * mpp;

      String label;
      double ratio;
      if (_scaleImperial) {
        final maxFeet = maxMeters * 3.28084;
        if (maxFeet > 5280) {
          final miles = _roundScaleNum(maxFeet / 5280);
          label = '${miles < 1 ? miles.toStringAsFixed(1) : miles.toInt()} mi';
          ratio = miles / (maxFeet / 5280);
        } else {
          final feet = _roundScaleNum(maxFeet);
          label = '${feet.toInt()} ft';
          ratio = feet / maxFeet;
        }
      } else {
        if (maxMeters >= 1000) {
          final km = _roundScaleNum(maxMeters / 1000);
          label = '${km < 1 ? km.toStringAsFixed(1) : km.toInt()} km';
          ratio = km * 1000 / maxMeters;
        } else {
          final m = _roundScaleNum(maxMeters);
          label = '${m.toInt()} m';
          ratio = m / maxMeters;
        }
      }

      final barW = (maxW * ratio).clamp(24.0, maxW);
      return GestureDetector(
        onTap: () => setState(() => _scaleImperial = !_scaleImperial),
        child: Container(
          padding: const EdgeInsets.fromLTRB(7, 3, 7, 4),
          decoration: BoxDecoration(
            color: const Color(0xEBFFFFFF),
            border: Border.all(color: const Color(0xFFBBBBBB)),
            borderRadius: BorderRadius.circular(4),
            boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 3, offset: Offset(0, 1))],
          ),
          child: Container(
            width: barW,
            padding: const EdgeInsets.symmetric(vertical: 1),
            decoration: const BoxDecoration(
              border: Border(
                left:   BorderSide(color: Color(0xFF555555), width: 2),
                right:  BorderSide(color: Color(0xFF555555), width: 2),
                bottom: BorderSide(color: Color(0xFF555555), width: 2),
              ),
            ),
            alignment: Alignment.center,
            child: Text(label,
                style: const TextStyle(fontSize: 10, color: Color(0xFF333333), height: 1.1)),
          ),
        ),
      );
    } catch (_) {
      return const SizedBox.shrink();
    }
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawerScrimColor: Colors.transparent,
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
        onSaveMap: _handleSaveMap,
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
                initialCenter: widget.initialCenter ?? LatLng(_config.mapLat, _config.mapLon),
                initialZoom: _config.mapZoom,
                minZoom: MapConfig.minZoom,
                maxZoom: MapConfig.maxZoom,
                backgroundColor: Colors.grey[900]!,
                interactionOptions: const InteractionOptions(
                  flags: InteractiveFlag.all & ~InteractiveFlag.pinchMove,
                ),
                onMapEvent: (event) {
                  final z = _mapController.camera.zoom;
                  if ((z - _scaleZoom).abs() > 0.05) {
                    setState(() => _scaleZoom = z);
                  }
                },
              ),
              children: [
                TileLayer(
                  urlTemplate: _tileUrl,
                  subdomains: _tileSubdomains,
                  userAgentPackageName: 'org.marsaprs.aprs_map',
                  tileProvider: _tileProvider,
                ),
                CourseLayer(courses: _visibleCourses),
                if (_trailPoints.length > 1)
                  PolylineLayer(polylines: [
                    Polyline(
                      points: _trailPoints,
                      color: _trailColor.withOpacity(0.80),
                      strokeWidth: 3.0,
                      pattern: StrokePattern.dashed(segments: [4, 7]),
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
                if (_trailPoints.length > 1)
                  MarkerLayer(markers: [
                    for (var i = 0; i < _trailPoints.length - 1; i++)
                      Marker(
                        point: LatLng(
                          (_trailPoints[i].latitude + _trailPoints[i + 1].latitude) / 2,
                          (_trailPoints[i].longitude + _trailPoints[i + 1].longitude) / 2,
                        ),
                        width: 20,
                        height: 20,
                        alignment: Alignment.center,
                        child: Transform.rotate(
                          angle: _bearingRad(_trailPoints[i], _trailPoints[i + 1]),
                          child: CustomPaint(
                            size: const Size(20, 20),
                            painter: ArrowPainter(color: _trailColor),
                          ),
                        ),
                      ),
                  ]),
                if (showIgates && _config.igates.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.igates,
                    isIgate: true,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  ),
                if (showAid && _config.aidStations.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.aidStations,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
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
                    positionStream: _locationMarkerStream,
                    // Passing an empty heading stream stops flutter_compass from
                    // firing continuous compass updates that drive a 60 fps
                    // AnimationController and prevent iOS auto-lock.
                    headingStream: const Stream.empty(),
                    style: const LocationMarkerStyle(
                      marker: DefaultLocationMarker(),
                      markerSize: Size(20, 20),
                      accuracyCircleColor: Color(0x1A2196F3),
                      headingSectorColor: Colors.transparent,
                    ),
                  ),
              ],
            ),
          ),

          if (!_isOnline) const OfflineBanner(),

          // Scale bar — bottom left
          Positioned(
            bottom: 24,
            left: 16,
            child: SafeArea(child: _buildScaleBar()),
          ),

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

