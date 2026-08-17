/// Client for the SQLite-backed messaging API (`index.php?messaging=…`).
///
/// A mobile client authenticates with its own tracker token — the server
/// auto-identifies it as a participant — so there is no separate "subscribe".
/// Mirrors the web operator panel: participants, conversations (direct/group),
/// threads, per-recipient delivery + read receipts.
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'map_config.dart';

// ── Models ──────────────────────────────────────────────────────────────────
class MsgParticipant {
  final int id;
  final String kind; // 'mobile' | 'operator'
  final String key; // callsign or operator name
  final String name;
  final String? shortId;
  final bool online;
  final bool self;

  /// How many things this row covers: devices for one person (`ent:`), or people
  /// for a whole station (`mult:`). 1 for an ordinary single-device participant.
  final int devices;

  const MsgParticipant({required this.id, required this.kind, required this.key, required this.name, this.shortId, this.online = false, this.self = false, this.devices = 1});
  factory MsgParticipant.fromJson(Map<String, dynamic> j) => MsgParticipant(
        id: (j['id'] as num).toInt(),
        kind: j['kind'] as String? ?? 'mobile',
        key: j['key'] as String? ?? '',
        name: j['name'] as String? ?? j['display_name'] as String? ?? '',
        shortId: j['short_id'] as String?,
        online: j['online'] as bool? ?? false,
        self: j['self'] as bool? ?? false,
        devices: (j['devices'] as num?)?.toInt() ?? 1,
      );

  /// A "<ID> (multiple)" row addressing everyone sharing one display_id.
  bool get isMultiple => key.startsWith('mult:');

  /// The display_id this row belongs to, used to keep a station's rows together.
  String get groupId => isMultiple ? key.substring(5) : (shortId ?? '');

  /// Whether an Online/Offline word belongs on this row. A "(multiple)" row stands
  /// for several people at once, so a single presence state would be misleading.
  bool get showsPresence => !isMultiple;

  /// Sub-line under the name in the picker. Deliberately empty for most rows so they
  /// stay single-line: presence has its own column, and operators need no "Operator"
  /// label because they are already the first, rule-separated block in the list.
  String get subtitle {
    if (kind == 'operator') return '';
    if (isMultiple) return '$devices people at $groupId';
    if (devices > 1) return '$devices devices';
    return '';
  }

  /// How the client is shown — mobiles as "M141 Dirck", operators as their name.
  String get label {
    if (kind == 'mobile' && shortId != null && shortId!.isNotEmpty) {
      return (name.isNotEmpty && name != key) ? '$shortId $name' : shortId!;
    }
    return name.isNotEmpty ? name : key;
  }
}

class MsgMember {
  final int id;
  final String kind;
  final String key;
  final String? shortId;
  final String displayName;
  const MsgMember({required this.id, required this.kind, required this.key, this.shortId, required this.displayName});
  factory MsgMember.fromJson(Map<String, dynamic> j) => MsgMember(
        id: (j['id'] as num).toInt(),
        kind: j['kind'] as String? ?? 'mobile',
        key: j['key'] as String? ?? '',
        shortId: j['short_id'] as String?,
        displayName: j['display_name'] as String? ?? '',
      );
  String get label {
    if (kind == 'mobile' && shortId != null && shortId!.isNotEmpty) {
      return (displayName.isNotEmpty && displayName != key) ? '$shortId $displayName' : shortId!;
    }
    return displayName.isNotEmpty ? displayName : key;
  }
}

class MsgPreview {
  final String text;
  final int ts;
  final bool self;
  const MsgPreview({required this.text, required this.ts, required this.self});
  factory MsgPreview.fromJson(Map<String, dynamic> j) => MsgPreview(
        text: j['text'] as String? ?? '',
        ts: (j['ts'] as num?)?.toInt() ?? 0,
        self: j['self'] as bool? ?? false,
      );
}

class MsgConversation {
  final int id;
  final String kind; // 'direct' | 'group' | 'broadcast'
  final String? title;
  final int unread;
  final int lastId;
  final List<MsgMember> members; // everyone except me
  final MsgPreview? preview;
  const MsgConversation({required this.id, required this.kind, this.title, required this.unread, required this.lastId, required this.members, this.preview});
  factory MsgConversation.fromJson(Map<String, dynamic> j) => MsgConversation(
        id: (j['id'] as num).toInt(),
        kind: j['kind'] as String? ?? 'direct',
        title: j['title'] as String?,
        unread: (j['unread'] as num?)?.toInt() ?? 0,
        lastId: (j['last_id'] as num?)?.toInt() ?? 0,
        members: ((j['members'] as List?) ?? []).map((m) => MsgMember.fromJson(m as Map<String, dynamic>)).toList(),
        preview: j['preview'] != null ? MsgPreview.fromJson(j['preview'] as Map<String, dynamic>) : null,
      );

  /// Thread title — "All Trackers" for broadcast, the group title, or the
  /// members joined for a direct/group chat.
  String get label {
    if (kind == 'broadcast') return 'All Trackers';
    if (title != null && title!.isNotEmpty) return title!;
    if (members.isEmpty) return 'Conversation';
    if (members.length == 1) return members.first.label;
    return members.map((m) => m.shortId ?? m.displayName).join(', ');
  }
}

class MsgMessage {
  final int id;
  final int conversationId;
  final int ts;
  final String text;
  final bool broadcast;
  final int fromId;
  final String? fromKind;
  final String? fromKey;
  final String? fromShort;
  final String fromName;
  final double? lat;
  final double? lon;
  final bool hasPhoto;
  final int? photoW;
  final int? photoH;

  /// Who it went to — "All Trackers", "Log", or the recipient list. The server has
  /// always sent this on history() and thread() and the app has always thrown it
  /// away, because a message you received needed no explaining. Monitored traffic
  /// does: half of it was addressed to somebody else.
  final String? toLabel;

  /// Recorded radio audio for a Transcriber entry. A flag and a URL, never bytes —
  /// a phone that has not opted into audio simply never fetches it and so spends
  /// nothing, which is the whole reason this is an attachment and not a stream.
  final bool hasAudio;
  final String? audioUrl;
  final double? audioSecs;

  /// True when this arrived through the monitor feed rather than being addressed to
  /// us. Not from the server — the client knows which call it made. It exists so the
  /// rest of the app can tell the difference at a glance, because almost everything
  /// downstream needs to: no notification, no re-aiming a reply, its own cap on the
  /// watch, and a different spoken preamble.
  final bool monitored;

  /// Sent TO us, as opposed to merely happening near us. Tagged by the server, and only
  /// on the monitor feed — every other feed is built from delivery rows, so everything
  /// in them is addressed here by construction.
  ///
  /// It exists because the phone announces addressed traffic and monitored traffic by
  /// two independent paths and the monitor feed returns both, so a message sent to this
  /// operator was read aloud twice. The client cannot work this out for itself: the two
  /// polls run at different intervals, so a monitor batch can arrive before the
  /// addressed path has seen anything to claim.
  ///
  /// False by default, which is what an older server yields. That is the safe direction
  /// — a message that is announced twice is a nuisance; one suppressed on both paths is
  /// a message nobody heard.
  final bool addressedToMe;

  const MsgMessage({required this.id, required this.conversationId, required this.ts, required this.text, this.broadcast = false, required this.fromId, this.fromKind, this.fromKey, this.fromShort, this.fromName = '', this.lat, this.lon, this.hasPhoto = false, this.photoW, this.photoH, this.toLabel, this.hasAudio = false, this.audioUrl, this.audioSecs, this.monitored = false, this.addressedToMe = false});

  MsgMessage asMonitored() => MsgMessage(
        id: id, conversationId: conversationId, ts: ts, text: text, broadcast: broadcast,
        fromId: fromId, fromKind: fromKind, fromKey: fromKey, fromShort: fromShort,
        fromName: fromName, lat: lat, lon: lon, hasPhoto: hasPhoto, photoW: photoW,
        photoH: photoH, toLabel: toLabel, hasAudio: hasAudio, audioUrl: audioUrl,
        audioSecs: audioSecs, monitored: true, addressedToMe: addressedToMe,
      );

  /// A Transcriber entry — something heard on the radio rather than typed by a person.
  /// The distinction matters most when spoken: a synthesised voice reading a garbled
  /// machine transcription sounds exactly as authoritative as a real message.
  bool get isRadio => fromKind == 'transcriber' || toLabel == 'Log';

  factory MsgMessage.fromJson(Map<String, dynamic> j) => MsgMessage(
        id: (j['id'] as num).toInt(),
        conversationId: (j['conversation_id'] as num?)?.toInt() ?? 0,
        ts: (j['ts'] as num?)?.toInt() ?? 0,
        text: j['text'] as String? ?? '',
        broadcast: j['broadcast'] as bool? ?? false,
        fromId: (j['from_id'] as num?)?.toInt() ?? 0,
        fromKind: j['from_kind'] as String?,
        fromKey: j['from_key'] as String?,
        fromShort: j['from_short'] as String?,
        fromName: j['from_name'] as String? ?? '',
        lat: (j['lat'] as num?)?.toDouble(),
        lon: (j['lon'] as num?)?.toDouble(),
        hasPhoto: j['photo'] as bool? ?? false,
        photoW: (j['photo_w'] as num?)?.toInt(),
        photoH: (j['photo_h'] as num?)?.toInt(),
        toLabel: j['to_label'] as String?,
        addressedToMe: j['addressed'] as bool? ?? false,
        hasAudio: j['has_audio'] as bool? ?? false,
        audioUrl: j['audio_url'] as String?,
        audioSecs: (j['audio_secs'] as num?)?.toDouble(),
      );
  String get senderLabel {
    if (fromKind == 'mobile' && fromShort != null && fromShort!.isNotEmpty) {
      return (fromName.isNotEmpty && fromName != fromKey) ? '$fromShort $fromName' : fromShort!;
    }
    return fromName.isNotEmpty ? fromName : (fromKey ?? '');
  }
}

class MsgReceipt {
  final int messageId;
  final int total;
  final int delivered;
  final int read;
  const MsgReceipt({required this.messageId, required this.total, required this.delivered, required this.read});
  factory MsgReceipt.fromJson(Map<String, dynamic> j) => MsgReceipt(
        messageId: (j['message_id'] as num).toInt(),
        total: (j['total'] as num?)?.toInt() ?? 0,
        delivered: (j['delivered'] as num?)?.toInt() ?? 0,
        read: (j['read'] as num?)?.toInt() ?? 0,
      );
}

class PollResult {
  final List<MsgMessage> messages;
  final List<MsgReceipt> receipts;
  final int lastId;
  const PollResult({required this.messages, required this.receipts, required this.lastId});
}

class MonitorResult {
  final List<MsgMessage> messages;

  /// How many the server declined to send — too old, or past the per-poll limit.
  /// Shown rather than swallowed: a silently truncated catch-up reads to the user as
  /// "nothing happened while I was away", which is the opposite of the truth.
  final int skipped;

  /// The high-water mark of everything the server matched, including what it
  /// skipped. Advancing past the gap is what stops the same backlog being reported
  /// on every poll for the rest of the event.
  final int lastId;
  const MonitorResult({required this.messages, required this.skipped, required this.lastId});
}

class SendResult {
  final bool ok;
  final int? id;
  final int? conversationId;
  final String? error;
  const SendResult({required this.ok, this.id, this.conversationId, this.error});
}

// ── Client ──────────────────────────────────────────────────────────────────
class MessagingClient {
  /// Returns the current tracker token, or null when not sharing.
  final String? Function() tokenProvider;
  MessagingClient(this.tokenProvider);

  Uri _url(String action) => Uri.parse('${MapConfig.serverBaseUrl}/index.php?messaging=$action');

  Future<Map<String, dynamic>?> _post(String action, [Map<String, dynamic> body = const {}]) async {
    final token = tokenProvider();
    if (token == null) return null;
    try {
      final r = await http
          .post(_url(action), headers: {'Content-Type': 'application/json'}, body: jsonEncode({'token': token, ...body}))
          .timeout(const Duration(seconds: 10));
      if (r.statusCode == 403) return {'__auth': true};
      return jsonDecode(r.body) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  /// This client's own participant id, and the token it was learned under.
  ///
  /// Every message carries `fromId`, but until now nothing outside the chat screen knew
  /// which id was *ours* — and "did I send this?" is the question that decides whether a
  /// message is read aloud. See `MessagingClient.myParticipantId`.
  int? _me;
  String? _meToken;

  Future<({List<MsgParticipant> participants, int? me})> participants() async {
    final d = await _post('participants');
    if (d == null || d['participants'] == null) return (participants: <MsgParticipant>[], me: null);
    final list = (d['participants'] as List).map((p) => MsgParticipant.fromJson(p as Map<String, dynamic>)).where((p) => !p.self).toList();
    final me = (d['me'] as num?)?.toInt();
    if (me != null) {
      _me = me;
      _meToken = tokenProvider();
    }
    return (participants: list, me: me);
  }

  /// Who this device is, on the messaging side. Fetched once and held.
  ///
  /// `participants` is the only endpoint that answers it and it returns the whole
  /// roster, so it is far too heavy to ask per message — but the answer does not change
  /// within a session, so one call covers the life of the token.
  ///
  /// Cached against the token rather than forever: a new token is a new participant, and
  /// an id remembered across an event change would name somebody else entirely.
  Future<int?> myParticipantId() async {
    final token = tokenProvider();
    if (token == null) return null;
    if (_me != null && _meToken == token) return _me;
    return (await participants()).me;
  }

  Future<List<MsgConversation>> conversations() async {
    final d = await _post('conversations');
    if (d == null || d['conversations'] == null) return [];
    return (d['conversations'] as List).map((c) => MsgConversation.fromJson(c as Map<String, dynamic>)).toList();
  }

  Future<List<MsgMessage>> thread(int conversationId, {int sinceId = 0}) async {
    final d = await _post('thread', {'conversation_id': conversationId, 'since_id': sinceId});
    if (d == null || d['messages'] == null) return [];
    return (d['messages'] as List).map((m) => MsgMessage.fromJson(m as Map<String, dynamic>)).toList();
  }

  Future<PollResult> poll(int sinceId) async {
    final d = await _post('poll', {'since_id': sinceId});
    if (d == null) return PollResult(messages: const [], receipts: const [], lastId: sinceId);
    return PollResult(
      messages: ((d['messages'] as List?) ?? []).map((m) => MsgMessage.fromJson(m as Map<String, dynamic>)).toList(),
      receipts: ((d['receipts'] as List?) ?? []).map((r) => MsgReceipt.fromJson(r as Map<String, dynamic>)).toList(),
      lastId: (d['last_id'] as num?)?.toInt() ?? sinceId,
    );
  }

  /// Send to a recipient set (new conversation) or into an existing conversation.
  /// With [photoPath], the message carries an attached photo (multipart upload);
  /// [text] may then be empty (a photo-only message).
  Future<SendResult> send({List<String>? recipients, int? conversationId, required String text, String? photoPath}) async {
    final token = tokenProvider();
    if (token == null) return const SendResult(ok: false, error: 'Not signed in');
    try {
      if (photoPath != null) {
        final req = http.MultipartRequest('POST', _url('send'));
        req.fields['token'] = token;
        req.fields['text'] = text;
        if (conversationId != null) req.fields['conversation_id'] = conversationId.toString();
        if (recipients != null) req.fields['recipients'] = jsonEncode(recipients);
        req.files.add(await http.MultipartFile.fromPath('photo', photoPath));
        final streamed = await req.send().timeout(const Duration(seconds: 30));
        final r = await http.Response.fromStream(streamed);
        if (r.statusCode == 403) return const SendResult(ok: false, error: 'Not signed in');
        final d = jsonDecode(r.body) as Map<String, dynamic>;
        if (d['error'] != null) return SendResult(ok: false, error: d['error'] as String);
        return SendResult(ok: true, id: (d['id'] as num?)?.toInt(), conversationId: (d['conversation_id'] as num?)?.toInt());
      }
      final body = <String, dynamic>{'text': text};
      if (conversationId != null) body['conversation_id'] = conversationId;
      if (recipients != null) body['recipients'] = recipients;
      final d = await _post('send', body);
      if (d == null) return const SendResult(ok: false, error: 'Network error');
      if (d['error'] != null) return SendResult(ok: false, error: d['error'] as String);
      return SendResult(ok: true, id: (d['id'] as num?)?.toInt(), conversationId: (d['conversation_id'] as num?)?.toInt());
    } catch (_) {
      return const SendResult(ok: false, error: 'Network error');
    }
  }

  /// Read-only feed of the event's traffic, for a phone that wants to follow
  /// everything rather than only what was addressed to it.
  ///
  /// Deliberately not part of poll(): this must never mark anything delivered or
  /// read. Monitoring somebody else's message has to leave no trace on it, or their
  /// sender's receipts start counting us as a recipient.
  Future<MonitorResult> monitor(int sinceId, {required bool all, required bool radio}) async {
    if (!all && !radio) return MonitorResult(messages: const [], skipped: 0, lastId: sinceId);
    final d = await _post('monitor', {'since_id': sinceId, 'all': all, 'log': radio});
    if (d == null) return MonitorResult(messages: const [], skipped: 0, lastId: sinceId);
    return MonitorResult(
      messages: ((d['messages'] as List?) ?? [])
          .map((m) => MsgMessage.fromJson(m as Map<String, dynamic>).asMonitored())
          .toList(),
      skipped: (d['skipped'] as num?)?.toInt() ?? 0,
      lastId: (d['last_id'] as num?)?.toInt() ?? sinceId,
    );
  }

  /// Absolute URL for a radio clip. Unlike photoUrl() this carries no token: radio
  /// audio is a static file served by Apache so that Cloudflare can cache it, which
  /// is what keeps the origin out of the way when fifty phones want the same clip.
  /// The URL is unguessable and only obtainable from the authenticated feed.
  static String? audioUrl(MsgMessage m) =>
      m.audioUrl == null ? null : '${MapConfig.serverBaseUrl}${m.audioUrl}';

  /// Auth-gated URL for a message's attached photo (token in the query so it can
  /// be loaded directly by an Image widget).
  String photoUrl(int messageId) {
    final token = tokenProvider() ?? '';
    return '${MapConfig.serverBaseUrl}/index.php?messaging=photo&id=$messageId&token=$token';
  }

  Future<void> read(List<int> ids) async {
    if (ids.isEmpty) return;
    await _post('read', {'ids': ids});
  }
}
