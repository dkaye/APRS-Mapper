import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'tracker_data.dart';

class TrackerLayer extends StatelessWidget {
  final List<TrackerData> trackers;
  final String? selectedId;
  final Set<String> blinkingIds;
  final bool blinkOn;

  const TrackerLayer({
    super.key,
    required this.trackers,
    this.selectedId,
    this.blinkingIds = const {},
    this.blinkOn = true,
  });

  @override
  Widget build(BuildContext context) {
    return MarkerLayer(
      markers: trackers.where((t) => t.hasPosition).map((t) {
        final color = _markerColor(t.color);
        final selected = t.id == selectedId;
        final blinking = blinkingIds.contains(t.id);
        final opacity = blinking ? (blinkOn ? 1.0 : 0.15) : 1.0;

        Widget dot = _TrackerMarker(color: color, mobile: t.mobile);

        Widget content = selected
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  dot,
                  const SizedBox(height: 2),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.92),
                      borderRadius: BorderRadius.circular(3),
                    ),
                    child: Text(
                      t.name.isNotEmpty ? t.name : t.id,
                      style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: color),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              )
            : dot;

        return Marker(
          point: t.latLng,
          width: selected ? 80 : 20,
          height: selected ? 36 : 20,
          alignment: selected ? Alignment.bottomCenter : Alignment.center,
          child: Opacity(
            opacity: opacity,
            child: GestureDetector(
              onTap: () => _showDetail(context, t),
              child: content,
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
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      if (t.name.isNotEmpty)
                        Text(t.id, style: const TextStyle(color: Colors.grey)),
                    ],
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              _row(Icons.access_time, t.time.isNotEmpty ? '${t.time} ago' : 'Unknown'),
              if (t.hasPosition)
                _row(Icons.location_on, '${t.lat!.toStringAsFixed(5)}, ${t.lon!.toStringAsFixed(5)}')
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
      width: 18,
      height: 18,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 3, offset: Offset(0, 1))],
      ),
    );
  }
}
