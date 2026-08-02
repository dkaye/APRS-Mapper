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
    if ($p) return $p;
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
function _msg_resolve_recipients(MessagingDb $db, array $ctx, array $keys): array
{
    $ids = [];
    $trackers = null;
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '') continue;
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
        echo json_encode(['token'=>$token, 'name'=>$name]);
        exit;
    }

    // Everything else needs a valid caller token.
    $token = trim($body['token'] ?? $_GET['token'] ?? '');
    $me    = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) _msg_fail(403, 'Not subscribed');
    $db->touchParticipant((int)$me['id']);

    switch ($action) {

    case 'participants': {   // addressable recipient list for the picker
        $rows = $db->listParticipants($event);
        $now  = time();
        $out  = array_map(fn($p) => [
            'id'=>(int)$p['id'], 'kind'=>$p['kind'], 'key'=>$p['key'],
            'name'=>$p['display_name'], 'short_id'=>$p['short_id'],
            'online'=> !empty($p['last_seen']) && ($now - (int)$p['last_seen']) < 90,
            'self'=> (int)$p['id'] === (int)$me['id'],
        ], $rows);
        echo json_encode(['participants'=>$out, 'me'=>(int)$me['id']]);
        exit;
    }

    case 'send': {
        $text = substr(trim($body['text'] ?? ''), 0, 280);
        if ($text === '') _msg_fail(400, 'Message text required');
        $convId    = isset($body['conversation_id']) ? (int)$body['conversation_id'] : null;
        $recipients= $body['recipients'] ?? [];
        $broadcast = ($recipients === 'all' || (is_array($recipients) && in_array('all', $recipients, true)));
        $title     = isset($body['title']) ? substr(trim($body['title']), 0, 40) : null;
        $rIds      = $broadcast ? [] : _msg_resolve_recipients($db, $ctx, is_array($recipients) ? $recipients : [$recipients]);
        if (!$broadcast && !$convId && !$rIds) _msg_fail(400, 'Recipient required');
        [$conv, $kind] = $db->resolveConversation($event, (int)$me['id'], $rIds, $broadcast, $convId, $title);
        // A broadcast reaches every registered mobile, so make sure they all exist.
        if ($kind === 'broadcast') _msg_ensure_all_mobiles($db, $ctx);
        $deliverTo = $db->conversationRecipients($event, $conv, $kind === 'broadcast', (int)$me['id']);
        $pos = ($me['kind'] === 'mobile' && isset($me['lat'], $me['lon']))
             ? ['lat'=>(float)$me['lat'], 'lon'=>(float)$me['lon'], 'ts'=>$me['pos_ts'] ? (int)$me['pos_ts'] : null] : null;
        $mid = $db->insertMessage($event, $conv, (int)$me['id'], $text, $deliverTo, $kind === 'broadcast', $pos);
        echo json_encode(['ok'=>true, 'id'=>$mid, 'conversation_id'=>$conv, 'kind'=>$kind, 'recipients'=>count($deliverTo)]);
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
        if (!$conv) _msg_fail(400, 'conversation_id required');
        echo json_encode(['messages'=>$db->thread($conv, $sinceId), 'conversation_id'=>$conv]);
        exit;
    }

    case 'conversations': {   // the caller's conversation list (unread + last msg)
        echo json_encode(['conversations'=>$db->conversationsFor($event, (int)$me['id'])]);
        exit;
    }

    case 'read': {
        $ids = array_map('intval', (array)($body['ids'] ?? []));
        if ($ids) $db->markRead((int)$me['id'], $ids);
        echo json_encode(['ok'=>true]);
        exit;
    }

    case 'history': {
        echo json_encode([
            'messages'       => $db->history($event),
            'participants'   => $db->listParticipants($event),
            'can_delete_all' => (bool)($ctx['authPerm']('messages.delete_all')),
        ]);
        exit;
    }

    case 'rename': {
        if (($me['kind'] ?? '') !== 'operator') _msg_fail(403, 'Only operators can rename');
        $newName = substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $body['name'] ?? '')), 0, 30);
        if ($newName === '') _msg_fail(400, 'Name required');
        $clash = $db->participantByKey($event, $newName);
        if ($clash && (int)$clash['id'] !== (int)$me['id']) _msg_fail(409, 'That name is in use — choose another.');
        $db->renameParticipant((int)$me['id'], $newName);
        echo json_encode(['ok'=>true, 'name'=>$newName]);
        exit;
    }

    case 'flush': {
        if (!$ctx['authPerm']('messages.delete_all')) _msg_fail(403, 'Missing permission: messages.delete_all');
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

/** Reduce a hydrated message row to the legacy {id,from_label,text,ts} shape. */
function _msg_legacy_shape(array $m): array
{
    return ['id'=>$m['id'], 'from_label'=>($m['from_name'] !== '' ? $m['from_name'] : ($m['from_key'] ?? '')),
            'text'=>$m['text'], 'ts'=>$m['ts']];
}

/** Un-acked messages for the mobile behind $token (legacy shape). Acks $ackIds
 *  first. Returns null on an invalid/unknown token. */
function messaging_legacy_pending(array $ctx, string $token, array $ackIds): ?array
{
    $db = new MessagingDb();
    $me = _msg_resolve_sender($db, $ctx, $token);
    if (!$me) return null;
    $db->touchParticipant((int)$me['id']);
    if ($ackIds) $db->markRead((int)$me['id'], $ackIds);
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
    $text = substr(trim($text), 0, 280);
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
