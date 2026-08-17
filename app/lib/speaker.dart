/// Reads incoming messages aloud on the phone, including from a pocket.
///
/// The phone is the better speaker of the two devices: it can be heard in a car, its
/// battery is not a watch's, and — with the `audio` background mode declared in
/// Info.plist — it can talk while the screen is off. The watch is an extension of it,
/// not a replacement, so the wrist only speaks when it is the thing being looked at.
///
/// One engine for the whole app. Two FlutterTts instances would contend for the same
/// AVAudioSession and cut each other off mid-sentence, which is precisely the failure
/// that made messages truncate on the watch.
import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter_tts/flutter_tts.dart';

/// The pause between "From Dirck." and the words. Run together, the name
/// blurs into the opening of the message and the listener loses both halves.
const _kSpeakGap = Duration(milliseconds: 500);

/// How long an utterance may wait before it is no longer worth saying. Matches the
/// server's monitor catch-up window, so what the phone speaks and what the server
/// considers current mean the same thing.
const _kMaxSpeechAge = Duration(minutes: 5);

class Speaker {
  Speaker._();
  static final Speaker instance = Speaker._();

  final FlutterTts _tts = FlutterTts();
  bool _ready = false;

  /// Serializes utterances. Two messages arriving a second apart must not produce
  /// "Message from A / Message from B / text of A / text of B".
  Future<void> _queue = Future.value();

  Future<void> _ensureReady() async {
    if (_ready) return;
    _ready = true;
    // Resolves speak() when the phrase finishes rather than when it starts, which is
    // what lets the gap below actually land between the two halves.
    await _tts.awaitSpeakCompletion(true);
    if (Platform.isIOS) {
      // playback + duckOthers is what allows audio to start while backgrounded and
      // makes navigation or music dip rather than stop. Without the playback
      // category iOS refuses the session outright once the app leaves the screen.
      //
      // No defaultToSpeaker here, however obviously useful it sounds: it is valid
      // only with playAndRecord, and setting it alongside playback makes the whole
      // category call fail — leaving the session unconfigured and the app silent in
      // the background, which is the one case this exists for. playback already
      // routes to the speaker.
      await _tts.setIosAudioCategory(
        IosTextToSpeechAudioCategory.playback,
        [IosTextToSpeechAudioCategoryOptions.duckOthers],
        IosTextToSpeechAudioMode.spokenAudio,
      );
    }
    await _tts.setSpeechRate(0.5);
  }

  /// "From <who>." — pause — the text.
  ///
  /// One preamble, whoever it was addressed to. Monitored traffic used to be announced
  /// as "Monitored, from Net Control", on the reasoning that a listener should know a
  /// message was not meant for them. In practice the sender's name already carries
  /// that, the qualifier made every announcement longer, and it read as clutter rather
  /// than information — so it is gone.
  ///
  /// There is deliberately no option here for radio traffic. It was tried: a
  /// synthesised voice reading a machine transcript of an over is slower than the
  /// traffic it describes, so it falls further behind all net; it discards tone and
  /// urgency; and it pronounces a mangled callsign in exactly the same confident
  /// cadence as a correct one. The recording itself is better on every count, so radio
  /// is played rather than spoken — see MonitorService.kPrefRadioAudio.
  Future<void> speakMessage({
    required String senderLabel,
    required String text,
  }) {
    final who = senderLabel.trim();
    final body = text.trim();
    if (body.isEmpty && who.isEmpty) return Future.value();
    // Stamped when the utterance is queued, not when it is spoken — the whole point
    // is to measure how long it waited.
    final queuedAt = DateTime.now();
    _queue = _queue.then((_) async {
      // Speech is real time and a backlog is not. Two messages take longer to read
      // than they took to arrive, so on a busy net the queue grows without bound and
      // the phone ends up narrating a net that finished ten minutes ago — steadily
      // further behind, and impossible to interrupt. Anything that has waited this
      // long has been overtaken by events; the text is still on screen.
      if (DateTime.now().difference(queuedAt) > _kMaxSpeechAge) return;
      await _ensureReady();
      final preamble = who.isNotEmpty ? 'From $who.' : '';
      if (preamble.isNotEmpty) {
        await _speak(preamble);
        await Future.delayed(_kSpeakGap);
      }
      await _speak(body);
    }).catchError((_) {});
    return _queue;
  }

  Future<void> _speak(String text) async {
    if (text.trim().isEmpty) return;
    try {
      await _tts.speak(text);
    } catch (_) {
      // A refused audio session must not take the alert down with it — the tone and
      // the notification have already done their job.
    }
  }

  Future<void> stop() async {
    try {
      await _tts.stop();
    } catch (_) {}
  }
}
