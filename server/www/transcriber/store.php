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
 * Two kinds of token, deliberately. A device token only fetches configuration; a
 * channel token only writes log entries. Neither can do the other's job, so a
 * Transcriber left in a shed with a readable config file cannot be used to read the
 * net's traffic.
 *
 * Stored beside messages.db, outside the web root. A registry of tokens under
 * /var/www/html is how mobile_trackers.json came to be downloadable by anyone.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

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

/** The systemd instance name and log identity for one channel, derived from the two
 *  things that actually define it. No '@' — that is systemd's instance separator. */
function transcriber_channel_id(string $device, $hz): string
{
    $device = preg_replace('/[^A-Za-z0-9_.-]/', '-', trim($device));
    $khz    = (int)round(((int)$hz) / 1000);
    if ($device === '' || $khz <= 0) return '';
    return substr("$device-$khz", 0, 64);
}

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
 * multi-word proper nouns with no shape to them at all. So the sheet gains a section that
 * states them, and the manager gains a box for the same syntax typed mid-event. Both are
 * read by transcriber_vocabulary_lines(); the section is found by
 * transcriber_vocabulary_section(), which also reports whether it was there.
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

/** How many terms and corrections are carried, from the sheet and the box together.
 *
 *  Bounded for the same reason the callsign list is: this is served to every device on
 *  every poll and turned into a prompt at channel start. A document that somehow matched
 *  thousands of things is a mistake somewhere, and it should not become a mistake on the
 *  air. */
if (!defined('TRANSCRIBER_MAX_TERMS')) define('TRANSCRIBER_MAX_TERMS', 200);

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

/** One term (or one correction) per line, in the syntax the sheet's section and the
 *  manager's box both use. Returns ['terms' => [...], 'corrections' => [key => written]].
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
    return [
        'terms'       => array_slice(array_values(array_unique($terms)), 0, TRANSCRIBER_MAX_TERMS),
        'corrections' => array_slice($corrections, 0, TRANSCRIBER_MAX_TERMS, true),
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
    if ($start < 0) return ['found' => false, 'terms' => [], 'corrections' => []];

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

/** What the devices are promised, in the shape they are promised it.
 *
 *  Four lists now. `callsigns` and `tactical` are exactly what they always were, because
 *  the field is never all on one version at once: a device fetches this before its worker
 *  knows what `terms` is, and a worker on new code polls a server that has not been
 *  deployed yet. Both directions are ordinary — extra keys are ignored, absent ones read
 *  as empty — and neither is worth a version number.
 *
 *  The manager's supplement box is merged in here rather than baked into the stored
 *  vocabulary, and that placement is the whole reason the box is useful. It takes effect
 *  the moment it is saved, with no fetch: the case it exists for is an event already
 *  running, with a shared document that is either unreachable or not yours to edit, and a
 *  correction that had to wait on a successful read of that document would be answering a
 *  different problem. It also survives a failed refresh, which keeps yesterday's sheet
 *  lists — and yesterday's sheet is exactly when you are typing into this box. */
function transcriber_vocabulary_words(?string $path = null): array
{
    $v = transcriber_vocabulary_load($path);
    // is_string rather than a cast: the registry can be hand-edited, and casting an array
    // to a string here would put the word "Array" in the fleet's vocabulary — from inside
    // a device poll, where a warning is a receiver that did not get its channels.
    $raw   = transcriber_load($path)['settings']['vocabulary_extra'] ?? '';
    $extra = transcriber_vocabulary_lines(is_string($raw) ? $raw : '');

    $terms = array_values(array_unique(array_merge($v['terms'], $extra['terms'])));
    // The box wins on a clash. It was typed later, and it was typed by somebody watching
    // the log get it wrong.
    $corrections = array_merge($v['corrections'], $extra['corrections']);

    return [
        'callsigns'   => $v['callsigns'],
        'tactical'    => $v['tactical'],
        'terms'       => array_slice($terms, 0, TRANSCRIBER_MAX_TERMS),
        'corrections' => array_slice($corrections, 0, TRANSCRIBER_MAX_TERMS, true),
    ];
}

/** The manager's view: what was read off the sheet, what the supplement box parsed to, and
 *  what is in force once the two are folded together.
 *
 *  All three, because they answer different questions — "did it read MY sheet", "did it
 *  understand what I typed", and "will the receivers say Cardiac". The counts alone answer
 *  none of them, which is why the page lists the words themselves. */
function transcriber_vocabulary_report(?string $path = null): array
{
    $raw = transcriber_load($path)['settings']['vocabulary_extra'] ?? '';
    return transcriber_vocabulary_load($path) + [
        'extra' => transcriber_vocabulary_lines(is_string($raw) ? $raw : ''),
        'words' => transcriber_vocabulary_words($path),
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
    $url  = transcriber_sheet_export_url((string)(transcriber_load($path)['settings']['sheet_url'] ?? ''));
    $prev = transcriber_vocabulary_load($path);
    $now  = time();

    if ($url === '') {
        $v = ['callsigns' => [], 'tactical' => [], 'terms' => [], 'corrections' => [],
              'section_found' => false, 'fetched_at' => 0, 'checked_at' => $now,
              'source' => '', 'error' => ''];
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
