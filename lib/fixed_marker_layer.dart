import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'remote_config.dart';

class FixedMarkerLayer extends StatelessWidget {
  final List<FixedMarker> markers;
  final bool isIgate;
  final String? selectedId;
  final Set<String> blinkingIds;
  final bool blinkOn;

  const FixedMarkerLayer({
    super.key,
    required this.markers,
    this.isIgate = false,
    this.selectedId,
    this.blinkingIds = const {},
    this.blinkOn = true,
  });

  @override
  Widget build(BuildContext context) {
    return MarkerLayer(
      markers: markers.map((m) {
        final selected = m.name == selectedId;
        final blinking = blinkingIds.contains(m.name);
        final opacity = blinking ? (blinkOn ? 1.0 : 0.15) : 1.0;

        Widget dot = Container(
          width: 12,
          height: 12,
          decoration: BoxDecoration(
            color: const Color(0xFF111111),
            shape: BoxShape.circle,
            border: Border.all(color: const Color(0xFF555555), width: 1),
          ),
        );

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
                      m.name,
                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: Color(0xFF111111)),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              )
            : dot;

        return Marker(
          point: LatLng(m.lat, m.lon),
          width: selected ? 80 : 14,
          height: selected ? 32 : 14,
          alignment: selected ? Alignment.bottomCenter : Alignment.center,
          child: Opacity(
            opacity: opacity,
            child: GestureDetector(
              onTap: () => _showDetail(context, m),
              child: content,
            ),
          ),
        );
      }).toList(),
    );
  }

  void _showDetail(BuildContext context, FixedMarker m) {
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
              Text(m.name,
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              if (m.callsign.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(m.callsign, style: const TextStyle(color: Colors.grey)),
              ],
              const SizedBox(height: 8),
              Text(
                isIgate ? 'iGate receiver' : 'Aid station',
                style: TextStyle(color: Colors.grey[600]),
              ),
              const SizedBox(height: 4),
              Text('${m.lat.toStringAsFixed(5)}, ${m.lon.toStringAsFixed(5)}',
                  style: const TextStyle(fontSize: 13)),
            ],
          ),
        ),
      ),
    );
  }
}
