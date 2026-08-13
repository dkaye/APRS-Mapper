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
 *   (none)    GET  — the UI
 *   ?load     GET  — devices + channels as JSON (tokens redacted)
 *   ?save     POST — {devices:[…], channels:[…]}, write the registry
 *   ?rotate   POST — {kind:'device'|'channel', id} → issue a fresh token, return it once
 *   ?logout   GET  — end the session
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
            'note'  => substr(trim($d['note'] ?? ''), 0, 120),
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

    transcriber_save(['devices' => $devices, 'channels' => $channels]);
    jsonOut(['ok' => true, 'devices' => count($devices), 'channels' => count($channels)]);
}

// Polled by the page while it waits for the fleet to check in. Deliberately cheap: two
// small files and a hash per device, no writes.
if (isset($_GET['status'])) {
    jsonOut(['now' => time(), 'devices' => transcriber_device_status()]);
}

if (isset($_GET['update']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    jsonOut(['ok' => true, 'requested' => transcriber_request_update()]);
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
      <thead><tr><th>Host</th><th>Where it is</th><th>Config token</th><th></th></tr></thead>
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
        <br>Leave it <strong>blank</strong> and each channel measures its own site on
        first start and remembers the answer — right for almost everywhere. Put a number
        in only when you have a reason: raise it (30, 40) if the log fills with noise,
        lower it if weak stations are being missed. Roughly 0–100.</td></tr>
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
        <th>Squelch</th><th>Accuracy</th><th>On</th><th>Log token</th><th></th>
      </tr></thead>
      <tbody id="channels"></tbody>
    </table>
    <div class="empty" id="channels-empty">No channels yet.</div>
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
let data = {devices: [], channels: []};

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
async function waitForDevices(ready, what) {
    clearInterval(waitTimer);
    const deadline = Date.now() + 75000;
    $('spinner').hidden = false;
    status(what + ' pending…', 'saving');

    const tick = async () => {
        let d;
        try { d = await (await fetch('?status')).json(); } catch { return; }
        const waiting = (d.devices || []).filter(x => !ready(x, d.now));
        if (!waiting.length) {
            clearInterval(waitTimer); $('spinner').hidden = true;
            status(what + ' applied', 'saved');
            return;
        }
        if (Date.now() > deadline) {
            clearInterval(waitTimer); $('spinner').hidden = true;
            status('No response from ' + waiting.map(x => x.host).join(', '), 'error');
            notice(what + ' not confirmed',
                   'These Transcribers have not checked in: '
                 + waiting.map(x => x.host).join(', ') + '.\n\n'
                 + 'The change is saved and they will collect it as soon as they are back. '
                 + 'A device that is switched off, off the network, or has the wrong '
                 + 'config token will look exactly like this.');
            return;
        }
        status(what + ' pending — waiting for ' + waiting.map(x => x.host).join(', '), 'saving');
    };
    waitTimer = setInterval(tick, 2000);
    tick();
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
        <td>${field('devices', i, 'note', d.note)}</td>
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
        data.devices.push({host: '', note: '', has_token: false}); render(); touch();
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
