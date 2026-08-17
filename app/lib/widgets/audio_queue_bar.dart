/// The Stop control for anything the phone is saying or playing.
///
/// Visible only while there is something to stop, so it costs no screen space the rest
/// of the time. It exists because the automatic rules — five minutes, oldest first, no
/// interrupting — are judgement, and judgement is sometimes wrong: an operator who has
/// just walked back to the radio does not want to sit through what they missed, however
/// recent it technically is.
///
/// It shows a count AND a duration, because neither answers the question on its own.
/// "Six waiting" could be twenty seconds or four minutes, and the decision to wait or
/// stop turns entirely on which.
import 'package:flutter/material.dart';

import '../audio_queue.dart';

class AudioQueueBar extends StatelessWidget {
  const AudioQueueBar({super.key});

  static String _duration(int secs) {
    if (secs < 60) return '${secs}s';
    final m = secs ~/ 60, s = secs % 60;
    return s == 0 ? '${m}m' : '${m}m ${s}s';
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<({int count, int seconds})>(
      valueListenable: AudioQueue.instance.pending,
      builder: (context, v, _) {
        if (v.count == 0) return const SizedBox.shrink();
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Material(
              color: const Color(0xFF1A5276),
              borderRadius: BorderRadius.circular(24),
              elevation: 4,
              child: InkWell(
                borderRadius: BorderRadius.circular(24),
                onTap: () => AudioQueue.instance.cancelAll(),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.stop_circle_outlined, color: Colors.white, size: 20),
                    const SizedBox(width: 8),
                    Text(
                      // "Playing" rather than a count of 1, because one item with
                      // nothing behind it is not a queue and does not need counting.
                      v.count == 1
                          ? 'Playing · ${_duration(v.seconds)}'
                          : '${v.count} to play · ${_duration(v.seconds)}',
                      style: const TextStyle(
                          color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(width: 10),
                    const Text('STOP',
                        style: TextStyle(
                            color: Colors.white70,
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            letterSpacing: 0.5)),
                  ]),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
