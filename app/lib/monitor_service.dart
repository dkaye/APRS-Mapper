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

  /// Receive every message in the event, not only those addressed to this device.
  static const kPrefAll = 'monitor_all_messages';

  /// Receive Transcriber entries — what the receivers heard on the air.
  static const kPrefRadio = 'monitor_radio';

  /// Fetch and play the recording attached to a radio entry. Independent of
  /// [kPrefRadio] on purpose: following the radio as text costs almost nothing,
  /// and the audio is the part that costs cellular data.
  static const kPrefAudio = 'monitor_radio_audio';

  /// Where the monitor feed has read up to. Separate from the delivered feed's
  /// cursor, and persisted: an app restart mid-net must not replay the event.
  static const _kPrefCursor = 'monitor_last_id';

  bool _all = false;
  bool _radio = false;
  bool _audio = false;
  int _cursor = 0;
  bool _loaded = false;
  bool _inFlight = false;

  bool get monitoringAll => _all;
  bool get monitoringRadio => _radio;
  bool get playingAudio => _audio;
  bool get enabled => _all || _radio;

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
    _radio = p.getBool(kPrefRadio) ?? false;
    _audio = p.getBool(kPrefAudio) ?? false;
    _cursor = p.getInt(_kPrefCursor) ?? 0;
    _loaded = true;
  }

  Future<void> setAll(bool v) => _setFlag(kPrefAll, v, (x) => _all = x);
  Future<void> setRadio(bool v) => _setFlag(kPrefRadio, v, (x) => _radio = x);
  Future<void> setAudio(bool v) => _setFlag(kPrefAudio, v, (x) => _audio = x);

  Future<void> _setFlag(String key, bool v, void Function(bool) assign) async {
    assign(v);
    final p = await SharedPreferences.getInstance();
    await p.setBool(key, v);
    // Turning a subscription on starts from now, not from whenever this device last
    // looked. Otherwise switching it on mid-net delivers a backlog nobody asked for,
    // which is the first thing the user would see and the last thing they wanted.
    if (v) await _startFromNow();
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
      final res = await client.monitor(_cursor, all: _all, radio: _radio);
      if (res.lastId != _cursor) {
        _cursor = res.lastId;
        final p = await SharedPreferences.getInstance();
        await p.setInt(_kPrefCursor, _cursor);
      }
      if (_skipFirstBatch) {
        _skipFirstBatch = false;
        return;
      }
      if (res.skipped > 0) _skipped.add(res.skipped);
      if (res.messages.isNotEmpty) _messages.add(res.messages);
    } finally {
      _inFlight = false;
    }
  }

  /// Forget where we were. Used when the event changes: ids do not restart, but the
  /// traffic does, and carrying a cursor across is only ever confusing.
  Future<void> reset() async {
    _cursor = 0;
    _skipFirstBatch = true;
    final p = await SharedPreferences.getInstance();
    await p.remove(_kPrefCursor);
  }
}
