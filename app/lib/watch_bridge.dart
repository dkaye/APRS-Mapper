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
  int _lastId = 0;

  /// Recently relayed messages, oldest first. Given to a watch that has just
  /// launched so it can show history it never received live. Always marked as
  /// context on the watch, which never announces it.
  final List<Map<String, dynamic>> _recent = [];

  List<Map<String, dynamic>> _conversations = const [];
  Map<String, dynamic>? _destination;

  Timer? _contextTimer;

  // ── lifecycle ───────────────────────────────────────────────────────────────

  /// Called from `main()` before `runApp`. No-op off iOS.
  Future<void> init() async {
    if (_started || !Platform.isIOS) return;
    _started = true;

    final p = await SharedPreferences.getInstance();
    _prefToken = p.getString(BackgroundLocationService.kPrefToken);
    _prefSharing = p.getBool(BackgroundLocationService.kPrefActive) ?? false;
    _speak = p.getBool(_kSpeakPref) ?? true;
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
  }

  /// Called once MapScreen owns a real session, so the live token wins over the
  /// persisted one and ending a session immediately wipes the watch's copy.
  void attachSession(BackgroundLocationService bg) {
    if (!Platform.isIOS) return;
    _bg = bg;
    _scheduleContext();
  }

  // ── phone → watch ───────────────────────────────────────────────────────────

  /// Relay one inbound message. Hangs off the same `onMessageReceived` callback
  /// the phone's own notification path uses, so the two never diverge.
  void pushInbound(InboundMessage m) {
    if (!Platform.isIOS || !_started) return;
    final dict = _messageDict(m);
    if (m.id > _lastId) _lastId = m.id;
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
      'conversationId': conversationId,
      'recipients': recipients,
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

  void _scheduleContext() {
    if (!_started) return;
    _contextTimer?.cancel();
    _contextTimer = Timer(_kContextDebounce, () => unawaited(_sendContext()));
  }

  Future<void> _sendContext() async {
    final token = _token;
    await _invoke('setContext', {
      'v': 1,
      'ts': DateTime.now().millisecondsSinceEpoch ~/ 1000,
      'token': token,
      'serverBase': MapConfig.serverBaseUrl,
      'callsign': _bg?.callsign ?? '',
      'trackerName': _bg?.trackerName ?? '',
      'sharing': token != null,
      'speak': _speak,
      'lastId': _lastId,
      'destination': _destination,
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

      default:
        break;
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
      final id = d['id'] as int;
      if (id > _lastId) _lastId = id;
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
        'fromKind': m.fromKind,
        'fromShort': m.fromShort,
        'broadcast': m.broadcast,
        'hasPhoto': false,
        'self': false,
      };

  Map<String, dynamic> _msgMessageDict(MsgMessage m) => {
        'id': m.id,
        'conversationId': m.conversationId,
        'ts': m.ts,
        'text': m.text,
        'senderLabel': m.senderLabel,
        'fromKind': m.fromKind,
        'fromShort': m.fromShort,
        'broadcast': m.broadcast,
        'hasPhoto': m.hasPhoto,
        'self': false,
      };
}
