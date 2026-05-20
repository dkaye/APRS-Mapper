<?php
// ── Host status query — no auth required ─────────────────────────────────────
// GET /aprs/status?hostname=K6DRK-10  →  1 (enabled), 0 (disabled), -1 (unknown)
if (isset($_GET['hostname'])) {
    require_once __DIR__ . '/yaml_lib.php';
    $needle  = trim($_GET['hostname']);
    $result  = -1;
    foreach (loadDevices(__DIR__ . '/addresses.yaml') as $d) {
        if ($d['host'] === $needle) {
            $result = $d['enabled'] ? 1 : 0;
            break;
        }
    }
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo $result;
    exit;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$passFile   = '/var/www/html/admin/password.txt';
$loginError = '';

if (isset($_POST['password'])) {
    $stored = trim((string)@file_get_contents($passFile));
    if ($stored !== '' && $_POST['password'] === $stored) {
        $_SESSION['stats_auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Incorrect password';
}

if (empty($_SESSION['stats_auth'])) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MARS APRS Status — Sign In</title>
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
  <h1>MARS APRS Status</h1>
  <p>Sign in to continue</p>
  <form method="post">
    <input type="password" name="password" placeholder="Password" autofocus autocomplete="current-password">
    <button type="submit">Sign In</button>
    <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  </form>
</div>
</body>
</html>
<?php exit; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MARS APRS Device Status</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
  background: #f3f4f6; color: #111827; font-size: 16px; min-height: 100vh;
}

/* ── Header ── */
header {
  background: #fff; padding: 10px 20px;
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  border-bottom: 1px solid #e5e7eb;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
  position: sticky; top: 0; z-index: 20;
}
header h1 { font-size: 18px; font-weight: 700; color: #111827; white-space: nowrap; margin-right: auto; }

.hdr-group { display: flex; align-items: center; gap: 7px; white-space: nowrap; }
.hdr-label { font-size: 12px; color: #9ca3af; }

.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
}
.badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.badge-green { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-red   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.badge-gray  { background: #f9fafb; color: #6b7280; border: 1px solid #e5e7eb; }

#poll-cd { font-size: 16px; font-weight: 700; color: #2563eb; min-width: 48px; text-align: right; }

select.hdr-select {
  background: #f9fafb; border: 1px solid #d1d5db; color: #374151;
  padding: 3px 6px; border-radius: 5px; font-size: 13px; cursor: pointer;
}
select.hdr-select:focus { outline: none; border-color: #2563eb; }

.hdr-btn {
  background: #f9fafb; border: 1px solid #d1d5db; color: #374151;
  padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 13px;
  text-decoration: none; display: inline-block;
}
.hdr-btn:hover { background: #e5e7eb; color: #111827; }

/* ── Summary bar ── */
#summary {
  display: flex; gap: 10px; padding: 14px 20px;
  background: #fff; border-bottom: 1px solid #e5e7eb;
}
.sum-card {
  background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
  padding: 8px 18px; text-align: center; min-width: 80px;
}
.sum-num { font-size: 28px; font-weight: 700; line-height: 1; }
.sum-lbl { font-size: 11px; color: #9ca3af; margin-top: 3px; text-transform: uppercase; letter-spacing: .5px; }
.num-total    { color: #111827; }
.num-online   { color: #16a34a; }
.num-enabling { color: #2563eb; }
.num-offline  { color: #dc2626; }
.num-disabled { color: #9ca3af; }

/* ── Main table ── */
main { padding: 16px 20px; }

#notice { display: none; margin-bottom: 14px; }
.notice-box {
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 8px; padding: 12px 16px; color: #991b1b; font-size: 14px;
}

.table-wrap {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
  overflow: auto;
}

table {
  table-layout: fixed; border-collapse: collapse;
  /* width set dynamically by JS */
}

thead th {
  font-size: 13px; font-weight: 600; color: #9ca3af;
  text-align: left; padding: 8px 10px;
  text-transform: uppercase; letter-spacing: .5px;
  background: #f9fafb; border-bottom: 1px solid #e5e7eb;
  white-space: nowrap; overflow: hidden;
  position: relative; user-select: none;
}

/* Resize handle */
.col-resize {
  position: absolute; right: 0; top: 0; bottom: 0; width: 6px;
  cursor: col-resize; z-index: 2;
}
.col-resize::after {
  content: ''; position: absolute;
  right: 2px; top: 15%; bottom: 15%; width: 2px;
  background: #e5e7eb; border-radius: 1px; transition: background .15s;
}
.col-resize:hover::after, .col-resize.dragging::after { background: #2563eb; }

/* Group header rows */
tr.group-row td {
  padding: 12px 10px 4px;
  font-size: 12px; font-weight: 700; color: #9ca3af;
  text-transform: uppercase; letter-spacing: .7px;
  background: #f9fafb; border-top: 1px solid #e5e7eb;
  white-space: nowrap; overflow: hidden;
}
tr.group-row:first-child td { border-top: none; }

/* Device rows */
tr.device-row td {
  padding: 9px 10px; border-bottom: 1px solid #f3f4f6;
  vertical-align: middle; overflow: hidden;
}
tr.device-row:last-child td { border-bottom: none; }
tr.device-row:hover td { background: #fafafa; }

/* Column cell styles */
.device-name {
  font-size: 15px; font-weight: 500; color: #111827;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.ip-cell { display: flex; align-items: center; gap: 4px; }
.ip-addr {
  font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
  font-size: 13px; color: #9ca3af; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.copy-btn {
  background: none; border: none; cursor: pointer; padding: 2px 3px;
  color: #d1d5db; border-radius: 3px; flex-shrink: 0;
  display: flex; align-items: center; line-height: 1;
}
.copy-btn:hover { color: #6b7280; background: #f3f4f6; }

.status-cell { display: flex; align-items: center; gap: 7px; }
.pulse { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.status-label { font-size: 17px; font-weight: 600; white-space: nowrap; }
.online   .pulse { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.offline  .pulse { background: #ef4444; }
.enabling .pulse { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
.disabled .pulse { background: #d1d5db; }
.unknown  .pulse { background: #d1d5db; }
.online   .status-label { color: #16a34a; }
.offline  .status-label { color: #dc2626; }
.enabling .status-label { color: #2563eb; }
.disabled .status-label { color: #9ca3af; }
.unknown  .status-label { color: #9ca3af; }

.age-cell { font-size: 15px; color: #6b7280; white-space: nowrap; }

.data-cell {
  font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
  font-size: 15px; color: #374151; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.data-cell.empty { color: #d1d5db; }

/* ── Toast ── */
#copy-toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
  background: #1f2937; color: #f9fafb; padding: 6px 16px;
  border-radius: 20px; font-size: 13px;
  opacity: 0; pointer-events: none; transition: opacity .15s; z-index: 100;
}
#copy-toast.show { opacity: 1; }

</style>
</head>
<body>

<header>
  <h1>MARS APRS Device Status</h1>

  <div class="hdr-group">
    <span class="hdr-label">Next poll</span>
    <span id="poll-cd">—</span>
  </div>

  <div class="hdr-group">
    <span class="hdr-label">Interval</span>
    <select class="hdr-select" id="interval-sel" onchange="setDaemonInterval(this.value)">
      <option value="15">15 s</option>
      <option value="30">30 s</option>
      <option value="60" selected>60 s</option>
      <option value="120">2 min</option>
      <option value="300">5 min</option>
      <option value="600">10 min</option>
    </select>
  </div>

  <div class="hdr-group">
    <span id="daemon-badge" class="badge badge-gray"><span class="dot"></span>Daemon</span>
  </div>

  <div class="hdr-group">
    <a href="admin.php" class="hdr-btn">Admin</a>
  </div>

  <div class="hdr-group">
    <a href="/aprs/userguide.php?back=/aprs/status/#device-status-monitor" class="hdr-btn">User Guide</a>
  </div>

  <div class="hdr-group">
    <span class="hdr-label">Refresh</span>
    <button class="hdr-btn" id="refresh-btn" onclick="doFetch()">&#8635; <span id="ui-cd"></span></button>
  </div>

  <div class="hdr-group">
    <a href="?logout=1" class="hdr-btn">Sign Out</a>
  </div>
</header>

<div id="summary">
  <div class="sum-card"><div class="sum-num num-total"    id="n-total">—</div><div class="sum-lbl">Total</div></div>
  <div class="sum-card"><div class="sum-num num-online"   id="n-online">—</div><div class="sum-lbl">Online</div></div>
  <div class="sum-card"><div class="sum-num num-enabling" id="n-enabling">—</div><div class="sum-lbl">Enabling</div></div>
  <div class="sum-card"><div class="sum-num num-offline"  id="n-offline">—</div><div class="sum-lbl">Offline</div></div>
  <div class="sum-card"><div class="sum-num num-disabled" id="n-disabled">—</div><div class="sum-lbl">Disabled</div></div>
</div>

<main>
  <div id="notice"><div id="notice-box" class="notice-box"></div></div>
  <div class="table-wrap">
    <table id="device-table">
      <thead id="thead"></thead>
      <tbody id="tbody">
        <tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;font-size:15px">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</main>

<div id="copy-toast">Copied!</div>

<script>
'use strict';

// ── Column width management ───────────────────────────────────────────────────

const COL_DEFAULTS = {
  name: 120, host: 90, ip: 138, status: 88, age: 48,
  Load: 82, Temp: 65, Mem: 55, SSID: 150, Throttled: 78, notes: 100
};
const colW = Object.assign({}, COL_DEFAULTS);

function loadColWidths() {
  try {
    const stored = JSON.parse(localStorage.getItem('stats-col-widths') || '{}');
    Object.assign(colW, stored);
  } catch(e) {}
}
function saveColWidths() {
  localStorage.setItem('stats-col-widths', JSON.stringify(colW));
}

function updateTableWidth() {
  const ths  = [...document.querySelectorAll('#thead th')];
  const sum  = ths.reduce((s, th) => s + th.offsetWidth, 0);
  const wrap = document.querySelector('.table-wrap').clientWidth;
  document.getElementById('device-table').style.width = Math.max(sum, wrap) + 'px';
}

function applyColWidths() {
  const ths = [...document.querySelectorAll('#thead th')];
  ths.forEach(th => {
    const id = th.dataset.col;
    const w  = colW[id] || COL_DEFAULTS[id] || 80;
    th.style.width = th.style.minWidth = w + 'px';

    const handle = document.createElement('span');
    handle.className = 'col-resize';
    th.appendChild(handle);

    handle.addEventListener('mousedown', e => {
      e.preventDefault();
      handle.classList.add('dragging');
      const startX = e.clientX;
      const startW = th.offsetWidth;

      const onMove = e => {
        const w = Math.max(32, startW + e.clientX - startX);
        th.style.width = th.style.minWidth = w + 'px';
        colW[id] = w;
        updateTableWidth();
      };
      const onUp = () => {
        handle.classList.remove('dragging');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        saveColWidths();
      };
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  });
  updateTableWidth();
}

// ── Response parsing ──────────────────────────────────────────────────────────

const KEY_ORDER = ['Load', 'Temp',  'Mem', 'SSID', 'Throttled'];
const KEY_LABEL = { Temp: 'CPU' };
function keyLabel(k) { return KEY_LABEL[k] || k; }

function cleanResponse(text, ip, hostname) {
  if (!text) return '';
  text = text.replace(new RegExp('\\b' + ip.replace(/\./g, '\\.') + '\\b', 'g'), '');
  if (hostname) {
    const h = hostname.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    text = text.replace(new RegExp('\\b' + h + '\\b', 'g'), '');
  } else {
    text = text.replace(/^\s*[A-Z][A-Z0-9]{1,5}-\d+\s+/, '');
  }
  return text.replace(/[ \t]{2,}/g, ' ').trim();
}

function parseKV(text) {
  const parsed = {};
  let remaining = text;
  const re = /\b([A-Za-z]\w*)=([^\s]+)/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    parsed[m[1]] = m[2];
    remaining = remaining.replace(m[0], '');
  }
  return { parsed, remaining: remaining.replace(/[,\s]+/g, ' ').trim() || null };
}

function sortKeys(keys) {
  return [...keys].sort((a, b) => {
    const ai = KEY_ORDER.indexOf(a), bi = KEY_ORDER.indexOf(b);
    if (ai >= 0 && bi >= 0) return ai - bi;
    if (ai >= 0) return -1; if (bi >= 0) return 1;
    return a.localeCompare(b);
  });
}

function collectKeys(devices) {
  const seen = new Set();
  devices.forEach(d => {
    if (!d.response_data) return;
    const { parsed } = parseKV(cleanResponse(d.response_data, d.ip, d.hostname || ''));
    Object.keys(parsed).forEach(k => seen.add(k));
  });
  return sortKeys([...seen]);
}

// ── Utilities ─────────────────────────────────────────────────────────────────

function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function timeAgo(ts) {
  if (!ts) return '—';
  const s = Math.floor(Date.now() / 1000) - ts;
  if (s <  5) return 'now';
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s / 60) + 'm';
  return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
}

const COPY_SVG = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none"
  stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
  <rect x="9" y="9" width="13" height="13" rx="2"/>
  <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
</svg>`;

function copyIP(ip) {
  navigator.clipboard.writeText(ip).then(() => {
    const t = document.getElementById('copy-toast');
    t.classList.add('show');
    clearTimeout(copyIP._t);
    copyIP._t = setTimeout(() => t.classList.remove('show'), 1400);
  });
}

// ── Countdowns ────────────────────────────────────────────────────────────────

let lastSendTs = null, repeatSecs = 60, pollCdTimer, uiTimer, uiCountdown = 15;

function startPollCd() {
  clearInterval(pollCdTimer);
  pollCdTimer = setInterval(() => {
    if (lastSendTs === null) return;
    const rem = Math.max(0, lastSendTs + repeatSecs - Math.floor(Date.now() / 1000));
    document.getElementById('poll-cd').textContent = rem > 0 ? rem + 's' : 'now…';
  }, 500);
}

function resetUiCd() {
  clearInterval(uiTimer);
  uiCountdown = 15;
  tickUi();
  uiTimer = setInterval(() => { uiCountdown--; tickUi(); if (uiCountdown <= 0) doFetch(); }, 1000);
}
function tickUi() {
  document.getElementById('ui-cd').textContent = uiCountdown > 0 ? uiCountdown + 's' : '…';
}

// ── Fetch ─────────────────────────────────────────────────────────────────────

function doFetch() {
  resetUiCd();
  fetch('api.php?_=' + Date.now())
    .then(r => {
      if (r.status === 401) { location.reload(); return null; }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(data => { if (data) render(data); })
    .catch(e => showNotice('Could not reach api.php: ' + e.message));
}

// ── Render ────────────────────────────────────────────────────────────────────

function render(data) {
  const badge = document.getElementById('daemon-badge');
  badge.className = 'badge ' + (data.daemon_running ? 'badge-green' : 'badge-red');
  badge.innerHTML = '<span class="dot"></span>' + (data.daemon_running ? 'Daemon Running' : 'Daemon Stopped');

  if (data.last_send_ts)   lastSendTs  = data.last_send_ts;
  if (data.repeat_seconds) repeatSecs  = data.repeat_seconds;

  const sel = document.getElementById('interval-sel');
  const cur = String(repeatSecs);
  let found = false;
  for (const opt of sel.options) { if (opt.value === cur) { opt.selected = true; found = true; break; } }
  if (!found) { sel.insertBefore(new Option(cur + ' s', cur, true, true), sel.firstChild); }

  const devices = data.devices || [];
  const nowTs    = Math.floor(Date.now() / 1000);
  const nOnline  = devices.filter(d => d.online).length;
  const nEnabling = devices.filter(d => {
    if (!d.enabled || d.online) return false;
    if (d.enabled_at) return nowTs < Math.floor(d.enabled_at / 300) * 300 + 330;
    return true;
  }).length;
  const nOffline = devices.filter(d => {
    if (!d.enabled || d.online) return false;
    if (d.enabled_at) return nowTs >= Math.floor(d.enabled_at / 300) * 300 + 330;
    return false;
  }).length;
  const nDisabled = devices.filter(d => !d.enabled).length;
  document.getElementById('n-total').textContent    = nOnline + nEnabling + nOffline + nDisabled;
  document.getElementById('n-online').textContent   = nOnline;
  document.getElementById('n-enabling').textContent = nEnabling;
  document.getElementById('n-offline').textContent  = nOffline;
  document.getElementById('n-disabled').textContent = nDisabled;

  data.error ? showNotice(data.error) : hideNotice();

  const dynKeys = collectKeys(devices);
  const hasNotes = devices.some(d => {
    if (!d.response_data) return false;
    return !!parseKV(cleanResponse(d.response_data, d.ip, d.hostname || '')).remaining;
  });

  // ── thead ──────────────────────────────────────────────────────────────────
  const dynHeads = dynKeys.map(k =>
    `<th data-col="${esc(k)}">${esc(keyLabel(k))}</th>`
  ).join('');
  const notesHead = hasNotes ? `<th data-col="notes">Notes</th>` : '';

  document.getElementById('thead').innerHTML = `<tr>
    <th data-col="name">Name</th>
    <th data-col="host">Host</th>
    <th data-col="ip">IP Address</th>
    <th data-col="status">Status</th>
    <th data-col="age">Last</th>
    ${dynHeads}${notesHead}
  </tr>`;

  applyColWidths(); // apply stored widths + add resize handles

  // ── tbody ──────────────────────────────────────────────────────────────────
  const groupMap = new Map(), groupOrder = [];
  devices.forEach(d => {
    const g = d.group || '';
    if (!groupMap.has(g)) { groupMap.set(g, []); groupOrder.push(g); }
    groupMap.get(g).push(d);
  });

  const totalCols = 5 + dynKeys.length + (hasNotes ? 1 : 0);
  let rows = '';

  groupOrder.forEach(group => {
    if (group) rows += `<tr class="group-row"><td colspan="${totalCols}">${esc(group)}</td></tr>`;
    groupMap.get(group).forEach(d => {
      let sc, sl;
      if (!d.enabled) {
        sc = 'disabled'; sl = 'Disabled';
      } else if (d.online) {
        sc = 'online'; sl = 'Online';
      } else if (d.enabled_at) {
        // Next 5-min clock boundary after enabled_at, plus 30 s for the host to react
        const deadline = Math.floor(d.enabled_at / 300) * 300 + 330;
        if (Math.floor(Date.now() / 1000) < deadline) { sc = 'enabling'; sl = 'Enabling'; }
        else                                           { sc = 'offline';  sl = 'Offline';  }
      } else {
        sc = 'enabling'; sl = 'Enabling'; // enabled_at not yet known
      }
      const ago = timeAgo(d.last_response);

      const cleaned = d.response_data ? cleanResponse(d.response_data, d.ip, d.hostname || '') : '';
      const { parsed, remaining } = parseKV(cleaned);

      const dynCells = dynKeys.map(k => {
        const v = parsed[k];
        return v ? `<td class="data-cell">${esc(v)}</td>`
                 : `<td class="data-cell empty">—</td>`;
      }).join('');

      const notesCell = hasNotes
        ? (remaining ? `<td class="data-cell">${esc(remaining)}</td>`
                     : `<td class="data-cell empty">—</td>`)
        : '';

      rows += `<tr class="device-row">
        <td><div class="device-name" title="${esc(d.name)}">${esc(d.name || d.ip)}</div></td>
        <td class="data-cell">${d.hostname ? esc(d.hostname) : '<span style="color:#d1d5db">—</span>'}</td>
        <td><div class="ip-cell">
          <span class="ip-addr">${esc(d.ip)}</span>
          <button class="copy-btn" onclick="copyIP('${esc(d.ip)}')" title="Copy">${COPY_SVG}</button>
        </div></td>
        <td><div class="status-cell ${sc}">
          <span class="pulse"></span><span class="status-label">${sl}</span>
        </div></td>
        <td class="age-cell">${ago}</td>
        ${dynCells}${notesCell}
      </tr>`;
    });
  });

  document.getElementById('tbody').innerHTML = rows ||
    `<tr><td colspan="${totalCols}" style="padding:40px;text-align:center;color:#9ca3af;font-size:15px">No devices configured.</td></tr>`;
}

function showNotice(msg) {
  document.getElementById('notice-box').textContent = msg;
  document.getElementById('notice').style.display = 'block';
}
function hideNotice() { document.getElementById('notice').style.display = 'none'; }

// ── Interval control ──────────────────────────────────────────────────────────

function setDaemonInterval(seconds) {
  const fd = new FormData();
  fd.append('action', 'save_config');
  fd.append('repeat_seconds', seconds);
  fetch('save.php', { method: 'POST', body: fd })
    .then(r => { if (r.status === 401) location.reload(); return r.json(); })
    .then(d => { if (d.ok) repeatSecs = parseInt(seconds); })
    .catch(() => {});
}

// Recompute table width on window resize
window.addEventListener('resize', updateTableWidth);

// ── Boot ──────────────────────────────────────────────────────────────────────
loadColWidths();
startPollCd();
// Re-fetch immediately when the admin page toggles a device
try { new BroadcastChannel('aprs_status').onmessage = () => doFetch(); } catch(e) {}
doFetch();

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
