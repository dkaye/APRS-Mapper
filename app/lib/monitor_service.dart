/// Following the whole event, rather than only what was addressed to you.
///
/// Two things a phone cannot normally reach: other people's traffic, and the radio.
/// Neither is a permission problem — the delivered feed is a join on `deliveries`, and
/// a message not addressed to you has no row there, while a Transcriber entry has no
/// rows at all. The server's `?messaging=monitor` action is a read-only view over the
/// event's messages that sidesteps both, and this is the client half of it.
///
/// Three things this must get right, all of them about not drowning the user:
///
///   1. **Nothing here raises a notification.** A monitored message was not sent to
///      this person. On a busy net that is a message every few seconds, and the phone
///      would be unusable inside a minute.
///   2. **The cursor is persisted.** MessagingScreen's `_lastId` is in-memory and
///      resets to 0 every time the screen opens, which is survivable for a thread you
///      are looking at and is not survivable for a firehose. The watch already learned
///      this — see `_kWatchLastId` in watch_bridge.dart.
///   3. **A gap is reported, not hidden.** The server bounds catch-up by age and
///      count and says how many it dropped. Swallowing that number would make a
///      half-hour outage look like half an hour of silence on the air.
import 'dart:async';

import 'package:shared_preferences/shared_preferences.dart';

import 'messaging_client.dart';

class MonitorService {
  MonitorService._();
  static final MonitorService instance = MonitorService._();

  /// (a) Play the off-air recording of each transmission as it arrives.
  ///
  /// The actual radio, not a voice reading a transcription of it. Those are very
  /// different products: a synthesised voice reading a machine transcript is slower
  /// than the traffic it describes, loses every bit of tone, and states a mangled
  /// callsign in the same confident cadence as a correct one. This plays what was
  /// really said.
  static const kPrefRadioAudio = 'monitor_radio_audio';

  /// (b) Receive every message in the event, whoever sent it and whoever it was for.
  static const kPrefAll = 'monitor_all_messages';

  /// (c) Speak those messages aloud. Only meaningful with [kPrefAll], and gated on it
  /// in the UI — this is about typed traffic, which is short and arrives rarely enough
  /// to be worth hearing. It is deliberately NOT offered for the radio, where the
  /// recording itself is available and strictly better.
  static const kPrefSpeakAll = 'monitor_speak_all';

  /// Where the monitor feed has read up to. Separate from the delivered feed's
  /// cursor, and persisted: an app restart mid-net must not replay the event.
  static const _kPrefCursor = 'monitor_last_id';

  bool _all = false;
  bool _radioAudio = false;
  bool _speakAll = false;
  int _cursor = 0;
  /// Second cursor, for rows that CHANGED rather than rows that are new. Not persisted:
  /// it is the server's clock, so a stale one across a restart would ask for a window
  /// that has nothing to do with this session. Starting at 0 asks by id alone on the
  /// first poll, which is right -- nothing is held yet to be updated.
  int _textCursor = 0;
  /// Ids this device has already been given, so a row coming back with its late
  /// transcription is recognised as an update.
  ///
  /// Separate from `recent`, which is capped and drops its oldest: a row evicted by that
  /// cap and then updated would look new again, and "new" is what queues radio audio --
  /// so the recording would play a second time. Bounded well above the cap for the same
  /// reason it exists at all.
  final _seenIds = <int>{};
  static const _kSeenCap = 2000;
  bool _loaded = false;
  bool _inFlight = false;

  bool get playingRadioAudio => _radioAudio;
  bool get monitoringAll => _all;
  bool get speakingAll => _speakAll;
  bool get enabled => _all || _radioAudio;

  /// Log entries are requested whenever radio audio is wanted, because the clip URL
  /// arrives on the log entry — that is the only way to learn a recording exists. The
  /// entry's TEXT is not shown or spoken for this option; it is carrier for the audio.
  bool get wantsLog => _radioAudio;

  /// What has been monitored recently, newest last, for the monitor view to show.
  ///
  /// Held here rather than fetched when the view opens, and that is deliberate: the
  /// cursor belongs to this service, and a second call with a rewound cursor to
  /// backfill history would either disturb it or need a parallel one. The server bounds
  /// catch-up to a few minutes anyway, so there is little history to miss — and this
  /// fills from the moment monitoring is switched on, which is when the operator asked
  /// to start following.
  final List<MsgMessage> recent = [];

  /// A net can produce a message every few seconds for an hour. The view is a running
  /// log, not an archive — the written record lives on the server.
  static const _kRecentCap = 300;

  /// Total skipped by the server's catch-up bound since monitoring began, so the view
  /// can say a gap happened rather than leaving one silently.
  int skippedTotal = 0;

  final _messages = StreamController<List<MsgMessage>>.broadcast();

  /// Batches, not individual messages. A caller that wants to speak them needs to
  /// know how many arrived together in order to decide not to speak them all.
  Stream<List<MsgMessage>> get messages => _messages.stream;

  final _skipped = StreamController<int>.broadcast();

  /// How many the server dropped from a catch-up. Surfaced so the UI can say "42
  /// messages skipped" rather than leaving a silent hole.
  Stream<int> get skipped => _skipped.stream;

  Future<void> load() async {
    if (_loaded) return;
    final p = await SharedPreferences.getInstance();
    _all = p.getBool(kPrefAll) ?? false;
    _radioAudio = p.getBool(kPrefRadioAudio) ?? false;
    _speakAll = p.getBool(kPrefSpeakAll) ?? false;
    _cursor = p.getInt(_kPrefCursor) ?? 0;
    _loaded = true;
  }

  Future<void> setRadioAudio(bool v) => _setFlag(kPrefRadioAudio, v, (x) => _radioAudio = x);
  Future<void> setAll(bool v) => _setFlag(kPrefAll, v, (x) => _all = x);
  // Speech changes nothing about what is fetched, so it does not reset the cursor the
  // way a subscription does — turning it on mid-net should not replay anything.
  Future<void> setSpeakAll(bool v) async {
    _speakAll = v;
    final p = await SharedPreferences.getInstance();
    await p.setBool(kPrefSpeakAll, v);
  }

  Future<void> _setFlag(String key, bool v, void Function(bool) assign) async {
    assign(v);
    final p = await SharedPreferences.getInstance();
    await p.setBool(key, v);
    // Turning a subscription on starts from now, not from whenever this device last
    // looked. Otherwise switching it on mid-net delivers a backlog nobody asked for,
    // which is the first thing the user would see and the last thing they wanted.
    if (v) {
      await _startFromNow();
    } else if (!enabled) {
      // Nothing is being followed any more, so the list behind the button is history
      // nobody asked to keep. Cleared rather than left to look live.
      recent.clear();
      skippedTotal = 0;
    }
  }

  Future<void> _startFromNow() async {
    // -1 is "wherever the event is now": the server treats any id greater than the
    // cursor as new, so a fresh subscriber wants the newest, and the first poll's
    // last_id supplies it. Asking for 0 would ask for the whole event.
    _cursor = 0;
    _skipFirstBatch = true;
  }

  /// Set when a subscription has just been switched on. The first poll after that is
  /// used only to learn where the event is, and its contents are discarded — the
  /// alternative is that enabling "hear everything" immediately reads out the last
  /// five minutes of somebody else's net.
  bool _skipFirstBatch = false;

  /// One pass. Safe to call from an existing timer — there is deliberately no timer
  /// in here, because the phone already runs several and a fourth competing for the
  /// radio is how battery goes.
  Future<void> poll(MessagingClient client) async {
    if (!_loaded || !enabled || _inFlight) return;
    _inFlight = true;
    try {
      final res = await client.monitor(_cursor, all: _all, radio: wantsLog,
                                       sinceTextTs: _textCursor);
      if (res.lastId != _cursor) {
        _cursor = res.lastId;
        final p = await SharedPreferences.getInstance();
        await p.setInt(_kPrefCursor, _cursor);
      }
      if (res.textTs > _textCursor) _textCursor = res.textTs;
      if (_skipFirstBatch) {
        _skipFirstBatch = false;
        return;
      }
      if (res.skipped > 0) {
        skippedTotal += res.skipped;
        _skipped.add(res.skipped);
      }
      if (res.messages.isNotEmpty) {
        // Merged by id, not appended. Everything used to be strictly newer than the
        // cursor; now a batch can carry a row already held, back with the transcription
        // that was not ready the first time. Appending it would show the same
        // transmission twice in the monitor view.
        //
        // `fresh` is what the rest of the app is told about, and it deliberately
        // excludes updates: downstream, radio audio is queued from these, and replaying
        // a recording because its words caught up is worse than the missing text this
        // whole mechanism exists to fix.
        final fresh = <MsgMessage>[];
        for (final m in res.messages) {
          final at = recent.indexWhere((r) => r.id == m.id);
          if (at >= 0) {
            recent[at] = m;
          } else if (_seenIds.contains(m.id)) {
            // Seen before but no longer in `recent` -- the cap dropped it. Still an
            // update, and must not be announced or played again.
          } else {
            recent.add(m);
            fresh.add(m);
          }
          _seenIds.add(m.id);
        }
        while (_seenIds.length > _kSeenCap) {
          _seenIds.remove(_seenIds.first);
        }
        if (recent.length > _kRecentCap) {
          recent.removeRange(0, recent.length - _kRecentCap);
        }
        if (fresh.isNotEmpty) _messages.add(fresh);
      }
    } finally {
      _inFlight = false;
    }
  }

  /// Forget where we were. Used when the event changes: ids do not restart, but the
  /// traffic does, and carrying a cursor across is only ever confusing.
  Future<void> reset() async {
    recent.clear();
    skippedTotal = 0;
    _cursor = 0;
    _textCursor = 0;
    _seenIds.clear();
    _skipFirstBatch = true;
    final p = await SharedPreferences.getInstance();
    await p.remove(_kPrefCursor);
  }
}
