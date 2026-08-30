<?php
/**
 * messaging.php — JSON API for the MARS messaging system (SQLite core).
 *
 * Routed from index.php as `?messaging=<action>`. Any-to-any + groups: operators
 * and mobiles are both "participants"; a message goes to a conversation (direct,
 * group, or broadcast) and is delivered per-recipient with delivery/read receipts.
 *
 * Entry point: messaging_handle($action, $body, $ctx) — echoes JSON and exits.
 *   $ctx = [
 *     'event'      => string,          // current event (scopes everything)
 *     'msgPassword'=> string,          // messaging password for operator subscribe
 *     'mobileFile' => string,          // path to mobile_trackers.json
 *     'authPerm'   => callable(string):bool,  // signed-in permission check (flush)
 *   ]
 *
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

require_once __DIR__ . '/messaging_db.php';

// ── shared helpers ────────────────────────────────────────────────────────────
function _msg_fail(int $code, string $err): void { http_response_code($code); echo json_encode(['error'=>$err]); exit; }

/** Load the trackers array from mobile_trackers.json (handles both wrapped + bare forms). */
function _msg_load_trackers(string $file): array
{
    if (!is_readable($file)) return [];
    $fh = fopen($file, 'r'); if (!$fh) return [];
    flock($fh, LOCK_SH); $raw = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
    return isset($raw['trackers']) ? $raw['trackers'] : $raw;
}

// Beside messages.db, deliberately outside the web root. It holds a token per channel,
// and a token registry under /var/www/html is how mobile_trackers.json came to be
// downloadable by anyone who asked. Overridable for tests.
if (!defined('MARSAPRS_CHANNELS')) {
    define('MARSAPRS_CHANNELS', getenv('MARSAPRS_CHANNELS') ?: '/var/lib/marsaprs/transcriber.json');
}

/** A Transcriber channel matching this token, or null.
 *
 *  The registry is JSON rather than YAML deliberately: it is read on every authenticated
 *  request, exactly as mobile_trackers.json is, and that file is the closest analogue —
 *  a token registry consulted by this same resolver. Keeping the two the same shape
 *  means no YAML parser in the hot path and none in the tests.
 *
 *  Shape: { "channels": [ {"id","label","token","short_id"?,"enabled"?}, … ] }
 *  A channel with `enabled: false` does not resolve, so switching one off in the
 *  manager stops it logging even if the device has not picked up its config yet. */
function _msg_find_channel(array $ctx, string $token): ?array
{
    $file = $ctx['channelFile'] ?? null;
    if (!$file || !is_readable($file) || $token === '') return null;
    $raw = json_decode((string)file_get_contents($file), true) ?: [];
    foreach (($raw['channels'] ?? []) as $c) {
        if (empty($c['token']) || !hash_equals((string)$c['token'], $token)) continue;
        if (isset($c['enabled']) && !$c['enabled']) return null;
        if (empty($c['id'])) return null;
        return ['id'=>(string)$c['id'],
                'label'=>(string)($c['label'] ?? $c['id']),
                'short_id'=>isset($c['short_id']) ? (string)$c['short_id'] : null];
    }
    return null;
}

/** Position array [lat,lon,ts] from a tracker record, or null. */
function _msg_tracker_pos(array $t): ?array
{
    if (isset($t['aprs_lat'], $t['aprs_lon']) && is_numeric($t['aprs_lat']) && is_numeric($t['aprs_lon'])) {
        return ['lat'=>(float)$t['aprs_lat'], 'lon'=>(float)$t['aprs_lon'], 'ts'=>(int)($t['aprs_ts'] ?? 0) ?: null];
    }
    return null;
}

/**
 * Resolve the caller from a token. Operators already exist as participants;
 * mobiles are auto-identified from their tracker token in mobile_trackers.json,
 * so any send/poll lazily registers them as a participant. Returns row or null.
 */
function _msg_resolve_sender(MessagingDb $db, array $ctx, string $token): ?array
{
    if ($token === '') return null;
    $p = $db->participantByToken($token);
    // An operator's token is kept in the browser indefinitely, so it outlives the event
    // it was issued for. participantByToken matches on the token alone, so after a new
    // event is created that session went on resolving to its old-event participant: the
    // operator kept working, but in the previous event's roster. Nothing looked broken
    // from their side -- messages sent and arrived -- while the new event had no
    // operator in it at all, so `participants` offered mobiles nobody to write to.
    //
    // Mobiles were left out of this on the reasoning that a session mints a fresh token
    // each time. It does — but the app persists that token and restores it on launch, so
    // a phone that was running when the event changed goes on resolving to its old-event
    // participant exactly as an operator did. Nothing showed it, because pollFor() joins
    // on deliveries and has no event predicate at all. The monitor feed IS event-scoped,
    // and would have served the new event's traffic to a row sitting in the old one.
    if ($p && in_array($p['kind'] ?? '', ['operator', 'transcriber', 'mobile'], true)
        && ($p['event'] ?? '') !== $ctx['event']) {
        return $db->participantById($db->rehomeSession($ctx['event'], $p, $token));
    }
    // A channel's name comes from the manager and the manager can change it. Once a
    // participant row exists the token matches it directly and everything below is
    // skipped, so "Heard as" edits were saved, collected by the device, and then had no
    // effect on a single line in the log — the name was fixed at whatever it had been
    // the first time that channel spoke.
    //
    // Only for transcribers: an operator's display name is their own, not the registry's.
    if ($p && ($p['kind'] ?? '') === 'transcriber') {
        $ch = _msg_find_channel($ctx, $token);
        $label = $ch['label'] ?? '';
        if ($label !== '' && $label !== ($p['display_name'] ?? '')) {
            $db->setParticipantName((int)$p['id'], $label);
            $p['display_name'] = $label;
        }
    }
    if ($p) return $p;
    // A transcriber channel, identified the same way a mobile is: its token is not in
    // the participants table until it first speaks, so an unmatched token is looked up
    // in the channel registry and the channel registered in the current event.
    if ($ch = _msg_find_channel($ctx, $token)) {
        $id = $db->upsertParticipant($ctx['event'], 'transcriber', $ch['id'],
                                     $ch['label'], $ch['short_id'] ?? null, $token);
        return $db->participantById($id);
    }
    foreach (_msg_load_trackers($ctx['mobileFile']) as $t) {
        if (!empty($t['token']) && hash_equals($t['token'], $token)) {
            $cs = $t['callsign'] ?? null;
            if (!$cs) return null;
            $id = $db->upsertParticipant($ctx['event'], 'mobile', $cs,
                    $t['name'] ?? $cs, $t['id'] ?? null, $token, _msg_tracker_pos($t));
            return $db->participantById($id);
        }
    }
    return null;
}

/** Register every currently-tracked mobile (token-bearing) as a participant, so a
 *  broadcast reaches all of them — not only those who have opened messaging. */
function _msg_ensure_all_mobiles(MessagingDb $db, array $ctx): void
{
    foreach (_msg_load_trackers($ctx['mobileFile']) as $t) {
        $cs = $t['callsign'] ?? null;
        if (!$cs || empty($t['token'])) continue;
        $db->upsertParticipant($ctx['event'], 'mobile', $cs, $t['name'] ?? $cs,
                               $t['id'] ?? null, $t['token'], _msg_tracker_pos($t));
    }
}

/** Map recipient keys (callsign or operator name) to participant ids, creating
 *  mobile participants on demand from mobile_trackers.json. Unknown keys dropped. */
/** Devices that are addressable right now: a live session token, seen within 24 h.
 *  Same test index.php:99 uses to build the picker, so what an operator can select
 *  and what actually receives a message never disagree. */
/**
 * How recently a device or operator must have been seen to appear in the mobile app's
 * recipient picker.
 *
 * Twenty-four hours. This has now been both values, so the reasoning for each is kept:
 *
 * It began at a day, was cut to six hours on 2026-08-20 because the list filled with
 * identities nobody could message usefully — four test operators and a Display Pi, all
 * last seen ~22 hours earlier — and a picker whose entries are mostly wrong is one an
 * operator stops reading.
 *
 * Raised back on 2026-08-27, because six hours charged that clutter to the wrong people.
 * M002 and M003 were 12.5 h since their last beacon — phones asleep overnight, not
 * retired identities — and could not be selected at all, while messages to an offline
 * recipient queue and deliver perfectly well on their next poll. A name you cannot pick
 * is worse than a name you can pick and see is stale, because `online` (90 s) is
 * reported separately and already says which is which.
 *
 * What actually went stale in the 08-20 case was OPERATORS, not mobiles: `test2`
 * through `test6`, `BigTV` and `NetControl` still sit in the participants table. So
 * this is now the MOBILE window only — operators are gated on MSG_ONLINE_SECONDS
 * instead, which is the two-window split that one number could never get right for
 * both. This value still governs broadcast delivery for both kinds.
 *
 * Note the web operator panel does NOT use this — it builds its list from the live
 * tracker feed, which is why the two have always differed.
 */
if (!defined('MSG_ADDRESSABLE_SECONDS')) define('MSG_ADDRESSABLE_SECONDS', 24 * 3600);

/**
 * How recently something must have been heard from to count as ONLINE, as opposed to
 * merely addressable. Reported to the app as `online` on every row, and — for operators
 * only — used as the gate for appearing in the picker at all. See the operator loop in
 * the `participants` case for why the two kinds are judged differently.
 */
if (!defined('MSG_ONLINE_SECONDS')) define('MSG_ONLINE_SECONDS', 90);

/** Who a broadcast is actually delivered to.
 *
 *  NOT "every participant row this event has ever had", which is what
 *  conversationRecipients() used to answer for a broadcast and what put "Read by 1 of 66"
 *  under a message sent to two dozen live devices. Of that 67-row event: 49 mobiles of
 *  which 22 had been heard from in six hours, 13 operators of which 1 had, and 5
 *  transcribers. Two thirds of the denominator were identities nobody could reach — test
 *  sessions, phones that left days ago, operators who signed out — and a receipt whose
 *  denominator can never be satisfied stops meaning anything at all.
 *
 *  The test is the recipient picker's, deliberately: what an operator can select and what
 *  actually receives a message must not disagree.
 *
 *    - Mobiles are judged by their tracker lastUpdate (_msg_addressable), NOT by
 *      participants.last_seen. upsertParticipant rewrites last_seen on every write, and
 *      _msg_ensure_all_mobiles touches every tracker in the file immediately before this
 *      runs — so a last_seen test would call all of them fresh and filter nothing.
 *      _msg_mark_stale documents the same trap at length.
 *    - Operators keep last_seen, which for them is only ever written by touchParticipant
 *      and so means what it says.
 *    - Transcribers are excluded. A receiver does not read anything, so its delivery row
 *      can never be marked read and would sit in the denominator for good.
 */
function _msg_broadcast_recipients(MessagingDb $db, array $ctx, int $senderId): array
{
    $now  = time();
    $live = [];
    foreach (_msg_load_trackers($ctx['mobileFile']) as $t) {
        if (_msg_addressable($t, $now)) $live[(string)$t['callsign']] = true;
    }
    $ids = [];
    foreach ($db->listParticipants($ctx['event']) as $p) {
        $id = (int)$p['id'];
        if ($id === $senderId) continue;
        $kind = (string)($p['kind'] ?? '');
        if ($kind === 'mobile') {
            if (isset($live[(string)($p['key'] ?? '')])) $ids[] = $id;
        } elseif ($kind === 'operator') {
            if (!empty($p['last_seen'])
                && ($now - (int)$p['last_seen']) <= MSG_ADDRESSABLE_SECONDS) $ids[] = $id;
        }
    }
    return $ids;
}

function _msg_addressable(array $t, int $now): bool
{
    return !empty($t['callsign']) && empty($t['blocked'])
        && !empty($t['token']) && ($now - ($t['lastUpdate'] ?? 0)) <= MSG_ADDRESSABLE_SECONDS;
}

/** The display_id shown to operators — the merge key. Falls back to the M0xx id. */
function _msg_display_id(array $t): string
{
    return (string)(($t['display_id'] ?? '') !== '' ? $t['display_id'] : ($t['id'] ?? ''));
}

/**
 * Expand an entity or multi-recipient key into the tracker records it addresses.
 *   ent:<display_id>|<name>  → every device sharing BOTH (one person, several phones)
 *   mult:<display_id>        → every device under that display_id, whatever the name
 * Returns [] for anything else, so plain callsigns fall through to the caller.
 */
function _msg_expand_group_key(string $key, array $trackers): array
{
    $now = time();
    if (strncmp($key, 'ent:', 4) === 0) {
        $rest = substr($key, 4);
        $bar  = strpos($rest, '|');
        if ($bar === false) return [];
        $disp = trim(substr($rest, 0, $bar));
        $name = trim(substr($rest, $bar + 1));
        return array_values(array_filter($trackers, fn($t) => _msg_addressable($t, $now)
            && _msg_display_id($t) === $disp && trim((string)($t['name'] ?? '')) === $name));
    }
    if (strncmp($key, 'mult:', 5) === 0) {
        $disp = trim(substr($key, 5));
        return array_values(array_filter($trackers, fn($t) => _msg_addressable($t, $now)
            && _msg_display_id($t) === $disp));
    }
    return [];
}

function _msg_resolve_recipients(MessagingDb $db, array $ctx, array $keys): array
{
    $ids = [];
    $trackers = null;
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '') continue;
        // Entity / (multiple) keys expand to several devices before anything else,
        // so one picker row can address a person's whole set of phones.
        if (strncmp($key, 'ent:', 4) === 0 || strncmp($key, 'mult:', 5) === 0) {
            if ($trackers === null) $trackers = _msg_load_trackers($ctx['mobileFile']);
            foreach (_msg_expand_group_key($key, $trackers) as $t) {
                $ids[] = $db->upsertParticipant($ctx['event'], 'mobile', $t['callsign'],
                            $t['name'] ?? $t['callsign'], $t['id'] ?? null, null, _msg_tracker_pos($t));
            }
            continue;
        }
        $p = $db->participantByKey($ctx['event'], $key);
        if ($p) { $ids[] = (int)$p['id']; continue; }
        if ($trackers === null) $trackers = _msg_load_trackers($ctx['mobileFile']);
        foreach ($trackers as $t) {
            if (($t['callsign'] ?? '') === $key) {
                $ids[] = $db->upsertParticipant($ctx['event'], 'mobile', $key,
                            $t['name'] ?? $key, $t['id'] ?? null, null, _msg_tracker_pos($t));
                break;
            }
        }
    }
    return array_values(array_unique($ids));
}

// ── main dispatcher ───────────────────────────────────────────────────────────
/**
 * Validate + store an uploaded photo for message $mid. GD isn't available on the
 * server, so we don't re-encode — the client downscales/compresses before upload.
 * We validate it's a real image (getimagesize reads headers only), cap the size,
 * and store it under the event's private photo dir. Returns metadata or null.
 */
function _msg_store_photo(string $event, int $mid, array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) return null;   // 12 MB ceiling
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_readable($tmp)) return null;
    $info = @getimagesize($tmp);
    if ($info === false) return null;                          // not a real image
    $ext = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) return null;                            // unsupported type
    $dir = MessagingDb::photoDir($event);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) return null;
    $fn   = $mid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $fn;
    if (!@move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) return null;
    @chmod($dest, 0660);
    return ['filename'=>$fn, 'w'=>(int)($info[0] ?? 0), 'h'=>(int)($info[1] ?? 0)];
}

/**
 * Validate + store a recorded radio clip for message $mid. Returns metadata or null.
 *
 * Unlike a photo this lands INSIDE the web root, so Apache serves it without PHP and
 * Cloudflare can cache it — see MessagingDb::audioDir() for why that is worth the
 * departure, and why photos must not follow. Everything else about the shape is the
 * photo path: 6 random bytes in the name so the URL is a capability, and validation
 * that does not trust the uploader's word for what the file is.
 *
 * There is no getimagesize() equivalent, so the check is the ISO base media container
 * signature: bytes 4..8 of an .m4a are the literal 'ftyp'. That is not a deep parse,
 * but it is enough that a mislabelled or truncated upload is rejected here rather than
 * becoming a clip that every subscribed phone fetches and fails to play.
 */
function _msg_store_audio(string $event, int $mid, array $file, ?float $secs = null): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) return null;    // 8 MB: a capped over is ~400 KB
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_readable($tmp)) return null;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'm4a') return null;
    $head = @file_get_contents($tmp, false, null, 0, 12);
    if ($head === false || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return null;

    $dir = MessagingDb::audioDir($event);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    $fn   = $mid . '-' . bin2hex(random_bytes(6)) . '.m4a';
    $dest = $dir . '/' . $fn;
    if (!@move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) return null;
    // World-readable, unlike a photo's 0660: Apache serves this one directly.
    @chmod($dest, 0644);
    return ['filename'=>$fn, 'secs'=>$secs];
}

function messaging_handle(string $action, array $body, array $ctx): void
{
    header('Content-Type: application/json');
    $event = $ctx['event'];
    if ($event === '') _msg_fail(400, 'No current event');
    $db = new MessagingDb();

    // Operators subscribe with a name + the messaging password → a session token.
    if ($action === 'subscribe') {
        $name = substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $body['name'] ?? '')), 0, 30);
        $pw   = trim($body['password'] ?? '');
        if ($name === '')                                   _msg_fail(400, 'Name required');
        if ($ctx['msgPassword'] === '' || !hash_equals($ctx['msgPassword'], $pw)) _msg_fail(403, 'Incorrect password');
        // Reject a name currently held by another live operator session.
        $existing = $db->participantByKey($event, $name);
        if ($existing && ($existing['kind'] ?? '') === 'operator'
            && !empty($existing['last_seen']) && (time() - (int)$existing['last_seen']) < 90
            && !empty($existing['token']) && empty($body['reclaim'])) {
            _msg_fail(409, 'That name is in use — choose another.');
        }
        $token = bin2hex(random_bytes(16));
        $db->upsertParticipant($event, 'operator', $name, $name, null, $token);
        echo json_encode(['token'=>$token, 'name'=>$name,
                          'can_manage'=>(bool)($ctx['authPerm']('messages.manage'))]);
        exit;
    }

    // Everything else needs a valid caller token.
    $token = trim($body['token'] ?? $_GET['token'] ?? '');
    $me    = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) _msg_fail(403, 'Not subscribed');
    $db->touchParticipant((int)$me['id']);

    switch ($action) {

    case 'participants': {
        // Addressable recipient list for the mobile app's picker. (The web operator
        // panel builds its own from the live tracker feed and does not call this.)
        //
        // Mobiles are grouped into ENTITIES — everyone sharing display_id and name is
        // one person, however many phones they carry — and only addressable devices
        // are listed. Returning the raw participants table here listed every identity
        // the event had ever seen, which is what produced a picker full of duplicate
        // names (one volunteer appearing four times across old sessions).
        $now      = time();
        $trackers = _msg_load_trackers($ctx['mobileFile']);
        $out      = [];

        // Operators: ONLINE only, which is a stricter test than the one mobiles get and
        // deliberately so. An operator is a staffed web panel — it polls continuously,
        // so last_seen is seconds old while somebody is sitting at it and stops the
        // instant they close the tab. There is no equivalent of a sleeping phone to be
        // generous towards, and it is operator rows that go stale and fill the picker:
        // `test2` through `test6`, `BigTV` and `NetControl` are all still in the
        // participants table. Measured 2026-08-27, this test kept 1 of 23 operators and
        // dropped 22, while the live Net Control had been seen 2 seconds earlier.
        //
        // Note this is STRICTER than the broadcast recipient test above, which still
        // uses MSG_ADDRESSABLE_SECONDS. That asymmetry is the safe direction: you may
        // only pick an operator who is there now, but a broadcast still reaches one who
        // has just stepped away rather than silently dropping their copy.
        foreach ($db->listParticipants($event) as $p) {
            if ($p['kind'] !== 'operator') continue;
            if (empty($p['last_seen']) || ($now - (int)$p['last_seen']) >= MSG_ONLINE_SECONDS) continue;
            $out[] = [
                'id'=>(int)$p['id'], 'kind'=>'operator', 'key'=>$p['key'],
                'name'=>$p['display_name'], 'short_id'=>$p['short_id'],
                'online'=> true,                       // guaranteed by the test above
                'self'=> (int)$p['id'] === (int)$me['id'],
            ];
        }

        // Mobiles: one row per entity, plus a "<ID> (multiple)" row wherever one
        // display_id covers more than one name.
        $entities = [];   // "disp\x1fname" => [trackers]
        $names    = [];   // disp => [name => true]
        foreach ($trackers as $t) {
            if (!_msg_addressable($t, $now)) continue;
            $disp = _msg_display_id($t);
            $name = trim((string)($t['name'] ?? ''));
            $entities[$disp . "\x1f" . $name][] = $t;
            $names[$disp][$name] = true;
        }
        foreach ($entities as $k => $list) {
            [$disp, $name] = explode("\x1f", $k, 2);
            $ids = [];
            foreach ($list as $t) {
                $ids[] = $db->upsertParticipant($event, 'mobile', $t['callsign'],
                            $t['name'] ?? $t['callsign'], $t['id'] ?? null, null, _msg_tracker_pos($t));
            }
            $newest = 0;
            foreach ($list as $t) $newest = max($newest, (int)($t['lastUpdate'] ?? 0));
            $out[] = [
                'id'      => (int)$ids[0],          // stable per entity: its first device
                'kind'    => 'mobile',
                'key'     => 'ent:' . $disp . '|' . $name,
                'name'    => $name,
                'short_id'=> $disp,
                'online'  => ($now - $newest) < MSG_ONLINE_SECONDS,
                // Hide the caller's own entity from their picker.
                'self'    => in_array((int)$me['id'], array_map('intval', $ids), true),
                'devices' => count($list),
            ];
        }
        $synth = -1;
        foreach ($names as $disp => $set) {
            if (count($set) < 2) continue;
            $out[] = [
                'id'=>$synth--, 'kind'=>'mobile', 'key'=>'mult:' . $disp,
                'name'=>$disp . ' (multiple)', 'short_id'=>null,
                'online'=>true, 'self'=>false, 'devices'=>count($set),
            ];
        }
        echo json_encode(['participants'=>$out, 'me'=>(int)$me['id']]);
        exit;
    }

    case 'send': {
        $hasPhoto  = !empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? 1) === UPLOAD_ERR_OK;
        // Text is required unless a photo is attached (a photo-only message is fine).
        // 1000, not 280. The old limit was sized for thumbs on a phone keyboard, and
        // dictation makes that the wrong premise -- thirty seconds of speech is about
        // 400 characters, and an operator talking into a watch should not have their
        // sentence cut off by a limit chosen for typing. Still bounded: this is a
        // message, not a document. Machine transcriptions have their own, larger cap in
        // `log`, because those can be two minutes of somebody else's over.
        $text = substr(trim($body['text'] ?? ''), 0, 1000);
        if ($text === '' && !$hasPhoto) _msg_fail(400, 'Message text required');
        $convId    = isset($body['conversation_id']) ? (int)$body['conversation_id'] : null;
        $recipients= $body['recipients'] ?? [];
        // In a multipart upload `recipients` arrives as a JSON string — decode it.
        if (is_string($recipients) && isset($recipients[0]) && $recipients[0] === '[') {
            $dec = json_decode($recipients, true);
            if (is_array($dec)) $recipients = $dec;
        }
        $broadcast = ($recipients === 'all' || (is_array($recipients) && in_array('all', $recipients, true)));
        $title     = isset($body['title']) ? substr(trim($body['title']), 0, 40) : null;
        $rKeys     = is_array($recipients) ? $recipients : [$recipients];
        $rIds      = $broadcast ? [] : _msg_resolve_recipients($db, $ctx, $rKeys);
        if (!$broadcast && !$convId && !$rIds) _msg_fail(400, 'Recipient required');

        // Addressing a single entity (one person's phones) or a whole display_id
        // gets a STABLE thread keyed to that entity rather than to the current set
        // of devices, so a phone going offline or a third joining never splits the
        // conversation. Only when the caller named the entity directly — replying
        // into an existing $convId keeps that thread.
        $entityKey = (!$broadcast && !$convId && count($rKeys) === 1
                      && (strncmp((string)$rKeys[0], 'ent:', 4) === 0
                          || strncmp((string)$rKeys[0], 'mult:', 5) === 0))
                   ? (string)$rKeys[0] : null;

        if ($entityKey !== null) {
            $isMult = strncmp($entityKey, 'mult:', 5) === 0;
            if ($isMult) {
                $disp = trim(substr($entityKey, 5));
                $name = '*';                       // every name under this display_id
            } else {
                $rest = substr($entityKey, 4);
                $bar  = strpos($rest, '|');
                $disp = trim(substr($rest, 0, (int)$bar));
                $name = trim(substr($rest, (int)$bar + 1));
            }
            $conv = $db->resolveEntityConversation($event, (int)$me['id'], $disp, $name, $rIds);
            $kind = 'entity';
            // Fold in the prior 1:1 history for THIS person's devices, so merging two
            // phones under one display_id doesn't leave a second stale thread behind.
            // Never for (multiple): Dirck's and Jerry's own conversations must stay
            // their own — an LKL group must not swallow them.
            if (!$isMult) $db->migrateThreadsIntoEntity($event, $conv, (int)$me['id'], $rIds);
            $deliverTo = array_values(array_filter($rIds, fn($id) => (int)$id !== (int)$me['id']));
        } else {
            // An operator addressing one device by callsign — the sidebar right-click —
            // lands in that person's existing entity thread rather than opening a
            // parallel direct one. The two render identically, since both take their
            // label from the same display_id and name, so the operator sees the same
            // person listed twice with their history split down the middle.
            //
            // This is what the system already believes: migrateThreadsIntoEntity folds
            // direct history *into* the entity thread, treating it as the canonical
            // place for a person. It just ran only when sending via an `ent:` picker
            // row, so every right-click afterwards undid the tidying.
            $entConv = (!$broadcast && !$convId && ($me['kind'] ?? '') === 'operator' && count($rIds) === 1)
                ? $db->entityConversationForDevice($event, (int)$me['id'], (int)$rIds[0])
                : null;
            if ($entConv !== null) {
                $conv = $entConv;
                $kind = 'entity';
            } else {
                [$conv, $kind] = $db->resolveConversation($event, (int)$me['id'], $rIds, $broadcast, $convId, $title);
            }
            // A broadcast reaches every registered mobile, so make sure they all exist.
            if ($kind === 'broadcast') _msg_ensure_all_mobiles($db, $ctx);
            $deliverTo = $kind === 'broadcast'
                ? _msg_broadcast_recipients($db, $ctx, (int)$me['id'])
                : $db->conversationRecipients($event, $conv, false, (int)$me['id']);
            // An OPERATOR replying into an entity thread: recompute from who is in the
            // entity NOW, so a device whose display_id moved elsewhere stops receiving
            // even though it remains a historical member of the thread.
            //
            // Only for an operator. The entity is the set of the *mobile's* devices, so
            // when the mobile itself replies, that set minus the sender is its own
            // sibling phones — and the operator, the one person it is answering, is
            // dropped entirely. The message stored fine, reported recipients: 0, and
            // reached nobody. resolveEntityConversation puts the operator in
            // conversation_members, so falling through to conversationRecipients below
            // already gives a mobile the right answer.
            if ($me['kind'] === 'operator'
                && ($kind === 'entity' || $kind === 'entity_multi')
                && ($ent = $db->entityOfConversation($conv))) {
                $key   = $ent[1] === '*' ? 'mult:' . $ent[0] : 'ent:' . $ent[0] . '|' . $ent[1];
                $live  = _msg_resolve_recipients($db, $ctx, [$key]);
                if ($live) $deliverTo = array_values(array_filter($live, fn($id) => (int)$id !== (int)$me['id']));
            }
        }
        $pos = ($me['kind'] === 'mobile' && isset($me['lat'], $me['lon']))
             ? ['lat'=>(float)$me['lat'], 'lon'=>(float)$me['lon'], 'ts'=>$me['pos_ts'] ? (int)$me['pos_ts'] : null] : null;
        $mid = $db->insertMessage($event, $conv, (int)$me['id'], $text, $deliverTo, $kind === 'broadcast', $pos);
        $photo = false;
        if ($hasPhoto) {
            $stored = _msg_store_photo($event, $mid, $_FILES['photo']);
            if ($stored) { $db->setAttachment($mid, $stored['filename'], $stored['w'], $stored['h']); $photo = true; }
        }
        echo json_encode(['ok'=>true, 'id'=>$mid, 'conversation_id'=>$conv, 'kind'=>$kind, 'recipients'=>count($deliverTo), 'photo'=>$photo]);
        exit;
    }

    case 'photo': {   // stream a message's attached photo (auth-gated: operator or member)
        $mid = (int)($_GET['id'] ?? $body['id'] ?? 0);
        $m   = $mid ? $db->messageById($mid) : null;
        if (!$m || empty($m['attachment'])) _msg_fail(404, 'No such photo');
        if (($me['kind'] ?? '') !== 'operator'
            && !$db->canAccessConversation($event, (int)$m['conversation_id'], (int)$me['id']))
            _msg_fail(403, 'Not a member of this conversation');
        $path = MessagingDb::photoDir($m['event']) . '/' . basename($m['attachment']);
        if (!is_file($path)) _msg_fail(404, 'Photo missing');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'webp'=>'image/webp', 'gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);            // overrides the JSON header set above
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    case 'poll': {
        $sinceId = (int)($_GET['since_id'] ?? $body['since_id'] ?? 0);
        $msgs    = $db->pollFor((int)$me['id'], $sinceId);
        $receipts= $db->receiptsForSender((int)$me['id'], $sinceId);
        $lastId  = $sinceId;
        foreach ($msgs as $m) if ($m['id'] > $lastId) $lastId = $m['id'];
        foreach ($receipts as $r) if ((int)$r['message_id'] > $lastId) $lastId = (int)$r['message_id'];
        echo json_encode(['messages'=>$msgs, 'receipts'=>$receipts, 'last_id'=>$lastId]);
        exit;
    }

    case 'thread': {
        $conv    = (int)($_GET['conversation_id'] ?? $body['conversation_id'] ?? 0);
        $sinceId = (int)($_GET['since_id'] ?? $body['since_id'] ?? 0);
        // Opt-in: ask for it and already-seen rows whose transcription arrived late come
        // back too. A client that omits it gets what it has always got -- which is the
        // point, because an older one would mishandle a repeat. See MessagingDb::thread.
        $sinceText = (int)($_GET['since_text_ts'] ?? $body['since_text_ts'] ?? 0);
        if (!$conv) _msg_fail(400, 'conversation_id required');
        // Only a member (or an operator) may read a thread — otherwise a mobile
        // could pull any conversation by guessing its id. Broadcasts are readable
        // by every participant in the event: they receive the message, so refusing
        // the thread would ring the alert tone for something they cannot open.
        if (($me['kind'] ?? '') !== 'operator' && !$db->canAccessConversation($event, $conv, (int)$me['id']))
            _msg_fail(403, 'Not a member of this conversation');
        // A stamp the caller can hand back next time. `time()` rather than the newest
        // row's, so a transcription written between this query and the next answer is
        // still ahead of the cursor and cannot fall through the gap.
        echo json_encode(['messages'=>$db->thread($conv, $sinceId, $sinceText),
                          'conversation_id'=>$conv, 'text_ts'=>time()]);
        exit;
    }

    case 'log': {   // an entry written to the event's log, addressed to nobody
        // Operators write log entries by hand; a Transcriber channel writes what it
        // heard on the air. Mobiles never do — the log is a net-control record, and a
        // tracker posting into it would be indistinguishable from net control's own
        // notes.
        if (!in_array($me['kind'] ?? '', ['operator', 'transcriber'], true)) {
            _msg_fail(403, 'Operators and transcribers only');
        }
        // substr rather than mb_substr: this server has no mbstring, and send bounds
        // its text the same way, so the two cannot disagree about what fits.
        //
        // NOT 280. That is the limit for something a person TYPED, which `send` uses
        // and should keep. A log entry is a machine transcription of however long
        // somebody held the key down, and a capped 120-second over runs to about 1,300
        // characters. At 280 the other ~78% was discarded here — after being received,
        // transcribed and delivered correctly — and the loss was invisible, because a
        // truncated entry reads as a complete one that simply ends mid-sentence.
        // Observed live: four of ten consecutive entries cut mid-word at exactly 280.
        //
        // 4000 is well clear of what MAX_CLIP_SECONDS can produce, so the bound stays
        // real without being reachable in normal use.
        $text = substr(trim((string)($body['text'] ?? '')), 0, 4000);
        if ($text === '') _msg_fail(400, 'text required');

        // Completing an entry whose audio was posted first. The Transcriber sends the
        // recording as soon as the over ends — so it can be listened to without waiting
        // on whisper — and comes back here with the words once it has them. Same entry,
        // so the log keeps one row per transmission rather than a clip and a
        // transcription that a reader has to pair up by eye.
        $entryId = (int)($body['entry_id'] ?? 0);
        if ($entryId > 0) {
            $prev = $db->messageById($entryId);
            // Its own entry, in this event, and still empty. A transcriber must not be
            // able to rewrite anybody else's line, nor a completed one of its own: the
            // outbox retries, and a retry that lands after the text is in would
            // otherwise overwrite it.
            if (!$prev || (int)$prev['sender_id'] !== (int)$me['id']
                || ($prev['event'] ?? '') !== $event) {
                _msg_fail(404, 'No such entry');
            }
            if (!$db->setMessageText($entryId, $text)) {
                // Already filled in — a duplicate delivery of something we accepted.
                // Not an error: answering ok lets the device drop it from the outbox
                // instead of retrying forever over an entry that is already correct.
                echo json_encode(['ok'=>true, 'id'=>$entryId, 'conversation_id'=>(int)$prev['conversation_id'],
                                  'kind'=>'log', 'duplicate'=>true]);
                exit;
            }
            echo json_encode(['ok'=>true, 'id'=>$entryId,
                              'conversation_id'=>(int)$prev['conversation_id'], 'kind'=>'log']);
            exit;
        }
        // No recipients, so insertMessage writes no deliveries: nothing is queued for
        // anyone to poll, nothing is announced, and no receipt can come back. The entry
        // exists only as history, which is the whole point of it.
        $conv = $db->resolveLogConversation($event);
        $id   = $db->insertMessage($event, $conv, (int)$me['id'], $text, [], false);
        // A Transcriber may attach the audio it transcribed. The entry is written
        // first and stands on its own: a clip that fails to store leaves the
        // transcription in the log rather than losing the entry with it, which is the
        // right way round — the text is the record and the audio is the check on it.
        $audio = null;
        if (($me['kind'] ?? '') === 'transcriber' && !empty($_FILES['audio'])) {
            $secs  = isset($body['audio_secs']) ? (float)$body['audio_secs'] : null;
            $audio = _msg_store_audio($event, $id, $_FILES['audio'], $secs);
            if ($audio) $db->setAudio($id, $audio['filename'], $audio['secs']);
            // Expire old clips occasionally rather than on every over — the check is a
            // query plus some unlinks, and on a busy net this runs every few seconds.
            // Keyed off the id so it is spread evenly and needs no timer or cron.
            if ($id % 20 === 0) $db->pruneAudio($event);
        }
        echo json_encode(['ok'=>true, 'id'=>$id, 'conversation_id'=>$conv, 'kind'=>'log',
                          'audio'=>$audio ? MessagingDb::audioUrl($event, $audio['filename']) : null]);
        exit;
    }

    case 'conversations': {   // the caller's conversation list (unread + last msg)
        echo json_encode([
            'conversations' => _msg_mark_stale(
                $db->conversationsFor($event, (int)$me['id'], ($me['kind'] ?? '') === 'operator'), $ctx),
            'can_manage'    => (bool)($ctx['authPerm']('messages.manage')),
        ]);
        exit;
    }

    case 'read': {
        $ids = array_map('intval', (array)($body['ids'] ?? []));
        if ($ids) $db->markRead((int)$me['id'], $ids);
        echo json_encode(['ok'=>true]);
        exit;
    }

    case 'history': {
        // The all-messages log is an operator/admin view — never exposed to mobiles.
        if (($me['kind'] ?? '') !== 'operator') _msg_fail(403, 'Operators only');
        echo json_encode([
            'messages'       => $db->history($event),
            'participants'   => $db->listParticipants($event),
            'can_manage'     => (bool)($ctx['authPerm']('messages.manage')),
        ]);
        exit;
    }

    case 'log_audio': {
        // The recording, posted the moment the over ended — before anything has been
        // transcribed. This exists because audio used to wait for whisper: the clip was
        // encoded after the model returned, so a listener heard the radio thirteen
        // seconds late on a quiet channel and minutes late behind a backlog. Sound has
        // no reason to queue behind text.
        //
        // The entry is created with NO text and gets it later, from `log` with
        // entry_id. Two consequences worth stating:
        //   - An over whose transcription is discarded as a hallucination still leaves
        //     its audio here. That is correct for listening: you want to hear what came
        //     over the air whatever a speech model made of it. It stays textless, so it
        //     does not put "(buzzing)" into the written log.
        //   - Readers that want the WRITTEN log skip empty entries; see history().
        if (($me['kind'] ?? '') !== 'transcriber') _msg_fail(403, 'Transcribers only');
        if (empty($_FILES['audio'])) _msg_fail(400, 'audio required');

        $conv = $db->resolveLogConversation($event);
        $id   = $db->insertMessage($event, $conv, (int)$me['id'], '', [], false);
        $secs = isset($body['audio_secs']) ? (float)$body['audio_secs'] : null;
        $audio = _msg_store_audio($event, $id, $_FILES['audio'], $secs);
        if (!$audio) {
            // Nothing stored means nothing to listen to and nothing to say, so the
            // empty row would be litter. Remove it rather than leave a permanent blank.
            $db->deleteMessage($id);
            _msg_fail(400, 'audio rejected');
        }
        $db->setAudio($id, $audio['filename'], $audio['secs']);
        if ($id % 20 === 0) $db->pruneAudio($event);
        echo json_encode(['ok'=>true, 'id'=>$id, 'conversation_id'=>$conv, 'kind'=>'log',
                          'audio'=>MessagingDb::audioUrl($event, $audio['filename'])]);
        exit;
    }

    case 'monitor': {
        // Opt-in read-only feed of the event's traffic, for a phone that wants to hear
        // everything rather than only what was addressed to it. Any subscribed
        // participant may call it; the two flags are the client's own subscription
        // settings ("all messages" / "radio traffic") passed through as filters.
        //
        // Note what is NOT here: no markDelivered, no markRead, no delivery rows of any
        // kind. Monitoring a message must leave no trace on it, or the sender's receipts
        // start counting strangers. MessagingDb::monitor() carries the full argument.
        $sinceId = (int)($_GET['since_id'] ?? $body['since_id'] ?? 0);
        $all     = !empty($_GET['all'] ?? $body['all'] ?? false);
        $log     = !empty($_GET['log'] ?? $body['log'] ?? false);
        // The viewer, so each message can say whether it was addressed to them. The
        // phone announces addressed and monitored traffic on two separate paths and this
        // feed carries both; without the tag a message sent to this operator is read
        // aloud twice. See MessagingDb::monitor().
        // Same opt-in as ?thread. Absent for every client built before 2026-08-29, which
        // is deliberate: v1.25.4's MonitorService appends without deduping and queues
        // clips with no msgId, so handing it a row it already has would replay the
        // recording. New behaviour is asked for, never assumed.
        $sinceText = (int)($_GET['since_text_ts'] ?? $body['since_text_ts'] ?? 0);
        $res     = $db->monitor($event, $sinceId, $all, $log, (int)$me['id'], $sinceText);
        echo json_encode([
            'messages' => $res['messages'],
            'skipped'  => $res['skipped'],
            'last_id'  => $res['last_id'],
            // See ?thread: the server's clock, not the newest row's, so nothing written
            // while this request was in flight lands behind the cursor.
            'text_ts'  => time(),
            'max_age'  => MessagingDb::MONITOR_MAX_AGE,
        ]);
        exit;
    }

    case 'rename': {
        if (($me['kind'] ?? '') !== 'operator') _msg_fail(403, 'Only operators can rename');
        $newName = substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $body['name'] ?? '')), 0, 30);
        if ($newName === '') _msg_fail(400, 'Name required');
        // Only a name held by ANOTHER live operator session blocks the rename — same
        // 90s-stale + token test as `subscribe`, so a departed operator's name auto-frees.
        $clash = $db->participantByKey($event, $newName);
        if ($clash && (int)$clash['id'] !== (int)$me['id']
            && ($clash['kind'] ?? '') === 'operator'
            && !empty($clash['last_seen']) && (time() - (int)$clash['last_seen']) < 90
            && !empty($clash['token'])) {
            _msg_fail(409, 'That name is in use — choose another.');
        }
        // run() throws now, and the web client relabels itself from this response — so a
        // rename that did not happen has to come back as an error rather than an ok.
        try {
            $db->renameParticipant((int)$me['id'], $newName);
        } catch (Throwable $e) {
            error_log('rename failed for participant ' . (int)$me['id'] . ': ' . $e->getMessage());
            _msg_fail(500, 'Could not save that name.');
        }
        echo json_encode(['ok'=>true, 'name'=>$newName]);
        exit;
    }

    case 'operators': {   // admin: list operators for the "Manage operators" view
        if (!$ctx['authPerm']('messages.manage')) _msg_fail(403, 'Missing permission: messages.manage');
        echo json_encode(['operators'=>$db->listOperators($event), 'me'=>(int)$me['id']]);
        exit;
    }

    case 'disconnect': {  // admin: disconnect an operator, freeing their name
        if (!$ctx['authPerm']('messages.manage')) _msg_fail(403, 'Missing permission: messages.manage');
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) _msg_fail(400, 'id required');
        echo json_encode(['ok'=>$db->disconnectParticipant($id)]);
        exit;
    }

    case 'flush': {
        if (!$ctx['authPerm']('messages.manage')) _msg_fail(403, 'Missing permission: messages.manage');
        echo json_encode(['ok'=>true, 'deleted'=>$db->flushEvent($event)]);
        exit;
    }

    default:
        _msg_fail(400, 'Unknown action');
    }
}

// ── Legacy mobile-app compatibility shim ────────────────────────────────────────
// The deployed Flutter app speaks the old ?mobile=… protocol: it sends
// {token,text,to} and expects polls to return [{id,from_label,text,ts}]. These
// helpers map that onto the new SQLite core so old phones keep working unchanged.

/** Current event for a parsed config (blank between events → 'default'). */
function messaging_ctx_event(array $mcfg): string { return trim($mcfg['event'] ?? '') ?: 'default'; }

/** Re-decide each conversation's `stale` flag from the tracker feed.
 *
 *  conversationsFor answers it from participants.last_seen, which is the right
 *  question asked of the wrong column: upsertParticipant sets last_seen on every
 *  write, and it is written in bulk by paths that say nothing about anyone being
 *  active — _msg_ensure_all_mobiles touches every tracker on each broadcast, and
 *  resolving a recipient touches whoever it resolved. A station that left the event
 *  weeks ago therefore looks freshly seen the moment somebody else broadcasts, and
 *  its thread never ages out.
 *
 *  A mobile's real freshness is its tracker lastUpdate, which is what the recipient
 *  picker has always used (_msg_addressable) and why the picker was right while the
 *  conversation list was not. Operators have no tracker record and keep last_seen,
 *  which for them is only ever written by touchParticipant and so means what it says. */
function _msg_mark_stale(array $rows, array $ctx): array
{
    $now  = time();
    $seen = [];
    foreach (_msg_load_trackers($ctx['mobileFile']) as $t) {
        if (!empty($t['callsign'])) $seen[$t['callsign']] = (int)($t['lastUpdate'] ?? 0);
    }
    foreach ($rows as &$r) {
        if (in_array($r['kind'] ?? '', ['broadcast', 'log'], true)) { $r['stale'] = false; continue; }
        $r['stale'] = true;
        foreach ($r['members'] ?? [] as $m) {
            $last = ($m['kind'] ?? '') === 'mobile'
                  ? ($seen[$m['key']] ?? 0)
                  : (int)($m['last_seen'] ?? 0);
            if ($last > 0 && ($now - $last) <= 86400) { $r['stale'] = false; break; }
        }
    }
    return $rows;
}

/** Reduce a hydrated message row to the legacy {id,from_label,text,ts} shape.
 *
 *  The extra keys are additive and older app builds ignore them. They exist for the
 *  Apple Watch relay, which aims the next reply at the thread a message arrived on:
 *  conversation_id has to survive this shim for that to be possible at all.
 *  from_short/from_kind let the phone build the same "M141 Dirck" label the new API
 *  produces, so the watch never re-implements the labelling rules. from_key is the
 *  addressable identity of the sender, needed to answer a broadcast — which goes back
 *  to the calling station rather than out to the whole net. */
function _msg_legacy_shape(array $m): array
{
    return ['id'=>$m['id'], 'from_label'=>($m['from_name'] !== '' ? $m['from_name'] : ($m['from_key'] ?? '')),
            'text'=>$m['text'], 'ts'=>$m['ts'],
            'conversation_id'=>(int)($m['conversation_id'] ?? 0),
            'from_short'=>$m['from_short'] ?? null,
            // Travels with from_short for the same stated reason: the watch must not
            // re-implement the labelling rules, and expanding an id is one of them.
            'from_spoken'=>$m['from_spoken'] ?? null,
            'from_kind'=>$m['from_kind'] ?? null,
            'from_key'=>$m['from_key'] ?? null,
            'broadcast'=>(bool)($m['broadcast'] ?? false)];
}

/** Un-acked messages for the mobile behind $token (legacy shape). Acks $ackIds
 *  first. Returns null on an invalid/unknown token. */
function messaging_legacy_pending(array $ctx, string $token, array $ackIds): ?array
{
    $db = new MessagingDb();
    $me = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) return null;
    $db->touchParticipant((int)$me['id']);
    // Delivered, not read. The ack means the device has it and should stop being
    // sent it again; whether a human has seen it is a different question, answered
    // by the explicit `read` action when a thread is opened.
    if ($ackIds) $db->markDelivered((int)$me['id'], $ackIds);
    return array_map('_msg_legacy_shape', $db->pendingFor((int)$me['id']));
}

/** Message history for the mobile behind $token (legacy shape, last 20). Null if bad token. */
function messaging_legacy_history(array $ctx, string $token): ?array
{
    $db = new MessagingDb();
    $me = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) return null;
    return array_map('_msg_legacy_shape', $db->messagesForParticipant((int)$me['id'], 20));
}

/** Names of operators actively monitoring (last 60 s). Null if bad token. */
function messaging_legacy_recipients(array $ctx, string $token): ?array
{
    $db = new MessagingDb();
    $me = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) return null;
    return array_values(array_map(fn($o) => $o['display_name'], $db->onlineOperators($ctx['event'], 60)));
}

/** Legacy send from a mobile. Returns [httpCode, respArray]. $to = a specific
 *  operator name, or '' / 'web' → every operator currently monitoring. */
function messaging_legacy_send(array $ctx, string $token, string $text, string $to): array
{
    // Same 1000 as `send` -- these two bound the same thing and must not disagree,
    // or which limit applies depends on which client sent it.
    $text = substr(trim($text), 0, 1000);
    if ($text === '') return [400, ['error'=>'Message required']];
    $db = new MessagingDb();
    $me = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) return [404, ['error'=>'Token not found']];
    $to  = trim($to);
    $pos = isset($me['lat'], $me['lon'])
         ? ['lat'=>(float)$me['lat'], 'lon'=>(float)$me['lon'], 'ts'=>$me['pos_ts'] ? (int)$me['pos_ts'] : null] : null;

    // Reply into the conversation the mobile last received a message in, so a
    // reply to a GROUP goes back to the whole group (operator sees it in that
    // thread; the other members receive it) instead of forking a new 1:1. Skip
    // broadcasts — you don't reply-all to an announcement. The app sends
    // to=<sender name>; honor a genuinely different operator only when they
    // aren't already in that conversation.
    $recent = $db->recentInboundConversation((int)$me['id']);
    if ($recent && $recent['kind'] !== 'broadcast') {
        $toOp = ($to !== '' && strcasecmp($to, 'web') !== 0)
              ? $db->participantByKey($ctx['event'], $to) : null;
        if (!$toOp || $db->isConversationMember((int)$recent['id'], (int)$toOp['id'])) {
            $conv = (int)$recent['id'];
            $deliverTo = $db->conversationRecipients($ctx['event'], $conv, false, (int)$me['id']);
            if ($deliverTo) {
                $mid = $db->insertMessage($ctx['event'], $conv, (int)$me['id'], $text, $deliverTo, false, $pos);
                return [200, ['ok'=>true, 'id'=>$mid]];
            }
        }
    }

    // No conversation to reply into → start a direct thread to the operators.
    $ops = $db->onlineOperators($ctx['event'], 60);
    if (!$ops) return [503, ['error'=>'no_receivers', 'message'=>'No one is currently monitoring messages. Try again later.']];
    $recips = [];
    if ($to !== '' && strcasecmp($to, 'web') !== 0) {
        foreach ($ops as $o) if (strcasecmp($o['display_name'], $to) === 0) { $recips[] = (int)$o['id']; break; }
    }
    if (!$recips) $recips = array_map(fn($o) => (int)$o['id'], $ops);   // 'web' / unknown → all operators
    [$conv, $kind] = $db->resolveConversation($ctx['event'], (int)$me['id'], $recips, false, null, null);
    $deliverTo = $db->conversationRecipients($ctx['event'], $conv, false, (int)$me['id']);
    $mid = $db->insertMessage($ctx['event'], $conv, (int)$me['id'], $text, $deliverTo, false, $pos);
    return [200, ['ok'=>true, 'id'=>$mid]];
}
