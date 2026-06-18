import 'dart:async';
import 'dart:io' show Platform;
import 'dart:math' show Point;
import 'package:flutter/gestures.dart' show PointerPanZoomUpdateEvent;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_location_marker/flutter_map_location_marker.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'background_location.dart';
import 'config_service.dart';
import 'course_layer.dart';
import 'download_screen.dart';
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
  int _recenterCount = 0;
  StreamSubscription<Position>? _positionSub;
  late RemoteConfig _config;

  // Online tracker state
  List<TrackerData> _trackers = [];
  bool _isOnline = true;
  late final OnlinePoller _poller;

  // Background location / sharing
  final _bgLocation = BackgroundLocationService();
  bool _isSharing = false;

  @override
  void initState() {
    super.initState();
    _config = widget.config;
    _requestPermission();
    _poller = OnlinePoller(
      onData: (data) {
        if (mounted) setState(() => _trackers = data.trackers);
      },
      onStateChange: (state) {
        if (mounted) setState(() => _isOnline = state == PollerState.online);
      },
    );
    _poller.start();
  }

  @override
  void dispose() {
    _poller.stop();
    _positionSub?.cancel();
    _bgLocation.dispose();
    _mapController.dispose();
    super.dispose();
  }

  // ── Location permission ────────────────────────────────────────────────────

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

  // ── Sharing location ───────────────────────────────────────────────────────

  Future<void> _toggleSharing() async {
    if (_isSharing) {
      await _bgLocation.stopSharing();
      if (mounted) setState(() => _isSharing = false);
      return;
    }
    // Request "always" permission before sharing so background uploads work
    final permission = await Geolocator.checkPermission();
    if (permission != LocationPermission.always) {
      final granted = await Geolocator.requestPermission();
      if (granted != LocationPermission.always && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Background location required to share position. Please grant "Always" access in Settings.'),
        ));
        return;
      }
      // Restart tracking stream with background settings
      _positionSub?.cancel();
      await _bgLocation.startTracking();
    }

    if (!mounted) return;
    final result = await _showShareDialog();
    if (result == null) return;

    final joined = await _bgLocation.startSharing(name: result.$1, pin: result.$2);
    if (!mounted) return;
    if (joined) {
      setState(() => _isSharing = true);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Sharing as ${result.$1} — ID: ${_bgLocation.trackerId ?? '?'}')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not join — check name and PIN')),
      );
    }
  }

  Future<(String, String)?> _showShareDialog() async {
    final nameCtl = TextEditingController();
    final pinCtl = TextEditingController();
    return showDialog<(String, String)?>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Share Location'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: nameCtl,
              decoration: const InputDecoration(labelText: 'Your Name'),
              textCapitalization: TextCapitalization.words,
            ),
            const SizedBox(height: 8),
            TextField(
              controller: pinCtl,
              decoration: const InputDecoration(labelText: 'PIN'),
              keyboardType: TextInputType.number,
              obscureText: true,
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          TextButton(
            onPressed: () {
              final name = nameCtl.text.trim();
              final pin = pinCtl.text.trim();
              if (name.isNotEmpty && pin.isNotEmpty) Navigator.pop(ctx, (name, pin));
            },
            child: const Text('Join'),
          ),
        ],
      ),
    );
  }

  // ── Map controls ───────────────────────────────────────────────────────────

  void _handleRecenter() {
    final userPos = _lastUserLatLng;
    if (userPos == null) return;
    final double zoom = _recenterCount == 0
        ? MapConfig.initialZoom
        : (_mapController.camera.zoom + 1).clamp(MapConfig.minZoom, MapConfig.maxZoom);
    _mapController.move(userPos, zoom);
    setState(() => _recenterCount++);
  }

  void _handleReset() {
    _mapController.move(
      LatLng(_config.mapLat, _config.mapLon),
      _config.mapZoom,
    );
    setState(() => _recenterCount = 0);
  }

  Future<void> _reloadConfig() async {
    final fresh = await ConfigService().load();
    if (!mounted) return;
    setState(() => _config = fresh);
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Config reloaded'), duration: Duration(seconds: 2)),
    );
  }

  Future<void> _refreshTiles() async {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => DownloadScreen(config: _config, forceRefresh: true)),
    );
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
    setState(() => _recenterCount = 0);
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawer: MenuDrawer(
        config: _config,
        isSharing: _isSharing,
        onReload: _reloadConfig,
        onShareToggle: _toggleSharing,
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

    return Builder(
      builder: (context) => Stack(
        children: [
          Listener(
            onPointerPanZoomUpdate: Platform.isMacOS ? _handleTrackpadZoom : null,
            child: FlutterMap(
              mapController: _mapController,
              options: MapOptions(
                initialCenter: LatLng(_config.mapLat, _config.mapLon),
                initialZoom: _config.mapZoom,
                minZoom: MapConfig.minZoom,
                maxZoom: MapConfig.maxZoom,
                backgroundColor: Colors.black,
                interactionOptions: InteractionOptions(
                  flags: Platform.isMacOS
                      ? InteractiveFlag.all & ~InteractiveFlag.pinchMove
                      : InteractiveFlag.all,
                ),
                cameraConstraint: CameraConstraint.containCenter(
                  bounds: LatLngBounds(MapConfig.downloadSW, MapConfig.downloadNE),
                ),
                onMapEvent: (event) {
                  if (event is MapEventMoveStart &&
                      event.source != MapEventSource.mapController) {
                    setState(() => _recenterCount = 0);
                  }
                },
              ),
              children: [
                TileLayer(
                  urlTemplate: MapConfig.tileUrl,
                  userAgentPackageName: 'org.marsaprs.aprs_map',
                  tileProvider: FMTCStore(MapConfig.storeName).getTileProvider(),
                ),
                if (_config.courses.isNotEmpty)
                  CourseLayer(courses: _config.courses),
                // APRS tracker markers (online only — empty list when offline)
                TrackerLayer(trackers: _trackers),
                // Always-on user position blue dot
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

          // Offline banner — centered top
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

          // Sharing indicator — below mode indicator when active
          if (_isSharing)
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
                    child: const Text('Sharing', style: TextStyle(color: Colors.white, fontSize: 11)),
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
