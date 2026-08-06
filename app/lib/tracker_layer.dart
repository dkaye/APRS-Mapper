/// Native Flutter Map overlay that renders tracker markers (squares for mobile,
/// circles for radio) and breadcrumb trails with directional arrows.
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'tracker_data.dart';

// Marker geometry constants — dot must be centered on the lat/lng point.
// With Alignment.center, the marker center = lat/lng pixel.
// Leading SizedBox offsets the dot so its center lands at the marker center.
const _markerW  = 200.0;
const _markerH  = 22.0;
const _dotW     = 18.0;
const _dotH     = 18.0;
const _labelGap = 4.0;
const _leftPad  = (_markerW - _dotW) / 2; // dot center x = _markerW/2 ✓

class TrackerLayer extends StatelessWidget {
  final List<TrackerData> trackers;
  // A tracker whose full "ID Name" label is forced (overriding the ID/Name
  // eyeballs) because it was just tapped in the sidebar; transient.
  final String? fullLabelId;
  final Set<String> blinkingIds;
  final bool blinkOn;
  final bool showIds;
  final bool showNames;
  final void Function(TrackerData)? onLongPress;

  const TrackerLayer({
    super.key,
    required this.trackers,
    this.fullLabelId,
    this.blinkingIds = const {},
    this.blinkOn = true,
    this.showIds = true,
    this.showNames = true,
    this.onLongPress,
  });

  @override
  Widget build(BuildContext context) {
    return MarkerLayer(
      markers: trackers.where((t) => t.hasPosition).map((t) {
        final color = _markerColor(t.color);
        final fullLabel = t.id == fullLabelId;
        final blinking = blinkingIds.contains(t.id);
        final opacity = blinking ? (blinkOn ? 1.0 : 0.15) : 1.0;

        final dot = _TrackerMarker(color: color, mobile: t.mobile);
        // A just-tapped tracker briefly shows "id name" regardless of the eyes; it
        // reverts on the next action. Otherwise show whatever the sidebar ID / Name
        // eyes enable (both on by default).
        final restingParts = <String>[
          if (showIds) t.id,
          if (showNames && t.name.isNotEmpty) t.name,
        ];
        final labelText = fullLabel
            ? (t.name.isNotEmpty ? '${t.id} ${t.name}' : t.id)
            : restingParts.join(' ');

        final labelWidget = fullLabel
            ? Text(
                labelText,
                style: const TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                  color: Colors.black,
                ),
                overflow: TextOverflow.fade,
                softWrap: false,
              )
            : Text(
                labelText,
                style: const TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF111111),
                  shadows: [Shadow(color: Colors.white, blurRadius: 4)],
                ),
                overflow: TextOverflow.fade,
                softWrap: false,
              );

        return Marker(
          point: t.latLng,
          width: _markerW,
          height: _markerH,
          alignment: Alignment.center, // marker center = lat/lng
          child: Opacity(
            opacity: opacity,
            child: GestureDetector(
              onTap: () => _showDetail(context, t),
              onLongPress: () => onLongPress?.call(t),
              child: Row(
                children: [
                  const SizedBox(width: _leftPad), // shifts dot to center
                  dot,
                  const SizedBox(width: _labelGap),
                  Expanded(child: labelWidget),
                ],
              ),
            ),
          ),
        );
      }).toList(),
    );
  }

  Color _markerColor(String color) {
    switch (color) {
      case 'green': return const Color(0xFF43A047);
      case 'blue':  return const Color(0xFF1E88E5);
      default:      return const Color(0xFFE53935);
    }
  }

  void _showDetail(BuildContext context, TrackerData t) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                _TrackerMarker(color: _markerColor(t.color), mobile: t.mobile),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(t.name.isNotEmpty ? t.name : t.id,
                          style: const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.bold)),
                      if (t.name.isNotEmpty)
                        Text(t.id,
                            style: const TextStyle(color: Colors.grey)),
                    ],
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              _row(Icons.access_time,
                  t.time.isNotEmpty ? '${t.time} ago' : 'Unknown'),
              if (t.hasPosition)
                _row(Icons.location_on,
                    '${t.lat!.toStringAsFixed(5)}, ${t.lon!.toStringAsFixed(5)}')
              else
                _row(Icons.location_off, 'No position yet'),
              if (t.mobile) _row(Icons.smartphone, 'Mobile tracker'),
            ],
          ),
        ),
      ),
    );
  }

  Widget _row(IconData icon, String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Icon(icon, size: 18, color: Colors.grey[600]),
          const SizedBox(width: 8),
          Text(text, style: const TextStyle(fontSize: 15)),
        ]),
      );
}

class _TrackerMarker extends StatelessWidget {
  final Color color;
  final bool mobile;

  const _TrackerMarker({required this.color, required this.mobile});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: _dotW,
      height: _dotH,
      decoration: BoxDecoration(
        color: color,
        shape: mobile ? BoxShape.rectangle : BoxShape.circle,
        borderRadius: mobile ? BorderRadius.circular(3) : null,
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: const [
          BoxShadow(color: Colors.black26, blurRadius: 3, offset: Offset(0, 1))
        ],
      ),
    );
  }
}
