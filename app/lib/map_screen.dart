/// Root widget for the APRS Tracker Map app.
/// Hosts the WebView map, the JS↔Dart bridge, the native tracker/breadcrumb overlay,
/// and the Share Location flow (join → track → leave).
import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;
import 'dart:typed_data';
import 'package:just_audio/just_audio.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart'
    show FlutterForegroundTask;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'dart:math' as math;
import 'package:flutter/gestures.dart' show PointerPanZoomUpdateEvent;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';   // HapticFeedback for the map anchor
import 'package:permission_handler/permission_handler.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_map_location_marker/flutter_map_location_marker.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'mobile_session.dart';
import 'arrow_painter.dart';
import 'background_location.dart';
import 'config_service.dart';
import 'course_layer.dart';
import 'download_screen.dart';
import 'help_screen.dart';
import 'update_check.dart';
import 'fixed_marker_layer.dart';
import 'map_config.dart';
import 'menu_drawer.dart';
import 'online_poller.dart';
import 'remote_config.dart';
import 'tracker_data.dart';
import 'tracker_layer.dart';
import 'messaging_client.dart';
import 'messaging_screen.dart';
import 'audio_queue.dart';
import 'monitor_service.dart';
import 'watch_bridge.dart';
import 'widgets/audio_queue_bar.dart';
import 'widgets/mode_indicator.dart';
import 'widgets/offline_banner.dart';
import 'widgets/update_banner.dart';

enum _LocationState { notRequested, whileInUse, always, denied, permanentlyDenied }

class MapScreen extends StatefulWidget {
  final RemoteConfig config;
  final LatLng? initialCenter;

  const MapScreen({super.key, required this.config, this.initialCenter});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> with WidgetsBindingObserver {
  _LocationState _locationState = _LocationState.notRequested;
  bool _sharingConsentShown = false; // true once user has seen the sharing consent screen
  final _mapController = MapController();

  /// A point the map is tethered to, set by long-pressing it.
  ///
  /// EXPERIMENT, 2026-08-30. Borrowed from the web's ctrl-click origin but doing a
  /// different job: that one is a reference for measuring distance and bearing, this one
  /// keeps a place on screen. Pin the aid station or the incident, then pan and zoom
  /// freely -- the map will not let that point leave the view, so an operator scanning
  /// for a tracker cannot lose the thing they are scanning around.
  ///
  /// No coordinates are shown. The point of it is the constraint, and lat/lon on screen
  /// is what the web overlay is for.
  LatLng? _anchor;

  /// True while the anchor is pulling the camera back. Any OTHER programmatic move
  /// clears the anchor, and this correction is itself a programmatic move -- without
  /// this flag the first correction would erase the thing doing the correcting.
  bool _anchorCorrecting = false;
  LatLng? _lastUserLatLng;
  StreamSubscription<Position>? _positionSub;
  Stream<LocationMarkerPosition?>? _locationMarkerStream;
  late RemoteConfig _config;

  // Online tracker state
  List<TrackerData> _trackers = [];
  bool _isOnline = true;
  late final OnlinePoller _poller;

  // Background / tile layer
  String _tileUrl = MapConfig.tileUrl;
  List<String> _tileSubdomains = const [];
  // Created once so TileLayer doesn't reset its cache on every poller setState.
  final _tileProvider = FMTCStore(MapConfig.storeName).getTileProvider();

  // Section and course visibility
  Map<String, bool> _sectionVisible = {
    'trackers': true,
    'courses': true,
    'aidstations': true,
    'igates': true,
  };
  // The user's own section-visibility toggles, persisted. Once set, these override
  // the admin's Default Section Visibility (which is only a starting default).
  Map<String, bool>? _savedSectionVis;
  Map<String, bool> _courseVisible = {};

  // Beacon settings (updated live from ?json poll)
  List<int>    _beaconIntervalsSec = [60, 30, 15, 120];  // Walk, Cycle, Drive, Stationary
  List<double> _beaconDistancesMi  = [0.2, 0.2, 0.2, 1.0];
  int _sharingActivityMode = -1; // mode index active during current share session; -1 if not sharing
  bool _shareBadgeOn = true;

  // Auto activity-mode detection
  int? _candidateAutoMode;
  int _candidateSampleCount = 0;
  DateTime? _candidateFirstSeen;
  DateTime? _lastMovementAt;   // last time sustained movement was detected
  int _movementAboveCount = 0; // consecutive above-threshold readings (guards against noise)
  Timer? _stationaryCheckTimer;
  int _totalSampleCount = 0;   // total since session start; drives startup fast-window
  static const _kAutoSpeedStationary  = 1.0;
  static const _kMovementConfirmSamples = 3; // consecutive above-threshold to confirm movement
  static const _kAutoSpeedWalkRun     = 4.5;
  static const _kAutoSpeedCycle       = 11.0;
  static const _kAutoGeneralWindow    = 15;
  static const _kAutoStationaryWindow = 20;
  static const _kAutoStationaryMinSecs = 300;
  static const _kAutoStartupWindow    = 3;   // samples needed during startup phase
  static const _kAutoStartupTotal     = 10;  // total samples that define startup phase

  // Selection / blink
  String? _selectedId;
  // A fixed marker (iGate/aid) temporarily shown on the map even though its
  // section eyeball is off — set when tapped in the drawer, cleared on any
  // "return to normal view" action (map tap, reset, recenter, new selection).
  String? _revealedFixed;
  // A tracker whose full "ID Name" label is forced on the map (overriding the
  // ID/Name eyeballs) because it was just tapped in the sidebar. Like
  // _revealedFixed, it's transient — cleared on any subsequent action.
  String? _fullLabelTrackerId;
  int _selectionClickCount = 0;
  Set<String> _blinkingIds = {};
  bool _blinkOn = true;
  Timer? _blinkTimer;
  int _blinkDurationSec  = 5;
  int _breadcrumbCount   = 100;

  // Resting tracker label content — toggled by the ID / Name eyes in the sidebar.
  bool _showTrackerIds   = true;
  bool _showTrackerNames = true;

  // Set when the server reports it no longer supports this app's API contract.
  bool _updateRequired      = false;
  bool _updateBannerDismissed = false;

  // Saved map position (restored when reset button tapped)
  LatLng? _savedCenter;
  double? _savedZoom;
  double? _savedRotation;

  // Scale bar
  bool _scaleImperial = true;
  double _scaleZoom = 0;

  // Breadcrumb trail for selected tracker
  List<Map<String, dynamic>> _trailEntries = [];
  List<LatLng> _cellTrailPts  = [];
  List<LatLng> _radioTrailPts = [];

  // Background location / sharing
  final _bgLocation = BackgroundLocationService();
  // Messaging client for the new chat screen (auth = the tracker token).
  late final MessagingClient _msgClient = MessagingClient(() => _bgLocation.session.token);
  bool _isSharing = false;
  final _audioPlayer = AudioPlayer();
  final _notifPlugin = FlutterLocalNotificationsPlugin();
  AppLifecycleState _appLifecycleState = AppLifecycleState.resumed;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _appLifecycleState = state;
    if (state == AppLifecycleState.resumed) {
      // Re-check location permission — user may have changed it in Settings.
      if (_locationState == _LocationState.permanentlyDenied ||
          _locationState == _LocationState.denied ||
          _locationState == _LocationState.notRequested) {
        _recheckLocationPermission();
      }
    }
  }

  // Called on app resume to pick up permission changes made in Settings.
  Future<void> _recheckLocationPermission() async {
    try {
      final permission = await Geolocator.checkPermission();
      if (!mounted) return;
      if (permission == LocationPermission.always) {
        if (_locationState == _LocationState.always) return;
        setState(() => _locationState = _LocationState.always);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
        unawaited(_maybeResumeSharing());
      } else if (permission == LocationPermission.whileInUse) {
        if (_locationState == _LocationState.whileInUse) return;
        setState(() => _locationState = _LocationState.whileInUse);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
        // Resumes on iOS too. This was Android-only, on the reasoning that a foreground
        // service works with whileInUse and iOS wants Always for background location.
        // True as far as it goes, and it left the app inconsistent with itself: nothing
        // gates STARTING a session on the permission, so an iOS user on "While Using the
        // App" could share all day and then be asked for the event password again the
        // next time the app was launched, with a live session sitting unused in
        // preferences. Starting and resuming now agree.
        unawaited(_maybeResumeSharing());
      } else if (permission == LocationPermission.deniedForever) {
        setState(() => _locationState = _LocationState.permanentlyDenied);
      } else {
        setState(() => _locationState = _LocationState.notRequested);
      }
    } catch (_) {}
  }

  Future<void> _initNotifications() async {
    await _notifPlugin.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(
          requestAlertPermission: true,
          requestSoundPermission: true,
          requestBadgePermission: true,
        ),
      ),
    );
    if (Platform.isAndroid) {
      final android = _notifPlugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
      // A channel's sound is immutable once created, so each change of mind about it
      // costs a new channel id. `aprs_msg` had the system default; `aprs_msg_2` carried
      // the custom alert sound; `aprs_msg_3` is SILENT.
      //
      // Silent because the tone now comes from AudioQueue as the first half of the
      // spoken item, which is what guarantees it lands immediately before the words
      // instead of racing them and what stops it landing in the middle of a radio clip.
      // Leaving the channel audible would simply play both — and per-notification
      // `playSound: false` cannot override a channel that was created with sound, which
      // is the trap this comment exists to mark.
      await android?.deleteNotificationChannel(channelId: 'aprs_msg');
      await android?.deleteNotificationChannel(channelId: 'aprs_msg_2');
      await android?.createNotificationChannel(const AndroidNotificationChannel(
        'aprs_msg_3',
        'APRS Messages',
        importance: Importance.max,
        playSound: false,
      ));
      await android?.requestFullScreenIntentPermission();
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        await Future.delayed(const Duration(milliseconds: 500));
        if (!await FlutterForegroundTask.canDrawOverlays) {
          await FlutterForegroundTask.openSystemAlertWindowSettings();
        }
      });
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _initNotifications();
    // Hand the bridge the live session so it prefers the in-memory token over the
    // persisted one it started with.
    WatchBridge.instance.attachSession(_bgLocation);
    // Three separate polls read the same feed and the first to arrive marks a message
    // delivered, so any of them can be the only one that sees it. The alert hangs off
    // all of them rather than off the background session alone.
    WatchBridge.instance.onInboundSeen = (msg) {
      if (!mounted) return;
      _handleInboundMessage(msg);
    };
    _initMonitor();
    _config = widget.config;
    // Base layer follows the server's offline-map tile source, so it matches the
    // offline download URL (shared FMTC cache) and an event can retarget both by
    // setting offline_map.url. Falls back to the compiled default (the proxy).
    if (_config.offlineTileUrl.isNotEmpty) _tileUrl = _config.offlineTileUrl;
    _beaconIntervalsSec = List.of(_config.beaconIntervalsSec);
    _beaconDistancesMi  = List.of(_config.beaconDistancesMi);
    _initCourseVisibility();
    _initSectionVisibility();
    _loadSectionVisPrefs();
    _loadLabelPrefs();
    _checkExistingPermission();
    _poller = OnlinePoller(
      onData: (data) {
        if (!mounted) return;
        final prev = _trackers;
        // Matched on CALLSIGN, which is unique. `id` is the display_id and is
        // deliberately shared — that is how several devices are grouped into one
        // entity — so matching on it compared each tracker against whichever other
        // tracker at that station happened to come first in the list. Their
        // lastUpdate values always differ, so every tracker sharing a display_id
        // reported itself as updated on every poll and blinked continuously.
        final updated = data.trackers
            .where((t) {
              final old = prev.where((o) => o.callsign == t.callsign).firstOrNull;
              return old != null && old.lastUpdate != t.lastUpdate;
            })
            .map((t) => t.callsign)
            .toSet();
        TrackerData? refetchTracker;
        // Hiding a tracker that happens to be selected takes its marker away, so the
        // selection and the breadcrumb trail hanging off it have to go too -- otherwise
        // a trail is left drawn to a tracker with nothing at the end of it. The web map
        // drops the marker, the popup and the selection together for the same reason.
        var deselectHidden = false;
        if (_selectedId != null) {
          final sel = data.trackers.where((t) => t.id == _selectedId).firstOrNull;
          if (sel != null && sel.hidden) {
            deselectHidden = true;
          } else if (sel != null && updated.contains(sel.callsign)) {
            refetchTracker = sel;
          }
        }
        final newIntervals  = data.beaconIntervalsSec;
        final newDistances  = data.beaconDistancesMi;
        setState(() {
          _trackers = data.trackers;
          if (deselectHidden) {
            _selectedId = null;
            _trailEntries = [];
            _cellTrailPts = [];
            _radioTrailPts = [];
          }
          _blinkDurationSec = data.blinkDuration;
          _breadcrumbCount  = data.breadcrumbCount;
          if (newIntervals != null) _beaconIntervalsSec = newIntervals;
          if (newDistances != null) _beaconDistancesMi  = newDistances;
          // Server no longer supports this app's contract → prompt an update.
          // Silent in the normal case (server newer but still supports this client).
          _updateRequired = data.apiMinClient > MapConfig.clientApiVersion;
        });
        // If a resumed session doesn't know its mode yet, infer it from the
        // server tracker's sharing_mode field (e.g. 'stationary').
        if (_isSharing && _sharingActivityMode < 0) {
          final myCs = _bgLocation.callsign;
          if (myCs != null) {
            const modeMap = {'walk_run': 0, 'cycle': 1, 'drive': 2, 'stationary': 3, 'drive_cycle': 2};
            final found = data.trackers.where((t) => t.callsign == myCs).toList();
            if (found.isNotEmpty) {
              final inferred = modeMap[found.first.sharingMode] ?? -1;
              if (inferred >= 0) {
                _sharingActivityMode = inferred;
                _bgLocation.saveActivityMode(inferred);
                _bgLocation.updateInterval(Duration(seconds: _beaconIntervalsSec[inferred]));
                _bgLocation.updateDistanceThreshold(_beaconDistancesMi[inferred]);
              }
            }
          }
        }
        // Push updated settings to a running share session (not unknown mode — interval stays at walk_run default until Smart Track fires).
        if (_isSharing && _sharingActivityMode >= 0 && _sharingActivityMode < 4) {
          if (newIntervals != null)
            _bgLocation.updateInterval(Duration(seconds: newIntervals[_sharingActivityMode]));
          if (newDistances != null)
            _bgLocation.updateDistanceThreshold(newDistances[_sharingActivityMode]);
        }
        if (updated.isNotEmpty) _triggerBlink({..._blinkingIds, ...updated});
        if (refetchTracker != null) _fetchTrail(refetchTracker);
      },
      onStateChange: (state) {
        if (!mounted) return;
        setState(() => _isOnline = state == PollerState.online);
        if (state == PollerState.online && _bgLocation.isSharing) {
          _bgLocation.triggerUpload();
        }
      },
    );
    _poller.start();
    _loadSavedMap();
    WidgetsBinding.instance.addPostFrameCallback((_) => _showHelpIfFirstLaunch());
    _checkForSoftUpdate();
    _bgLocation.onBeaconSent = () {
      if (!mounted) return;
      setState(() => _shareBadgeOn = false);
      Future.delayed(const Duration(milliseconds: 300), () {
        if (mounted) setState(() => _shareBadgeOn = true);
      });
    };

    _bgLocation.onSessionEnded = () {
      // Immediately, not debounced: the watch is holding a copy of a token that
      // has just died and must drop it rather than keep polling with it.
      WatchBridge.instance.pushContextNow();
      if (!mounted) return;
      setState(() { _isSharing = false; _sharingActivityMode = -1; });
      _resetAutoModeDetection();
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Session Ended'),
          content: const Text('Your location sharing session has ended. Tap Share Location to rejoin.'),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
    };

    _bgLocation.onMessageReceived = (msg) {
      // The watch is a separate output surface, so it is fed unconditionally --
      // deliberately not inside _handleInboundMessage, which suppresses itself
      // while the chat screen is open. Dedupe already happened upstream in
      // BackgroundLocationService, and the watch dedupes again by message id.
      WatchBridge.instance.pushInbound(msg);
      if (!mounted) return;
      _handleInboundMessage(msg);
    };
    _bgLocation.onModeChanged = (mode) {
      if (!mounted) return;
      const modeMap = {'walk_run': 0, 'cycle': 1, 'drive': 2, 'stationary': 3};
      const modeKeys = ['walk_run', 'cycle', 'drive', 'stationary'];
      final idx = modeMap[mode];
      if (idx == null || idx == _sharingActivityMode) return;
      final intervals = _beaconIntervalsSec.map((s) => Duration(seconds: s)).toList();
      _bgLocation.changeActivityMode(idx, intervals[idx], _beaconDistancesMi[idx], modeKeys[idx]);
      setState(() => _sharingActivityMode = idx);
    };
  }

  // Opens the redesigned chat screen (conversation list → thread → composer).
  void _openMessaging() {
    if (!_isSharing) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Start sharing your location to send messages'),
        duration: Duration(seconds: 3),
      ));
      return;
    }
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => MessagingScreen(client: _msgClient)));
  }

  // ── Monitored traffic ─────────────────────────────────────────────────────

  Timer? _monitorTimer;
  StreamSubscription<List<MsgMessage>>? _monitorSub;
  StreamSubscription<int>? _monitorSkipSub;

  Future<void> _initMonitor() async {
    await MonitorService.instance.load();
    _monitorSub = MonitorService.instance.messages.listen(_handleMonitoredBatch);
    _monitorSkipSub = MonitorService.instance.skipped.listen((n) {
      if (!mounted) return;
      // Said in the UI, not spoken: it is context for what is missing, and reading
      // it aloud would itself be an interruption on a busy net.
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('$n monitored message${n == 1 ? '' : 's'} skipped while offline'),
        duration: const Duration(seconds: 4),
      ));
    });
    // One timer. Ten seconds is right for text nobody is waiting on, but it is half
    // the delay when the point is listening: the server now has the recording within a
    // second or two of the over ending, and up to ten more spent waiting for the next
    // poll is the largest remaining piece of the lag. Four seconds when audio is on,
    // ten when it is not — a phone following text has no reason to wake as often.
    _monitorTimer = Timer.periodic(const Duration(seconds: 2), (t) {
      if (!_isSharing) return;
      final mon = MonitorService.instance;
      final everyN = mon.playingRadioAudio ? 2 : 5;      // 4s vs 10s
      if (t.tick % everyN != 0) return;
      unawaited(mon.poll(_msgClient));
    });
  }

  Future<void> _handleMonitoredBatch(List<MsgMessage> batch) async {
    if (!mounted || batch.isEmpty) return;
    // NO NOTIFICATION, deliberately, and this is the constraint the whole feature
    // lives inside. None of this was addressed to this operator; on a busy net it is
    // a message every few seconds, and a phone that buzzed for each would be unusable
    // within a minute.
    final mon = MonitorService.instance;

    // Radio entries are handled as SOUND, never as speech. The entry's text came from
    // a speech model and is carrier for the clip URL; reading it aloud was tried and
    // is strictly worse than the recording — slower than the traffic it describes, and
    // it states a mangled callsign in the same confident voice as a correct one.
    //
    // `played` records what actually went to the audio queue, because that — not
    // "is it radio" — is the thing the speech rule below has to agree with. Nothing
    // may be delivered twice, once as the real voice and again as a synthesised one
    // saying approximately the same words a beat later.
    final played = <int>{};
    if (mon.playingRadioAudio) {
      for (final m in batch.where((m) => m.isRadio)) {
        final url = MessagingClient.audioUrl(m);
        if (url == null) continue;
        // msgId so the queue can attribute a failed or playing clip to its row -- see
        // clipFailed()/isClipActive(), which the bubble's Play control reads. Omitting
        // it left every monitor-played clip anonymous to that bookkeeping.
        AudioQueue.instance.addClip(
            ts: m.ts, url: url, seconds: m.audioSecs ?? 0, msgId: m.id);
        played.add(m.id);
      }
    }

    // Typed traffic is the only thing spoken, and only if asked. Short, infrequent,
    // and written by a person — the case where a synthesised voice actually helps.
    //
    // Excluded: anything queued as audio above, and any radio entry at all. The second
    // clause covers the case where audio was wanted but unavailable — an entry whose
    // clip never arrived, or a channel with send_audio off. Falling back to reading it
    // aloud there would quietly reintroduce exactly the behaviour that made a busy net
    // unlistenable, and it would do so only sometimes, which is worse than never.
    // "Everyone's traffic" is the only question here. Whether to speak at all is the
    // Speak switch, and it is read straight from prefs further down as the global mute
    // -- one source of truth, checked once.
    //
    // There was a second flag, MonitorService.speakingAll, a mirror of the Speak switch
    // kept in its own pref. It defaulted to FALSE where the switch it mirrored defaults
    // to true, and it was only brought into line when the Messages screen was opened.
    // So a fresh install with both switches on stayed silent on everyone else's traffic
    // until somebody happened to open Messages -- speech that the settings sheet said
    // was on, and was not.
    if (!mon.monitoringAll) return;

    // Never this operator's own words.
    //
    // The monitor feed is the one inbound path not built from delivery rows — the
    // server deliberately writes none for it (MessagingDb::monitor) — so it is the one
    // feed that hands this phone back the messages this operator just sent. Every other
    // path gets the exclusion for free, because a sender never gets a delivery row for
    // their own message.
    //
    // Invisible until the watch existed: you sent from this phone, so you were holding
    // the thing that spoke. Dictate into the wrist with the phone in a pocket and it
    // reads your own message back at you a few seconds later.
    //
    // Only the speech is suppressed. The message stays in `mon.recent`, which is the
    // event's traffic log — your own transmissions belong in that.
    final me = await _msgClient.myParticipantId();
    if (!mounted) return;

    // Messages addressed to this operator are NO LONGER excluded here, and that
    // reversal is the point.
    //
    // They used to be, on the grounds that `_handleInboundMessage` owns them — it
    // decides between the wrist and this phone, raises the notification, marks the
    // message read once spoken. But that handler does not always speak: backgrounded
    // on Android it only sounded the notification, it returns early while the chat
    // screen is open, and it defers to a wrist that may be installed but inaudible.
    // Every one of those left the addressee hearing nothing while everyone monitoring
    // the net heard their message read out. That is the failure watchWillAnnounce's
    // comment calls the worst in the system, reached by a different route.
    //
    // Offering them here is safe because AudioQueue dedupes by msgId and was built for
    // exactly this: a queued duplicate takes on the `chime` of the copy that lost the
    // race and chains its `onSpoken`, and a message already spoken still runs the new
    // copy's `onSpoken` so it is marked read either way. Whichever path arrives first
    // speaks; the other folds into it.
    //
    // Two exclusions remain. `addressedHere` is the phone's own "already alerted"
    // marker — the fallback for a server that does not tag the feed. And a wrist that
    // really will announce still wins, which is the check this path never used to make
    // and the reason it had to be kept out altogether.
    final spoken = batch
        .where((m) =>
            !m.isRadio &&
            !played.contains(m.id) &&
            m.fromId != me &&
            !WatchBridge.instance.addressedHere(m.id) &&
            !(m.addressedToMe && WatchBridge.instance.watchWillAnnounce))
        .toList();
    if (spoken.isEmpty) return;
    final p = await SharedPreferences.getInstance();
    if (!(p.getBool(MobileSession.kPrefSpeakMessages) ?? true)) return;  // the global mute
    if (!mounted) return;

    // No batch summarising any more, and none needed. The queue drops anything over
    // five minutes old measured by when it was SENT, so a reconnect backlog never
    // forms — whatever survives that rule is recent enough to be worth hearing in
    // full, however many of it there is. Counting the batch was a proxy for age and a
    // poor one: three stale messages were read out and four fresh ones were not.
    // Everything reaching here is monitored traffic between two OTHER stations: the
    // filters above already dropped this operator's own messages and anything addressed
    // here. So who it was for is worth saying — "From Hiker One Germain" alone sounds
    // addressed to the listener, and on a busy net that is how an operator learns to
    // stop trusting the announcements.
    //
    // Not for broadcasts. Their to_label is "All Trackers", and announcing the
    // recipient of a message sent to everybody is noise on every single one of them.
    for (final m in spoken) {
      // Addressed to this operator: carry the tone and the mark-read callback, so that
      // when this path is the one that gets there first it is a full substitute for
      // the addressed path rather than a quieter version of it.
      final mine = m.addressedToMe;
      // No "to <you>" on your own mail — you are the recipient, saying so is noise.
      // None on a broadcast either; its to_label is "All Trackers".
      final to = (mine || m.broadcast) ? null : (m.toLabel ?? '').trim();
      AudioQueue.instance.addSpeech(
          ts: m.ts, senderLabel: m.spokenLabel, text: m.text, msgId: m.id,
          toLabel: (to == null || to.isEmpty) ? null : to,
          chime: mine,
          onSpoken: mine ? () => unawaited(_msgClient.read([m.id])) : null);
    }
  }



  Future<void> _handleInboundMessage(InboundMessage msg) async {
    // The chat screen is open and shows arriving messages live via its own poll,
    // so don't also raise a notification/banner. (background_location still acks
    // it so it isn't re-delivered.)
    //
    // Speech is NOT part of what that screen covers, and this used to return before
    // reaching it. MessagingScreen.isOpen is one flag for the whole screen, but that
    // screen only speaks a message whose conversation is the one currently displayed —
    // anything arriving on another thread, or while the inbox list is showing, was put
    // in _deferredSpeak and read out only if somebody later opened that thread. So an
    // operator reading one conversation heard a tone for a call addressed to them and
    // never heard the words. Worse, the flag stays true when the app is backgrounded
    // from that screen, which is the ordinary way a phone ends up in a pocket: open
    // Messages, lock the phone. That was silence too.
    //
    // A message addressed to this operator is spoken whenever the speaker is on, full
    // stop -- no matter which screen is showing or whether the app is visible. Safe to
    // do here as well as there because AudioQueue dedupes on msgId: whichever path
    // reaches it first speaks, and the other folds into that one.
    if (MessagingScreen.isOpen) {
      if (!WatchBridge.instance.watchWillAnnounce) {
        final p = await SharedPreferences.getInstance();
        if (p.getBool(MobileSession.kPrefSpeakMessages) ?? true) {
          AudioQueue.instance.addSpeech(
            ts: msg.ts,
            senderLabel: msg.spokenLabel,
            text: msg.text,
            msgId: msg.id,
            chime: true,
            onSpoken: () => unawaited(_msgClient.read([msg.id])),
          );
        }
      }
      return;
    }
    if (_appLifecycleState != AppLifecycleState.resumed) {
      // Backgrounded: raise a native notification the user can tap to return to
      // the app and open Messages.
      if (Platform.isAndroid) {
        if (await FlutterForegroundTask.canDrawOverlays) {
          FlutterForegroundTask.launchApp();
        }
        // Read it out, exactly as the iOS branch below does. This branch used to raise
        // a notification and nothing else, so a backgrounded Android phone sounded a
        // tone for a message addressed to it and never said what the message was —
        // while every phone monitoring the net read it aloud in full. The foreground
        // service is what makes speech off-screen possible here, and it is the same
        // reason iOS needs its `audio` background mode.
        //
        // The wrist still wins if it will genuinely announce, matching the foreground
        // path below. The notification itself is raised either way: it is for somebody
        // looking at the phone.
        if (!WatchBridge.instance.watchWillAnnounce) {
          AudioQueue.instance.addSpeech(
            ts: msg.ts,
            senderLabel: msg.spokenLabel,
            text: msg.text,
            msgId: msg.id,
            chime: true,
            onSpoken: () => unawaited(_msgClient.read([msg.id])),
          );
        }
        unawaited(_notifPlugin.show(
          id: msg.id & 0x7FFFFFFF,
          title: '📨 ${msg.fromLabel}',
          body: msg.text,
          notificationDetails: NotificationDetails(
            android: AndroidNotificationDetails(
              'aprs_msg_3',
              'APRS Messages',
              importance: Importance.max,
              priority: Priority.max,
              fullScreenIntent: true,
              // Silent, for the same reason iOS sets presentSound: false. The tone is
              // now the first half of the queued speech item above, which guarantees it
              // lands before the words instead of racing them — and two sounds for one
              // message was the bug that ordering was introduced to fix.
              playSound: false,
              enableVibration: true,
              vibrationPattern: Int64List.fromList([0, 400, 200, 400, 200, 400]),
              autoCancel: true,
            ),
          ),
        ));
      } else if (Platform.isIOS) {
        // The phone alerts for everything unless the watch app is actually on screen,
        // in which case the wrist is already speaking the message to a user who is
        // looking at it. A backgrounded watch is not an alerting device — watchOS
        // will not let it make a sound — so treating "a watch exists" as "the wrist
        // will handle it" left both devices silent.
        if (WatchBridge.instance.watchWillAnnounce) return;
        // Read it out as well as raising the notification. The notification sound
        // says a message arrived; this says what it was, which is the difference
        // between a driver having to stop and a driver carrying on. Needs the `audio`
        // background mode, without which iOS refuses the session off-screen.
        //
        // Tone and speech both go through the queue, in that order, as one item. They
        // used to be a race: the tone was the notification's own sound and speech was
        // delayed 900 ms to clear it. Nothing here schedules that sound or can observe
        // it, so the delay was a guess, and it lost — the tone landed on top of speech
        // that had already started. The queue makes the order a fact.
        //
        // Being in the queue also means a message addressed to this operator cannot
        // start on top of a radio clip already playing, and that it inherits the
        // five-minute rule which stops an hour offline becoming an hour of
        // announcements on reconnect.
        AudioQueue.instance.addSpeech(
          ts: msg.ts,
          senderLabel: msg.spokenLabel,
          text: msg.text,
          msgId: msg.id,
          chime: true,
          // Spoken aloud IS read. Leaving it unread meant a message the operator had
          // already heard in full still sat in Messages behind a red badge, and opening
          // it to clear that badge was the act that read it out a second time. Marking
          // it also tells the sender it landed, which is true — somebody heard it.
          //
          // Fired when the words have actually been said, not when the message arrived.
          // Marking on arrival claimed the operator had heard something the queue could
          // still drop as stale or fail to play, and it ran before the audio session
          // was known to be working — which is exactly when it was wrong.
          onSpoken: () => unawaited(_msgClient.read([msg.id])),
        );
        unawaited(_notifPlugin.show(
          id: msg.id & 0x7FFFFFFF,
          title: '📨 ${msg.fromLabel}',
          body: msg.text,
          notificationDetails: const NotificationDetails(
            iOS: DarwinNotificationDetails(
              presentAlert: true,
              // Silent on purpose. The tone is the first half of the queued item above,
              // so it is guaranteed to come before the words rather than whenever iOS
              // gets to it. Two sounds for one message was the bug; this is the half
              // that had no ordering guarantee, so this is the half that goes.
              presentSound: false,
              presentBadge: true,
              interruptionLevel: InterruptionLevel.timeSensitive,
            ),
          ),
        ));
      }
      return;
    }
    // Foreground: tone, then read it aloud, and offer a tap-to-open banner. The full
    // conversation lives in the chat screen, reachable from the Messages button.
    try {
      await _audioPlayer.setAudioSource(AudioSource.asset('assets/sounds/message.wav'));
      unawaited(_audioPlayer.play());
    } catch (_) {}
    // The wrist takes precedence here exactly as it does when this app is backgrounded.
    // That check used to live only in the backgrounded branch, so a phone that was
    // awake never asked the watch at all — and with the watch app open in front of the
    // operator, both devices read the same message aloud at once.
    //
    // The tone above and the banner below are deliberately NOT suppressed: they are for
    // somebody looking at the phone, and a tone alongside the wrist speaking is
    // information rather than duplication. Only the second voice is the problem.
    if (!WatchBridge.instance.watchWillAnnounce) {
      AudioQueue.instance.addSpeech(
          ts: msg.ts, senderLabel: msg.spokenLabel, text: msg.text, msgId: msg.id);
    }
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.clearSnackBars();
    messenger.showSnackBar(SnackBar(
      content: Text(
        '📨 ${msg.fromLabel}: ${msg.text}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      duration: const Duration(seconds: 6),
      action: SnackBarAction(label: 'Open', onPressed: _openMessaging),
    ));
  }

  void _initCourseVisibility() {
    _courseVisible = {
      for (final c in _config.courses) c.file: c.visible,
    };
  }

  // Seed section on/off state from the server's "Default Section Visibility"
  // config (admin page). Only overrides known sections that the config
  // specifies; absent keys keep their default (visible).
  void _initSectionVisibility() {
    // Admin's Default Section Visibility is only a starting default; the user's own
    // saved toggles (_savedSectionVis) override it once they've changed anything.
    final src = _savedSectionVis ?? _config.sectionVisibility;
    src.forEach((key, visible) {
      if (_sectionVisible.containsKey(key)) _sectionVisible[key] = visible;
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poller.stop();
    _positionSub?.cancel();
    _stationaryCheckTimer?.cancel();
    _blinkTimer?.cancel();
    _monitorTimer?.cancel();
    _monitorSub?.cancel();
    _monitorSkipSub?.cancel();
    _audioPlayer.dispose();
    _bgLocation.dispose();
    _mapController.dispose();
    super.dispose();
  }

  // ── Location permission ───────────────────────────────────────────────────

  // Called at startup — only checks existing grant, never shows the system prompt.
  Future<void> _checkExistingPermission() async {
    try {
      final permission = await Geolocator.checkPermission();
      if (!mounted) return;
      if (permission == LocationPermission.always) {
        setState(() => _locationState = _LocationState.always);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
        unawaited(_maybeResumeSharing());
      } else if (permission == LocationPermission.whileInUse) {
        setState(() => _locationState = _LocationState.whileInUse);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
        // Resumes on iOS too. This was Android-only, on the reasoning that a foreground
        // service works with whileInUse and iOS wants Always for background location.
        // True as far as it goes, and it left the app inconsistent with itself: nothing
        // gates STARTING a session on the permission, so an iOS user on "While Using the
        // App" could share all day and then be asked for the event password again the
        // next time the app was launched, with a live session sitting unused in
        // preferences. Starting and resuming now agree.
        unawaited(_maybeResumeSharing());
      } else if (permission == LocationPermission.deniedForever) {
        setState(() => _locationState = _LocationState.permanentlyDenied);
      } else {
        setState(() => _locationState = _LocationState.notRequested);
      }
    } catch (_) {
      if (mounted) setState(() => _locationState = _LocationState.notRequested);
    }
  }

  // Shows Apple's recommended pre-alert screen before the system permission dialog.
  Future<bool> _showLocationPreAlert({
    required String title,
    required String body,
    required IconData icon,
  }) async {
    if (!mounted) return false;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: BoxDecoration(
          color: Theme.of(ctx).colorScheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        ),
        padding: EdgeInsets.fromLTRB(24, 12, 24,
            24 + MediaQuery.of(ctx).viewPadding.bottom),
        // Scrollable, because the sheet is sized to its content and on a small screen
        // the content is taller than the screen. A Column that overruns a bottom sheet
        // is clipped at the bottom, and the bottom is where Continue is — so a 240x320
        // handset showed a request for consent with no way to give it: text cut off
        // mid-sentence, button gone, and nothing on screen to say there was more below.
        //
        // The sheet still shrinks to fit its content wherever there is room for it.
        // Only past that does it fill the screen and scroll.
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40, height: 4,
                margin: const EdgeInsets.only(bottom: 24),
                decoration: BoxDecoration(
                  color: Colors.grey[300],
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              Icon(icon, size: 52, color: Colors.blue),
              const SizedBox(height: 16),
              Text(title,
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Text(body,
                style: TextStyle(fontSize: 15, color: Colors.grey[600]),
              ),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Continue'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    return confirmed == true;
  }

  // Shows the consent screen disclosing that location will be sent to the server.
  // Not a system dialog — our own UI. Shown once per app session.
  // backgroundLimited: true when permission is only "While Using" so we warn
  // that sharing pauses when the screen locks.
  /// What the consent sheet says about background sharing, which is not the same
  /// sentence on the two platforms because the two platforms do not behave the same.
  ///
  /// This used to give every operator iOS's Settings path. On Android that named menus
  /// that do not exist, and told them sharing would pause when it does not: Android runs
  /// a foreground service, so location keeps going with the screen locked for as long as
  /// the notification is there. The advice that matters on Android is to leave that
  /// notification alone; the advice that matters on iOS is to grant Always.
  String _sharingConsentBody(bool backgroundLimited) {
    const shared =
        'Others will be able to see your location on the map. Your name and callsign '
        'will also be visible if entered.\n\n'
        'You will be assigned an ad-hoc callsign such as MARSQ-123, which can also be '
        'used with APRS-aware apps such as aprs.fi and CalTopo.com.\n\n';
    if (Platform.isAndroid) {
      return '$shared'
          'Sharing continues when the screen locks or you switch apps. A notification '
          'stays in the status bar while it does — leaving it there is what keeps '
          'sharing running.';
    }
    if (backgroundLimited) {
      return '$shared'
          'Sharing will pause when the screen locks or you switch apps. To share in the '
          'background, go to Settings → Privacy & Security → Location Services → '
          'APRS Map and choose “Always”.';
    }
    return '$shared'
        'Because you have allowed Always access, sharing continues when the screen is '
        'locked or you switch apps.';
  }

  Future<bool> _showSharingConsentScreen({bool backgroundLimited = false}) async {
    if (!mounted) return false;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: BoxDecoration(
          color: Theme.of(ctx).colorScheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        ),
        padding: EdgeInsets.fromLTRB(24, 12, 24,
            24 + MediaQuery.of(ctx).viewPadding.bottom),
        // Sized to content and scrolled once that outgrows the screen, for the reason
        // set out on the pre-alert sheet above: the buttons are at the bottom, and the
        // bottom is what a bottom sheet clips.
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40, height: 4,
                margin: const EdgeInsets.only(bottom: 24),
                decoration: BoxDecoration(
                  color: Colors.grey[300],
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const Icon(Icons.share_location, size: 52, color: Colors.blue),
              const SizedBox(height: 16),
              const Text('Share Your Location',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Text(
                _sharingConsentBody(backgroundLimited),
                style: TextStyle(fontSize: 15, color: Colors.grey[600]),
              ),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Continue'),
                ),
              ),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Cancel'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    return confirmed == true;
  }

  // Requests the initial location permission (for blue dot or share).
  // Sets _locationState to whileInUse or always, and starts GPS on success.
  Future<void> _requestLocationPermission() async {
    try {
      final permission = await Geolocator.requestPermission();
      if (!mounted) return;
      if (permission == LocationPermission.always) {
        setState(() => _locationState = _LocationState.always);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
      } else if (permission == LocationPermission.whileInUse) {
        setState(() => _locationState = _LocationState.whileInUse);
        unawaited(_bgLocation.startTracking());
        _startPositionStream();
      } else if (permission == LocationPermission.deniedForever) {
        setState(() => _locationState = _LocationState.permanentlyDenied);
      } else {
        setState(() => _locationState = _LocationState.denied);
      }
    } catch (_) {
      if (mounted) setState(() => _locationState = _LocationState.denied);
    }
  }

  // Requests upgrade from "While Using" to "Always" (iOS background sharing).
  Future<void> _requestAlwaysPermission() async {
    try {
      final permission = await Geolocator.requestPermission();
      if (!mounted) return;
      if (permission == LocationPermission.always) {
        setState(() => _locationState = _LocationState.always);
      }
    } catch (_) {}
  }

  // Full permission + consent gate for the Share Location flow.
  // Returns true only when all required permissions are granted and user consented.
  Future<bool> _ensureSharePermissions() async {
    // Re-sync with the real iOS permission state. "Allow Once" expires when the
    // app is backgrounded, reverting to notDetermined — our cached _locationState
    // can be stale. Checking here lets Step 1 re-run the dialog in that case.
    if (Platform.isIOS) {
      try {
        final current = await Geolocator.checkPermission();
        if (!mounted) return false;
        if (current == LocationPermission.always) {
          setState(() => _locationState = _LocationState.always);
        } else if (current == LocationPermission.whileInUse) {
          setState(() => _locationState = _LocationState.whileInUse);
        } else if (current == LocationPermission.deniedForever) {
          setState(() => _locationState = _LocationState.permanentlyDenied);
        } else {
          setState(() => _locationState = _LocationState.notRequested);
        }
      } catch (_) {}
    }

    // Step 1: need at least "While Using"
    // Track whether permission was just granted here so we can skip Step 3:
    // if Share Location is the user's first action, the pre-alert + Apple dialog
    // already established the sharing context. Step 3 is only needed when the
    // user previously granted location for the blue dot ("stays on device") and
    // now we need to disclose that sharing sends it to the server.
    // Can't share if location access is permanently disabled.
    if (_locationState == _LocationState.permanentlyDenied) {
      if (!mounted) return false;
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Location Access Disabled'),
          content: const Text(
            'Enable Location Services for APRS Map in Settings to share your location.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            TextButton(
              onPressed: () { Navigator.pop(ctx); openAppSettings(); },
              child: const Text('Open Settings'),
            ),
          ],
        ),
      );
      return false;
    }

    var justGrantedPermission = false;
    if (_locationState == _LocationState.notRequested) {
      final ok = await _showLocationPreAlert(
        title: 'Share Your Location',
        icon: Icons.share_location,
        body: 'Sharing your location lets others see where you are — including '
              'users of this app, our website, and third-party apps like '
              'aprs.fi and CalTopo.com.\n\n'
              'Your name and ham-radio callsign will also be visible, if entered.\n\n'
              'For background tracking, choose "Always" when prompted, or '
              'change it later in Settings → Privacy & Security → '
              'Location Services → APRS Map.',
      );
      if (!ok || !mounted) return false;
      await _requestLocationPermission();
      if (_locationState == _LocationState.notRequested ||
          _locationState == _LocationState.denied ||
          _locationState == _LocationState.permanentlyDenied) return false;
      justGrantedPermission = true;
    }

    // Step 2: iOS needs "Always" for background tracking
    if (Platform.isIOS && _locationState == _LocationState.whileInUse) {
      await _requestAlwaysPermission();
      // Proceed even if user declined the upgrade — it's their choice.
    }

    // Step 3: consent screen — only when permission was already granted (blue dot
    // used first), so the user needs to know sharing sends their location to the server.
    // Warn about background limitation if we only have "While Using" permission.
    if (!_sharingConsentShown && !justGrantedPermission) {
      if (!mounted) return false;
      final backgroundLimited = _locationState == _LocationState.whileInUse;
      final consented = await _showSharingConsentScreen(backgroundLimited: backgroundLimited);
      if (!consented) return false;
      _sharingConsentShown = true;
    }

    return true;
  }

  void _startPositionStream() {
    // Use bgLocation.positionStream (a Dart broadcast stream) rather than a
    // second Geolocator.getPositionStream() call. geolocator_apple only
    // supports ONE active event-channel listener; a second call silently fails
    // and leaves allowsBackgroundLocationUpdates = false (no blue arrow, no
    // background location). startTracking() owns the single GPS stream and
    // feeds all position events through positionStream.
    _positionSub?.cancel();
    _stationaryCheckTimer?.cancel();
    _stationaryCheckTimer = Timer.periodic(const Duration(seconds: 30), (_) => _checkStationaryByTime());
    final posStream = _bgLocation.positionStream;
    _positionSub = posStream.listen((pos) {
      _lastUserLatLng = LatLng(pos.latitude, pos.longitude);
      _processSpeedSample(pos.speed, pos.accuracy);
    });
    _locationMarkerStream = const LocationMarkerDataStreamFactory()
        .fromGeolocatorPositionStream(stream: posStream);
  }

  // ── Background permission setup ───────────────────────────────────────────

  /// Ensures the OS won't kill location tracking when the screen locks.
  /// Called once when the user first starts sharing.
  Future<void> _ensureBackgroundPermissions() async {
    if (Platform.isAndroid) {
      // Android 13+: must grant notification permission for the foreground
      // service notification to appear; without it Android kills the service.
      if (await Permission.notification.isDenied) {
        await Permission.notification.request();
      }
      // Ask the user to exempt this app from battery optimization so the
      // foreground service isn't throttled or killed while screen is off.
      if (await Permission.ignoreBatteryOptimizations.isDenied) {
        await Permission.ignoreBatteryOptimizations.request();
      }
    }
  }

  // ── Location sharing ──────────────────────────────────────────────────────

  /// Auto-resumes sharing on app startup if the user was sharing when the app
  /// was last closed. Silently reuses the saved token (or re-joins if expired).
  Future<void> _maybeResumeSharing() async {
    if (!mounted) return;
    await _ensureBackgroundPermissions();
    final resumed = await _bgLocation.resumeSharing();
    if (!mounted) return;
    if (resumed) {
      // Two permission paths can both reach here on one launch, and resumeSharing() is
      // now idempotent — so this can be a genuine resume or just the UI catching up with
      // a service that never stopped. Only the first is worth telling anybody about.
      final wasAlreadySharing = _isSharing;
      // The token only exists once a session is live, and the watch cannot do
      // anything without it -- so a session starting is the one event it most
      // needs, pushed immediately rather than waiting for the next natural one.
      WatchBridge.instance.pushContextNow();
      setState(() { _isSharing = true; _sharingActivityMode = _bgLocation.activityMode; });
      _resetAutoModeDetection();
      if (!wasAlreadySharing) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Location sharing resumed'),
          duration: Duration(seconds: 3),
        ));
      }
    }
  }


  int _classifySpeedMs(double s) {
    if (s <= _kAutoSpeedStationary) return 3;
    if (s <= _kAutoSpeedWalkRun)   return 0;
    if (s <= _kAutoSpeedCycle)     return 1;
    return 2;
  }

  void _resetAutoModeDetection({bool fullReset = false}) {
    _candidateAutoMode = null;
    _candidateSampleCount = 0;
    _candidateFirstSeen = null;
    _lastMovementAt = null;
    _movementAboveCount = 0;
    if (fullReset) {
      _totalSampleCount = 0;
      _lastMovementAt = DateTime.now(); // arm the time-based check from session start
    }
  }

  void _checkStationaryByTime() {
    if (!_isSharing || _sharingActivityMode == 3 || _lastMovementAt == null) return;
    final elapsed = DateTime.now().difference(_lastMovementAt!).inSeconds;
    final inStartup = _totalSampleCount <= _kAutoStartupTotal;
    if (elapsed >= (inStartup ? 90 : _kAutoStationaryMinSecs)) {
      _changeActivityMode(3, silent: true);
      _resetAutoModeDetection();
    }
  }

  void _processSpeedSample(double speedMs, double accuracy) {
    if (!_isSharing || _sharingActivityMode < 0 || speedMs < 0) return;
    if (speedMs > _kAutoSpeedStationary && accuracy <= 20) {
      _movementAboveCount++;
      if (_movementAboveCount >= _kMovementConfirmSamples) _lastMovementAt = DateTime.now();
    } else {
      _movementAboveCount = 0;
    }
    _totalSampleCount++;
    if (accuracy > 20) return;
    final inStartup = _totalSampleCount <= _kAutoStartupTotal;
    final newMode = _classifySpeedMs(speedMs);
    if (newMode == _candidateAutoMode) {
      _candidateSampleCount++;
    } else {
      _candidateAutoMode = newMode;
      _candidateSampleCount = 1;
      _candidateFirstSeen = DateTime.now();
    }
    if (newMode == _sharingActivityMode) return;
    final isStationary = newMode == 3;
    final windowNeeded = inStartup ? _kAutoStartupWindow :
        (isStationary ? _kAutoStationaryWindow : _kAutoGeneralWindow);
    if (_candidateSampleCount < windowNeeded) return;
    if (!inStartup && isStationary) {
      if (DateTime.now().difference(_candidateFirstSeen!).inSeconds < _kAutoStationaryMinSecs) return;
    }
    _changeActivityMode(newMode, silent: true);
    _resetAutoModeDetection();
  }

  Future<void> _changeActivityMode(int newMode, {bool silent = false}) async {
    if (!_isSharing || newMode == _sharingActivityMode) return;
    const modeKeys = ['walk_run', 'cycle', 'drive', 'stationary'];
    final intervals = _beaconIntervalsSec.map((s) => Duration(seconds: s)).toList();
    // When leaving 'unknown' for the first time, don't upload immediately — let the server keep
    // 'unknown' visible until the next scheduled beacon so observers can see the '?' state.
    final wasUnknown = _sharingActivityMode == 4;
    await _bgLocation.changeActivityMode(
      newMode,
      intervals[newMode],
      _beaconDistancesMi[newMode],
      modeKeys[newMode],
      uploadNow: !wasUnknown,
    );
    if (!mounted) return;
    setState(() => _sharingActivityMode = newMode);
    if (!silent) await _showSharingStartedDialog(_bgLocation.callsign ?? '');
  }

  Future<void> _toggleSharing() async {
    if (_isSharing) {
      await _bgLocation.stopSharing();
      if (mounted) setState(() => _isSharing = false);
      _resetAutoModeDetection();
      return;
    }
    if (!mounted) return;
    if (!_isOnline) {
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('No Connection'),
          content: const Text('You are offline. Connect to the internet to share your location.'),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
      return;
    }
    final ready = await _ensureSharePermissions();
    if (!ready || !mounted) return;
    await _showShareDialog();
  }

  Future<void> _startSharingWithMode(int mode) async {
    if (_isSharing) return;
    if (!mounted) return;
    if (!_isOnline) {
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('No Connection'),
          content: const Text('You are offline. Connect to the internet to share your location.'),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
      return;
    }
    final ready = await _ensureSharePermissions();
    if (!ready || !mounted) return;
    await _showShareDialog();
  }

  /// Shows the Share Location dialog. Handles join attempts inline —
  /// wrong PIN shows an error inside the dialog; success closes it and
  /// shows the callsign info modal; network failure closes it and shows
  /// a separate error dialog.
  Future<void> _showShareDialog({int initialMode = -1}) async {
    final prefs = await SharedPreferences.getInstance();
    final savedName    = prefs.getString('sharing_name') ?? '';
    final savedHamRoot = prefs.getString('sharing_ham_root') ?? '';
    final savedHamSsid = prefs.getInt('sharing_ham_ssid') ?? 0;
    final nameCtl    = TextEditingController(text: savedName);
    final pinCtl     = TextEditingController();
    final hamRootCtl = TextEditingController(text: savedHamRoot);
    final hamSsidCtl = TextEditingController(text: savedHamSsid > 0 ? savedHamSsid.toString() : '');
    final pinFocus     = FocusNode();
    final hamRootFocus = FocusNode();
    String? errorText;
    bool loading = false;
    bool hamExpanded = false;

    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          Future<void> submit() async {
            final name = nameCtl.text.trim();
            final pin = pinCtl.text.trim();
            if (name.isEmpty) {
              setDialogState(() => errorText = 'Please enter your first name.');
              return;
            }
            if (pin.isEmpty) {
              setDialogState(() => errorText = 'Please enter the event PIN.');
              return;
            }
            setDialogState(() { loading = true; errorText = null; });
            await _ensureBackgroundPermissions();
            final intervals = _beaconIntervalsSec.map((s) => Duration(seconds: s)).toList();
            final hamRoot = hamExpanded ? hamRootCtl.text.trim().toUpperCase() : '';
            final hamSsid = hamExpanded ? (int.tryParse(hamSsidCtl.text.trim()) ?? 0) : 0;
            if (hamExpanded && hamRoot.isNotEmpty) {
              // ITU/FCC callsign: 1–3 prefix chars (letters or digit), one area digit, 1–3 letter suffix
              final csRe = RegExp(r'^[A-Z0-9]{1,3}[0-9][A-Z]{1,3}$');
              if (!csRe.hasMatch(hamRoot)) {
                setDialogState(() { errorText = 'Enter a valid callsign (e.g. K6DRK or W6SG).'; loading = false; });
                return;
              }
            }
            if (hamExpanded && hamRoot.isNotEmpty && (hamSsid < 1 || hamSsid > 15)) {
              setDialogState(() { errorText = 'SSID must be between 1 and 15.'; loading = false; });
              return;
            }
            final joinResult = await _bgLocation.startSharing(
              name: name,
              pin: pin,
              interval: intervals[0],
              distanceThresholdMiles: _beaconDistancesMi[0],
              sharingMode: 'unknown',
              activityModeIndex: 4,
              hamRoot: hamRoot,
              hamSsid: hamSsid,
            );
            if (!ctx.mounted) return;
            if (joinResult == JoinResult.success) {
              WatchBridge.instance.pushContextNow(); // the watch has no token until now
              Navigator.pop(ctx);
              if (mounted) setState(() { _isSharing = true; _sharingActivityMode = 4; });
              _resetAutoModeDetection(fullReset: true);
              await _showSharingStartedDialog(_bgLocation.callsign ?? '');
            } else if (joinResult == JoinResult.wrongPin) {
              setDialogState(() {
                errorText = 'Incorrect PIN. Please try again.';
                loading = false;
              });
            } else if (joinResult == JoinResult.callsignError) {
              setDialogState(() {
                errorText = _bgLocation.session.lastJoinError ?? 'Invalid callsign.';
                loading = false;
              });
            } else {
              Navigator.pop(ctx);
              if (mounted) await showDialog<void>(
                context: context,
                builder: (ctx2) => AlertDialog(
                  title: const Text('Could Not Connect'),
                  content: const Text('Could not reach the server. Check your connection and try again.'),
                  actions: [TextButton(onPressed: () => Navigator.pop(ctx2), child: const Text('OK'))],
                ),
              );
            }
          }

          return AlertDialog(
            title: const Text('Share Location'),
            insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
            contentPadding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
            actionsAlignment: MainAxisAlignment.center,
            content: SingleChildScrollView(
              child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameCtl,
                  decoration: InputDecoration(
                    hintText: 'First Name',
                    hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                  ),
                  textCapitalization: TextCapitalization.sentences,
                  onSubmitted: (_) => pinFocus.requestFocus(),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: pinCtl,
                  focusNode: pinFocus,
                  decoration: InputDecoration(
                    hintText: 'PIN',
                    hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                  ),
                  keyboardType: TextInputType.number,
                  obscureText: true,
                  onSubmitted: (_) => submit(),
                ),
                const SizedBox(height: 12),
                Center(
                  child: OutlinedButton(
                    onPressed: () => setDialogState(() => hamExpanded = !hamExpanded),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      textStyle: const TextStyle(fontSize: 12),
                      minimumSize: Size.zero,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text('Ham Radio Callsign?'),
                  ),
                ),
                if (hamExpanded) ...[
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(
                      flex: 3,
                      child: TextField(
                        controller: hamRootCtl,
                        focusNode: hamRootFocus,
                        decoration: InputDecoration(
                          hintText: 'Callsign',
                          hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
                          isDense: true,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                        ),
                        textCapitalization: TextCapitalization.characters,
                        onChanged: (v) {
                          final up = v.toUpperCase();
                          if (up != v) hamRootCtl.value = hamRootCtl.value.copyWith(text: up, selection: TextSelection.collapsed(offset: up.length));
                        },
                        onSubmitted: (_) => FocusScope.of(ctx).requestFocus(hamRootFocus),
                      ),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(
                      width: 64,
                      child: TextField(
                        controller: hamSsidCtl,
                        decoration: InputDecoration(
                          hintText: 'SSID 1–15',
                          hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
                          isDense: true,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                        ),
                        keyboardType: TextInputType.number,
                        onSubmitted: (_) => submit(),
                      ),
                    ),
                  ]),
                ],
                const SizedBox(height: 10),
                if (errorText != null)
                  Text(errorText!, style: const TextStyle(color: Colors.red, fontSize: 13)),
              ],
            ),
            ),
            actions: [
              if (loading)
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
                )
              else ...[
                OutlinedButton(
                  onPressed: () => Navigator.pop(ctx),
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    textStyle: const TextStyle(fontSize: 12),
                    minimumSize: Size.zero,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  ),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  onPressed: submit,
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    textStyle: const TextStyle(fontSize: 12),
                    minimumSize: Size.zero,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    backgroundColor: Colors.blueGrey.shade700,
                    foregroundColor: Colors.white,
                  ),
                  child: const Text('Share Location'),
                ),
              ],
            ],
          );
        },
      ),
    );
    nameCtl.dispose(); pinCtl.dispose();
    hamRootCtl.dispose(); hamSsidCtl.dispose();
    pinFocus.dispose(); hamRootFocus.dispose();
  }

  Future<void> _showSharingStartedDialog(String cs) async {
    if (!mounted || cs.isEmpty) return;
    final aprsUrl = Uri.parse('https://aprs.fi/#!call=$cs');
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Location Sharing Started'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Your location is now shared using callsign $cs.'),
            const SizedBox(height: 12),
            const Text(
              'In addition to this map, you can track your position on aprs.fi:',
            ),
            const SizedBox(height: 4),
            GestureDetector(
              onTap: () => launchUrl(aprsUrl, mode: LaunchMode.externalApplication),
              child: Text(
                'aprs.fi/?call=$cs',
                style: const TextStyle(
                  color: Colors.blue,
                  decoration: TextDecoration.underline,
                ),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'The callsign $cs can also be entered in CalTopo.com to show your position there.',
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  // ── Map controls ──────────────────────────────────────────────────────────

  Future<void> _handleRecenter() async {
    _clearTransientMapHighlights();
    if (_locationState == _LocationState.permanentlyDenied ||
        _locationState == _LocationState.denied) {
      if (!mounted) return;
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Location Access Disabled'),
          content: const Text(
            'Enable Location Services for APRS Map in Settings to see your position on the map.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            TextButton(
              onPressed: () { Navigator.pop(ctx); openAppSettings(); },
              child: const Text('Open Settings'),
            ),
          ],
        ),
      );
      return;
    }
    if (_locationState == _LocationState.notRequested) {
      final ok = await _showLocationPreAlert(
        title: 'Show Your Location on the Map',
        icon: Icons.my_location,
        body: 'APRS Map will show your position as a blue dot on the map.\n\n'
              'This works in both online and offline modes.',
      );
      if (!ok) return;
      await _requestLocationPermission();
      // GPS stream now running — re-center once first position arrives
      return;
    }
    final userPos = _lastUserLatLng;
    if (userPos == null) return;
    _mapController.move(userPos, 14.0);
    _mapController.rotate(0);
  }

  // Clear transient map highlights (a revealed eyeball-off iGate/aid, and a
  // forced full "ID Name" tracker label) — the "return to normal view" reset.
  // ── Anchor: a place the map will not let you lose ──────────────────────────

  /// How far inside the edge the anchor is held, as a fraction of the view.
  ///
  /// Not zero. Pinning it exactly at the boundary means it sits under the frame, half
  /// off, and the pan that put it there feels like it failed rather than like it was
  /// caught. An eighth of the view in from each side leaves it visibly on screen.
  static const double _kAnchorInset = 0.12;

  void _setAnchor(LatLng at) {
    HapticFeedback.mediumImpact();
    setState(() => _anchor = at);
    // Pull it into view straight away. Long-pressing near the edge otherwise sets an
    // anchor that is already out of bounds and nothing moves until the next gesture.
    _anchorCorrect();
  }

  /// Drop the anchor and let the map go where it likes again.
  void _clearAnchor() {
    if (_anchor == null) return;
    setState(() => _anchor = null);
  }

  void _anchorOnMapEvent(MapEvent event) {
    if (_anchor == null) return;
    // A programmatic move is the app deciding where to look -- centring on a tracker,
    // going to my location, restoring the saved view. Every one of those is a request
    // to be somewhere specific, and honouring it while still dragging the camera back
    // would fight the operator. So they release the anchor, which is what "anything
    // that resets the map or zooms to a location frees it" means.
    if (event.source == MapEventSource.mapController) {
      if (!_anchorCorrecting) _clearAnchor();
      return;
    }
    if (event.source == MapEventSource.nonRotatedSizeChange) return;
    _anchorCorrect();
  }

  /// If the anchor has left the view, move the camera the shortest way that brings it
  /// back just inside.
  void _anchorCorrect() {
    final a = _anchor;
    if (a == null) return;
    final cam = _mapController.camera;
    final b = cam.visibleBounds;
    final latSpan = b.north - b.south;
    final lonSpan = b.east - b.west;
    if (latSpan <= 0 || lonSpan <= 0) return;
    final padLat = latSpan * _kAnchorInset;
    final padLon = lonSpan * _kAnchorInset;

    // Shift by the overshoot rather than recentring on the anchor: the operator was
    // panning somewhere for a reason, and this should take away only as much of that
    // pan as it has to.
    double dLat = 0, dLon = 0;
    if (a.latitude > b.north - padLat) dLat = a.latitude - (b.north - padLat);
    if (a.latitude < b.south + padLat) dLat = a.latitude - (b.south + padLat);
    if (a.longitude > b.east - padLon) dLon = a.longitude - (b.east - padLon);
    if (a.longitude < b.west + padLon) dLon = a.longitude - (b.west + padLon);
    if (dLat == 0 && dLon == 0) return;

    _anchorCorrecting = true;
    _mapController.move(
      LatLng(cam.center.latitude + dLat, cam.center.longitude + dLon), cam.zoom);
    _anchorCorrecting = false;
  }

  void _clearTransientMapHighlights() {
    if (_revealedFixed != null || _fullLabelTrackerId != null) {
      setState(() { _revealedFixed = null; _fullLabelTrackerId = null; });
    }
  }

  void _handleReset() {
    _clearTransientMapHighlights();
    // A map reset also drops the selected tracker's breadcrumb trail. Panning,
    // zooming and tapping the map do NOT — breadcrumbs stay visible through those.
    if (_selectedId != null ||
        _trailEntries.isNotEmpty ||
        _cellTrailPts.isNotEmpty ||
        _radioTrailPts.isNotEmpty) {
      setState(() {
        _selectedId = null;
        _selectionClickCount = 0;
        _trailEntries = [];
        _cellTrailPts = [];
        _radioTrailPts = [];
      });
    }
    if (_savedCenter != null) {
      _mapController.move(_savedCenter!, _savedZoom ?? _config.mapZoom);
      _mapController.rotate(_savedRotation ?? 0);
    } else {
      _mapController.move(
        widget.initialCenter ?? LatLng(_config.mapLat, _config.mapLon),
        _config.mapZoom,
      );
      _mapController.rotate(0);
    }
  }

  Future<void> _handleSaveMap() async {
    final camera = _mapController.camera;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble('map_saved_lat', camera.center.latitude);
    await prefs.setDouble('map_saved_lon', camera.center.longitude);
    await prefs.setDouble('map_saved_zoom', camera.zoom);
    await prefs.setDouble('map_saved_rotation', camera.rotation);
    if (!mounted) return;
    setState(() {
      _savedCenter = camera.center;
      _savedZoom = camera.zoom;
      _savedRotation = camera.rotation;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Map position saved'), duration: Duration(seconds: 2)),
    );
  }

  Future<void> _loadLabelPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _showTrackerIds   = prefs.getBool('show_tracker_ids')   ?? true;
      _showTrackerNames = prefs.getBool('show_tracker_names') ?? true;
    });
  }

  Future<void> _toggleTrackerIds() async {
    setState(() => _showTrackerIds = !_showTrackerIds);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('show_tracker_ids', _showTrackerIds);
  }

  Future<void> _toggleTrackerNames() async {
    setState(() => _showTrackerNames = !_showTrackerNames);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('show_tracker_names', _showTrackerNames);
  }

  Future<void> _loadSavedMap() async {
    final prefs = await SharedPreferences.getInstance();
    final lat = prefs.getDouble('map_saved_lat');
    final lon = prefs.getDouble('map_saved_lon');
    final zoom = prefs.getDouble('map_saved_zoom');
    final rot = prefs.getDouble('map_saved_rotation');
    if (!mounted || lat == null || lon == null) return;
    setState(() {
      _savedCenter = LatLng(lat, lon);
      _savedZoom = zoom;
      _savedRotation = rot;
    });
  }

  Future<void> _showHelpIfFirstLaunch() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool('help_seen') == true) return;
    if (!mounted) return;
    await Navigator.push(context, MaterialPageRoute(
      builder: (_) => HelpScreen(isOnline: _isOnline, isFirstLaunch: true),
    ));
  }

  // Soft "update available" nudge: if the server manifest lists a newer build
  // than this one, offer a dismissable prompt. Never blocks; "Later" suppresses
  // it until an even newer version ships. iOS opens the App Store; Android opens
  // the APK download.
  Future<void> _checkForSoftUpdate() async {
    final info = await UpdateChecker.check();
    if (info == null || !mounted) return;
    final prefs = await SharedPreferences.getInstance();
    if (info.build <= (prefs.getInt('update_dismissed_build') ?? 0)) return;
    if (!mounted) return;
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Update available'),
        content: Text(
          'A new version of APRS Map (${info.version}) is available.'
          '${info.notes.isNotEmpty ? '\n\n${info.notes}' : ''}',
        ),
        actions: [
          TextButton(
            onPressed: () async {
              await prefs.setInt('update_dismissed_build', info.build);
              if (ctx.mounted) Navigator.pop(ctx, false);
            },
            child: const Text('Later'),
          ),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Update')),
        ],
      ),
    );
    if (go == true) {
      try {
        await launchUrl(Uri.parse(info.url), mode: LaunchMode.externalApplication);
      } catch (_) {}
    }
  }

  void _triggerBlink(Set<String> ids) {
    _blinkTimer?.cancel();
    if (_blinkDurationSec <= 0) return;
    setState(() { _blinkingIds = ids; _blinkOn = true; });
    int count = 0;
    final ticks = (_blinkDurationSec * 2).clamp(1, 200); // 500ms ticks
    _blinkTimer = Timer.periodic(const Duration(milliseconds: 500), (t) {
      if (!mounted) { t.cancel(); return; }
      count++;
      if (count >= ticks) {
        t.cancel();
        setState(() { _blinkOn = true; _blinkingIds = {}; });
        return;
      }
      setState(() => _blinkOn = !_blinkOn);
    });
  }

  /// Zoom for a long-press — "go there and get close".
  ///
  /// The old rule raised the zoom to a floor of 14, so once the map was already at 14
  /// or closer a long-press computed exactly the zoom a tap does and the two gestures
  /// became indistinguishable. That is the normal state after any tap, and it is where
  /// an iPad starts: a larger viewport fits the event at a higher zoom than a phone,
  /// so the floor was never reached and the gestures were identical from launch.
  ///
  /// Going in from wherever the map already is keeps them distinct at every zoom, and
  /// the floor still guarantees a real close-up when starting from a wide view.
  double _closeUpZoom() {
    final z = _mapController.camera.zoom;
    return (z + 2 > 16.0 ? z + 2 : 16.0).clamp(MapConfig.minZoom, MapConfig.maxZoom);
  }

  void _selectTracker(TrackerData t, {bool zoom = false}) {
    if (!t.hasPosition) {
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(t.name.isNotEmpty ? t.name : t.id),
          content: const Text(
            'No location data has been received for this tracker yet.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('OK'),
            ),
          ],
        ),
      );
      return;
    }
    setState(() {
      _selectedId = t.id;
      _revealedFixed = null;   // selecting a tracker returns to normal view
      _fullLabelTrackerId = t.id;   // force ID+Name label until the next action
      _trailEntries = [];
      _cellTrailPts  = [];
      _radioTrailPts = [];
    });
    _selectionClickCount = 1;
    final newZoom = zoom ? _closeUpZoom() : _mapController.camera.zoom;
    _mapController.move(t.latLng, newZoom);
    _triggerBlink({t.callsign});   // callsign, to blink just this device
    _fetchTrail(t);
  }

  void _selectFixed(FixedMarker m, {bool zoom = false}) {
    setState(() {
      _selectedId = m.name;
      _revealedFixed = m.name;   // reveal it if its section eyeball is off
      _fullLabelTrackerId = null;   // a fixed-marker selection reverts any tracker label
      _trailEntries  = [];
      _cellTrailPts  = [];
      _radioTrailPts = [];
    });
    _selectionClickCount = 1;
    final newZoom = zoom ? _closeUpZoom() : _mapController.camera.zoom;
    _mapController.move(LatLng(m.lat, m.lon), newZoom);
    _triggerBlink({m.name});
  }

  // ── Fixed marker tap cycle (matches web 3-tap cycle) ─────────────────────

  void _onFixedTap(FixedMarker m) {
    if (_selectedId == m.name && _selectionClickCount == 1) {
      _selectionClickCount = 2;
      _mapController.move(LatLng(m.lat, m.lon), 15.0);
    } else if (_selectedId == m.name && _selectionClickCount >= 2) {
      _selectionClickCount = 0;
      setState(() { _selectedId = null; _trailEntries = []; _cellTrailPts = []; _radioTrailPts = []; });
      _handleReset();
    } else {
      _selectFixed(m);
    }
  }

  void _openGoogleMaps(double lat, double lon) {
    if (!_isOnline) return;
    launchUrl(
      Uri.parse('https://www.google.com/maps?q=${lat.toStringAsFixed(6)},${lon.toStringAsFixed(6)}'),
      mode: LaunchMode.externalApplication,
    );
  }

  void _onFixedLongPress(FixedMarker m) {
    _openGoogleMaps(m.lat, m.lon);
  }

  Color _trackerColor(String color) {
    switch (color) {
      case 'green': return const Color(0xFF43A047);
      case 'blue':  return const Color(0xFF1E88E5);
      default:      return const Color(0xFFE53935);
    }
  }

  // Bearing in radians from p1 to p2, clockwise from north.
  double _bearingRad(LatLng p1, LatLng p2) {
    final lat1 = p1.latitude * math.pi / 180;
    final lat2 = p2.latitude * math.pi / 180;
    final dLon = (p2.longitude - p1.longitude) * math.pi / 180;
    final y = math.sin(dLon) * math.cos(lat2);
    final x = math.cos(lat1) * math.sin(lat2) -
        math.sin(lat1) * math.cos(lat2) * math.cos(dLon);
    return math.atan2(y, x);
  }

  Future<void> _fetchTrail(TrackerData tracker) async {
    if (!_isOnline) return;
    try {
      final resp = await http.get(Uri.parse('${MapConfig.serverBaseUrl}/index.php?history'));
      if (resp.statusCode != 200) return;
      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      // Cellular entries from the tracker's own callsign; radio entries from the ham callsign
      final cellEntries = (data[tracker.callsign] as List? ?? [])
          .cast<Map<String, dynamic>>()
          .map((e) => {...e, 'isCell': tracker.mobile})
          .toList();
      final radioEntries = tracker.hamCallsign != null
          ? (data[tracker.hamCallsign!] as List? ?? [])
              .cast<Map<String, dynamic>>()
              .map((e) => {...e, 'isCell': false})
              .toList()
          : <Map<String, dynamic>>[];
      // Helper: sort newest-first and drop consecutive duplicate positions, tagging
      // each crumb with its source trail so the combined set can be split back apart.
      List<Map<String, dynamic>> dedupe(List<Map<String, dynamic>> src, String trail) {
        src.sort((a, b) => (b['ts'] as int? ?? 0).compareTo(a['ts'] as int? ?? 0));
        final d = <Map<String, dynamic>>[];
        for (var i = 0; i < src.length; i++) {
          if (i == 0 || src[i]['lat'] != src[i - 1]['lat'] || src[i]['lon'] != src[i - 1]['lon']) {
            d.add({...src[i], '_trail': trail});
          }
        }
        return d;
      }
      // Apply the Breadcrumb Count cap across BOTH sources combined: keep only the N
      // most-recent crumbs overall, regardless of whether each came from the mobile or
      // the radio tracker, then split back by source for drawing.
      final limit = _breadcrumbCount;
      var merged = [...dedupe(cellEntries, 'cell'), ...dedupe(radioEntries, 'radio')]
        ..sort((a, b) => (b['ts'] as int? ?? 0).compareTo(a['ts'] as int? ?? 0));
      if (limit <= 0) {
        merged = [];
      } else if (merged.length > limit) {
        merged = merged.sublist(0, limit);
      }
      final all   = merged.reversed.toList(); // oldest-first for drawing
      final cell  = all.where((e) => e['_trail'] == 'cell').toList();
      final radio = all.where((e) => e['_trail'] == 'radio').toList();
      toLatLng(List<Map<String, dynamic>> es) =>
          es.map((e) => LatLng((e['lat'] as num).toDouble(), (e['lon'] as num).toDouble())).toList();
      if (mounted) setState(() {
        _trailEntries  = all;
        _cellTrailPts  = toLatLng(cell);
        _radioTrailPts = toLatLng(radio);
      });
    } catch (_) {}
  }

  List<Widget> _buildTrailLayers(List<LatLng> pts, Color color) {
    if (pts.length < 2) return [];
    return [
      PolylineLayer(polylines: [
        Polyline(
          points: pts,
          color: color.withOpacity(0.80),
          strokeWidth: 3.0,
          pattern: StrokePattern.dashed(segments: [4, 7]),
        ),
      ]),
      MarkerLayer(markers: [
        for (var i = 0; i < pts.length - 1; i++)
          Marker(
            point: LatLng(
              (pts[i].latitude  + pts[i + 1].latitude)  / 2,
              (pts[i].longitude + pts[i + 1].longitude) / 2,
            ),
            width: 20,
            height: 20,
            alignment: Alignment.center,
            child: Transform.rotate(
              angle: _bearingRad(pts[i], pts[i + 1]),
              child: CustomPaint(
                size: const Size(20, 20),
                painter: ArrowPainter(color: color),
              ),
            ),
          ),
      ]),
    ];
  }

  String _relativeTime(int ts) {
    final s = (DateTime.now().millisecondsSinceEpoch ~/ 1000) - ts;
    if (s < 10) return 'just now';
    if (s < 60) return '${s}s ago';
    final m = s ~/ 60, r = s % 60;
    if (m < 60) return '${m}m ${r}s ago';
    final h = m ~/ 60;
    return '${h}h ${m % 60}m ago';
  }

  void _showTrailEntryInfo(Map<String, dynamic> entry) {
    final ts = entry['ts'] as int? ?? 0;
    final path = (entry['path'] as String? ?? '').trim();
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        margin: const EdgeInsets.all(12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: Theme.of(ctx).colorScheme.surface,
          borderRadius: BorderRadius.circular(12),
          boxShadow: [BoxShadow(color: Colors.black26, blurRadius: 8)],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_relativeTime(ts),
                style: TextStyle(fontSize: 13, color: Colors.grey[600])),
            if (path.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(path, style: const TextStyle(fontSize: 13, fontFamily: 'monospace')),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _reloadConfig() async {
    final fresh = await ConfigService().load();
    if (!mounted) return;
    setState(() {
      _config = fresh;
      _initCourseVisibility();
      _initSectionVisibility();
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Config reloaded'), duration: Duration(seconds: 2)),
    );
  }

  void _handleTrackpadZoom(PointerPanZoomUpdateEvent event) {
    final camera = _mapController.camera;
    final newZoom = (camera.zoom - event.panDelta.dy * 0.01)
        .clamp(MapConfig.minZoom, MapConfig.maxZoom);
    if ((newZoom - camera.zoom).abs() < 0.001) return;
    final newCenter = camera.focusedZoomCenter(
      event.localPosition,
      newZoom,
    );
    _mapController.move(newCenter, newZoom);
  }

  void _changeBackground(BackgroundLayer bg) {
    setState(() {
      _tileUrl = bg.url;
      _tileSubdomains = bg.subdomains;
    });
  }

  void _setSectionVisible(String key, bool visible) {
    setState(() => _sectionVisible[key] = visible);
    _savedSectionVis = Map<String, bool>.from(_sectionVisible);
    _persistSectionVis();
  }

  Future<void> _persistSectionVis() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('section_visibility', jsonEncode(_sectionVisible));
  }

  Future<void> _loadSectionVisPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('section_visibility');
    if (raw == null) return;
    try {
      _savedSectionVis = (jsonDecode(raw) as Map)
          .map((k, v) => MapEntry(k.toString(), v as bool));
      if (mounted) setState(_initSectionVisibility);
    } catch (_) {}
  }

  void _setCourseVisible(String file, bool visible) =>
      setState(() => _courseVisible[file] = visible);

  List<CourseConfig> get _visibleCourses {
    if (!(_sectionVisible['courses'] ?? true)) return const [];
    return _config.courses
        .map((c) => CourseConfig(
              name: c.name,
              file: c.file,
              color: c.color,
              visible: _courseVisible[c.file] ?? c.visible,
            ))
        .toList();
  }

  // ── Scale bar ─────────────────────────────────────────────────────────────

  double _roundScaleNum(double n) {
    if (n <= 0) return 1;
    final pow10 = math.pow(10, (math.log(n) / math.ln10).floor()).toDouble();
    final d = n / pow10;
    if (d >= 10) return 10 * pow10;
    if (d >= 5)  return 5  * pow10;
    if (d >= 3)  return 3  * pow10;
    if (d >= 2)  return 2  * pow10;
    return pow10;
  }

  Widget _buildScaleBar() {
    try {
      const maxW = 100.0;
      final cam = _mapController.camera;
      final mpp = 156543.03392 *
          math.cos(cam.center.latitude * math.pi / 180) /
          math.pow(2, cam.zoom);
      final maxMeters = maxW * mpp;

      String label;
      double ratio;
      if (_scaleImperial) {
        final maxFeet = maxMeters * 3.28084;
        if (maxFeet > 5280) {
          final miles = _roundScaleNum(maxFeet / 5280);
          label = '${miles < 1 ? miles.toStringAsFixed(1) : miles.toInt()} mi';
          ratio = miles / (maxFeet / 5280);
        } else {
          final feet = _roundScaleNum(maxFeet);
          label = '${feet.toInt()} ft';
          ratio = feet / maxFeet;
        }
      } else {
        if (maxMeters >= 1000) {
          final km = _roundScaleNum(maxMeters / 1000);
          label = '${km < 1 ? km.toStringAsFixed(1) : km.toInt()} km';
          ratio = km * 1000 / maxMeters;
        } else {
          final m = _roundScaleNum(maxMeters);
          label = '${m.toInt()} m';
          ratio = m / maxMeters;
        }
      }

      final barW = (maxW * ratio).clamp(24.0, maxW);
      return GestureDetector(
        onTap: () => setState(() => _scaleImperial = !_scaleImperial),
        child: Container(
          padding: const EdgeInsets.fromLTRB(7, 3, 7, 4),
          decoration: BoxDecoration(
            color: const Color(0xEBFFFFFF),
            border: Border.all(color: const Color(0xFFBBBBBB)),
            borderRadius: BorderRadius.circular(4),
            boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 3, offset: Offset(0, 1))],
          ),
          child: Container(
            width: barW,
            padding: const EdgeInsets.symmetric(vertical: 1),
            decoration: const BoxDecoration(
              border: Border(
                left:   BorderSide(color: Color(0xFF555555), width: 2),
                right:  BorderSide(color: Color(0xFF555555), width: 2),
                bottom: BorderSide(color: Color(0xFF555555), width: 2),
              ),
            ),
            alignment: Alignment.center,
            child: Text(label,
                style: const TextStyle(fontSize: 10, color: Color(0xFF333333), height: 1.1)),
          ),
        ),
      );
    } catch (_) {
      return const SizedBox.shrink();
    }
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawerScrimColor: Colors.transparent,
      // Opening the sidebar returns to normal view — hide any revealed
      // (eyeball-off) iGate/aid marker. Clear on open, not close: tapping a tile
      // closes the drawer right after selecting, and clearing on close would
      // wipe the reveal we just set.
      onDrawerChanged: (isOpen) {
        if (isOpen) _clearTransientMapHighlights();
      },
      drawer: MenuDrawer(
        config: _config,
        trackers: _trackers,
        isSharing: _isSharing,
        sharingCallsign: _isSharing ? _bgLocation.callsign : null,
        sharingName: _isSharing ? _bgLocation.trackerName : null,
        sharingActivityMode: _isSharing ? _sharingActivityMode : -1,
        isOnline: _isOnline,
        selectedId: _selectedId,
        blinkingIds: _blinkingIds,
        blinkOn: _blinkOn,
        showTrackerIds: _showTrackerIds,
        showTrackerNames: _showTrackerNames,
        onToggleTrackerIds: _toggleTrackerIds,
        onToggleTrackerNames: _toggleTrackerNames,
        selectedBgUrl: _tileUrl,
        sectionVisible: _sectionVisible,
        courseVisible: _courseVisible,
        onTrackerTap: (t) => _selectTracker(t),
        onTrackerLongPress: (t) => _selectTracker(t, zoom: true),
        onFixedTap: (m) => _selectFixed(m),
        onFixedLongPress: (m) => _selectFixed(m, zoom: true),
        onBackgroundChange: _changeBackground,
        onSectionVisibility: _setSectionVisible,
        onCourseVisibility: _setCourseVisible,
        onReload: _reloadConfig,
        onShareToggle: _toggleSharing,
        onResetMap: _handleReset,
        onSaveMap: _handleSaveMap,
        onSendMessage: _openMessaging,
        onActivityModeChange: _isSharing ? _changeActivityMode : null,
        onStartSharingWithMode: _isSharing ? null : _startSharingWithMode,
      ),
      body: Stack(children: [
        _buildBody(),
        // Only visible while something is queued; see AudioQueueBar.
        const Align(alignment: Alignment.bottomCenter, child: AudioQueueBar()),
      ]),
    );
  }

  Widget _buildBody() {
    final showTrackers = _sectionVisible['trackers'] ?? true;
    final showAid = _sectionVisible['aidstations'] ?? true;
    final showIgates = _sectionVisible['igates'] ?? true;
    // When a section's eyeball is off, still reveal the single object the user
    // tapped in the drawer, until they return to normal view.
    final revealIgate = (!showIgates && _revealedFixed != null)
        ? _config.igates.where((g) => g.name == _revealedFixed).firstOrNull
        : null;
    final revealAid = (!showAid && _revealedFixed != null)
        ? _config.aidStations.where((g) => g.name == _revealedFixed).firstOrNull
        : null;

    return Builder(
      builder: (context) => Stack(
        children: [
          Listener(
            onPointerPanZoomUpdate: _handleTrackpadZoom,
            child: FlutterMap(
              mapController: _mapController,
              options: MapOptions(
                initialCenter: widget.initialCenter ?? LatLng(_config.mapLat, _config.mapLon),
                initialZoom: _config.mapZoom,
                minZoom: MapConfig.minZoom,
                maxZoom: MapConfig.maxZoom,
                backgroundColor: Colors.grey[900]!,
                interactionOptions: const InteractionOptions(
                  flags: InteractiveFlag.all & ~InteractiveFlag.pinchMove,
                ),
                // Tapping the empty map returns to normal view: hide a revealed
                // (eyeball-off) iGate/aid marker and drop a forced tracker label.
                onTap: (_, __) => _clearTransientMapHighlights(),
                onLongPress: (_, latlng) => _setAnchor(latlng),
                onMapEvent: (event) {
                  final z = _mapController.camera.zoom;
                  if ((z - _scaleZoom).abs() > 0.05) {
                    setState(() => _scaleZoom = z);
                  }
                  // Any user map gesture (pan, zoom, fling) returns to normal
                  // view: hide a revealed (eyeball-off) iGate/aid marker and drop
                  // a forced tracker label. The programmatic centering move on
                  // selection uses MapEventSource.mapController and is ignored, as
                  // are layout size changes.
                  if (event.source != MapEventSource.mapController &&
                      event.source != MapEventSource.nonRotatedSizeChange) {
                    _clearTransientMapHighlights();
                  }
                  _anchorOnMapEvent(event);
                },
              ),
              children: [
                TileLayer(
                  urlTemplate: _tileUrl,
                  subdomains: _tileSubdomains,
                  userAgentPackageName: 'org.marsaprs.aprs_map',
                  tileProvider: _tileProvider,
                ),
                CourseLayer(courses: _visibleCourses),
                ..._buildTrailLayers(_cellTrailPts,  const Color(0xFF27AE60)),
                ..._buildTrailLayers(_radioTrailPts, const Color(0xFFE74C3C)),
                // The anchor. Drawn before the trackers so it never covers one -- it is
                // a reference point, not traffic, and the traffic is what is being
                // looked for. Tapping it lets go without needing a reset.
                if (_anchor != null)
                  MarkerLayer(markers: [
                    Marker(
                      point: _anchor!,
                      width: 44,
                      height: 44,
                      child: GestureDetector(
                        behavior: HitTestBehavior.opaque,
                        onTap: _clearAnchor,
                        child: const Center(
                          child: Icon(Icons.push_pin, size: 26, color: Color(0xFF8E44AD),
                                      shadows: [Shadow(blurRadius: 3, color: Colors.black54)]),
                        ),
                      ),
                    ),
                  ]),
                if (_trailEntries.isNotEmpty)
                  MarkerLayer(markers: _trailEntries.map((e) {
                    final pt = LatLng((e['lat'] as num).toDouble(), (e['lon'] as num).toDouble());
                    final isCell = e['isCell'] as bool? ?? true;
                    final dotColor = isCell ? const Color(0xFF27AE60) : const Color(0xFFE74C3C);
                    return Marker(
                      point: pt,
                      width: 30,
                      height: 30,
                      child: GestureDetector(
                        behavior: HitTestBehavior.opaque,
                        onTap: () => _showTrailEntryInfo(e),
                        onLongPress: () => _openGoogleMaps(pt.latitude, pt.longitude),
                        child: Center(
                          child: SizedBox(
                            width: 14,
                            height: 14,
                            child: Container(
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: dotColor.withOpacity(0.5),
                                border: Border.all(color: dotColor, width: 1.5),
                              ),
                            ),
                          ),
                        ),
                      ),
                    );
                  }).toList()),
                if (showIgates && _config.igates.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.igates,
                    isIgate: true,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  )
                else if (revealIgate != null)
                  FixedMarkerLayer(
                    markers: [revealIgate],
                    isIgate: true,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  ),
                if (showAid && _config.aidStations.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.aidStations,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  )
                else if (revealAid != null)
                  FixedMarkerLayer(
                    markers: [revealAid],
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  ),
                if (showTrackers && _isOnline)
                  TrackerLayer(
                    trackers: _trackers,
                    fullLabelId: _fullLabelTrackerId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    showIds: _showTrackerIds,
                    showNames: _showTrackerNames,
                    onLongPress: (t) { if (t.lat != null && t.lon != null) _openGoogleMaps(t.lat!, t.lon!); },
                  ),
                if (_locationState == _LocationState.whileInUse ||
                    _locationState == _LocationState.always)
                  CurrentLocationLayer(
                    positionStream: _locationMarkerStream,
                    // Passing an empty heading stream stops flutter_compass from
                    // firing continuous compass updates that drive a 60 fps
                    // AnimationController and prevent iOS auto-lock.
                    headingStream: const Stream.empty(),
                    style: const LocationMarkerStyle(
                      marker: DefaultLocationMarker(),
                      markerSize: Size(20, 20),
                      accuracyCircleColor: Color(0x1A2196F3),
                      headingSectorColor: Colors.transparent,
                    ),
                  ),
              ],
            ),
          ),

          if (!_isOnline) const OfflineBanner(),
          if (_updateRequired && !_updateBannerDismissed)
            UpdateBanner(onDismiss: () => setState(() => _updateBannerDismissed = true)),

          // Scale bar — bottom left
          Positioned(
            bottom: 24,
            left: 16,
            child: SafeArea(child: _buildScaleBar()),
          ),

          // Menu button — top left
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Material(
                color: Colors.white,
                shape: const CircleBorder(),
                elevation: 4,
                child: IconButton(
                  icon: const Icon(Icons.menu),
                  onPressed: () => Scaffold.of(context).openDrawer(),
                ),
              ),
            ),
          ),

          // Mode indicator — top right
          Positioned(
            top: 0,
            right: 0,
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(0, 8, 12, 0),
                child: ModeIndicator(online: _isOnline),
              ),
            ),
          ),

          // Sharing badge — below mode indicator
          if (_isSharing && _isOnline)
            Positioned(
              top: 0,
              right: 0,
              child: SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(0, 36, 12, 0),
                  child: AnimatedOpacity(
                    opacity: _shareBadgeOn ? 1.0 : 0.15,
                    duration: const Duration(milliseconds: 150),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: Colors.red[700],
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Text('Sharing',
                          style: TextStyle(color: Colors.white, fontSize: 11)),
                    ),
                  ),
                ),
              ),
            ),

          // Reset map button — bottom right, above recenter
          Positioned(
            bottom: 92,
            right: 20,
            child: SafeArea(
              child: Material(
                color: Colors.white,
                shape: const CircleBorder(),
                elevation: 4,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: _handleReset,
                  child: const SizedBox(
                    width: 44,
                    height: 44,
                    child: Icon(Icons.restart_alt, color: Colors.grey, size: 22),
                  ),
                ),
              ),
            ),
          ),

          // Re-center button — bottom right
          Positioned(
            bottom: 24,
            right: 16,
            child: SafeArea(
              child: GestureDetector(
                onTap: _handleRecenter,
                onLongPress: _handleReset,
                child: Material(
                  color: Colors.white,
                  shape: const CircleBorder(),
                  elevation: 4,
                  child: Container(
                    width: 56,
                    height: 56,
                    alignment: Alignment.center,
                    child: Icon(
                      Icons.my_location,
                      color: (_locationState == _LocationState.whileInUse ||
                              _locationState == _LocationState.always)
                          ? Colors.blue[700]
                          : Colors.grey,
                    ),
                  ),
                ),
              ),
            ),
          ),

          // Location denied toast
          if (_locationState == _LocationState.denied)
            Positioned(
              bottom: 90,
              left: 16,
              right: 80,
              child: Material(
                borderRadius: BorderRadius.circular(8),
                color: Colors.black87,
                child: const Padding(
                  padding: EdgeInsets.all(12),
                  child: Text(
                    'Location access denied — your position won\'t be shown.',
                    style: TextStyle(color: Colors.white),
                    textAlign: TextAlign.center,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

