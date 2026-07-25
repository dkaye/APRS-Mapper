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
import 'package:permission_handler/permission_handler.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_map_location_marker/flutter_map_location_marker.dart';
import 'package:flutter_map_tile_caching/flutter_map_tile_caching.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'arrow_painter.dart';
import 'background_location.dart';
import 'config_service.dart';
import 'course_layer.dart';
import 'download_screen.dart';
import 'help_screen.dart';
import 'fixed_marker_layer.dart';
import 'map_config.dart';
import 'menu_drawer.dart';
import 'online_poller.dart';
import 'remote_config.dart';
import 'tracker_data.dart';
import 'tracker_layer.dart';
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
  int _selectionClickCount = 0;
  Set<String> _blinkingIds = {};
  bool _blinkOn = true;
  Timer? _blinkTimer;
  int _blinkDurationSec  = 5;
  int _breadcrumbCount   = 100;

  // Resting tracker label content — toggled by the ID / Name eyes in the sidebar.
  bool _showTrackerIds   = true;
  bool _showTrackerNames = true;
  String? _lastRecipient; // sticky default for Send Message

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
  bool _isSharing = false;
  final _audioPlayer = AudioPlayer();
  final _notifPlugin = FlutterLocalNotificationsPlugin();
  final _msgLog = <({String label, String text, bool isMe, DateTime time})>[];
  final _pendingMessages = <InboundMessage>[];
  AppLifecycleState _appLifecycleState = AppLifecycleState.resumed;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _appLifecycleState = state;
    if (state == AppLifecycleState.resumed) {
      if (_pendingMessages.isNotEmpty) {
        final queued = List<InboundMessage>.of(_pendingMessages);
        _pendingMessages.clear();
        WidgetsBinding.instance.addPostFrameCallback((_) async {
          for (final msg in queued) {
            if (!mounted) return;
            await _showInboundDialog(msg);
          }
        });
      }
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
        if (Platform.isAndroid) unawaited(_maybeResumeSharing());
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
      // Old channel had the default sound; a channel's sound is immutable once
      // created, so move to a new channel id to deliver the custom alert sound
      // to existing installs.
      await android?.deleteNotificationChannel(channelId: 'aprs_msg');
      await android?.createNotificationChannel(const AndroidNotificationChannel(
        'aprs_msg_2',
        'APRS Messages',
        importance: Importance.max,
        playSound: true,
        sound: RawResourceAndroidNotificationSound('message'),
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
    _config = widget.config;
    // Base layer follows the server's offline-map tile source, so it matches the
    // offline download URL (shared FMTC cache) and an event can retarget both by
    // setting offline_map.url. Falls back to the compiled default (the proxy).
    if (_config.offlineTileUrl.isNotEmpty) _tileUrl = _config.offlineTileUrl;
    _beaconIntervalsSec = List.of(_config.beaconIntervalsSec);
    _beaconDistancesMi  = List.of(_config.beaconDistancesMi);
    _initCourseVisibility();
    _initSectionVisibility();
    _loadLabelPrefs();
    _loadLastRecipient();
    _checkExistingPermission();
    _poller = OnlinePoller(
      onData: (data) {
        if (!mounted) return;
        final prev = _trackers;
        final updated = data.trackers
            .where((t) {
              final old = prev.where((o) => o.id == t.id).firstOrNull;
              return old != null && old.lastUpdate != t.lastUpdate;
            })
            .map((t) => t.id)
            .toSet();
        TrackerData? refetchTracker;
        if (_selectedId != null) {
          final sel = data.trackers.where((t) => t.id == _selectedId).firstOrNull;
          if (sel != null && updated.contains(_selectedId)) refetchTracker = sel;
        }
        final newIntervals  = data.beaconIntervalsSec;
        final newDistances  = data.beaconDistancesMi;
        setState(() {
          _trackers = data.trackers;
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
    _bgLocation.onBeaconSent = () {
      if (!mounted) return;
      setState(() => _shareBadgeOn = false);
      Future.delayed(const Duration(milliseconds: 300), () {
        if (mounted) setState(() => _shareBadgeOn = true);
      });
    };

    _bgLocation.onSessionEnded = () {
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

    _bgLocation.onHistoryLoaded = (msgs) {
      if (!mounted) return;
      setState(() {
        for (final m in msgs) {
          _msgLog.add((
            label: m.fromLabel.isNotEmpty ? m.fromLabel : 'Unknown',
            text: m.text,
            isMe: false,
            time: DateTime.fromMillisecondsSinceEpoch(m.ts * 1000),
          ));
        }
        if (_msgLog.length > 50) _msgLog.removeRange(0, _msgLog.length - 50);
      });
    };
    _bgLocation.onMessageReceived = (msg) {
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

  void _showSendMessageDialog({String? prefill}) {
    if (!_isSharing) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Start sharing your location to send messages'),
        duration: Duration(seconds: 3),
      ));
      return;
    }
    final controller = TextEditingController(text: prefill ?? '');
    final scrollController = ScrollController();
    bool didScroll = false;
    // Destination picker: web operators currently monitoring. Fetched once when
    // the sheet opens. 0/1 → no picker; >1 → dropdown to choose the recipient.
    List<String> recipients = [];
    String? selectedRecipient;
    bool recipientsRequested = false;
    bool recipientError = false; // true after a send attempt with no recipient chosen
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
      ),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setDlgState) {
        if (!recipientsRequested) {
          recipientsRequested = true;
          _bgLocation.session.fetchWebRecipients().then((list) {
            if (!ctx.mounted) return;
            setDlgState(() {
              recipients = list;
              // One operator → pick it. Otherwise fall back to whoever was chosen
              // last time, provided they're still monitoring, so repeat messages
              // don't need a trip through the dropdown. Only when there's no usable
              // previous choice is the user made to pick one.
              selectedRecipient = list.length == 1
                  ? list.first
                  : (list.contains(_lastRecipient) ? _lastRecipient : null);
            });
          });
        }
        final recent = _msgLog.length > 10 ? _msgLog.sublist(_msgLog.length - 10) : List.of(_msgLog);
        if (!didScroll && recent.isNotEmpty) {
          didScroll = true;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (scrollController.hasClients) {
              scrollController.jumpTo(scrollController.position.maxScrollExtent);
            }
          });
        }
        return Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 4, 0),
              child: Row(children: [
                const Expanded(child: Text('Send Message',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold))),
                IconButton(
                  icon: const Icon(Icons.close, size: 20),
                  onPressed: () => Navigator.pop(ctx),
                  padding: EdgeInsets.zero,
                  visualDensity: VisualDensity.compact,
                ),
              ]),
            ),
            if (recent.isNotEmpty) ...[
              Container(
                constraints: const BoxConstraints(maxHeight: 130),
                margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                decoration: BoxDecoration(
                  color: const Color(0xFFF5F5F5),
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: const Color(0xFFE0E0E0)),
                ),
                child: ListView(
                  controller: scrollController,
                  shrinkWrap: true,
                  padding: const EdgeInsets.all(8),
                  children: recent.map((m) {
                    final t = '${m.time.hour.toString().padLeft(2,'0')}:${m.time.minute.toString().padLeft(2,'0')}';
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: RichText(text: TextSpan(style: const TextStyle(fontSize: 12, color: Colors.black87), children: [
                        TextSpan(text: m.label, style: TextStyle(fontWeight: FontWeight.bold, color: m.isMe ? const Color(0xFF1A5276) : Colors.black87)),
                        TextSpan(text: '  $t\n', style: const TextStyle(fontSize: 10, color: Colors.grey)),
                        TextSpan(text: m.text),
                      ])),
                    );
                  }).toList(),
                ),
              ),
              const Divider(height: 1, thickness: 1, color: Color(0xFFBDBDBD), indent: 16, endIndent: 16),
            ],
            if (recipients.length > 1)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    const Text('To: ', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
                    Expanded(
                      child: DropdownButton<String>(
                        isExpanded: true,
                        value: selectedRecipient,
                        hint: const Text('Select recipient…',
                            style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                        items: recipients
                            .map((r) => DropdownMenuItem(value: r, child: Text(r)))
                            .toList(),
                        onChanged: (v) {
                          _rememberRecipient(v);
                          setDlgState(() {
                            selectedRecipient = v;
                            recipientError = false;
                          });
                        },
                      ),
                    ),
                  ]),
                  if (recipientError)
                    const Padding(
                      padding: EdgeInsets.only(top: 2),
                      child: Text('Choose who will receive this message',
                          style: TextStyle(color: Colors.red, fontSize: 12)),
                    ),
                ]),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 8, 12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Expanded(
                  child: TextField(
                    controller: controller,
                    maxLength: 280,
                    maxLines: 5,
                    minLines: 1,
                    decoration: const InputDecoration(hintText: 'Type your message…', border: OutlineInputBorder()),
                    autofocus: true,
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.send),
                  color: const Color(0xFF1565C0),
                  onPressed: () async {
                    final text = controller.text.trim();
                    if (text.isEmpty) return;
                    if (recipients.length > 1 && selectedRecipient == null) {
                      setDlgState(() => recipientError = true);
                      return;
                    }
                    Navigator.pop(ctx);
                    _rememberRecipient(selectedRecipient);
                    final error = await _bgLocation.session.sendMessage(text, to: selectedRecipient);
                    if (error == null) {
                      setState(() {
                        _msgLog.add((label: 'Me', text: text, isMe: true, time: DateTime.now()));
                        if (_msgLog.length > 30) _msgLog.removeAt(0);
                      });
                      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text('Message Sent'),
                        duration: Duration(seconds: 3),
                      ));
                    } else {
                      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(error),
                        backgroundColor: Colors.red[700],
                        duration: const Duration(seconds: 5),
                      ));
                    }
                  },
                ),
              ]),
            ),
          ]),
        );
      }),
    );
  }

  Widget _buildMsgThread() {
    final recent = _msgLog.length > 10 ? _msgLog.sublist(_msgLog.length - 10) : _msgLog;
    return Container(
      constraints: const BoxConstraints(maxHeight: 160),
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: const Color(0xFFF5F5F5),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: const Color(0xFFE0E0E0)),
      ),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.all(8),
        children: recent.map((m) {
          final t = '${m.time.hour.toString().padLeft(2,'0')}:${m.time.minute.toString().padLeft(2,'0')}';
          return Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: RichText(text: TextSpan(style: const TextStyle(fontSize: 12, color: Colors.black87), children: [
              TextSpan(text: m.label, style: TextStyle(fontWeight: FontWeight.bold, color: m.isMe ? const Color(0xFF1A5276) : Colors.black87)),
              TextSpan(text: '  $t\n', style: const TextStyle(fontSize: 10, color: Colors.grey)),
              TextSpan(text: m.text),
            ])),
          );
        }).toList(),
      ),
    );
  }

  Future<void> _handleInboundMessage(InboundMessage msg) async {
    setState(() {
      _msgLog.add((label: msg.fromLabel, text: msg.text, isMe: false, time: DateTime.fromMillisecondsSinceEpoch(msg.ts * 1000)));
      if (_msgLog.length > 30) _msgLog.removeAt(0);
    });
    if (_appLifecycleState != AppLifecycleState.resumed) {
      _pendingMessages.add(msg);
      if (Platform.isAndroid) {
        if (await FlutterForegroundTask.canDrawOverlays) {
          FlutterForegroundTask.launchApp();
        }
        unawaited(_notifPlugin.show(
          id: msg.id & 0x7FFFFFFF,
          title: '📨 ${msg.fromLabel}',
          body: msg.text,
          notificationDetails: NotificationDetails(
            android: AndroidNotificationDetails(
              'aprs_msg_2',
              'APRS Messages',
              importance: Importance.max,
              priority: Priority.max,
              fullScreenIntent: true,
              playSound: true,
              sound: const RawResourceAndroidNotificationSound('message'),
              enableVibration: true,
              vibrationPattern: Int64List.fromList([0, 400, 200, 400, 200, 400]),
              autoCancel: true,
            ),
          ),
        ));
      } else if (Platform.isIOS) {
        unawaited(_notifPlugin.show(
          id: msg.id & 0x7FFFFFFF,
          title: '📨 ${msg.fromLabel}',
          body: msg.text,
          notificationDetails: const NotificationDetails(
            iOS: DarwinNotificationDetails(
              presentAlert: true,
              presentSound: true,
              // Custom alert sound bundled in the Runner app (ios/Runner/message.wav).
              // Still obeys the silent switch / Focus like any notification sound.
              sound: 'message.wav',
              presentBadge: true,
              interruptionLevel: InterruptionLevel.timeSensitive,
            ),
          ),
        ));
      }
      return;
    }
    try {
      await _audioPlayer.setAudioSource(AudioSource.asset('assets/sounds/message.wav'));
      unawaited(_audioPlayer.play());
    } catch (_) {}
    await _showInboundDialog(msg);
  }

  Future<void> _showInboundDialog(InboundMessage msg) async {
    if (!mounted) return;
    showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        bool showReply = false;
        final replyController = TextEditingController();
        final scrollController = ScrollController();
        bool didScroll = false;
        return StatefulBuilder(builder: (ctx, setDlgState) {
          // Prior messages = everything except the just-arrived one (last in _msgLog)
          final prior = _msgLog.length > 1
              ? _msgLog.sublist(0, _msgLog.length - 1)
              : <({String label, String text, bool isMe, DateTime time})>[];
          final recentPrior = prior.length > 10 ? prior.sublist(prior.length - 10) : prior;
          final newTime = DateTime.fromMillisecondsSinceEpoch(msg.ts * 1000);
          final newT = '${newTime.hour.toString().padLeft(2,'0')}:${newTime.minute.toString().padLeft(2,'0')}';
          // Scroll to bottom (new message) on first render
          if (!didScroll) {
            didScroll = true;
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (scrollController.hasClients) {
                scrollController.jumpTo(scrollController.position.maxScrollExtent);
              }
            });
          }
          // In landscape (esp. iPad) the on-screen keyboard eats ~half the
          // screen, so go wide-and-short: a wider dialog with a shorter
          // history pane and a shorter reply field.
          final mq = MediaQuery.of(ctx);
          final landscape = mq.size.width > mq.size.height;
          final historyMax = landscape ? 200.0 : 300.0;
          final contentWidth = landscape
              ? math.min(mq.size.width * 0.8, 720.0)
              : math.min(mq.size.width * 0.9, 400.0);
          return AlertDialog(
            insetPadding: EdgeInsets.symmetric(horizontal: 24, vertical: landscape ? 12 : 24),
            titlePadding: EdgeInsets.fromLTRB(24, landscape ? 12 : 24, 24, 0),
            contentPadding: EdgeInsets.fromLTRB(24, landscape ? 12 : 20, 24, landscape ? 8 : 24),
            title: Row(children: [
              const Icon(Icons.message, size: 20),
              const SizedBox(width: 8),
              Text(msg.fromLabel),
            ]),
            content: SizedBox(
              width: contentWidth,
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              // Flexible so the history pane gives up height to the on-screen
              // keyboard instead of overflowing the dialog.
              Flexible(child: Container(
                constraints: BoxConstraints(maxHeight: historyMax),
                decoration: BoxDecoration(
                  color: const Color(0xFFF5F5F5),
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: const Color(0xFFE0E0E0)),
                ),
                child: ListView(
                  controller: scrollController,
                  shrinkWrap: true,
                  padding: const EdgeInsets.all(8),
                  children: [
                    ...recentPrior.map((m) {
                      final t = '${m.time.hour.toString().padLeft(2,'0')}:${m.time.minute.toString().padLeft(2,'0')}';
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: RichText(text: TextSpan(style: const TextStyle(fontSize: 12, color: Colors.black87), children: [
                          TextSpan(text: m.label, style: TextStyle(fontWeight: FontWeight.bold, color: m.isMe ? const Color(0xFF1A5276) : Colors.black87)),
                          TextSpan(text: '  $t\n', style: const TextStyle(fontSize: 10, color: Colors.grey)),
                          TextSpan(text: m.text),
                        ])),
                      );
                    }),
                    if (recentPrior.isNotEmpty)
                      const Divider(height: 16, thickness: 1, color: Color(0xFFBDBDBD)),
                    RichText(text: TextSpan(style: const TextStyle(fontSize: 13, color: Colors.black87), children: [
                      TextSpan(text: msg.fromLabel, style: const TextStyle(fontWeight: FontWeight.bold)),
                      TextSpan(text: '  $newT\n', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                      TextSpan(text: msg.text, style: const TextStyle(fontSize: 15)),
                    ])),
                  ],
                ),
              )),
              if (showReply) ...[
                SizedBox(height: landscape ? 8 : 12),
                TextField(
                  controller: replyController,
                  maxLength: 280,
                  maxLines: landscape ? 2 : 4,
                  // Hide the 0/280 counter in landscape — every pixel counts.
                  buildCounter: landscape
                      ? (_, {required currentLength, required isFocused, maxLength}) => null
                      : null,
                  decoration: InputDecoration(
                    hintText: 'Type your reply…',
                    border: const OutlineInputBorder(),
                    isDense: landscape,
                    contentPadding: landscape ? const EdgeInsets.symmetric(horizontal: 12, vertical: 10) : null,
                  ),
                  autofocus: true,
                ),
              ],
            ])),
            actions: [
              if (!showReply) TextButton(
                onPressed: () => setDlgState(() => showReply = true),
                child: const Text('Reply'),
              ),
              if (showReply) TextButton(
                onPressed: () async {
                  final text = replyController.text.trim();
                  if (text.isEmpty) return;
                  Navigator.pop(ctx);
                  // Reply goes back to the operator who sent the incoming message.
                  final error = await _bgLocation.session.sendMessage(text, to: msg.fromLabel);
                  if (error == null) {
                    setState(() {
                      _msgLog.add((label: 'Me', text: text, isMe: true, time: DateTime.now()));
                      if (_msgLog.length > 30) _msgLog.removeAt(0);
                    });
                    if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                      content: Text('Message Sent'),
                      duration: Duration(seconds: 3),
                    ));
                  } else {
                    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text(error),
                      backgroundColor: Colors.red[700],
                      duration: const Duration(seconds: 5),
                    ));
                  }
                },
                child: const Text('Send'),
              ),
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Close')),
            ],
          );
        });
      },
    );
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
    _config.sectionVisibility.forEach((key, visible) {
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
        // Android foreground service works with whileInUse; iOS needs always.
        if (Platform.isAndroid) unawaited(_maybeResumeSharing());
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
    );
    return confirmed == true;
  }

  // Shows the consent screen disclosing that location will be sent to the server.
  // Not a system dialog — our own UI. Shown once per app session.
  // backgroundLimited: true when permission is only "While Using" so we warn
  // that sharing pauses when the screen locks.
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
            const Text('Share Your Location with Participants',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            Text(
              backgroundLimited
                ? 'Others will be able to see your location on the map — '
                  'including users of this app, our website, and third-party '
                  'apps like aprs.fi and CalTopo.com.\n\n'
                  'Your name and ham-radio callsign will also be visible, '
                  'if entered.\n\n'
                  'Sharing will pause when the screen locks or you switch apps. '
                  'To share in the background, go to Settings → Privacy & Security → '
                  'Location Services → APRS Map and choose “Always”.'
                : 'Others will be able to see your location on the map — '
                  'including users of this app, our website, and third-party '
                  'apps like aprs.fi and CalTopo.com.\n\n'
                  'Your name and ham-radio callsign will also be visible, '
                  'if entered.\n\n'
                  'Because you\'ve allowed background access, sharing will '
                  'continue even when the screen is locked.',
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
      setState(() { _isSharing = true; _sharingActivityMode = _bgLocation.activityMode; });
      _resetAutoModeDetection();
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Location sharing resumed'),
        duration: Duration(seconds: 3),
      ));
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

  void _handleReset() {
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

  // Last operator the user picked in Send Message. Reused as the default so a
  // repeat message doesn't need a trip through the dropdown; only honoured when
  // that operator is still monitoring (i.e. still in the fetched recipient list).
  Future<void> _loadLastRecipient() async {
    final prefs = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() => _lastRecipient = prefs.getString('last_msg_recipient'));
  }

  Future<void> _rememberRecipient(String? name) async {
    if (name == null || name == _lastRecipient) return;
    _lastRecipient = name;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('last_msg_recipient', name);
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
      _trailEntries = [];
      _cellTrailPts  = [];
      _radioTrailPts = [];
    });
    _selectionClickCount = 1;
    final newZoom = zoom
        ? _mapController.camera.zoom.clamp(14.0, MapConfig.maxZoom)
        : _mapController.camera.zoom;
    _mapController.move(t.latLng, newZoom);
    _triggerBlink({t.id});
    _fetchTrail(t);
  }

  void _selectFixed(FixedMarker m, {bool zoom = false}) {
    setState(() {
      _selectedId = m.name;
      _trailEntries  = [];
      _cellTrailPts  = [];
      _radioTrailPts = [];
    });
    _selectionClickCount = 1;
    final newZoom = zoom
        ? _mapController.camera.zoom.clamp(14.0, MapConfig.maxZoom)
        : _mapController.camera.zoom;
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

  void _setSectionVisible(String key, bool visible) =>
      setState(() => _sectionVisible[key] = visible);

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
        onSendMessage: _showSendMessageDialog,
        onActivityModeChange: _isSharing ? _changeActivityMode : null,
        onStartSharingWithMode: _isSharing ? null : _startSharingWithMode,
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    final showTrackers = _sectionVisible['trackers'] ?? true;
    final showAid = _sectionVisible['aidstations'] ?? true;
    final showIgates = _sectionVisible['igates'] ?? true;

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
                onMapEvent: (event) {
                  final z = _mapController.camera.zoom;
                  if ((z - _scaleZoom).abs() > 0.05) {
                    setState(() => _scaleZoom = z);
                  }
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
                  ),
                if (showAid && _config.aidStations.isNotEmpty)
                  FixedMarkerLayer(
                    markers: _config.aidStations,
                    selectedId: _selectedId,
                    blinkingIds: _blinkingIds,
                    blinkOn: _blinkOn,
                    onTap: _onFixedTap,
                    onLongPress: _onFixedLongPress,
                  ),
                if (showTrackers && _isOnline)
                  TrackerLayer(
                    trackers: _trackers,
                    selectedId: _selectedId,
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

