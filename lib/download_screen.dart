import 'dart:async';
import 'dart:math' show cos, pi, pow;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'map_config.dart';
import 'password_gate_screen.dart';
import 'remote_config.dart';

class DownloadScreen extends StatefulWidget {
  final RemoteConfig config;
  final bool forceRefresh;

  const DownloadScreen({super.key, required this.config, this.forceRefresh = false});

  @override
  State<DownloadScreen> createState() => _DownloadScreenState();
}

class _DownloadScreenState extends State<DownloadScreen> {
  // True once the user has confirmed (or we've verified tiles already exist).
  // Starts false; _init() resolves it after checking the cache.
  bool _confirmed = false;
  bool _checkingCache = true;

  DownloadProgress? _progress;
  DownloadProgress? _finalProgress;
  StreamSubscription<DownloadProgress>? _sub;
  String? _errorMessage;
  bool _downloadComplete = false;
  Timer? _completionTimer;

  static int _nextId = 1;
  late final int _instanceId = _nextId++;

  // Estimated tile count for the configured region, computed once.
  late final int _estimatedTiles = _calcEstimatedTiles();

  @override
  void initState() {
    super.initState();
    _init();
  }

  // Show the prompt only when the user hasn't explicitly consented to
  // downloading tiles.  We track consent in SharedPreferences rather than
  // infer it from tile count — the cache persists across updates so tile
  // count alone can't tell us whether the user has actively agreed.
  static const _consentKey = 'tiles_download_consented';

  Future<void> _init() async {
    final prefs = await SharedPreferences.getInstance();
    final hasConsented = prefs.getBool(_consentKey) ?? false;
    if (!mounted) return;
    setState(() {
      _checkingCache = false;
      _confirmed = hasConsented;
    });
    if (_confirmed) _startDownload();
  }

  @override
  void dispose() {
    _completionTimer?.cancel();
    _sub?.cancel();
    if (!_downloadComplete) {
      FMTCStore(MapConfig.storeName).download.cancel(instanceId: _instanceId);
    }
    super.dispose();
  }

  // ── Tile-count estimator ───────────────────────────────────────────────────
  // Sums tiles across all zoom levels for the configured bounding box using
  // standard Web-Mercator tile math. Intentionally over-estimates slightly
  // (skip-sea-tiles optimisation will reduce the actual download).
  int _calcEstimatedTiles() {
    final radius = widget.config.offlineRadiusMiles ?? MapConfig.downloadRadiusMiles;
    final center = LatLng(widget.config.mapLat, widget.config.mapLon);
    final latDelta = radius / 69.0;
    final lonDelta = radius / (69.0 * cos(center.latitude * pi / 180));
    int total = 0;
    final maxZoom = widget.config.offlineMaxZoom;
    for (int z = MapConfig.downloadMinZoom; z <= maxZoom; z++) {
      final tilesPerDeg = pow(2, z) / 360.0;
      final nx = (2 * lonDelta * tilesPerDeg).ceil() + 1;
      final ny = (2 * latDelta * tilesPerDeg).ceil() + 1;
      total += nx * ny;
    }
    return total;
  }

  // Converts tile count to a human-readable size string.
  // Assumes ~18 KB per tile (typical outdoor raster tile average).
  String get _estimatedSizeLabel {
    final kb = _estimatedTiles * 18;
    final mb = kb ~/ 1024;
    if (mb < 2) return 'a few MB';
    if (mb < 20) return 'about $mb MB';
    return 'up to $mb MB';
  }

  // ── Download ───────────────────────────────────────────────────────────────

  Future<void> _startDownload() async {
    _sub?.cancel();
    _sub = null;

    await FMTCStore(MapConfig.storeName)
        .download
        .cancel(instanceId: _instanceId)
        .timeout(const Duration(seconds: 3), onTimeout: () {});

    if (widget.forceRefresh) {
      await FMTCStore(MapConfig.storeName).manage.reset();
    }

    final center = LatLng(widget.config.mapLat, widget.config.mapLon);
    final radius = widget.config.offlineRadiusMiles ?? MapConfig.downloadRadiusMiles;
    final latDelta = radius / 69.0;
    final lonDelta = radius / (69.0 * cos(center.latitude * pi / 180));
    final sw = LatLng(center.latitude - latDelta, center.longitude - lonDelta);
    final ne = LatLng(center.latitude + latDelta, center.longitude + lonDelta);
    final region = RectangleRegion(LatLngBounds(sw, ne));

    final downloadable = region.toDownloadable(
      minZoom: MapConfig.downloadMinZoom,
      maxZoom: widget.config.offlineMaxZoom,
      options: TileLayer(urlTemplate: widget.config.offlineTileUrl),
    );

    final streams = FMTCStore(MapConfig.storeName).download.startForeground(
      region: downloadable,
      instanceId: _instanceId,
      disableRecovery: true,
      parallelThreads: 5,
      maxBufferLength: 200,
      skipExistingTiles: !widget.forceRefresh,
      skipSeaTiles: true,
    );

    _sub = streams.downloadProgress.listen(
      (progress) {
        setState(() => _progress = progress);
      },
      onDone: () {
        final p = _progress;
        if (p != null && !_downloadComplete) {
          _downloadComplete = true;
          setState(() => _finalProgress = p);
          _completionTimer = Timer(const Duration(seconds: 2), _goToMap);
        }
      },
      onError: (e) {
        setState(() => _errorMessage = e.toString());
      },
    );
  }

  void _goToMap() {
    _completionTimer?.cancel();
    if (mounted) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => PasswordGateScreen(config: widget.config)),
      );
    }
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_checkingCache) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (!_confirmed) return _buildPrompt();

    final progress = _progress;
    final final_ = _finalProgress;

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(
                final_ != null ? Icons.check_circle_rounded : Icons.download_rounded,
                size: 64,
                color: final_ != null ? Colors.green : Colors.blue,
              ),
              const SizedBox(height: 24),
              Text(
                final_ != null
                    ? 'Map Ready'
                    : widget.forceRefresh
                        ? 'Refreshing Offline Map'
                        : 'Downloading Offline Map',
                style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                '${(widget.config.offlineRadiusMiles ?? MapConfig.downloadRadiusMiles).toStringAsFixed(0)} mi radius · '
                'zoom ${MapConfig.downloadMinZoom}–${widget.config.offlineMaxZoom}',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.grey, fontSize: 11, fontFamily: 'monospace'),
              ),
              const SizedBox(height: 40),
              if (_errorMessage != null) ...[
                Text(
                  'Download error: $_errorMessage',
                  style: const TextStyle(color: Colors.red),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                ElevatedButton(
                  onPressed: () {
                    setState(() {
                      _errorMessage = null;
                      _progress = null;
                      _finalProgress = null;
                    });
                    _startDownload();
                  },
                  child: const Text('Retry'),
                ),
              ] else if (final_ != null) ...[
                Text(
                  _completionSummary(final_),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 15),
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  onPressed: _goToMap,
                  child: const Text('Open Map'),
                ),
              ] else if (progress == null) ...[
                const CircularProgressIndicator(),
                const SizedBox(height: 16),
                const Text('Preparing download...', textAlign: TextAlign.center),
              ] else ...[
                LinearProgressIndicator(
                  value: progress.percentageProgress / 100,
                  minHeight: 8,
                  borderRadius: BorderRadius.circular(4),
                ),
                const SizedBox(height: 16),
                Text(
                  '${progress.successfulTilesCount} / ${progress.maxTilesCount} tiles'
                  '  •  ${progress.percentageProgress.toStringAsFixed(0)}%',
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 15),
                ),
                if (progress.elapsedDuration.inSeconds > 2) ...[
                  const SizedBox(height: 8),
                  Text(
                    _eta(progress.estRemainingDuration),
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.grey, fontSize: 13),
                  ),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }

  // Shown on first launch before any download begins.
  Widget _buildPrompt() {
    final radius = (widget.config.offlineRadiusMiles ?? MapConfig.downloadRadiusMiles)
        .toStringAsFixed(0);
    final minZ = MapConfig.downloadMinZoom;
    final maxZ = widget.config.offlineMaxZoom;

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Icon(Icons.download_for_offline_outlined,
                  size: 64, color: Colors.blue),
              const SizedBox(height: 24),
              const Text(
                'Download Offline Map?',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 20),
              Text(
                'Map tiles for a $radius-mile radius around the event will be '
                'saved to your device (zoom levels $minZ–$maxZ).\n\n'
                'Estimated download size: $_estimatedSizeLabel.\n\n'
                'Once downloaded, the map works without an internet connection.',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 15, height: 1.55),
              ),
              const SizedBox(height: 40),
              FilledButton.icon(
                icon: const Icon(Icons.download_rounded),
                label: const Text('Download Map'),
                onPressed: () async {
                  final prefs = await SharedPreferences.getInstance();
                  await prefs.setBool(_consentKey, true);
                  setState(() => _confirmed = true);
                  _startDownload();
                },
              ),
              const SizedBox(height: 14),
              TextButton(
                onPressed: () async {
                  final prefs = await SharedPreferences.getInstance();
                  await prefs.setBool(_consentKey, false);
                  _goToMap();
                },
                child: const Text('Skip — use online map only'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _completionSummary(DownloadProgress p) {
    final downloaded = p.successfulTilesCount;
    final skipped = p.skippedTilesCount;
    if (downloaded == 0 && skipped > 0) {
      return '$skipped tile${skipped == 1 ? '' : 's'} already up to date';
    }
    if (downloaded == 0) {
      return 'No tiles to download for this area';
    }
    final parts = <String>['$downloaded tile${downloaded == 1 ? '' : 's'} downloaded'];
    if (skipped > 0) parts.add('$skipped skipped');
    return parts.join(' · ');
  }

  String _eta(Duration remaining) {
    final seconds = remaining.inSeconds;
    if (seconds < 60) return 'About $seconds seconds remaining';
    final minutes = remaining.inMinutes;
    return 'About $minutes minute${minutes == 1 ? '' : 's'} remaining';
  }
}
