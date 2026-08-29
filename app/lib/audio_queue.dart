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
import 'package:audio_session/audio_session.dart';
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

/// How long a clip may take to arrive before the attempt is abandoned. Generous,
/// because this covers a cold cellular connection fetching a file that is only a few
/// tens of kB — the wait is the network finding its feet, not the size of the download.
const _kFetchBudget = Duration(seconds: 30);

/// Added to a clip's own length to get the longest it may hold the speaker. Covers
/// buffering part way through and a duration that is slightly short, and no more: past
/// this, something has gone wrong and the queue has to have its turn back.
const _kPlaySlack = Duration(seconds: 10);

enum _Kind { speech, clip }

class _Item {
  final _Kind kind;
  final int ts; // unix seconds — when it was SAID, not when it was queued
  final String senderLabel;
  final String text; // speech only
  final String url; // clip only
  final double seconds; // clip only, from audio_secs

  /// Which message this is. For a clip it drives the bubble's Play/Stop control; for
  /// speech it is what stops the same message being read aloud twice — see `_spoken`.
  /// Zero means "not a message", and nothing is deduped or attributed to it.
  final int msgId;

  /// Sound the alert tone immediately before this, through this queue.
  ///
  /// The tone used to be the iOS notification's own sound, with speech delayed 900 ms
  /// to let it finish. That is a race against a sound the app does not schedule and
  /// cannot observe, and it lost: the tone arrived after the speech had already
  /// started. Owning both ends of it makes the order a fact rather than a bet, and it
  /// also stops the tone landing in the middle of a radio clip — which the queue
  /// exists to prevent and the notification sound was quietly exempt from.
  ///
  /// Not final: a message can be offered to the queue twice, once by the monitor feed
  /// (no tone — none of that traffic is addressed here) and once by the path that knows
  /// it was addressed to this operator (tone). Whichever arrives second is dropped as a
  /// duplicate, so the surviving item has to be able to take on the tone the dropped one
  /// was carrying, or an alert would be lost to a race between two polls.
  bool chime;

  /// Whether the user asked for this specific item by name.
  ///
  /// The five-minute rule exists to stop a backlog playing itself. Tapping Play on a
  /// message from an hour ago is not a backlog, it is an instruction, and refusing it
  /// silently would look like a broken button.
  final bool force;

  /// Run only if this was actually spoken — not if it was dropped as stale, and not if
  /// the audio session refused it.
  ///
  /// Not final, for the same reason as `chime`: a dropped duplicate hands its callback
  /// to the survivor rather than losing it. Only one caller sets it — marking a message
  /// read once it has been heard — and that is exactly the callback that must not go
  /// missing because a second feed got there first.
  void Function()? onSpoken;

  /// Who this was addressed to, when the listener is not that person. See
  /// Speaker.speakNow — null for broadcasts and for anything addressed here.
  final String? toLabel;

  _Item.speech(this.ts, this.senderLabel, this.text,
      {this.msgId = 0, this.chime = false, this.onSpoken, this.toLabel})
      : kind = _Kind.speech, url = '', seconds = 0, force = false;
  _Item.clip(this.ts, this.url, this.seconds,
      {this.msgId = 0, this.force = false})
      : kind = _Kind.clip, senderLabel = '', text = '', chime = false, onSpoken = null,
        toLabel = null;

  /// Best estimate of how long this will occupy the speaker.
  double get estSeconds =>
      (chime ? 1.0 : 0.0) +
      (kind == _Kind.clip
          ? seconds
          : (senderLabel.length + (toLabel?.length ?? 0) + text.length) / _kCharsPerSecond + 0.5);
}

class AudioQueue {
  AudioQueue._();
  static final AudioQueue instance = AudioQueue._();

  final _player = AudioPlayer();

  /// Configure the shared iOS/Android audio session once, before anything plays.
  ///
  /// Without this, recorded clips were silently unplayable on iPhone: tapping Play
  /// showed "Playing" for a fraction of a second and produced nothing. Safari played
  /// the same URL perfectly, which is what proved the file, the network and the format
  /// were all fine and the fault was here.
  ///
  /// The cause is two engines and one AVAudioSession. `flutter_tts` sets its own
  /// category in Speaker; `just_audio` set none at all and inherited whatever it found,
  /// so activating it while the speech engine held the session threw — and the queue's
  /// catch, which exists so a bad clip cannot stall the queue, swallowed it. The same
  /// contention the header of speaker.dart warns about for two TTS instances, one layer
  /// down and between two different packages.
  ///
  /// `playback` rather than the default, and deliberately: it is what makes both clips
  /// and speech audible with the ringer switch on silent, which is the position a phone
  /// lives in on a net. `duckOthers` and `spokenAudio` match what Speaker asks for, so
  /// the two engines want the same session instead of fighting over it.
  static Future<void> configureSession() async {
    try {
      final session = await AudioSession.instance;
      await session.configure(const AudioSessionConfiguration(
        avAudioSessionCategory: AVAudioSessionCategory.playback,
        avAudioSessionCategoryOptions: AVAudioSessionCategoryOptions.duckOthers,
        avAudioSessionMode: AVAudioSessionMode.spokenAudio,
        androidAudioAttributes: AndroidAudioAttributes(
          contentType: AndroidAudioContentType.speech,
          usage: AndroidAudioUsage.assistanceNavigationGuidance,
        ),
        androidAudioFocusGainType: AndroidAudioFocusGainType.gainTransientMayDuck,
      ));
    } catch (_) {
      // Best effort. A session the OS will not configure is a reason to try playing
      // anyway — it may already be usable — not a reason to fail before the attempt.
    }
  }
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

  /// Message ids already read aloud, newest last.
  ///
  /// The phone has more than one way to learn about the same message and they do not
  /// know about each other. A message addressed to this operator arrives on the
  /// addressed path AND, if monitoring is on, in the monitor feed — which is the whole
  /// event's traffic and excludes nothing. Both called this, so both were spoken, a few
  /// seconds apart.
  ///
  /// It has to be remembered rather than checked against the queue, which is what
  /// `addClip` does with its url. The two polls run at different intervals, so the
  /// second copy usually turns up after the first has been spoken and drained — a
  /// queue-scoped test would miss precisely the common case.
  ///
  /// Capped and trimmed oldest-first, matching `WatchBridge._alerted`. Ids only ever
  /// increase, so the oldest is the safest to forget.
  final _spoken = <int>{};
  static const _kSpokenCap = 500;

  void addSpeech({
    required int ts,
    required String senderLabel,
    required String text,
    int msgId = 0,
    bool chime = false,
    void Function()? onSpoken,
    String? toLabel,
  }) {
    if (text.trim().isEmpty && senderLabel.trim().isEmpty) return;

    if (msgId != 0) {
      if (_spoken.contains(msgId)) {
        // Said already, by whichever feed got here first. `onSpoken` marks it read, and
        // read is exactly what it now is — so run it rather than dropping it on the
        // floor. The tone cannot be recovered, and chiming after the words have been
        // spoken would be worse than the silence.
        onSpoken?.call();
        return;
      }
      final queued = _pendingSpeech(msgId);
      if (queued != null) {
        // Not said yet. Fold this copy into the one already waiting so nothing it was
        // carrying is lost to having lost the race.
        if (chime) queued.chime = true;
        if (onSpoken != null) {
          final earlier = queued.onSpoken;
          queued.onSpoken = earlier == null
              ? onSpoken
              : () {
                  earlier();
                  onSpoken();
                };
        }
        _publish();
        return;
      }
    }

    _q.add(_Item.speech(ts, senderLabel, text,
        msgId: msgId, chime: chime, onSpoken: onSpoken, toLabel: toLabel));
    _publish();
    unawaited(_drain());
  }

  /// The speech item for this message that is waiting or being said right now, if any.
  /// Deliberately not clips: a message can have both a recording and a spoken summary,
  /// and they are different things to hear.
  _Item? _pendingSpeech(int msgId) {
    if (_current?.kind == _Kind.speech && _current?.msgId == msgId) return _current;
    for (final i in _q) {
      if (i.kind == _Kind.speech && i.msgId == msgId) return i;
    }
    return null;
  }

  void _markSpoken(int msgId) {
    if (msgId == 0) return;
    _spoken.add(msgId);
    if (_spoken.length > _kSpokenCap) _spoken.remove(_spoken.first);
  }

  void addClip({
    required int ts,
    required String url,
    double seconds = 0,
    int msgId = 0,
    bool force = false,
  }) {
    if (_q.any((i) => i.url == url) || _current?.url == url) return;
    _failedClips.remove(msgId);   // a fresh attempt deserves a clean slate
    _q.add(_Item.clip(ts, url, seconds, msgId: msgId, force: force));
    _publish();
    unawaited(_drain());
  }

  /// Clips whose playback failed, so their row can say so rather than going quiet.
  final _failedClips = <int>{};
  bool clipFailed(int msgId) => _failedClips.contains(msgId);

  /// True while this message's clip is playing or waiting — what the Play/Stop control
  /// on a bubble reflects. It was local state on the messaging screen, which is why
  /// that screen played clips through a second player the Stop bar could not see.
  ///
  /// The kind test is not decoration. Speech items carry a msgId too now, so without it
  /// a message being read aloud would light up its own Play control as though its
  /// recording were playing — and `cancelClip` below would silence the wrong thing.
  bool isQueuedClip(int msgId) =>
      msgId != 0 &&
      ((_current?.kind == _Kind.clip && _current?.msgId == msgId) ||
          _q.any((i) => i.kind == _Kind.clip && i.msgId == msgId));

  /// Drop one message's clip; the rest of the queue carries on.
  Future<void> cancelClip(int msgId) async {
    _q.removeWhere((i) => i.kind == _Kind.clip && i.msgId == msgId);
    if (_current?.kind == _Kind.clip && _current?.msgId == msgId) {
      _current = null;
      try {
        await _player.stop();
      } catch (_) {}
    }
    _publish();
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

  /// A missing or nonsensical timestamp must never mean "discard".
  ///
  /// The age rule exists to drop a backlog, and it decides that by subtracting from
  /// now — so a ts of 0 reads as 1970 and is silently, permanently too old. Any path
  /// that ever loses the field would go quiet with nothing to show for it, which is the
  /// worst way for a safety rule to fail: it looks exactly like nothing arrived.
  bool _stale(_Item i) =>
      i.ts > 0 &&
      DateTime.now().millisecondsSinceEpoch ~/ 1000 - i.ts > kAudioMaxAge.inSeconds;

  /// The alert tone, through the same player and the same queue slot as everything
  /// else. Failure is not fatal: the words matter more than the noise before them.
  Future<void> _chime() async {
    try {
      await _player.stop();
      await _player.setAsset('assets/sounds/message.wav');
      await _playToEnd(const Duration(seconds: 4));
    } catch (_) {}
  }

  /// Play what is already loaded, and return when it has actually finished.
  ///
  /// `play()` cannot be asked that question, and this is the whole reason clips
  /// misbehaved on iPhone. just_audio clears its `playing` flag on `pause()` and
  /// `stop()` and nowhere else — reaching the end of a track leaves it set — so from
  /// the first tone or clip of the session onwards it is true for the life of the
  /// process. Two things then go wrong at once on every item after the first:
  ///
  ///   * `setUrl` sees `playing` and starts the new source itself, the moment it
  ///     finishes loading, outside anybody's control;
  ///   * `play()` opens with `if (playing) return;` and hands back an already-complete
  ///     future.
  ///
  /// So the queue believed each clip was over the instant it began. That is the Stop
  /// button appearing and vanishing in the same frame, and the queue moving on to load
  /// the next item over the top of one that had only just started — whose interrupted
  /// load throws, which is where "Unavailable — tap to retry" came from. Nothing was
  /// wrong with the recordings, the URLs, or the session.
  ///
  /// The cure is both halves: `stop()` before every load, so `playing` is false and
  /// nothing self-starts, and finishing judged by the processing state rather than by
  /// a future that no longer means what it reads as.
  ///
  /// `idle` counts as finished as well as `completed`, because that is what `stop()`
  /// produces — Stop has to end this wait, not leave it sitting out the full budget.
  Future<void> _playToEnd(Duration budget) async {
    final done = Completer<void>();
    void finish([Object? error]) {
      if (done.isCompleted) return;
      if (error == null) {
        done.complete();
      } else {
        done.completeError(error);
      }
    }

    final sub = _player.processingStateStream.listen(
      (s) {
        if (s == ProcessingState.completed || s == ProcessingState.idle) finish();
      },
      onError: (Object e) => finish(e),
    );
    // play()'s completion is meaningless here, but its errors are not — a source the
    // OS will not play, or a session it will not activate, has to reach the caller so
    // the row can say so rather than sitting out the whole budget in silence.
    unawaited(_player.play().catchError((Object e) => finish(e)));
    try {
      await done.future.timeout(budget);
    } finally {
      await sub.cancel();
      // Unconditional, and it is the half of the cure that outlives this call: reaching
      // the end does not clear `playing`, so leaving without stopping would hand the
      // next item a player that starts itself. It also frees the decoder, and after a
      // timeout it is what actually silences a clip that overran.
      try {
        await _player.stop();
      } catch (_) {}
    }
  }

  Future<void> _drain() async {
    if (_draining) return;
    _draining = true;
    try {
      while (_q.isNotEmpty) {
        final item = _q.removeAt(0);
        // Checked here rather than on the way in: something can sit in the queue behind
        // a long over and go stale while it waits, and playing it then is the same
        // mistake as playing it after an outage.
        if (!item.force && _stale(item)) continue;
        _current = item;
        _publish();
        try {
          if (item.kind == _Kind.speech) {
            if (item.chime) await _chime();
            // Bounded, and that is not belt-and-braces. Speaker sets
            // awaitSpeakCompletion(true), so speakNow() resolves when the utterance
            // FINISHES — and if iOS refuses the audio session, which it can do on a
            // locked phone while a notification sound holds it, the utterance never
            // starts, never finishes, and this await never returns. The queue would then
            // be stalled for the life of the process with nothing played again and no
            // error anywhere. A timeout costs one skipped item; the alternative costs
            // every item after it.
            final budget = Duration(seconds: item.estSeconds.ceil() + 15);
            await Speaker.instance
                .speakNow(senderLabel: item.senderLabel, text: item.text,
                          toLabel: item.toLabel)
                .timeout(budget, onTimeout: () => Speaker.instance.stop());
            // Only here, on the path that actually said it out loud. Marking a message
            // read when it merely ARRIVED claimed the operator had heard something the
            // queue might still drop as stale or fail to play.
            //
            // The same reasoning governs `_spoken`, which is why it is recorded here and
            // not on the way in: a copy dropped as stale, or one the audio session
            // refused, was never heard, and a second feed offering it later deserves the
            // attempt this one did not get.
            item.onSpoken?.call();
            _markSpoken(item.msgId);
          } else {
            // Stopped before loading, and not for tidiness: see `_playToEnd`. A player
            // left flagged `playing` — which it is after anything reaches its end —
            // starts the next source by itself the moment setUrl finishes loading it.
            await _player.stop();
            // Fetching and playing get their own budgets. Sharing one meant a clip that
            // took twenty seconds to arrive over cellular had only what was left to play
            // in, and got cut off part way through the over. They are different waits and
            // neither says anything about the other.
            final dur = await _player.setUrl(item.url).timeout(_kFetchBudget);
            // The duration the file actually has, in preference to the audio_secs the
            // message claimed. The claim comes from the recorder and is what the "Play 4s"
            // label is drawn from; the file is the thing about to be played.
            final len = dur ?? Duration(seconds: item.seconds.ceil());
            await _playToEnd(len + _kPlaySlack);
          }
        } catch (e) {
          // Remember which message failed, so the bubble can say "Unavailable" instead
          // of flashing "Playing" and going quiet. There is still no dialog — the radio
          // is not worth one — but a silent failure indistinguishable from a muted phone
          // is what turned a broken audio session into an afternoon of diagnosis.
          // _publish() runs a few lines below on the way out of this item, and the
          // queue count changes when it does, so the row rebuilds and sees this.
          //
          // Except when the operator stopped it. Both cancel paths clear `_current`,
          // and stopping the player mid-fetch makes the load throw — so without this
          // test, pressing Stop reported the clip as unavailable, which is a lie about
          // a button that did exactly what it said.
          if (item.kind == _Kind.clip && item.msgId != 0 && identical(_current, item)) {
            _failedClips.add(item.msgId);
          }
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
