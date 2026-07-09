/// Small widget that displays the current Smart Track activity mode icon
/// (Walk/Run, Cycle, Drive, Stationary, or Unknown).
import 'package:flutter/material.dart';

class ModeIndicator extends StatelessWidget {
  final bool online;

  const ModeIndicator({super.key, required this.online});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: online ? Colors.green[700] : Colors.grey[700],
        borderRadius: BorderRadius.circular(12),
        boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 4)],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(
              color: online ? Colors.greenAccent : Colors.grey[300],
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 5),
          Text(
            online ? 'Online' : 'Offline',
            style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
