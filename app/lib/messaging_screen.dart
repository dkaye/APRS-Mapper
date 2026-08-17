/// Chat screen for the redesigned messaging system (mirrors the web operator
/// panel): a conversation list (inbox) → thread → always-visible composer, with
/// any-to-any + group recipients, live polling, delivery/read receipts, and an
/// optional read-aloud (text-to-speech) toggle.
import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:just_audio/just_audio.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'messaging_client.dart';
import 'audio_queue.dart';
import 'widgets/audio_queue_bar.dart';
import 'monitor_service.dart';
import 'speaker.dart';
import 'watch_bridge.dart';

const _kBlue = Color(0xFF2980B9);
const _kLastRecipients = 'aprs_msg_last_recipients';
const _kDark = Color(0xFF1A5276);

class MessagingScreen extends StatefulWidget {
  final MessagingClient client;
  const MessagingScreen({super.key, required this.client});

  /// True while the chat screen is on-screen — lets the map screen suppress the
  /// legacy inbound notification/dialog (the chat shows messages live instead).
  static bool isOpen = false;

  @override
  State<MessagingScreen> createState() => _MessagingScreenState();
}

class _MessagingScreenState extends State<MessagingScreen> {
  List<MsgConversation> _convs = [];
  int? _myId;
  MsgConversation? _open; // open thread; null = inbox
  List<String>? _pendingRecipients; // new conversation not yet created
  List<MsgMessage> _messages = [];
  final Map<int, MsgReceipt> _receipts = {};
  final Set<int> _seen = {};
  final Set<int> _deferredSpeak = {};
  int _lastId = 0;
  Timer? _pollTimer;
  bool _sending = false;

  final _composeCtl = TextEditingController();
  final _scrollCtl = ScrollController();
  final _player = AudioPlayer();
  final _picker = ImagePicker();
  String? _pendingPhotoPath; // photo staged in the composer, not yet sent
  bool _speak = false;
  List<String> _lastRecipients = const [];

  /// Monitor mode: the event's whole traffic, as a running log. A third place this
  /// screen can be, alongside the inbox and an open thread.
  bool _monitorOpen = false;
  StreamSubscription<List<MsgMessage>>? _monitorSub;

  @override
  void initState() {
    super.initState();
    MessagingScreen.isOpen = true;
    _restoreSpeak();
    _bootstrap();
    // Live while this screen exists, not only while the monitor view is showing: the
    // inbox row carries a count, and it has to be right when the operator looks at it.
    _monitorSub = MonitorService.instance.messages.listen((_) {
      if (mounted) setState(() {});
    });
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
  }

  @override
  void dispose() {
    MessagingScreen.isOpen = false;
    _pollTimer?.cancel();
    _monitorSub?.cancel();
    _composeCtl.dispose();
    _scrollCtl.dispose();
    _player.dispose();
    Speaker.instance.stop();
    super.dispose();
  }

  Future<void> _restoreSpeak() async {
    final p = await SharedPreferences.getInstance();
    if (mounted) {
      setState(() {
        // Default ON: this is a net-control tool and the operator is usually not
        // watching the screen. An explicit mute is still remembered.
        _speak = p.getBool('aprs_msg_speak') ?? true;
        _lastRecipients = p.getStringList(_kLastRecipients) ?? const [];
      });
    }
  }

  /// Recipients of the last message started from the picker, remembered so the
  /// next one opens pre-ticked — during an event the same station is usually
  /// addressed repeatedly. Stored as recipient KEYS rather than participant ids:
  /// a "(multiple)" row's id is synthetic and would not survive a reload.
  Future<void> _saveLastRecipients(List<String> keys) async {
    final p = await SharedPreferences.getInstance();
    await p.setStringList(_kLastRecipients, keys);
  }

  Future<void> _bootstrap() async {
    final pr = await widget.client.participants();
    _myId = pr.me;
    await _loadConversations();
    // Prime the poll watermark WITHOUT alerting, so opening the chat doesn't
    // replay old messages as tones/speech — only genuinely new arrivals do.
    final res = await widget.client.poll(0);
    for (final m in res.messages) {
      _seen.add(m.id);
    }
    for (final r in res.receipts) {
      _receipts[r.messageId] = r;
    }
    if (res.lastId > _lastId) _lastId = res.lastId;
    if (mounted) setState(() {});
  }

  Future<void> _loadConversations() async {
    final list = await widget.client.conversations();
    if (!mounted) return;
    setState(() => _convs = list);
    // The watch offers these as switch targets, so it needs the same list the
    // inbox shows rather than a separately-fetched one that could disagree.
    WatchBridge.instance.pushConversations(list);
  }

  // ── Alerts (tone + speech), mirroring the web ──────────────────────────────
  Future<void> _playTone() async {
    try {
      await _player.setAsset('assets/sounds/message.wav');
      unawaited(_player.play());
    } catch (_) {}
  }

  /// Speak a message, announcing the sender first — whoever is listening usually
  /// is not looking at the screen, so the text alone leaves them without a caller.
  /// senderLabel resolves a mobile through its display_id, giving "CRD Stanton".
  ///
  /// Through the shared Speaker, which owns the app's only text-to-speech engine.
  /// This screen used to own a second one; both would have contended for the same
  /// audio session now that the map screen speaks messages from the background.
  Future<void> _speakMessage(MsgMessage m) {
    if (!_speak) return Future.value();
    // Through the shared queue, so this cannot start on top of a radio clip and it
    // inherits the five-minute rule with everything else.
    AudioQueue.instance.addSpeech(ts: m.ts, senderLabel: m.senderLabel, text: m.text);
    return Future.value();
  }

  void _speakDeferred(int convId) {
    if (!_speak || _deferredSpeak.isEmpty) return;
    for (final m in _messages) {
      if (_deferredSpeak.remove(m.id)) _speakMessage(m);
    }
  }

  // ── Live poll ──────────────────────────────────────────────────────────────
  Future<void> _poll() async {
    final res = await widget.client.poll(_lastId);
    if (!mounted) return;
    for (final r in res.receipts) {
      _receipts[r.messageId] = r;
    }
    if (res.lastId > _lastId) _lastId = res.lastId;
    if (res.messages.isNotEmpty) {
      for (final m in res.messages) {
        _ingest(m);
      }
      _loadConversations();
    } else if (res.receipts.isNotEmpty) {
      setState(() {});
    }
    // Cleared after the batch, not before: everything this first poll returned is
    // history and must be absorbed silently, and only what arrives afterwards is new.
    _primingSeen = false;
  }

  /// True until the first poll after this screen opens has been absorbed.
  ///
  /// `_lastId` is in-memory and starts at 0, so that first poll returns everything
  /// already delivered to this device — history, not arrivals. Treating it as arrivals
  /// meant re-announcing messages the operator had already heard: the map screen speaks
  /// a message when it lands, and then opening Messages queued the very same message
  /// for deferred speech, so tapping the conversation read it out a second time.
  ///
  /// The first pass therefore only records what exists. Nothing is spoken, no tone is
  /// played, and nothing is relayed to the watch — it has seen these too.
  bool _primingSeen = true;

  void _ingest(MsgMessage m) {
    final isNew = _seen.add(m.id);
    if (!isNew) return;
    if (_primingSeen) return;
    // The watch is a separate device and must see every message the phone does.
    // This path is not interchangeable with the background session's: polling here
    // marks the message delivered, and the legacy feed only returns what is still
    // undelivered -- so anything this screen sees first, it sees exclusively.
    WatchBridge.instance.pushSeenInChat(m, isSelf: m.fromId == _myId);
    // The wrist announces when it can, and this screen is no exception. It is a
    // third place the phone can make a sound, and it was the one that had not been
    // told — so with the chat open and the watch awake, the same message was read
    // aloud twice. The message is on screen here anyway; the operator loses nothing
    // by hearing it from their arm.
    final wristHasIt = WatchBridge.instance.watchWillAnnounce;
    final inOpen = _open != null && _open!.id == m.conversationId;
    if (inOpen) {
      setState(() => _messages.add(m));
      _markRead([m.id]);
      _scrollToEnd();
      if (wristHasIt) return;
      if (_speak) {
        _speakMessage(m);
      } else {
        _playTone();
      }
    } else {
      if (wristHasIt) return;
      if (_speak) _deferredSpeak.add(m.id);
      _playTone();
    }
  }

  /// Whether anything will be audible when a message arrives — speech for messages
  /// sent to you, or the radio playing. Drives the app-bar icon, so a glance says
  /// whether this phone is going to make a noise, without opening the sheet.
  bool get _audible => _speak || MonitorService.instance.playingRadioAudio;

  Future<void> _markRead(List<int> ids) => widget.client.read(ids);

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtl.hasClients) _scrollCtl.jumpTo(_scrollCtl.position.maxScrollExtent);
    });
  }

  // ── Open / leave a thread ──────────────────────────────────────────────────
  Future<void> _openConversation(MsgConversation c) async {
    setState(() {
      _open = c;
      _pendingRecipients = null;
      _messages = [];
    });
    final msgs = await widget.client.thread(c.id);
    if (!mounted || _open?.id != c.id) return;
    for (final m in msgs) {
      _seen.add(m.id);
    }
    setState(() => _messages = msgs);
    _scrollToEnd();
    final unread = msgs.where((m) => m.fromId != _myId).map((m) => m.id).toList();
    if (unread.isNotEmpty) _markRead(unread);
    _speakDeferred(c.id);
    // Deliberately does NOT re-aim the watch. Opening a thread is browsing, not
    // choosing: during a net an operator reads several threads on the phone, and if
    // each one silently became the wrist's reply target, the next thing they said
    // would go wherever they last glanced. Only an explicit act sets it -- the
    // recipient picker here, or the Reply to page on the watch.
    _loadConversations();
  }

  void _startNew(List<String> recipients, String label) {
    setState(() {
      _open = MsgConversation(id: -1, kind: recipients.length > 1 ? 'group' : 'direct', title: label, unread: 0, lastId: 0, members: const []);
      _pendingRecipients = recipients;
      _messages = [];
    });
  }

  void _backToInbox() {
    setState(() {
      _open = null;
      _pendingRecipients = null;
      _messages = [];
    });
    _loadConversations();
  }

  // ── Send ────────────────────────────────────────────────────────────────────
  Future<void> _send() async {
    final text = _composeCtl.text.trim();
    final photo = _pendingPhotoPath;
    if ((text.isEmpty && photo == null) || _sending) return;
    setState(() => _sending = true);
    SendResult res;
    if (_pendingRecipients != null) {
      res = await widget.client.send(recipients: _pendingRecipients, text: text, photoPath: photo);
    } else if (_open != null && _open!.id > 0) {
      res = await widget.client.send(conversationId: _open!.id, text: text, photoPath: photo);
    } else {
      setState(() => _sending = false);
      return;
    }
    if (!mounted) return;
    setState(() => _sending = false);
    if (!res.ok) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res.error ?? 'Send failed'), backgroundColor: Colors.red[700]));
      return;
    }
    _composeCtl.clear();
    setState(() => _pendingPhotoPath = null);
    final cid = res.conversationId;
    if (cid != null) {
      // Reload the (now real) conversation + thread.
      await _loadConversations();
      final msgs = await widget.client.thread(cid);
      if (!mounted) return;
      for (final m in msgs) {
        _seen.add(m.id);
      }
      final c = _convs.firstWhere(
        (x) => x.id == cid,
        orElse: () => MsgConversation(id: cid, kind: _open?.kind ?? 'direct', title: _open?.title, unread: 0, lastId: 0, members: _open?.members ?? const []),
      );
      setState(() {
        _open = c;
        _pendingRecipients = null;
        _messages = msgs;
      });
      _scrollToEnd();
    }
  }

  Future<void> _toggleSpeak() async {
    setState(() => _speak = !_speak);
    final p = await SharedPreferences.getInstance();
    await p.setBool('aprs_msg_speak', _speak);
    WatchBridge.instance.pushSpeak(_speak);
    if (!_speak) {
      _deferredSpeak.clear();
      AudioQueue.instance.cancelAll();
    }
  }

  // ── Monitoring the whole event ─────────────────────────────────────────────

  /// Three independent choices, and they are independent on purpose.
  ///
  /// Following the event as text costs almost nothing; the audio is the part that
  /// costs cellular data, so it is never implied by either of the others. Nothing is
  /// ever pushed to a device that did not ask — the feed carries a flag, and a phone
  /// with audio off simply never makes the request.
  Future<void> _openMonitorSettings() async {
    final m = MonitorService.instance;
    // isScrollControlled + a scroll view, because the default sheet is only as tall as
    // it feels like being and simply clips whatever does not fit — with no scrollbar and
    // no way to reach it. The last two rows were invisible on an iPhone, which is the
    // sort of thing that looks like a missing feature rather than a layout bug.
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => SafeArea(
          child: ConstrainedBox(
            // Not the full height: leaving the top of the screen visible keeps it
            // reading as a sheet over the messages rather than a new page.
            constraints: BoxConstraints(
                maxHeight: MediaQuery.of(ctx).size.height * 0.85),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const ListTile(
                    title: Text('Sound', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                  SwitchListTile(
                    secondary: const Icon(Icons.volume_up),
                    title: const Text('Read my messages aloud'),
                    subtitle: const Text(
                      'Messages sent to you are spoken as they arrive. Turn this off and '
                      'they arrive with a tone instead.',
                    ),
                    value: _speak,
                    onChanged: (v) async {
                      await _toggleSpeak();
                      setSheet(() {});
                      if (mounted) setState(() {});
                    },
                  ),
                  const Divider(height: 1),
                  const ListTile(
                    title: Text('Follow the whole event',
                        style: TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text(
                      'Normally you only get what was sent to you. These two add the rest. '
                      'Neither one ever buzzes or alerts you — this is for listening in, '
                      'not for being interrupted.',
                    ),
                  ),
                  const Divider(height: 1),
                  SwitchListTile(
                    secondary: const Icon(Icons.radio),
                    title: const Text('Listen to the radio'),
                    subtitle: const Text(
                      'Plays the off-air recording of each transmission a few seconds '
                      'after it ends — the operators\' actual voices, not a computer '
                      'reading a transcript. Uses cellular data.',
                    ),
                    value: m.playingRadioAudio,
                    onChanged: (v) async {
                      await m.setRadioAudio(v);
                      setSheet(() {});
                      if (mounted) setState(() {});
                    },
                  ),
                  const Divider(height: 1),
                  SwitchListTile(
                    secondary: const Icon(Icons.forum_outlined),
                    title: const Text("See everyone's messages"),
                    subtitle: const Text(
                      'Every message sent in this event, whoever it came from and whoever '
                      'it was meant for.',
                    ),
                    value: m.monitoringAll,
                    onChanged: (v) async {
                      await m.setAll(v);
                      setSheet(() {});
                      if (mounted) setState(() {});
                    },
                  ),
                  SwitchListTile(
                    secondary: const Icon(Icons.record_voice_over_outlined),
                    title: const Text('Read those messages aloud'),
                    subtitle: Text(
                      m.monitoringAll
                          ? 'A synthesised voice speaks each one as it arrives, so you can '
                            'keep your hands and eyes on something else.'
                          : "Turn on \"See everyone's messages\" first.",
                    ),
                    value: m.speakingAll && m.monitoringAll,
                    onChanged: m.monitoringAll
                        ? (v) async {
                            await m.setSpeakAll(v);
                            setSheet(() {});
                            if (mounted) setState(() {});
                          }
                        : null,
                  ),
                  const SizedBox(height: 8),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ── Recipient picker (new message) ─────────────────────────────────────────
  Future<void> _openPicker() async {
    final pr = await widget.client.participants();
    if (!mounted) return;
    final chosen = await showModalBottomSheet<List<MsgParticipant>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(12))),
      builder: (_) => _RecipientPicker(people: pr.participants, initialKeys: _lastRecipients),
    );
    if (chosen == null || chosen.isEmpty) return;
    final keys = chosen.map((p) => p.key).toList();
    final label = chosen.map((p) => p.label).join(', ');
    setState(() => _lastRecipients = keys);
    unawaited(_saveLastRecipients(keys));
    WatchBridge.instance.setDestination(recipients: keys, label: label);
    _startNew(keys, label);
  }

  // ── UI ──────────────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    final inThread = _open != null;
    // Keep the window open until the user taps Close: the system back gesture
    // only steps a thread back to the inbox; it never dismisses messaging.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_monitorOpen) { setState(() => _monitorOpen = false); return; }
        if (_open != null) _backToInbox();
      },
      child: Scaffold(
        appBar: AppBar(
          backgroundColor: _kDark,
          foregroundColor: Colors.white,
          titleSpacing: (inThread || _monitorOpen) ? 0 : null,
          title: Text(
              _monitorOpen
                  ? (MonitorService.instance.monitoringAll ? 'Everyone’s traffic' : 'Radio')
                  : (inThread ? _open!.label : 'Messages'),
              overflow: TextOverflow.ellipsis),
          // On the inbox there is nothing to go back TO — Close is the way out. Without
          // this the AppBar auto-inserts a back arrow that calls maybePop(), which
          // PopScope(canPop: false) swallows, leaving a visible button that does nothing.
          automaticallyImplyLeading: false,
          leading: _monitorOpen
              ? IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to conversations',
                  onPressed: () => setState(() => _monitorOpen = false))
              : inThread
                  ? IconButton(icon: const Icon(Icons.arrow_back), tooltip: 'Back to conversations', onPressed: _backToInbox)
                  : null,
          actions: [
            // One speaker, not two. This was a mute toggle beside a separate ear icon
            // for the monitor sheet — but every setting behind both of them is about
            // sound, and two audio icons side by side made neither obvious. The sheet
            // now owns all of it, including mute, and this opens the sheet.
            //
            // It still shows at a glance whether anything is audible: filled when this
            // device will make a sound for an arriving message, crossed out when it
            // will not.
            IconButton(
              tooltip: 'Sound and monitoring',
              icon: Icon(_audible ? Icons.volume_up : Icons.volume_off),
              onPressed: _openMonitorSettings,
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: TextButton.icon(
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close, size: 18),
                label: const Text('Close'),
                style: TextButton.styleFrom(foregroundColor: Colors.white, backgroundColor: Colors.white24),
              ),
            ),
          ],
        ),
        body: Stack(children: [
          _monitorOpen ? _buildMonitor() : (inThread ? _buildThread() : _buildInbox()),
          const Align(alignment: Alignment.bottomCenter, child: AudioQueueBar()),
        ]),
        floatingActionButton: (inThread || _monitorOpen)
            ? null
            : FloatingActionButton.extended(
                backgroundColor: _kBlue,
                // Without this the label and icon inherit a dark theme colour and
                // came out near-black on the blue fill. White on _kBlue matches the
                // app's other filled buttons.
                foregroundColor: Colors.white,
                onPressed: _openPicker,
                icon: const Icon(Icons.edit),
                label: const Text('New message', style: TextStyle(fontWeight: FontWeight.w600)),
              ),
      ),
    );
  }

  /// The way into the monitor log, at the top of the inbox and only when something is
  /// actually being followed.
  ///
  /// Deliberately not a conversation row, and it does not look like one. Monitored
  /// traffic has no thread to reply into, and presenting it as one would invite exactly
  /// the mistake the rest of this feature avoids: answering a question that was asked
  /// of somebody else.
  Widget _monitorEntry() {
    final mon = MonitorService.instance;
    final n = mon.recent.length;
    final radioOnly = mon.playingRadioAudio && !mon.monitoringAll;
    return Material(
      color: _kDark.withValues(alpha: 0.06),
      child: ListTile(
        leading: const Icon(Icons.hearing, color: _kDark),
        title: Text(radioOnly ? 'Radio' : 'Everyone’s traffic',
            style: const TextStyle(fontWeight: FontWeight.w600, color: _kDark)),
        subtitle: Text(n == 0
            ? 'Listening — nothing yet'
            : '$n recent${mon.skippedTotal > 0 ? ' · ${mon.skippedTotal} skipped' : ''}'),
        trailing: const Icon(Icons.chevron_right, color: _kDark),
        onTap: () => setState(() => _monitorOpen = true),
      ),
    );
  }

  /// Everything being monitored, oldest at the top, newest at the bottom — the order a
  /// net happened in, which is the order somebody reading back wants it.
  Widget _buildMonitor() {
    final items = MonitorService.instance.recent;
    if (items.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'Nothing yet.\n\nThis fills as traffic arrives. It shows every message in '
            'the event and what the receivers heard — none of it addressed to you, and '
            'none of it will alert you.',
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.grey),
          ),
        ),
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.symmetric(vertical: 4),
      itemCount: items.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (_, i) => _monitorRow(items[items.length - 1 - i]),
    );
  }

  Widget _monitorRow(MsgMessage m) {
    // An audio-first entry has no words yet: the recording is posted the moment the
    // over ends and the transcription follows a few seconds later, and some never get
    // one because it was discarded as a hallucination. Saying so is better than an
    // empty row that looks like a bug.
    final text = m.text.trim();
    final body = text.isNotEmpty
        ? text
        : (m.hasAudio ? 'Recording — no transcription' : '');
    return ListTile(
      dense: true,
      title: Row(children: [
        Expanded(
          child: Text(
            m.isRadio ? '📻 ${m.senderLabel}' : m.senderLabel,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: _kDark),
            overflow: TextOverflow.ellipsis,
          ),
        ),
        if ((m.toLabel ?? '').isNotEmpty)
          Text('→ ${m.toLabel}',
              style: const TextStyle(fontSize: 11, color: Colors.grey)),
        const SizedBox(width: 8),
        Text(_clockTime(m.ts), style: const TextStyle(fontSize: 11, color: Colors.grey)),
      ]),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (body.isNotEmpty)
          Text(body,
              style: TextStyle(
                  fontSize: 14,
                  color: text.isEmpty ? Colors.grey : Colors.black87,
                  fontStyle: text.isEmpty ? FontStyle.italic : FontStyle.normal)),
        if (m.hasAudio) _bubbleAudio(m),
      ]),
    );
  }

  Widget _buildInbox() {
    final items = _convs.where((c) => c.lastId > 0).toList()..sort((a, b) => b.lastId.compareTo(a.lastId));
    final monitoring = MonitorService.instance.enabled;
    if (items.isEmpty && !monitoring) {
      return const Center(child: Padding(padding: EdgeInsets.all(24), child: Text('No conversations yet.\nTap “New message” to start one.', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey))));
    }
    // The monitor entry is row zero when anything is being followed, so the count is
    // visible without opening it.
    final lead = monitoring ? 1 : 0;
    return RefreshIndicator(
      onRefresh: _loadConversations,
      child: ListView.separated(
        itemCount: items.length + lead,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (_, idx) {
          if (monitoring && idx == 0) return _monitorEntry();
          final i = idx - lead;
          final c = items[i];
          final pv = c.preview;
          return ListTile(
            title: Text(c.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: pv == null ? null : Text('${pv.self ? 'You: ' : ''}${pv.text}', maxLines: 1, overflow: TextOverflow.ellipsis),
            trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(pv == null ? '' : _shortTime(pv.ts), style: const TextStyle(fontSize: 11, color: Colors.grey)),
              if (c.unread > 0) ...[
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(color: const Color(0xFFC0392B), borderRadius: BorderRadius.circular(9)),
                  child: Text('${c.unread}', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                ),
              ],
            ]),
            onTap: () => _openConversation(c),
          );
        },
      ),
    );
  }

  Widget _buildThread() {
    return Column(children: [
      Expanded(
        child: Container(
          color: const Color(0xFFF4F6F8),
          child: _messages.isEmpty
              ? const Center(child: Text('No messages yet.', style: TextStyle(color: Colors.grey)))
              : ListView.builder(
                  controller: _scrollCtl,
                  padding: const EdgeInsets.all(10),
                  itemCount: _messages.length,
                  itemBuilder: (_, i) => _bubble(_messages[i]),
                ),
        ),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            if (_pendingPhotoPath != null) _photoPreviewStrip(),
            Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              IconButton(
                icon: const Icon(Icons.add_photo_alternate_outlined),
                color: _kBlue,
                tooltip: 'Attach photo',
                onPressed: _sending ? null : _attachPhoto,
              ),
              Expanded(
                child: TextField(
                  controller: _composeCtl,
                  maxLength: 280,
                  minLines: 1,
                  maxLines: 4,
                  textInputAction: TextInputAction.newline,
                  decoration: const InputDecoration(hintText: 'Type a message…', border: OutlineInputBorder(), counterText: '', isDense: true, contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10)),
                ),
              ),
              const SizedBox(width: 6),
              _sending
                  ? const Padding(padding: EdgeInsets.all(10), child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)))
                  : IconButton.filled(style: IconButton.styleFrom(backgroundColor: _kBlue), icon: const Icon(Icons.send), onPressed: _send),
            ]),
          ]),
        ),
      ),
    ]);
  }

  // Thumbnail of the photo staged in the composer, with a remove button.
  Widget _photoPreviewStrip() {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Stack(children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Image.file(File(_pendingPhotoPath!), width: 84, height: 84, fit: BoxFit.cover),
          ),
          Positioned(
            top: -6, right: -6,
            child: IconButton(
              icon: const Icon(Icons.cancel, size: 22, color: Colors.black54),
              tooltip: 'Remove photo',
              onPressed: () => setState(() => _pendingPhotoPath = null),
            ),
          ),
        ]),
      ),
    );
  }

  // Let the user pick a photo from the camera or their library, downscaled and
  // compressed on-device (the server doesn't have GD to re-encode).
  Future<void> _attachPhoto() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera), title: const Text('Take a photo'), onTap: () => Navigator.pop(ctx, ImageSource.camera)),
          ListTile(leading: const Icon(Icons.photo_library), title: const Text('Choose from library'), onTap: () => Navigator.pop(ctx, ImageSource.gallery)),
        ]),
      ),
    );
    if (source == null) return;
    try {
      final x = await _picker.pickImage(source: source, maxWidth: 1600, maxHeight: 1600, imageQuality: 82);
      if (x != null && mounted) setState(() => _pendingPhotoPath = x.path);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Could not attach photo: $e'), backgroundColor: Colors.red[700]));
    }
  }

  // Full-screen, pinch-to-zoom viewer for a photo tapped in a bubble.
  void _openPhotoViewer(String url) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, elevation: 0),
        body: Center(
          child: InteractiveViewer(
            maxScale: 5,
            child: Image.network(url, fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Text('Could not load photo', style: TextStyle(color: Colors.white70))),
          ),
        ),
      ),
    ));
  }

  Widget _bubble(MsgMessage m) {
    final me = m.fromId == _myId;
    final rec = me ? _receipts[m.id] : null;
    return Align(
      alignment: me ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.78),
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.fromLTRB(10, 6, 10, 5),
        decoration: BoxDecoration(
          color: me ? _kBlue : Colors.white,
          borderRadius: BorderRadius.circular(12),
          boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 1, offset: Offset(0, 1))],
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (!me) Padding(padding: const EdgeInsets.only(bottom: 2), child: Text(m.senderLabel, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: _kDark))),
          if (m.hasPhoto) _bubblePhoto(m),
          if (m.text.isNotEmpty)
            Padding(
              padding: EdgeInsets.only(top: m.hasPhoto ? 6 : 0),
              child: Text(m.text, style: TextStyle(fontSize: 14, color: me ? Colors.white : Colors.black87)),
            ),
          // Unconditional, unlike the auto-play setting: tapping this IS the request,
          // so it needs no opt-in. Nothing is fetched until the tap.
          if (m.hasAudio) _bubbleAudio(m),
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              _clockTime(m.ts)
                  // Who it went to, but only where that is not obvious. In a thread
                  // you opened, everything went to the same people; monitored traffic
                  // is the case where half of it was addressed to somebody else.
                  + (m.monitored && (m.toLabel ?? '').isNotEmpty ? '  → ${m.toLabel}' : '')
                  + (me ? '   ${_ackLabel(rec)}' : ''),
              style: TextStyle(fontSize: 10, color: me ? Colors.white70 : Colors.grey),
            ),
          ),
        ]),
      ),
    );
  }

  /// "Play 4s" for a radio entry that has its recording.
  ///
  /// Fetched on tap, never ahead of time. That is the difference between a hands-free
  /// phone costing a few kB an hour and costing a few MB: the transcription is what
  /// you follow, and the audio answers "what did they actually say" about the one
  /// line in fifty that came out garbled. Downloading the other forty-nine is pure
  /// cellular data spent on clips nobody will play.
  Widget _bubbleAudio(MsgMessage m) {
    final url = MessagingClient.audioUrl(m);
    if (url == null) return const SizedBox.shrink();
    final playing = _playingAudioId == m.id;
    final secs = m.audioSecs;
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: InkWell(
        onTap: () => _playClip(m.id, url),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(playing ? Icons.stop_circle_outlined : Icons.play_circle_outline,
              size: 20, color: _kBlue),
          const SizedBox(width: 4),
          Text(
            playing ? 'Playing…' : (secs != null ? 'Play ${secs.round()}s' : 'Play'),
            style: const TextStyle(fontSize: 12, color: _kBlue, fontWeight: FontWeight.w500),
          ),
        ]),
      ),
    );
  }

  int? _playingAudioId;

  Future<void> _playClip(int id, String url) async {
    if (_playingAudioId == id) {
      await _player.stop();
      if (mounted) setState(() => _playingAudioId = null);
      return;
    }
    setState(() => _playingAudioId = id);
    try {
      // just_audio caches by URL, and the clip is served immutable, so replaying one
      // costs nothing after the first fetch.
      await _player.setUrl(url);
      await _player.play();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Could not play that recording'),
          duration: Duration(seconds: 2),
        ));
      }
    }
    if (mounted) setState(() => _playingAudioId = null);
  }

  // Attached-photo thumbnail inside a bubble; tap opens the full-screen viewer.
  Widget _bubblePhoto(MsgMessage m) {
    final url = widget.client.photoUrl(m.id);
    final ar = (m.photoW != null && m.photoH != null && m.photoH! > 0) ? m.photoW! / m.photoH! : 4 / 3;
    return GestureDetector(
      onTap: () => _openPhotoViewer(url),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 220, maxHeight: 260),
          child: AspectRatio(
            aspectRatio: ar,
            child: Image.network(
              url,
              fit: BoxFit.cover,
              loadingBuilder: (ctx, child, prog) => prog == null
                  ? child
                  : Container(color: Colors.black12, alignment: Alignment.center, child: const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))),
              errorBuilder: (_, __, ___) => Container(color: Colors.black12, alignment: Alignment.center, padding: const EdgeInsets.all(16), child: const Icon(Icons.broken_image, color: Colors.black38)),
            ),
          ),
        ),
      ),
    );
  }

  // Delivery acknowledgement for a message I sent. "Sent" means it's queued and
  // will be delivered when the recipient is next online.
  String _ackLabel(MsgReceipt? r) {
    if (r == null || r.total == 0) return 'Sent';
    if (r.read > 0) return r.total > 1 ? 'Read by ${r.read} of ${r.total}' : 'Read ✓✓';
    if (r.delivered > 0) return r.total > 1 ? 'Delivered to ${r.delivered} of ${r.total}' : 'Delivered ✓';
    return 'Sent';
  }

  String _clockTime(int ts) {
    final d = DateTime.fromMillisecondsSinceEpoch(ts * 1000);
    return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  String _shortTime(int ts) {
    final d = DateTime.fromMillisecondsSinceEpoch(ts * 1000);
    final now = DateTime.now();
    if (d.year == now.year && d.month == now.month && d.day == now.day) return _clockTime(ts);
    return '${d.month}/${d.day}';
  }
}

// ── Recipient picker sheet ────────────────────────────────────────────────────
class _RecipientPicker extends StatefulWidget {
  final List<MsgParticipant> people;
  /// Recipient keys from the last message sent, pre-ticked on open.
  final List<String> initialKeys;
  const _RecipientPicker({required this.people, this.initialKeys = const []});
  @override
  State<_RecipientPicker> createState() => _RecipientPickerState();
}

class _RecipientPickerState extends State<_RecipientPicker> {
  final Set<int> _sel = {};

  @override
  void initState() {
    super.initState();
    // Restore the previous selection. Keys are resolved against the CURRENT roster,
    // so anyone who has since gone offline is simply dropped rather than selecting
    // someone unreachable. A remembered "(multiple)" expands to its people, which is
    // what keeps the group row ticked and individually adjustable.
    for (final k in widget.initialKeys) {
      if (k.startsWith('mult:')) {
        for (final p in _members(k.substring(5), widget.people)) {
          _sel.add(p.id);
        }
      } else {
        final p = widget.people.where((x) => !x.isMultiple && x.key == k).firstOrNull;
        if (p != null) _sel.add(p.id);
      }
    }
  }

  /// The individual people at a station (never the "(multiple)" row itself).
  List<MsgParticipant> _members(String groupId, List<MsgParticipant> all) => all
      .where((p) => p.kind != 'operator' && !p.isMultiple && p.groupId == groupId)
      .toList();

  bool _stationAllChecked(String groupId, List<MsgParticipant> all) {
    final m = _members(groupId, all);
    return m.isNotEmpty && m.every((p) => _sel.contains(p.id));
  }

  /// Tapping a "(multiple)" row selects or clears its whole station. The selection
  /// itself only ever holds individuals, which is what lets one be unchecked
  /// afterwards without disturbing the others.
  void _toggle(MsgParticipant p, List<MsgParticipant> all) {
    if (p.isMultiple) {
      final m   = _members(p.groupId, all);
      final on  = _stationAllChecked(p.groupId, all);
      for (final x in m) {
        on ? _sel.remove(x.id) : _sel.add(x.id);
      }
      return;
    }
    _sel.contains(p.id) ? _sel.remove(p.id) : _sel.add(p.id);
  }

  /// Chosen recipients, with any fully-checked station collapsed back to its
  /// "(multiple)" row so the message lands in that station's own stable thread
  /// instead of an ad-hoc group of the same people.
  List<MsgParticipant> _chosen(List<MsgParticipant> all) {
    final picked  = all.where((p) => !p.isMultiple && _sel.contains(p.id)).toList();
    final out     = <MsgParticipant>[];
    final covered = <int>{};
    for (final m in all.where((p) => p.isMultiple)) {
      final mem = _members(m.groupId, all);
      if (mem.length > 1 && mem.every((x) => _sel.contains(x.id))) {
        out.add(m);
        covered.addAll(mem.map((x) => x.id));
      }
    }
    out.addAll(picked.where((p) => !covered.contains(p.id)));
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final list = widget.people.toList();
    // Operators first, then mobiles alphabetically by station ID and name. Each
    // "<ID> (multiple)" sorts to the head of its own ID group so it sits directly
    // above that station's people instead of drifting elsewhere in the list.
    int byText(String a, String b) => a.toLowerCase().compareTo(b.toLowerCase());
    list.sort((a, b) {
      if (a.kind != b.kind) return a.kind == 'operator' ? -1 : 1;
      if (a.kind == 'operator') return byText(a.name, b.name);
      final g = byText(a.groupId, b.groupId);
      if (g != 0) return g;
      if (a.isMultiple != b.isMultiple) return a.isMultiple ? -1 : 1;
      return byText(a.name, b.name);
    });
    // Stations that have a "(multiple)" row — their people get indented under it.
    final multIds = widget.people.where((p) => p.isMultiple).map((p) => p.groupId).toSet();
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Padding(padding: EdgeInsets.fromLTRB(16, 12, 16, 4), child: Align(alignment: Alignment.centerLeft, child: Text('New message', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)))),
        Flexible(
          child: list.isEmpty
              ? const Padding(padding: EdgeInsets.all(24), child: Text('No one available', style: TextStyle(color: Colors.grey)))
              : ListView.builder(
                  shrinkWrap: true,
                  itemCount: list.length,
                  itemBuilder: (_, i) {
                    final p = list[i];
                    // A "(multiple)" row shows checked only while every person at
                    // that station is checked, so unchecking one leaves the rest
                    // selected and simply clears the group row.
                    final sel = p.isMultiple ? _stationAllChecked(p.groupId, widget.people) : _sel.contains(p.id);
                    final sub = p.subtitle;
                    // Rule between the operators and the trackers. The list is sorted
                    // operators-first, so the boundary is wherever the kind changes.
                    final rule = i > 0 && list[i - 1].kind == 'operator' && p.kind != 'operator';
                    // A station's rows read as one cluster: the people under a
                    // "<ID> (multiple)" are indented beneath it, and a small gap
                    // separates one station from the next.
                    final child = p.kind != 'operator' && !p.isMultiple && multIds.contains(p.groupId);
                    final newGroup = !rule && i > 0 && p.kind != 'operator'
                        && list[i - 1].kind != 'operator' && list[i - 1].groupId != p.groupId;
                    // A compact hand-built row rather than CheckboxListTile, which
                    // bottoms out at visualDensity -4 and still reserves far more
                    // height than these one-line entries need.
                    final tile = InkWell(
                      onTap: () => setState(() => _toggle(p, widget.people)),
                      child: Padding(
                        padding: EdgeInsets.only(left: child ? 22 : 4, right: 12, top: 1, bottom: 1),
                        child: Row(children: [
                          SizedBox(
                            width: 34,
                            child: Checkbox(
                              value: sel,
                              onChanged: (_) => setState(() => _toggle(p, widget.people)),
                              visualDensity: const VisualDensity(horizontal: -4, vertical: -4),
                              materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            ),
                          ),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(p.label,
                                    maxLines: 1, overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14, height: 1.2)),
                                if (sub.isNotEmpty)
                                  Text(sub,
                                      maxLines: 1, overflow: TextOverflow.ellipsis,
                                      style: TextStyle(fontSize: 11, height: 1.2, color: Colors.grey.shade600)),
                              ],
                            ),
                          ),
                          // Presence reads better as a word than as a colour-only dot,
                          // which carries no meaning for a colour-blind operator.
                          // Online is the signal worth spotting, so it keeps full
                          // colour and weight while Offline is muted and unbolded.
                          if (p.showsPresence)
                            Text(p.online ? 'Online' : 'Offline',
                                style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: p.online ? FontWeight.w600 : FontWeight.w400,
                                    color: p.online ? const Color(0xFF1B8A3A) : const Color(0xFFC9938C))),
                        ]),
                      ),
                    );
                    if (rule) {
                      return Column(mainAxisSize: MainAxisSize.min,
                          children: [const Divider(height: 1, thickness: 1), tile]);
                    }
                    if (newGroup) {
                      return Column(mainAxisSize: MainAxisSize.min,
                          children: [const SizedBox(height: 6), tile]);
                    }
                    return tile;
                  },
                ),
        ),
        // The bottom inset is the sheet's own to add: showModalBottomSheet(useSafeArea:
        // true) inserts SafeArea(bottom: false), freeing that edge on purpose so a sheet
        // can run to the screen edge. Without it this row sits under Android's gesture
        // bar, and it is the only way to commit the selection. MediaQuery.padding
        // already drops to zero when the keyboard consumes the inset, so this does not
        // double up with the viewInsets padding above.
        Padding(
          padding: EdgeInsets.fromLTRB(12, 12, 12, 12 + MediaQuery.of(context).padding.bottom),
          child: Row(children: [
            // Explicit Cancel: swiping the sheet down is the only other way out and
            // is easy to miss. Returning null (not an empty list) leaves the last
            // remembered selection untouched.
            Expanded(
              child: OutlinedButton(
                onPressed: () => Navigator.pop(context, null),
                style: OutlinedButton.styleFrom(
                  foregroundColor: _kDark,
                  side: BorderSide(color: Colors.grey.shade400),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
                child: const Text('Cancel'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              flex: 2,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: _kBlue,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
                onPressed: _sel.isEmpty ? null : () => Navigator.pop(context, _chosen(widget.people)),
                child: Text(_sel.length > 1 ? 'Start group (${_sel.length})' : 'Start conversation'),
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}
