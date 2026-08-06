<?php
/**
 * messaging_db.php — SQLite data layer for the MARS messaging system.
 *
 * A single event-scoped database (like the auth users.db) holding participants,
 * conversations (direct / group / broadcast), messages, and per-recipient
 * deliveries (delivery + read receipts). Replaces the old per-event messages.json
 * + mobile_trackers.json pending_msgs model.
 *
 * House style: SQLite3 class, DB outside the web root under /var/lib/marsaprs/.
 * The path is overridable via the MARSAPRS_MESSAGES_DB env var for testing.
 *
 * Conversation identity:
 *   - direct    : exactly 2 members, deduped by the member set (one thread/pair).
 *   - group     : 3+ members, deduped by the exact member set (a "group text").
 *   - broadcast : one per event; recipients snapshotted at send time.
 *
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

if (!defined('MARSAPRS_MESSAGES_DB')) {
    define('MARSAPRS_MESSAGES_DB', getenv('MARSAPRS_MESSAGES_DB') ?: '/var/lib/marsaprs/messages.db');
}

class MessagingDb
{
    private SQLite3 $db;

    public function __construct(?string $path = null)
    {
        $path = $path ?: MARSAPRS_MESSAGES_DB;
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $this->db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->initSchema();
    }

    public function close(): void { $this->db->close(); }

    private function initSchema(): void
    {
        $this->db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS participants (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            event        TEXT NOT NULL,
            kind         TEXT NOT NULL,            -- 'mobile' | 'operator'
            key          TEXT NOT NULL,            -- mobile callsign, or operator display name
            display_name TEXT NOT NULL,
            short_id     TEXT,                     -- M0xx for mobiles
            token        TEXT,                     -- current session/auth token
            last_seen    INTEGER,
            lat          REAL, lon REAL, pos_ts INTEGER,
            UNIQUE(event, kind, key)
        );
        CREATE INDEX IF NOT EXISTS idx_part_token ON participants(token);
        CREATE INDEX IF NOT EXISTS idx_part_event ON participants(event);

        CREATE TABLE IF NOT EXISTS conversations (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            event       TEXT NOT NULL,
            kind        TEXT NOT NULL,             -- 'direct' | 'group' | 'broadcast'
            title       TEXT,
            member_hash TEXT NOT NULL,             -- canonical member set ('*' for broadcast)
            created_ts  INTEGER,
            UNIQUE(event, member_hash)
        );

        CREATE TABLE IF NOT EXISTS conversation_members (
            conversation_id INTEGER NOT NULL,
            participant_id  INTEGER NOT NULL,
            PRIMARY KEY (conversation_id, participant_id)
        );

        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,  -- monotonic wire id
            event           TEXT NOT NULL,
            conversation_id INTEGER NOT NULL,
            sender_id       INTEGER NOT NULL,
            ts              INTEGER NOT NULL,
            text            TEXT NOT NULL,
            lat             REAL, lon REAL, pos_ts INTEGER,
            broadcast       INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_msg_event ON messages(event, id);
        CREATE INDEX IF NOT EXISTS idx_msg_conv  ON messages(conversation_id, id);

        CREATE TABLE IF NOT EXISTS deliveries (
            message_id   INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            delivered_ts INTEGER,
            read_ts      INTEGER,
            PRIMARY KEY (message_id, recipient_id)
        );
        CREATE INDEX IF NOT EXISTS idx_deliv_recip ON deliveries(recipient_id, message_id);
        SQL);

        // Migration: photo attachment columns on messages (one photo per message).
        // CREATE TABLE IF NOT EXISTS won't add columns to an existing DB, so add
        // them here if missing.
        $cols = [];
        $r = $this->db->query('PRAGMA table_info(messages)');
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $cols[$row['name']] = true;
        if (!isset($cols['attachment'])) $this->db->exec('ALTER TABLE messages ADD COLUMN attachment TEXT');
        if (!isset($cols['attach_w']))   $this->db->exec('ALTER TABLE messages ADD COLUMN attach_w INTEGER');
        if (!isset($cols['attach_h']))   $this->db->exec('ALTER TABLE messages ADD COLUMN attach_h INTEGER');
    }

    // ── Photo attachments ──────────────────────────────────────────────────────
    // Photos are stored as files (GD isn't available server-side), per event,
    // beside messages.db and served only through the auth-gated ?messaging=photo
    // endpoint. flushEvent() and the admin event-export reach them via photoDir().
    public static function photoBaseDir(): string
    {
        return dirname(MARSAPRS_MESSAGES_DB) . '/photos';
    }
    public static function photoDir(string $event): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $event);
        if ($safe === '' || $safe === null) $safe = 'default';
        return self::photoBaseDir() . '/' . $safe;
    }
    public function setAttachment(int $mid, string $filename, ?int $w, ?int $h): void
    {
        $this->run('UPDATE messages SET attachment=:a, attach_w=:w, attach_h=:h WHERE id=:id',
                   [':a'=>$filename, ':w'=>$w, ':h'=>$h, ':id'=>$mid]);
    }
    public function messageById(int $mid): ?array
    {
        return $this->one('SELECT * FROM messages WHERE id=:id', [':id'=>$mid]);
    }
    /** [id => attachment filename] for every message in the event that has a photo. */
    public function attachmentsForEvent(string $event): array
    {
        $out = [];
        foreach ($this->all("SELECT id, attachment FROM messages WHERE event=:e AND attachment IS NOT NULL AND attachment<>''", [':e'=>$event]) as $r) {
            $out[(int)$r['id']] = $r['attachment'];
        }
        return $out;
    }

    // ── small helpers ─────────────────────────────────────────────────────────
    private function one(string $sql, array $params = []): ?array
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        return $row === false ? null : $row;
    }
    private function all(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $r = $st->execute(); $out = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
        return $out;
    }
    private function run(string $sql, array $params = []): void
    {
        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
    }

    // ── participants ──────────────────────────────────────────────────────────
    /** Insert or update a participant; returns its id. `pos` = [lat,lon,pos_ts] or null. */
    public function upsertParticipant(string $event, string $kind, string $key,
                                      string $displayName, ?string $shortId,
                                      ?string $token, ?array $pos = null): int
    {
        $now = time();
        $this->run(
            'INSERT INTO participants (event,kind,key,display_name,short_id,token,last_seen,lat,lon,pos_ts)
             VALUES (:e,:k,:key,:dn,:sid,:tok,:ls,:lat,:lon,:pts)
             ON CONFLICT(event,kind,key) DO UPDATE SET
                display_name=excluded.display_name,
                short_id=COALESCE(excluded.short_id, participants.short_id),
                token=COALESCE(excluded.token, participants.token),
                last_seen=excluded.last_seen,
                lat=COALESCE(excluded.lat, participants.lat),
                lon=COALESCE(excluded.lon, participants.lon),
                pos_ts=COALESCE(excluded.pos_ts, participants.pos_ts)',
            [':e'=>$event, ':k'=>$kind, ':key'=>$key, ':dn'=>$displayName, ':sid'=>$shortId,
             ':tok'=>$token, ':ls'=>$now,
             ':lat'=>$pos['lat'] ?? null, ':lon'=>$pos['lon'] ?? null, ':pts'=>$pos['ts'] ?? null]);
        return (int)($this->one('SELECT id FROM participants WHERE event=:e AND kind=:k AND key=:key',
                                 [':e'=>$event, ':k'=>$kind, ':key'=>$key])['id'] ?? 0);
    }

    public function participantByToken(string $token): ?array
    {
        if ($token === '') return null;
        return $this->one('SELECT * FROM participants WHERE token=:t', [':t'=>$token]);
    }
    public function participantById(int $id): ?array
    {
        return $this->one('SELECT * FROM participants WHERE id=:i', [':i'=>$id]);
    }
    public function participantByKey(string $event, string $key): ?array
    {
        return $this->one('SELECT * FROM participants WHERE event=:e AND key=:k',
                          [':e'=>$event, ':k'=>$key]);
    }
    public function touchParticipant(int $id): void
    {
        $this->run('UPDATE participants SET last_seen=:n WHERE id=:i', [':n'=>time(), ':i'=>$id]);
    }
    /** Addressable participants for the event; `$onlineSecs` filters by last_seen recency. */
    public function listParticipants(string $event, ?int $onlineSecs = null): array
    {
        $sql = 'SELECT id,kind,key,display_name,short_id,last_seen,lat,lon,pos_ts
                FROM participants WHERE event=:e';
        $p = [':e'=>$event];
        if ($onlineSecs !== null) { $sql .= ' AND last_seen >= :cut'; $p[':cut'] = time() - $onlineSecs; }
        return $this->all($sql . ' ORDER BY kind, display_name', $p);
    }

    /** Operators for the admin "Manage operators" view. Only those still HOLDING a name
     *  (a live session token) are returned — a signed-out operator holds nothing and has
     *  nothing to disconnect, so it's omitted. The client uses last_seen to show
     *  connected (recent) vs idle (stale-but-still-holding). */
    public function listOperators(string $event): array
    {
        return $this->all(
            "SELECT id, display_name, last_seen, 1 AS has_token
             FROM participants
             WHERE event=:e AND kind='operator' AND token IS NOT NULL AND token != ''
             ORDER BY last_seen DESC",
            [':e'=>$event]);
    }

    /** Disconnect an operator: clear its session token and stale its last_seen. This logs
     *  it out AND frees its name for reuse — both `subscribe` and `rename` treat a name as
     *  in-use only while a live token was seen within the lock window. The participant row
     *  and its message history are left intact. Returns true if an operator row matched. */
    public function disconnectParticipant(int $id): bool
    {
        $this->run("UPDATE participants SET token=NULL, last_seen=0 WHERE id=:i AND kind='operator'",
                   [':i'=>$id]);
        return (int)($this->one('SELECT COUNT(*) AS c FROM participants WHERE id=:i', [':i'=>$id])['c'] ?? 0) > 0;
    }

    // ── conversations ─────────────────────────────────────────────────────────
    private static function memberHash(array $participantIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $participantIds)));
        sort($ids, SORT_NUMERIC);
        return implode(',', $ids);
    }

    /**
     * Find-or-create the conversation for a message. Returns [id, kind].
     *  - $conversationId given → reuse it (a reply).
     *  - $broadcast           → the event's single broadcast conversation.
     *  - 2 members            → direct; 3+ → group. Both deduped by member set.
     */
    public function resolveConversation(string $event, int $senderId, array $recipientIds,
                                        bool $broadcast, ?int $conversationId,
                                        ?string $title = null): array
    {
        if ($conversationId) {
            $c = $this->one('SELECT id,kind FROM conversations WHERE id=:i AND event=:e',
                            [':i'=>$conversationId, ':e'=>$event]);
            if ($c) return [(int)$c['id'], $c['kind']];
        }
        if ($broadcast) {
            return [$this->findOrCreateConversation($event, 'broadcast', '*', [$senderId], $title), 'broadcast'];
        }
        $members = array_values(array_unique(array_merge([$senderId], array_map('intval', $recipientIds))));
        $kind    = count($members) > 2 ? 'group' : 'direct';
        $hash    = self::memberHash($members);
        return [$this->findOrCreateConversation($event, $kind, $hash, $members, $title), $kind];
    }

    private function findOrCreateConversation(string $event, string $kind, string $hash,
                                              array $members, ?string $title): int
    {
        $ex = $this->one('SELECT id FROM conversations WHERE event=:e AND member_hash=:h',
                         [':e'=>$event, ':h'=>$hash]);
        if ($ex) {
            if ($title) $this->run('UPDATE conversations SET title=:t WHERE id=:i', [':t'=>$title, ':i'=>$ex['id']]);
            return (int)$ex['id'];
        }
        $this->run('INSERT INTO conversations (event,kind,title,member_hash,created_ts)
                    VALUES (:e,:k,:t,:h,:n)',
                   [':e'=>$event, ':k'=>$kind, ':t'=>$title, ':h'=>$hash, ':n'=>time()]);
        $cid = (int)$this->db->lastInsertRowID();
        foreach ($members as $pid) {
            $this->run('INSERT OR IGNORE INTO conversation_members (conversation_id,participant_id)
                        VALUES (:c,:p)', [':c'=>$cid, ':p'=>(int)$pid]);
        }
        return $cid;
    }

    /** Current member ids of a conversation (for broadcast we snapshot all event participants). */
    public function conversationRecipients(string $event, int $conversationId, bool $broadcast, int $senderId): array
    {
        if ($broadcast) {
            $rows = $this->all('SELECT id FROM participants WHERE event=:e', [':e'=>$event]);
        } else {
            $rows = $this->all('SELECT participant_id AS id FROM conversation_members WHERE conversation_id=:c',
                               [':c'=>$conversationId]);
        }
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        return array_values(array_filter($ids, fn($id) => $id !== $senderId));  // sender doesn't receive own msg
    }

    // ── messages + deliveries ─────────────────────────────────────────────────
    /** Insert a message and its per-recipient deliveries. Returns the new message id. */
    public function insertMessage(string $event, int $conversationId, int $senderId, string $text,
                                  array $recipientIds, bool $broadcast, ?array $pos = null): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->run('INSERT INTO messages (event,conversation_id,sender_id,ts,text,lat,lon,pos_ts,broadcast)
                        VALUES (:e,:c,:s,:ts,:tx,:lat,:lon,:pts,:b)',
                       [':e'=>$event, ':c'=>$conversationId, ':s'=>$senderId, ':ts'=>time(), ':tx'=>$text,
                        ':lat'=>$pos['lat'] ?? null, ':lon'=>$pos['lon'] ?? null, ':pts'=>$pos['ts'] ?? null,
                        ':b'=>$broadcast ? 1 : 0]);
            $mid = (int)$this->db->lastInsertRowID();
            foreach ($recipientIds as $rid) {
                $this->run('INSERT OR IGNORE INTO deliveries (message_id,recipient_id) VALUES (:m,:r)',
                           [':m'=>$mid, ':r'=>(int)$rid]);
            }
            $this->db->exec('COMMIT');
            return $mid;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Hydrate message rows with sender identity + conversation info for the API. */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $m) {
            $s = $this->participantById((int)$m['sender_id']);
            $out[] = [
                'id'              => (int)$m['id'],
                'conversation_id' => (int)$m['conversation_id'],
                'ts'              => (int)$m['ts'],
                'text'            => $m['text'],
                'broadcast'       => (int)$m['broadcast'] === 1,
                'from_id'         => (int)$m['sender_id'],
                'from_kind'       => $s['kind'] ?? null,
                'from_key'        => $s['key'] ?? null,           // callsign / operator name
                'from_short'      => $s['short_id'] ?? null,      // M0xx
                'from_name'       => $s['display_name'] ?? ($m['from_key'] ?? ''),
                'lat'             => isset($m['lat']) ? (float)$m['lat'] : null,
                'lon'             => isset($m['lon']) ? (float)$m['lon'] : null,
                'pos_ts'          => isset($m['pos_ts']) ? (int)$m['pos_ts'] : null,
                'photo'           => !empty($m['attachment']),
                'photo_w'         => isset($m['attach_w']) ? (int)$m['attach_w'] : null,
                'photo_h'         => isset($m['attach_h']) ? (int)$m['attach_h'] : null,
            ];
        }
        return $out;
    }

    /** New messages delivered to $recipientId with id > $sinceId; marks them delivered. */
    public function pollFor(int $recipientId, int $sinceId): array
    {
        $rows = $this->all(
            'SELECT m.* FROM messages m
               JOIN deliveries d ON d.message_id = m.id
             WHERE d.recipient_id = :r AND m.id > :since
             ORDER BY m.id', [':r'=>$recipientId, ':since'=>$sinceId]);
        if ($rows) {
            $now = time();
            foreach ($rows as $m) {
                $this->run('UPDATE deliveries SET delivered_ts=:n
                            WHERE message_id=:m AND recipient_id=:r AND delivered_ts IS NULL',
                           [':n'=>$now, ':m'=>$m['id'], ':r'=>$recipientId]);
            }
        }
        return $this->hydrate($rows);
    }

    /** All messages in a conversation (thread view). */
    public function thread(int $conversationId, int $sinceId = 0): array
    {
        return $this->hydrate($this->all(
            'SELECT * FROM messages WHERE conversation_id=:c AND id > :s ORDER BY id',
            [':c'=>$conversationId, ':s'=>$sinceId]));
    }

    /** Every message in the event (all-messages / admin view), each tagged with a
     *  recipient (`to_label`) derived from its conversation. */
    public function history(string $event): array
    {
        $msgs = $this->hydrate($this->all('SELECT * FROM messages WHERE event=:e ORDER BY id', [':e'=>$event]));
        $kind = []; $members = [];
        foreach ($this->all('SELECT id,kind FROM conversations WHERE event=:e', [':e'=>$event]) as $c) {
            $kind[(int)$c['id']] = $c['kind'];
        }
        foreach ($this->all(
            'SELECT cm.conversation_id AS cid, p.id, p.kind, p.key, p.short_id, p.display_name
               FROM conversation_members cm JOIN participants p ON p.id=cm.participant_id
              WHERE cm.conversation_id IN (SELECT id FROM conversations WHERE event=:e)', [':e'=>$event]) as $m) {
            $members[(int)$m['cid']][] = $m;
        }
        $label = fn($p) => $p['kind'] === 'mobile'
            ? trim((($p['short_id'] ? $p['short_id'] . ' ' : '') . $p['display_name']))
            : $p['display_name'];
        foreach ($msgs as &$msg) {
            $cid = $msg['conversation_id'];
            if (($kind[$cid] ?? '') === 'broadcast' || $msg['broadcast']) { $msg['to_label'] = 'All Trackers'; continue; }
            $others = array_filter($members[$cid] ?? [], fn($p) => (int)$p['id'] !== $msg['from_id']);
            $msg['to_label'] = implode(', ', array_map($label, $others));
        }
        return $msgs;
    }

    /** Conversation list for a participant: last message + unread count + the other
     *  members (for labelling) per thread, plus a preview of the latest message. */
    public function conversationsFor(string $event, int $participantId): array
    {
        $rows = $this->all(
            'SELECT c.id, c.kind, c.title,
                    (SELECT COUNT(*) FROM deliveries d JOIN messages mm ON mm.id=d.message_id
                       WHERE d.recipient_id=:p AND mm.conversation_id=c.id AND d.read_ts IS NULL) AS unread,
                    (SELECT MAX(id) FROM messages WHERE conversation_id=c.id) AS last_id
             FROM conversations c
             JOIN conversation_members cm ON cm.conversation_id=c.id
             WHERE c.event=:e AND cm.participant_id=:p
             ORDER BY last_id DESC', [':e'=>$event, ':p'=>$participantId]);
        foreach ($rows as &$r) {
            $r['unread']  = (int)$r['unread'];
            $r['last_id'] = (int)($r['last_id'] ?? 0);
            // Other members (excludes the caller) for the thread label.
            $mem = $this->all(
                'SELECT p.id,p.kind,p.key,p.short_id,p.display_name
                   FROM conversation_members cm JOIN participants p ON p.id=cm.participant_id
                  WHERE cm.conversation_id=:c AND p.id<>:me
                  ORDER BY p.kind, p.display_name', [':c'=>$r['id'], ':me'=>$participantId]);
            $r['members'] = $mem;
            // Latest-message preview (sender + text).
            $last = $r['last_id'] ? $this->one(
                'SELECT sender_id, text, ts FROM messages WHERE id=:i', [':i'=>$r['last_id']]) : null;
            if ($last) {
                $s = $this->participantById((int)$last['sender_id']);
                $r['preview'] = ['text'=>$last['text'], 'ts'=>(int)$last['ts'],
                                 'from_id'=>(int)$last['sender_id'],
                                 'from_name'=>$s['display_name'] ?? '', 'from_short'=>$s['short_id'] ?? null,
                                 'self'=>(int)$last['sender_id'] === $participantId];
            } else {
                $r['preview'] = null;
            }
        }
        return $rows;
    }

    public function markRead(int $recipientId, array $messageIds): void
    {
        $now = time();
        foreach ($messageIds as $mid) {
            $this->run('UPDATE deliveries SET read_ts=:n
                        WHERE message_id=:m AND recipient_id=:r AND read_ts IS NULL',
                       [':n'=>$now, ':m'=>(int)$mid, ':r'=>$recipientId]);
        }
    }

    /** Delivery/read receipts for this sender's recent messages. Deliberately
     *  covers the last N sent messages (not only id > $sinceId): a delivery or
     *  read that lands AFTER the message-id watermark — e.g. the recipient comes
     *  online later — must still update the sender's acknowledgement. $sinceId is
     *  accepted for call-site compatibility but no longer bounds the result. */
    public function receiptsForSender(int $senderId, int $sinceId): array
    {
        $rows = $this->all(
            'SELECT d.message_id, COUNT(*) AS total,
                    SUM(CASE WHEN d.delivered_ts IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN d.read_ts IS NOT NULL THEN 1 ELSE 0 END) AS read,
                    MAX(p.last_seen) AS recipient_last_seen
             FROM deliveries d
             LEFT JOIN participants p ON p.id = d.recipient_id
             WHERE d.message_id IN (SELECT id FROM messages WHERE sender_id=:s ORDER BY id DESC LIMIT 50)
             GROUP BY d.message_id', [':s'=>$senderId]);
        // Flag a 1:1 message that is still queued because its sole recipient is
        // offline (last_seen older than the 90s online window). The web UI shows
        // "Pending" instead of "Sent" for these; groups keep the "N of M" wording.
        $now = time();
        foreach ($rows as &$r) {
            $ls = $r['recipient_last_seen'];
            $r['pending'] = ((int)$r['total'] === 1 && (int)$r['delivered'] === 0
                             && ($ls === null || ($now - (int)$ls) >= 90)) ? 1 : 0;
            unset($r['recipient_last_seen']);
        }
        unset($r);
        return $rows;
    }

    // ── legacy mobile-app compat (old ?mobile=… protocol over the new core) ─────
    /** Un-acked messages delivered to $recipientId (read_ts IS NULL); marks delivered. */
    public function pendingFor(int $recipientId): array
    {
        $rows = $this->all(
            'SELECT m.* FROM messages m
               JOIN deliveries d ON d.message_id = m.id
             WHERE d.recipient_id = :r AND d.read_ts IS NULL
             ORDER BY m.id', [':r'=>$recipientId]);
        if ($rows) {
            $now = time();
            foreach ($rows as $m) {
                $this->run('UPDATE deliveries SET delivered_ts=:n
                            WHERE message_id=:m AND recipient_id=:r AND delivered_ts IS NULL',
                           [':n'=>$now, ':m'=>$m['id'], ':r'=>$recipientId]);
            }
        }
        return $this->hydrate($rows);
    }

    /** Messages this participant sent or received, oldest first, last $limit. */
    public function messagesForParticipant(int $participantId, int $limit = 20): array
    {
        $rows = $this->all(
            'SELECT * FROM messages
             WHERE sender_id = :p
                OR id IN (SELECT message_id FROM deliveries WHERE recipient_id = :p)
             ORDER BY id DESC LIMIT :lim',
            [':p'=>$participantId, ':lim'=>$limit]);
        return $this->hydrate(array_reverse($rows));
    }

    /** Conversation of the most recent message DELIVERED to $participantId — what a
     *  legacy mobile reply should go back to (so a group reply reaches the group).
     *  Returns ['id'=>int,'kind'=>str] or null. */
    public function recentInboundConversation(int $participantId): ?array
    {
        return $this->one(
            'SELECT c.id, c.kind FROM deliveries d
               JOIN messages m ON m.id = d.message_id
               JOIN conversations c ON c.id = m.conversation_id
             WHERE d.recipient_id = :p
             ORDER BY m.id DESC LIMIT 1', [':p'=>$participantId]);
    }

    /** True if $participantId is a member of the conversation. */
    public function isConversationMember(int $conversationId, int $participantId): bool
    {
        return (bool)$this->one('SELECT 1 FROM conversation_members WHERE conversation_id=:c AND participant_id=:p',
                                [':c'=>$conversationId, ':p'=>$participantId]);
    }

    /** Operator participants active within $secs (the "who is monitoring" set). */
    public function onlineOperators(string $event, int $secs): array
    {
        return $this->all(
            "SELECT id, key, display_name, last_seen FROM participants
             WHERE event=:e AND kind='operator' AND last_seen >= :cut
             ORDER BY display_name", [':e'=>$event, ':cut'=>time() - $secs]);
    }

    /** Rename an operator (updates both key and display name). */
    public function renameParticipant(int $id, string $name): void
    {
        $this->run('UPDATE participants SET key=:k, display_name=:k WHERE id=:i', [':k'=>$name, ':i'=>$id]);
    }

    /** Wipe an event's messaging (used by the admin flush). */
    public function flushEvent(string $event): int
    {
        $ids = array_map(fn($r) => (int)$r['id'],
                         $this->all('SELECT id FROM messages WHERE event=:e', [':e'=>$event]));
        $n = count($ids);
        $this->db->exec('BEGIN IMMEDIATE');
        $this->run('DELETE FROM deliveries WHERE message_id IN (SELECT id FROM messages WHERE event=:e)', [':e'=>$event]);
        $this->run('DELETE FROM conversation_members WHERE conversation_id IN
                    (SELECT id FROM conversations WHERE event=:e)', [':e'=>$event]);
        $this->run('DELETE FROM messages WHERE event=:e', [':e'=>$event]);
        $this->run('DELETE FROM conversations WHERE event=:e', [':e'=>$event]);
        $this->db->exec('COMMIT');
        // Delete the event's stored photos too — they live outside the DB.
        $dir = self::photoDir($event);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
            @rmdir($dir);
        }
        return $n;
    }
}
