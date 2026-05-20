<?php
session_start();
if (empty($_SESSION['stats_auth'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#1a1a1a;color:#f87171">'
       . '<p>Not authenticated. Please <a href="index.php" style="color:#60a5fa">sign in</a> first.</p>'
       . '</body></html>';
    exit;
}

require_once __DIR__ . '/yaml_lib.php';
$ip      = trim($_GET['ip'] ?? '');
$devices = loadDevices(__DIR__ . '/addresses.yaml');
$device  = null;
foreach ($devices as $d) {
    if ($d['ip'] === $ip) { $device = $d; break; }
}
if (!$device) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#1a1a1a;color:#f87171">'
       . '<p>Device not found.</p></body></html>';
    exit;
}

$sshUser  = $device['ssh_user'] ?? '';
$sshPass  = $device['ssh_pass'] ?? '';
$needUser = ($sshUser === '');
$needPass = ($sshPass === '');
$needForm = $needUser || $needPass;

// If we have everything, mint a token server-side — password never touches the browser
$autoToken = null;
if (!$needForm) {
    $tok = bin2hex(random_bytes(16));
    if (!isset($_SESSION['ssh_sessions'])) $_SESSION['ssh_sessions'] = [];
    foreach ($_SESSION['ssh_sessions'] as $k => $s) {
        if (time() - ($s['ts'] ?? 0) > 7200) unset($_SESSION['ssh_sessions'][$k]);
    }
    $_SESSION['ssh_sessions'][$tok] = ['ip' => $ip, 'user' => $sshUser, 'pass' => $sshPass, 'ts' => time()];
    $autoToken = $tok;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SSH — <?= htmlspecialchars($device['name']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;background:#0f172a;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;overflow:hidden}
body{display:flex;flex-direction:column}

/* Title bar */
#bar{background:#1e293b;border-bottom:1px solid #334155;padding:7px 14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
#bar h1{font-size:13px;font-weight:500;color:#e2e8f0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#bar .ip{font-size:11px;color:#64748b;font-family:monospace}
#conn-badge{font-size:11px;padding:2px 9px;border-radius:10px;background:#1e293b;border:1px solid #334155;color:#64748b;flex-shrink:0}
#exit-btn{background:#1e293b;border:1px solid #475569;color:#94a3b8;padding:3px 12px;border-radius:4px;cursor:pointer;font-size:12px;flex-shrink:0}
#exit-btn:hover{background:#ef4444;border-color:#ef4444;color:#fff}

/* Auth form */
#auth-wrap{flex:1;display:flex;align-items:center;justify-content:center}
#auth-box{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:28px 32px;width:320px}
#auth-box h2{font-size:15px;font-weight:600;color:#f1f5f9;margin-bottom:20px}
.fr{margin-bottom:13px}
.fr label{display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.fr input[type=text],.fr input[type=password]{width:100%;padding:8px 10px;background:#0f172a;border:1px solid #475569;border-radius:5px;color:#e2e8f0;font-size:14px;font-family:monospace}
.fr input:focus{outline:none;border-color:#3b82f6}
.chk{display:flex;align-items:center;gap:8px;margin-bottom:18px;font-size:13px;color:#94a3b8;cursor:pointer}
.chk input{cursor:pointer;accent-color:#3b82f6;width:15px;height:15px;flex-shrink:0}
.btn-conn{width:100%;padding:9px;background:#2563eb;border:none;color:#fff;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer}
.btn-conn:hover{background:#1d4ed8}
.btn-conn:disabled{opacity:.55;cursor:default}
#auth-err{color:#f87171;font-size:13px;margin-top:10px;min-height:18px}

/* Terminal */
#term-wrap{flex:1;display:none;overflow:hidden;padding:2px}
#terminal{height:100%}
.xterm .xterm-viewport{border-radius:0!important}
</style>
</head>
<body>

<div id="bar">
  <h1>SSH — <?= htmlspecialchars($device['name']) ?></h1>
  <span class="ip"><?= htmlspecialchars($ip) ?></span>
  <span id="conn-badge">●&nbsp; Connecting…</span>
  <button id="exit-btn" onclick="doExit()">Exit</button>
</div>

<?php if ($needForm): ?>
<div id="auth-wrap">
  <div id="auth-box">
    <h2>SSH Authentication</h2>
    <div class="fr" id="user-wrap"<?= $needUser ? '' : ' style="display:none"' ?>>
      <label>Username</label>
      <input type="text" id="f-user" value="<?= htmlspecialchars($sshUser) ?>"
             autocomplete="username" spellcheck="false" autocapitalize="off">
    </div>
    <div class="fr">
      <label>Password</label>
      <input type="password" id="f-pass" autocomplete="current-password">
    </div>
    <label class="chk">
      <input type="checkbox" id="f-remember">
      Remember password
    </label>
    <button class="btn-conn" id="conn-btn" onclick="doAuth()">Connect</button>
    <div id="auth-err"></div>
  </div>
</div>
<?php endif; ?>

<div id="term-wrap">
  <div id="terminal"></div>
</div>

<script>
'use strict';
const IP         = <?= json_encode($ip) ?>;
const STORED_USER = <?= json_encode($sshUser) ?>;
const AUTO_TOKEN  = <?= json_encode($autoToken) ?>;

let term, fitAddon, token, evtSource, wasConnected = false;

function doExit() {
    if (evtSource) { evtSource.close(); evtSource = null; }
    window.close();
}

function setBadge(text, color) {
    const b = document.getElementById('conn-badge');
    b.textContent = '● ' + text;
    b.style.color = color;
    b.style.borderColor = color + '55';
}

function initTerm() {
    term = new Terminal({
        cursorBlink: true,
        fontSize: 14,
        fontFamily: '"SF Mono",SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace',
        theme: { background: '#0f172a', foreground: '#e2e8f0', cursor: '#94a3b8' },
        scrollback: 5000,
        allowProposedApi: true,
    });
    fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(document.getElementById('terminal'));
    fitAddon.fit();
    window.addEventListener('resize', () => { fitAddon.fit(); if (token) sendResize(); });

    term.onData(data => {
        if (!token) return;
        const fd = new FormData();
        fd.append('token', token);
        fd.append('data', data);
        fetch('ssh_input.php', { method: 'POST', body: fd }).catch(() => {});
    });
}

function sendResize() {
    const fd = new FormData();
    fd.append('token', token);
    fd.append('cols', term.cols);
    fd.append('rows', term.rows);
    fetch('ssh_resize.php', { method: 'POST', body: fd }).catch(() => {});
}

function openTerminal(tok) {
    token = tok;
    document.getElementById('auth-wrap') && (document.getElementById('auth-wrap').style.display = 'none');
    const tw = document.getElementById('term-wrap');
    tw.style.display = 'block';
    setBadge('Connecting…', '#64748b');

    initTerm();

    evtSource = new EventSource('ssh_stream.php?token=' + encodeURIComponent(tok)
        + '&cols=' + term.cols + '&rows=' + term.rows);

    evtSource.onmessage = e => {
        term.write(atob(e.data));
    };
    evtSource.addEventListener('connected', () => {
        wasConnected = true;
        setBadge('Connected', '#22c55e');
        term.focus();
    });
    evtSource.addEventListener('error_msg', e => {
        term.writeln('\r\n\x1b[31mError: ' + e.data + '\x1b[0m\r\n');
        setBadge('Error', '#f87171');
        evtSource.close();
    });
    evtSource.onerror = () => {
        if (evtSource.readyState === EventSource.CLOSED) {
            if (wasConnected) { window.close(); } else { setBadge('Disconnected', '#64748b'); }
        }
    };
}

function doAuth() {
    const user = document.getElementById('f-user')?.value.trim() || STORED_USER;
    const pass = document.getElementById('f-pass')?.value ?? '';
    const remember = document.getElementById('f-remember')?.checked ?? false;

    if (!user || pass === '') {
        document.getElementById('auth-err').textContent = 'Username and password are required.';
        return;
    }

    const btn = document.getElementById('conn-btn');
    btn.disabled = true; btn.textContent = 'Connecting…';
    document.getElementById('auth-err').textContent = '';

    const fd = new FormData();
    fd.append('ip',       IP);
    fd.append('user',     user);
    fd.append('pass',     pass);
    fd.append('remember', remember ? '1' : '0');

    fetch('ssh_start.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Connect';
            if (d.error) {
                document.getElementById('auth-err').textContent = d.error;
                const userWrap = document.getElementById('user-wrap');
                if (userWrap) userWrap.style.display = 'block';
                const passField = document.getElementById('f-pass');
                if (passField) { passField.value = ''; passField.focus(); }
                return;
            }
            openTerminal(d.token);
        })
        .catch(e => {
            btn.disabled = false; btn.textContent = 'Connect';
            document.getElementById('auth-err').textContent = 'Network error: ' + e.message;
        });
}

// Enter key on password field
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('f-pass')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') doAuth();
    });
    document.getElementById('f-user')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            const p = document.getElementById('f-pass');
            if (p) p.focus(); else doAuth();
        }
    });
    if (AUTO_TOKEN) {
        openTerminal(AUTO_TOKEN);
    } else {
        setBadge('Auth required', '#64748b');
        document.getElementById('f-user')?.focus() || document.getElementById('f-pass')?.focus();
    }
});
</script>
</body>
</html>
