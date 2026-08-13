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
    jsonOut(redact(transcriber_load()));
}

if (isset($_GET['save']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) jsonOut(['error' => 'Missing permission: netbird.admin'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body['devices'] ?? null) || !is_array($body['channels'] ?? null)) {
        jsonOut(['error' => 'Invalid request body'], 400);
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
        // No '@': it is systemd's instance separator, and the id names the unit
        // (transcriber@rx1-146520.service).
        $id = substr(preg_replace('/[^A-Za-z0-9_.-]/', '-', trim($c['id'] ?? '')), 0, 64);
        if ($id === '' || isset($seen[$id])) continue;   // ids are unit names; must be unique
        $seen[$id] = true;
        $channels[] = [
            'id'        => $id,
            'device'    => substr(trim($c['device'] ?? ''), 0, 64),
            'label'     => substr(trim($c['label'] ?? ''), 0, 40) ?: $id,
            'frequency' => transcriber_hz($c['frequency'] ?? ''),
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
input:disabled, select:disabled { background: #f9fafb; color: #6b7280; }
.tok { font-size: 12px; white-space: nowrap; }
.tok.set { color: #16a34a; } .tok.unset { color: #dc2626; font-weight: 600; }
.row-btn { background: none; border: none; cursor: pointer; font-size: 13px; color: #6b7280;
           padding: 3px 6px; border-radius: 4px; font-family: inherit; }
.row-btn:hover { background: #f3f4f6; color: #111827; }
.row-btn.danger:hover { background: #fee2e2; color: #b91c1c; }
.empty { padding: 22px; text-align: center; color: #9ca3af; font-size: 14px; }
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
  <span id="status"></span>
  <?php if ($canEdit): ?>
    <button class="hdr-btn" id="add-device">+ Device</button>
    <button class="hdr-btn hdr-btn-primary" id="add-channel">+ Channel</button>
  <?php endif; ?>
  <a class="hdr-btn" href="/netbird/">Devices</a>
  <a class="hdr-btn" href="?logout">Sign out</a>
</header>

<main>
  <h2>Devices</h2>
  <p class="hint">One row per Transcriber Pi. <strong>Host</strong> must match its
     <code>hostname</code> — that is what it identifies itself with when it fetches its
     channels. Put the token in <code>/home/pi/.transcriber-token</code> on that Pi.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Host</th><th>Note</th><th>Config token</th><th></th></tr></thead>
      <tbody id="devices"></tbody>
    </table>
    <div class="empty" id="devices-empty">No Transcribers yet.</div>
  </div>

  <h2>Channels</h2>
  <p class="hint">One row per frequency. <strong>ID</strong> names the systemd unit
     (<code>transcriber@rx1-146520</code>) and is the author shown in the event log, so it
     must be unique across the fleet. <strong>Frequency</strong> may be written either way — <code>147.465</code> or <code>147465000</code>; it is stored in Hz and echoed back so you can see how it was read. <strong>Serial</strong> is the dongle's USB serial,
     not its index — index order is not stable across reboots, and two channels swapping
     frequencies is the kind of fault nobody notices until the log is already wrong. Set
     them with <code>rtl_eeprom -d 0 -s 00000001</code>.</p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>ID</th><th>Device</th><th>Label</th><th>Frequency</th><th>Serial</th>
        <th>Squelch</th><th>Model</th><th>On</th><th>Log token</th><th></th>
      </tr></thead>
      <tbody id="channels"></tbody>
    </table>
    <div class="empty" id="channels-empty">No channels yet.</div>
  </div>
</main>

<div id="tokenbox"><div class="card">
  <strong id="tok-title">New token</strong>
  <p class="hint">Copy it now — it is never shown again.</p>
  <code id="tok-value"></code>
  <button class="hdr-btn hdr-btn-primary" onclick="document.getElementById('tokenbox').classList.remove('open')">Done</button>
</div></div>

<script>
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
const MODELS = ['ggml-tiny.en.bin', 'ggml-base.en.bin'];
let data = {devices: [], channels: []};

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function status(text, cls) { const s = $('status'); s.textContent = text; s.className = cls || ''; }

async function load() {
    const r = await fetch('?load');
    data = await r.json();
    render();
}

// Auto-saves on every change, like the WiFi Manager — there is no save button to forget.
let saveTimer = null;
function save() {
    if (!CAN_EDIT) return;
    clearTimeout(saveTimer);
    status('Saving…', 'saving');
    saveTimer = setTimeout(async () => {
        try {
            const r = await fetch('?save', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data),
            });
            const d = await r.json();
            if (d.error) { status(d.error, 'error'); return; }
            status('Saved', 'saved');
            // Re-read: the server issues tokens for new rows and normalises ids, so the
            // browser must not keep believing what it sent.
            await load();
        } catch { status('Save failed', 'error'); }
    }, 400);
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

function field(group, i, key, value, type) {
    const dis = CAN_EDIT ? '' : ' disabled';
    return `<input type="${type || 'text'}" value="${esc(value)}"${dis}
             oninput="data.${group}[${i}].${key} = this.value; save()">`;
}

function render() {
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
        <td>${field('channels', i, 'id', c.id)}</td>
        <td><select ${CAN_EDIT ? '' : 'disabled'} onchange="data.channels[${i}].device = this.value; save()">
              ${hosts.map(h => `<option${h === c.device ? ' selected' : ''}>${esc(h)}</option>`).join('')}
            </select></td>
        <td>${field('channels', i, 'label', c.label)}</td>
        <td>${field('channels', i, 'frequency', c.frequency)}</td>
        <td>${field('channels', i, 'serial', c.serial)}</td>
        <td>${field('channels', i, 'squelch', c.squelch, 'number')}</td>
        <td><select ${CAN_EDIT ? '' : 'disabled'} onchange="data.channels[${i}].model = this.value; save()">
              ${MODELS.map(m => `<option value="${m}"${m === c.model ? ' selected' : ''}>${m.replace('ggml-','').replace('.bin','')}</option>`).join('')}
            </select></td>
        <td><input type="checkbox" ${c.enabled ? 'checked' : ''} ${CAN_EDIT ? '' : 'disabled'}
                   onchange="data.channels[${i}].enabled = this.checked; save()"></td>
        <td>${tokenCell(c, 'channel', c.id)}</td>
        <td>${CAN_EDIT ? `<button class="row-btn danger" onclick="delChannel(${i})">Remove</button>` : ''}</td>
    </tr>`).join('');
    $('channels-empty').style.display = data.channels.length ? 'none' : '';
}

function delDevice(i) {
    const host = data.devices[i].host;
    const using = data.channels.filter(c => c.device === host).length;
    if (using && !confirm(`${host} still has ${using} channel(s). Remove it anyway?`)) return;
    data.devices.splice(i, 1); render(); save();
}
function delChannel(i) {
    if (!confirm(`Remove ${data.channels[i].id}?\n\nIt stops logging at that device's next update.`)) return;
    data.channels.splice(i, 1); render(); save();
}

if (CAN_EDIT) {
    $('add-device').onclick = () => {
        data.devices.push({host: '', note: '', has_token: false}); render(); save();
    };
    $('add-channel').onclick = () => {
        data.channels.push({id: '', device: data.devices[0]?.host || '', label: '',
                            frequency: '', serial: '', squelch: 0,
                            model: MODELS[0], enabled: true, has_token: false});
        render(); save();
    };
}

load();
</script>
</body>
</html>
