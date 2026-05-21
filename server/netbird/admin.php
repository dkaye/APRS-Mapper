<?php
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$passFile   = '/var/www/html/admin/password.txt';
$loginError = '';

if (isset($_POST['password'])) {
    $stored = trim((string)@file_get_contents($passFile));
    if ($stored !== '' && $_POST['password'] === $stored) {
        $_SESSION['stats_auth']        = true;
        $_SESSION['aprs_admin_authed'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = 'Incorrect password';
}

if (empty($_SESSION['stats_auth']) && empty($_SESSION['aprs_admin_authed'])) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MARS APRS NetBird — Sign In</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:300px}
h1{font-size:17px;font-weight:700;color:#111827;margin-bottom:4px}
p{font-size:13px;color:#6b7280;margin-bottom:22px}
input{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:15px;color:#111827;background:#f9fafb;margin-bottom:12px}
input:focus{outline:none;border-color:#2563eb;background:#fff}
button{width:100%;padding:9px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer}
button:hover{background:#1d4ed8}
.err{color:#dc2626;font-size:13px;margin-top:8px}
</style>
</head>
<body>
<div class="box">
  <h1>MARS APRS NetBird — Admin</h1>
  <p>Sign in to continue</p>
  <form method="post">
    <input type="password" name="password" placeholder="Password" autofocus autocomplete="current-password">
    <button type="submit">Sign In</button>
    <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  </form>
</div>
</body>
</html>
<?php exit; }

require_once __DIR__ . '/yaml_lib.php';
$devices   = loadDevices(__DIR__ . '/addresses.yaml');
$allGroups = array_values(array_unique(array_column($devices, 'group')));

// Merge live status from daemon stats
$onlineMap       = [];
$enabledAtMap    = [];
$lastRequestMap  = [];
$lastResponseMap = [];
$statsRaw = @file_get_contents(__DIR__ . '/stats.json');
if ($statsRaw) {
    foreach ((json_decode($statsRaw, true)['devices'] ?? []) as $sd) {
        $onlineMap[$sd['ip']]       = !empty($sd['online']);
        $enabledAtMap[$sd['ip']]    = $sd['enabled_at']    ?? null;
        $lastRequestMap[$sd['ip']]  = $sd['last_request']  ?? null;
        $lastResponseMap[$sd['ip']] = $sd['last_response'] ?? null;
    }
}
foreach ($devices as &$d) {
    $d['online']        = $onlineMap[$d['ip']]       ?? false;
    $d['enabled_at']    = $enabledAtMap[$d['ip']]    ?? null;
    $d['last_request']  = $lastRequestMap[$d['ip']]  ?? null;
    $d['last_response'] = $lastResponseMap[$d['ip']] ?? null;
}
unset($d);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS NetBird — Admin</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;color:#111827;font-size:16px;min-height:100vh}

header{background:#fff;padding:10px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);position:sticky;top:0;z-index:20}
header h1{font-size:18px;font-weight:700;color:#111827;margin-right:auto}
.hdr-btn{background:#f9fafb;border:1px solid #d1d5db;color:#374151;padding:5px 12px;border-radius:5px;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block}
.hdr-btn:hover{background:#e5e7eb;color:#111827}
.btn-add{background:#2563eb;border:none;color:#fff;padding:5px 14px;border-radius:5px;cursor:pointer;font-size:13px;font-weight:500}
.btn-add:hover{background:#1d4ed8}

main{padding:16px 20px}
.table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:auto;display:inline-block;min-width:300px}
table{border-collapse:collapse;table-layout:fixed}
thead th{font-size:12px;font-weight:600;color:#9ca3af;text-align:left;padding:8px 10px;text-transform:uppercase;letter-spacing:.5px;background:#f9fafb;border-bottom:1px solid #e5e7eb;white-space:nowrap}
tr.group-row td{padding:10px 10px 4px;font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;background:#f9fafb;border-top:1px solid #e5e7eb}
tr.group-row:first-child td{border-top:none}
tr.device-row td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tr.device-row:last-child td{border-bottom:none}
tr.device-row:hover td{background:#fafafa}
tr.device-row.disabled td:not(.toggle-cell):not(.action-cell){opacity:.4}
.mono{font-family:'SF Mono',SFMono-Regular,Consolas,monospace;font-size:13px;color:#6b7280}

/* Toggle */
.toggle-cell{text-align:center}
.toggle{position:relative;display:inline-block;width:36px;height:20px;cursor:pointer;vertical-align:middle}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle .slider{position:absolute;inset:0;background:#d1d5db;border-radius:20px;transition:.2s}
.toggle .slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked+.slider{background:#22c55e}
.toggle input:checked+.slider::before{transform:translateX(16px)}

/* Action buttons */
.action-btns{display:flex;gap:5px}
.btn-edit{background:#f3f4f6;border:1px solid #d1d5db;color:#374151;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap}
.btn-edit:hover{background:#e5e7eb}
.btn-del{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap}
.btn-del:hover{background:#fee2e2}
.btn-ssh,.btn-web{background:#f3f4f6;border:1px solid #d1d5db;color:#374151;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap}
.btn-ssh:not(:disabled):hover,.btn-web:not(:disabled):hover{background:#e5e7eb}
.btn-ssh:disabled,.btn-web:disabled{opacity:.35;cursor:default}

/* Status cell — identical colours to main page */
.status-cell{display:flex;align-items:center;gap:6px}
.pulse{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.status-label{font-size:13px;font-weight:400;white-space:nowrap}
.online  .pulse{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.offline .pulse{background:#ef4444}
.pending .pulse{background:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.2)}
.disabled .pulse{background:#d1d5db}
.online  .status-label{color:#16a34a}
.offline .status-label{color:#dc2626}
.pending .status-label{color:#2563eb}
.disabled .status-label{color:#9ca3af}

/* Modal */
#modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:50;align-items:center;justify-content:center}
#modal-backdrop.open{display:flex}
#modal{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 20px 40px rgba(0,0,0,.15);width:min(460px,96vw);display:flex;flex-direction:column}
.modal-header{padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.modal-header h2{font-size:16px;font-weight:600;color:#111827}
.modal-close{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:20px;line-height:1;padding:2px 6px;border-radius:4px}
.modal-close:hover{background:#f3f4f6;color:#374151}
.modal-body{padding:20px;display:flex;flex-direction:column;gap:14px}
.form-row label{display:block;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.form-row input[type=text]{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;color:#111827;background:#f9fafb}
.form-row input[type=text]:focus{outline:none;border-color:#2563eb;background:#fff}
.form-row.check{display:flex;align-items:center;gap:10px}
.form-row.check label{margin:0;text-transform:none;font-size:14px;font-weight:400;color:#374151;cursor:pointer;letter-spacing:0}
.form-row input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#2563eb;flex-shrink:0}
.modal-footer{padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;align-items:center;justify-content:flex-end;gap:10px}
#modal-err{font-size:13px;color:#dc2626;padding:0 20px 10px;min-height:0}
.btn-save{background:#2563eb;border:none;color:#fff;padding:7px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500}
.btn-save:hover{background:#1d4ed8}
.btn-save:disabled{opacity:.6;cursor:default}
.btn-cancel{background:#f9fafb;border:1px solid #d1d5db;color:#374151;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:14px}
.btn-cancel:hover{background:#e5e7eb}

/* Copy button */
.btn-copy{background:none;border:none;cursor:pointer;padding:0 0 0 5px;color:#9ca3af;opacity:.55;line-height:1;vertical-align:middle}
.btn-copy:hover{opacity:1;color:#374151}

/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1f2937;color:#f9fafb;padding:6px 16px;border-radius:20px;font-size:13px;opacity:0;pointer-events:none;transition:opacity .15s;z-index:100}
#toast.show{opacity:1}
</style>
</head>
<body>

<header>
  <h1>MARS APRS NetBird — Admin</h1>
  <button class="btn-add" onclick="openModal(null)">+ Add Device</button>
  <a href="index.php" class="hdr-btn">← Back</a>
  <a href="https://marsaprs.org" class="hdr-btn">Map</a>
  <a href="/igate/wifi/" class="hdr-btn">WiFi</a>
  <a href="/userguide.php?back=/netbird/admin.php#netbird-status-monitor" class="hdr-btn">User Guide</a>
  <a href="?logout=1" class="hdr-btn">Sign Out</a>
</header>

<main>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:56px;text-align:center">On</th>
          <th style="width:90px">Status</th>
          <th style="width:50px;text-align:center">Web</th>
          <th style="width:160px">Name</th>
          <th style="width:105px">Host</th>
          <th style="width:135px">IP Address</th>
          <th style="width:185px">Actions</th>
        </tr>
      </thead>
      <tbody id="tbody"></tbody>
    </table>
  </div>
</main>

<div id="modal-backdrop">
  <div id="modal">
    <div class="modal-header">
      <h2 id="modal-title">Add Device</h2>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="orig-ip">
      <div class="form-row">
        <label>Name</label>
        <input type="text" id="f-name" placeholder="e.g. Muir Woods">
      </div>
      <div class="form-row">
        <label>Host (Callsign)</label>
        <input type="text" id="f-host" placeholder="e.g. K6DRK-10 — leave blank if none">
      </div>
      <div class="form-row">
        <label>IP Address</label>
        <input type="text" id="f-ip" placeholder="100.101.x.y">
      </div>
      <div class="form-row">
        <label>Group</label>
        <input type="text" id="f-group" list="group-list" placeholder="Section heading">
        <datalist id="group-list"></datalist>
      </div>
      <div class="form-row check">
        <input type="checkbox" id="f-enabled" checked>
        <label for="f-enabled">Enabled (include in polling)</label>
      </div>
    </div>
    <div id="modal-err"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-save" id="save-btn" onclick="saveDevice()">Save</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';
let devices = <?= json_encode(array_values($devices), JSON_HEX_TAG) ?>;
const GROUPS = <?= json_encode($allGroups) ?>;

// ── State ─────────────────────────────────────────────────────────────────────
const pendingIPs = new Set();
let lastSendTs = null, inActiveWindow = false;
let rapidPollTimer = null, windowExpiryTimer = null, backgroundTimer = null;
const WINDOW_MS = 10000, RAPID_MS = 1000, BG_MS = 8000;

// ── Status helper (identical to index.php) ───────────────────────────────────
function getStatus(d) {
    if (!d.enabled)            return ['disabled', 'Disabled'];
    if (pendingIPs.has(d.ip))  return ['pending',  'Pending'];
    if (d.online)              return ['online',   'Online'];
    return ['offline', 'Offline'];
}

// ── Active polling window ─────────────────────────────────────────────────────
function startActiveWindow() {
    clearInterval(backgroundTimer); backgroundTimer = null;
    clearTimeout(windowExpiryTimer);
    if (!inActiveWindow) {
        inActiveWindow = true;
        clearInterval(rapidPollTimer);
        rapidPollTimer = setInterval(doFetch, RAPID_MS);
    }
    windowExpiryTimer = setTimeout(expireWindow, WINDOW_MS);
}
function expireWindow() {
    inActiveWindow = false;
    clearInterval(rapidPollTimer); rapidPollTimer = null;
    pendingIPs.clear();
    patchStatusCells();
    startBgPolling();
}
function startBgPolling() {
    clearInterval(backgroundTimer);
    backgroundTimer = setInterval(doFetch, BG_MS);
}

// ── Cell patching ─────────────────────────────────────────────────────────────
function patchStatusCells() {
    devices.forEach(d => {
        const row = document.querySelector(`#tbody tr.device-row[data-ip="${d.ip}"]`);
        if (!row) return;
        const [sc, sl] = getStatus(d);
        const cell = row.querySelector('.status-cell');
        if (cell) {
            cell.className = 'status-cell ' + sc;
            cell.innerHTML = `<span class="pulse"></span><span class="status-label">${sl}</span>`;
        }
        const sshBtn = row.querySelector('.btn-ssh');
        if (sshBtn) sshBtn.disabled = !d.online || !d.enabled;
        const webBtn = row.querySelector('.btn-web');
        if (webBtn) webBtn.disabled = !d.online || !d.enabled;
    });
}

function doFetch() {
    fetch('api.php?_=' + Date.now())
        .then(r => { if (r.status === 401) { location.reload(); return null; } return r.json(); })
        .then(data => {
            if (!data) return;
            const newSendTs  = data.last_send_ts || null;
            const isNewCycle = newSendTs && lastSendTs !== null && newSendTs !== lastSendTs;
            if (newSendTs) lastSendTs = newSendTs;
            (data.devices || []).forEach(ad => {
                const d = devices.find(x => x.ip === ad.ip);
                if (d) {
                    d.online = ad.online; d.last_request = ad.last_request; d.last_response = ad.last_response;
                    if (d.online) pendingIPs.delete(d.ip);
                }
            });
            if (isNewCycle && !inActiveWindow) startActiveWindow();
            // Detect devices awaiting first poll since being enabled
            const before = pendingIPs.size;
            (data.devices || []).forEach(ad => {
                if (ad.enabled && !ad.online && ad.last_request === null) pendingIPs.add(ad.ip);
            });
            if (pendingIPs.size > before && !inActiveWindow) startActiveWindow();
            patchStatusCells();
        })
        .catch(() => {});
}

const dl = document.getElementById('group-list');
GROUPS.forEach(g => { const o = document.createElement('option'); o.value = g; dl.appendChild(o); });

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderTable() {
    const groupMap = new Map(), groupOrder = [];
    devices.forEach(d => {
        const g = d.group || '';
        if (!groupMap.has(g)) { groupMap.set(g, []); groupOrder.push(g); }
        groupMap.get(g).push(d);
    });

    let html = '';
    groupOrder.forEach(g => {
        if (g) html += `<tr class="group-row"><td colspan="7">${esc(g)}</td></tr>`;
        groupMap.get(g).forEach(d => {
            const safeD = JSON.stringify(d).replace(/</g, '\\u003c').replace(/'/g, '\\u0027');
            const [sc, sl] = getStatus(d);
            html += `<tr class="device-row${d.enabled ? '' : ' disabled'}" data-ip="${esc(d.ip)}">
              <td class="toggle-cell">
                <label class="toggle" title="${d.enabled ? 'Enabled' : 'Disabled'}">
                  <input type="checkbox" ${d.enabled ? 'checked' : ''}
                    onchange="toggleDevice('${esc(d.ip)}', this)">
                  <span class="slider"></span>
                </label>
              </td>
              <td><div class="status-cell ${sc}"><span class="pulse"></span><span class="status-label">${sl}</span></div></td>
              <td style="text-align:center"><input type="checkbox" ${d.web ? 'checked' : ''} onchange="toggleWeb('${esc(d.ip)}', this)" style="width:16px;height:16px;cursor:pointer;accent-color:#2563eb"></td>
              <td>${esc(d.name)}</td>
              <td class="mono">${d.host ? esc(d.host) : '<span style="color:#d1d5db">—</span>'}</td>
              <td class="mono">${esc(d.ip)}<button class="btn-copy" onclick="copyIP('${esc(d.ip)}')" title="Copy IP"><svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H6zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h-1v1H2V6h1V5H2z"/></svg></button></td>
              <td class="action-cell"><div class="action-btns">
                <button class="btn-ssh" ${d.online && d.enabled ? '' : 'disabled'} onclick="openSSH('${esc(d.ip)}')">ssh</button>
                <button class="btn-web" ${d.online && d.enabled ? '' : 'disabled'} onclick="openWeb('${esc(d.ip)}')">Web</button>
                <button class="btn-edit" onclick='openModal(${safeD})'>Edit</button>
                <button class="btn-del" data-ip="${esc(d.ip)}" data-name="${esc(d.name)}" onclick="deleteDevice(this.dataset.ip,this.dataset.name)">Del</button>
              </div></td>
            </tr>`;
        });
    });

    document.getElementById('tbody').innerHTML = html ||
        '<tr><td colspan="7" style="padding:40px;text-align:center;color:#9ca3af">No devices configured.</td></tr>';
}

function toggleDevice(ip, cb) {
    const fd = new FormData();
    fd.append('action', 'toggle_device');
    fd.append('ip', ip);
    fetch('save.php', { method: 'POST', body: fd })
        .then(r => { if (r.status === 401) { location.reload(); return null; } return r.json(); })
        .then(resp => {
            if (!resp) return;
            if (resp.ok) {
                const d = devices.find(x => x.ip === ip);
                if (d) {
                    d.enabled = cb.checked;
                    if (d.enabled) {
                        d.enabled_at    = resp.enabled_at || Math.floor(Date.now() / 1000);
                        d.last_request  = null;
                        d.last_response = null;
                        d.online        = false;
                        pendingIPs.add(ip);
                        renderTable();
                        startActiveWindow();
                    } else {
                        pendingIPs.delete(ip);
                        d.enabled_at = null;
                        d.online     = false;
                        renderTable();
                    }
                    try { const bc = new BroadcastChannel('aprs_netbird'); bc.postMessage({type:'toggle', ip, enabled: d.enabled}); bc.close(); } catch(e) {}
                }
            } else {
                cb.checked = !cb.checked;
                showToast('Error: ' + (resp.error || 'save failed'));
            }
        })
        .catch(() => { cb.checked = !cb.checked; showToast('Network error'); });
}

function toggleWeb(ip, cb) {
    if (cb.checked && devices.filter(x => x.web).length >= 20) {
        cb.checked = false;
        alert('Only 20 iGates can be displayed on the website at once (limit imposed by the aprs.fi API). Uncheck another iGate before enabling this one.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'toggle_web');
    fd.append('ip', ip);
    fetch('save.php', { method: 'POST', body: fd })
        .then(r => { if (r.status === 401) { location.reload(); return null; } return r.json(); })
        .then(resp => {
            if (!resp) return;
            if (resp.ok) {
                const d = devices.find(x => x.ip === ip);
                if (d) d.web = cb.checked;
            } else {
                cb.checked = !cb.checked;
                showToast('Error: ' + (resp.error || 'save failed'));
            }
        })
        .catch(() => { cb.checked = !cb.checked; showToast('Network error'); });
}

function openModal(device) {
    document.getElementById('modal-title').textContent = device ? 'Edit Device' : 'Add Device';
    document.getElementById('orig-ip').value    = device ? device.ip      : '';
    document.getElementById('f-name').value     = device ? device.name    : '';
    document.getElementById('f-host').value     = device ? device.host    : '';
    document.getElementById('f-ip').value       = device ? device.ip      : '';
    document.getElementById('f-group').value    = device ? device.group   : '';
    document.getElementById('f-enabled').checked = device ? device.enabled : true;
    document.getElementById('modal-err').textContent = '';
    document.getElementById('save-btn').disabled = false;
    document.getElementById('save-btn').textContent = 'Save';
    document.getElementById('modal-backdrop').classList.add('open');
    setTimeout(() => document.getElementById('f-name').focus(), 50);
}

function closeModal() {
    document.getElementById('modal-backdrop').classList.remove('open');
}

function saveDevice() {
    const origIp  = document.getElementById('orig-ip').value;
    const name    = document.getElementById('f-name').value.trim();
    const host    = document.getElementById('f-host').value.trim();
    const ip      = document.getElementById('f-ip').value.trim();
    const group   = document.getElementById('f-group').value.trim();
    const enabled = document.getElementById('f-enabled').checked;

    if (!name || !ip) {
        document.getElementById('modal-err').textContent = 'Name and IP Address are required.';
        return;
    }

    const btn = document.getElementById('save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('action',  origIp ? 'update_device' : 'add_device');
    if (origIp) fd.append('orig_ip', origIp);
    fd.append('name',    name);
    fd.append('host',    host);
    fd.append('ip',      ip);
    fd.append('group',   group);
    fd.append('enabled', enabled ? 'true' : 'false');

    fetch('save.php', { method: 'POST', body: fd })
        .then(r => { if (r.status === 401) { location.reload(); return null; } return r.json(); })
        .then(resp => {
            btn.disabled = false; btn.textContent = 'Save';
            if (!resp) return;
            if (resp.ok) {
                const dev = { name, host, ip, group, enabled };
                if (origIp) {
                    const idx = devices.findIndex(d => d.ip === origIp);
                    if (idx >= 0) devices[idx] = dev; else devices.push(dev);
                } else {
                    devices.push(dev);
                }
                closeModal();
                renderTable();
                showToast(origIp ? 'Device updated.' : 'Device added.');
            } else {
                document.getElementById('modal-err').textContent = resp.error || 'Save failed.';
            }
        })
        .catch(e => {
            btn.disabled = false; btn.textContent = 'Save';
            document.getElementById('modal-err').textContent = 'Network error: ' + e.message;
        });
}

async function deleteDevice(ip, name) {
    const confirmed = await new Promise(resolve => {
        const backdrop = document.createElement('div');
        backdrop.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';

        const modal = document.createElement('div');
        modal.style.cssText = 'background:#fff;border-radius:8px;padding:24px;max-width:400px;width:90vw;box-shadow:0 4px 16px rgba(0,0,0,0.3)';

        const heading = document.createElement('h3');
        heading.textContent = 'Delete Device?';
        heading.style.cssText = 'margin:0 0 16px 0;font-size:18px;color:#333';

        const msg = document.createElement('div');
        msg.innerHTML = `You are about to delete <strong>"${name}"</strong> (${ip}). This cannot be undone.`;
        msg.style.cssText = 'margin-bottom:24px;font-size:14px;line-height:1.5;color:#555';

        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'display:flex;gap:8px;justify-content:flex-end';

        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'padding:8px 16px;border:1px solid #ccc;border-radius:4px;background:#f5f5f5;cursor:pointer;font-size:14px';

        const confirmBtn = document.createElement('button');
        confirmBtn.textContent = 'Delete';
        confirmBtn.style.cssText = 'padding:8px 16px;border:none;border-radius:4px;background:#d32f2f;color:#fff;cursor:pointer;font-size:14px;font-weight:bold';

        const dismiss = (val) => { backdrop.remove(); document.removeEventListener('keydown', onKey); resolve(val); };
        cancelBtn.addEventListener('click', () => dismiss(false));
        confirmBtn.addEventListener('click', () => dismiss(true));
        backdrop.addEventListener('click', e => { if (e.target === backdrop) dismiss(false); });
        const onKey = e => { if (e.key === 'Escape') dismiss(false); };
        document.addEventListener('keydown', onKey);

        btnRow.appendChild(cancelBtn);
        btnRow.appendChild(confirmBtn);
        modal.appendChild(heading);
        modal.appendChild(msg);
        modal.appendChild(btnRow);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);
        confirmBtn.focus();
    });

    if (!confirmed) return;
    const fd = new FormData();
    fd.append('action', 'delete_device');
    fd.append('ip', ip);
    fetch('save.php', { method: 'POST', body: fd })
        .then(r => { if (r.status === 401) { location.reload(); return null; } return r.json(); })
        .then(resp => {
            if (!resp) return;
            if (resp.ok) {
                devices = devices.filter(d => d.ip !== ip);
                renderTable();
                showToast('Device deleted.');
            } else {
                showToast('Delete failed: ' + (resp.error || ''));
            }
        })
        .catch(e => showToast('Network error: ' + e.message));
}

function copyIP(ip) {
    navigator.clipboard.writeText(ip)
        .then(() => showToast('Copied: ' + ip))
        .catch(() => showToast('Copy failed'));
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => t.classList.remove('show'), 2200);
}

document.getElementById('modal-backdrop').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function openWeb(ip) {
    window.open('http://' + ip, '_blank');
}

function openSSH(ip) {
    const w = 920, h = 620;
    const left = Math.round((screen.width  - w) / 2);
    const top  = Math.round((screen.height - h) / 2);
    window.open('ssh_term.php?ip=' + encodeURIComponent(ip),
        'ssh_' + ip.replace(/\./g, '_'),
        `width=${w},height=${h},left=${left},top=${top},resizable=yes,menubar=no,toolbar=no,location=no,status=no`);
}

renderTable();
doFetch();
startBgPolling();

try {
    const bc = new BroadcastChannel('aprs_netbird');
    bc.onmessage = (e) => {
        if (!e.data || e.data.type !== 'toggle') return;
        const d = devices.find(x => x.ip === e.data.ip);
        if (!d) return;
        d.enabled = e.data.enabled;
        if (d.enabled) {
            d.online = false; d.last_request = null;
            pendingIPs.add(d.ip);
            renderTable();
            startActiveWindow();
        } else {
            pendingIPs.delete(d.ip);
            d.online = false;
            renderTable();
        }
    };
} catch(e) {}

// ── Inactivity timeout ────────────────────────────────────────────────────────
(function() {
    const IDLE_SECS  = 20 * 60;
    const GRACE_SECS =  2 * 60;

    const overlay = document.createElement('div');
    overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center';
    overlay.innerHTML =
        '<div style="background:#fff;border-radius:12px;padding:32px 36px;width:min(360px,90vw);text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)">' +
          '<h2 style="font-size:18px;font-weight:700;color:#111827;margin-bottom:10px">Still there?</h2>' +
          '<p style="font-size:14px;color:#6b7280;margin-bottom:22px">You\'ll be signed out in <strong id="idle-cd" style="color:#dc2626"></strong>.</p>' +
          '<button id="idle-stay" style="background:#2563eb;color:#fff;border:none;padding:10px 28px;border-radius:7px;font-size:15px;font-weight:500;cursor:pointer">I\'m still here</button>' +
        '</div>';
    document.body.appendChild(overlay);

    let idleTimer, graceTimer, graceCount;

    function fmt(s) {
        const m = Math.floor(s / 60), r = s % 60;
        return m > 0 ? m + ':' + String(r).padStart(2, '0') : s + 's';
    }

    function resetIdle() {
        if (overlay.style.display !== 'none') return;
        clearTimeout(idleTimer);
        idleTimer = setTimeout(showPrompt, IDLE_SECS * 1000);
    }

    function showPrompt() {
        graceCount = GRACE_SECS;
        document.getElementById('idle-cd').textContent = fmt(graceCount);
        overlay.style.display = 'flex';
        graceTimer = setInterval(() => {
            graceCount--;
            document.getElementById('idle-cd').textContent = fmt(graceCount);
            if (graceCount <= 0) exitPage();
        }, 1000);
    }

    function stayHere() {
        clearInterval(graceTimer);
        overlay.style.display = 'none';
        resetIdle();
    }

    function exitPage() {
        clearInterval(graceTimer);
        window.location.href = '?logout=1';
    }

    document.getElementById('idle-stay').onclick = stayHere;
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(e =>
        document.addEventListener(e, resetIdle, { passive: true })
    );
    resetIdle();
})();
</script>
</body>
</html>
