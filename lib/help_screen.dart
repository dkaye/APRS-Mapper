/// Built-in Quick Start guide screen, displayed on first launch and accessible
/// from the Help button in the drawer footer.
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'map_config.dart';

/// Release date shown under the Quick Start title. The version number itself
/// comes from PackageInfo (i.e. pubspec.yaml), but the date has no such source —
/// bump it by hand alongside the version.
const kGuideDate = 'July 22, 2026';

const _tipStyle = TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4);
const _tipStyleBold = TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4, fontWeight: FontWeight.w600);
const _tipStyleItalic = TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4, fontStyle: FontStyle.italic);

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

          FutureBuilder<PackageInfo>(
            future: PackageInfo.fromPlatform(),
            builder: (_, snap) => Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: Text(
                snap.hasData
                    ? 'Version ${snap.data!.version}+${snap.data!.buildNumber} · $kGuideDate'
                    : '',
                style: const TextStyle(fontSize: 12, color: Color(0xFF888888)),
              ),
            ),
          ),

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
            _shapeTip(_ShapeCircle(), 'Circle (or other configured shape) — radio tracker via APRS radio and iGates.'),
            _shapeTip(_ShapeSquare(), 'Rounded square — mobile-only, sharing via this app with no ham radio.'),
            _shapeTip(_ShapeTriangle(), 'Triangle — hybrid: both this app and a licensed ham radio simultaneously.'),
            _tip('In the sidebar: tap any Tracker, Aid/Rest Stop, or iGate to close the menu, center the map on it, and blink its marker.'),
            _tip('In the sidebar: long-press any Tracker, Aid/Rest Stop, or iGate to do the same and also zoom in.'),
            _tip('On the map: tap a marker to see its details. Long-press any marker to open Google Maps centered on that location.'),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'The ', style: _tipStyle),
              WidgetSpan(child: Icon(Icons.visibility, size: 16, color: Colors.grey[600]), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' eye at the right of each section header (Trackers, Courses, Aid/Rest Stops, iGates) shows or hides everything in that section on the map. Each Course also has its own eye.', style: _tipStyle),
            ]))),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'The Trackers header has ', style: _tipStyle),
              const TextSpan(text: 'two more eyes', style: _tipStyleBold),
              const TextSpan(text: ' to the left of that one. These don\'t hide the markers — they choose what the map labels say. The first eye controls the tracker ', style: _tipStyle),
              const TextSpan(text: 'ID', style: _tipStyleBold),
              const TextSpan(text: ', the second controls the tracker ', style: _tipStyle),
              const TextSpan(text: 'Name', style: _tipStyleBold),
              const TextSpan(text: '.', style: _tipStyle),
            ]))),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'A dimmed, slashed ', style: _tipStyle),
              WidgetSpan(child: Icon(Icons.visibility_off, size: 16, color: Colors.grey[400]), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' means that part is switched off. With both tracker eyes on a label reads ', style: _tipStyle),
              const TextSpan(text: 'M083 James', style: _tipStyleItalic),
              const TextSpan(text: '; with only the ID eye on it reads ', style: _tipStyle),
              const TextSpan(text: 'M083', style: _tipStyleItalic),
              const TextSpan(text: '. Turn both off and the tracker labels disappear while the markers stay on the map — useful when a crowded course turns into a wall of text.', style: _tipStyle),
            ]))),
            _tip('All of these choices are remembered the next time you open the app.'),
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
            _tip('Tap Share Location to start. Sharing begins in unknown (?) mode while Smart Track takes its first GPS readings — typically within 90 seconds it determines your activity and sets the beacon interval automatically: walk/run (60 s), cycle (30 s), drive (15 s), or stationary (2 min). No selection needed.'),
            _tipWidget(Text.rich(TextSpan(children: [
              const TextSpan(text: 'While sharing, tap ', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
              WidgetSpan(child: Icon(Icons.share_location, size: 16, color: Colors.green[700]), alignment: PlaceholderAlignment.middle),
              const TextSpan(text: ' Sharing to stop.', style: TextStyle(fontSize: 14, color: Color(0xFF333333), height: 1.4)),
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
            _tip('If more than one operator is monitoring messages, pick one from the To: dropdown. '
                 'Whoever you choose becomes the default for your next message, so you don\'t have to '
                 'choose again each time. If that operator stops monitoring, you\'ll be asked to pick again.'),
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

  Widget _shapeTip(Widget shape, String text) => Padding(
        padding: const EdgeInsets.only(left: 14, bottom: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 3, right: 8),
              child: shape,
            ),
            Expanded(
              child: Text(text,
                  style: const TextStyle(fontSize: 13, color: Color(0xFF555555), height: 1.4)),
            ),
          ],
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

class _ShapeCircle extends StatelessWidget {
  const _ShapeCircle();
  @override
  Widget build(BuildContext context) => Container(
        width: 11, height: 11,
        decoration: BoxDecoration(
          color: const Color(0xFF888888),
          shape: BoxShape.circle,
          border: Border.all(color: Colors.white, width: 1.5),
        ),
      );
}

class _ShapeSquare extends StatelessWidget {
  const _ShapeSquare();
  @override
  Widget build(BuildContext context) => Container(
        width: 11, height: 11,
        decoration: BoxDecoration(
          color: const Color(0xFF888888),
          borderRadius: BorderRadius.circular(2),
          border: Border.all(color: Colors.white, width: 1.5),
        ),
      );
}

class _ShapeTriangle extends StatelessWidget {
  const _ShapeTriangle();
  @override
  Widget build(BuildContext context) => CustomPaint(
        size: const Size(11, 11),
        painter: _TrianglePainter(),
      );
}

class _TrianglePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final fill = Paint()..color = const Color(0xFF888888);
    final border = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    final path = Path()
      ..moveTo(size.width / 2, 0)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(path, fill);
    canvas.drawPath(path, border);
  }

  @override
  bool shouldRepaint(_TrianglePainter old) => false;
}
