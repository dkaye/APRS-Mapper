<?php
/**
 * spoken_ids.php — MARS APRS Map
 *
 * The tracker-ID name list: what a short map ID is called when it is written out
 * in the Messages panel or read aloud. `CAR` is the right label on a marker and
 * the wrong one everywhere else — "CAR Stanton" tells a reader nothing and comes
 * out of a speech engine as three letters.
 *
 * Pure functions: no globals, no output, no side effects beyond the one file this
 * writes. Required by both messaging_db.php, which stamps the expansion onto every
 * message the server hands out, and transcriber/index.php, which is where the list
 * is edited. One parser, so the page that writes the file and the code that reads
 * it can never disagree about what a line means.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/ADMIN.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

if (!defined('MARSAPRS_SPOKEN_IDS')) {
    define('MARSAPRS_SPOKEN_IDS', getenv('MARSAPRS_SPOKEN_IDS') ?: '/var/lib/marsaprs/spoken-ids.json');
}

// Generous, but not unbounded: this is one line per tracker ID and an event has
// tens, not thousands. The cap is here for the same reason the standing
// vocabulary has one — a paste accident should not fill the disk.
if (!defined('SPOKEN_IDS_MAX_BYTES')) define('SPOKEN_IDS_MAX_BYTES', 20000);

/**
 * Every function takes an optional path so tests can point at a temp file.
 *
 * The list belongs to the EVENT, not to the server: these are the tactical calls of
 * whoever is out today, and carrying one global list meant last month's names were still
 * being spoken at this month's event. Resolved per call from the config.yaml symlink the
 * rest of the server keys off, so switching the active event switches the names with it.
 *
 * MARSAPRS_SPOKEN_IDS remains the fallback for an event that has no list of its own and
 * for anything running outside a web root -- the CLI tools and the tests.
 */
function spoken_ids_path(?string $path = null): string
{
    if ($path) return $path;
    $ev = spoken_ids_event_dir();
    return $ev !== '' ? $ev . '/spoken-ids.json' : MARSAPRS_SPOKEN_IDS;
}

/** The active event's directory, or '' if there is not one to be found. */
function spoken_ids_event_dir(): string
{
    $root = defined('MARSAPRS_WEB_ROOT') ? MARSAPRS_WEB_ROOT : '/var/www/html';
    $cfg  = $root . '/config.yaml';
    if (!is_readable($cfg)) return '';
    foreach (file($cfg, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*event\s*:\s*(.+?)\s*$/', $line, $m)) {
            $name = trim($m[1], " \"'");
            if ($name === '' || strpos($name, '/') !== false) return '';
            $dir = $root . '/events/' . $name;
            return is_dir($dir) ? $dir : '';
        }
    }
    return '';
}

/**
 * The list as typed, with a fingerprint the editor saves against.
 *
 * Same concurrency guard as the standing vocabulary: this is a slowly-edited list,
 * which is exactly the case where two people with it open means one of them
 * silently loses everything they wrote.
 */
function spoken_ids_load(?string $path = null): array
{
    $f = spoken_ids_path($path);
    if (!is_readable($f)) {
        return ['text' => '', 'updated_at' => 0, 'fingerprint' => 'empty'];
    }
    $body = (string)file_get_contents($f);
    $raw  = json_decode($body, true) ?: [];
    $text = $raw['text'] ?? '';
    return [
        'text'        => is_string($text) ? $text : '',
        'updated_at'  => (int)($raw['updated_at'] ?? 0),
        'fingerprint' => hash('sha256', $body),
    ];
}

function spoken_ids_save(string $text, ?string $path = null): array
{
    if (strlen($text) > SPOKEN_IDS_MAX_BYTES) {
        $text = substr($text, 0, SPOKEN_IDS_MAX_BYTES);
        // Back to the last complete line. Cutting at a byte can land inside a UTF-8
        // character — the Pi's PHP has no mbstring — and half a character on the end
        // matches nothing while looking like it should.
        $nl   = strrpos($text, "\n");
        $text = $nl === false ? '' : substr($text, 0, $nl);
    }
    $f   = spoken_ids_path($path);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $body = json_encode(['text' => $text, 'updated_at' => time()],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents($f, $body, LOCK_EX);
    return ['text' => $text, 'updated_at' => time(), 'fingerprint' => hash('sha256', (string)$body)];
}

/**
 * The parsed map, keyed by UPPERCASED id.
 *
 * One entry per line, `ID = Phrase`, deliberately the same shape as the
 * transcriber's corrections (`Cardiff = Cardiac`) so there is one syntax to learn
 * rather than two. Blank lines and lines starting with # are ignored, so the list
 * can carry section comments.
 *
 * Keys are uppercased on both sides of the lookup because a tracker ID is
 * displayed uppercase but not always typed that way, and an entry that silently
 * fails to match is worse than no entry — the operator sees the old label and has
 * no reason to suspect the line they added is the problem.
 */
function spoken_ids_map(?string $path = null): array
{
    static $cache = [];
    $key = $path ?? '';
    if (array_key_exists($key, $cache)) return $cache[$key];

    $out = [];
    foreach (explode("\n", spoken_ids_load($path)['text']) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $id     = strtoupper(trim(substr($line, 0, $eq)));
        $phrase = trim(substr($line, $eq + 1));
        // Both halves required. "CAR =" is a half-finished edit, and treating it as
        // "expand to nothing" would blank the label rather than leave it alone.
        if ($id === '' || $phrase === '') continue;
        $out[$id] = $phrase;
    }
    return $cache[$key] = $out;
}

/**
 * The phrase for one short id, or null when there is no entry.
 *
 * Null rather than the id itself: every caller has to decide between "expand" and
 * "leave as it was", and returning the id would make those two cases identical
 * at the call site.
 */
function spoken_id_for(?string $shortId, ?string $path = null): ?string
{
    $id = strtoupper(trim((string)$shortId));
    if ($id === '') return null;
    return spoken_ids_map($path)[$id] ?? null;
}

/**
 * The same expansion applied inside a line of transcribed radio traffic, so a log
 * entry reads "Hiker One to net control" rather than "H1 to net control".
 *
 * Three rules, each of them load-bearing:
 *
 * 1. **Whole tokens only.** A bare str_replace of "CAR" rewrites the middle of
 *    "CARDIAC", "SCARED" and "CARRY". The guards are explicit lookarounds rather
 *    than \b because an id may legitimately start or end with a non-word character,
 *    and \b silently does the wrong thing there. Trailing punctuation and
 *    possessives still match, which is what you want: "H1's" is H1.
 *
 * 2. **One pass, longest id first.** Replacing in a loop lets one substitution feed
 *    the next — with "CAR = Cardiac" and "CARDIAC = Cardiac Aid" in the list, a
 *    second pass turns the first result into the second. Longest-first also stops a
 *    short id from claiming the front of a longer one.
 *
 * 3. **Radio traffic only**, enforced by the caller. Expanding text somebody typed
 *    would rewrite their words, which is not this feature's business.
 *
 * Matching is case-insensitive, which is the deliberate trade. Transcription does
 * not reliably produce a callsign in the case it was written in, so requiring a case
 * match would make this miss most of the time. The cost is that an id which is also
 * an ordinary word — "CAR" is the obvious one — will fire on that word: "the car is
 * parked" becomes "the Cardiac is parked". Ids that are unambiguous tokens (H1, INS,
 * M141) have no such problem.
 */
function spoken_ids_expand_text(string $text, ?string $path = null): string
{
    $map = spoken_ids_map($path);
    if (!$map || $text === '') return $text;

    static $reCache = [];
    $key = $path ?? '';
    if (!isset($reCache[$key])) {
        $ids = array_keys($map);
        usort($ids, fn($a, $b) => strlen($b) <=> strlen($a));
        $reCache[$key] = '/(?<![A-Za-z0-9])(' .
            implode('|', array_map(fn($i) => preg_quote($i, '/'), $ids)) .
            ')(?![A-Za-z0-9])/i';
    }

    return preg_replace_callback(
        $reCache[$key],
        fn($m) => $map[strtoupper($m[1])] ?? $m[1],
        $text
    ) ?? $text;
}

/**
 * A sender label: the expansion when there is one, the short id when there is not,
 * followed by the person's name.
 *
 * $sep is what separates the two halves. A space is right on screen; the speech
 * paths pass ", " because "From Hiker One Germain" runs the two together into one
 * name and the listener loses which is which.
 */
function spoken_label(?string $shortId, string $name, string $sep = ' ', ?string $path = null): string
{
    $short  = trim((string)$shortId);
    $name   = trim($name);
    $head   = spoken_id_for($short, $path) ?? $short;
    if ($head === '') return $name;
    if ($name === '' || strcasecmp($name, $short) === 0) return $head;
    return $head . $sep . $name;
}
