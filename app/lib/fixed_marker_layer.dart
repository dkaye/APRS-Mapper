/// Flutter Map layer that renders fixed-position map markers for aid stations
/// and iGates, with optional APRS callsign labels.
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
  final void Function(FixedMarker)? onTap;
  final void Function(FixedMarker)? onLongPress;

  const FixedMarkerLayer({
    super.key,
    required this.markers,
    this.isIgate = false,
    this.selectedId,
    this.blinkingIds = const {},
    this.blinkOn = true,
    this.onTap,
    this.onLongPress,
  });

  @override
  Widget build(BuildContext context) {
    return MarkerLayer(
      markers: markers.map((m) {
        final selected = m.name == selectedId;
        final blinking = blinkingIds.contains(m.name);
        final opacity = blinking ? (blinkOn ? 1.0 : 0.15) : 1.0;

        final dot = Container(
          width: 12,
          height: 12,
          decoration: BoxDecoration(
            color: const Color(0xFF111111),
            shape: BoxShape.circle,
            border: Border.all(color: const Color(0xFF555555), width: 1),
          ),
        );

        final label = m.callsign.isNotEmpty ? '${m.name} (${m.callsign})' : m.name;

        final child = selected
            ? Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  dot,
                  const SizedBox(height: 2),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xEBFFFFFF),
                      borderRadius: BorderRadius.circular(3),
                    ),
                    child: Text(
                      label,
                      style: const TextStyle(
                          fontSize: 10, fontWeight: FontWeight.w600, color: Color(0xFF111111)),
                      softWrap: false,
                    ),
                  ),
                ],
              )
            : dot;

        return Marker(
          point: LatLng(m.lat, m.lon),
          width: selected ? 240 : 44,
          height: selected ? 42 : 44,
          alignment: selected ? Alignment.bottomCenter : Alignment.center,
          child: Opacity(
            opacity: opacity,
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => onTap?.call(m),
              onLongPress: () => onLongPress?.call(m),
              child: Center(child: child),
            ),
          ),
        );
      }).toList(),
    );
  }
}
