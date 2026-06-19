import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html/flutter_widget_from_html.dart';
import 'package:url_launcher/url_launcher.dart';
import 'remote_config.dart';
import 'tracker_data.dart';

class MenuDrawer extends StatefulWidget {
  final RemoteConfig config;
  final List<TrackerData> trackers;
  final bool isSharing;
  final bool isOnline;
  final String? selectedId;
  final String selectedBgUrl;
  final Map<String, bool> sectionVisible;
  final Map<String, bool> courseVisible;
  final void Function(TrackerData)? onTrackerTap;
  final void Function(TrackerData)? onTrackerLongPress;
  final void Function(FixedMarker)? onFixedTap;
  final void Function(BackgroundLayer)? onBackgroundChange;
  final void Function(String section, bool visible)? onSectionVisibility;
  final void Function(String courseFile, bool visible)? onCourseVisibility;
  final Future<void> Function()? onReload;
  final Future<void> Function()? onShareToggle;
  final VoidCallback? onResetMap;
  final Future<void> Function()? onRefreshTiles;
  final String? sharingCallsign;

  const MenuDrawer({
    super.key,
    required this.config,
    required this.selectedBgUrl,
    required this.sectionVisible,
    required this.courseVisible,
    this.trackers = const [],
    this.isSharing = false,
    this.isOnline = true,
    this.selectedId,
    this.onTrackerTap,
    this.onTrackerLongPress,
    this.onFixedTap,
    this.onBackgroundChange,
    this.onSectionVisibility,
    this.onCourseVisibility,
    this.onReload,
    this.onShareToggle,
    this.onResetMap,
    this.onRefreshTiles,
    this.sharingCallsign,
  });

  @override
  State<MenuDrawer> createState() => _MenuDrawerState();
}

class _MenuDrawerState extends State<MenuDrawer> {
  final _expanded = <String>{
    'trackers',
    'courses',
  };

  void _toggleSection(String key) =>
      setState(() => _expanded.contains(key) ? _expanded.remove(key) : _expanded.add(key));

  Color _trackerColor(String color) {
    switch (color) {
      case 'green': return const Color(0xFF43A047);
      case 'blue':  return const Color(0xFF1E88E5);
      default:      return const Color(0xFFE53935);
    }
  }

  Color _parseHex(String hex) {
    final clean = hex.replaceAll('#', '');
    if (clean.length != 6) return Colors.blue;
    return Color(int.parse('FF$clean', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    return Drawer(
      child: SafeArea(
        child: Column(
          children: [
            // ── Header ──────────────────────────────────────────────────────
            Container(
              color: const Color(0xFF2C3E50),
              width: double.infinity,
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('APRS Map',
                    style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                if (widget.config.event.isNotEmpty)
                  Text(widget.config.event,
                      style: const TextStyle(color: Colors.white70, fontSize: 12)),
              ]),
            ),

            // ── Scrollable body ─────────────────────────────────────────────
            Expanded(
              child: ListView(
                padding: EdgeInsets.zero,
                physics: const ClampingScrollPhysics(),
                children: [
                  if (widget.isOnline)
                    _section(
                      key: 'trackers',
                      title: 'Trackers',
                      hasVisToggle: true,
                      children: widget.trackers.isEmpty
                          ? [_empty('Waiting for tracker data…')]
                          : widget.trackers.map(_trackerTile).toList(),
                    ),

                  if (widget.config.courses.isNotEmpty)
                    _section(
                      key: 'courses',
                      title: 'Courses',
                      hasVisToggle: true,
                      children: widget.config.courses.map(_courseTile).toList(),
                    ),

                  if (widget.config.backgrounds.isNotEmpty)
                    _section(
                      key: 'backgrounds',
                      title: 'Backgrounds',
                      hasVisToggle: false,
                      children: widget.config.backgrounds.map(_bgTile).toList(),
                    ),

                  if (widget.config.aidStations.isNotEmpty)
                    _section(
                      key: 'aidstations',
                      title: 'Aid Stations',
                      hasVisToggle: true,
                      children: widget.config.aidStations.map(_fixedTile).toList(),
                    ),

                  if (widget.config.igates.isNotEmpty)
                    _section(
                      key: 'igates',
                      title: 'iGates',
                      hasVisToggle: true,
                      children: widget.config.igates.map(_fixedTile).toList(),
                    ),

                  if (widget.config.legend.isNotEmpty)
                    _section(
                      key: 'about',
                      title: 'About',
                      hasVisToggle: false,
                      children: [
                        Padding(
                          padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
                          child: HtmlWidget(widget.config.legend),
                        ),
                      ],
                    ),
                ],
              ),
            ),

            // ── Footer button grid ──────────────────────────────────────────
            const Divider(height: 1),
            Padding(
              padding: const EdgeInsets.all(10),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (widget.config.mobileEnabled)
                    _footerBtn(
                      widget.isSharing ? 'Stop Sharing' : 'Share Location',
                      widget.isSharing ? Icons.location_off : Icons.share_location,
                      () async {
                        Navigator.pop(context);
                        await widget.onShareToggle?.call();
                      },
                      color: widget.isSharing ? Colors.red[700] : null,
                    ),
                  _footerBtn('Reload Tiles', Icons.download_for_offline, () async {
                    Navigator.pop(context);
                    await widget.onRefreshTiles?.call();
                  }),
                  _footerBtn('About', Icons.info_outline, () {
                    Navigator.pop(context);
                    _showAboutDialog();
                  }),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Accordion section ──────────────────────────────────────────────────────

  Widget _section({
    required String key,
    required String title,
    required bool hasVisToggle,
    required List<Widget> children,
  }) {
    final open = _expanded.contains(key);
    final visible = widget.sectionVisible[key] ?? true;

    return Column(
      children: [
        InkWell(
          onTap: () => _toggleSection(key),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            child: Row(children: [
              Expanded(
                child: Text(title,
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Color(0xFF444444))),
              ),
              if (hasVisToggle)
                GestureDetector(
                  onTap: () => widget.onSectionVisibility?.call(key, !visible),
                  behavior: HitTestBehavior.opaque,
                  child: Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Icon(
                      visible ? Icons.visibility : Icons.visibility_off,
                      size: 18,
                      color: visible ? Colors.grey[600] : Colors.grey[400],
                    ),
                  ),
                ),
              Icon(open ? Icons.expand_less : Icons.expand_more,
                  size: 18, color: Colors.grey[500]),
            ]),
          ),
        ),
        if (open) ...children,
        const Divider(height: 1, indent: 0, endIndent: 0),
      ],
    );
  }

  // ── Tracker tile ───────────────────────────────────────────────────────────

  Widget _trackerTile(TrackerData t) {
    final color = _trackerColor(t.color);
    final isSelected = t.id == widget.selectedId;
    return InkWell(
      onTap: () {
        Navigator.pop(context);
        widget.onTrackerTap?.call(t);
      },
      onLongPress: () {
        Navigator.pop(context);
        widget.onTrackerLongPress?.call(t);
      },
      child: Container(
        color: isSelected ? Colors.blue.withOpacity(0.08) : null,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        child: Row(children: [
          Container(
            width: 10,
            height: 10,
            margin: const EdgeInsets.only(right: 8),
            decoration: BoxDecoration(
              color: color,
              shape: t.mobile ? BoxShape.rectangle : BoxShape.circle,
              borderRadius: t.mobile ? BorderRadius.circular(2) : null,
              border: Border.all(color: Colors.white, width: 1.5),
            ),
          ),
          Text(t.id,
              style: TextStyle(fontSize: 11, color: Colors.grey[600], fontWeight: FontWeight.w500)),
          const SizedBox(width: 6),
          Expanded(
            child: Text(t.name, style: const TextStyle(fontSize: 12), overflow: TextOverflow.ellipsis),
          ),
          Builder(builder: (_) {
            final age = DateTime.now().millisecondsSinceEpoch ~/ 1000 - t.lastUpdate;
            final label = (t.lastUpdate > 0 && age > 300) ? 'stale' : t.time;
            return Text(label, style: TextStyle(fontSize: 11, color: color));
          }),
        ]),
      ),
    );
  }

  // ── Course tile ────────────────────────────────────────────────────────────

  Widget _courseTile(CourseConfig c) {
    final color = _parseHex(c.color);
    final visible = widget.courseVisible[c.file] ?? c.visible;
    return InkWell(
      onTap: () => widget.onCourseVisibility?.call(c.file, !visible),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 3),
        child: Row(children: [
          Container(
            width: 16,
            height: 3,
            margin: const EdgeInsets.only(right: 8),
            decoration: BoxDecoration(
              color: visible ? color : Colors.grey[300],
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          Expanded(
            child: Text(c.name,
                style: TextStyle(
                    fontSize: 12,
                    color: visible ? null : Colors.grey[400])),
          ),
          Icon(
            visible ? Icons.visibility : Icons.visibility_off,
            size: 16,
            color: visible ? Colors.grey[600] : Colors.grey[400],
          ),
        ]),
      ),
    );
  }

  // ── Background tile ────────────────────────────────────────────────────────

  Widget _bgTile(BackgroundLayer bg) {
    final selected = widget.selectedBgUrl == bg.url;
    return InkWell(
      onTap: () => widget.onBackgroundChange?.call(bg),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        child: Row(children: [
          Icon(
            selected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
            size: 18,
            color: selected ? Colors.blue[700] : Colors.grey,
          ),
          const SizedBox(width: 10),
          Text(bg.name, style: const TextStyle(fontSize: 13)),
        ]),
      ),
    );
  }

  // ── Fixed marker tile (aid station / iGate) ────────────────────────────────

  Widget _fixedTile(FixedMarker m) {
    final isSelected = m.name == widget.selectedId;
    return InkWell(
      onTap: () {
        Navigator.pop(context);
        widget.onFixedTap?.call(m);
      },
      child: Container(
        color: isSelected ? Colors.blue.withOpacity(0.08) : null,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
        child: Row(children: [
          Container(
            width: 10,
            height: 10,
            margin: const EdgeInsets.only(right: 10),
            decoration: const BoxDecoration(
              color: Color(0xFF111111),
              shape: BoxShape.circle,
            ),
          ),
          Expanded(child: Text(m.name, style: const TextStyle(fontSize: 13))),
          if (m.callsign.isNotEmpty)
            Text(m.callsign, style: const TextStyle(fontSize: 11, color: Colors.grey)),
        ]),
      ),
    );
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  Widget _empty(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
        child: Text(text, style: const TextStyle(color: Colors.grey, fontSize: 13)),
      );

  void _showAboutDialog() {
    final cfg = widget.config;
    final osmUrl = Uri.parse('https://www.openstreetmap.org/copyright');

    showDialog<void>(
      context: context,
      builder: (ctx) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        clipBehavior: Clip.hardEdge,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Header
            Container(
              color: const Color(0xFF2c3e50),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(
                children: [
                  const Expanded(
                    child: Text('About',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                  GestureDetector(
                    onTap: () => Navigator.pop(ctx),
                    child: const Icon(Icons.close, color: Colors.white70, size: 20),
                  ),
                ],
              ),
            ),
            // Body
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _aboutRow('Organization', 'Marin Amateur Radio Society'),
                  _aboutRow('Application', 'APRS Tracker Map · v1.13'),
                  if (cfg.event.isNotEmpty) _aboutRow('Event', cfg.event),
                  if (widget.sharingCallsign != null && widget.sharingCallsign!.isNotEmpty)
                    _aboutRow('My Callsign', widget.sharingCallsign!),
                  _aboutRowWidget('Map Data', GestureDetector(
                    onTap: () => launchUrl(osmUrl, mode: LaunchMode.externalApplication),
                    child: const Text(
                      '© OpenStreetMap contributors',
                      style: TextStyle(fontSize: 13, color: Colors.blue),
                    ),
                  )),
                  _aboutRow('Copyright', '© 2026 Doug Kaye (K6DRK). All Rights Reserved.'),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _aboutRow(String label, String value) => _aboutRowWidget(
        label,
        Text(value, style: const TextStyle(fontSize: 13, color: Color(0xFF222222))),
      );

  Widget _aboutRowWidget(String label, Widget valueWidget) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label.toUpperCase(),
                style: const TextStyle(fontSize: 10, letterSpacing: 0.6, color: Color(0xFF999999))),
            const SizedBox(height: 2),
            valueWidget,
          ],
        ),
      );

  Widget _footerBtn(String label, IconData icon, VoidCallback onTap, {Color? color}) {
    return OutlinedButton.icon(
      onPressed: onTap,
      icon: Icon(icon, size: 14, color: color),
      label: Text(label, style: TextStyle(fontSize: 12, color: color)),
      style: OutlinedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        minimumSize: Size.zero,
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      ),
    );
  }
}
