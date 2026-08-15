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
        $channels[] = [
            'id'        => $id,
            'device'    => $device,
            'label'     => substr(trim($c['label'] ?? ''), 0, 40) ?: $id,
            'frequency' => $hz,
            'serial'    => substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($c['serial'] ?? '')), 0, 32),
            'squelch'   => max(0, min(1000, (int)($c['squelch'] ?? 0))),
            'model'     => in_array($c['model'] ?? '', ['ggml-tiny.en.bin', 'ggml-base.en.bin'], true)
                           ? $c['model'] : 'ggml-tiny.en.bin',
            'enabled'   => !empty($c['enabled']),
            'token'     => $chTokens[$id] ?? transcriber_token(),
        ];
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
    $extra = substr((string)($body['settings']['vocabulary_extra'] ?? ''), 0, 4000);
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
.cal { font-size: 12px; white-space: nowrap; }
.cal.never { color: #b45309; font-weight: 600; }
.cal.busy { color: #2563eb; }
.cal.bad { color: #dc2626; font-weight: 600; }
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
    <button class="hdr-btn" id="add-device">+ Device</button>
    <button class="hdr-btn" id="add-channel">+ Channel</button>
    <button class="hdr-btn hdr-btn-primary" id="save-btn" onclick="save()" disabled>Save</button>
    <button class="hdr-btn" id="update-btn" onclick="requestUpdate()">Update devices</button>
  <?php endif; ?>
  <a class="hdr-btn" href="/netbird/">Devices</a>
  <a class="hdr-btn" href="?logout">Sign out</a>
</header>

<main>
  <div class="panel">
    <strong>When do changes take effect?</strong>
    <p>Nothing is written until you press <strong>Save</strong>. Each Pi then collects
       its settings <strong>within 60 seconds</strong> — there is nothing to run and
       nothing to log in to. A change to frequency, accuracy or squelch restarts that
       channel when it lands; switching a channel <strong>On</strong> or off takes effect
       at once, because the server stops accepting from it without waiting for the device
       to notice. The page waits for each Pi to collect and tells you when it has —
       or which one never answered.</p>
    <p><strong>Update devices</strong> is for software rather than settings: it asks every
       Transcriber to pull a new worker at its next check instead of waiting for the
       nightly run at 4:11am. Settings do not need it.</p>
    <p><strong>Recalibrate</strong>, on a channel row, is the other kind of thing again:
       it asks that one channel to measure the tuner gain and squelch for the site it is
       on. It is off the air for two or three minutes while it does, so it is asked for
       rather than scheduled — nothing here recalibrates by itself, at boot or otherwise.
       A channel that has never been calibrated says <strong>Never</strong> and is running
       numbers measured somewhere else.</p>
    <p>This page does <strong>not</strong> refresh by itself, so it can go stale while it
       sits open. It no longer overwrites what it cannot see: if anything changed since
       you loaded, Save is refused and asks you to reload rather than quietly reverting
       somebody else's work.</p>
  </div>

  <h2>Receivers</h2>
  <p class="hint">One row per Transcriber Pi. <strong>Host</strong> must match what that
     machine calls itself (<code>hostname</code>) — it is how the Pi identifies itself
     when it collects its settings. The <strong>config token</strong> goes in
     <code>/home/pi/.transcriber-token</code> on that Pi and lets it do so.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Host</th><th>Config token</th><th></th></tr></thead>
      <tbody id="devices"></tbody>
    </table>
    <div class="empty" id="devices-empty">No Transcribers yet.</div>
  </div>

  <h2>Channels</h2>
  <p class="hint">One row per frequency being listened to. A receiver with two dongles
     can cover two channels at once.</p>
  <table class="explain">
    <tr><th>Receiver</th><td>Which Pi does the listening.</td></tr>
    <tr><th>Frequency</th><td>In <strong>MHz</strong>, as you would read it off a radio —
        <code>147.465</code>. For a repeater this is the <em>output</em>: the frequency it
        transmits on, not the one you transmit to it on.</td></tr>
    <tr><th>Heard as</th><td>The name on every entry this channel writes, so choose what you
        want to read in the log during an event: <code>West Marin</code> says more than
        <code>147.465</code>. Cosmetic only — changing it renames nothing else.</td></tr>
    <tr><th>Dongle serial</th><td>The serial programmed into the SDR stick, not a slot
        number. Slot order changes when the Pi reboots, and two channels quietly swapping
        frequencies is a fault nobody notices until the log is already wrong. Read or set
        one with <code>rtl_eeprom -d 0 -s 00000001</code>.</td></tr>
    <tr><th>Squelch</th><td>How strong a signal has to be before the receiver records
        anything. This is the one setting that decides what gets logged: too low and the
        Pi spends its day transcribing static, too high and it is quietly deaf.
        <br>Leave it <strong>blank</strong> and the channel uses whatever
        <strong>Recalibrate</strong> measured for the site it is on, or a built-in default
        if nobody has ever pressed it. Put a number in only when you have a reason: raise
        it (30, 40) if the log fills with noise, lower it if weak stations are being
        missed. Roughly 0–100. A number typed here overrides the measurement.</td></tr>
    <tr><th>Calibration</th><td>The tuner gain and squelch measured at the site this
        receiver is actually on, and when. The two go together: squelch is a threshold on
        received signal strength, and the gain decides what that strength is, so a squelch
        measured at the wrong gain means nothing.
        <br><strong>Never</strong> means this channel is running the built-in pair, which
        was measured somewhere else — worth fixing on a newly sited receiver, and harmless
        on one that is somewhere quiet. Pressing <strong>Recalibrate</strong> takes that
        one channel off the air for two or three minutes while it measures, and nothing
        said on that frequency is logged until it finishes. Do it on a quiet channel:
        traffic arriving mid-measurement is detected and the calibration is abandoned
        rather than recorded wrong.</td></tr>
    <tr><th>Accuracy</th><td><strong>Fast</strong> keeps up with a busy net in real time and
        is the right default. <strong>Careful</strong> is better on callsigns and phonetics
        but runs about three times slower, so on a busy frequency entries arrive behind the
        traffic. Worth it only if you are reading the log for identifiers rather than for
        the gist.</td></tr>
    <tr><th>On</th><td>Off stops it logging at once, without losing the setup.</td></tr>
  </table>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Receiver</th><th>Frequency (MHz)</th><th>Heard as</th><th>Dongle serial</th>
        <th>Squelch</th><th>Calibration</th><th>Accuracy</th><th>On</th><th>Log token</th>
        <th></th>
      </tr></thead>
      <tbody id="channels"></tbody>
    </table>
    <div class="empty" id="channels-empty">No channels yet.</div>
  </div>

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
</main>

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
function calibrationCell(c) {
    const cal = (data.calibration || {})[c.id] || {};
    const btn = CAN_EDIT && c.id
        ? ` <button class="row-btn" onclick="recalibrate('${esc(c.id)}')">Recalibrate</button>`
        : '';
    const pair = cal.gain
        ? `<span class="cal">${cal.gain} dB / ${cal.squelch}</span>`
        : '';

    // No button while it is running: pressing it again would only queue a second
    // measurement behind the one already holding the dongle.
    if (cal.requested && (!cal.finished || cal.finished < cal.requested)) {
        return (cal.started >= cal.requested
                ? '<span class="cal busy">Measuring…</span>'
                : '<span class="cal busy">Waiting for the receiver…</span>')
             + (pair ? `<div class="derived">now: ${cal.gain} dB / ${cal.squelch}</div>` : '');
    }
    if (cal.error) {
        return `<span class="cal bad" title="${esc(cal.error)}">Failed</span>${btn}`
             + `<div class="derived">${esc(cal.error)}</div>`
             + (pair ? `<div class="derived">still using ${cal.gain} dB / ${cal.squelch}</div>` : '');
    }
    if (cal.gain) {
        return pair + btn + `<div class="derived">measured ${ago(cal.finished)}</div>`;
    }
    // No numbers and nothing pending. Deliberately not spelling out what the built-in pair
    // is: it lives in the worker, and a second copy of it here would be wrong the first
    // time somebody changed one of them.
    return `<span class="cal never">Never</span>${btn}`
         + '<div class="derived">built-in defaults</div>';
}

/* Measure one channel's gain and squelch, at the site it is on.
 *
 * Off the air while it runs, so it says so first — the same shape as "Update devices",
 * which also asks before doing something a receiver will notice. */
async function recalibrate(id) {
    if (!CAN_EDIT) return;
    // The device measures the channel as it is SAVED. An unsaved frequency change means a
    // different channel id, and an unsaved squelch override means the number about to be
    // measured is one the manager is going to overrule — either way the answer would be
    // about something other than what is on the screen.
    if (dirty) {
        status('Save first — the receiver measures the channel as it is saved', 'error');
        notice('Save before calibrating',
               'This page has changes that have not been saved, so the receiver would '
             + 'measure the channel as it was before them. Press Save, then Recalibrate.');
        return;
    }
    if (!confirm(`Measure the tuner gain and squelch for ${id}?\n\n`
               + `That channel is off the air for two or three minutes while it measures, `
               + `and nothing said on that frequency is logged until it finishes. Other `
               + `channels on the same receiver keep listening.\n\n`
               + `Do this on a quiet channel: if somebody transmits during the `
               + `measurement it is abandoned rather than recorded wrong.`)) return;
    try {
        const r = await fetch('?calibrate', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({channel: id}),
        });
        const d = await r.json();
        if (d.error) { status(d.error, 'error'); return; }
        // Show it as pending straight away rather than a second later when the first poll
        // comes back, so the button visibly did something.
        data.calibration = data.calibration || {};
        data.calibration[id] = {...(data.calibration[id] || {}),
                                requested: d.requested, started: 0, finished: 0, error: ''};
        render();
        waitForCalibration(id, d.requested);
    } catch { status('Request failed', 'error'); }
}

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

async function waitForCalibration(id, since) {
    clearInterval(waitTimer);
    let left = CALIBRATE_PICKUP;
    let startedAt = 0;                      // when we first saw it start, by our clock
    let checking = false;

    const done = (text, cls) => {
        clearInterval(waitTimer);
        $('spinner').hidden = true;
        status(text, cls);
        render();
    };

    const show = () => {
        if (!startedAt) {
            status(`Calibrating ${id} — waiting for the receiver to start  :${left}`, 'saving');
        } else if (left > 0) {
            status(`Calibrating ${id} — measuring, about  :${left}`, 'saving');
        } else {
            status(`Calibrating ${id} — still measuring`, 'saving');
        }
    };

    const check = async () => {
        if (checking) return;
        checking = true;
        try {
            const d = await (await fetch('?status')).json();
            if (d.calibration) data.calibration = d.calibration;
            const cal = (d.calibration || {})[id] || {};
            if (cal.finished >= since) {
                if (cal.error) {
                    done('Calibration failed: ' + cal.error, 'error');
                    notice('Calibration failed',
                           id + ' did not finish measuring: ' + cal.error + '\n\n'
                         + 'Nothing was changed — the channel is back on the air using '
                         + 'what it was using before. Try again when the frequency is '
                         + 'quiet.');
                } else {
                    done(`${id}: gain ${cal.gain} dB, squelch ${cal.squelch} — measured`,
                         'saved');
                }
                return;
            }
            if (cal.started >= since) {
                if (!startedAt) { startedAt = Date.now(); render(); }
                // Against the server's clock, not this browser's: the start is a
                // timestamp the device reported and the page has no idea how far out its
                // own clock is. The fallback is only for a device too old to say.
                left = Math.max(0, (cal.expected || 150) - (d.now - cal.started));
            }
        } catch (e) {
            // A blip in the page's own connection is not the receiver failing to answer.
        } finally { checking = false; }
    };

    $('spinner').hidden = false;
    show();
    await check();

    let tick = 0;
    waitTimer = setInterval(() => {
        tick++;
        if (!startedAt) {
            if (--left <= 0) {
                done('No response from the receiver for ' + id, 'error');
                notice('Calibration not started',
                       id + ' has not collected the request. It is saved and the receiver '
                     + 'will act on it as soon as it checks in.\n\n'
                     + 'A device that is switched off, off the network, or has the wrong '
                     + 'config token will look exactly like this.');
                return;
            }
        } else {
            if (left > 0) left--;
            if (Date.now() - startedAt > CALIBRATE_OVERRUN * 1000) {
                done('No result from ' + id, 'error');
                notice('Calibration did not report back',
                       id + ' said it had started measuring but never said what it found. '
                     + 'The channel restarts by itself either way; check the update log '
                     + 'on that receiver.');
                return;
            }
        }
        show();
        if (tick % 2 === 0) check();
    }, 1000);
}

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

function renderRows() {
    const dev = $('devices');
    dev.innerHTML = data.devices.map((d, i) => `<tr>
        <td>${field('devices', i, 'host', d.host)}</td>
        <td>${tokenCell(d, 'device', d.host)}</td>
        <td>${CAN_EDIT ? `<button class="row-btn danger" onclick="delDevice(${i})">Remove</button>` : ''}</td>
    </tr>`).join('');
    $('devices-empty').style.display = data.devices.length ? 'none' : '';

    const hosts = data.devices.map(d => d.host);
    const ch = $('channels');
    ch.innerHTML = data.channels.map((c, i) => `<tr>
        <td>${CAN_EDIT
              ? `<select onchange="data.channels[${i}].device = this.value; touch()">
                   ${hosts.map(h => `<option${h === c.device ? ' selected' : ''}>${esc(h)}</option>`).join('')}
                 </select>`
              : ro(c.device)}</td>
        <td>${field('channels', i, 'mhz', c.mhz, 'text', 'change')}<div class="derived">${esc(c.id || '')}</div></td>
        <td>${field('channels', i, 'label', c.label)}</td>
        <td>${field('channels', i, 'serial', c.serial)}</td>
        <td>${CAN_EDIT
              ? `<input type="text" value="${c.squelch ? esc(c.squelch) : ''}" placeholder="auto"
                        data-k="channels.${i}.squelch"
                        oninput="data.channels[${i}].squelch = this.value"
                        onchange="touch()">`
              : ro(c.squelch ? c.squelch : 'auto')}</td>
        <td>${calibrationCell(c)}</td>
        <td>${CAN_EDIT
              ? `<select onchange="data.channels[${i}].model = this.value; touch()">
                   ${MODELS.map(m => `<option value="${m.file}"${m.file === c.model ? ' selected' : ''}>${m.name}</option>`).join('')}
                 </select>`
              : ro((MODELS.find(m => m.file === c.model) || {}).name || c.model)}</td>
        <td>${CAN_EDIT
              ? `<input type="checkbox" ${c.enabled ? 'checked' : ''}
                        onchange="data.channels[${i}].enabled = this.checked; touch()">`
              : ro(c.enabled ? 'On' : 'Off')}</td>
        <td>${tokenCell(c, 'channel', c.id)}</td>
        <td>${CAN_EDIT ? `<button class="row-btn danger" onclick="delChannel(${i})">Remove</button>` : ''}</td>
    </tr>`).join('');
    $('channels-empty').style.display = data.channels.length ? 'none' : '';

    renderVocabulary();
}

function delDevice(i) {
    const host = data.devices[i].host;
    const using = data.channels.filter(c => c.device === host).length;
    if (using && !confirm(`${host} still has ${using} channel(s). Remove it anyway?`)) return;
    data.devices.splice(i, 1); render(); touch();
}
function delChannel(i) {
    if (!confirm(`Remove ${data.channels[i].id}?\n\nIt stops logging at that device's next update.`)) return;
    data.channels.splice(i, 1); render(); touch();
}

if (CAN_EDIT) {
    $('add-device').onclick = () => {
        data.devices.push({host: '', has_token: false}); render(); touch();
    };
    $('add-channel').onclick = () => {
        data.channels.push({id: '', device: data.devices[0]?.host || '', label: '',
                            mhz: '', serial: '', model: MODELS[0].file,
                            enabled: true, has_token: false});
        render(); touch();
    };
}

load();
</script>
</body>
</html>
