import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'tracker_data.dart';

class TrackerLayer extends StatelessWidget {
  final List<TrackerData> trackers;

  const TrackerLayer({super.key, required this.trackers});

  @override
  Widget build(BuildContext context) {
    return MarkerLayer(
      markers: trackers.map((t) => Marker(
        point: t.latLng,
        width: 44,
        height: 44,
        child: GestureDetector(
          onTap: () => _showDetail(context, t),
          child: _TrackerMarker(color: _markerColor(t.color), mobile: t.mobile),
        ),
      )).toList(),
    );
  }

  Color _markerColor(String color) {
    switch (color) {
      case 'green':
        return const Color(0xFF43A047);
      case 'blue':
        return const Color(0xFF1E88E5);
      default:
        return const Color(0xFFE53935);
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
                      Text(t.name.isNotEmpty ? t.name : t.callsign,
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      if (t.name.isNotEmpty)
                        Text(t.callsign, style: const TextStyle(color: Colors.grey)),
                    ],
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              _row(Icons.access_time, t.time.isNotEmpty ? '${t.time} ago' : 'Unknown'),
              _row(Icons.location_on, '${t.lat.toStringAsFixed(5)}, ${t.lon.toStringAsFixed(5)}'),
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
    return Center(
      child: Container(
        width: 18,
        height: 18,
        decoration: BoxDecoration(
          color: color,
          shape: mobile ? BoxShape.rectangle : BoxShape.circle,
          borderRadius: mobile ? BorderRadius.circular(3) : null,
          border: Border.all(color: Colors.white, width: 2),
          boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 3, offset: Offset(0, 1))],
        ),
      ),
    );
  }
}
