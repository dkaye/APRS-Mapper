/// App entry point. Initializes Flutter, requests Android foreground-service
/// permissions, and launches MapScreen.
import 'dart:async' show unawaited;
import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'config_service.dart';
import 'download_screen.dart';
import 'map_config.dart';
import 'password_gate_screen.dart';
import 'remote_config.dart';
import 'watch_bridge.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Before the router runs: WatchConnectivity can cold-launch this app in the
  // background to deliver a watch message, and StartupRouter may never reach
  // MapScreen on that path. The bridge has to exist regardless of which screen wins.
  unawaited(WatchBridge.instance.init());
  // Required for sendDataToTask / addTaskDataCallback communication channel.
  // iOS has no foreground task; calling this on iOS can enable a wake lock that
  // prevents auto-lock even when the user isn't sharing.
  if (Platform.isAndroid) FlutterForegroundTask.initCommunicationPort();
  await FMTCObjectBoxBackend().initialise();
  await FMTCStore(MapConfig.storeName).manage.create();
  final config = await ConfigService().load();
  runApp(AprsMapApp(config: config));
}

class AprsMapApp extends StatelessWidget {
  final RemoteConfig config;

  const AprsMapApp({super.key, required this.config});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'APRS Map',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.blue),
        useMaterial3: true,
      ),
      home: StartupRouter(config: config),
    );
  }
}

class StartupRouter extends StatefulWidget {
  final RemoteConfig config;

  const StartupRouter({super.key, required this.config});

  @override
  State<StartupRouter> createState() => _StartupRouterState();
}

class _StartupRouterState extends State<StartupRouter> {
  @override
  void initState() {
    super.initState();
    _route();
  }

  Future<void> _route() async {
    final length = await FMTCStore(MapConfig.storeName).stats.length;
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => length > 0
            ? PasswordGateScreen(config: widget.config)
            : DownloadScreen(config: widget.config),
      ),
    );
  }

  @override
  Widget build(BuildContext context) =>
      const Scaffold(body: Center(child: CircularProgressIndicator()));
}
