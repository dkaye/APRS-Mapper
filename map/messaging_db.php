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

// The tracker-ID name list. Expansion happens here, once, rather than in each
// client: five places build a sender label from from_short + from_name — the
// Flutter client, the mobile-session path, the web panel's text and HTML
// variants, and the watch announcers — and a lookup added to four of them is a
// lookup that will disagree with the fifth within a season.
require_once __DIR__ . '/spoken_ids.php';

if (!defined('MARSAPRS_MESSAGES_DB')) {
    define('MARSAPRS_MESSAGES_DB', getenv('MARSAPRS_MESSAGES_DB') ?: '/var/lib/marsaprs/messages.db');
}
// Radio audio, unlike every other attachment, lives INSIDE the web root so Apache
// can serve it without PHP in the path. See the audioDir() comment for why.
if (!defined('MARSAPRS_AUDIO_ROOT')) {
    define('MARSAPRS_AUDIO_ROOT', getenv('MARSAPRS_AUDIO_ROOT') ?: '/var/www/html/radio');
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
            kind         TEXT NOT NULL,            -- 'mobile' | 'operator' | 'transcriber'
            key          TEXT NOT NULL,            -- mobile callsign, operator display name,
                                                   -- or transcriber channel id ('rx1-146520')
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
        // Migration: merge log. When devices are grouped into one entity (see
        // entityHash), their prior 1:1 threads are folded into the entity thread.
        // These two columns record where each moved message came from and when, so
        // a mis-typed display_id can be reversed exactly. Without them the boundary
        // between pre-merge and post-merge messages is not reliably recoverable:
        // the obvious signal (one delivery row vs several) collapses whenever a
        // device was offline at send time, which is the normal case for the
        // multi-device people this feature exists to serve.
        if (!isset($cols['prev_conversation_id']))
            $this->db->exec('ALTER TABLE messages ADD COLUMN prev_conversation_id INTEGER');
        if (!isset($cols['merged_ts']))
            $this->db->exec('ALTER TABLE messages ADD COLUMN merged_ts INTEGER');
        // Migration: recorded radio audio for a Transcriber log entry. Deliberately
        // NOT the `attachment` column. Overloading it and telling the two apart by
        // file extension would mean `photo => !empty($m['attachment'])` reports true
        // for an audio clip, and attachmentsForEvent() -- which flushEvent() uses to
        // delete photos out of photoDir() -- would hand it filenames that live in the
        // web root instead. Two kinds of file in two places want two columns.
        if (!isset($cols['audio']))
            $this->db->exec('ALTER TABLE messages ADD COLUMN audio TEXT');
        if (!isset($cols['audio_secs']))
            $this->db->exec('ALTER TABLE messages ADD COLUMN audio_secs REAL');
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
    /** Remove a message and any delivery rows for it.
     *
     *  Used for one case only: an audio-first log entry whose clip turned out not to be
     *  storable, where the row was created a moment earlier and has never been seen by
     *  anybody. It is not an undo for ordinary traffic — the log is a record, and a
     *  message that was delivered has been read. */
    public function deleteMessage(int $mid): void
    {
        $this->run('DELETE FROM deliveries WHERE message_id=:id', [':id'=>$mid]);
        $this->run('DELETE FROM messages WHERE id=:id', [':id'=>$mid]);
    }

    /** Fill in the text of an entry posted earlier without any.
     *
     *  Only ever used to complete an audio-first log entry: the Transcriber posts the
     *  recording the moment the over ends, so it can be heard without waiting for
     *  whisper, and comes back with the words when it has them. Guarded on the text
     *  still being empty, so this can never rewrite an entry that already said
     *  something — a retry after a timeout must not be able to overwrite the log. */
    public function setMessageText(int $mid, string $text): bool
    {
        $this->run("UPDATE messages SET text=:t WHERE id=:id AND (text IS NULL OR text='')",
                   [':t'=>$text, ':id'=>$mid]);
        return $this->db->changes() > 0;
    }
    // ── Radio audio ────────────────────────────────────────────────────────────
    // Recorded radio audio is the one attachment served straight off disk by Apache,
    // with no PHP and no auth check, and that is a deliberate departure from photos.
    //
    // The reason is fan-out. Fifty hands-free phones each fetching every clip of a
    // busy net is on the order of ten thousand PHP invocations an hour, arriving in
    // bursts because every client polls on a similar cadence — the bytes are nothing,
    // but that request rate is not what you want in front of mod_php on an SD card.
    // There is no multicast over HTTP; caching at the edge is the substitute, and an
    // immutable public URL lets Cloudflare serve each clip while the origin serves it
    // roughly once.
    //
    // That is available here only because amateur radio transmissions are public by
    // law. The clip is not somebody's private attachment, which is exactly what a
    // photo is — so PHOTOS DO NOT MOVE. They stay outside the web root, PHP-gated and
    // Cache-Control: private. The filename still carries 6 random bytes, so the URL is
    // a capability obtainable only from the authenticated feed rather than an index.
    public static function audioDir(string $event): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $event);
        if ($safe === '' || $safe === null) $safe = 'default';
        return MARSAPRS_AUDIO_ROOT . '/' . $safe;
    }
    /** Public URL path for a stored clip — what the client actually fetches. */
    public static function audioUrl(string $event, string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $event);
        if ($safe === '' || $safe === null) $safe = 'default';
        return '/radio/' . $safe . '/' . basename($filename);
    }
    public function setAudio(int $mid, string $filename, ?float $secs): void
    {
        $this->run('UPDATE messages SET audio=:a, audio_secs=:s WHERE id=:id',
                   [':a'=>$filename, ':s'=>$secs, ':id'=>$mid]);
    }
    // How long a clip is kept. Audio exists to answer "what did they actually say" on
    // a line that came out garbled, and nobody asks that about something they heard six
    // hours ago — the transcription is the record, and it is not deleted. A busy net at
    // ~40% duty is roughly 4 MB/hour, so this bounds an event at about 25 MB whether it
    // runs for a day or a week.
    public const AUDIO_MAX_AGE = 6 * 3600;

    /**
     * Delete clips older than AUDIO_MAX_AGE and forget them. Returns the number removed.
     *
     * The row survives, with `audio` cleared: the transcription is the log entry and
     * outlives its recording. A client holding a stale URL gets a 404 rather than a
     * file that silently reappeared as something else.
     */
    public function pruneAudio(string $event, ?int $maxAge = null): int
    {
        $cut  = time() - ($maxAge ?? self::AUDIO_MAX_AGE);
        $rows = $this->all(
            "SELECT id, audio FROM messages
              WHERE event=:e AND audio IS NOT NULL AND audio<>'' AND ts < :cut",
            [':e'=>$event, ':cut'=>$cut]);
        if (!$rows) return 0;
        $dir = self::audioDir($event);
        foreach ($rows as $r) {
            $path = $dir . '/' . basename((string)$r['audio']);
            if (is_file($path)) @unlink($path);
            $this->run('UPDATE messages SET audio=NULL, audio_secs=NULL WHERE id=:i', [':i'=>(int)$r['id']]);
        }
        return count($rows);
    }

    /** [id => audio filename] for every message in the event that has a clip. */
    public function audioForEvent(string $event): array
    {
        $out = [];
        foreach ($this->all("SELECT id, audio FROM messages WHERE event=:e AND audio IS NOT NULL AND audio<>''", [':e'=>$event]) as $r) {
            $out[(int)$r['id']] = $r['audio'];
        }
        return $out;
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
    /**
     * Every write in this class goes through here, and every one of them was allowed to
     * fail in silence: SQLite3Stmt::execute() returns false on error and nothing looked
     * at it.
     *
     * What that cost, once: a rename that hit UNIQUE(event,kind,key) warned into the
     * Apache log and changed nothing, the endpoint returned {ok:true}, and the web client
     * — which correctly waits for the server before relabelling itself — believed it. One
     * operator was "Net Control" on their own screen and "test9" to every phone in the
     * event, with no error anywhere either of them could see.
     *
     * Nothing here relies on a failed write being ignored: the only INSERT that can
     * conflict carries ON CONFLICT DO UPDATE, and the rest are guarded by a lookup first.
     * So a write that does not happen is a bug every time, and now says so.
     */
    private function run(string $sql, array $params = []): void
    {
        $st = $this->db->prepare($sql);
        if ($st === false) {
            throw new RuntimeException('prepare failed: ' . $this->db->lastErrorMsg());
        }
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        // Suppressed because it is not lost: SQLite3 both warns AND returns false, and
        // lastErrorMsg() puts the same text in the exception below. Left unsuppressed,
        // every failed write is reported twice — once as a warning nobody is looking at
        // and once as the exception that actually stops something.
        if (@$st->execute() === false) {
            throw new RuntimeException('write failed: ' . $this->db->lastErrorMsg());
        }
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
    /** Carry a live session into the current event.
     *
     *  Participants are per-event, but a long-lived token is not. An operator's lives in
     *  the browser until they sign out; a transcriber channel's lives in its config file
     *  indefinitely. Creating a new event therefore left the session pointing at the old
     *  event's row, where it kept being touched and kept working, while the new event had
     *  no operator for anyone to address and no channel to log into it.
     *
     *  The token moves rather than being copied: two rows answering to one token would
     *  make participantByToken's answer depend on row order. The old row keeps its name
     *  and its history, it simply stops being reachable by that token — which is what
     *  "that session is in this event now" means.
     *
     *  History does not follow. Conversations are per-event by design, so the session
     *  arrives in the new event with a clean list, exactly as a fresh subscribe would
     *  have given it. */
    public function rehomeSession(string $event, array $old, string $token): int
    {
        $id = $this->upsertParticipant($event, (string)$old['kind'], (string)$old['key'],
                                       (string)($old['display_name'] ?? $old['key']),
                                       $old['short_id'] ?? null, $token);
        if ((int)$old['id'] !== $id) {
            $this->run('UPDATE participants SET token=NULL WHERE id=:i', [':i'=>(int)$old['id']]);
        }
        return $id;
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
            // No members are recorded: the broadcast conversation is shared by the whole
            // event, so access is decided by kind (see canAccessConversation), not by
            // membership. Recording the creator here would make the thread render as a
            // direct chat with whoever happened to send the event's first broadcast.
            return [$this->findOrCreateConversation($event, 'broadcast', '*', [], $title), 'broadcast'];
        }
        $members = array_values(array_unique(array_merge([$senderId], array_map('intval', $recipientIds))));
        $kind    = count($members) > 2 ? 'group' : 'direct';
        $hash    = self::memberHash($members);
        return [$this->findOrCreateConversation($event, $kind, $hash, $members, $title), $kind];
    }

    /** The event's running log: one thread per event holding entries that were written
     *  rather than sent. Memberless for the same reason broadcast is -- it belongs to
     *  the event, not to whoever happened to make the first entry -- and access is
     *  decided by kind. Only operators ever see it: the thread handler already lets an
     *  operator read any thread and refuses a non-member anything else, so a memberless
     *  log is operator-only without a rule of its own. */
    public function resolveLogConversation(string $event): int
    {
        return $this->findOrCreateConversation($event, 'log', 'log:*', [], 'Event Log');
    }

    /** This operator's entity thread for one device, if there is one.
     *
     *  Addressing a device by callsign — what right-clicking a tracker does — otherwise
     *  opens a direct thread beside that person's entity thread. Both render from the
     *  same display_id and name, so the operator sees the same label twice and traffic
     *  splits between them, which is what migrateThreadsIntoEntity exists to clean up
     *  after the fact. Finding the entity thread first avoids making the mess.
     *
     *  kind = 'entity' only, never 'entity_multi': a "(multiple)" thread is a station's
     *  whole group, and routing one person's message into it would put a private reply
     *  in front of everyone at that station. */
    public function entityConversationForDevice(string $event, int $operatorId, int $deviceId): ?int
    {
        $r = $this->one(
            "SELECT c.id FROM conversations c
               JOIN conversation_members cd ON cd.conversation_id = c.id AND cd.participant_id = :dev
               JOIN conversation_members co ON co.conversation_id = c.id AND co.participant_id = :op
               JOIN participants p ON p.id = cd.participant_id AND p.kind = 'mobile'
              WHERE c.event = :e AND c.kind = 'entity'
              ORDER BY c.id DESC LIMIT 1",
            [':e'=>$event, ':dev'=>$deviceId, ':op'=>$operatorId]);
        return $r ? (int)$r['id'] : null;
    }

    /** Canonical key for a multi-device entity: everyone sharing BOTH display_id and
     *  name is one person. display_id is operator-editable in the Admin UI and is
     *  deliberately used to merge devices, so it is the grouping key by design; the
     *  underlying callsign never changes and stays the APRS identity. */
    public static function entityHash(string $displayId, string $name): string
    {
        return 'ent:' . trim($displayId) . "\x1f" . trim($name);
    }

    /** Find-or-create the stable thread between one operator and one entity.
     *
     *  Keyed by the entity, NOT by the set of device participants, so the thread
     *  survives phones going offline, coming back, or a third being added — the
     *  member set would otherwise change the hash and split the conversation.
     *  $deviceIds are the entity's devices live right now; they are added as members
     *  (never removed) so access and listing work, while delivery is resolved fresh
     *  on every send.
     */
    public function resolveEntityConversation(string $event, int $operatorId, string $displayId,
                                              string $name, array $deviceIds): int
    {
        $hash = $operatorId . '|' . self::entityHash($displayId, $name);
        $ex   = $this->one('SELECT id FROM conversations WHERE event=:e AND member_hash=:h',
                           [':e'=>$event, ':h'=>$hash]);
        if ($ex) {
            $cid = (int)$ex['id'];
        } else {
            // Title is what the conversation list shows (_convLabel falls back to it),
            // so it must read the way the picker row did. The kind distinguishes ONE
            // person on several phones ('entity') from several people sharing a
            // display_id ('entity_multi') — receipts collapse for the former (any
            // phone counts) but stay "N of M" for the latter, which really is a group.
            $isMulti = ($name === '*');
            $title   = $isMulti ? trim($displayId) . ' (multiple)' : trim($displayId . ' ' . $name);
            $this->run('INSERT INTO conversations (event,kind,title,member_hash,created_ts)
                        VALUES (:e,:k,:t,:h,:n)',
                       [':e'=>$event, ':k'=>($isMulti ? 'entity_multi' : 'entity'),
                        ':t'=>$title, ':h'=>$hash, ':n'=>time()]);
            $cid = (int)$this->db->lastInsertRowID();
        }
        foreach (array_merge([$operatorId], $deviceIds) as $pid) {
            $this->run('INSERT OR IGNORE INTO conversation_members (conversation_id,participant_id)
                        VALUES (:c,:p)', [':c'=>$cid, ':p'=>(int)$pid]);
        }
        return $cid;
    }

    /** [display_id, name] for an entity conversation, or null if it isn't one.
     *  name is '*' for a whole-display_id "(multiple)" thread. Lets the send path
     *  re-resolve an entity's live devices when replying into an existing thread,
     *  rather than trusting conversation_members — a device whose display_id was
     *  edited away is no longer part of the entity and must stop receiving. */
    public function entityOfConversation(int $conversationId): ?array
    {
        $c = $this->one("SELECT member_hash FROM conversations
                          WHERE id=:i AND kind IN ('entity','entity_multi')",
                        [':i'=>$conversationId]);
        if (!$c) return null;
        $bar = strpos($c['member_hash'], '|ent:');
        if ($bar === false) return null;
        $rest = substr($c['member_hash'], $bar + 5);
        $sep  = strpos($rest, "\x1f");
        if ($sep === false) return null;
        return [substr($rest, 0, $sep), substr($rest, $sep + 1)];
    }

    /** Fold prior one-to-one threads for this entity's devices into its entity thread.
     *
     *  Called when an entity thread is resolved, so that merging two devices under one
     *  display_id also merges the history the operator already had with each of them —
     *  otherwise the conversation list shows a second, stale "CRD Stanton".
     *
     *  Guarded to DIRECT threads whose only non-operator member is one of this entity's
     *  devices. Group and broadcast threads are never touched, so an unrelated
     *  participant's messages can never be pulled in by a mistyped display_id.
     *
     *  Every moved row records prev_conversation_id + merged_ts, making the migration
     *  exactly reversible (see undoMerge). Returns the number of messages moved.
     */
    public function migrateThreadsIntoEntity(string $event, int $entityConvId, int $operatorId,
                                             array $deviceIds): int
    {
        if (!$deviceIds) return 0;
        $now = time();
        $moved = 0;
        foreach ($deviceIds as $pid) {
            $pid  = (int)$pid;
            $rows = $this->all(
                "SELECT c.id FROM conversations c
                   JOIN conversation_members cm ON cm.conversation_id = c.id
                  WHERE c.event = :e AND c.kind = 'direct' AND c.id <> :self
                    AND cm.participant_id = :p
                    AND (SELECT COUNT(*) FROM conversation_members x
                          WHERE x.conversation_id = c.id) = 2
                    AND EXISTS (SELECT 1 FROM conversation_members o
                                 WHERE o.conversation_id = c.id AND o.participant_id = :op)",
                [':e'=>$event, ':self'=>$entityConvId, ':p'=>$pid, ':op'=>$operatorId]);
            foreach ($rows as $r) {
                $old = (int)$r['id'];
                $this->run('UPDATE messages
                               SET prev_conversation_id = conversation_id,
                                   merged_ts            = :n,
                                   conversation_id      = :new
                             WHERE conversation_id = :old',
                           [':n'=>$now, ':new'=>$entityConvId, ':old'=>$old]);
                $moved += $this->db->changes();
                // The emptied thread is deliberately NOT deleted. It disappears from
                // the conversation list on its own because conversationsFor() hides
                // threads with no messages, and keeping the row (and its members)
                // means undoMerge only has to move the messages back — the thread it
                // restores them to still exists. Deleting here orphaned the history.
            }
        }
        return $moved;
    }

    /** Kind of a conversation, or null if there is no such row. */
    public function conversationKind(int $conversationId): ?string
    {
        $c = $this->one('SELECT kind FROM conversations WHERE id=:i', [':i'=>$conversationId]);
        return $c ? (string)$c['kind'] : null;
    }

    /** Title of a conversation, or null. */
    public function conversationTitle(int $conversationId): ?string
    {
        $c = $this->one('SELECT title FROM conversations WHERE id=:i', [':i'=>$conversationId]);
        return $c ? ($c['title'] !== null ? (string)$c['title'] : null) : null;
    }

    /** Timestamp of the most recent merge in this event — the handle undoMerge needs. */
    public function lastMergeTs(string $event): ?int
    {
        $r = $this->one('SELECT MAX(merged_ts) AS t FROM messages
                          WHERE event=:e AND merged_ts IS NOT NULL', [':e'=>$event]);
        return ($r && $r['t'] !== null) ? (int)$r['t'] : null;
    }

    /** Reverse one merge: put every message moved at $mergedTs back where it came from.
     *  Recreating the old conversation row is not needed — the id is preserved in
     *  prev_conversation_id — but the row is gone, so callers should re-resolve it. */
    public function undoMerge(string $event, int $mergedTs): int
    {
        $this->run('UPDATE messages
                       SET conversation_id      = prev_conversation_id,
                           prev_conversation_id = NULL,
                           merged_ts            = NULL
                     WHERE event = :e AND merged_ts = :t AND prev_conversation_id IS NOT NULL',
                   [':e'=>$event, ':t'=>$mergedTs]);
        return $this->db->changes();
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
        // Senders are fetched in one statement rather than one per message. This was a
        // query per row, which nobody noticed while history() was an operator's
        // occasional click; the monitor feed is polled by every subscribed phone, and a
        // busy net makes that hundreds of statements a second against an SD card.
        $senders = [];
        $ids = array_values(array_unique(array_map(fn($m) => (int)$m['sender_id'], $rows)));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT * FROM participants WHERE id IN ($ph)");
            foreach ($ids as $i => $id) $st->bindValue($i + 1, $id, SQLITE3_INTEGER);
            $r = $st->execute();
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) $senders[(int)$row['id']] = $row;
        }
        $out = [];
        foreach ($rows as $m) {
            $s = $senders[(int)$m['sender_id']] ?? null;
            $out[] = [
                'id'              => (int)$m['id'],
                'conversation_id' => (int)$m['conversation_id'],
                'ts'              => (int)$m['ts'],
                // Radio traffic gets ids expanded inside the line as well as in the
                // label: a log entry reading "Hiker One to net control" is the point of
                // the list. Applied on the way out, not on the way in, so the stored
                // transcript stays exactly what was heard and editing the list fixes
                // yesterday's entries too. Never applied to text a person typed.
                'text'            => (($s['kind'] ?? '') === 'transcriber')
                                     ? spoken_ids_expand_text((string)$m['text'])
                                     : $m['text'],
                'broadcast'       => (int)$m['broadcast'] === 1,
                'from_id'         => (int)$m['sender_id'],
                'from_kind'       => $s['kind'] ?? null,
                'from_key'        => $s['key'] ?? null,           // callsign / operator name
                'from_short'      => $s['short_id'] ?? null,      // M0xx
                // The written-out name for that id when one is set, else null.
                // Null and not the id itself, so a client can tell "expand this"
                // from "there is nothing to expand" without a second lookup.
                'from_spoken'     => spoken_id_for($s['short_id'] ?? null),
                'from_name'       => $s['display_name'] ?? ($m['from_key'] ?? ''),
                'lat'             => isset($m['lat']) ? (float)$m['lat'] : null,
                'lon'             => isset($m['lon']) ? (float)$m['lon'] : null,
                'pos_ts'          => isset($m['pos_ts']) ? (int)$m['pos_ts'] : null,
                'photo'           => !empty($m['attachment']),
                'photo_w'         => isset($m['attach_w']) ? (int)$m['attach_w'] : null,
                'photo_h'         => isset($m['attach_h']) ? (int)$m['attach_h'] : null,
                // A flag and a URL, never the bytes. A phone that has not opted into
                // audio simply never issues the fetch, and so spends nothing on it —
                // which is the whole reason this is an attachment and not a stream.
                'has_audio'       => !empty($m['audio']),
                'audio_url'       => !empty($m['audio'])
                                        ? self::audioUrl((string)$m['event'], (string)$m['audio']) : null,
                'audio_secs'      => isset($m['audio_secs']) ? (float)$m['audio_secs'] : null,
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
        $msgs = $this->hydrate($this->all(
            'SELECT * FROM messages WHERE conversation_id=:c AND id > :s ORDER BY id',
            [':c'=>$conversationId, ':s'=>$sinceId]));
        if (!$msgs) return $msgs;
        // Tag each message with who it went TO, the same way history() does, so a
        // reader can tell a note addressed to them alone from one that also went to
        // a whole station or every tracker. All messages here share one conversation,
        // so its kind and members are fetched once.
        $conv = $this->one('SELECT kind,title FROM conversations WHERE id=:i', [':i'=>$conversationId]);
        $kind = $conv['kind'] ?? '';
        $mem  = $this->all(
            'SELECT p.id,p.kind,p.key,p.short_id,p.display_name
               FROM conversation_members cm JOIN participants p ON p.id=cm.participant_id
              WHERE cm.conversation_id=:c', [':c'=>$conversationId]);
        $label = fn($p) => $p['kind'] === 'mobile'
            ? spoken_label($p['short_id'] ?? '', (string)$p['display_name'])
            : $p['display_name'];
        foreach ($msgs as &$m) {
            if ($kind === 'broadcast' || !empty($m['broadcast'])) { $m['to_label'] = 'All Trackers'; continue; }
            // The log is where it went, and it is the only honest answer: the thread
            // has no members, so deriving a recipient the usual way yields nothing.
            if ($kind === 'log') { $m['to_label'] = 'Log'; continue; }
            if ($kind === 'entity' || $kind === 'entity_multi') {
                $m['to_label'] = (string)($conv['title'] ?? '');
                continue;
            }
            $others = array_filter($mem, fn($p) => (int)$p['id'] !== (int)$m['from_id']);
            $m['to_label'] = implode(', ', array_map($label, $others));
        }
        unset($m);
        return $msgs;
    }

    /** Tag each hydrated message with who it went TO, derived from its conversation.
     *
     *  Shared by history() and monitor(): both need the same answer over an event's
     *  worth of messages, and both would otherwise pay a query per row for it. Two
     *  statements cover the whole event regardless of how many messages there are. */
    private function tagRecipients(string $event, array $msgs): array
    {
        if (!$msgs) return $msgs;
        $kind = []; $title = []; $members = [];
        foreach ($this->all('SELECT id,kind,title FROM conversations WHERE event=:e', [':e'=>$event]) as $c) {
            $kind[(int)$c['id']]  = $c['kind'];
            $title[(int)$c['id']] = $c['title'];
        }
        foreach ($this->all(
            'SELECT cm.conversation_id AS cid, p.id, p.kind, p.key, p.short_id, p.display_name
               FROM conversation_members cm JOIN participants p ON p.id=cm.participant_id
              WHERE cm.conversation_id IN (SELECT id FROM conversations WHERE event=:e)', [':e'=>$event]) as $m) {
            $members[(int)$m['cid']][] = $m;
        }
        $label = fn($p) => $p['kind'] === 'mobile'
            ? spoken_label($p['short_id'] ?? '', (string)$p['display_name'])
            : $p['display_name'];
        foreach ($msgs as &$msg) {
            $cid = $msg['conversation_id'];
            $k   = $kind[$cid] ?? '';
            if ($k === 'broadcast' || $msg['broadcast']) { $msg['to_label'] = 'All Trackers'; continue; }
            // The log is where it went, and it is the only honest answer: the thread
            // has no members, so deriving a recipient the usual way yields nothing.
            if ($k === 'log') { $msg['to_label'] = 'Log'; continue; }
            // Entity threads name the person, not the devices. thread() has always done
            // this; history() did not, so the all-messages view labelled a multi-device
            // recipient with a list of their phones.
            if ($k === 'entity' || $k === 'entity_multi') {
                $msg['to_label'] = (string)($title[$cid] ?? '');
                continue;
            }
            $others = array_filter($members[$cid] ?? [], fn($p) => (int)$p['id'] !== $msg['from_id']);
            $msg['to_label'] = implode(', ', array_map($label, $others));
        }
        unset($msg);
        return $msgs;
    }

    /** Every message in the event (all-messages / admin view), each tagged with a
     *  recipient (`to_label`) derived from its conversation. */
    public function history(string $event): array
    {
        // Textless entries are skipped. They are audio-first log rows whose recording
        // arrived before the transcription — and some never get one, because the
        // transcription was discarded as a hallucination or a courtesy beep. This is
        // the WRITTEN log, so a row with nothing written in it has nothing to show; it
        // reappears here the moment its text lands. The monitor feed does not filter
        // them, because that is where the audio is reached from.
        return $this->tagRecipients($event, $this->hydrate($this->all(
            "SELECT * FROM messages WHERE event=:e AND text IS NOT NULL AND text<>'' ORDER BY id",
            [':e'=>$event])));
    }

    // How much history a reconnecting monitor is given. A device that has been off the
    // network for an hour of a busy net must not come back to a thousand messages it
    // will read aloud one after another, so the firehose is bounded — but the bound is
    // reported rather than applied silently (see monitor()).
    public const MONITOR_MAX_AGE     = 300;   // seconds
    public const MONITOR_MAX_RESULTS = 50;

    /**
     * Read-only feed of an event's traffic for a subscribed monitor. Returns
     * ['messages'=>[], 'skipped'=>int, 'last_id'=>int].
     *
     * THIS MUST NEVER WRITE A `deliveries` ROW. It is tempting to implement monitoring
     * by giving the monitoring device delivery rows and reusing pollFor(), and that
     * would corrupt every sender's receipts event-wide: receiptsForSender() counts all
     * delivery rows for a message, so a 1:1 would start reporting "Delivered to 1 of 2"
     * and its `pending` state would never clear. conversationsFor()'s unread subquery
     * and recentInboundConversation() would likewise start pointing the monitor at
     * strangers' threads. Reading is the entire contract.
     *
     * $includeMessages / $includeLog are the two subscriptions ("everything" and "radio
     * traffic") expressed as filters over one query; with both false there is nothing
     * to send and the caller gets an empty result without touching the database.
     *
     * $viewerId tags each message with `addressed`: whether this monitor also has a
     * delivery row for it, i.e. whether it was sent TO them.
     *
     * The tag exists because the phone announces monitored traffic and addressed traffic
     * by two separate paths, and this feed returns both — so a message addressed to the
     * monitor was announced twice, once by each. The obvious fix is to exclude those rows
     * here, and it is wrong: this feed is ALSO the event's traffic log, and dropping the
     * messages sent to you would leave holes in the one view whose whole purpose is
     * completeness. Tagging lets the client show everything and announce once.
     *
     * A tag rather than a client-side guess, because only this side knows. The client
     * can observe which messages reached it on an addressed path, but the two polls run
     * at different intervals, so a monitor batch can arrive first and it would announce
     * before the addressed path had claimed anything.
     */
    public function monitor(string $event, int $sinceId, bool $includeMessages, bool $includeLog,
                            int $viewerId = 0): array
    {
        $empty = ['messages'=>[], 'skipped'=>0, 'last_id'=>$sinceId];
        if (!$includeMessages && !$includeLog) return $empty;

        // Which conversations are the log. Usually one; not assumed to be.
        $logIds = [];
        foreach ($this->all("SELECT id FROM conversations WHERE event=:e AND kind='log'", [':e'=>$event]) as $c) {
            $logIds[] = (int)$c['id'];
        }
        $where = 'event = :e AND id > :since';
        if (!$includeMessages) {
            if (!$logIds) return $empty;                       // radio only, no log thread yet
            $where .= ' AND conversation_id IN (' . implode(',', $logIds) . ')';
        } elseif (!$includeLog && $logIds) {
            $where .= ' AND conversation_id NOT IN (' . implode(',', $logIds) . ')';
        }
        $params = [':e'=>$event, ':since'=>$sinceId];

        // Everything the cursor has not seen, before bounding. Both numbers come from
        // the same predicate so `skipped` cannot disagree with what was returned, and
        // last_id is the high-water mark of the WHOLE set — not of the rows sent — or a
        // client that was bounded would re-request the same skipped range forever.
        $agg     = $this->one("SELECT COUNT(*) AS n, MAX(id) AS hi FROM messages WHERE $where", $params);
        $total   = (int)($agg['n'] ?? 0);
        if ($total === 0) return $empty;
        $lastId  = (int)($agg['hi'] ?? $sinceId);

        // Newest first, then reversed: after a long outage the recent minutes are what
        // is worth hearing, not the start of a backlog the event has already moved past.
        $rows = $this->all(
            "SELECT * FROM messages WHERE $where AND ts >= :cutoff ORDER BY id DESC LIMIT :n",
            $params + [':cutoff'=>time() - self::MONITOR_MAX_AGE, ':n'=>self::MONITOR_MAX_RESULTS]);
        $rows = array_reverse($rows);

        return [
            'messages' => $this->tagAddressed(
                $this->tagRecipients($event, $this->hydrate($rows)), $viewerId),
            // Never silently truncated: the client says "42 messages skipped" so the
            // gap is visible rather than looking like nothing happened.
            'skipped'  => max(0, $total - count($rows)),
            'last_id'  => $lastId,
        ];
    }

    /**
     * Mark which of these messages were sent TO $viewerId, by their delivery rows.
     *
     * One statement for the batch, not one per row: this runs on every monitor poll from
     * every subscribed phone, and hydrate() above carries the scar from getting that
     * wrong. `idx_deliv_recip` covers (recipient_id, message_id), so it is an index scan.
     *
     * With no viewer the answer is false for everything, which is the safe direction: a
     * caller that cannot say who it is gets a feed it will announce, rather than one it
     * silently ignores.
     */
    private function tagAddressed(array $msgs, int $viewerId): array
    {
        $mine = [];
        if ($viewerId > 0 && $msgs) {
            $ids = array_map(fn($m) => (int)$m['id'], $msgs);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $st  = $this->db->prepare(
                "SELECT message_id FROM deliveries
                  WHERE recipient_id = ? AND message_id IN ($ph)");
            $st->bindValue(1, $viewerId, SQLITE3_INTEGER);
            foreach ($ids as $i => $id) $st->bindValue($i + 2, $id, SQLITE3_INTEGER);
            $r = $st->execute();
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) $mine[(int)$row['message_id']] = true;
        }
        foreach ($msgs as &$m) {
            $m['addressed'] = isset($mine[(int)$m['id']]);
        }
        unset($m);
        return $msgs;
    }

    /** Conversation list for a participant: last message + unread count + the other
     *  members (for labelling) per thread, plus a preview of the latest message.
     *
     *  $includeLog is the caller asserting this participant is an operator. The log
     *  thread has no members, so without it there is nothing to join against and a
     *  mobile would see the event's log listed in its conversation list. */
    public function conversationsFor(string $event, int $participantId, bool $includeLog = false): array
    {
        $rows = $this->all(
            'SELECT c.id, c.kind, c.title, c.member_hash,
                    (SELECT COUNT(*) FROM deliveries d JOIN messages mm ON mm.id=d.message_id
                       WHERE d.recipient_id=:p AND mm.conversation_id=c.id AND d.read_ts IS NULL) AS unread,
                    (SELECT MAX(id) FROM messages WHERE conversation_id=c.id) AS last_id
             FROM conversations c
             LEFT JOIN conversation_members cm
                    ON cm.conversation_id=c.id AND cm.participant_id=:p
             WHERE c.event=:e
               AND (cm.participant_id IS NOT NULL OR c.kind = \'broadcast\'
                    OR (c.kind = \'log\' AND :log = 1))
               -- Hide threads with no messages. Conversations are only created when
               -- something is sent, so an empty one means every message was migrated
               -- into an entity thread; showing it is the stale duplicate that
               -- merging exists to remove. Keeping the row (rather than deleting it)
               -- is what lets undoMerge put the history back somewhere real.
               AND EXISTS (SELECT 1 FROM messages mx WHERE mx.conversation_id = c.id)
             ORDER BY last_id DESC',
            [':e'=>$event, ':p'=>$participantId, ':log'=>$includeLog ? 1 : 0]);
        foreach ($rows as &$r) {
            $r['unread']  = (int)$r['unread'];
            $r['last_id'] = (int)($r['last_id'] ?? 0);
            // An entity thread's title names the person it was addressed TO -- "M040
            // Doug" -- which is what the sender needs to see and is that person's own
            // name when they look at the same thread. Both clients prefer title over
            // members, so the recipient's conversation list showed them themselves
            // instead of whoever had just called.
            //
            // The title is sender-relative but stored once on the conversation, so it
            // is suppressed for the other side and the label falls back to `members`,
            // which is already everyone-except-me. The addresser is the id prefixed to
            // the entity hash, which is what makes these threads per-sender.
            if (($r['kind'] === 'entity' || $r['kind'] === 'entity_multi')
                && ($cut = strpos((string)$r['member_hash'], '|')) !== false
                && (int)substr((string)$r['member_hash'], 0, $cut) !== $participantId) {
                $r['title'] = null;
            }
            unset($r['member_hash']);   // internal key, not part of the API
            // Other members (excludes the caller) for the thread label.
            $mem = $this->all(
                'SELECT p.id,p.kind,p.key,p.short_id,p.display_name,p.last_seen
                   FROM conversation_members cm JOIN participants p ON p.id=cm.participant_id
                  WHERE cm.conversation_id=:c AND p.id<>:me
                  ORDER BY p.kind, p.display_name', [':c'=>$r['id'], ':me'=>$participantId]);
            $r['members'] = $mem;
            // Whether anyone on the other end has been seen lately, on the same
            // 24-hour window the recipient picker uses to decide who is addressable
            // (_msg_addressable). Reported rather than acted on: a client showing an
            // inbox may reasonably keep history a client offering reply targets would
            // hide. Broadcast threads have no members and are never stale — "All
            // Trackers" outlives everyone in it.
            // The log outlives its members the same way "All Trackers" does -- it is
            // the event's own thread and nobody is at the other end of it.
            $cutoff = time() - 86400;
            $r['stale'] = $r['kind'] !== 'broadcast' && $r['kind'] !== 'log'
                && !array_filter($mem, fn($m) => (int)($m['last_seen'] ?? 0) > $cutoff);
            // Latest-message preview (sender + text).
            $last = $r['last_id'] ? $this->one(
                'SELECT sender_id, text, ts FROM messages WHERE id=:i', [':i'=>$r['last_id']]) : null;
            if ($last) {
                $s = $this->participantById((int)$last['sender_id']);
                $r['preview'] = ['text'=>$last['text'], 'ts'=>(int)$last['ts'],
                                 'from_id'=>(int)$last['sender_id'],
                                 'from_name'=>$s['display_name'] ?? '', 'from_short'=>$s['short_id'] ?? null,
                                 'from_spoken'=>spoken_id_for($s['short_id'] ?? null),
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
    /** Mark deliveries handed to a device. The legacy ack means "I have it, stop
     *  resending" — which is delivery, not reading. It used to call markRead, so a
     *  phone reported a message read the instant it arrived and the operator's panel
     *  showed "Read ✓✓" for something nobody had looked at. Read is now set only when
     *  a human actually opens the thread. */
    public function markDelivered(int $recipientId, array $messageIds): void
    {
        $now = time();
        foreach ($messageIds as $mid) {
            $this->run('UPDATE deliveries SET delivered_ts=:n
                        WHERE message_id=:m AND recipient_id=:r AND delivered_ts IS NULL',
                       [':n'=>$now, ':m'=>(int)$mid, ':r'=>$recipientId]);
        }
    }

    public function pendingFor(int $recipientId): array
    {
        // Keyed on delivered_ts, not read_ts: "still owed to this device" is a
        // delivery question. Keying it on read meant the only way to stop redelivery
        // was to claim the operator had read it.
        $rows = $this->all(
            'SELECT m.* FROM messages m
               JOIN deliveries d ON d.message_id = m.id
             WHERE d.recipient_id = :r AND d.delivered_ts IS NULL
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

    /** True if $participantId is a member of the conversation. Literal membership only —
     *  callers gating *reads* want canAccessConversation() instead. */
    public function isConversationMember(int $conversationId, int $participantId): bool
    {
        return (bool)$this->one('SELECT 1 FROM conversation_members WHERE conversation_id=:c AND participant_id=:p',
                                [':c'=>$conversationId, ':p'=>$participantId]);
    }

    /** True if $participantId may READ this conversation: either a member, or it is the
     *  event's broadcast conversation, which every participant receives and so must be
     *  able to open. Broadcast records no members (its hash is the constant '*'), so a
     *  membership test alone would 403 every recipient of an announcement. */
    public function canAccessConversation(string $event, int $conversationId, int $participantId): bool
    {
        if ($this->isConversationMember($conversationId, $participantId)) return true;
        return (bool)$this->one(
            "SELECT 1 FROM conversations WHERE id=:c AND event=:e AND kind='broadcast'",
            [':c'=>$conversationId, ':e'=>$event]);
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
    /**
     * Rename a participant, taking the name back from a retired session if one holds it.
     *
     * UNIQUE(event,kind,key) does not care that a name's holder left days ago; the
     * caller's clash test — not seen for 90 seconds, so treat it as gone — very much
     * does. The two disagreed, so the endpoint handed out a name the schema then
     * refused, and every operator name an event had ever used was permanently
     * unclaimable by anybody else. The comment beside that test says a departed
     * operator's name auto-frees. This is what makes that true.
     *
     * The retired row's KEY moves aside and its DISPLAY_NAME does not. Old messages are
     * attributed by display_name, so rewriting it would relabel traffic that identity
     * really did send. Clearing token and last_seen is what disconnectParticipant means
     * by signing a session out, and it keeps the retired row out of the addressable list
     * so the picker never offers the same name twice.
     *
     * The "#<id>" suffix cannot itself collide: ids are unique, so the row being moved is
     * the only one that could ever hold that key.
     */
    public function renameParticipant(int $id, string $name): void
    {
        $me = $this->participantById($id);
        if (!$me) return;

        $held = $this->one(
            'SELECT id, last_seen FROM participants
              WHERE event=:e AND kind=:k AND key=:key AND id<>:i',
            [':e'=>$me['event'], ':k'=>$me['kind'], ':key'=>$name, ':i'=>$id]);
        if ($held) {
            $this->run('UPDATE participants SET key=:k, token=NULL, last_seen=0 WHERE id=:i',
                       [':k'=>$name . ' #' . (int)$held['id'], ':i'=>(int)$held['id']]);
        }

        $this->run('UPDATE participants SET key=:k, display_name=:k WHERE id=:i',
                   [':k'=>$name, ':i'=>$id]);
    }

    /** Change only what a participant is called.
     *
     *  Not renameParticipant, which sets `key` to the new name as well. For an operator
     *  the key IS the name and that is right; for a transcriber the key is the channel
     *  id, and overwriting it would break the identity the upsert matches on — the next
     *  entry would arrive as a brand-new participant and the log would show the channel
     *  twice.
     *
     *  Retroactive, because the log joins participants for the name: past entries from
     *  this channel are relabelled too. That is the right answer for a receiver that was
     *  renamed — it is the same station and always was — and the alternative would leave
     *  a log showing two names for one radio with no way to tell they were the same.
     */
    public function setParticipantName(int $id, string $displayName): void
    {
        $this->run('UPDATE participants SET display_name=:dn WHERE id=:i',
                   [':dn'=>$displayName, ':i'=>$id]);
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
        // Delete the event's stored photos and radio clips too — both live outside the
        // DB, and in two different places, which is exactly why audio has its own
        // column rather than sharing `attachment`.
        foreach ([self::photoDir($event), self::audioDir($event)] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
            @rmdir($dir);
        }
        return $n;
    }
}
