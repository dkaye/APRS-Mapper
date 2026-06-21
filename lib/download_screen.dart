import 'dart:async';
import 'dart:math' show cos, pi;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:latlong2/latlong.dart';
import 'map_config.dart';
import 'map_screen.dart';
import 'remote_config.dart';

class DownloadScreen extends StatefulWidget {
  final RemoteConfig config;
  final bool forceRefresh;

  const DownloadScreen({super.key, required this.config, this.forceRefresh = false});

  @override
  State<DownloadScreen> createState() => _DownloadScreenState();
}

class _DownloadScreenState extends State<DownloadScreen> {
  DownloadProgress? _progress;
  DownloadProgress? _finalProgress;
  StreamSubscription<DownloadProgress>? _sub;
  String? _errorMessage;
  bool _downloadComplete = false;
  Timer? _completionTimer;

  static int _nextId = 1;
  late final int _instanceId = _nextId++;

  @override
  void initState() {
    super.initState();
    _startDownload();
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

    final stream = FMTCStore(MapConfig.storeName).download.startForeground(
      region: downloadable,
      instanceId: _instanceId,
      disableRecovery: true,
      parallelThreads: 5,
      maxBufferLength: 200,
      skipExistingTiles: !widget.forceRefresh,
      skipSeaTiles: true,
    );

    _sub = stream.listen(
      (progress) {
        setState(() => _progress = progress);
        if (progress.isComplete) {
          _downloadComplete = true;
          _sub?.cancel();
          setState(() => _finalProgress = progress);
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
        MaterialPageRoute(builder: (_) => MapScreen(config: widget.config)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
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
                  '${progress.successfulTiles} / ${progress.maxTiles} tiles'
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

  String _completionSummary(DownloadProgress p) {
    final downloaded = p.successfulTiles;
    final skipped = p.skippedTiles;
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
