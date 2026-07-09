/// View displayed when location permissions have been denied, with instructions
/// for re-enabling them in the device Settings app.
import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';

class PermissionDeniedView extends StatelessWidget {
  final bool permanent;

  const PermissionDeniedView({super.key, required this.permanent});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.location_off, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            Text(
              permanent
                  ? 'Location access is disabled. Enable it in Settings to see your position on the map.'
                  : 'Location access was denied. The map will still display, but your position won\'t be shown.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16),
            ),
            if (permanent) ...[
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: openAppSettings,
                child: const Text('Open Settings'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
