import 'dart:ui' as ui;
import 'package:flutter/material.dart';

// Filled arrowhead matching the web SVG polygon "10,2 18,18 10,12 2,18"
// in a 20×20 canvas. Caller wraps in Transform.rotate for direction.
class ArrowPainter extends CustomPainter {
  final Color color;
  const ArrowPainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color.withValues(alpha: 0.85)
      ..style = PaintingStyle.fill;
    final path = ui.Path()
      ..moveTo(size.width * 0.50, size.height * 0.10) // tip
      ..lineTo(size.width * 0.90, size.height * 0.90) // bottom-right
      ..lineTo(size.width * 0.50, size.height * 0.60) // inner notch
      ..lineTo(size.width * 0.10, size.height * 0.90) // bottom-left
      ..close();
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(ArrowPainter old) => old.color != color;
}
