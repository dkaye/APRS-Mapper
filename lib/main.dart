import 'package:flutter/material.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'config_service.dart';
import 'download_screen.dart';
import 'map_config.dart';
import 'map_screen.dart';
import 'remote_config.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
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
            ? MapScreen(config: widget.config)
            : DownloadScreen(config: widget.config),
      ),
    );
  }

  @override
  Widget build(BuildContext context) =>
      const Scaffold(body: Center(child: CircularProgressIndicator()));
}
