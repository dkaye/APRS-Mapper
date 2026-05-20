<?php
session_start();
if (empty($_SESSION['stats_auth'])) { http_response_code(403); exit; }

$token   = trim($_GET['token'] ?? '');
$session = $_SESSION['ssh_sessions'][$token] ?? null;
session_write_close();

if (!$session) { http_response_code(400); echo "Invalid token"; exit; }

['ip' => $ip, 'user' => $user, 'pass' => $pass] = $session;

// ── SSE setup ─────────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) apache_setenv('no-gzip', '1');
while (ob_get_level()) ob_end_clean();
set_time_limit(0);
ignore_user_abort(true);

function sseEvent(string $event, string $data): void {
    echo "event: $event\ndata: $data\n\n";
    flush();
}

// ── Spawn Python relay ────────────────────────────────────────────────────────
$queueFile = sys_get_temp_dir() . "/aprs_ssh_{$token}.q";
$relay     = __DIR__ . '/ssh_relay.py';
$cols      = max(40, min(500, (int)($_GET['cols'] ?? 200)));
$rows      = max(10, min(300, (int)($_GET['rows'] ?? 50)));

$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open('/usr/bin/python3 ' . escapeshellarg($relay)
    . ' ' . escapeshellarg($ip)
    . ' ' . escapeshellarg($user)
    . ' ' . escapeshellarg($pass)
    . ' ' . escapeshellarg($queueFile)
    . ' ' . (int)$cols
    . ' ' . (int)$rows,
    $desc, $pipes);

if (!is_resource($proc)) {
    sseEvent('error_msg', 'Failed to start SSH relay.');
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$buf           = '';
$lastKeepalive = time();

// ── Main relay loop ───────────────────────────────────────────────────────────
while (true) {
    if (connection_aborted()) break;

    $chunk = @fread($pipes[1], 65536);
    if ($chunk === false || ($chunk === '' && feof($pipes[1]))) break;

    if ($chunk !== '') {
        $buf .= $chunk;
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = rtrim(substr($buf, 0, $nl), "\r");
            $buf  = substr($buf, $nl + 1);
            if ($line === 'CONNECTED') {
                sseEvent('connected', '1');
            } elseif (substr($line, 0, 6) === 'ERROR:') {
                sseEvent('error_msg', substr($line, 6));
                break 2;
            } elseif (substr($line, 0, 5) === 'DATA:') {
                echo "data: " . substr($line, 5) . "\n\n";
                flush();
            }
        }
    }

    if (time() - $lastKeepalive >= 20) {
        echo ": keepalive\n\n";
        flush();
        $lastKeepalive = time();
    }

    usleep(20000); // 20 ms
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
proc_terminate($proc);
proc_close($proc);
@unlink($queueFile);
@unlink($queueFile . '.resize');
