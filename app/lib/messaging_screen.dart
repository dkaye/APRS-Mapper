/// Chat screen for the redesigned messaging system (mirrors the web operator
/// panel): a conversation list (inbox) → thread → always-visible composer, with
/// any-to-any + group recipients, live polling, delivery/read receipts, and an
/// optional read-aloud (text-to-speech) toggle.
import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:just_audio/just_audio.dart';
import 'package:flutter_tts/flutter_tts.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'messaging_client.dart';

const _kBlue = Color(0xFF2980B9);
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
  final _tts = FlutterTts();
  final _picker = ImagePicker();
  String? _pendingPhotoPath; // photo staged in the composer, not yet sent
  bool _speak = false;

  @override
  void initState() {
    super.initState();
    MessagingScreen.isOpen = true;
    _restoreSpeak();
    _bootstrap();
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
  }

  @override
  void dispose() {
    MessagingScreen.isOpen = false;
    _pollTimer?.cancel();
    _composeCtl.dispose();
    _scrollCtl.dispose();
    _player.dispose();
    _tts.stop();
    super.dispose();
  }

  Future<void> _restoreSpeak() async {
    final p = await SharedPreferences.getInstance();
    if (mounted) setState(() => _speak = p.getBool('aprs_msg_speak') ?? false);
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
  }

  // ── Alerts (tone + speech), mirroring the web ──────────────────────────────
  Future<void> _playTone() async {
    try {
      await _player.setAsset('assets/sounds/message.wav');
      unawaited(_player.play());
    } catch (_) {}
  }

  Future<void> _speakText(String text) async {
    if (!_speak || text.trim().isEmpty) return;
    try {
      await _tts.setSpeechRate(0.5);
      unawaited(_tts.speak(text));
    } catch (_) {}
  }

  void _speakDeferred(int convId) {
    if (!_speak || _deferredSpeak.isEmpty) return;
    for (final m in _messages) {
      if (_deferredSpeak.remove(m.id)) _speakText(m.text);
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
  }

  void _ingest(MsgMessage m) {
    final isNew = _seen.add(m.id);
    if (!isNew) return;
    final inOpen = _open != null && _open!.id == m.conversationId;
    if (inOpen) {
      setState(() => _messages.add(m));
      _markRead([m.id]);
      _scrollToEnd();
      if (_speak) {
        _speakText(m.text);
      } else {
        _playTone();
      }
    } else {
      if (_speak) _deferredSpeak.add(m.id);
      _playTone();
    }
  }

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
    if (!_speak) {
      _deferredSpeak.clear();
      _tts.stop();
    }
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
      builder: (_) => _RecipientPicker(people: pr.participants),
    );
    if (chosen == null || chosen.isEmpty) return;
    _startNew(chosen.map((p) => p.key).toList(), chosen.map((p) => p.label).join(', '));
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
        if (_open != null) _backToInbox();
      },
      child: Scaffold(
        appBar: AppBar(
          backgroundColor: _kDark,
          foregroundColor: Colors.white,
          titleSpacing: inThread ? 0 : null,
          title: Text(inThread ? _open!.label : 'Messages', overflow: TextOverflow.ellipsis),
          leading: inThread ? IconButton(icon: const Icon(Icons.arrow_back), tooltip: 'Back to conversations', onPressed: _backToInbox) : null,
          actions: [
            IconButton(
              tooltip: _speak ? 'Reading messages aloud — tap to mute' : 'Read arriving messages aloud',
              icon: Icon(_speak ? Icons.volume_up : Icons.volume_off),
              onPressed: _toggleSpeak,
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
        body: inThread ? _buildThread() : _buildInbox(),
        floatingActionButton: inThread
            ? null
            : FloatingActionButton.extended(
                backgroundColor: _kBlue,
                onPressed: _openPicker,
                icon: const Icon(Icons.edit),
                label: const Text('New message'),
              ),
      ),
    );
  }

  Widget _buildInbox() {
    final items = _convs.where((c) => c.lastId > 0).toList()..sort((a, b) => b.lastId.compareTo(a.lastId));
    if (items.isEmpty) {
      return const Center(child: Padding(padding: EdgeInsets.all(24), child: Text('No conversations yet.\nTap “New message” to start one.', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey))));
    }
    return RefreshIndicator(
      onRefresh: _loadConversations,
      child: ListView.separated(
        itemCount: items.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (_, i) {
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
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              _clockTime(m.ts) + (me ? '   ${_ackLabel(rec)}' : ''),
              style: TextStyle(fontSize: 10, color: me ? Colors.white70 : Colors.grey),
            ),
          ),
        ]),
      ),
    );
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
  const _RecipientPicker({required this.people});
  @override
  State<_RecipientPicker> createState() => _RecipientPickerState();
}

class _RecipientPickerState extends State<_RecipientPicker> {
  final Set<int> _sel = {};
  String _query = '';

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
    final list = widget.people.where((p) => _query.isEmpty || p.label.toLowerCase().contains(_query.toLowerCase()) || p.key.toLowerCase().contains(_query.toLowerCase())).toList();
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
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
          child: TextField(
            decoration: const InputDecoration(hintText: 'Search people…', prefixIcon: Icon(Icons.search), border: OutlineInputBorder(), isDense: true),
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
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
                          if (p.showsPresence)
                            Text(p.online ? 'Online' : 'Offline',
                                style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                    color: p.online ? const Color(0xFF1B8A3A) : const Color(0xFFC0392B))),
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
        Padding(
          padding: const EdgeInsets.all(12),
          child: SizedBox(
            width: double.infinity,
            child: FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _kBlue),
              onPressed: _sel.isEmpty ? null : () => Navigator.pop(context, _chosen(widget.people)),
              child: Text(_sel.length > 1 ? 'Start group (${_sel.length})' : 'Start conversation'),
            ),
          ),
        ),
      ]),
    );
  }
}
