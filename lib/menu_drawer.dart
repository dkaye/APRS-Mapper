import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html/flutter_widget_from_html.dart';
import 'remote_config.dart';

class MenuDrawer extends StatelessWidget {
  final RemoteConfig config;
  final bool isSharing;
  final Future<void> Function()? onReload;
  final Future<void> Function()? onShareToggle;
  final Future<void> Function()? onRefreshTiles;

  const MenuDrawer({
    super.key,
    required this.config,
    this.isSharing = false,
    this.onReload,
    this.onShareToggle,
    this.onRefreshTiles,
  });

  @override
  Widget build(BuildContext context) {
    return Drawer(
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              color: const Color(0xFF2C3E50),
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'APRS Map',
                    style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold),
                  ),
                  if (config.event.isNotEmpty)
                    Text(
                      config.event,
                      style: const TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: EdgeInsets.zero,
                children: [
                  // Share Location
                  if (config.mobileEnabled)
                    ListTile(
                      leading: Icon(
                        isSharing ? Icons.location_off : Icons.share_location,
                        color: isSharing ? Colors.red : null,
                      ),
                      title: Text(isSharing ? 'Stop Sharing Location' : 'Share My Location'),
                      subtitle: isSharing
                          ? const Text('Sending your position to the map',
                              style: TextStyle(color: Colors.green))
                          : const Text('Appear as a tracker on the map'),
                      onTap: () async {
                        Navigator.pop(context);
                        if (onShareToggle != null) await onShareToggle!();
                      },
                    ),

                  if (config.mobileEnabled) const Divider(),

                  // Reload config
                  ListTile(
                    leading: const Icon(Icons.sync),
                    title: const Text('Reload from Server'),
                    onTap: () async {
                      Navigator.pop(context);
                      if (onReload != null) await onReload!();
                    },
                  ),

                  // Refresh offline tiles
                  ListTile(
                    leading: const Icon(Icons.download_for_offline),
                    title: const Text('Refresh Offline Map'),
                    subtitle: const Text('Re-download Marin County tiles'),
                    onTap: () async {
                      Navigator.pop(context);
                      if (onRefreshTiles != null) await onRefreshTiles!();
                    },
                  ),

                  const Divider(),

                  if (config.attribution.isNotEmpty)
                    ListTile(
                      leading: const Icon(Icons.map_outlined),
                      title: const Text('About'),
                      onTap: () {
                        Navigator.pop(context);
                        showDialog(
                          context: context,
                          builder: (ctx) => AlertDialog(
                            title: const Text('About'),
                            content: Text(config.attribution),
                            actions: [
                              TextButton(
                                onPressed: () => Navigator.pop(ctx),
                                child: const Text('Close'),
                              ),
                            ],
                          ),
                        );
                      },
                    ),

                  if (config.copyright.isNotEmpty)
                    ListTile(
                      leading: const Icon(Icons.copyright),
                      title: const Text('Copyright'),
                      onTap: () {
                        Navigator.pop(context);
                        showDialog(
                          context: context,
                          builder: (ctx) => AlertDialog(
                            title: const Text('Copyright'),
                            content: Text(config.copyright),
                            actions: [
                              TextButton(
                                onPressed: () => Navigator.pop(ctx),
                                child: const Text('Close'),
                              ),
                            ],
                          ),
                        );
                      },
                    ),

                  if (config.helpHtml.isNotEmpty)
                    ListTile(
                      leading: const Icon(Icons.help_outline),
                      title: const Text('Help'),
                      onTap: () {
                        Navigator.pop(context);
                        showDialog(
                          context: context,
                          builder: (_) => Dialog(
                            insetPadding: const EdgeInsets.all(16),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Container(
                                  color: const Color(0xFF2C3E50),
                                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                                  child: Row(
                                    children: [
                                      const Expanded(
                                        child: Text('Help',
                                            style: TextStyle(
                                                color: Colors.white,
                                                fontSize: 16,
                                                fontWeight: FontWeight.bold)),
                                      ),
                                      IconButton(
                                        icon: const Icon(Icons.close, color: Colors.white),
                                        onPressed: () => Navigator.pop(context),
                                      ),
                                    ],
                                  ),
                                ),
                                Flexible(
                                  child: SingleChildScrollView(
                                    padding: const EdgeInsets.all(16),
                                    child: HtmlWidget(config.helpHtml),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
