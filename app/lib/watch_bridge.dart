/// Dart side of the Apple Watch bridge.
///
/// Talks to `app/ios/Runner/WatchBridge.swift` over a method channel (Dart → native)
/// and an event channel (native → Dart). The watch itself is native Swift under
/// `app/ios/WatchApp`; see README.md ("Apple Watch Companion") for the whole design.
///
/// Two rules shape this file:
///
/// 1. **It initializes from `main()`, not from a widget.** `StartupRouter` routes to
///    DownloadScreen or PasswordGateScreen first, so on a WatchConnectivity-triggered
///    background cold launch `MapScreen` — and therefore its `MessagingClient` — may
///    never be built. The bridge therefore owns its own client and reads the token
///    straight from SharedPreferences.
/// 2. **It never dedupes.** `BackgroundLocationService._deliveredMsgIds` is the single
///    inbound dedupe on the phone, and the watch has its own by message id. A third
///    one here could only disagree with them.
import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;

import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'background_location.dart'; // also re-exports InboundMessage
import 'map_config.dart';
import 'messaging_client.dart';

/// Where the sticky watch destination is remembered. Deliberately not
/// `aprs_msg_last_recipients` (messaging_screen.dart): a destination can be an
/// existing conversation_id, which a list of recipient keys cannot express.
const _kWatchDestination = 'aprs_watch_destination';
const _kSpeakPref = 'aprs_msg_speak';
const _kWatchLastId = 'aprs_watch_last_id';

/// Newest-first cap on the history handed to a watch that has just launched.
const _kRecentCap = 20;

/// Application context is latest-value-wins, so bursts coalesce anyway; this keeps
/// us from spending radio on a push per keystroke of activity.
const _kContextDebounce = Duration(seconds: 2);

class WatchBridge {
  WatchBridge._();
  static final WatchBridge instance = WatchBridge._();

  static const _method = MethodChannel('org.marsaprs/watch');
  static const _events = EventChannel('org.marsaprs/watch/events');

  bool _started = false;
  BackgroundLocationService? _bg;
  late final MessagingClient _client = MessagingClient(() => _token);

  String? _prefToken;
  bool _prefSharing = false;
  bool _speak = true;

  /// Live session token when MapScreen is up, the persisted one otherwise.
  String? get _token {
    final live = _bg?.session.token;
    if (live != null && live.isNotEmpty) return live;
    final p = _prefToken;
    return (p != null && p.isNotEmpty && _prefSharing) ? p : null;
  }

  /// Watch link state, mirrored from the native side for the UI to show.
  bool paired = false;
  bool appInstalled = false;
  bool reachable = false;

  /// Highest message id we have handed to the watch.
  ///
  /// Persisted. It used to reset to 0 on every launch of the phone app, which turned
  /// the next receipt poll into `poll(since_id: 0)` — the server obligingly returned
  /// the participant's entire history and the bridge relayed all of it to the wrist.
  /// The watch dedupes by id so most of it was discarded, but anything recent enough
  /// to pass the announce window was read out a second time.
  int _lastId = 0;

  /// Recently relayed messages, oldest first. Given to a watch that has just
  /// launched so it can show history it never received live. Always marked as
  /// context on the watch, which never announces it.
  final List<Map<String, dynamic>> _recent = [];

  List<Map<String, dynamic>> _conversations = const [];
  Map<String, dynamic>? _destination;

  Timer? _contextTimer;

  /// The watch's list of places it can reply to used to arrive only from the chat
  /// screen, which meant a watch was mute until the operator happened to open
  /// Messages on the phone. The bridge fetches it itself now, so the wrist is usable
  /// from launch.
  Timer? _convTimer;
  static const _convRefresh = Duration(seconds: 60);

  /// Messages sent from the watch whose receipts we are still following, and the
  /// furthest stage each has reached (0 sent, 1 delivered, 2 read). Only watch sends
  /// are tracked: the operator is looking at the phone for anything typed there, and
  /// announcing its receipts on the wrist would be noise.
  final Map<int, int> _receiptStage = {};
  Timer? _receiptTimer;

  /// Stop following a message after this. A recipient who never opens the app would
  /// otherwise keep us polling for the rest of the event.
  static const _receiptGiveUp = Duration(minutes: 10);
  final Map<int, DateTime> _receiptSince = {};

  // ── lifecycle ───────────────────────────────────────────────────────────────

  /// Called from `main()` before `runApp`. No-op off iOS.
  Future<void> init() async {
    if (_started || !Platform.isIOS) return;
    _started = true;

    final p = await SharedPreferences.getInstance();
    _prefToken = p.getString(BackgroundLocationService.kPrefToken);
    _prefSharing = p.getBool(BackgroundLocationService.kPrefActive) ?? false;
    _speak = p.getBool(_kSpeakPref) ?? true;
    _lastId = p.getInt(_kWatchLastId) ?? 0;
    final saved = p.getString(_kWatchDestination);
    if (saved != null && saved.isNotEmpty) {
      try {
        _destination = jsonDecode(saved) as Map<String, dynamic>;
      } catch (_) {
        // A corrupt value must not stop the bridge from starting.
      }
    }

    // Never cancelled: this singleton lives for the life of the process, and the
    // watch link has to survive every screen transition. An error on the channel is
    // not fatal either — the phone app is fully usable without a watch.
    _events.receiveBroadcastStream().listen(_onEvent, onError: (Object _) {});

    // `ready` both reports the link state and tells the native side to replay
    // anything the watch sent while Dart was still starting.
    _applyState(await _invoke('ready'));
    _scheduleContext();
    _startConversationRefresh();
  }

  void _startConversationRefresh() {
    _convTimer?.cancel();
    unawaited(_refreshConversations());
    _convTimer = Timer.periodic(_convRefresh, (_) => unawaited(_refreshConversations()));
  }

  /// Keep the wrist's reply targets current without depending on any screen being
  /// open on the phone. Cheap: one request a minute, and only while sharing.
  Future<void> _refreshConversations() async {
    if (!paired || _token == null) return;
    final list = await _client.conversations();
    if (list.isEmpty) return;
    pushConversations(list);

    // No destination yet, but there is somewhere obvious to reply to. Adopt the most
    // recent thread rather than leaving Talk disabled -- the server orders these by
    // last activity, so it is the conversation the operator is already in.
    if (_destination == null) {
      setDestination(conversationId: list.first.id, label: list.first.label);
    }
  }

  /// Called once MapScreen owns a real session, so the live token wins over the
  /// persisted one and ending a session immediately wipes the watch's copy.
  void attachSession(BackgroundLocationService bg) {
    if (!Platform.isIOS) return;
    _bg = bg;
    _scheduleContext();
  }

  // ── phone → watch ───────────────────────────────────────────────────────────

  /// Relay a message seen by the background session (legacy `?mobile=poll`).
  void pushInbound(InboundMessage m) => _relay(_messageDict(m));

  /// Relay a message seen by the chat screen's own poll loop (`?messaging=poll`).
  ///
  /// The phone ingests messages by two independent routes and the watch has to see
  /// both. They are not interchangeable: the chat screen marks what it displays as
  /// read, and the legacy feed only returns messages with `read_ts IS NULL`, so once
  /// this path has taken a message the other one can never deliver it. Tapping only
  /// the background session left the watch silent whenever the phone's Messages
  /// screen happened to be open. Relaying from both is safe — the watch dedupes by
  /// message id, so whichever arrives second is dropped.
  void pushSeenInChat(MsgMessage m, {required bool isSelf}) =>
      _relay(_msgMessageDict(m, isSelf: isSelf));

  void _relay(Map<String, dynamic> dict) {
    if (!Platform.isIOS || !_started) return;
    _advanceWatermark(dict['id'] as int);
    _recent.add(dict);
    while (_recent.length > _kRecentCap) {
      _recent.removeAt(0);
    }
    unawaited(_invoke('pushMessage', dict));
    _scheduleContext();
  }

  /// Mirror the phone's read-aloud setting. The watch has the same toggle and the
  /// two must agree about what "speak" means.
  void pushSpeak(bool enabled) {
    if (!Platform.isIOS || !_started) return;
    _speak = enabled;
    _scheduleContext();
  }

  /// The sticky destination. Opening a thread on the phone is what makes it the
  /// watch's reply target, which is why this is called from the chat screen rather
  /// than being a separate setting.
  void setDestination({int? conversationId, List<String>? recipients, required String label}) {
    if (!Platform.isIOS || !_started) return;
    _destination = {
      if (conversationId != null) 'conversationId': conversationId,
      if (recipients != null) 'recipients': recipients,
      'label': label,
    };
    unawaited(SharedPreferences.getInstance()
        .then((p) => p.setString(_kWatchDestination, jsonEncode(_destination))));
    _scheduleContext();
  }

  /// The short recent-conversation list the watch offers as switch targets.
  void pushConversations(List<MsgConversation> convs) {
    if (!Platform.isIOS || !_started) return;
    _conversations = convs
        .take(10)
        .map((c) => <String, dynamic>{
              'id': c.id,
              'kind': c.kind,
              'label': c.label,
              'unread': c.unread,
              'lastId': c.lastId,
              'previewText': c.preview?.text ?? '',
              'previewTs': c.preview?.ts ?? 0,
              'previewSelf': c.preview?.self ?? false,
            })
        .toList();
    _scheduleContext();
  }

  /// Session ended / token changed — push immediately rather than debounced, so a
  /// watch holding a now-dead token drops it at once.
  void pushContextNow() {
    if (!Platform.isIOS || !_started) return;
    _contextTimer?.cancel();
    unawaited(_sendContext());
  }

  /// The only place the watermark moves, so persisting it cannot be forgotten at a
  /// call site. Losing it means re-fetching a participant's whole history.
  void _advanceWatermark(int id) {
    if (id <= _lastId) return;
    _lastId = id;
    unawaited(SharedPreferences.getInstance().then((p) => p.setInt(_kWatchLastId, id)));
  }

  void _scheduleContext() {
    if (!_started) return;
    _contextTimer?.cancel();
    _contextTimer = Timer(_kContextDebounce, () => unawaited(_sendContext()));
  }

  Future<void> _sendContext() async {
    final token = _token;
    // Null-valued keys are omitted rather than sent: WatchConnectivity rejects the
    // whole payload if any value is not a property-list type, and Flutter encodes a
    // Dart null as NSNull. The native side strips them too, belt and braces. Absent
    // means null by contract -- the context is always a complete snapshot.
    await _invoke('setContext', {
      'v': 1,
      'ts': DateTime.now().millisecondsSinceEpoch ~/ 1000,
      if (token != null) 'token': token,
      'serverBase': MapConfig.serverBaseUrl,
      'callsign': _bg?.callsign ?? '',
      'trackerName': _bg?.trackerName ?? '',
      'sharing': token != null,
      'speak': _speak,
      'lastId': _lastId,
      if (_destination != null) 'destination': _destination,
      'conversations': _conversations,
      'recent': _recent,
    });
  }

  // ── watch → phone ───────────────────────────────────────────────────────────

  void _onEvent(dynamic raw) {
    if (raw is! Map) return;
    final e = Map<String, dynamic>.from(raw);
    switch (e['type'] as String? ?? '') {
      case 'watchState':
        final wasReachable = reachable;
        _applyState(e);
        // Newly reachable: the watch has just stopped whatever it was doing on its
        // own and needs current state now, not at the next natural push.
        if (!wasReachable && reachable) pushContextNow();
        break;

      case 'hello':
        // The watch has just come forward and may have been away for hours.
        unawaited(_refreshConversations());
        pushContextNow();
        break;

      case 'speak':
        _speak = e['enabled'] as bool? ?? _speak;
        unawaited(SharedPreferences.getInstance().then((p) => p.setBool(_kSpeakPref, _speak)));
        _scheduleContext();
        break;

      case 'sync':
        unawaited(_sync((e['sinceId'] as num?)?.toInt() ?? 0));
        break;

      case 'send':
        unawaited(_send(e));
        break;

      case 'destination':
        _destination = {
          if (e['conversationId'] != null) 'conversationId': (e['conversationId'] as num).toInt(),
          if (e['recipients'] != null) 'recipients': List<String>.from(e['recipients'] as List),
          'label': e['label'] as String? ?? '',
        };
        unawaited(SharedPreferences.getInstance()
            .then((p) => p.setString(_kWatchDestination, jsonEncode(_destination))));
        // Echoed back in the next context, which is how the watch knows the phone
        // accepted its choice rather than assuming it did.
        _scheduleContext();
        break;

      default:
        break;
    }
  }

  /// A reply spoken into the watch. The phone owns the token and does the HTTP, so
  /// the watch never talks to the server on this path.
  ///
  /// The result is reported by `clientId` rather than positionally, because it may
  /// come back long after the request: the native side answers the watch immediately
  /// with `queued` when this engine was still starting, and the real answer then
  /// travels out of band.
  Future<void> _send(Map<String, dynamic> e) async {
    final clientId = e['clientId'] as String?;
    if (clientId == null) return;
    final text = (e['text'] as String? ?? '').trim();
    final conversationId = (e['conversationId'] as num?)?.toInt();
    final recipients =
        e['recipients'] != null ? List<String>.from(e['recipients'] as List) : null;

    if (text.isEmpty || (conversationId == null && recipients == null)) {
      await _invoke('sendResult', {'clientId': clientId, 'ok': false, 'error': 'Nothing to send'});
      return;
    }
    if (_token == null) {
      await _invoke('sendResult', {'clientId': clientId, 'ok': false, 'error': 'Not sharing'});
      return;
    }

    final r = await _client.send(
      text: text,
      conversationId: conversationId,
      recipients: recipients,
    );
    await _invoke('sendResult', {
      'clientId': clientId,
      'ok': r.ok,
      if (r.id != null) 'id': r.id,
      if (r.conversationId != null) 'conversationId': r.conversationId,
      if (r.error != null) 'error': r.error,
    });

    if (r.ok && r.id != null) _followReceipts(r.id!);

    // A successful send may have created the thread the watch will keep replying to,
    // so make that the sticky destination rather than leaving it on a recipient list
    // that would open a second thread next time.
    if (r.ok && r.conversationId != null && conversationId == null) {
      setDestination(
        conversationId: r.conversationId,
        label: (_destination?['label'] as String?) ?? 'Conversation',
      );
    }
  }

  // ── delivery receipts for watch-originated sends ───────────────────────────

  /// Start following one message and tell the watch it left the building.
  void _followReceipts(int messageId) {
    _receiptStage[messageId] = 0;
    _receiptSince[messageId] = DateTime.now();
    unawaited(_invoke('pushMessage', {
      'type': 'receipt',
      'messageId': messageId,
      'stage': 'sent',
      'total': 0,
      'count': 0,
    }));
    _receiptTimer ??= Timer.periodic(const Duration(seconds: 5), (_) => unawaited(_pollReceipts()));
  }

  /// `poll` reports receipts for the sender's recent messages regardless of the id
  /// watermark — deliberately, server-side, so a delivery landing after the watermark
  /// still reaches the sender. That is what lets this ask for receipts without
  /// re-fetching messages.
  Future<void> _pollReceipts() async {
    if (_receiptStage.isEmpty || _token == null) {
      _receiptTimer?.cancel();
      _receiptTimer = null;
      return;
    }

    final now = DateTime.now();
    _receiptSince.removeWhere((id, since) {
      final expired = now.difference(since) > _receiptGiveUp;
      if (expired) _receiptStage.remove(id);
      return expired;
    });

    final result = await _client.poll(_lastId);
    for (final r in result.receipts) {
      final stage = _receiptStage[r.messageId];
      if (stage == null) continue;
      // Same thresholds the phone's own ack label uses, so the two never disagree
      // about what "delivered" means.
      final reached = r.read > 0 ? 2 : (r.delivered > 0 ? 1 : 0);
      if (reached <= stage) continue;
      _receiptStage[r.messageId] = reached;
      unawaited(_invoke('pushMessage', {
        'type': 'receipt',
        'messageId': r.messageId,
        'stage': reached == 2 ? 'read' : 'delivered',
        'total': r.total,
        'count': reached == 2 ? r.read : r.delivered,
      }));
      // Read is terminal; nothing further to wait for.
      if (reached == 2) {
        _receiptStage.remove(r.messageId);
        _receiptSince.remove(r.messageId);
      }
    }

    // The poll also carries any new inbound traffic. Relay it: this timer only runs
    // while a watch send is outstanding, but during that window it may well be the
    // first path to see a reply.
    if (result.messages.isNotEmpty) {
      for (final m in result.messages) {
        _advanceWatermark(m.id);
      }
      await _invoke('pushMessages', {'messages': result.messages.map(_msgMessageDict).toList()});
    }
  }

  /// The watch fell back to polling on its own and is now handing authority back.
  /// Fill whatever gap it has from the phone's session, which is the side that
  /// knows the real watermark.
  Future<void> _sync(int sinceId) async {
    if (_token == null) return;
    final result = await _client.poll(sinceId);
    if (result.messages.isEmpty) return;
    final dicts = result.messages.map(_msgMessageDict).toList();
    for (final d in dicts) {
      _advanceWatermark(d['id'] as int);
    }
    await _invoke('pushMessages', {'messages': dicts});
    _scheduleContext();
  }

  // ── plumbing ────────────────────────────────────────────────────────────────

  void _applyState(Map<String, dynamic>? s) {
    if (s == null) return;
    paired = s['paired'] as bool? ?? false;
    appInstalled = s['appInstalled'] as bool? ?? false;
    reachable = s['reachable'] as bool? ?? false;
  }

  Future<Map<String, dynamic>?> _invoke(String method, [Map<String, dynamic>? args]) async {
    try {
      final r = await _method.invokeMapMethod<String, dynamic>(method, args ?? const {});
      return r;
    } on MissingPluginException {
      return null; // no native side (shouldn't happen on iOS, harmless if it does)
    } on PlatformException {
      return null;
    }
  }

  /// The one shape a message takes on the wire. Labels are computed here, on the
  /// phone, so the watch never re-implements the mobile/operator/short-id rules.
  Map<String, dynamic> _messageDict(InboundMessage m) => {
        'id': m.id,
        'conversationId': m.conversationId,
        'ts': m.ts,
        'text': m.text,
        'senderLabel': m.senderLabel,
        if (m.fromKind != null) 'fromKind': m.fromKind,
        if (m.fromShort != null) 'fromShort': m.fromShort,
        'broadcast': m.broadcast,
        'hasPhoto': false,
        'self': false,
      };

  Map<String, dynamic> _msgMessageDict(MsgMessage m, {bool isSelf = false}) => {
        'id': m.id,
        'conversationId': m.conversationId,
        'ts': m.ts,
        'text': m.text,
        'senderLabel': m.senderLabel,
        if (m.fromKind != null) 'fromKind': m.fromKind,
        if (m.fromShort != null) 'fromShort': m.fromShort,
        'broadcast': m.broadcast,
        'hasPhoto': m.hasPhoto,
        'self': isSelf,
      };
}
