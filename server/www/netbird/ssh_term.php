<?php
/**
 * ssh_term.php — MARS APRS NetBird
 *
 * Browser SSH terminal popup (xterm.js). Opened by the SSH button in admin.php.
 * Credentials are stored in the browser's localStorage (never on the server).
 *
 * GET  ?ip=<ip>   — render terminal page
 * POST ?auth      — accept ip/user/pass, mint one-time token, return JSON {token}
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */

// POST ?auth — mint a one-time token from supplied credentials
if (isset($_GET['auth']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $ip   = trim($_POST['ip']   ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if (!$ip || !$user) { echo json_encode(['error' => 'ip and user required']); exit; }
    $token = bin2hex(random_bytes(16));
    file_put_contents("/tmp/aprs_ssh_{$token}.creds",
        json_encode(['ip' => $ip, 'user' => $user, 'pass' => $pass]), LOCK_EX);
    echo json_encode(['token' => $token]);
    exit;
}

// GET — render terminal page
require_once __DIR__ . '/yaml_lib.php';
$ip = trim($_GET['ip'] ?? '');
if (!$ip) { http_response_code(400); echo 'Missing ip'; exit; }

$devices = loadDevices(__DIR__ . '/addresses.yaml');
$device  = null;
foreach ($devices as $d) {
    if ($d['ip'] === $ip) { $device = $d; break; }
}
if (!$device) { http_response_code(404); echo 'Device not found'; exit; }

$name = $device['name'] ?? $ip;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SSH — <?= htmlspecialchars($name) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5/css/xterm.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8/lib/xterm-addon-fit.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: #1e1e1e; color: #ccc;
             font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
body { display: flex; flex-direction: column; }
#hdr { background: #2d2d2d; padding: 6px 14px; font-size: 12px;
       display: flex; gap: 12px; align-items: center; flex-shrink: 0;
       border-bottom: 1px solid #444; }
#hdr strong { color: #eee; }
#hdr #st { color: #aaa; margin-left: auto; }
#hdr button { background: #555; border: none; color: #ccc; padding: 3px 10px;
              border-radius: 3px; cursor: pointer; font-size: 12px; }
#hdr button:hover { background: #666; color: #fff; }
#term { flex: 1; min-height: 0; padding: 4px; }
/* login */
#login { display: flex; align-items: center; justify-content: center; height: 100%; }
#login form { background: #2a2a2a; border: 1px solid #444; border-radius: 8px;
              padding: 28px 32px; min-width: 300px; }
#login h2 { font-size: 14px; margin-bottom: 16px; color: #ddd; }
#login label { display: block; font-size: 11px; color: #999; margin-bottom: 3px; margin-top: 10px; }
#login input[type=text], #login input[type=password] {
    width: 100%; padding: 6px 10px; background: #1a1a1a;
    border: 1px solid #555; color: #eee; border-radius: 3px; font-size: 13px; }
.chk { display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 12px; color: #999; }
.chk input { width: auto; }
#login button[type=submit] { margin-top: 16px; width: 100%; padding: 8px; background: #2563eb;
    border: none; color: #fff; border-radius: 4px; cursor: pointer; font-size: 13px; }
#login button[type=submit]:hover { background: #1d4ed8; }
.err { color: #f87171; font-size: 12px; margin-bottom: 8px; }
</style>
</head>
<body>

<div id="hdr" style="display:none">
  <strong><?= htmlspecialchars($name) ?></strong>
  <span><?= htmlspecialchars($ip) ?></span>
  <span id="st">Connecting…</span>
  <button onclick="window.close()">Exit</button>
</div>
<div id="term" style="display:none"></div>

<div id="login">
  <form id="loginForm">
    <h2>SSH — <?= htmlspecialchars("$name ($ip)") ?></h2>
    <p class="err" id="err" style="display:none"></p>
    <label>Username</label>
    <input type="text" id="fUser" autocomplete="username" required autofocus>
    <label>Password</label>
    <input type="password" id="fPass" autocomplete="current-password">
    <div class="chk">
      <input type="checkbox" id="fRem" checked>
      <label for="fRem">Remember in this browser</label>
    </div>
    <button type="submit">Connect</button>
  </form>
</div>

<script>
const IP    = <?= json_encode($ip) ?>;
const STORE = 'ssh_creds_' + IP;
let term, fit, es;

function showErr(msg) {
    const el = document.getElementById('err');
    el.textContent = msg; el.style.display = msg ? '' : 'none';
}

function showLogin(errMsg) {
    document.getElementById('login').style.display = '';
    document.getElementById('hdr').style.display   = 'none';
    document.getElementById('term').style.display  = 'none';
    if (errMsg) showErr(errMsg);
}

function showTerminal() {
    document.getElementById('login').style.display = 'none';
    document.getElementById('hdr').style.display   = '';
    document.getElementById('term').style.display  = '';
    if (!term) {
        term = new Terminal({ cursorBlink: true, fontSize: 13, theme: { background: '#1e1e1e' } });
        fit  = new FitAddon.FitAddon();
        term.loadAddon(fit);
        term.open(document.getElementById('term'));
    }
    fit.fit();
}

async function mintToken(user, pass) {
    const fd = new FormData();
    fd.append('ip', IP); fd.append('user', user); fd.append('pass', pass);
    const r = await fetch('ssh_term.php?auth', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    return d.token;
}

function openStream(token) {
    const st = document.getElementById('st');

    term.onData(data => {
        fetch('ssh_input.php?token=' + token, {
            method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: data
        });
    });

    new ResizeObserver(() => {
        fit.fit();
        fetch('ssh_resize.php?token=' + token + '&cols=' + term.cols + '&rows=' + term.rows,
              { method: 'POST' });
    }).observe(document.getElementById('term'));

    if (es) es.close();
    es = new EventSource('ssh_stream.php?token=' + token);
    es.onmessage = e => {
        if (e.data === '__CLOSED__') { es.close(); st.textContent = 'Disconnected'; return; }
        if (e.data === '__AUTH_FAILED__') {
            es.close();
            localStorage.removeItem(STORE);
            showLogin('Authentication failed — enter your credentials.');
            return;
        }
        try { term.write(atob(e.data)); } catch {}
    };
    es.addEventListener('open', () => {
        st.textContent = 'Connected'; term.focus(); fit.fit();
        fetch('ssh_resize.php?token=' + token + '&cols=' + term.cols + '&rows=' + term.rows,
              { method: 'POST' });
    });
    es.onerror = () => { st.textContent = 'Disconnected'; es.close(); };
}

async function connect(user, pass) {
    showTerminal();
    const token = await mintToken(user, pass);
    openStream(token);
}

// Auto-connect if credentials stored locally
const stored = (() => { try { return JSON.parse(localStorage.getItem(STORE)); } catch { return null; } })();
if (stored?.user) {
    connect(stored.user, stored.pass || '').catch(err => {
        localStorage.removeItem(STORE);
        showLogin('Connection failed: ' + err.message);
    });
}

document.getElementById('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    const user = document.getElementById('fUser').value.trim();
    const pass = document.getElementById('fPass').value;
    if (!user) return;
    if (document.getElementById('fRem').checked) {
        localStorage.setItem(STORE, JSON.stringify({ user, pass }));
    } else {
        localStorage.removeItem(STORE);
    }
    try {
        await connect(user, pass);
    } catch (err) {
        showErr('Error: ' + err.message);
    }
});
</script>
</body>
</html>
