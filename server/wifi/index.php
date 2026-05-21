<?php
/**
 * iGate WiFi Manager — marsaprs.org/igate/wifi/
 *
 * Manages the fleet-wide wifi.yaml served to all iGates.
 * Auth shared with the admin panel (same session, same password file).
 *
 * Endpoints (query-string routed):
 *   (none)   GET  — login form or main UI
 *   ?load    GET  — return wifi.yaml entries as JSON (auth required)
 *   ?save    POST — JSON {entries:[...]}, write wifi.yaml (auth required)
 *   ?wpa     POST — JSON {ssid, password} → run wpa_passphrase, return PSK (auth required)
 *   ?logout  GET  — destroy session, redirect to login
 *
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */
ini_set('display_errors', '0');
ini_set('session.gc_maxlifetime', 43200);
session_start();

$dataFile     = __DIR__ . '/wifi.yaml';
$passwordFile = __DIR__ . '/../../admin/password.txt';

/* ── YAML parser / writer ──────────────────────────────────────────────────── */

function wifiLoad(): array {
    global $dataFile;
    if (!file_exists($dataFile)) return [];
    $out = [];
    $cur = null;
    foreach (file($dataFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^- name:\s*(.*)$/', $line, $m)) {
            if ($cur !== null) $out[] = $cur;
            $cur = ['name' => yval($m[1]), 'ssid' => '', 'password' => '', 'encrypted' => ''];
        } elseif ($cur !== null && preg_match('/^\s+(ssid|password|encrypted):\s*(.*)$/', $line, $m)) {
            $cur[$m[1]] = yval($m[2]);
        }
    }
    if ($cur !== null) $out[] = $cur;
    return $out;
}

function yval(string $s): string {
    $s = trim($s);
    if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
        $s = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($s, 1, -1));
    }
    return $s;
}

function wifiSave(array $entries): void {
    global $dataFile;
    $lines = [];
    foreach ($entries as $e) {
        $lines[] = '- name: "'      . addcslashes($e['name']      ?? '', '"\\') . '"';
        $lines[] = '  ssid: "'      . addcslashes($e['ssid']      ?? '', '"\\') . '"';
        $lines[] = '  password: "'  . addcslashes($e['password']  ?? '', '"\\') . '"';
        $lines[] = '  encrypted: "' . addcslashes($e['encrypted'] ?? '', '"\\') . '"';
    }
    file_put_contents($dataFile, implode("\n", $lines) . "\n", LOCK_EX);
}

function sanitize(array $e): array {
    return [
        'name'      => substr(trim($e['name']      ?? ''), 0, 255),
        'ssid'      => substr(trim($e['ssid']      ?? ''), 0, 255),
        'password'  => substr(trim($e['password']  ?? ''), 0, 255),
        'encrypted' => substr(trim($e['encrypted'] ?? ''), 0, 255),
    ];
}

/* ── JSON response helper ──────────────────────────────────────────────────── */

function jsonOut($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/* ── Auth ──────────────────────────────────────────────────────────────────── */

$storedPass = file_exists($passwordFile) ? trim(file_get_contents($passwordFile)) : '';
$authed = !empty($_SESSION['aprs_admin_authed']) || !empty($_SESSION['stats_auth']);

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$loginError = '';
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    if ($storedPass !== '' && $_POST['pw'] === $storedPass) {
        $_SESSION['aprs_admin_authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Incorrect password';
}

if (!$authed) {
    if (isset($_GET['load']) || isset($_GET['save']) || isset($_GET['wpa']))
        jsonOut(['error' => 'Unauthorized'], 401);
    showLogin($loginError);
    exit;
}

/* ── API ───────────────────────────────────────────────────────────────────── */

if (isset($_GET['load'])) {
    jsonOut(['entries' => wifiLoad()]);
}

if (isset($_GET['save']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body['entries'] ?? null)) jsonOut(['error' => 'Invalid request body'], 400);
    wifiSave(array_map('sanitize', $body['entries']));
    jsonOut(['ok' => true, 'count' => count($body['entries'])]);
}

if (isset($_GET['wpa']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $ssid = trim($body['ssid'] ?? '');
    $pw   = trim($body['password'] ?? '');
    if ($ssid === '' || $pw === '') jsonOut(['error' => 'ssid and password are required'], 400);
    $out = shell_exec('wpa_passphrase ' . escapeshellarg($ssid) . ' ' . escapeshellarg($pw) . ' 2>&1');
    if (preg_match('/^\s*psk=([a-f0-9]{64})\s*$/m', $out, $m)) {
        jsonOut(['psk' => $m[1]]);
    } else {
        jsonOut(['error' => 'wpa_passphrase failed: ' . trim($out)], 500);
    }
}

/* ── Login page ────────────────────────────────────────────────────────────── */

function showLogin(string $err): never { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>iGate WiFi Manager</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    background: #f3f4f6; color: #111827; font-size: 15px;
    display: flex; align-items: center; justify-content: center; min-height: 100vh;
}
.card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 2rem; width: 340px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
h1 { font-size: 1.15rem; font-weight: 700; margin-bottom: 1.5rem; color: #111827; }
label { font-size: .75rem; font-weight: 600; color: #6b7280; display: block;
        margin-bottom: .35rem; text-transform: uppercase; letter-spacing: .04em; }
input[type=password] {
    width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: .95rem; color: #111827; background: #fff;
}
input[type=password]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
button {
    width: 100%; padding: .55rem; margin-top: .85rem; border: none; border-radius: 6px;
    background: #2563eb; color: #fff; font-size: .95rem; font-weight: 500; cursor: pointer;
}
button:hover { background: #1d4ed8; }
.err { color: #dc2626; font-size: .85rem; margin-top: .6rem; }
</style>
</head>
<body>
<div class="card">
  <h1>iGate WiFi Manager</h1>
  <form method="POST">
    <label for="pw">Password</label>
    <input type="password" id="pw" name="pw" autofocus required>
    <button type="submit">Sign in</button>
    <?php if ($err) echo '<p class="err">'.htmlspecialchars($err).'</p>' ?>
  </form>
</div>
</body>
</html>
<?php exit; }

/* ── Main UI ───────────────────────────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>iGate WiFi Manager</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    background: #f3f4f6; color: #111827; font-size: 15px; min-height: 100vh;
}

/* ── Header ── */
header {
    background: #fff; padding: 10px 20px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    border-bottom: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    position: sticky; top: 0; z-index: 20;
}
header h1 { font-size: 17px; font-weight: 700; color: #111827; margin-right: auto; white-space: nowrap; }

#status { font-size: 13px; color: #9ca3af; }
#status.saving { color: #2563eb; }
#status.saved  { color: #16a34a; }
#status.error  { color: #dc2626; }

.hdr-btn {
    background: #f9fafb; border: 1px solid #d1d5db; color: #374151;
    padding: 5px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;
    font-family: inherit; white-space: nowrap; text-decoration: none; display: inline-block;
}
.hdr-btn:hover { background: #e5e7eb; }
.hdr-btn-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.hdr-btn-primary:hover { background: #1d4ed8; }

/* ── Main ── */
main { padding: 16px 20px; }
#count { font-size: 13px; color: #9ca3af; margin-bottom: 10px; }

/* ── Table card ── */
.table-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; font-size: 14px; }

thead th {
    font-size: 12px; font-weight: 600; color: #9ca3af; text-align: left;
    padding: 9px 10px; text-transform: uppercase; letter-spacing: .05em;
    background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr { border-bottom: 1px solid #f3f4f6; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover:not(.editing) td { background: #fafafa; }
tbody tr.editing td { background: #eff6ff; }
tbody tr.drag-over { outline: 2px solid #2563eb; }

td { padding: 9px 10px; vertical-align: middle; }

/* ── Columns ── */
.col-drag {
    width: 28px; text-align: center; color: #d1d5db;
    cursor: grab; user-select: none; font-size: 16px;
}
.col-drag:active { cursor: grabbing; }
.col-name { min-width: 160px; font-weight: 500; color: #111827; }
.col-ssid { min-width: 130px; color: #374151; }
.col-pw   { min-width: 130px; color: #374151; }
.col-enc  {
    min-width: 160px; font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
    font-size: 12px; color: #9ca3af;
}
.col-act  { width: 100px; white-space: nowrap; text-align: right; }

.none { color: #d1d5db; font-style: italic; }

/* ── Row action buttons ── */
.btn {
    background: #f9fafb; border: 1px solid #d1d5db; color: #374151;
    padding: 3px 10px; border-radius: 5px; cursor: pointer; font-size: 12px;
    font-family: inherit; white-space: nowrap;
}
.btn:hover   { background: #e5e7eb; }
.btn:disabled { opacity: .4; cursor: default; }
.btn-danger  { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
.btn-danger:hover { background: #fee2e2; }
.btn-ok      { color: #fff; background: #2563eb; border-color: #2563eb; }
.btn-ok:hover { background: #1d4ed8; }
.btn-cancel  { color: #6b7280; }

/* ── Edit inputs ── */
td input[type=text] {
    width: 100%; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 5px;
    font-size: 13px; font-family: inherit; color: #111827; background: #fff;
}
td input[type=text]:focus {
    outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

/* ── Loading ── */
#loading { padding: 2rem; text-align: center; color: #9ca3af; }
</style>
</head>
<body>

<header>
  <h1>iGate WiFi Manager</h1>
  <span id="status"></span>
  <button class="hdr-btn hdr-btn-primary" id="btn-add">+ Add Network</button>
  <a href="javascript:history.back()" class="hdr-btn">← Back</a>
  <a href="?logout" class="hdr-btn">Sign out</a>
</header>

<main>
  <div id="count"></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th class="col-drag"></th>
          <th class="col-name">Name</th>
          <th class="col-ssid">SSID</th>
          <th class="col-pw">Password</th>
          <th class="col-enc">PSK Hash</th>
          <th class="col-act"></th>
        </tr>
      </thead>
      <tbody id="tbody"></tbody>
    </table>
    <div id="loading">Loading…</div>
  </div>
</main>

<script>
'use strict';

let entries    = [];
let editingIdx = -1;
let dragSrcIdx = -1;
let saving     = false;
let pendingSave = false;

const tbody   = document.getElementById('tbody');
const btnAdd  = document.getElementById('btn-add');
const statusEl = document.getElementById('status');
const countEl  = document.getElementById('count');
const loading  = document.getElementById('loading');

/* ── Status ── */

function setStatus(msg, cls) {
    statusEl.textContent = msg;
    statusEl.className = cls || '';
}

/* ── Auto-save ── */

async function autoSave() {
    if (saving) { pendingSave = true; return; }
    saving = true;
    pendingSave = false;
    setStatus('Saving…', 'saving');
    try {
        const resp = await fetch('?save', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({entries}),
        });
        if (resp.status === 401) { window.location.reload(); return; }
        const data = await resp.json();
        if (!resp.ok || data.error) throw new Error(data.error || 'Server error');
        setStatus('Saved', 'saved');
        setTimeout(() => { if (!saving) setStatus('', ''); }, 2000);
    } catch(err) {
        setStatus('Error: ' + err.message, 'error');
    } finally {
        saving = false;
        if (pendingSave) autoSave();
    }
}

/* ── Render ── */

function render() {
    if (editingIdx >= 0) commitEdit(editingIdx, false);
    tbody.innerHTML = '';
    entries.forEach((e, i) => tbody.appendChild(makeRow(e, i)));
    countEl.textContent = entries.length + ' network' + (entries.length !== 1 ? 's' : '');
    loading.style.display = 'none';
}

function makeRow(e, i) {
    const tr = document.createElement('tr');
    tr.dataset.idx = i;
    tr.draggable = true;

    const enc = e.encrypted || '';
    const encShort = enc.length > 12 ? enc.slice(0, 12) + '…' : enc;

    tr.innerHTML = `
      <td class="col-drag" title="Drag to reorder">⠿</td>
      <td class="col-name">${esc(e.name) || '<span class="none">unnamed</span>'}</td>
      <td class="col-ssid">${esc(e.ssid) || '<span class="none">—</span>'}</td>
      <td class="col-pw">${e.password ? esc(e.password) : '<span class="none">none</span>'}</td>
      <td class="col-enc" title="${esc(enc)}">${esc(encShort) || '<span class="none">—</span>'}</td>
      <td class="col-act">
        <button class="btn" onclick="startEdit(${i})">Edit</button>
        <button class="btn btn-danger" onclick="deleteRow(${i})">Delete</button>
      </td>`;

    tr.addEventListener('dragstart', () => { dragSrcIdx = i; tr.style.opacity = '.4'; });
    tr.addEventListener('dragend',   () => { tr.style.opacity = ''; clearDragOver(); });
    tr.addEventListener('dragover',  ev => { ev.preventDefault(); clearDragOver(); tr.classList.add('drag-over'); });
    tr.addEventListener('drop', ev => {
        ev.preventDefault();
        if (dragSrcIdx !== -1 && dragSrcIdx !== i) {
            const moved = entries.splice(dragSrcIdx, 1)[0];
            entries.splice(i, 0, moved);
            dragSrcIdx = -1;
            render();
            autoSave();
        }
    });

    return tr;
}

function clearDragOver() {
    document.querySelectorAll('tr.drag-over').forEach(r => r.classList.remove('drag-over'));
}

/* ── Edit ── */

function startEdit(i) {
    if (editingIdx >= 0 && editingIdx !== i) commitEdit(editingIdx, false);
    editingIdx = i;
    const e = entries[i];
    const tr = tbody.querySelector(`tr[data-idx="${i}"]`);
    tr.draggable = false;
    tr.classList.add('editing');

    tr.innerHTML = `
      <td class="col-drag" style="cursor:default;color:#e5e7eb">⠿</td>
      <td><input type="text" name="name"     value="${esc(e.name)}"     placeholder="Name"></td>
      <td><input type="text" name="ssid"     value="${esc(e.ssid)}"     placeholder="SSID"></td>
      <td><input type="text" name="password" value="${esc(e.password)}" placeholder="Password"></td>
      <td class="col-enc" style="color:#9ca3af;font-size:12px">
        ${e.encrypted ? esc(e.encrypted.slice(0, 12)) + '…' : '<span style="color:#d1d5db">computed on save</span>'}
      </td>
      <td class="col-act">
        <button class="btn btn-ok" id="ok-${i}" onclick="commitEdit(${i}, true)">OK</button>
        <button class="btn btn-cancel" onclick="cancelEdit(${i})">Cancel</button>
      </td>`;

    tr.querySelector('input[name=name]').focus();
}

async function commitEdit(i, save) {
    const tr = tbody.querySelector(`tr[data-idx="${i}"]`);
    if (!tr) { editingIdx = -1; return; }

    const get = n => tr.querySelector(`input[name=${n}]`)?.value.trim() ?? '';
    const name     = get('name');
    const ssid     = get('ssid');
    const password = get('password');
    let encrypted  = entries[i]?.encrypted ?? '';

    if (save && password && ssid) {
        const okBtn = document.getElementById(`ok-${i}`);
        if (okBtn) { okBtn.disabled = true; okBtn.textContent = '…'; }
        try {
            const resp = await fetch('?wpa', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({ssid, password}),
            });
            if (resp.status === 401) { window.location.reload(); return; }
            const data = await resp.json();
            if (data.psk) encrypted = data.psk;
            else { alert('PSK error: ' + (data.error || 'unknown')); if (okBtn) { okBtn.disabled = false; okBtn.textContent = 'OK'; } return; }
        } catch(err) {
            alert('PSK failed: ' + err.message);
            if (okBtn) { okBtn.disabled = false; okBtn.textContent = 'OK'; }
            return;
        }
    }

    entries[i] = {name, ssid, password, encrypted};
    editingIdx = -1;
    render();
    if (save) await autoSave();
}

function cancelEdit(i) {
    editingIdx = -1;
    const e = entries[i];
    if (!e.name && !e.ssid && !e.password && !e.encrypted) {
        entries.splice(i, 1);
    }
    render();
}

/* ── Add ── */

btnAdd.addEventListener('click', () => {
    entries.push({name: '', ssid: '', password: '', encrypted: ''});
    render();
    startEdit(entries.length - 1);
    tbody.lastElementChild?.scrollIntoView({block: 'nearest'});
});

/* ── Delete ── */

function deleteRow(i) {
    if (!confirm(`Delete "${entries[i].name || entries[i].ssid || 'this entry'}"?`)) return;
    if (editingIdx === i) editingIdx = -1;
    entries.splice(i, 1);
    render();
    autoSave();
}

/* ── HTML escape ── */

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ── Init ── */

(async () => {
    try {
        const resp = await fetch('?load', {credentials: 'same-origin'});
        if (resp.status === 401) { window.location.reload(); return; }
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        entries = data.entries || [];
        render();
    } catch(err) {
        loading.textContent = 'Failed to load: ' + err.message;
    }
})();
</script>
</body>
</html>
