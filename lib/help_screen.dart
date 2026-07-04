import 'dart:io';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'map_config.dart';

class HelpScreen extends StatelessWidget {
  final bool isOnline;
  final bool isFirstLaunch;

  const HelpScreen({super.key, required this.isOnline, this.isFirstLaunch = false});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Quick Start'),
        backgroundColor: const Color(0xFF2C3E50),
        foregroundColor: Colors.white,
        actions: [
          TextButton(
            onPressed: () async {
              if (isFirstLaunch) {
                final prefs = await SharedPreferences.getInstance();
                await prefs.setBool('help_seen', true);
              }
              if (context.mounted) Navigator.pop(context);
            },
            style: TextButton.styleFrom(foregroundColor: Colors.white),
            child: const Text('Continue', style: TextStyle(fontSize: 15)),
          ),
        ],
      ),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 20, 16, 32 + MediaQuery.of(context).padding.bottom),
        children: [

          if (isFirstLaunch)
            Container(
              margin: const EdgeInsets.only(bottom: 20),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: const Color(0xFFE8F4FD),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF90CAF9)),
              ),
              child: const Text(
                'You can always view this page by tapping Help → Quick Start in the sidebar menu.',
                style: TextStyle(fontSize: 13, color: Color(0xFF1565C0), height: 1.4),
              ),
            ),

          _section('The Map', Icons.map_outlined, [
            _tip('Pan, zoom or rotate the map as you would in other apps.'),
		    _tipWidget(Text.rich(TextSpan(children: [
		      const TextSpan(text: 'Tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		      WidgetSpan(child: Icon(Icons.restart_alt, size: 16, color: Colors.grey), alignment: PlaceholderAlignment.middle),
		      const TextSpan(text: ' to reset the map, or ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		      WidgetSpan(child: Icon(Icons.my_location, size: 16, color: Color(0xFF1565C0)), alignment: PlaceholderAlignment.middle),
		      const TextSpan(text: ' to center on your current location.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		    ]))),
		 ]),
		
          _section('The Sidebar', Icons.map_outlined, [
            _tip('Open the sidebar by tapping ☰ in the top-left corner, or by swiping right from the left edge.'),
            _tip('Colored dots show the current location of each tracker.'),
            Padding(
              padding: const EdgeInsets.only(left: 14),
              child: Column(children: [
                _colorTip(const Color(0xFF43A047), 'Green', 'Reported within the last 2 minutes.'),
                _colorTip(const Color(0xFF1E88E5), 'Blue', 'Reported 2–5 minutes ago.'),
                _colorTip(const Color(0xFFE53935), 'Red', 'No report for more than 5 minutes.'),
              ]),
            ),
            _tip('Marker shapes show how a tracker\'s position is reported:'),
            _indent('Circle (or other configured shape) — radio tracker via APRS radio and iGates.'),
            _indent('Rounded square — mobile-only, sharing via this app with no ham radio.'),
            _indent('Triangle — hybrid: both this app and a licensed ham radio simultaneously.'),
            _tip('In the sidebar: tap any Tracker, Aid/Rest Stop, or iGate to close the menu, center the map on it, and blink its marker.'),
            _tip('In the sidebar: long-press any Tracker, Aid/Rest Stop, or iGate to do the same and also zoom in.'),
            _tip('On the map: tap a marker to see its details. Long-press any marker to open Google Maps centered on that location.'),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'Hide/show sections of the sidebar or individual Courses by tapping the ', 
			  style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              WidgetSpan(child: Icon(Icons.visibility, size: 16, color: Colors.grey), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: '.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
            ]))),
		    _tipWidget(Text.rich(TextSpan(children: [
		      const TextSpan(text: 'Tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		      WidgetSpan(child: Icon(Icons.push_pin, size: 16, color: Color(0xFF333333)), alignment: PlaceholderAlignment.middle),
		      const TextSpan(text: ' Save Map to record the map\'s current position, zoom and rotation, which will then be restored when you tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		      WidgetSpan(child: Icon(Icons.restart_alt, size: 16, color: Colors.grey), alignment: PlaceholderAlignment.middle),
		      const TextSpan(text: ' to reset.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
		    ]))),
          ]), 
		  
          _section('Share Location  (☰ menu)', Icons.share_location, [
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'So that others can see your location on the map, tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              WidgetSpan(child: Icon(Icons.share_location, size: 16, color: Color(0xFF333333)), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' Share Location.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
          ]))),
            _tip('Enter your first name (pre-filled from your last session) and the event PIN — both shown as plain text.'),
            _tip('Optional: tap "Ham Radio Callsign" to enter your licensed ham callsign and SSID. This makes you a hybrid tracker — your position comes from both the app and your radio, and your marker appears as a triangle on the map.'),
            _tip('Tap an activity to start sharing immediately:'),
            _indent('Walk / Run — sends your position every 60 seconds.'),
            _indent('Cycle — sends your position every 30 seconds, or immediately when you move ≥ 0.2 mile.'),
            _indent('Drive — sends your position every 15 seconds, or immediately when you move ≥ 0.2 mile.'),
            _indent('Stationary — sends your position every 2 minutes.'),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'While sharing, tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              WidgetSpan(child: Icon(Icons.share_location, size: 16, color: Colors.green[700]), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' Sharing to change your activity mode or stop.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
            ]))),
            _tip('Your position keeps updating even with the screen locked or the app in the background so long as you don\'t stop the app.'),
            _tip('iOS only: if asked, tap "Change to Always Allow" to enable background tracking.'),
            _tip('Android only: grant Notifications and allow battery optimization when prompted the first time.'),
          ]),

          _section('Messaging  (while sharing)', Icons.chat_bubble_outline, [
            _tip('While sharing your location, net control can send you text messages.'),
            _tip('An incoming message plays a three-repeat tone and shows a pop-up dialog with the sender\'s name and text.'),
            _tip('If the app is in the background, a notification appears. Tap it to open the app — the message dialog opens automatically.'),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'Tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              const TextSpan(text: 'Reply', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Color(0xFF333333), height: 1.4)),
              const TextSpan(text: ' to respond, or tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              WidgetSpan(child: Icon(Icons.chat_bubble_outline, size: 16, color: Color(0xFF333333)), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' Message in the drawer footer to send a new message.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
            ]))),
          ]),

          _section('Offline Use', Icons.download_for_offline_outlined, [
            _tip('The app will continue to operate even if you have no WiFi or cellular connection to the internet.'),
            _indent('Your location will not be seen by others.'),
            _indent('You will not see other\'s locations.'),
          ]),

          const SizedBox(height: 8),
          _fullGuideButton(context),
          if (isFirstLaunch) ...[
            const SizedBox(height: 16),
            _continueButton(context),
          ],
        ],
      ),
    );
  }

  Widget _section(String title, IconData icon, List<Widget> children) => Padding(
        padding: const EdgeInsets.only(bottom: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(icon, size: 16, color: const Color(0xFF2C3E50)),
              const SizedBox(width: 6),
              Text(title,
                  style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF2C3E50),
                      letterSpacing: 0.2)),
            ]),
            const SizedBox(height: 8),
            ...children,
          ],
        ),
      );

  Widget _tip(String text) => _tipWidget(Text(text,
      style: const TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)));

  Widget _tipWidget(Widget content) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('• ', style: TextStyle(fontSize: 14, color: Color(0xFF555555))),
            Expanded(child: content),
          ],
        ),
      );

  Widget _iconTip(IconData icon, Color iconColor, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 1, right: 6),
              child: Icon(icon, size: 14, color: iconColor),
            ),
            Expanded(
              child: Text(text,
                  style: const TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
            ),
          ],
        ),
      );

  Widget _indent(String text) => Padding(
        padding: const EdgeInsets.only(left: 14, bottom: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('– ', style: TextStyle(fontSize: 14, color: Color(0xFF888888))),
            Expanded(
              child: Text(text,
                  style: const TextStyle(fontSize: 13, color: Color(0xFF555555), height: 1.4)),
            ),
          ],
        ),
      );

  Widget _colorTip(Color color, String label, String description) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 12,
              height: 12,
              margin: const EdgeInsets.only(top: 2, right: 8),
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            ),
            Text('$label  ',
                style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: color)),
            Expanded(
              child: Text(description,
                  style: const TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
            ),
          ],
        ),
      );

  Widget _continueButton(BuildContext context) => Center(
        child: FilledButton(
          onPressed: () async {
            final prefs = await SharedPreferences.getInstance();
            await prefs.setBool('help_seen', true);
            if (context.mounted) Navigator.pop(context);
          },
          style: FilledButton.styleFrom(
            backgroundColor: const Color(0xFF2C3E50),
            padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 14),
          ),
          child: const Text('Continue', style: TextStyle(fontSize: 15)),
        ),
      );

  Widget _fullGuideButton(BuildContext context) => Center(
        child: OutlinedButton.icon(
          onPressed: isOnline
              ? () => launchUrl(
                    Uri.parse('${MapConfig.serverBaseUrl}/userguide.html'),
                    mode: LaunchMode.externalApplication,
                  )
              : null,
          icon: const Icon(Icons.menu_book_outlined, size: 16),
          label: Text(
            isOnline ? 'Open Full User Guide' : 'Full User Guide (requires internet)',
            style: const TextStyle(fontSize: 13),
          ),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          ),
        ),
      );
}
