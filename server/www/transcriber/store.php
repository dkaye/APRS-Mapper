<?php
/**
 * Transcriber registry — read/write for the channel manager and the device download.
 *
 * One file holds both halves of the fleet:
 *
 *   devices   [{host, token}]                 a Pi, and the token it fetches config with
 *   channels  [{id, device, label, frequency, serial, squelch, model, enabled, token}]
 *   settings  {sheet_url}                    fleet-wide, currently just the event's
 *                                            radio assignment sheet
 *
 * Two kinds of token, deliberately. A device token fetches that device's configuration
 * and reports on that device's own channels — its level and its heartbeat; a
 * channel token only writes log entries. Neither can do the other's job, so a
 * Transcriber left in a shed with a readable config file cannot be used to read the
 * net's traffic, and cannot speak for a receiver somewhere else.
 *
 * Stored beside messages.db, outside the web root. A registry of tokens under
 * /var/www/html is how mobile_trackers.json came to be downloadable by anyone.
 *
 * Three more files sit beside it, each holding something written at a moment the manager
 * did not choose, so that none of them moves the registry's fingerprint and makes an open
 * page refuse its own Save: transcriber-state.json (device check-ins),
 * transcriber-heartbeat.json (which channels are still running) and
 * transcriber-standing.json (the standing vocabulary). transcriber-vocabulary.json holds
 * the sheet's lists for the same reason. None of them holds a token.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

// Where the events live. The transcriber's per-event settings sit beside each event's
// own files, so this is the one place the manager reaches into the web root.
if (!defined('MARSAPRS_WEB_ROOT')) {
    define('MARSAPRS_WEB_ROOT', getenv('MARSAPRS_WEB_ROOT') ?: '/var/www/html');
}

if (!defined('MARSAPRS_CHANNELS')) {
    define('MARSAPRS_CHANNELS', getenv('MARSAPRS_CHANNELS') ?: '/var/lib/marsaprs/transcriber.json');
}

/** Every function takes an optional path so the registry can be pointed at a temp file
 *  in tests. The device-token check below is the one piece of this worth testing, and it
 *  could not be reached while it lived inside get.php, which reads a fixed path and
 *  exits. */
function transcriber_path(?string $path = null): string
{
    return $path ?: MARSAPRS_CHANNELS;
}

function transcriber_load(?string $path = null): array
{
    $file = transcriber_path($path);
    $raw = [];
    if (is_readable($file)) {
        $raw = json_decode((string)file_get_contents($file), true) ?: [];
    }
    return [
        'devices'  => array_values(array_filter($raw['devices']  ?? [], 'is_array')),
        'channels' => array_values(array_filter($raw['channels'] ?? [], 'is_array')),
        'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
    ];
}

function transcriber_save(array $data, ?string $path = null): void
{
    $file = transcriber_path($path);
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // Written then renamed: a device fetching mid-write would otherwise get a truncated
    // file, and auto-update.sh validates JSON precisely because that used to be possible.
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $file);
}

/** Whether this device may fetch its configuration.
 *
 *  Both arguments must be non-empty before anything is compared: an absent token must
 *  never match an absent stored one, which is the shape of bug that turns an
 *  unconfigured device into an authenticated one. */
function transcriber_device_ok(string $device, string $token, ?string $path = null): bool
{
    if ($device === '' || $token === '') return false;
    foreach (transcriber_load($path)['devices'] as $d) {
        if (($d['host'] ?? '') !== $device) continue;
        if (empty($d['token'])) continue;
        if (hash_equals((string)$d['token'], $token)) return true;
    }
    return false;
}

/** The channels one device should run, with the fields the worker needs. */
function transcriber_channels_for(string $device, ?string $path = null): array
{
    $out = [];
    foreach (transcriber_load($path)['channels'] as $c) {
        if (($c['device'] ?? '') !== $device) continue;
        $out[] = [
            'id'        => (string)($c['id'] ?? ''),
            'label'     => (string)($c['label'] ?? ''),
            'token'     => (string)($c['token'] ?? ''),
            'frequency' => (string)($c['frequency'] ?? ''),
            'serial'    => (string)($c['serial'] ?? ''),
            'squelch'   => (int)($c['squelch'] ?? 0),
            'model'     => (string)($c['model'] ?? 'ggml-tiny.en.bin'),
            'enabled'   => (bool)($c['enabled'] ?? true),
            // Passed through so it can be set here rather than on the device. A value
            // written straight into the Pi's channels.json is erased within the minute:
            // the config poll rebuilds that file from exactly this list, so a key missing
            // here is a key the device silently loses. Anything a channel should know has
            // to be named in this array — a setting that only works until the next poll
            // is worse than one that never worked, because it is believed.
            'record_until' => (string)($c['record_until'] ?? ''),
            // Send the recorded audio to the server with each log entry, so a phone can
            // hear what was actually said on a line that came out garbled. Named here,
            // unlike record_until when it was first added, so the manager's checkbox
            // actually reaches the device instead of surviving until the next poll.
            'send_audio'   => (bool)($c['send_audio'] ?? false),
            // What to do about courtesy tones and Morse identifiers: "observe" (the
            // default — measure, record the verdict, drop nothing), "drop", or "off".
            // Named here for the reason above: without it a channel could never be moved
            // off observe at all, because the poll would erase the value within a minute.
            // Empty string rather than a default, so the device's own default decides and
            // there is one place that says what it is.
            'tone_filter'  => (string)($c['tone_filter'] ?? ''),
        ];
    }
    return $out;
}

/** A frequency in Hz, from whatever a person typed.
 *
 *  "147.465" is what anyone actually writes, and the first version of this stripped
 *  every non-digit — turning it into 1474650, or 1.47 MHz. That is not an error anyone
 *  sees: it saves, it looks like a number, the receiver tunes to a band it cannot hear
 *  and the channel simply never logs anything. It happened on the first real edit.
 *
 *  So a value under 1000 is read as MHz, which is the only way a human writes a VHF or
 *  UHF frequency, and anything larger is already Hz. The result is echoed back into the
 *  field on reload, so the interpretation is visible rather than assumed. */
function transcriber_hz($raw): string
{
    $s = preg_replace('/[^0-9.]/', '', (string)$raw);
    if ($s === '' || !is_numeric($s)) return '';
    $n = (float)$s;
    if ($n <= 0) return '';
    if ($n < 1000) $n *= 1_000_000;          // MHz
    return (string)(int)round($n);
}

/** MHz, as a person reads it: 147465000 -> "147.465". */
function transcriber_mhz($hz): string
{
    $hz = (int)$hz;
    if ($hz <= 0) return '';
    return rtrim(rtrim(number_format($hz / 1_000_000, 4, '.', ''), '0'), '.');
}

/* transcriber_channel_id() was removed on 2026-08-29.
 *
 * It derived the id from device+frequency, which was right when several Pis each ran
 * several dongles: the id had to say which machine and which frequency. With one receiver
 * tuned by hand at the radio, the frequency is not the server's business, and baking a
 * location into an identifier that names the systemd unit and the author of every log
 * entry meant the identifier went stale the moment the receiver moved.
 *
 * The id is now stored, not derived. Nothing recomputes it, so nothing can invalidate it.
 */

function transcriber_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Per-device state the manager shows and the devices act on.
 *
 *  Kept beside the registry rather than inside it: it changes on every device poll, and
 *  rewriting the file that holds every token that often is a good way to eventually lose
 *  one to a truncated write. Nothing here is secret.
 */
/* ── per-event settings ────────────────────────────────────────────────────────
 *
 * What a receiver is called, how carefully it transcribes, and which vocabulary it uses
 * are facts about an EVENT, not about the hardware. "Tam West" at one event is "Radio" at
 * the next; the assignment sheet changes every time; the tracker ID names are the tactical
 * calls of whoever is out today. Holding one global copy meant the Dipsea vocabulary was
 * still loaded at Escape from Alcatraz.
 *
 * Stored in the event's own directory rather than in the registry, for two reasons: it
 * travels with the event, and `events/` is in the nightly backup where
 * /var/lib/marsaprs/transcriber.json is not.
 *
 * The RECEIVER stays global -- host, config token, log token. There is one of it and no
 * event has an opinion about which machine is listening.
 */

/** The active event's name, from the config.yaml symlink the whole server keys off. */
/**
 * The event-scoped settings a vocabulary reader needs, honouring the $path contract.
 *
 * Every function in this file takes an optional $path so tests can point at a temp
 * registry. That contract has to survive the move to per-event storage: an explicit path
 * means "this registry, not the live server", and a test has no config.yaml and no events
 * directory to resolve. So an explicit path reads the registry's own settings block; the
 * live server, which passes nothing, reads the active event.
 */
function transcriber_event_settings_for(?string $path): array
{
    if ($path !== null) {
        $set = transcriber_load($path)['settings'] ?? [];
        // is_string, not a cast. A registry hand-edited into nonsense -- an array where a
        // string belongs -- casts to the literal "Array", which then parses as a
        // vocabulary term. Anything that is not a string means nothing extra.
        return ['sheet_url'        => is_string($set['sheet_url'] ?? null) ? $set['sheet_url'] : '',
                'vocabulary_extra' => is_string($set['vocabulary_extra'] ?? null) ? $set['vocabulary_extra'] : ''];
    }
    $ev = transcriber_event_load(transcriber_event_name());
    return ['sheet_url' => $ev['sheet_url'], 'vocabulary_extra' => $ev['vocabulary_extra']];
}

function transcriber_event_name(): string
{
    $cfg = MARSAPRS_WEB_ROOT . '/config.yaml';
    if (!is_readable($cfg)) return '';
    foreach (file($cfg, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*event\s*:\s*(.+?)\s*$/', $line, $m)) {
            return trim($m[1], " \"'");
        }
    }
    return '';
}

/** Where one event's transcriber settings live. '' if the name is unusable as a path --
 *  an event called "../../etc" must not be able to name a file outside the events tree. */
function transcriber_event_path(string $event): string
{
    $event = trim($event);
    if ($event === '' || strpos($event, '/') !== false || strpos($event, "\0") !== false
        || $event === '.' || $event === '..') {
        return '';
    }
    return MARSAPRS_WEB_ROOT . '/events/' . $event . '/transcriber.json';
}

/** One event's settings, with every key present so callers never test for absence. */
function transcriber_event_load(string $event): array
{
    $blank = ['label' => '', 'model' => 'ggml-base.en.bin', 'enabled' => true,
              'send_audio' => false, 'sheet_url' => '', 'vocabulary_extra' => ''];
    $f = transcriber_event_path($event);
    if ($f === '' || !is_readable($f)) return $blank;
    $raw = json_decode((string)file_get_contents($f), true);
    if (!is_array($raw)) return $blank;
    return [
        'label'            => substr(trim((string)($raw['label'] ?? '')), 0, 40),
        'model'            => in_array($raw['model'] ?? '', ['ggml-tiny.en.bin', 'ggml-base.en.bin'], true)
                              ? $raw['model'] : 'ggml-base.en.bin',
        'enabled'          => !empty($raw['enabled']),
        'send_audio'       => !empty($raw['send_audio']),
        'sheet_url'        => substr(trim((string)($raw['sheet_url'] ?? '')), 0, 300),
        'vocabulary_extra' => substr((string)($raw['vocabulary_extra'] ?? ''), 0, 20000),
    ];
}

/** Write one event's settings. Same tmp-then-rename as everything else here, so a reader
 *  never sees a half-written file. */
function transcriber_event_save(string $event, array $settings): bool
{
    $f = transcriber_event_path($event);
    if ($f === '') return false;
    $dir = dirname($f);
    if (!is_dir($dir)) return false;      // an event directory is created by the map, not here
    $merged = array_merge(transcriber_event_load($event), $settings);
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return rename($tmp, $f);
}

function transcriber_state_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-state.json';
}

function transcriber_state_load(?string $path = null): array
{
    $f = transcriber_state_path($path);
    if (!is_readable($f)) return ['devices' => [], 'update_requested' => 0];
    $raw = json_decode((string)file_get_contents($f), true) ?: [];
    return ['devices' => $raw['devices'] ?? [], 'update_requested' => (int)($raw['update_requested'] ?? 0)];
}

function transcriber_state_save(array $s, ?string $path = null): void
{
    $f = transcriber_state_path($path);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $f);
}

/** What this device's channels currently amount to.
 *
 *  Compared against what it was last served, this answers "has it got the change yet"
 *  without consulting a clock. It also answers it correctly for a device the change did
 *  not touch: its fingerprint still matches, so it is up to date rather than pending
 *  forever on an edit that was never about it.
 */
function transcriber_channels_fingerprint(string $device, ?string $path = null): string
{
    return hash('sha256', json_encode(transcriber_channels_for($device, $path)));
}

/** Record that this device just collected its settings, and what it was given. */
function transcriber_mark_fetch(string $device, ?string $path = null): void
{
    $s = transcriber_state_load($path);
    $s['devices'][$device] = [
        'last_fetch' => time(),
        'served'     => transcriber_channels_fingerprint($device, $path),
    ];
    transcriber_state_save($s, $path);
}

/** Per-device: when it last checked in, and whether what it holds is current. */
function transcriber_device_status(?string $path = null): array
{
    $state = transcriber_state_load($path);
    $out = [];
    foreach (transcriber_load($path)['devices'] as $d) {
        $host = (string)($d['host'] ?? '');
        if ($host === '') continue;
        $seen = $state['devices'][$host] ?? [];
        $out[] = [
            'host'       => $host,
            'last_fetch' => (int)($seen['last_fetch'] ?? 0),
            'up_to_date' => (($seen['served'] ?? '') === transcriber_channels_fingerprint($host, $path)),
        ];
    }
    return $out;
}

/** Ask every device to do a full update — software as well as configuration — the next
 *  time it looks. Devices compare this against the last one they honoured, so nothing
 *  has to be written back and a device that was switched off simply catches up. */
function transcriber_request_update(?string $path = null): int
{
    $s = transcriber_state_load($path);
    $s['update_requested'] = time();
    transcriber_state_save($s, $path);
    return $s['update_requested'];
}

/** A fingerprint of the registry as it stands, for detecting a stale editor.
 *
 *  Over the stored file rather than a version counter: nothing has to be incremented and
 *  it notices a change made by any route, including a hand edit of the JSON — which is
 *  precisely how the registry came to differ from an open page in the first place.
 */
function transcriber_fingerprint(?string $path = null): string
{
    $f = transcriber_path($path);
    return is_readable($f) ? hash('sha256', (string)file_get_contents($f)) : 'empty';
}

/* ── What a device reports about itself ───────────────────────────────────────
 *
 * Two things, each in its own file: the live audio level while somebody sets a radio's
 * volume, and a heartbeat saying the channel is still running.
 *
 * Their own files, and that is the whole of what keeps an open manager page working.
 * Both are written by devices at moments nobody chose, and the page carries a fingerprint
 * of the registry so a stale write is refused — put these together and a receiver
 * reporting in would make an open page refuse its own Save. The same reasoning that gave
 * the vocabulary its own file, and the same conclusion.
 *
 * Not in transcriber-state.json either, which is the file that looks like the obvious
 * home. That one is rewritten by every device on every 60-second poll, and a
 * read-modify-write arriving from two directions at once loses whichever landed first.
 */

/**
 * Where the live audio level lives, for the meter on the manager page.
 *
 * Its own file, and deliberately: this is a reading that is worthless three seconds
 * later, and putting it anywhere durable would mean rewriting a record every second to
 * carry a number nobody wants to keep.
 */
function transcriber_level_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-level.json';
}

/** Latest level per channel: {channel: {db, wide_db, at}}. Stale rows are the caller's
 *  problem to notice -- `at` is what says whether a reading still means anything. */
function transcriber_level_load(?string $path = null): array
{
    $f = transcriber_level_path($path);
    $raw = is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $out = [];
    foreach ($raw as $id => $row) {
        if (!is_array($row)) continue;
        $out[(string)$id] = [
            'db'      => (float)($row['db']      ?? -99),
            'wide_db' => (float)($row['wide_db'] ?? -99),
            'at'      => (int)  ($row['at']      ?? 0),
        ];
    }
    return $out;
}

/**
 * Record one channel's current level.
 *
 * No lock and no read-modify-write. This is written about
 * once a second per channel by the only device that can speak for it, and the whole file
 * is rewritten each time -- so two channels reporting in the same instant can lose one
 * reading. That is the right trade here: a lost sample is invisible on a meter that
 * refreshes a second later, and taking a lock every second for a value with a one-second
 * shelf life would cost more than it protects.
 */
function transcriber_level_update(string $channel, float $db, float $wide, ?string $path = null): void
{
    $file = transcriber_level_path($path);
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $all = transcriber_level_load($path);
    // Drop anything nobody has reported in five minutes, so a retired channel does not
    // sit here forever showing a level it last had in August.
    $now = time();
    foreach ($all as $id => $row) if ($now - $row['at'] > 300) unset($all[$id]);
    $all[$channel] = ['db' => round($db, 1), 'wide_db' => round($wide, 1), 'at' => $now];
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT) . "\n");
    @chmod($tmp, 0640);
    rename($tmp, $file);
}

/**
 * Heartbeats: {channel: {at, version, last_heard, heard}}.
 *
 * Separate from the level feed because it means the opposite thing. A level reading is
 * worthless three seconds later and is only sent while somebody is turning a knob; a
 * heartbeat is worth most when it STOPS, and its absence is the whole signal. Nothing
 * expires rows here for the same reason: a channel that has not been heard from in a
 * week is exactly what the manager needs to show, and dropping the row would put it back
 * to displaying nothing at all -- which is the state this was written to fix.
 */
function transcriber_heartbeat_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-heartbeat.json';
}

function transcriber_heartbeat_load(?string $path = null): array
{
    $f = transcriber_heartbeat_path($path);
    $raw = is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $out = [];
    foreach ($raw as $id => $row) {
        if (!is_array($row)) continue;
        $out[(string)$id] = [
            'at'         => (int)   ($row['at']         ?? 0),
            'version'    => (string)($row['version']    ?? ''),
            'last_heard' => (int)   ($row['last_heard'] ?? 0),
            'heard'      => (int)   ($row['heard']      ?? 0),
        ];
    }
    return $out;
}

/** Record one channel's heartbeat. Whole-file rewrite without a lock, as in
 *  transcriber_level_update: one writer per channel, once a minute, and a lost beat
 *  costs nothing because the next one is sixty seconds behind it. */
function transcriber_heartbeat_update(string $channel, array $beat, ?string $path = null): void
{
    $file = transcriber_heartbeat_path($path);
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $all = transcriber_heartbeat_load($path);
    $all[$channel] = [
        'at'         => time(),
        'version'    => substr(trim((string)($beat['version'] ?? '')), 0, 20),
        'last_heard' => max(0, (int)($beat['last_heard'] ?? 0)),
        'heard'      => max(0, (int)($beat['heard'] ?? 0)),
    ];
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT) . "\n");
    @chmod($tmp, 0640);
    rename($tmp, $file);
}

/** Which device owns a channel, or '' if no channel by that name exists.
 *
 *  The check that keeps one Transcriber from reporting about another's channels. A report
 *  is authenticated with the DEVICE token — the channel's own token writes log entries and
 *  nothing else, and blurring that is how a registry stops meaning anything — so the
 *  channel it names has to be checked against the device that sent it. */
function transcriber_channel_device(string $channel, ?string $path = null): string
{
    if ($channel === '') return '';
    foreach (transcriber_load($path)['channels'] as $c) {
        if ((string)($c['id'] ?? '') === $channel) return (string)($c['device'] ?? '');
    }
    return '';
}

/* The calibration record lived here until 2026-08-29: what each channel had measured
 * for tuner gain and software squelch, which channel had been asked to measure itself,
 * and the started/done/failed report a device sent back while it did.
 *
 * All of it belonged to the SDR. A receiver whose own squelch gates the audio has no
 * tuner gain to set and no software squelch to measure -- it has a volume knob, and the
 * meter on the manager page is how that gets set. The whole path went out with rtl_fm.
 *
 * transcriber-calibration.json is left on disk rather than deleted here. Nothing reads
 * it, and a store function that exists only to remove a file is a worse thing to keep
 * than the file.
 */

/* ── Assignment-sheet vocabulary ───────────────────────────────────────────────
 *
 * Every event has a radio assignment sheet in Google Docs: who is where, on what
 * frequency, under what tactical call. The words on it are exactly the words that will
 * be said on the air, and they are exactly the words whisper is worst at — a callsign is
 * a string of letters and digits with no language model behind it, and "K6DRK" comes back
 * as "K6 dark" or "case six DRK" often enough to make the log tedious to read. Handed the
 * list up front as a prompt, it gets them.
 *
 * There is nothing clever here and there does not need to be: a plain regex over the
 * document's plain-text export finds every callsign and every tactical call on the real
 * sheet. What actually matters is normalizing what comes back — the export is full of
 * tabs and stray case, so the same call arrives as "Net control\t", "net control\n" and
 * "netcontrol" — and being narrow about what is taken at all.
 *
 * What a pattern cannot get is place names. Aid stations answer to their own tactical
 * calls — "Windy Gap", "Cardiac", "Bootjack", "Pantoll", "Stinson Beach" — and those are
 * multi-word proper nouns with no shape to them at all. So they are stated, from three
 * places: a section in the sheet, a box on the manager for the same syntax typed
 * mid-event, and one standing list shared by every event. All three are read by
 * transcriber_vocabulary_lines() and folded together by transcriber_vocabulary_merge();
 * the section is found by transcriber_vocabulary_section(), which also reports whether it
 * was there.
 *
 * The sheet URL lives here, fleet-wide, because the vocabulary is per-event and there is
 * one live event at a time. It arguably belongs on the event in the map admin instead,
 * beside the event name and date: that is where an operator sets up an event, that is
 * where it would survive one event ending and the next beginning, and a Transcriber page
 * is a strange place to keep a fact about a race. That is the right long-term home and
 * this is deliberately not it yet — moving it there means a schema change to event.yaml
 * and a second admin page, for a field that is typed once a month.
 */

/** The plain-text export URL for whatever somebody pasted.
 *
 *  Accepts the /edit URL a browser gives you, any other /document/d/<id>/… URL, or a bare
 *  document ID. Nobody types an export URL, and asking for one would mean explaining what
 *  it is. Google's export needs no authentication as long as the document is link-shared,
 *  which every one of these already is because the whole team reads it. */
function transcriber_sheet_export_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (preg_match('#/document/d/([A-Za-z0-9_-]{16,})#', $raw, $m))      $id = $m[1];
    elseif (preg_match('#^[A-Za-z0-9_-]{16,}$#', $raw))                  $id = $raw;
    else return '';
    return "https://docs.google.com/document/d/$id/export?format=txt";
}

/** How many terms and corrections are carried, from the three sources together.
 *
 *  This bounds MATCHING, and matching wants as many as it can get. Every term is an exact
 *  target the worker compares against what whisper produced; one that never comes up on
 *  the air costs a comparison and nothing else. It does NOT bound the whisper prompt, which
 *  is where 200 came from — the prompt has a hard ~224-token limit and the worker trims to
 *  it itself, in Vocabulary.prompt(), dropping whole terms in priority order because only
 *  the worker knows what whisper's tokenizer will do with "K6DRK". The server owes it a
 *  list, not a short one.
 *
 *  200 applied here was the prompt's number on the wrong list, and it failed the way this
 *  project keeps failing: 270 lines were pasted into the supplement box, 200 were kept, 70
 *  were dropped and nothing anywhere said so. The only clue was that the displayed list
 *  looked short. Whatever is discarded now is counted and reported — see
 *  transcriber_vocabulary_merge().
 *
 *  1000, not unbounded. The cost is not the wire — a thousand terms is about 15 KB per
 *  device poll — it is resolve() on the receiver, which scores every candidate span against
 *  every phrase of the same word count, for every clip, on a Pi that has to keep up with a
 *  net. A finite ceiling also means a hand-edited registry or a runaway paste cannot turn
 *  into a receiver that falls behind the traffic. 1000 is roughly four times the largest
 *  list anybody has typed. */
if (!defined('TRANSCRIBER_MAX_TERMS')) define('TRANSCRIBER_MAX_TERMS', 1000);

/** The longest a term may be. A place name is two or three words; anything past this is a
 *  sentence somebody pasted, and a sentence in a whisper prompt biases the model towards
 *  saying it. */
if (!defined('TRANSCRIBER_MAX_TERM_LENGTH')) define('TRANSCRIBER_MAX_TERM_LENGTH', 80);

/** A heard-form reduced to the one shape both ends compare on: lower case, and anything
 *  that is not a letter or a digit becomes a single space.
 *
 *  transcriber.py's correction_key() does exactly this and must go on doing exactly this.
 *  The server writes the keys and the worker looks them up, so a difference between the
 *  two is not a mismatch anybody would see — it is a correction rule that silently never
 *  fires. Deliberately NOT phrase_key(): that maps spoken numbers to digits, which the
 *  server has no table for, and half a shared normalization is worse than none. */
function transcriber_correction_key(string $s): string
{
    return trim(strtolower(preg_replace('/[^0-9A-Za-z]+/', ' ', $s)));
}

/** One term (or one correction) per line, in the syntax all three sources use. Returns
 *  ['terms' => [...], 'corrections' => [key => written], 'dropped' => n].
 *
 *      Windy Gap                 a phrase this event expects to hear
 *      Cardiac Hill = Cardiac    what whisper produced = what it should say
 *
 *  Everything else on a line is decoration and is taken off: the export puts a tab in
 *  front of anything that was in a table cell, and Docs writes a bulleted list as "* term"
 *  and a numbered one as "1. term". An author will use a list — it is a list — and a
 *  vocabulary full of asterisks would be a feature that looks like it works. */
function transcriber_vocabulary_lines(string $block): array
{
    $terms = [];
    $corrections = [];
    foreach (preg_split("/\r\n|\r|\n/", $block) as $line) {
        $line = transcriber_trim_line($line);
        $line = preg_replace('/^(?:[*•\-\x{2013}]|\d+[.)])\s+/u', '', $line);
        if ($line === '' || strlen($line) > TRANSCRIBER_MAX_TERM_LENGTH) continue;

        if (strpos($line, '=') !== false) {
            [$heard, $written] = explode('=', $line, 2);
            $heard   = transcriber_trim_line($heard);
            $written = transcriber_trim_line($written);
            $key     = transcriber_correction_key($heard);
            // Half a rule is not a rule. "= Cardiac" would match everything and
            // "Cardiff =" would rewrite text into nothing; both are typos, and acting on
            // either is worse than ignoring it.
            //
            // A heard-form of nothing but digits is refused for a different reason: it
            // would fire on every reading of that number on the air — bib numbers, times,
            // frequencies — and a bare number is not a mishearing anybody can pin down. It
            // also keeps these out of PHP's integer-key territory, where a map keyed "0"
            // silently encodes as a JSON array and the worker drops every rule in it.
            if ($key === '' || $written === '' || !preg_match('/[a-z]/', $key)) continue;
            $corrections[$key] = $written;
            // The written form is a term as well. Somebody who says "Cardiac comes out as
            // Cardiff" has told us Cardiac is a phrase this event says, and there is no
            // reason to make them type it a second time for the prompt to know it.
            $terms[] = $written;
            continue;
        }
        $terms[] = $line;
    }
    $terms = array_values(array_unique($terms));
    // What the ceiling threw away, counted rather than assumed. One source alone can reach
    // it, and a list that quietly stops at a round number looks exactly like a list that
    // was that long. Lines refused for being longer than a term is allowed to be are not
    // counted here — that is a pasted sentence, not truncation, and folding the two
    // together would make the number mean nothing.
    $dropped = max(0, count($terms) - TRANSCRIBER_MAX_TERMS)
             + max(0, count($corrections) - TRANSCRIBER_MAX_TERMS);
    return [
        'terms'       => array_slice($terms, 0, TRANSCRIBER_MAX_TERMS),
        'corrections' => array_slice($corrections, 0, TRANSCRIBER_MAX_TERMS, true),
        'dropped'     => $dropped,
    ];
}

/** Whitespace off both ends, including the non-breaking space Google Docs sprinkles
 *  through an export. trim() would leave one, and a term with an invisible character on
 *  the front matches nothing and looks exactly like a term that does. */
function transcriber_trim_line(string $s): string
{
    return (string)preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s);
}

/** The stated vocabulary: a heading containing "Vocabulary", then one term per line, to
 *  the first blank line or the end of the document.
 *
 *  This exists because patterns cannot get place names. Aid stations answer to their own
 *  tactical calls — "Windy Gap", "Cardiac", "Bootjack", "Pantoll", "Stinson Beach" —
 *  multi-word proper nouns with no shape to them, and a regex wide enough to catch those
 *  would catch half the document. So the sheet states them and this reads what it was
 *  told.
 *
 *  The heading is a heading and not any line with the word in it: reduced to its words it
 *  must be five or fewer, which admits "Transcriber Vocabulary", "Vocabulary:" and
 *  "Vocabulary (place names)" and rejects a sentence about vocabulary. The sheet is prose
 *  as well as tables, and the cost of getting this wrong is the section swallowing the
 *  operators' names into the fleet's prompt.
 *
 *  `found` is returned separately from the terms and is not decoration. If somebody
 *  renames the heading, or the section is lost in an edit, the terms silently become none
 *  and the first anybody knows is a log full of "Windy Cap" — so the manager says whether
 *  it was there, distinctly from how many terms it held. */
function transcriber_vocabulary_section(string $text): array
{
    $lines = preg_split("/\r\n|\r|\n/", $text);
    $start = -1;
    foreach ($lines as $i => $line) {
        if (stripos($line, 'vocabulary') === false) continue;
        $words = preg_split('/[^A-Za-z]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= 5) { $start = $i; break; }
    }
    if ($start < 0) return ['found' => false, 'terms' => [], 'corrections' => [], 'dropped' => 0];

    $block = [];
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (transcriber_trim_line($lines[$i]) === '') break;
        $block[] = $lines[$i];
    }
    return ['found' => true] + transcriber_vocabulary_lines(implode("\n", $block));
}

/** The vocabulary a radio assignment sheet contains — and nothing else.
 *
 *  Four lists, all narrow on purpose. The sheet is somebody's working document: it also
 *  carries operators' full names, their shift times and a mobile number, none of which a
 *  fleet of receivers has any business holding. So this takes the things a whisper prompt
 *  can act on, and the document itself is never written to disk. Two are found by pattern
 *  and two are stated outright in the Vocabulary section, and `section_found` says whether
 *  that section was there at all.
 *
 *  Everything is returned in the form it would be spoken, not the form it was typed in.
 *  The export is a table flattened to text, so a match routinely arrives as "Sweep\t1" or
 *  "finish " — the strings are rebuilt from the parts rather than trimmed, so there is
 *  only ever one spelling of each. */
function transcriber_extract_vocabulary(string $text): array
{
    // US amateur callsign shape: one or two letters, one digit, one to three letters.
    //
    // The trailing letters are the whole of what keeps this away from the frequency table
    // it sits next to. "CC3", "TS1" and "Ch21R" have nothing after the digit; "440.1375MHz"
    // and "PL 192.8Hz" have no letter before one. The word boundary at each end matters as
    // much: without it the pattern reaches inside the base64 of a Zoom link, where
    // "B65XT8" is a perfectly good callsign. It also drops the SSID off a tracker's
    // identifier, which is right — KM6BON-7 is said on the air as KM6BON.
    preg_match_all('/\b([A-Z]{1,2}[0-9][A-Z]{1,3})\b/', $text, $m);
    $callsigns = array_values(array_unique($m[1]));
    sort($callsigns);

    // Numbered roles. A number is required, because a bare role word is prose — the real
    // sheet says "the last sweep, lenny, has a tracker" — and "Sweep" on its own is a word
    // whisper already knows. Hiker is in the list because the real sheet has five of them
    // patrolling the course; it is the same kind of thing as a Sweep and it is said on the
    // air the same way.
    $canonical = ['sweep' => 'Sweep', 'sag' => 'SAG', 'aid' => 'Aid',
                  'biker' => 'Biker', 'hiker' => 'Hiker'];
    $tactical = [];
    preg_match_all('/\b(sweep|sag|aid|biker|hiker)\s*([0-9]{1,2})\b/i', $text, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $tactical[] = $canonical[strtolower($hit[1])] . ' ' . (int)$hit[2];
    }

    // Calls that stand on their own. SAG is here as well as above because the sheet says
    // "SAG Wagon" and the driver answers to "SAG"; the negative lookahead keeps it from
    // firing on "SAG 2", which the numbered pass already has.
    $standalone = [
        '/\bnet[\s]*control\b/i'  => 'Net Control',
        '/\bsag\b(?!\s*[0-9])/i'  => 'SAG',
        '/\bstart\b/i'            => 'Start',
        '/\bfinish\b/i'           => 'Finish',
    ];
    foreach ($standalone as $re => $name) {
        if (preg_match($re, $text)) $tactical[] = $name;
    }

    $tactical = array_values(array_unique($tactical));
    usort($tactical, 'strnatcasecmp');       // "Sweep 2" before "Sweep 10", not after

    // What no pattern can find: the section the sheet states outright. Reported as found
    // or not found alongside what it held, because a missing section and an empty one look
    // identical from the lists alone and only one of them is a problem.
    $section = transcriber_vocabulary_section($text);

    // Bounded. This is served to every device on every poll and turned into a prompt at
    // channel start; a document that somehow matched thousands of things is a mistake
    // somewhere, and it should not become a mistake on the air.
    return [
        'callsigns'     => array_slice($callsigns, 0, 500),
        'tactical'      => array_slice($tactical, 0, 200),
        'terms'         => $section['terms'],
        'corrections'   => $section['corrections'],
        'section_found' => $section['found'],
        // Carried with the lists so it survives into the stored vocabulary and reaches the
        // manager. A sheet that states more terms than the ceiling allows has to say so on
        // the page, not on the run that read it.
        'terms_dropped' => $section['dropped'],
    ];
}

/** Kept beside the registry rather than inside it, for the same reason the device state
 *  is: it is refetched on a timer and rewritten each time, and the registry is the file
 *  that holds every token in the fleet. It is also what keeps a refresh from moving the
 *  registry's fingerprint — otherwise a background refresh would make an open manager
 *  page refuse its own Save as a stale write. Nothing in here is secret. */
function transcriber_vocabulary_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-vocabulary.json';
}

function transcriber_vocabulary_load(?string $path = null): array
{
    $f = transcriber_vocabulary_path($path);
    $raw = is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $corrections = [];
    foreach ((array)($raw['corrections'] ?? []) as $heard => $written) {
        if (is_string($heard) && is_string($written)) $corrections[$heard] = $written;
    }
    return [
        'callsigns'     => array_values(array_filter((array)($raw['callsigns'] ?? []), 'is_string')),
        'tactical'      => array_values(array_filter((array)($raw['tactical']  ?? []), 'is_string')),
        'terms'         => array_values(array_filter((array)($raw['terms']     ?? []), 'is_string')),
        'corrections'   => $corrections,
        // Whether the sheet's Vocabulary heading was there the last time it was read. Its
        // own field and not inferred from an empty terms list: a sheet whose section has
        // been renamed away looks exactly like a sheet that never had one, and the whole
        // point of asking is to tell those two apart.
        'section_found' => (bool)($raw['section_found'] ?? false),
        'terms_dropped' => (int)($raw['terms_dropped'] ?? 0), // over the ceiling, at read time
        'fetched_at'    => (int)($raw['fetched_at'] ?? 0),   // last time it worked
        'checked_at'    => (int)($raw['checked_at'] ?? 0),   // last time it was tried
        'source'        => (string)($raw['source'] ?? ''),
        'error'         => (string)($raw['error'] ?? ''),
    ];
}

function transcriber_vocabulary_save(array $v, ?string $path = null): void
{
    $f = transcriber_vocabulary_path($path);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $f);
}

/* ── The standing vocabulary ───────────────────────────────────────────────────
 *
 * The third source, and the only one that is not about a particular event. Most of what a
 * net says does not change between events: the procedural words, the amateur-radio terms,
 * and the place names of the region every one of these events happens in. Until now that
 * had to be retyped into each event's sheet, which meant it was retyped imperfectly or not
 * at all.
 *
 * One file, fleet-wide, in its own file beside the registry — and that placement is
 * decided by the same two facts that put the per-event vocabulary in its own file:
 *
 *   - The manager refuses a Save made against a stale registry fingerprint. This list is
 *     written from its own button, at its own moment, and if it lived in the registry then
 *     saving it would move that fingerprint and the open page would refuse its own next
 *     Save — a page that breaks itself, for a reason nobody could see. The same reasoning
 *     as the vocabulary file and the heartbeat file, and the same conclusion.
 *   - It is not in transcriber-vocabulary.json either, which is the file that looks like
 *     the obvious home. That one is overwritten whole by every sheet refresh, so a standing
 *     list kept in it would be erased by the next poll — silently, fifteen minutes later,
 *     with nothing to connect the two.
 *
 * Nothing in it is secret, so it has no business in the file that holds every token in the
 * fleet.
 *
 * Precedence on a clash is standing < sheet < box, and it is worth saying why in case
 * somebody reverses it later. More specific beats more general: the sheet is about THIS
 * event and the standing list is about all of them, and the box was typed most recently by
 * somebody watching the log get that exact phrase wrong. The same order decides which
 * spelling of a repeated term survives, which terms fill the worker's prompt budget first,
 * and — if a ceiling is ever reached — which are given up first.
 */

/** Where a standing list that has never been edited starts from.
 *
 *  Not an empty box. An empty box teaches nobody what belongs in it, and this list only
 *  earns its keep if it is populated, so it ships populated.
 *
 *  Chosen conservatively, and the rule is the important part: every line here is a match
 *  target, so a distinctive or multi-word term is close to free and a common English word
 *  is expensive everywhere. "Runner" and "Bib" in a real list capitalized every mention of
 *  a runner and a bib, and "Cardiac" turns "cardiac arrest" into "Cardiac arrest". So the
 *  aid station called Cardiac is deliberately NOT here — an event that wants it puts it on
 *  its own sheet, where it is worth the cost for that one day.
 *
 *  No corrections. A correction is an instruction from somebody who has watched a specific
 *  mishearing happen, and there is nothing to seed one from. */
if (!defined('TRANSCRIBER_STANDING_SEED')) define('TRANSCRIBER_STANDING_SEED', <<<TXT
Net Control
Amateur Radio
radio check
say again
standing by
break break
priority traffic
emergency traffic
health and welfare
simplex
duplex
APRS
iGate
digipeater
Winlink
ARES
ARRL
QSL
QSY
QTH
QRZ
QRM
Mount Tamalpais
Mill Valley
Muir Woods
Muir Beach
Stinson Beach
Panoramic Highway
Sequoia Valley Road
Tennessee Valley
Steep Ravine
Bolinas Ridge
Ridgecrest Boulevard
Rock Spring
East Peak
West Point Inn
Pantoll
Bootjack
Windy Gap
Dipsea
Marin Headlands
Point Reyes
San Rafael
Sausalito
Corte Madera
Larkspur
Fairfax
Novato
Tiburon
Golden Gate Bridge
TXT);

/** How much text the standing list may hold. Twice the supplement box's, because this is
 *  the long list and the box is a handful of lines typed mid-event — and, like the box's,
 *  set well above the term ceiling on purpose. A byte cap truncates silently and there is
 *  nowhere sensible to report half a cut line, so the limit anybody actually reaches has to
 *  be the one that is counted. */
if (!defined('TRANSCRIBER_STANDING_MAX_BYTES')) define('TRANSCRIBER_STANDING_MAX_BYTES', 40000);

function transcriber_standing_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-standing.json';
}

/** The standing list as typed, with a fingerprint for the editor to save against.
 *
 *  The seed is used only when the file does not exist. Once it has been saved, whatever it
 *  says is what it says — including nothing at all. A load that fell back to the seed
 *  whenever the text was empty would make "delete everything" the one edit that cannot be
 *  made, and it would make it fail by quietly restoring fifty terms. */
function transcriber_standing_load(?string $path = null): array
{
    $f = transcriber_standing_path($path);
    if (!is_readable($f)) {
        return ['text' => TRANSCRIBER_STANDING_SEED, 'updated_at' => 0,
                'seeded' => true, 'fingerprint' => 'empty'];
    }
    $body = (string)file_get_contents($f);
    $raw  = json_decode($body, true) ?: [];
    $text = $raw['text'] ?? '';
    return [
        'text'        => is_string($text) ? $text : '',
        'updated_at'  => (int)($raw['updated_at'] ?? 0),
        'seeded'      => false,
        'fingerprint' => hash('sha256', $body),
    ];
}

function transcriber_standing_save(string $text, ?string $path = null): array
{
    if (strlen($text) > TRANSCRIBER_STANDING_MAX_BYTES) {
        $text = substr($text, 0, TRANSCRIBER_STANDING_MAX_BYTES);
        // Back to the last complete line. Cutting at a byte can land in the middle of a
        // UTF-8 character — mb_substr is not available, the Pi's PHP has no mbstring — and
        // a term with half a character on the end matches nothing while looking like a term
        // that does.
        $nl = strrpos($text, "\n");
        $text = $nl === false ? '' : substr($text, 0, $nl);
    }
    $f   = transcriber_standing_path($path);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode(['text' => $text, 'updated_at' => time()],
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $f);
    return transcriber_standing_load($path);
}

/** The three sources folded into one vocabulary, with an account of anything discarded.
 *
 *  Returns the four lists the devices are promised, plus `dropped` — how many terms and
 *  corrections each source lost, by name. The count is the whole reason this function
 *  exists separately from transcriber_vocabulary_words(): the old code sliced the merged
 *  list and said nothing, so 70 of 270 pasted lines disappeared and the only symptom was
 *  that the list on the page looked shorter than the one in the clipboard.
 *
 *  Sources are taken most specific first — box, then sheet, then standing — and that
 *  ordering does three jobs at once. The first spelling of a repeated term is the one kept;
 *  the worker fills its prompt budget from the front of the list; and the ceiling cuts from
 *  the back. All three should give up the general list before anything typed for this
 *  event. Corrections follow the same order for the same reason, so the rule that wins a
 *  clash is also the rule that survives a truncation.
 *
 *  The box and the standing list are merged here rather than baked into the stored
 *  vocabulary, and that placement is the whole reason the box is useful. It takes effect the
 *  moment it is saved, with no fetch: the case it exists for is an event already running,
 *  with a shared document that is either unreachable or not yours to edit. Both also survive
 *  a failed refresh, which keeps yesterday's sheet lists — and yesterday's sheet is exactly
 *  when somebody is typing into that box. */
function transcriber_vocabulary_merge(?string $path = null): array
{
    $v = transcriber_vocabulary_load($path);
    // is_string rather than a cast: the registry can be hand-edited, and casting an array
    // to a string here would put the word "Array" in the fleet's vocabulary — from inside
    // a device poll, where a warning is a receiver that did not get its channels.
    // The supplement box belongs to the event, same as the sheet it supplements.
    $raw      = transcriber_event_settings_for($path)['vocabulary_extra'];
    $extra    = transcriber_vocabulary_lines(is_string($raw) ? $raw : '');
    $standing = transcriber_vocabulary_lines(transcriber_standing_load($path)['text']);

    $sources = [
        'box'      => $extra,
        'sheet'    => ['terms' => $v['terms'], 'corrections' => $v['corrections'],
                       'dropped' => $v['terms_dropped']],
        'standing' => $standing,
    ];

    // Whatever each source already lost to the per-source ceiling when it was parsed, plus
    // whatever it loses to the merged ceiling below. Both are truncation and a reader has no
    // reason to care which happened.
    $dropped     = [];
    $terms       = [];
    $seen        = [];
    $corrections = [];
    foreach ($sources as $name => $src) {
        $dropped[$name] = (int)($src['dropped'] ?? 0);
        foreach ($src['terms'] as $term) {
            if (isset($seen[$term])) continue;
            if (count($terms) >= TRANSCRIBER_MAX_TERMS) { $dropped[$name]++; continue; }
            $seen[$term] = true;
            $terms[] = $term;
        }
        foreach ($src['corrections'] as $heard => $written) {
            // A more specific source has already claimed this heard-form. Not a drop: the
            // rule was overridden, which is what precedence means, and counting it as
            // truncation would send somebody looking for a limit they have not reached.
            if (isset($corrections[$heard])) continue;
            if (count($corrections) >= TRANSCRIBER_MAX_TERMS) { $dropped[$name]++; continue; }
            $corrections[$heard] = $written;
        }
    }

    return [
        'callsigns'   => $v['callsigns'],
        'tactical'    => $v['tactical'],
        'terms'       => $terms,
        'corrections' => $corrections,
        'dropped'     => $dropped,
    ];
}

/** What the devices are promised, in the shape they are promised it.
 *
 *  Four lists, and exactly four. `callsigns` and `tactical` are exactly what they always
 *  were, because the field is never all on one version at once: a device fetches this
 *  before its worker knows what `terms` is, and a worker on new code polls a server that
 *  has not been deployed yet. Both directions are ordinary — extra keys are ignored, absent
 *  ones read as empty — and neither is worth a version number. The third source changed
 *  what goes into `terms`, not what a receiver is handed. */
function transcriber_vocabulary_words(?string $path = null): array
{
    $m = transcriber_vocabulary_merge($path);
    return ['callsigns'   => $m['callsigns'],
            'tactical'    => $m['tactical'],
            'terms'       => $m['terms'],
            'corrections' => $m['corrections']];
}

/** The manager's view: what was read off the sheet, what each typed list parsed to, what is
 *  in force once all three are folded together, and what — if anything — was discarded.
 *
 *  All of it, because they answer different questions: "did it read MY sheet", "did it
 *  understand what I typed", "will the receivers say Cardiac", and "is anything I typed
 *  simply not there". The counts alone answer none of them, which is why the page lists the
 *  words themselves and names the source of every dropped term. */
function transcriber_vocabulary_report(?string $path = null): array
{
    // The supplement box belongs to the event, same as the sheet it supplements.
    $raw      = transcriber_event_settings_for($path)['vocabulary_extra'];
    $standing = transcriber_standing_load($path);
    $merged   = transcriber_vocabulary_merge($path);
    return transcriber_vocabulary_load($path) + [
        'extra'    => transcriber_vocabulary_lines(is_string($raw) ? $raw : ''),
        'standing' => $standing + ['lines' => transcriber_vocabulary_lines($standing['text'])],
        'words'    => ['callsigns'   => $merged['callsigns'],
                       'tactical'    => $merged['tactical'],
                       'terms'       => $merged['terms'],
                       'corrections' => $merged['corrections']],
        'dropped'  => $merged['dropped'],
        'ceiling'  => TRANSCRIBER_MAX_TERMS,
    ];
}

/** Fetch the document's plain text. Returns [text, error]; one of them is always empty.
 *
 *  The HTTP stream wrapper rather than cURL, which is not installed on the Pi — the same
 *  reason tiles.php uses it. The export 302s to a googleusercontent host, so redirects
 *  have to be followed, and the timeout has to be short because this can run inside a
 *  device's configuration fetch.
 *
 *  A document that is not link-shared does not fail: Google redirects to a sign-in page
 *  and returns it with a 200, so the body has to be looked at. Extracting from an HTML
 *  login form would find nothing and report success, which is the worst of both. */
function transcriber_sheet_fetch(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'method'         => 'GET',
        'header'         => "User-Agent: MARSAPRS/1.0\r\n",
        'timeout'        => 8,
        'follow_location' => 1,
        'max_redirects'  => 5,
        'ignore_errors'  => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, 1_048_576);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $hm)) $code = (int)$hm[1];
    }
    if ($body === false || $body === '') return ['', 'the document could not be fetched'];
    if ($code !== 0 && $code !== 200) return ['', "the document returned HTTP $code"];
    if (stripos(substr($body, 0, 400), '<html') !== false
        || stripos(substr($body, 0, 400), '<!doctype html') !== false) {
        return ['', 'the document is not shared — set it to "Anyone with the link can view"'];
    }
    return [$body, ''];
}

/** Re-read the sheet now and store what it yields.
 *
 *  $fetch is injectable so the tests can exercise the extraction and the failure handling
 *  without a network; nothing in the field passes it.
 *
 *  A failed fetch keeps the previous lists. The sheet is edited up to the morning of an
 *  event, which is also when the network is a phone tethered to a folding table — losing
 *  a working vocabulary because one fetch timed out would be a strictly worse outcome
 *  than holding yesterday's. Clearing the URL does clear the lists, so a vocabulary can
 *  never outlive the document it came from with nothing to point at. */
function transcriber_vocabulary_refresh(?string $path = null, ?callable $fetch = null): array
{
    // The sheet belongs to the EVENT. An event with no sheet simply has no vocabulary to
    // fetch, which is a working receiver transcribing without hints -- not a failure.
    $url  = transcriber_sheet_export_url(transcriber_event_settings_for($path)['sheet_url']);
    $prev = transcriber_vocabulary_load($path);
    $now  = time();

    if ($url === '') {
        $v = ['callsigns' => [], 'tactical' => [], 'terms' => [], 'corrections' => [],
              'section_found' => false, 'terms_dropped' => 0, 'fetched_at' => 0,
              'checked_at' => $now, 'source' => '', 'error' => ''];
        transcriber_vocabulary_save($v, $path);
        return $v;
    }

    [$text, $err] = ($fetch ?? 'transcriber_sheet_fetch')($url);
    if ($err !== '') {
        $v = $prev;                 // whatever worked last time, kept
        $v['checked_at'] = $now;
        $v['source']     = $url;
        $v['error']      = $err;
        transcriber_vocabulary_save($v, $path);
        return $v;
    }

    $words = transcriber_extract_vocabulary($text);
    $v = $words + ['fetched_at' => $now, 'checked_at' => $now, 'source' => $url, 'error' => ''];
    transcriber_vocabulary_save($v, $path);
    return $v;
}

/** How long a fetched vocabulary is treated as current. Fifteen minutes is chosen against
 *  the only deadline that matters: the sheet is edited on the morning of an event, and a
 *  change made at the briefing has to be on the receivers before the start. */
if (!defined('TRANSCRIBER_VOCABULARY_TTL')) define('TRANSCRIBER_VOCABULARY_TTL', 900);

/** Refresh if it has not been tried recently. Silent, and never fatal.
 *
 *  This is what makes the vocabulary keep up without anybody opening the manager, so it
 *  has to run somewhere that runs on its own — which means the device configuration
 *  fetch, the one path that must not be made slower or more fragile. Hence the guards:
 *
 *   - checked_at, not fetched_at, so a document that cannot be reached is retried on the
 *     same schedule as one that can, rather than on every single poll;
 *   - a non-blocking lock, so eight devices polling in the same second produce one fetch
 *     and seven immediate returns rather than eight requests to Google;
 *   - an 8-second timeout inside a 30-second budget the device already allows.
 *
 *  Under Apache's mod_php there is no fastcgi_finish_request to hide this behind, so it
 *  is a real cost on a real request. It is bounded to one device, once every fifteen
 *  minutes, at a poll interval of sixty seconds. */
function transcriber_vocabulary_refresh_if_stale(?string $path = null, ?callable $fetch = null): void
{
    $v = transcriber_vocabulary_load($path);
    if (time() - $v['checked_at'] < TRANSCRIBER_VOCABULARY_TTL) return;

    $lockfile = transcriber_vocabulary_path($path) . '.lock';
    $lock = @fopen($lockfile, 'c');
    if ($lock === false) return;
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
    try {
        transcriber_vocabulary_refresh($path, $fetch);
    } catch (\Throwable $e) {
        // A receiver must never lose its channels because a Google Doc misbehaved.
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
