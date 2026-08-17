/// Everything the phone says or plays, in one queue.
///
/// There were two before, and they did not know about each other: the text-to-speech
/// engine had a Future chain, radio clips had a list and a player, and both would start
/// whenever their own item was ready. On a net with speech and radio both on, a
/// synthesised voice and a real one came out of the speaker simultaneously and neither
/// could be understood. One queue, one thing audible at a time.
///
/// Three rules, and they are the whole design:
///
///   1. **Nothing interrupts.** A new transmission waits for the current one to finish.
///      Chopping a clip mid-word to start another is worse than a few seconds' wait,
///      and rule 2 bounds how far behind that can put you.
///
///   2. **Five minutes, by when it was SAID.** Not when it was queued — that is the
///      distinction that matters after an outage, where everything is queued at the
///      same instant and a queue-time rule would consider none of it stale and read out
///      an hour of backlog. Message time is also the only rule that can be explained in
///      one sentence: you will not hear anything more than five minutes old.
///
///   3. **It can always be stopped.** Automatic rules are judgement, and judgement is
///      sometimes wrong — so there is a button, and it empties everything.
///
/// Silent text is deliberately NOT bounded by any of this. A message nobody has to
/// listen to costs nothing to deliver, so every one still arrives and lands in the
/// thread; only what is AUDIBLE is rationed.
import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:just_audio/just_audio.dart';

import 'speaker.dart';

/// How old a thing may be, by the time it was said on the air or sent, before playing
/// it does more harm than good. One value for speech and for radio: two thresholds were
/// impossible to explain and disagreed in exactly the case that mattered.
const kAudioMaxAge = Duration(minutes: 5);

/// Characters of speech per second, for estimating how long the queue will take.
/// Measured against the engine at its configured rate; near enough for a countdown that
/// exists to tell somebody whether to wait or press stop.
const _kCharsPerSecond = 14.0;

enum _Kind { speech, clip }

class _Item {
  final _Kind kind;
  final int ts; // unix seconds — when it was SAID, not when it was queued
  final String senderLabel;
  final String text; // speech only
  final String url; // clip only
  final double seconds; // clip only, from audio_secs
  const _Item.speech(this.ts, this.senderLabel, this.text)
      : kind = _Kind.speech, url = '', seconds = 0;
  const _Item.clip(this.ts, this.url, this.seconds)
      : kind = _Kind.clip, senderLabel = '', text = '';

  /// Best estimate of how long this will occupy the speaker.
  double get estSeconds =>
      kind == _Kind.clip ? seconds : (senderLabel.length + text.length) / _kCharsPerSecond + 0.5;
}

class AudioQueue {
  AudioQueue._();
  static final AudioQueue instance = AudioQueue._();

  final _player = AudioPlayer();
  final List<_Item> _q = [];
  _Item? _current;
  bool _draining = false;

  /// What the Stop control shows: how many are waiting and roughly how long they will
  /// take. Both, because neither alone answers "should I wait or stop it" — three long
  /// overs and thirty short ones are the same count and very different waits.
  final pending = ValueNotifier<({int count, int seconds})>((count: 0, seconds: 0));

  void _publish() {
    final items = [if (_current != null) _current!, ..._q];
    final secs = items.fold<double>(0, (a, b) => a + b.estSeconds);
    pending.value = (count: items.length, seconds: secs.round());
  }

  bool get isBusy => _current != null || _q.isNotEmpty;

  void addSpeech({required int ts, required String senderLabel, required String text}) {
    if (text.trim().isEmpty && senderLabel.trim().isEmpty) return;
    _q.add(_Item.speech(ts, senderLabel, text));
    _publish();
    unawaited(_drain());
  }

  void addClip({required int ts, required String url, double seconds = 0}) {
    if (_q.any((i) => i.url == url) || _current?.url == url) return;
    _q.add(_Item.clip(ts, url, seconds));
    _publish();
    unawaited(_drain());
  }

  /// Empty everything and silence what is playing. The button.
  Future<void> cancelAll() async {
    _q.clear();
    _current = null;
    _publish();
    await Speaker.instance.stop();
    try {
      await _player.stop();
    } catch (_) {}
  }

  bool _stale(_Item i) =>
      DateTime.now().millisecondsSinceEpoch ~/ 1000 - i.ts > kAudioMaxAge.inSeconds;

  Future<void> _drain() async {
    if (_draining) return;
    _draining = true;
    try {
      while (_q.isNotEmpty) {
        final item = _q.removeAt(0);
        // Checked here rather than on the way in: something can sit in the queue behind
        // a long over and go stale while it waits, and playing it then is the same
        // mistake as playing it after an outage.
        if (_stale(item)) continue;
        _current = item;
        _publish();
        try {
          if (item.kind == _Kind.speech) {
            await Speaker.instance.speakNow(
                senderLabel: item.senderLabel, text: item.text);
          } else {
            await _player.setUrl(item.url);
            await _player.play();
          }
        } catch (_) {
          // A clip that will not fetch or decode, or an audio session the OS refused.
          // Skipped in silence — the radio is not worth an error dialog, and the next
          // over is already on its way.
        }
        _current = null;
        _publish();
      }
    } finally {
      _draining = false;
      _publish();
    }
  }
}
