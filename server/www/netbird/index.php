<?php
/**
 * index.php — MARS APRS NetBird host-status query endpoint
 *
 * Lightweight REST endpoint that checks whether a given hostname is enabled
 * in the NetBird VPN via addresses.yaml. Returns 1 (enabled), 0 (disabled),
 * or -1 (unknown). No authentication required — used by the mobile app and
 * the admin UI to gate remote operations.
 */
// ── Host status query — no auth required ─────────────────────────────────────
// GET /netbird/?hostname=K6DRK-10  →  1 (enabled), 0 (disabled), -1 (unknown)
// Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
// ©2025 Doug Kaye, K6DRK <doug@rds.com>
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

session_start();

$passFile   = '/var/www/html/admin/password.txt';
$loginError = '';

if (isset($_POST['password'])) {
    $stored = trim((string)@file_get_contents($passFile));
    if ($stored !== '' && $_POST['password'] === $stored) {
        $_SESSION['stats_auth']        = true;
        $_SESSION['aprs_admin_authed'] = true;
        header('Location: index.php');
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
  <h1>MARS APRS NetBird — Status</h1>
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MARS APRS NetBird Status</title>
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

.slider-group { display: flex; flex-direction: column; gap: 3px; }
.slider-row { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.slider-val { font-size: 12px; font-weight: 600; color: #374151; min-width: 30px; text-align: right; }
input[type=range].hdr-range { width: 100px; cursor: pointer; accent-color: #2563eb; vertical-align: middle; }
.progress-track { height: 3px; background: #e5e7eb; border-radius: 2px; overflow: hidden; }
.progress-fill { height: 100%; background: #2563eb; border-radius: 2px; width: 0; }

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
.num-pending  { color: #2563eb; }
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
.status-label { font-size: 13px; font-weight: 400; white-space: nowrap; }
.online   .pulse { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.offline  .pulse { background: #ef4444; }
.pending  .pulse { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
.disabled .pulse { background: #d1d5db; }
.unknown  .pulse { background: #d1d5db; }
.online   .status-label { color: #16a34a; }
.offline  .status-label { color: #dc2626; }
.pending  .status-label { color: #2563eb; }
.disabled .status-label { color: #9ca3af; }
.unknown  .status-label { color: #9ca3af; }

.age-cell { font-size: 15px; color: #6b7280; white-space: nowrap; }

.data-cell {
  font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
  font-size: 15px; color: #374151; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.data-cell.empty { color: #d1d5db; }
.throttle-warn { color: #dc2626; }
.throttle-temp  { color: #dc2626; font-weight: 700; }

/* ── Footer ── */
footer {
  text-align: center; padding: 16px 20px;
  font-size: 12px; color: #9ca3af;
}

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
  <h1>MARS APRS NetBird Status</h1>

  <div class="hdr-group">
    <div class="slider-group">
      <div class="slider-row">
        <span class="hdr-label">Poll</span>
        <input type="range" class="hdr-range" id="poll-slider" min="0" max="9" step="1" value="4"
               oninput="pollSliderMove(this.value)" onchange="pollSliderDone(this.value)">
        <span class="slider-val" id="poll-val">60s</span>
      </div>
      <div class="progress-track"><div class="progress-fill" id="poll-bar"></div></div>
    </div>
  </div>

  <div class="hdr-group">
    <div class="slider-group">
      <div class="slider-row">
        <span class="hdr-label">Refresh</span>
        <input type="range" class="hdr-range" id="refresh-slider" min="0" max="9" step="1" value="5"
               oninput="refreshSliderMove(this.value)" onchange="refreshSliderDone(this.value)">
        <span class="slider-val" id="refresh-val">8s</span>
      </div>
      <div class="progress-track"><div class="progress-fill" id="refresh-bar"></div></div>
    </div>
  </div>

  <div class="hdr-group">
    <span id="daemon-badge" class="badge badge-gray"><span class="dot"></span>Daemon</span>
  </div>

  <div class="hdr-group">
    <a href="https://marsaprs.org" class="hdr-btn">Map</a>
  </div>

  <div class="hdr-group">
    <a href="admin.php" class="hdr-btn">Admin</a>
  </div>

  <div class="hdr-group">
    <a href="/wifi/" class="hdr-btn">WiFi</a>
  </div>

  <div class="hdr-group">
    <a href="/userguide.html?back=/netbird/" class="hdr-btn">User Guide</a>
  </div>

</header>

<div id="summary">
  <div class="sum-card"><div class="sum-num num-total"    id="n-total">—</div><div class="sum-lbl">Total</div></div>
  <div class="sum-card"><div class="sum-num num-online"   id="n-online">—</div><div class="sum-lbl">Online</div></div>
  <div class="sum-card"><div class="sum-num num-pending"  id="n-pending">—</div><div class="sum-lbl">Pending</div></div>
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

<footer>Marin Amateur Radio Society &middot; APRS NetBird Status Monitor &middot; v1.12 &copy; 2026 Doug Kaye (K6DRK)</footer>

<div id="copy-toast">Copied!</div>

<script>
'use strict';

// ── Column width management ───────────────────────────────────────────────────

const COL_DEFAULTS = {
  name: 165, host: 100, ip: 160, status: 115, age: 68,
  Load: 100, Temp: 74, Mem: 56, SSID: 164, Throttled: 120
};
const colW = Object.assign({}, COL_DEFAULTS);

function loadColWidths() {
  try {
    const stored = JSON.parse(localStorage.getItem('nb-col-widths-v2') || '{}');
    Object.assign(colW, stored);
  } catch(e) {}
}
function saveColWidths() {
  localStorage.setItem('nb-col-widths-v2', JSON.stringify(colW));
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

const KEY_ORDER = ['Load', 'Temp', 'Mem', 'SSID', 'Throttled'];
const KEY_LABEL = { Temp: 'CPU' };
function keyLabel(k) { return KEY_LABEL[k] || k; }

const THROTTLE_FLAGS = [[0x1,'Low voltage'],[0x2,'Frequency capped']];
function formatVal(k, v, parsed) {
  if (k === 'Throttled') {
    const n = parseInt(v, 16);
    if (!isNaN(n)) {
      const labels = THROTTLE_FLAGS.filter(([bit]) => n & bit).map(([,s]) => s);
      return labels.length ? `<span class="throttle-warn">${esc(labels.join(', '))}</span>` : '—';
    }
  }
  if (k === 'Temp' && parsed) {
    const n = parseInt(parsed['Throttled'] || '0', 16);
    if (!isNaN(n) && (n & 0x8)) return `<span class="throttle-temp">${esc(v)}</span>`;
  }
  return esc(v);
}

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

function collectKeys(devs) {
  const seen = new Set();
  devs.forEach(d => {
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

// ── State ─────────────────────────────────────────────────────────────────────

let devices = [];
let lastSendTs = null, repeatSecs = 60, pollSliderSetAt = 0;
let lastDynKeysStr = '', lastDynKeys = [];
let backgroundTimer = null, ageCellTimer = null;
let bgMs = 8000, lastFetchTime = 0;

const AGE_MS = 10000;

// ── Status helper ─────────────────────────────────────────────────────────────

function getStatus(d) {
    const pending = d.pending_until && Date.now() / 1000 < d.pending_until;
    if (d.online) {
        if (!d.enabled && pending) return ['pending', 'Pending'];
        return d.enabled ? ['online', 'Online'] : ['disabled', 'Disabled'];
    }
    if (pending)    return ['pending',  'Pending'];
    if (!d.enabled) return ['disabled', 'Disabled'];
    return ['offline', 'Offline'];
}

// ── Slider helpers ────────────────────────────────────────────────────────────

const POLL_STEPS    = [15, 20, 30, 45, 60, 90, 120, 180, 240, 300];
const REFRESH_STEPS = [2, 3, 4, 5, 6, 8, 10, 15, 20, 30];

function fmtSecs(s) {
    s = parseInt(s);
    if (s < 60) return s + 's';
    if (s % 60 === 0) return (s / 60) + 'm';
    return Math.floor(s / 60) + 'm' + (s % 60) + 's';
}

function nearestIdx(steps, secs) {
    return steps.reduce((best, v, i) =>
        Math.abs(v - secs) < Math.abs(steps[best] - secs) ? i : best, 0);
}

function pollSliderMove(idx) {
    document.getElementById('poll-val').textContent = fmtSecs(POLL_STEPS[idx]);
}
function pollSliderDone(idx) {
    repeatSecs = POLL_STEPS[idx];
    pollSliderSetAt = Date.now();
    document.getElementById('poll-val').textContent = fmtSecs(repeatSecs);
    try { localStorage.setItem('nb-poll-idx', idx); } catch(e) {}
    setDaemonInterval(repeatSecs);
}
function refreshSliderMove(idx) {
    document.getElementById('refresh-val').textContent = fmtSecs(REFRESH_STEPS[idx]);
}
function refreshSliderDone(idx) {
    bgMs = REFRESH_STEPS[idx] * 1000;
    document.getElementById('refresh-val').textContent = fmtSecs(REFRESH_STEPS[idx]);
    try { localStorage.setItem('nb-refresh-idx', idx); } catch(e) {}
    startBgPolling();
}

function updateProgressBars() {
    const now = Date.now();
    if (lastSendTs !== null) {
        const pct = Math.min(100, (now / 1000 - lastSendTs) / repeatSecs * 100);
        document.getElementById('poll-bar').style.width = pct + '%';
    }
    if (lastFetchTime > 0) {
        const pct = Math.min(100, (now - lastFetchTime) / bgMs * 100);
        document.getElementById('refresh-bar').style.width = pct + '%';
    }
}

function startBgPolling() {
    clearInterval(backgroundTimer);
    backgroundTimer = setInterval(doFetch, bgMs);
}

function stopBgPolling() {
    clearInterval(backgroundTimer);
    backgroundTimer = null;
}

// ── Fetch ─────────────────────────────────────────────────────────────────────

function doFetch(init = false) {
    lastFetchTime = Date.now();
    fetch('api.php?_=' + lastFetchTime + (init ? '&init=1' : ''))
        .then(r => {
            if (r.status === 401) { location.reload(); return null; }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => { if (data) processData(data, init); })
        .catch(e => showNotice('Could not reach api.php: ' + e.message));
}

function processData(data, wasInit) {
    const badge = document.getElementById('daemon-badge');
    badge.className = 'badge ' + (data.daemon_running ? 'badge-green' : 'badge-red');
    badge.innerHTML = '<span class="dot"></span>' + (data.daemon_running ? 'Daemon Running' : 'Daemon Stopped');

    if (data.repeat_seconds && data.repeat_seconds !== repeatSecs && Date.now() - pollSliderSetAt > 5000) {
        repeatSecs = data.repeat_seconds;
        const sl = document.getElementById('poll-slider');
        if (sl) sl.value = nearestIdx(POLL_STEPS, repeatSecs);
        document.getElementById('poll-val').textContent = fmtSecs(repeatSecs);
    }

    const newSendTs  = data.last_send_ts || null;
    const isNewCycle = !wasInit && newSendTs && lastSendTs !== null && newSendTs !== lastSendTs;
    if (newSendTs) lastSendTs = newSendTs;

    const incoming  = data.devices || [];
    const dynKeys   = collectKeys(incoming);
    const dynStr    = JSON.stringify(dynKeys);
    const needsFull = dynStr !== lastDynKeysStr
        || incoming.length !== devices.length
        || incoming.some((d, i) => !devices[i] || d.ip !== devices[i].ip);
    lastDynKeysStr = dynStr;
    lastDynKeys    = dynKeys;

    // Patch live fields into existing objects to avoid a full re-render on every poll
    incoming.forEach(ad => {
        const d = devices.find(x => x.ip === ad.ip);
        if (d) {
            d.online = ad.online; d.last_request = ad.last_request;
            d.last_response = ad.last_response; d.response_data = ad.response_data;
            d.enabled = ad.enabled; d.hostname = ad.hostname;
            d.pending_until = ad.pending_until ?? null;
        }
    });
    if (needsFull) devices = incoming;

    data.error ? showNotice(data.error) : hideNotice();

    if (needsFull) {
        fullRender();
    } else {
        patchAllStatuses();
        updateAgeCells();
        patchDataCells();
        updateSummary();
    }
}

// ── Full render ───────────────────────────────────────────────────────────────

function fullRender() {
    const dynKeys  = collectKeys(devices);
    const dynHeads  = dynKeys.map(k => `<th data-col="${esc(k)}">${esc(keyLabel(k))}</th>`).join('');
    document.getElementById('thead').innerHTML = `<tr>
      <th data-col="name">Name</th>
      <th data-col="host">Host</th>
      <th data-col="status">Status</th>
      <th data-col="ip">IP Address</th>
      <th data-col="age">Last</th>
      ${dynHeads}
    </tr>`;
    applyColWidths();

    const groupMap = new Map(), groupOrder = [];
    devices.forEach(d => {
        const g = d.group || '';
        if (!groupMap.has(g)) { groupMap.set(g, []); groupOrder.push(g); }
        groupMap.get(g).push(d);
    });

    const totalCols = 5 + dynKeys.length;
    let rows = '';
    groupOrder.forEach(group => {
        if (group) rows += `<tr class="group-row"><td colspan="${totalCols}">${esc(group)}</td></tr>`;
        groupMap.get(group).forEach(d => {
            const [sc, sl] = getStatus(d);
            const ago      = timeAgo(d.last_response);
            const cleaned  = d.response_data ? cleanResponse(d.response_data, d.ip, d.hostname || '') : '';
            const { parsed } = parseKV(cleaned);
            const dynCells  = dynKeys.map(k => {
                const v = parsed[k];
                return v ? `<td class="data-cell" data-col="${esc(k)}">${formatVal(k, v, parsed)}</td>`
                         : `<td class="data-cell empty" data-col="${esc(k)}">—</td>`;
            }).join('');
            rows += `<tr class="device-row" data-ip="${esc(d.ip)}">
              <td><div class="device-name" title="${esc(d.name)}">${esc(d.name || d.ip)}</div></td>
              <td class="data-cell">${d.hostname ? esc(d.hostname) : '<span style="color:#d1d5db">—</span>'}</td>
              <td><div class="status-cell ${sc}"><span class="pulse"></span><span class="status-label">${sl}</span></div></td>
              <td><div class="ip-cell">
                <span class="ip-addr">${esc(d.ip)}</span>
                <button class="copy-btn" onclick="copyIP('${esc(d.ip)}')" title="Copy">${COPY_SVG}</button>
              </div></td>
              <td class="age-cell">${ago}</td>
              ${dynCells}
            </tr>`;
        });
    });

    document.getElementById('tbody').innerHTML = rows ||
        `<tr><td colspan="${totalCols}" style="padding:40px;text-align:center;color:#9ca3af;font-size:15px">No devices configured.</td></tr>`;

    updateSummary();
}

// ── Cell patching ─────────────────────────────────────────────────────────────

function patchAllStatuses() {
    devices.forEach(d => {
        const row = document.querySelector(`#tbody tr.device-row[data-ip="${d.ip}"]`);
        if (!row) return;
        const [sc, sl] = getStatus(d);
        const cell = row.querySelector('.status-cell');
        if (cell) {
            cell.className = 'status-cell ' + sc;
            cell.innerHTML = `<span class="pulse"></span><span class="status-label">${sl}</span>`;
        }
    });
}

function updateAgeCells() {
    devices.forEach(d => {
        const row = document.querySelector(`#tbody tr.device-row[data-ip="${d.ip}"]`);
        if (!row) return;
        const cell = row.querySelector('.age-cell');
        if (cell) cell.textContent = timeAgo(d.last_response);
    });
}

function patchDataCells() {
    if (!lastDynKeys.length) return;
    devices.forEach(d => {
        const row = document.querySelector(`#tbody tr.device-row[data-ip="${d.ip}"]`);
        if (!row) return;
        const cleaned = d.response_data ? cleanResponse(d.response_data, d.ip, d.hostname || '') : '';
        const { parsed } = parseKV(cleaned);
        lastDynKeys.forEach(k => {
            const cell = row.querySelector(`[data-col="${k}"]`);
            if (!cell) return;
            const v = parsed[k];
            if (v) {
                cell.className = 'data-cell';
                cell.innerHTML = formatVal(k, v, parsed);
            } else {
                cell.className = 'data-cell empty';
                cell.textContent = '—';
            }
        });
    });
}

function updateSummary() {
    let nOnline = 0, nPending = 0, nOffline = 0, nDisabled = 0;
    devices.forEach(d => {
        const [sc] = getStatus(d);
        if      (sc === 'online')  nOnline++;
        else if (sc === 'pending') nPending++;
        else if (sc === 'offline') nOffline++;
        else                       nDisabled++;
    });
    document.getElementById('n-total').textContent    = devices.length;
    document.getElementById('n-online').textContent   = nOnline;
    document.getElementById('n-pending').textContent  = nPending;
    document.getElementById('n-offline').textContent  = nOffline;
    document.getElementById('n-disabled').textContent = nDisabled;
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
        .then(r => r.json())
        .then(d => { if (d.ok) repeatSecs = parseInt(seconds); })
        .catch(() => {});
}

window.addEventListener('resize', updateTableWidth);

// ── Boot ──────────────────────────────────────────────────────────────────────

loadColWidths();
try {
    const pi = parseInt(localStorage.getItem('nb-poll-idx'));
    if (!isNaN(pi) && pi >= 0 && pi < POLL_STEPS.length) {
        repeatSecs = POLL_STEPS[pi];
        pollSliderSetAt = Date.now();
        document.getElementById('poll-slider').value = pi;
        document.getElementById('poll-val').textContent = fmtSecs(repeatSecs);
    }
} catch(e) {}
try {
    const ri = parseInt(localStorage.getItem('nb-refresh-idx'));
    if (!isNaN(ri) && ri >= 0 && ri < REFRESH_STEPS.length) {
        bgMs = REFRESH_STEPS[ri] * 1000;
        document.getElementById('refresh-slider').value = ri;
        document.getElementById('refresh-val').textContent = fmtSecs(REFRESH_STEPS[ri]);
    }
} catch(e) {}
setInterval(updateProgressBars, 500);
ageCellTimer = setInterval(updateAgeCells, AGE_MS);

try {
    const bc = new BroadcastChannel('aprs_netbird');
    bc.onmessage = (e) => {
        if (!e.data || e.data.type !== 'toggle') return;
        const d = devices.find(x => x.ip === e.data.ip);
        if (!d) return;
        d.enabled       = e.data.enabled;
        d.pending_until = e.data.pending_until ?? null;
        d.online        = false;
        patchAllStatuses();
        updateSummary();
    };
} catch(e) {}

doFetch(false);
startBgPolling();

// ── Inactivity timeout ────────────────────────────────────────────────────────
(function() {
    const IDLE_SECS = 10 * 60;

    const pauseOverlay = document.createElement('div');
    pauseOverlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center';
    pauseOverlay.innerHTML =
        '<div style="background:#fff;border-radius:12px;padding:32px 36px;width:min(360px,90vw);text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)">' +
          '<h2 style="font-size:18px;font-weight:700;color:#111827;margin-bottom:10px">Polling Paused</h2>' +
          '<p style="font-size:14px;color:#6b7280;margin-bottom:22px">Updates stopped due to inactivity.</p>' +
          '<button id="idle-resume" style="background:#2563eb;color:#fff;border:none;padding:10px 28px;border-radius:7px;font-size:15px;font-weight:500;cursor:pointer">Resume</button>' +
        '</div>';
    document.body.appendChild(pauseOverlay);

    function pausePolling() {
        stopBgPolling();
        pauseOverlay.style.display = 'flex';
    }

    function resumePolling() {
        pauseOverlay.style.display = 'none';
        doFetch(false);
        startBgPolling();
    }

    document.getElementById('idle-resume').onclick = resumePolling;
    setTimeout(pausePolling, IDLE_SECS * 1000);
})();
</script>
</body>
</html>
