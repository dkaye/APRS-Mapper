<?php
require_once '/var/www/html/track_ip.php'; track_client_ip('transcriber');
/**
 * Transcriber Channel Manager — marsaprs.org/transcriber/
 *
 * Manages the fleet of Transcribers: which Pi listens on which frequency, with which
 * dongle, and whether it is on. Devices fetch their own slice nightly via get.php.
 * Auth shared with the admin panel (same session, same permissions).
 *
 * Endpoints (query-string routed, as the WiFi Manager does):
 *   (none)      GET  — the UI
 *   ?load       GET  — devices + channels + settings + vocabulary (tokens redacted)
 *   ?save       POST — {devices:[…], channels:[…], settings:{…}}, write the registry
 *   ?rotate     POST — {kind:'device'|'channel', id} → issue a fresh token, return it once
 *   ?vocabulary POST — re-read the assignment sheet now, return what it found
 *   ?standing   GET  — the standing vocabulary as typed, with a fingerprint
 *   ?standing   POST — {text, fingerprint}, write the standing vocabulary
 *   ?calibrate  POST — {channel} → ask that channel to measure its gain and squelch
 *   ?status     GET  — per-device check-in, and per-channel calibration state
 *   ?logout     GET  — end the session
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */
ini_set('display_errors', '0');

require_once '/var/www/html/auth/auth.php';
require_once __DIR__ . '/store.php';
// The tracker-ID name list. It lives in map/ beside messaging_db.php, which is the
// code that consumes it — this page is only where it is edited. Absolute path for
// the same reason the requires above it are absolute: this file runs from the web
// root, not from the repo layout.
require_once '/var/www/html/spoken_ids.php';

function jsonOut($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/* ── Auth ──────────────────────────────────────────────────────────────────── */

if (isset($_GET['logout'])) { destroy_session(); header('Location: /auth/login.php'); exit; }

// Seeing which frequencies are covered is a device-monitoring question; changing them
// is device administration. Same split the NetBird pages use.
if (!has_permission('netbird.admin') && !has_permission('netbird.view')) {
    require_permission('netbird.view');   // redirects or 403s
}
$canEdit = has_permission('netbird.admin');

/* ── API ───────────────────────────────────────────────────────────────────── */

/** Tokens never leave the server. The UI shows whether one is set, not what it is —
 *  a channel token writes to the event log and a device token fetches config, and
 *  neither has any business being in a browser it is not being issued to. */
function redact(array $data): array {
    foreach (['devices', 'channels'] as $group) {
        foreach ($data[$group] as &$row) {
            $row['has_token'] = !empty($row['token']);
            unset($row['token']);
        }
        unset($row);
    }
    return $data;
}

if (isset($_GET['load'])) {
    // A fingerprint of exactly what this page is being given. It comes back with the
    // save, and a mismatch means the registry moved while the page sat open — which is
    // not hypothetical: a page open from before lunch silently reverted a device rename
    // and a squelch override the moment somebody changed an unrelated field.
    $baseline = transcriber_fingerprint();
    $data = redact(transcriber_load());
    $data['baseline'] = $baseline;
    // The UI works in MHz throughout; Hz is storage, and nobody reads a frequency
    // that way. Sent alongside rather than instead, so the page never has to convert.
    foreach ($data['channels'] as &$c) {
        $c['mhz'] = transcriber_mhz($c['frequency'] ?? 0);
    }
    unset($c);
    // Given an explicit shape rather than passed through. An empty PHP array encodes as
    // [] and not {}, and a JSON array that the page then hangs a property on loses it
    // silently on the way back — the sheet URL would simply never save.
    $data['settings'] = [
        'sheet_url'        => (string)($data['settings']['sheet_url'] ?? ''),
        'vocabulary_extra' => (string)($data['settings']['vocabulary_extra'] ?? ''),
    ];
    // The lists as they stand, with when and whether the last read worked, and what is
    // actually in force once the supplement box is folded in. Not refetched here — opening
    // a page is not a reason to make somebody wait on Google, and the device poll keeps
    // this within a quarter of an hour on its own. The button is there for the case that
    // matters, which is a sheet edited two minutes ago.
    $data['vocabulary'] = transcriber_vocabulary_report();
    // What each channel was last measured at, and when — including the channels that have
    // never been measured at all, which are simply absent. Loaded here as well as polled
    // from ?status so the page can say "never calibrated" the moment it opens, rather than
    // showing nothing until somebody presses something.
    $data['calibration'] = transcriber_calibration_load();
    jsonOut($data);
}

if (isset($_GET['save']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body['devices'] ?? null) || !is_array($body['channels'] ?? null)) {
        jsonOut(['error' => 'Invalid request body'], 400);
    }

    // Refuse rather than overwrite. Blank baseline means a page from before this check
    // existed; those are let through, because refusing every open tab once on upgrade is
    // its own kind of broken.
    $sent = (string)($body['baseline'] ?? '');
    if ($sent !== '' && $sent !== transcriber_fingerprint()) {
        jsonOut(['error' => 'Someone else changed this since you loaded the page — reload and redo your edit'], 409);
    }

    // The browser never sees tokens, so it cannot send them back. Carry the existing
    // ones across by key; anything new gets one issued here.
    $old = transcriber_load();
    $devTokens = [];
    foreach ($old['devices'] as $d) if (!empty($d['host'])) $devTokens[$d['host']] = $d['token'] ?? '';
    $chTokens = [];
    foreach ($old['channels'] as $c) if (!empty($c['id'])) $chTokens[$c['id']] = $c['token'] ?? '';
    // The whole previous channel, by id. The page posts back only the fields it draws,
    // so anything the registry holds that this UI does not offer a control for was
    // being erased by an unrelated save — silently, because nothing on the page ever
    // mentioned it.
    //
    // That cost a real event: `tone_filter` was set to "drop" on 2026-08-20, then wiped
    // when a channel was added for the Double Dipsea on 2026-08-22, and the beep filter
    // spent the day in observe mode. `record_until` and a hand-set `gain` have exactly
    // the same exposure.
    //
    // Carrying the old row forward and overwriting the managed keys is the general fix:
    // a key this page does not know about survives a save through it, which is the only
    // behaviour that stays correct as fields are added to the registry and not here.
    $chPrev = [];
    foreach ($old['channels'] as $c) if (!empty($c['id'])) $chPrev[$c['id']] = $c;

    $devices = [];
    foreach ($body['devices'] as $d) {
        $host = substr(trim($d['host'] ?? ''), 0, 64);
        if ($host === '') continue;
        $devices[] = [
            'host'  => $host,
            'token' => $devTokens[$host] ?? transcriber_token(),
        ];
    }

    $channels = [];
    $seen = [];
    foreach ($body['channels'] as $c) {
        $device = substr(trim($c['device'] ?? ''), 0, 64);
        $hz     = transcriber_hz($c['frequency'] ?? '');
        // Derived, not typed. The id names the systemd unit and identifies the author
        // of every entry, but it is fully determined by which receiver is on which
        // frequency — so asking for it was asking the operator to invent a value whose
        // rules ("must be unique", "no @, it is systemd's instance separator") only
        // make sense if you know how the device is built.
        $id = transcriber_channel_id($device, $hz);
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        // Stored row first, managed keys second: array_merge lets the later array win,
        // so every field this page draws is taken from the form and everything else is
        // carried across untouched.
        $channels[] = array_merge($chPrev[$id] ?? [], [
            'id'        => $id,
            'device'    => $device,
            'label'     => substr(trim($c['label'] ?? ''), 0, 40) ?: $id,
            'frequency' => $hz,
            'serial'    => substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($c['serial'] ?? '')), 0, 32),
            'squelch'   => max(0, min(1000, (int)($c['squelch'] ?? 0))),
            'model'     => in_array($c['model'] ?? '', ['ggml-tiny.en.bin', 'ggml-base.en.bin'], true)
                           ? $c['model'] : 'ggml-tiny.en.bin',
            'enabled'   => !empty($c['enabled']),
            'send_audio' => !empty($c['send_audio']),
            'token'     => $chTokens[$id] ?? transcriber_token(),
        ]);
    }

    // Stored as typed, not as derived. The export URL is reconstructed on every use, so
    // the field can be shown back exactly as it was pasted — an operator checking that
    // the manager has the right document wants to recognize their own link, not a
    // rewritten one they have never seen.
    $sheet = substr(trim((string)($body['settings']['sheet_url'] ?? '')), 0, 300);
    // The supplement box, stored as typed. It is read by the same parser as the sheet's
    // own section and merged with it on the way out, so a term typed here is in force at
    // the devices' next poll without anything being fetched from Google — which is the
    // entire point of it, because the case it exists for is a document that is either
    // unreachable or not yours to edit while the event is running.
    // 20000 rather than the 4000 it was. A byte cap here truncates silently — there is no
    // sensible place on the page to say "your last line was cut in half" — so it must never
    // be the limit anybody actually reaches. The limit that binds is the term ceiling, which
    // is counted and reported; at 20000 bytes this holds well over that many lines.
    $extra = substr((string)($body['settings']['vocabulary_extra'] ?? ''), 0, 20000);
    $settings = ['sheet_url' => $sheet, 'vocabulary_extra' => $extra];
    $changed  = $sheet !== (string)($old['settings']['sheet_url'] ?? '')
             || $extra !== (string)($old['settings']['vocabulary_extra'] ?? '');

    transcriber_save(['devices' => $devices, 'channels' => $channels, 'settings' => $settings]);
    jsonOut(['ok' => true, 'devices' => count($devices), 'channels' => count($channels),
             'vocabulary_changed' => $changed]);
}

// Polled by the page while it waits for the fleet to check in, or for one channel to
// finish measuring itself. Deliberately cheap: three small files and a hash per device,
// no writes.
//
// `now` is the server's clock and the page does its arithmetic against it rather than
// against the browser's. A calibration countdown runs from a timestamp a device reported,
// and a laptop two minutes out would otherwise show a measurement finishing before it
// started.
if (isset($_GET['status'])) {
    jsonOut(['now'         => time(),
             'devices'     => transcriber_device_status(),
             'calibration' => transcriber_calibration_load()]);
}

// The calibration meter's feed. Polled about once a second while somebody is setting a
// radio's level by hand, so it is the cheapest thing here: one small file, no writes.
// `now` travels with it so the page can judge staleness against the server's clock.
if (isset($_GET['level'])) {
    jsonOut(['now' => time(), 'levels' => transcriber_level_load()]);
}

if (isset($_GET['update']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    jsonOut(['ok' => true, 'requested' => transcriber_request_update()]);
}

// Recalibrate one channel: measure the tuner gain for the site it is on, and then the
// squelch at that gain. Per channel rather than per device or fleet-wide, because it
// takes that one channel off the air for a couple of minutes and its neighbour on the
// same Pi has no reason to stop listening.
//
// The channel has to exist here and not only on the device. A request for one that does
// not is a request no device will ever answer, and the page would count down to nothing.
if (isset($_GET['calibrate']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $channel = trim((string)($body['channel'] ?? ''));
    if (transcriber_channel_device($channel) === '') jsonOut(['error' => 'Not found'], 404);
    jsonOut(['ok' => true, 'requested' => transcriber_request_calibration($channel)]);
}

// Re-read the assignment sheet now. Unconditional — this is the button somebody presses
// after editing the sheet at the briefing, and a cache that said "checked four minutes
// ago, come back later" would be answering a question nobody asked.
if (isset($_GET['vocabulary']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    transcriber_vocabulary_refresh();
    jsonOut(transcriber_vocabulary_report());
}

// The standing vocabulary: one list, shared by every event, edited from its own button.
//
// Its own endpoint and its own file rather than a field in the registry, and that is not
// tidiness. A write through ?save would move the registry's fingerprint, and the page that
// just made the edit would be refused its own next Save as a stale write — a page that
// breaks itself for a reason nobody can see. Same reasoning as the calibration and
// vocabulary files; see the comment in store.php.
if (isset($_GET['standing'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
        $body = json_decode(file_get_contents('php://input'), true);
        $sent = (string)($body['fingerprint'] ?? '');
        // The registry's guard, applied to this file for the same reason. This is the long
        // list and it is edited slowly, so two people with the editor open is exactly the
        // case where one silently loses everything they typed. A blank fingerprint is a
        // page from before this check existed and is let through.
        if ($sent !== '' && $sent !== transcriber_standing_load()['fingerprint']) {
            jsonOut(['error' => 'Someone else changed the standing list since you opened it'
                              . ' — close the editor, reopen it and redo your edit'], 409);
        }
        $saved = transcriber_standing_save((string)($body['text'] ?? ''));
        // The whole vocabulary report comes back with it, so the page shows what is now in
        // force — including anything the ceiling discarded — without a second request.
        jsonOut($saved + ['ok' => true, 'vocabulary' => transcriber_vocabulary_report()]);
    }
    jsonOut(transcriber_standing_load());
}

// The tracker-ID name list. Its own endpoint and its own file, for the same reason
// the standing vocabulary has them: a write through ?save would move the registry's
// fingerprint and the page that just saved would be refused its own next Save.
if (isset($_GET['ids'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
        $body = json_decode(file_get_contents('php://input'), true);
        $sent = (string)($body['fingerprint'] ?? '');
        if ($sent !== '' && $sent !== spoken_ids_load()['fingerprint']) {
            jsonOut(['error' => 'Someone else changed the ID list since you opened it'
                              . ' — reload the page and redo your edit'], 409);
        }
        $saved = spoken_ids_save((string)($body['text'] ?? ''));
        // The parsed map comes back with it, so the page can show what actually took
        // effect rather than what was typed — a line missing its "=" simply vanishes,
        // and seeing that immediately is the difference between a typo and a mystery.
        jsonOut($saved + ['ok' => true, 'map' => spoken_ids_map()]);
    }
    jsonOut(spoken_ids_load() + ['map' => spoken_ids_map()]);
}

if (isset($_GET['rotate']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $kind = $body['kind'] ?? '';
    $key  = trim($body['id'] ?? '');
    $data = transcriber_load();
    $token = transcriber_token();
    $hit = false;
    if ($kind === 'device') {
        foreach ($data['devices'] as &$d) {
            if (($d['host'] ?? '') === $key) { $d['token'] = $token; $hit = true; }
        }
        unset($d);
    } elseif ($kind === 'channel') {
        foreach ($data['channels'] as &$c) {
            if (($c['id'] ?? '') === $key) { $c['token'] = $token; $hit = true; }
        }
        unset($c);
    }
    if (!$hit) jsonOut(['error' => 'Not found'], 404);
    transcriber_save($data);
    // Returned once, at the moment it is issued. It is never readable again — ?load
    // redacts it — so it has to be copied to the device now.
    jsonOut(['ok' => true, 'token' => $token]);
}

/* ── UI ────────────────────────────────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transcriber Channels</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
       background: #f3f4f6; color: #111827; font-size: 15px; min-height: 100vh; }
header { background: #fff; padding: 10px 20px; display: flex; align-items: center; gap: 12px;
         flex-wrap: wrap; border-bottom: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,.06);
         position: sticky; top: 0; z-index: 20; }
header h1 { font-size: 17px; font-weight: 700; margin-right: auto; white-space: nowrap; }
#status { font-size: 13px; color: #9ca3af; }
#status.saving { color: #2563eb; } #status.saved { color: #16a34a; } #status.error { color: #dc2626; }
.hdr-btn { background: #f9fafb; border: 1px solid #d1d5db; color: #374151; padding: 5px 14px;
           border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit;
           white-space: nowrap; text-decoration: none; display: inline-block; }
.hdr-btn:hover { background: #e5e7eb; }
.hdr-btn-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.hdr-btn-primary:hover { background: #1d4ed8; }
main { padding: 16px 20px; max-width: 1200px; }
h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280;
     margin: 18px 0 8px; }
.hint { font-size: 13px; color: #6b7280; margin-bottom: 10px; line-height: 1.5; }
.table-wrap { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
thead th { font-size: 12px; font-weight: 600; color: #9ca3af; text-align: left; padding: 9px 10px;
           text-transform: uppercase; letter-spacing: .05em; background: #f9fafb;
           border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f3f4f6; }
tbody tr:last-child { border-bottom: none; }
td { padding: 7px 10px; vertical-align: middle; }
input[type=text], input[type=number], select {
    width: 100%; min-width: 80px; padding: 5px 7px; border: 1px solid #d1d5db; border-radius: 5px;
    font-family: inherit; font-size: 14px; background: #fff; }
input:focus, select:focus { outline: 2px solid #2563eb; outline-offset: -1px; border-color: #2563eb; }
.ro { color: #374151; }                  /* read-only: plain text, never an empty box */
.tok { font-size: 12px; white-space: nowrap; }
.tok.set { color: #16a34a; } .tok.unset { color: #dc2626; font-weight: 600; }
/* Never calibrated is colored like the thing it is: not an error, but a receiver running
   numbers measured on somebody else's hill, which nobody would otherwise think to look
   for. Same amber as a missing vocabulary section, for the same reason. */
/* The banner, not the column. A channel running on built-in numbers already says
   "Never" in its own row, in amber, and on 2026-08-22 that was not enough: a channel
   added the night before an event ran all day on defaults measured for nowhere, the
   squelch never gated, and eight hours of a net produced twenty-five entries of which
   most read "the the the you." The row said so; nobody was looking at the row.
   This says it once, at the top, in the words that describe the consequence. */
.cal { font-size: 12px; white-space: nowrap; }
.cal.never { color: #b45309; font-weight: 600; }
.cal.busy { color: #2563eb; }
.cal.bad { color: #dc2626; font-weight: 600; }
.vu-wrap { margin: 10px 0 22px; }
.vu-wrap select { margin-left: 8px; }
.vu { margin-top: 12px; max-width: 640px; }
.vu-track { position: relative; height: 26px; background: #f1f5f9; border: 1px solid #cbd5e1;
            border-radius: 4px; overflow: hidden; }
/* The acceptable window, drawn once: -38 to -20 dBFS on a -70..0 scale. */
.vu-band { position: absolute; top: 0; bottom: 0; left: 45.7%; width: 25.7%;
           background: #dcfce7; border-left: 1px solid #86efac; border-right: 1px solid #86efac; }
.vu-bar { position: absolute; top: 0; bottom: 0; left: 0; width: 0; background: #64748b;
          opacity: .85; transition: width .15s linear; }
.vu-bar.vu-ok  { background: #16a34a; }
.vu-bar.vu-mid { background: #ca8a04; }
.vu-bar.vu-hot { background: #dc2626; }
.vu-bar.vu-low { background: #2563eb; }
.vu-peak { position: absolute; top: 0; bottom: 0; width: 2px; background: #0f172a; display: none; }
.vu-read { margin-top: 6px; font-family: ui-monospace, Menlo, monospace; font-size: 13px; }
.vu-read .vu-ok  { color: #16a34a; font-weight: 600; }
.vu-read .vu-mid { color: #ca8a04; }
.vu-read .vu-hot { color: #dc2626; font-weight: 600; }
.vu-read .vu-low { color: #2563eb; }
.vu-read .vu-stale { color: #94a3b8; }
.derived { font-size: 11px; color: #9ca3af; margin-top: 2px; font-family: ui-monospace, monospace; }
.panel { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 14px;
         font-size: 13px; line-height: 1.55; margin-bottom: 6px; }
.panel p { margin-top: 6px; color: #374151; }
table.explain { border-collapse: collapse; font-size: 13px; margin-bottom: 12px; max-width: 900px; }
table.explain th { text-align: left; vertical-align: top; padding: 4px 12px 4px 0; white-space: nowrap;
                   color: #111827; font-weight: 600; }
table.explain td { vertical-align: top; padding: 4px 0; color: #4b5563; line-height: 1.5; }
.row-btn { background: none; border: none; cursor: pointer; font-size: 13px; color: #6b7280;
           padding: 3px 6px; border-radius: 4px; font-family: inherit; }
.row-btn:hover { background: #f3f4f6; color: #111827; }
.row-btn.danger:hover { background: #fee2e2; color: #b91c1c; }
.empty { padding: 22px; text-align: center; color: #9ca3af; font-size: 14px; }
#spinner { display: inline-block; width: 12px; height: 12px; margin-right: 7px;
           border: 2px solid #bfdbfe; border-top-color: #2563eb; border-radius: 50%;
           animation: spin .7s linear infinite; vertical-align: -1px; }
#spinner[hidden] { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }
#notice { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none;
          align-items: center; justify-content: center; z-index: 60; }
#notice.open { display: flex; }
#notice .card { background: #fff; border-radius: 10px; padding: 22px 26px; max-width: 460px; }
#tokenbox { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none;
            align-items: center; justify-content: center; z-index: 50; }
#tokenbox.open { display: flex; }
#tokenbox .card { background: #fff; border-radius: 10px; padding: 22px; max-width: 540px; }
#tokenbox code { display: block; background: #f3f4f6; padding: 10px; border-radius: 6px;
                 font-size: 13px; word-break: break-all; margin: 12px 0; }
.box { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; }
.vocab-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.vocab-row input { flex: 1; min-width: 260px; }
.vocab-group { margin-top: 12px; }
.vocab-group strong { font-size: 12px; font-weight: 600; text-transform: uppercase;
                      letter-spacing: .05em; color: #9ca3af; }
.words { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.words span { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;
              padding: 2px 6px; font-size: 12px; font-family: ui-monospace, monospace; }
#vocab-meta { margin: 10px 0 0; }
#vocab-meta.error { color: #dc2626; }
/* The section report is not decoration. If the heading is renamed or the section is lost
   in an edit, the terms silently become none and the first anybody knows is a log full of
   "Windy Cap" — so "not found" is colored like the problem it is. */
#vocab-section { margin: 6px 0 0; font-weight: 600; }
#vocab-section.missing { color: #b45309; }
#vocab-extra { width: 100%; box-sizing: border-box; resize: vertical;
               font-family: ui-monospace, monospace; font-size: 13px; }
#vocab-extra-meta { margin: 8px 0 0; }
/* Anything the ceiling threw away, in the color of the thing it is. A truncated list is
   not an error the page recovered from — it is words the receivers were never given, and
   the last time it happened the only symptom was that the list looked short. */
#vocab-dropped { margin: 8px 0 0; font-weight: 600; color: #b91c1c; }
#standing-meta { margin: 8px 0 0; }
/* The standing list is long, so its editor is a wide modal rather than a box in the flow
   of the page. Same shape as #tokenbox — same overlay, same card — with room to read fifty
   lines at once and its own scroll, because the help above it is the point. */
#standingbox { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none;
               align-items: center; justify-content: center; z-index: 55; padding: 16px; }
#standingbox.open { display: flex; }
#standingbox .card { background: #fff; border-radius: 10px; padding: 22px;
                     width: min(780px, 100%); max-height: 92vh; overflow-y: auto; }
#standing-text { width: 100%; box-sizing: border-box; resize: vertical;
                 font-family: ui-monospace, monospace; font-size: 13px; margin-top: 10px; }
#standing-status { font-size: 13px; color: #6b7280; }
#standing-status.error { color: #dc2626; }
.sample { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;
          padding: 10px 12px; font-size: 13px; line-height: 1.5; overflow-x: auto;
          font-family: ui-monospace, monospace; }
</style>
</head>
<body>
<header>
  <h1>Transcriber Channels</h1>
  <span id="spinner" hidden></span><span id="status"></span>
  <?php if ($canEdit): ?>
    <button class="hdr-btn hdr-btn-primary" id="save-btn" onclick="save()" disabled>Save</button>
    <button class="hdr-btn" id="update-btn" onclick="requestUpdate()">Update devices</button>
  <?php endif; ?>
  <a class="hdr-btn" href="/netbird/">Devices</a>
  <a class="hdr-btn" href="?logout">Sign out</a>
</header>

<main>
  <div class="panel">
    <strong>Saving, and the calibration meter</strong>
    <p>Nothing is written until you press <strong>Save</strong>. The Pi then collects its
       settings <strong>within 60 seconds</strong>. Switching a channel <strong>On</strong>
       or off takes effect at once, because the server stops accepting from it without
       waiting for the device to notice.</p>
    <p><strong>Update devices</strong> is for software rather than settings: it asks the
       Transcriber to pull a new worker at its next check instead of waiting for the
       nightly run at 4:11&nbsp;am.</p>
    <p>This page does <strong>not</strong> refresh by itself. If anything changed since you
       loaded, Save is refused and asks you to reload rather than quietly reverting
       somebody else&rsquo;s work.</p>
  </div>

  <h2>Audio level</h2>
  <p class="hint">For a receiver whose own squelch gates the audio. <strong>Open the
     radio&rsquo;s squelch</strong>, press <strong>Start meter</strong>, and turn the
     radio&rsquo;s volume until the bar sits in the green band. Then close the squelch.
     Calibrate again whenever the radio moves or changes frequency &mdash; nothing here is
     saved, because a level measured at one site says nothing about the next.</p>
  <table class="explain">
    <tr><th>Why noise</th><td>Open-squelch noise is the reference because it is
        <em>stationary</em> &mdash; about a decibel of spread over a minute &mdash; and
        available on demand. Speech varies 20&nbsp;dB inside a syllable and only arrives
        when somebody talks.</td></tr>
    <tr><th>Why the band</th><td>The reading is the <strong>audio band, 200&ndash;4000
        Hz</strong>, not the raw level. A squelch thump is generated after the volume
        control, so no knob can move it &mdash; levelling against the raw peak drove a
        radio&rsquo;s volume to zero on 2026-08-29 and left ten seconds of test speech with
        no trace in the recording.</td></tr>
    <tr><th>The marks</th><td><strong>&minus;27&nbsp;dBFS</strong> is the target; below
        &minus;38 wastes resolution against the noise floor, above &minus;20 clips on loud
        traffic. Tones are the loud case, not voice &mdash; a repeater&rsquo;s Morse ID runs
        hotter than anybody talking.</td></tr>
  </table>
  <div class="vu-wrap">
    <button class="row-btn" id="vu-btn" onclick="toggleMeter()">Start meter</button>
    <select id="vu-channel"></select>
    <span id="vu-note" class="hint"></span>
    <div class="vu">
      <div class="vu-track">
        <div class="vu-band"></div>
        <div class="vu-bar" id="vu-bar"></div>
        <div class="vu-peak" id="vu-peak"></div>
      </div>
      <div class="vu-read"><span id="vu-db">&mdash;</span> <span id="vu-tag"></span></div>
    </div>
  </div>

  <h2>This receiver</h2>
  <p class="hint">The settings the Pi collects. One receiver, one frequency &mdash; this is
     the current setup, not a list of saved ones.</p>
  <p class="hint">The <strong>identity</strong> below looks like a hostname with a frequency
     stuck on the end because that is exactly what it is: the id was designed when several
     Pis each ran several dongles, and it had to say which machine and which frequency. That
     no longer applies, but the string is still the name of the running service and the
     author of every log entry, so it cannot be changed without renaming both.</p>
  <div id="receiver"></div>
  <div class="empty" id="receiver-empty">No receiver configured.</div>

  <h2>Event vocabulary</h2>
  <p class="hint">The event's <strong>radio assignment sheet</strong> in Google Docs. The
     callsigns and tactical calls on it are read out of the document and given to every
     channel as a hint, because they are exactly the words transcription gets wrong:
     a callsign is letters and digits with no language behind it, and
     <code>K6DRK</code> comes back as <em>K6 dark</em> often enough to make the log
     tedious to read. Paste the ordinary <code>/edit</code> link — the document must be
     shared as <em>Anyone with the link can view</em>, which yours already is if the team
     can read it.<br>
     Only callsigns, tactical calls and the vocabulary section below are taken. Names,
     shift times and phone numbers on the sheet are not read and are never stored. The
     sheet is re-read every quarter of an hour by itself; press <strong>Read sheet
     now</strong> if you have just edited it.</p>
  <div class="box">
    <div class="vocab-row">
      <?php if ($canEdit): ?>
        <input type="text" id="sheet-url" placeholder="https://docs.google.com/document/d/…/edit"
               oninput="data.settings.sheet_url = this.value; touch()">
        <button class="hdr-btn" id="vocab-btn" onclick="refreshVocabulary()">Read sheet now</button>
      <?php else: ?>
        <span class="ro" id="sheet-url-ro"></span>
      <?php endif; ?>
    </div>
    <p class="hint" id="vocab-meta"></p>
    <p class="hint" id="vocab-section"></p>
    <div id="vocab-lists"></div>
    <!-- Anything the ceiling discarded, under the list it was discarded from. This is the
         words the receivers were never given, so it belongs beside the words they were. -->
    <p class="hint" id="vocab-dropped"></p>
  </div>

  <h2>Place names and corrections</h2>
  <p class="hint">Callsigns and tactical calls are found on the sheet by their shape.
     <strong>Place names are not</strong>: <em>Windy Gap</em>, <em>Cardiac</em>,
     <em>Bootjack</em>, <em>Pantoll</em> and <em>Stinson Beach</em> are ordinary words in
     an ordinary order, and nothing that could pick them out of the document would leave
     the rest of it alone. So the sheet has to say them. Ask whoever keeps it to add a
     heading with <strong>Vocabulary</strong> in it and then one term per line, ending at a
     blank line:</p>
  <pre class="sample">Transcriber Vocabulary
Windy Gap
Cardiac
Bootjack
Pantoll
Stinson Beach
Cardiac Hill = Cardiac</pre>
  <p class="hint">The last line is a <strong>correction</strong>: what the transcription
     produced on the left, what it should have said on the right. Use one only for a
     mishearing somebody has actually heard — a correction is obeyed exactly, so it fixes
     the phrase it names and nothing else.</p>
  <p class="hint">The box below is the same thing, typed here instead of in the document.
     It is not the main way to do this — a term belongs on the sheet, where the whole team
     can see it. It is for the middle of an event, when <em>Cardiac</em> is coming out as
     <em>Cardiff</em> in the log and the shared document is not yours to edit right then.
     What you type here is added to what the sheet gave, and takes effect at each Pi's next
     check after you press Save.</p>
  <div class="box">
    <?php if ($canEdit): ?>
      <textarea id="vocab-extra" rows="5" spellcheck="false"
                placeholder="Pantoll&#10;Cardiff = Cardiac"
                oninput="data.settings.vocabulary_extra = this.value; touch()"></textarea>
    <?php else: ?>
      <pre class="sample" id="vocab-extra-ro"></pre>
    <?php endif; ?>
    <p class="hint" id="vocab-extra-meta"></p>
  </div>

  <h2>Tracker ID names</h2>
  <p class="hint">What a tracker's <strong>ID</strong> is called when it is written out or
     read aloud. An ID is short because it has to fit on a map marker and stay readable
     across a room &mdash; <code>CAR</code>, <code>H1</code>, <code>INS</code> &mdash; and that
     is exactly what makes it wrong everywhere else. In the Messages panel
     <em>Cardiac Stanton</em> tells you who is talking and <em>CAR Stanton</em> does not, and
     a speech engine reads <code>H1</code> as two characters rather than as a station.</p>
  <p class="hint">One per line, <strong>ID = what to call it</strong>. The same shape as a
     correction above, so there is one syntax rather than two. Lines starting with
     <code>#</code> are ignored, so the list can be grouped with headings.</p>
  <pre class="sample">H1 = Hiker One
INS = Insult
CAR = Cardiac</pre>
  <p class="hint">With those set, a message from <strong>H1 Germain</strong> is shown as
     <em>Hiker One Germain</em> and announced as <em>&ldquo;From Hiker One, Germain.&rdquo;</em>
     An ID with no line here is left exactly as it is, so the list only needs the ones worth
     expanding. This list is shared by every event, because an aid station keeps its name from
     one year to the next.</p>
  <p class="hint">It also applies <strong>inside transcribed radio traffic</strong>: a line
     heard as <em>&ldquo;H1 to net control&rdquo;</em> is logged as <em>&ldquo;Hiker One to net
     control&rdquo;</em>. Whole words only, so <code>CAR</code> does not rewrite the middle of
     <em>CARDIAC</em> or <em>SCARED</em>, and only radio traffic is touched &mdash; what an
     operator typed is never altered.</p>
  <p class="hint">Matching ignores case, so an ID that is also an ordinary word will fire on
     that word too: with <code>CAR = Cardiac</code>, <em>&ldquo;the car is parked&rdquo;</em>
     becomes <em>&ldquo;the Cardiac is parked&rdquo;</em>. IDs like <code>H1</code> or
     <code>INS</code> have no such problem. If it becomes annoying, remove that one line —
     the Messages panel label still expands either way.</p>
  <p class="hint"><strong>Map markers are deliberately not changed.</strong> They keep the
     short ID, which is the reason the field is short.</p>
  <div class="box">
    <?php if ($canEdit): ?>
      <textarea id="ids-text" rows="8" spellcheck="false"
                placeholder="H1 = Hiker One&#10;CAR = Cardiac"></textarea>
      <div class="vocab-row" style="margin-top:8px">
        <button class="hdr-btn hdr-btn-primary" id="ids-save" onclick="saveIds()">Save ID names</button>
        <span class="hint" id="ids-meta"></span>
      </div>
    <?php else: ?>
      <pre class="sample" id="ids-text-ro"></pre>
      <p class="hint" id="ids-meta"></p>
    <?php endif; ?>
  </div>

  <h2>Standing vocabulary</h2>
  <p class="hint">One list, shared by <strong>every</strong> event. Most of what a net says
     does not change from one event to the next — the procedural words, the amateur-radio
     terms, and the place names of the region all of these events happen in — and putting
     them here means nobody retypes them into each new sheet.
     <br>An event's own sheet and the box above still win on the same term, so a standing
     entry never gets in the way of what today's sheet says.</p>
  <div class="box">
    <?php if ($canEdit): ?>
      <button class="hdr-btn" id="standing-btn" onclick="openStanding()">Edit standing list…</button>
    <?php endif; ?>
    <p class="hint" id="standing-meta"></p>
  </div>
</main>

<div id="standingbox"><div class="card">
  <strong>Standing vocabulary</strong>
  <p class="hint" style="margin-top:8px">Words this transcriber should expect to hear at
     <em>every</em> event. The event's own sheet and the box on the page add to this one, so
     nothing here needs repeating in them.</p>

  <p class="hint"><strong>What a line does.</strong> Each line is a target. When a
     transcription comes back close to it, it is rewritten to exactly what you typed — so
     <em>pan toll</em> becomes <em>Pantoll</em>. A line nothing ever comes close to costs
     nothing.</p>

  <p class="hint"><strong>Capitalization: how you type it is how the log reads.</strong>
     Matching ignores case completely — <em>net control</em>, <em>Net Control</em> and
     <em>NET CONTROL</em> are all recognized whichever you write. But the log then copies
     your spelling exactly, so write it the way you want to read it back. Type
     <em>FInish</em> and every entry will say <em>FInish</em>.</p>

  <p class="hint"><strong>Long and distinctive is free. Short and ordinary is expensive.</strong>
     <em>Sequoia Valley Road</em> and <em>Pantoll</em> cost nothing — nothing else sounds like
     them, so they either match or they do not. A short or everyday word is matched far more
     loosely than it looks: <em>ARES</em> is close enough to <em>are</em> that "there are
     several areas" comes back as "there ARES several ARES", and <em>Cardiac</em> turns
     "cardiac arrest" into "Cardiac arrest" at every event thereafter.
     <strong>If it is a word you would use in an ordinary sentence, leave it out</strong> — or
     put it on the one sheet that actually needs it.</p>

  <p class="hint"><strong>Corrections.</strong> Write <code>heard = written</code> when you have
     watched a particular mishearing happen — <code>Insult Hill = White Gate</code>. The left
     side is what the transcription produced, and its capitalization is ignored; the right side
     is what the log will say. A correction is obeyed exactly and never loosely, so a guess
     here is wrong at every event rather than at one.</p>

  <p class="hint"><strong>If two lists disagree</strong>, the more specific wins: the box on the
     page beats the event's sheet, and the sheet beats this list.</p>


  <textarea id="standing-text" rows="18" spellcheck="false"></textarea>
  <div style="margin-top:14px;display:flex;align-items:center;gap:12px">
    <span id="standing-status"></span>
    <span style="margin-left:auto"></span>
    <button class="hdr-btn" onclick="closeStanding()">Cancel</button>
    <button class="hdr-btn hdr-btn-primary" id="standing-save" onclick="saveStanding()">Save standing list</button>
  </div>
</div></div>

<div id="notice">
  <div class="card">
    <strong id="notice-title"></strong>
    <p id="notice-body" style="margin:12px 0 0;line-height:1.55;color:#374151"></p>
    <div style="margin-top:18px;text-align:right">
      <button class="hdr-btn hdr-btn-primary" onclick="document.getElementById('notice').classList.remove('open')">OK</button>
    </div>
  </div>
</div>

<div id="tokenbox"><div class="card">
  <strong id="tok-title">New token</strong>
  <p class="hint">Copy it now — it is never shown again.</p>
  <code id="tok-value"></code>
  <button class="hdr-btn hdr-btn-primary" onclick="document.getElementById('tokenbox').classList.remove('open')">Done</button>
</div></div>

<script>
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
const MODELS = [{file: 'ggml-tiny.en.bin', name: 'Fast'},
                {file: 'ggml-base.en.bin', name: 'Careful'}];
let data = {devices: [], channels: [], settings: {sheet_url: '', vocabulary_extra: ''},
            vocabulary: {}, calibration: {}};

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function status(text, cls) { const s = $('status'); s.textContent = text; s.className = cls || ''; }

/* Wait for the fleet to come and collect, and say so while it happens.
 *
 * Devices poll once a minute and nothing can reach into a Pi behind NAT, so a save is
 * not in effect when it returns. A modal saying "up to 60 seconds" was a promise; this
 * is the thing itself — every Transcriber reports when it fetches, and the page waits
 * for that rather than for a clock.
 *
 * `ready(d)` decides what counts, because the two cases differ: after a Save a device is
 * done when what it holds matches what it should hold, and after an update request it is
 * done when it has checked in at all since the request.
 *
 * The timeout has to be longer than the poll interval or it would fire on a device that
 * is simply forty seconds into its minute. 75s gives a full cycle plus room for a slow
 * link; past that, the honest answer is that the device is not answering.
 */
let waitTimer = null;
const WAIT_SECONDS = 75;

async function waitForDevices(ready, what) {
    clearInterval(waitTimer);
    let left = WAIT_SECONDS;
    let waiting = [];
    let checking = false;

    // The countdown ticks every second so it reads as a clock rather than a stalled
    // number; the server is only asked every other one, because nothing about the answer
    // changes faster than a device can fetch.
    const show = () => status(
        `${what} pending  :${left}` + (waiting.length ? ' — waiting for ' + waiting.join(', ') : ''),
        'saving');

    const done = (text, cls) => {
        clearInterval(waitTimer);
        $('spinner').hidden = true;
        status(text, cls);
    };

    const check = async () => {
        if (checking) return;
        checking = true;
        try {
            const d = await (await fetch('?status')).json();
            const not = (d.devices || []).filter(x => !ready(x, d.now));
            waiting = not.map(x => x.host);
            if (!not.length) done(what + ' applied', 'saved');
        } catch (e) {
            // A blip in the page's own connection is not the devices failing to answer.
            // Say nothing and try again next tick; the countdown still runs out.
        } finally { checking = false; }
    };

    $('spinner').hidden = false;
    show();
    await check();

    waitTimer = setInterval(() => {
        if (--left <= 0) {
            done('No response from ' + (waiting.join(', ') || 'the devices'), 'error');
            notice(what + ' not confirmed',
                   'These Transcribers have not checked in: '
                 + (waiting.join(', ') || 'none reported') + '.\n\n'
                 + 'The change is saved and they will collect it as soon as they are back. '
                 + 'A device that is switched off, off the network, or has the wrong '
                 + 'config token will look exactly like this.');
            return;
        }
        show();
        if (left % 2 === 0) check();
    }, 1000);
}

function notice(title, body) {
    $('notice-title').textContent = title;
    $('notice-body').textContent = body;
    $('notice').classList.add('open');
}

async function load() {
    const r = await fetch('?load');
    data = await r.json();
    baseline = data.baseline || '';
    dirty = false;
    const b = $('save-btn');
    if (b) b.disabled = true;
    render();
}

let baseline = '';

/* Nothing is written until Save is pressed.
 *
 * This used to auto-save on every keystroke, copied from the WiFi Manager, on the
 * reasoning that a save button is one more thing to forget. On a page whose fields are
 * frequencies and squelch levels that was wrong twice over: pausing while typing "146.7"
 * saved 146 MHz and renamed a live channel, and a page left open for an hour silently
 * overwrote everything that had changed underneath it the moment anything was touched.
 * A receiver's configuration should change when somebody says so, and not before. */
let dirty = false;

function touch() {
    if (!CAN_EDIT) return;
    dirty = true;
    status('Unsaved changes', 'saving');
    const b = $('save-btn');
    if (b) b.disabled = false;
}

// The browser's own guard against walking away from unsaved work.
window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

async function save() {
    if (!CAN_EDIT) return;
    status('Saving…', 'saving');
    try {
        const r = await fetch('?save', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            // The page works in MHz; the server accepts either and stores Hz.
            // `baseline` is what this page was loaded from: the server refuses the write
            // if the registry has moved on, rather than silently overwriting somebody
            // else's change — or, as happened here, a change made outside the page.
            body: JSON.stringify({...data, baseline: baseline, channels: data.channels.map(
                c => ({...c, frequency: c.mhz}))}),
        });
        const d = await r.json();
        if (d.error) { status(d.error, 'error'); return; }
        dirty = false;
        const b = $('save-btn');
        if (b) b.disabled = true;
        // Re-read: the server issues tokens for new rows and normalises ids, so the
        // browser must not keep believing what it sent.
        await load();
        // A new sheet URL, or a change to the supplement box, is worth reading straight
        // away — the alternative is a field that saves and then appears to do nothing for
        // a quarter of an hour. Not folded into the save itself: fetching a document from
        // Google can take seconds, and Save must return as fast as it did before whether
        // or not these fields were touched.
        if (d.vocabulary_changed) await refreshVocabulary();
        // A device is done when what it holds matches what it should hold — which is
        // already true for one the edit did not touch, so it does not sit pending on
        // somebody else's change.
        waitForDevices(x => x.up_to_date, 'Update');
    } catch { status('Save failed', 'error'); }
}

/* Asks every Transcriber to do a full update — the worker itself, not just its settings
 * — at its next check. There is no way to reach into a Pi behind NAT and no wish to open
 * one, so this leaves a timestamp the devices find when they next look. */
async function requestUpdate() {
    if (!CAN_EDIT) return;
    if (!confirm('Ask every Transcriber to update its software at its next check?\n\n'
               + 'Each channel restarts as it updates, so a receiver is off the air for '
               + 'a few seconds.')) return;
    try {
        const r = await fetch('?update', {method: 'POST'});
        const d = await r.json();
        if (d.error) { status(d.error, 'error'); return; }
        const since = d.requested;
        waitForDevices(x => x.last_fetch >= since, 'Software update');
    } catch { status('Request failed', 'error'); }
}

/* Re-read the assignment sheet, and show what came back.
 *
 * The counts alone would not answer the question anybody is actually asking, which is not
 * "how many" but "did it read MY sheet, the one I edited ten minutes ago". So the words
 * themselves are listed. Thirty-five callsigns is a number; your own callsign in the list
 * is an answer, and a callsign that should be there and is not is the only way to notice
 * that the manager is pointed at last month's document. */
async function refreshVocabulary() {
    if (!CAN_EDIT) return;
    // The refresh reads the STORED url, because it has no other way of knowing which
    // document to trust — the one in the box may be half-typed. So a url that has been
    // changed but not saved is read from its old value, and the page reports what the
    // PREVIOUS document contained, which looks exactly like the new one being wrong.
    //
    // That happened the first time somebody used this: a new sheet was pasted in, "read
    // sheet now" pressed, and the honest "no vocabulary section" it reported was about a
    // document the user was no longer looking at. Nothing about the wording could have
    // helped; the button had to stop reading a url the page had already replaced.
    if (dirty) {
        status('Save first — the sheet is read from the saved address', 'error');
        notice('Save before reading the sheet',
               'The address in the box has not been saved yet, so reading now would '
             + 'fetch the previous sheet and report on that one instead. Press Save, '
             + 'then read.');
        return;
    }
    const btn = $('vocab-btn'), meta = $('vocab-meta');
    if (btn) btn.disabled = true;
    meta.textContent = 'Reading the sheet…';
    meta.className = 'hint';
    try {
        const v = await (await fetch('?vocabulary', {method: 'POST'})).json();
        // A failed read keeps the lists it already had, so what comes back is still the
        // vocabulary in force — it simply carries an error alongside it. A refusal is not
        // that shape and carries no lists at all, so keep the ones already on the page
        // rather than making a permission error look like an empty sheet.
        data.vocabulary = v.callsigns ? v : {...data.vocabulary, error: v.error || 'refused'};
    } catch {
        data.vocabulary = {...data.vocabulary, error: 'the server did not answer'};
    }
    if (btn) btn.disabled = false;
    renderVocabulary();
}

/* The standing vocabulary, in a modal of its own.
 *
 * It is fetched when the editor opens rather than taken from what ?load gave, and it is
 * saved through its own endpoint. Both for the same reason: this list is not part of the
 * page's Save. It lives in its own file so that writing it does not move the registry's
 * fingerprint — otherwise editing it would make the page refuse its own next Save — and a
 * page that has been open a while would otherwise offer somebody a stale copy of a long
 * list to overwrite.
 *
 * `standingPrint` is what the editor was opened against. The server refuses a write made
 * against anything else, which is the same guard the registry has and matters more here:
 * this is a list somebody spends minutes in. */
let standingPrint = '';

async function openStanding() {
    if (!CAN_EDIT) return;
    const box = $('standingbox'), text = $('standing-text'), st = $('standing-status');
    st.textContent = 'Loading…';
    st.className = '';
    text.value = '';
    box.classList.add('open');
    try {
        const d = await (await fetch('?standing')).json();
        text.value = d.text || '';
        standingPrint = d.fingerprint || '';
        st.textContent = d.seeded
            ? 'Never edited — this is the list it started with.'
            : 'Last edited ' + ago(d.updated_at) + '.';
    } catch {
        st.textContent = 'Could not read the standing list.';
        st.className = 'error';
    }
}

function closeStanding() { $('standingbox').classList.remove('open'); }

// ── Tracker ID names ─────────────────────────────────────────────────────────
let idsPrint = '';

function renderIdsMeta(d) {
    const n  = d && d.map ? Object.keys(d.map).length : 0;
    const el = $('ids-meta');
    if (!el) return;
    // The count is of lines that actually parsed, not lines typed, so a line missing
    // its "=" shows up as a number that did not go up.
    el.textContent = n === 0 ? 'No IDs named yet — every ID is shown as-is.'
                             : n + (n === 1 ? ' ID named.' : ' IDs named.');
}

async function loadIds() {
    try {
        const d = await (await fetch('?ids')).json();
        idsPrint = d.fingerprint || '';
        const ta = $('ids-text'), ro = $('ids-text-ro');
        if (ta) ta.value = d.text || '';
        if (ro) ro.textContent = (d.text || '').trim() || '— none —';
        renderIdsMeta(d);
    } catch {}
}

async function saveIds() {
    if (!CAN_EDIT) return;
    const btn = $('ids-save');
    btn.disabled = true;
    try {
        const r = await fetch('?ids', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({text: $('ids-text').value, fingerprint: idsPrint}),
        });
        const d = await r.json();
        if (d.error) { status(d.error, 'error'); btn.disabled = false; return; }
        idsPrint = d.fingerprint || '';
        renderIdsMeta(d);
        // No waitForDevices here: unlike the vocabulary, this list is not sent to the
        // receivers at all. It is read by the server when it hands out a message, so it
        // is in force for the next message rather than at the next device poll.
        status('ID names saved', 'saved');
    } catch {
        status('Save failed.', 'error');
    }
    btn.disabled = false;
}

async function saveStanding() {
    if (!CAN_EDIT) return;
    const btn = $('standing-save'), st = $('standing-status');
    btn.disabled = true;
    st.textContent = 'Saving…';
    st.className = '';
    try {
        const r = await fetch('?standing', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({text: $('standing-text').value, fingerprint: standingPrint}),
        });
        const d = await r.json();
        if (d.error) { st.textContent = d.error; st.className = 'error'; btn.disabled = false; return; }
        standingPrint = d.fingerprint || '';
        // What is now in force comes back with the save, so the page below the modal is
        // right the moment the modal closes rather than a request later.
        if (d.vocabulary) data.vocabulary = d.vocabulary;
        renderVocabulary();
        closeStanding();
        status('Standing vocabulary saved', 'saved');
        // The receivers do not have it yet: the vocabulary travels with their channels, at
        // their next poll. Waited for by check-in rather than by fingerprint, as the
        // software update is — the channel fingerprint deliberately excludes the vocabulary,
        // so `up_to_date` is already true here and waiting on it would report "applied"
        // before any device had looked.
        waitForDevices(x => x.last_fetch >= d.updated_at, 'Vocabulary update');
    } catch {
        st.textContent = 'Save failed.';
        st.className = 'error';
    }
    btn.disabled = false;
}

function ago(ts) {
    if (!ts) return 'never';
    const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 90)     return 'just now';
    if (s < 5400)   return Math.round(s / 60) + ' minutes ago';
    if (s < 172800) return Math.round(s / 3600) + ' hours ago';
    return Math.round(s / 86400) + ' days ago';
}

const plural = (n, one, many) => `${n} ${n === 1 ? one : (many || one + 's')}`;

function renderVocabulary() {
    const v = data.vocabulary || {};
    const settings = data.settings || {};
    const url = settings.sheet_url || '';
    const box = $('sheet-url');
    // Not while somebody is typing in it: this runs on every render, including the one
    // that follows a save.
    if (box && document.activeElement !== box) box.value = url;
    const readonlyUrl = $('sheet-url-ro');          // shown instead of the box without edit rights
    if (readonlyUrl) readonlyUrl.textContent = url || 'No sheet set';

    const extraBox = $('vocab-extra');
    if (extraBox && document.activeElement !== extraBox) {
        extraBox.value = settings.vocabulary_extra || '';
    }
    const extraRo = $('vocab-extra-ro');
    if (extraRo) extraRo.textContent = settings.vocabulary_extra || 'Nothing added here.';

    const calls = v.callsigns || [], tac = v.tactical || [];
    // What is in force — the sheet and the box together — because that is what the
    // receivers were handed. `words` is absent on a response that carried no lists at all,
    // such as a permission error; fall back rather than render nothing.
    const words = v.words || {callsigns: calls, tactical: tac, terms: v.terms || [],
                              corrections: v.corrections || {}};

    const meta = $('vocab-meta');
    if (v.error) {
        meta.textContent = 'Could not read the sheet: ' + v.error
            + (calls.length ? ' The channels are still using what it read last time.' : '');
        meta.className = 'hint error';
    } else if (!url) {
        meta.textContent = 'No sheet set. '
            + ((words.terms || []).length
                ? 'Channels are using only what is typed below.'
                : 'Channels transcribe without a vocabulary hint.');
        meta.className = 'hint';
    } else {
        meta.textContent = `${plural(calls.length, 'callsign')} and `
            + `${plural(tac.length, 'tactical call')}, read ${ago(v.fetched_at)}.`;
        meta.className = 'hint';
    }

    /* Whether the sheet's vocabulary section was there, said separately from the counts
     * above and never folded into them.
     *
     * This is the whole reason the parser reports it. Rename the heading, or lose the
     * section in an edit, and the terms silently become none — the callsign and tactical
     * counts are unchanged, everything looks like it worked, and the first anybody knows
     * is a log full of "Windy Cap" halfway through an event. A line that says "not found"
     * costs nothing and is the only thing standing between that and a phone call. */
    const section = $('vocab-section');
    const terms = (v.terms || []).length;
    const fixes = Object.keys(v.corrections || {}).length;
    if (!url || v.error) {
        section.textContent = '';
        section.className = 'hint';
    } else if (v.section_found) {
        section.textContent = `Vocabulary section: found, ${plural(terms, 'term')}`
            + (fixes ? ` and ${plural(fixes, 'correction')}.` : '.');
        section.className = 'hint';
    } else {
        section.textContent = 'Vocabulary section: not found. The sheet has no heading with '
            + '"Vocabulary" in it, so no place names were read from it.';
        section.className = 'hint missing';
    }

    const fixLines = Object.keys(words.corrections || {})
        .map(heard => `${heard} → ${words.corrections[heard]}`);
    $('vocab-lists').innerHTML = [['Callsigns', words.callsigns || []],
                                  ['Tactical calls', words.tactical || []],
                                  ['Place names and other terms', words.terms || []],
                                  ['Corrections', fixLines]]
        .filter(g => g[1].length)
        .map(g => `<div class="vocab-group"><strong>${g[0]}</strong>
                   <div class="words">${g[1].map(w => `<span>${esc(w)}</span>`).join('')}</div>
                 </div>`).join('');

    /* And what the box on its own came to. Same argument as listing the words: the
     * question nobody asks is "how many", it is "did it understand the line I typed". A
     * line with a typo in it — no "=", or nothing after one — is simply not there, and
     * this is where that shows. */
    const em = $('vocab-extra-meta');
    const ex = v.extra || {terms: [], corrections: {}};
    const exTerms = (ex.terms || []).length;
    const exFixes = Object.keys(ex.corrections || {}).length;
    if (!exTerms && !exFixes) {
        em.textContent = (settings.vocabulary_extra || '').trim()
            ? 'Nothing readable here yet — press Save, and check each line is a term or '
              + '"heard = written".'
            : 'Empty. The sheet is doing all the work, which is where it belongs.';
    } else {
        em.textContent = `Adding ${plural(exTerms, 'term')}`
            + (exFixes ? ` and ${plural(exFixes, 'correction')}` : '')
            + " to the event's vocabulary.";
    }

    const st = v.standing || {};
    const sl = st.lines || {terms: [], corrections: {}};
    const sTerms = (sl.terms || []).length;
    const sFixes = Object.keys(sl.corrections || {}).length;
    const sm = $('standing-meta');
    if (!sTerms && !sFixes) {
        sm.textContent = 'Empty. Every event supplies its own words.';
    } else {
        sm.textContent = plural(sTerms, 'term')
            + (sFixes ? ` and ${plural(sFixes, 'correction')}` : '')
            + ', on every event'
            + (st.seeded ? ' — never edited, this is the list it started with.' : '.');
    }

    /* Anything the ceiling threw away, said plainly and by source.
     *
     * This is the failure that has already happened: 270 lines were pasted into the box,
     * 200 were kept, 70 vanished, and nothing anywhere said so. The only symptom was that
     * the list on the page looked shorter than the one in the clipboard, and it was noticed
     * by luck. A third source makes reaching the ceiling likelier, so the page says which
     * list lost words and how many — the same reason the vocabulary section reports "found"
     * rather than leaving an empty list to be interpreted. */
    // Named by the heading they are under, not by where they sit relative to this line.
    // "the box below" was right until this line moved, and a report that sends somebody to
    // the wrong list is worse than a count on its own.
    const names = {standing: 'Standing vocabulary', sheet: 'the sheet',
                   box: 'Place names and corrections'};
    const lost = Object.keys(v.dropped || {})
        .filter(k => v.dropped[k] > 0)
        // "lines" rather than "terms": the count covers corrections too, and a message that
        // says "terms" about a dropped correction sends somebody to count the wrong list.
        .map(k => `${plural(v.dropped[k], 'line')} from ${names[k] || k}`);
    const total = Object.keys(v.dropped || {}).reduce((n, k) => n + v.dropped[k], 0);
    const dp = $('vocab-dropped');
    // Agreement on the count of words, not on the count of sources: one source losing 70
    // terms is still "were dropped".
    dp.textContent = total
        ? `Over the limit: ${lost.join(', ')} `
          + (total === 1 ? 'was dropped and the receivers never saw it.'
                         : 'were dropped and the receivers never saw them.')
          + ` The limit is ${v.ceiling || 1000} terms and as many corrections —`
          + ' shorten one of the lists.'
        : '';
}

/* What this channel was measured at, or that it never has been.
 *
 * "Never" is the state this column exists for. A receiver that has never been calibrated
 * works — it runs the built-in gain and squelch — and looks exactly like one that has, so
 * a newly sited Pi would quietly use numbers measured on a different hill with a different
 * antenna for as long as nobody thought to ask. Nobody presses a button they have no
 * reason to know about, so the page has to say it.
 *
 * A failure keeps showing the last good pair beside the reason, because that pair is what
 * the receiver went back on the air with. */
/* Say once, at the top, that a channel is about to listen using numbers measured
 * somewhere else.
 *
 * Only ENABLED channels count. A disabled row is not listening, so warning about it is
 * noise — and noise in a warning is how the row-level "Never" came to be ignored.
 *
 * The wording names the consequence rather than the state. "Never calibrated" is a fact
 * about a config file; "will not hear the net properly" is what actually happened on
 * 2026-08-22, and is what makes somebody press the button before the event rather than
 * read past it.
 */
/* Measure one channel's gain and squelch, at the site it is on.
 *
 * Off the air while it runs, so it says so first — the same shape as "Update devices",
 * which also asks before doing something a receiver will notice. */
/* Wait for one channel to measure itself, and count down honestly while it does.
 *
 * Two phases, because there are two waits and only the second one has a length. The
 * device collects its settings once a minute, so the first phase is the same wait as
 * everything else on this page — up to 75 seconds before it even hears about this. A
 * countdown started at the button press would spend that minute counting down to a
 * measurement that had not begun, and would then claim the channel was back on the air
 * while the radio was still busy.
 *
 * So the device reports that it has STARTED, and how long it expects to take, and the
 * second phase counts down from that against the server's clock rather than the
 * browser's. When the estimate runs out and the device has not reported back, the page
 * says it is still measuring rather than pretending to know something it does not. */
const CALIBRATE_PICKUP = WAIT_SECONDS;      // the same 60-second poll, and the same slack
const CALIBRATE_OVERRUN = 420;              // ...after which the receiver is not answering

function tokenCell(row, kind, key) {
    const state = row.has_token ? '<span class="tok set">set</span>'
                                : '<span class="tok unset">none</span>';
    if (!CAN_EDIT) return state;
    return state + ` <button class="row-btn" onclick="rotate('${kind}','${esc(key)}')">New…</button>`;
}

async function rotate(kind, id) {
    if (!confirm(`Issue a new token for ${id}?\n\nThe old one stops working immediately, and `
               + `${kind === 'device' ? 'that Pi will not fetch its channels' : 'that channel will not log'} `
               + `until the new one is installed.`)) return;
    const r = await fetch('?rotate', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({kind, id}),
    });
    const d = await r.json();
    if (d.error) { status(d.error, 'error'); return; }
    $('tok-title').textContent = `Token for ${id}`;
    $('tok-value').textContent = d.token;
    $('tokenbox').classList.add('open');
    await load();
}

/* A box you cannot type in still looks like a box, and the only way to discover it is
   read-only is to try. Without edit rights this is a report, so it is rendered as one. */
function ro(text) {
    return `<span class="ro">${esc(text) || '—'}</span>`;
}

/* commit: 'change' saves when the field is left rather than on every keystroke.
 *
 * For a name or a serial, saving as you type is fine — a half-typed one is just a
 * shorter name. A half-typed NUMBER is a different value: pausing while typing "146.7"
 * saved 146 MHz, which renamed the channel to -146000, issued it a token under the new
 * id and would have stopped the running unit at the next poll. The receiver was being
 * reconfigured, briefly and for real, out of an unfinished keystroke. */
function field(group, i, key, value, type, commit) {
    if (!CAN_EDIT) return ro(value);
    const track = `data.${group}[${i}].${key} = this.value;`;
    const attrs = `type="${type || 'text'}" value="${esc(value)}" data-k="${group}.${i}.${key}"`;
    return commit === 'change'
        ? `<input ${attrs} oninput="${track}" onchange="touch()">`
        : `<input ${attrs} oninput="${track} touch()">`;
}

function render() {
    // A save re-reads from the server and rebuilds this table, which destroys the input
    // being typed into. Frequency showed it worst: "146." normalises to "146", the box is
    // replaced with that, the caret is gone, and the decimal point can never be typed at
    // all. So remember where the cursor was and what was in the box, and put both back.
    const was = document.activeElement;
    const key = was && was.dataset ? was.dataset.k : null;
    const caret = key && was.setSelectionRange ? [was.selectionStart, was.selectionEnd] : null;
    const typed = key ? was.value : null;

    renderRows();

    if (key) {
        const el = document.querySelector(`[data-k="${key.replace(/"/g, '')}"]`);
        if (el) {
            // The server's normalised value must not overwrite a half-finished number
            // under the cursor. Whatever is in `data` is already what was sent.
            if (typed !== null) el.value = typed;
            el.focus();
            if (caret) { try { el.setSelectionRange(caret[0], caret[1]); } catch (e) {} }
        }
    }
}

/* ── Calibration meter ─────────────────────────────────────────────────────────
 *
 * Polls ?level about once a second while running. Not a real VU meter and not trying to
 * be: a knob is turned by hand over seconds, and a websocket to shave 900 ms off a reading
 * nobody follows that fast would be machinery for its own sake.
 *
 * The number is the AUDIO BAND (200-4000 Hz), measured on the device and sent already
 * band-limited. Levelling against a raw peak is what drove a radio's volume to zero on
 * 2026-08-29: the peak belonged to a squelch thump generated after the volume control,
 * which no knob can move. Whatever this bar shows, it must never be that number.
 *
 * Nothing here is stored. A level measured at one site says nothing about the next, and
 * the receiver moves.
 */
const VU_LO = -70, VU_HI = 0, VU_MIN = -38, VU_IDEAL = -27, VU_MAX = -20;
let vuTimer = null, vuPeak = -99, vuPeakAt = 0;

const vuPct = db => Math.max(0, Math.min(100, (db - VU_LO) / (VU_HI - VU_LO) * 100));

function renderMeterChannels() {
    const sel = $('vu-channel');
    if (!sel) return;
    const want = sel.value;
    sel.innerHTML = data.channels.map(c =>
        `<option value="${esc(c.id)}">${esc(c.label || c.id)}</option>`).join('');
    if (want) sel.value = want;
    // One receiver is the whole point; a picker with a single entry is furniture.
    sel.style.display = data.channels.length > 1 ? '' : 'none';
}

function toggleMeter() {
    if (vuTimer) { stopMeter(); return; }
    vuPeak = -99;
    $('vu-btn').textContent = 'Stop meter';
    $('vu-note').textContent = 'Open the radio\u2019s squelch, then turn its volume.';
    vuTimer = setInterval(pollLevel, 1000);
    pollLevel();
}

function stopMeter() {
    clearInterval(vuTimer); vuTimer = null;
    $('vu-btn').textContent = 'Start meter';
    $('vu-note').textContent = '';
}

async function pollLevel() {
    let d;
    try { d = await (await fetch('?level')).json(); }
    catch (e) { $('vu-note').textContent = 'No answer from the server.'; return; }
    const id = $('vu-channel').value || (data.channels[0] || {}).id;
    const row = (d.levels || {})[id];
    const bar = $('vu-bar'), pk = $('vu-peak'), out = $('vu-db'), tag = $('vu-tag');

    // Silence and staleness must not look alike. A device that stopped reporting is not a
    // radio turned down, and confusing the two sends somebody to the wrong knob.
    if (!row || (d.now - row.at) > 10) {
        bar.style.width = '0%'; bar.className = 'vu-bar';
        pk.style.display = 'none';
        out.textContent = '\u2014';
        tag.textContent = row ? 'device stopped reporting' : 'waiting for the device\u2026';
        tag.className = 'vu-stale';
        return;
    }
    const db = row.db;
    bar.style.width = vuPct(db) + '%';
    const now = Date.now();
    if (db > vuPeak || now - vuPeakAt > 2000) { vuPeak = db; vuPeakAt = now; }
    pk.style.display = ''; pk.style.left = vuPct(vuPeak) + '%';
    out.textContent = db.toFixed(1) + ' dBFS';
    let t, cls;
    if      (db > VU_MAX)                                  { t = 'too hot'; cls = 'vu-hot'; }
    else if (db >= VU_MIN && Math.abs(db - VU_IDEAL) <= 3) { t = 'ideal';   cls = 'vu-ok';  }
    else if (db >= VU_MIN)                                 { t = 'ok';      cls = 'vu-mid'; }
    else                                                   { t = 'too low'; cls = 'vu-low'; }
    tag.textContent = t; tag.className = cls;
    bar.className = 'vu-bar ' + cls;
}

function renderRows() {
    // The Receivers and Channels TABLES are gone from the page; `data.devices` and
    // `data.channels` are not gone from the registry. They still carry the config token,
    // the log token and the channel id the Pi needs, and save() posts them back untouched.
    // Dropping the UI must not drop the data.
    const c = data.channels[0];
    const box = $('receiver');
    $('receiver-empty').style.display = c ? 'none' : '';
    if (!c) { box.innerHTML = ''; renderMeterChannels(); renderVocabulary(); return; }
    const i = 0;
    box.innerHTML = `
      <table class="explain">
        <tr><th>Identity</th><td>${ro(c.id || '')}
            <div class="derived">Names the systemd unit
            (<code>transcriber@${esc(c.id || '')}</code>), the spool directory and the
            author of every log entry. Fixed at creation.</div></td></tr>
        <tr><th>Heard as</th><td>${field('channels', i, 'label', c.label)}
            <div class="derived">The name on every entry this channel writes.</div></td></tr>
        <tr><th>Accuracy</th><td>${CAN_EDIT
              ? `<select onchange="data.channels[0].model = this.value; touch()">
                   ${MODELS.map(m => `<option value="${m.file}"${m.file === c.model ? ' selected' : ''}>${m.name}</option>`).join('')}
                 </select>`
              : ro((MODELS.find(m => m.file === c.model) || {}).name || c.model)}
            <div class="derived">Careful is better on callsigns, about three times slower.</div></td></tr>
        <tr><th>On</th><td>${CAN_EDIT
              ? `<input type="checkbox" ${c.enabled ? 'checked' : ''}
                        onchange="data.channels[0].enabled = this.checked; touch()">`
              : ro(c.enabled ? 'On' : 'Off')}
            <div class="derived">Off stops it logging at once, without losing the setup.</div></td></tr>
        <tr><th>Audio</th><td>${CAN_EDIT
              ? `<input type="checkbox" ${c.send_audio ? 'checked' : ''}
                        onchange="data.channels[0].send_audio = this.checked; touch()">`
              : ro(c.send_audio ? 'On' : 'Off')}
            <div class="derived">Sends the recording with the transcription, kept six hours.</div></td></tr>
        <tr><th>Log token</th><td>${tokenCell(c, 'channel', c.id)}</td></tr>
      </table>`;
    renderMeterChannels();
    renderVocabulary();
}


load();
loadIds();
</script>
</body>
</html>
