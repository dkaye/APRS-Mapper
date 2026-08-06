/// Banner shown when the server reports it no longer supports this app's API
/// contract (server `api.min_client` > MapConfig.clientApiVersion). Dismissible;
/// stays hidden in the normal case where a newer server still supports this app.
import 'package:flutter/material.dart';

class UpdateBanner extends StatelessWidget {
  final VoidCallback? onDismiss;

  const UpdateBanner({super.key, this.onDismiss});

  @override
  Widget build(BuildContext context) {
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      child: SafeArea(
        child: Center(
          child: Container(
            margin: const EdgeInsets.only(top: 8),
            padding: const EdgeInsets.fromLTRB(14, 6, 8, 6),
            decoration: BoxDecoration(
              color: const Color(0xFFB9770E), // amber-brown, distinct from the offline banner
              borderRadius: BorderRadius.circular(20),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.system_update, color: Colors.white70, size: 16),
                const SizedBox(width: 6),
                const Text(
                  'Please update APRS Map to keep using live data',
                  style: TextStyle(color: Colors.white, fontSize: 13),
                ),
                const SizedBox(width: 4),
                GestureDetector(
                  onTap: onDismiss,
                  behavior: HitTestBehavior.opaque,
                  child: const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                    child: Icon(Icons.close, color: Colors.white70, size: 16),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
