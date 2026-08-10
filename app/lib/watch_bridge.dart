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

  /// The watch app is on screen in front of the operator, which on watchOS is the
  /// only state in which it can make a sound. The phone stays quiet only then — the
  /// watch is an extension of the phone, not a replacement for it, so the phone
  /// alerts for everything else including a backgrounded watch.
  bool get watchAppFrontmost => Platform.isIOS && paired && appInstalled && reachable;

  /// Alert the phone raises for a message, whichever path saw it first. Registered by
  /// MapScreen.
  ///
  /// This exists because there is no longer a single path. The background session,
  /// the chat screen and the receipt poll all read the same feed, and the first to
  /// poll marks the message delivered, so any of them can be the only one to see it.
  /// Hanging the alert off one of them left the phone silent whenever another got
  /// there first.
  void Function(InboundMessage)? onInboundSeen;

  /// Ids already handed to `onInboundSeen`, so a message reaching us down two paths
  /// alerts once.
  final Set<int> _alerted = {};

  void _alertOnce(InboundMessage m) {
    if (!_alerted.add(m.id)) return;
    if (_alerted.length > 500) _alerted.remove(_alerted.first);
    onInboundSeen?.call(m);
  }

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
  ///
  /// MapScreen alerts for this one itself, so it is only recorded here — claiming the
  /// id stops a later path re-alerting for the same message.
  void pushInbound(InboundMessage m) {
    _alerted.add(m.id);
    _relay(_messageDict(m));
  }

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
    _aimAt(dict);
    unawaited(_invoke('pushMessage', dict));
    _scheduleContext();
  }

  /// Point the next reply at whoever just called.
  ///
  /// This is what makes the watch behave like a radio rather than a form: the
  /// announcement the operator just heard — "Message from Net Control" — is also a
  /// statement of where their reply will go. Replying into the thread reaches the
  /// original sender and everyone else it was addressed to, which is what a net
  /// expects of a group call.
  ///
  /// Called from `_relay`, the one point both inbound paths converge on, so it cannot
  /// be wired to one and forgotten on the other. It therefore fires twice per message
  /// — `setDestination` ignores an unchanged value.
  void _aimAt(Map<String, dynamic> dict) {
    if (dict['self'] == true) return; // our own traffic, echoed back to us

    if (dict['broadcast'] == true) {
      // Answer the calling station, not the whole net. A spoken "copy that" reaching
      // every tracker is a worse default than one that reaches Net Control, and
      // broadcasting stays available deliberately through Reply to → All Trackers.
      final key = dict['fromKey'] as String?;
      if (dict['fromKind'] == 'operator' && key != null && key.isNotEmpty) {
        setDestination(recipients: [key], label: dict['senderLabel'] as String? ?? key);
      }
      // A broadcast from a mobile has no addressable key here; leave the aim alone
      // rather than guess.
      return;
    }

    final cid = dict['conversationId'] as int? ?? 0;
    if (cid <= 0) return;
    setDestination(conversationId: cid, label: _labelFor(cid, dict));
  }

  /// The thread's own name where we know it, the sender's otherwise. A conversation
  /// outside the top-10 list still gets replied to correctly; only the label shown on
  /// the wrist is less precise.
  String _labelFor(int conversationId, Map<String, dynamic> dict) {
    for (final c in _conversations) {
      if (c['id'] == conversationId) return c['label'] as String? ?? '';
    }
    return dict['senderLabel'] as String? ?? 'Conversation';
  }

  /// Mirror the phone's read-aloud setting. The watch has the same toggle and the
  /// two must agree about what "speak" means.
  void pushSpeak(bool enabled) {
    if (!Platform.isIOS || !_started) return;
    _speak = enabled;
    _scheduleContext();
  }

  /// Where the next spoken reply goes. Set by an arriving message (`_aimAt`), by the
  /// phone's recipient picker, or by the watch's Reply to page — whichever happened
  /// most recently wins, and `chosenAt` is what lets the watch decide that.
  ///
  /// Unchanged values are ignored: `_relay` calls this twice for every message,
  /// because both inbound paths feed it, and a prefs write plus a context push per
  /// duplicate would be pure waste.
  void setDestination({int? conversationId, List<String>? recipients, required String label}) {
    if (!Platform.isIOS || !_started) return;
    final next = <String, dynamic>{
      if (conversationId != null) 'conversationId': conversationId,
      if (recipients != null) 'recipients': recipients,
      'label': label,
    };
    if (_sameTarget(_destination, next)) return;
    next['chosenAt'] = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    _destination = next;
    unawaited(SharedPreferences.getInstance()
        .then((p) => p.setString(_kWatchDestination, jsonEncode(_destination))));
    _scheduleContext();
  }

  /// Same place, ignoring the label and the timestamp — a thread that has been
  /// renamed is still the same thread.
  bool _sameTarget(Map<String, dynamic>? a, Map<String, dynamic> b) {
    if (a == null) return false;
    if (a['conversationId'] != b['conversationId']) return false;
    final ar = (a['recipients'] as List?)?.cast<String>();
    final br = (b['recipients'] as List?)?.cast<String>();
    if (ar == null || br == null) return ar == br;
    return ar.length == br.length && !ar.asMap().entries.any((e) => br[e.key] != e.value);
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
          // The wrist's own timestamp, not ours: it is what decides whether this or a
          // message arriving at the same moment is the newer decision.
          'chosenAt': (e['chosenAt'] as num?)?.toInt() ??
              DateTime.now().millisecondsSinceEpoch ~/ 1000,
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
        _alertOnce(_inboundFrom(m));
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
    for (final m in result.messages) {
      _advanceWatermark(m.id);
      _alertOnce(_inboundFrom(m));
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
  /// The phone's alert path speaks InboundMessage; the messaging API speaks
  /// MsgMessage. Same message, two shapes, because the legacy and modern feeds were
  /// never unified.
  InboundMessage _inboundFrom(MsgMessage m) => InboundMessage(
        id: m.id,
        fromLabel: m.fromName,
        text: m.text,
        ts: m.ts,
        conversationId: m.conversationId,
        fromShort: m.fromShort,
        fromKind: m.fromKind,
        fromKey: m.fromKey,
        broadcast: m.broadcast,
      );

  Map<String, dynamic> _messageDict(InboundMessage m) => {
        'id': m.id,
        'conversationId': m.conversationId,
        'ts': m.ts,
        'text': m.text,
        'senderLabel': m.senderLabel,
        if (m.fromKind != null) 'fromKind': m.fromKind,
        if (m.fromShort != null) 'fromShort': m.fromShort,
        if (m.fromKey != null) 'fromKey': m.fromKey,
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
        if (m.fromKey != null) 'fromKey': m.fromKey,
        'broadcast': m.broadcast,
        'hasPhoto': m.hasPhoto,
        'self': isSelf,
      };
}
