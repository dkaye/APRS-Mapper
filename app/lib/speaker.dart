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

class Speaker {
  Speaker._();
  static final Speaker instance = Speaker._();

  final FlutterTts _tts = FlutterTts();
  bool _ready = false;

  /// Applied before EVERY utterance, not once at startup.
  ///
  /// The iOS audio session is process-wide shared state, and this app has more than one
  /// thing that configures it: AudioQueue owns a just_audio player for radio clips and
  /// sets its own category, through the same queue that then hands over to speech. Last
  /// writer wins. Configuring once and assuming it holds was true when this was the only
  /// audio component in the app and silently stopped being true when the queue arrived —
  /// the phone kept speaking on screen, where the category barely matters, and went
  /// quiet locked, where it is the only thing allowing audio at all.
  ///
  /// It is a method-channel call against state already in the right shape most of the
  /// time, which is far cheaper than the failure it prevents.
  Future<void> _ensureReady() async {
    // Set only after the calls that matter have actually returned. Above them, a category
    // call that threw left the object flagged configured while being unconfigured — for
    // the life of the process, because nothing retries a thing marked done.
    if (!_ready) {
      // Resolves speak() when the phrase finishes rather than when it starts, which is
      // what lets the gap below actually land between the two halves.
      await _tts.awaitSpeakCompletion(true);
      await _tts.setSpeechRate(0.5);
    }
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
    _ready = true;
  }

  /// Say one thing and complete when it has been said. No queue, no staleness rule:
  /// AudioQueue owns both now, because it also holds radio clips and the two have to
  /// take turns through one gate. Speaking straight from here would put a synthesised
  /// voice on top of a real one.
  Future<void> speakNow({required String senderLabel, required String text}) async {
    final who = senderLabel.trim();
    final body = text.trim();
    if (who.isEmpty && body.isEmpty) return;
    // Best effort. A refused category is a reason to try speaking anyway — the session
    // may already be in a usable state — not a reason to give up before the attempt.
    try {
      await _ensureReady();
    } catch (_) {}
    if (who.isNotEmpty) {
      await _speak('From $who.');
      await Future.delayed(_kSpeakGap);
    }
    await _speak(body);
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
