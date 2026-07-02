import 'dart:io';
import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import 'help_screen.dart';
import 'map_config.dart';
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
  final Set<String> blinkingIds;
  final bool blinkOn;
  final void Function(TrackerData)? onTrackerTap;
  final void Function(TrackerData)? onTrackerLongPress;
  final void Function(FixedMarker)? onFixedTap;
  final void Function(FixedMarker)? onFixedLongPress;
  final void Function(BackgroundLayer)? onBackgroundChange;
  final void Function(String section, bool visible)? onSectionVisibility;
  final void Function(String courseFile, bool visible)? onCourseVisibility;
  final Future<void> Function()? onReload;
  final Future<void> Function()? onShareToggle;
  final VoidCallback? onResetMap;
  final Future<void> Function()? onSaveMap;
  final Future<void> Function()? onRefreshTiles;
  final String? sharingCallsign;
  final String? sharingName;
  final int sharingActivityMode;
  final VoidCallback? onSendMessage;
  final Future<void> Function(int mode)? onActivityModeChange;
  final Future<void> Function(int mode)? onStartSharingWithMode;

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
    this.blinkingIds = const {},
    this.blinkOn = true,
    this.onTrackerTap,
    this.onTrackerLongPress,
    this.onFixedTap,
    this.onFixedLongPress,
    this.onBackgroundChange,
    this.onSectionVisibility,
    this.onCourseVisibility,
    this.onReload,
    this.onShareToggle,
    this.onResetMap,
    this.onSaveMap,
    this.onRefreshTiles,
    this.sharingCallsign,
    this.sharingName,
    this.sharingActivityMode = -1,
    this.onSendMessage,
    this.onActivityModeChange,
    this.onStartSharingWithMode,
  });

  @override
  State<MenuDrawer> createState() => _MenuDrawerState();
}

class _MenuDrawerState extends State<MenuDrawer> {
  final _expanded = <String>{
    'trackers',
    'courses',
  };

  String _appVersion = '';

  @override
  void initState() {
    super.initState();
    PackageInfo.fromPlatform().then((info) {
      if (mounted) setState(() => _appVersion = '${info.version}+${info.buildNumber}');
    });
  }

  void _toggleSection(String key) =>
      setState(() => _expanded.contains(key) ? _expanded.remove(key) : _expanded.add(key));

  void _openSharingModal(BuildContext drawerCtx) {
    const modes = [(0, 'Walk/Run'), (1, 'Cycle'), (2, 'Drive'), (3, 'Stationary')];
    showDialog<void>(
      context: drawerCtx,
      builder: (dlgCtx) {
        void closeAll() {
          Navigator.pop(dlgCtx);
          Navigator.pop(drawerCtx);
        }
        return AlertDialog(
          title: const Text('Activity Mode'),
          contentPadding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
          content: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in modes)
                ChoiceChip(
                  label: Text(entry.$2,
                      style: TextStyle(
                        color: widget.sharingActivityMode == entry.$1
                            ? Colors.white
                            : Colors.black54,
                        fontWeight: widget.sharingActivityMode == entry.$1
                            ? FontWeight.w600
                            : FontWeight.normal,
                      )),
                  selected: widget.sharingActivityMode == entry.$1,
                  selectedColor: Colors.blueGrey.shade700,
                  backgroundColor: Colors.grey.shade200,
                  showCheckmark: false,
                  onSelected: (_) {
                    if (widget.isSharing) {
                      widget.onActivityModeChange?.call(entry.$1);
                    } else {
                      widget.onStartSharingWithMode?.call(entry.$1);
                    }
                    closeAll();
                  },
                ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () {
                if (widget.isSharing) widget.onShareToggle?.call();
                closeAll();
              },
              style: widget.isSharing
                  ? TextButton.styleFrom(foregroundColor: Colors.red[700])
                  : null,
              child: Text(widget.isSharing ? 'Stop Sharing' : 'Cancel'),
            ),
          ],
        );
      },
    );
  }

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
                          : ([...widget.trackers]..sort((a, b) => _naturalCompare(a.id, b.id)))
                              .map(_trackerTile).toList(),
                    ),

                  if (widget.config.courses.isNotEmpty)
                    _section(
                      key: 'courses',
                      title: 'Courses',
                      hasVisToggle: true,
                      children: widget.config.courses.map(_courseTile).toList(),
                    ),

                  if (widget.config.aidStations.isNotEmpty)
                    _section(
                      key: 'aidstations',
                      title: 'Aid/Rest Stops',
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
                      widget.isSharing ? 'Sharing' : 'Share Location',
                      Icons.share_location,
                      widget.isSharing
                          ? () => _openSharingModal(context)
                          : () { Navigator.pop(context); widget.onStartSharingWithMode?.call(0); },
                      color: widget.isSharing ? Colors.green[700] : null,
                    ),
                  if (widget.onSendMessage != null)
                    _footerBtn('Message', Icons.chat_bubble_outline, () {
                      Navigator.pop(context);
                      widget.onSendMessage?.call();
                    }),
                  _footerBtn('Save Map', Icons.push_pin, () async {
                    Navigator.pop(context);
                    await widget.onSaveMap?.call();
                  }),
                  _footerBtn('Reload Tiles', Icons.download_for_offline, () async {
                    Navigator.pop(context);
                    await widget.onRefreshTiles?.call();
                  }),
                  _footerBtn('Help', Icons.help_outline, () {
                    showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      useSafeArea: true,
                      shape: const RoundedRectangleBorder(
                        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
                      ),
                      builder: (ctx) => SingleChildScrollView(
                        padding: EdgeInsets.fromLTRB(20, 20, 20, 24 + MediaQuery.of(ctx).padding.bottom),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Row(children: [
                              const Text('Help', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                              const Spacer(),
                              IconButton(
                                icon: const Icon(Icons.close, size: 20),
                                onPressed: () => Navigator.pop(ctx),
                                padding: EdgeInsets.zero,
                                visualDensity: VisualDensity.compact,
                              ),
                            ]),
                            const SizedBox(height: 12),
                            _aboutRow('Organization', 'Marin Amateur Radio Society'),
                            _aboutRow('Application', 'APRS Tracker Map${_appVersion.isEmpty ? '' : ' · v$_appVersion'}'),
                            if (widget.config.event.isNotEmpty)
                              _aboutRow('Event', widget.config.event),
                            if (widget.sharingCallsign != null && widget.sharingCallsign!.isNotEmpty)
                              _aboutRow('My Callsign', widget.sharingName != null && widget.sharingName!.isNotEmpty
                                  ? '${widget.sharingName} (${widget.sharingCallsign})'
                                  : widget.sharingCallsign!),
                            if (widget.isSharing && widget.sharingActivityMode >= 0)
                              _aboutRow('Activity', const ['Walk/Run', 'Cycle', 'Drive', 'Stationary'][widget.sharingActivityMode]),
                            _aboutRowWidget('Map Data', GestureDetector(
                              onTap: () => launchUrl(
                                Uri.parse('https://www.openstreetmap.org/copyright'),
                                mode: LaunchMode.externalApplication,
                              ),
                              child: const Text('© OpenStreetMap contributors',
                                  style: TextStyle(fontSize: 13, color: Colors.blue)),
                            )),
                            _aboutRow('Copyright', '© 2026 Doug Kaye (K6DRK). All Rights Reserved.'),
                            const SizedBox(height: 16),
                            SizedBox(
                              width: double.infinity,
                              child: OutlinedButton(
                                onPressed: () => Navigator.push(ctx, MaterialPageRoute(
                                  builder: (_) => HelpScreen(isOnline: widget.isOnline),
                                )),
                                style: OutlinedButton.styleFrom(
                                  padding: const EdgeInsets.symmetric(vertical: 10),
                                  textStyle: const TextStyle(fontSize: 13),
                                ),
                                child: const Text('Quick Start'),
                              ),
                            ),
                            const SizedBox(height: 6),
                            SizedBox(
                              width: double.infinity,
                              child: OutlinedButton(
                                onPressed: () => launchUrl(
                                  Uri.parse('${MapConfig.serverBaseUrl}/userguide.html'),
                                  mode: LaunchMode.externalApplication,
                                ),
                                style: OutlinedButton.styleFrom(
                                  padding: const EdgeInsets.symmetric(vertical: 10),
                                  textStyle: const TextStyle(fontSize: 13),
                                ),
                                child: const Text('User Guide'),
                              ),
                            ),
                            const SizedBox(height: 6),
                            SizedBox(
                              width: double.infinity,
                              child: OutlinedButton(
                                onPressed: () => launchUrl(
                                  Uri.parse('${MapConfig.serverBaseUrl}/tickets/'),
                                  mode: LaunchMode.externalApplication,
                                ),
                                style: OutlinedButton.styleFrom(
                                  padding: const EdgeInsets.symmetric(vertical: 10),
                                  textStyle: const TextStyle(fontSize: 13),
                                ),
                                child: const Text('Submit a Bug or Suggestion'),
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  }),
                  if (Platform.isIOS || Platform.isAndroid)
                    _footerBtn('Exit', Icons.exit_to_app, () => exit(0)),
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
    final baseColor = _trackerColor(t.color);
    final isSelected = t.id == widget.selectedId;
    final isBlinking = widget.blinkingIds.contains(t.id);
    final opacity = (isBlinking && !widget.blinkOn) ? 0.15 : 1.0;
    final color = baseColor.withOpacity(opacity);
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
          Opacity(
            opacity: opacity,
            child: Padding(
              padding: const EdgeInsets.only(right: 8),
              child: t.mobile && t.hamCallsign != null
                ? CustomPaint(
                    size: const Size(10, 10),
                    painter: _TrackerTriangle(fill: baseColor, border: Colors.white),
                  )
                : Container(
                    width: 10,
                    height: 10,
                    decoration: BoxDecoration(
                      color: baseColor,
                      shape: t.mobile ? BoxShape.rectangle : BoxShape.circle,
                      borderRadius: t.mobile ? BorderRadius.circular(2) : null,
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                  ),
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
            final label = !t.hasPosition ? '—' : (t.lastUpdate > 0 && age > 300) ? 'stale' : t.time;
            final modeIcon = switch (t.sharingMode) {
              'drive' || 'drive_cycle' => Icons.directions_car_outlined,
              'cycle'                  => Icons.directions_bike,
              'walk_run'               => Icons.directions_run,
              'stationary'             => Icons.location_on,
              _                        => t.mobile ? null : Icons.rss_feed,
            };
            return Row(mainAxisSize: MainAxisSize.min, children: [
              if (modeIcon != null) ...[
                Icon(modeIcon, size: 11, color: color),
                const SizedBox(width: 3),
              ],
              Text(label, style: TextStyle(fontSize: 11, color: color)),
            ]);
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
      onLongPress: () {
        Navigator.pop(context);
        widget.onFixedLongPress?.call(m);
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

  int _naturalCompare(String a, String b) {
    final re = RegExp(r'(\d+)|(\D+)');
    final pa = re.allMatches(a).toList();
    final pb = re.allMatches(b).toList();
    for (var i = 0; i < pa.length && i < pb.length; i++) {
      final sa = pa[i].group(0)!;
      final sb = pb[i].group(0)!;
      final na = int.tryParse(sa);
      final nb = int.tryParse(sb);
      final c = (na != null && nb != null) ? na.compareTo(nb) : sa.compareTo(sb);
      if (c != 0) return c;
    }
    return pa.length.compareTo(pb.length);
  }

  Widget _empty(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
        child: Text(text, style: const TextStyle(color: Colors.grey, fontSize: 13)),
      );

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

class _TrackerTriangle extends CustomPainter {
  final Color fill;
  final Color border;
  const _TrackerTriangle({required this.fill, required this.border});

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(size.width / 2, 0)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = fill);
    canvas.drawPath(path, Paint()..color = border..style = PaintingStyle.stroke..strokeWidth = 1.5..strokeJoin = StrokeJoin.round);
  }

  @override
  bool shouldRepaint(_TrackerTriangle old) => old.fill != fill || old.border != border;
}
