<?php
/**
 * ssh_stream.php — MARS APRS NetBird
 *
 * SSE relay for the SSH terminal. Reads one-time token credentials, spawns
 * ssh_relay.py via proc_open, and forwards its base64 output as SSE events.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */
$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(400); exit; }

$credsFile = "/tmp/aprs_ssh_{$token}.creds";
if (!file_exists($credsFile)) { http_response_code(404); exit; }

$creds = json_decode(file_get_contents($credsFile), true);
unlink($credsFile);

if (!$creds || empty($creds['ip']) || empty($creds['user'])) { http_response_code(400); exit; }

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();
flush();

$relay = __DIR__ . '/ssh_relay.py';
$cmd = '/usr/bin/python3 -u ' . escapeshellarg($relay) . ' '
     . escapeshellarg($token) . ' '
     . escapeshellarg($creds['ip']) . ' '
     . escapeshellarg($creds['user']) . ' '
     . escapeshellarg($creds['pass'] ?? '');

$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open($cmd, $desc, $pipes);
if (!is_resource($proc)) {
    echo 'data: ' . base64_encode("\r\nFailed to start relay.\r\n") . "\n\n";
    flush();
    exit;
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$buf = '';
while (true) {
    $r = [$pipes[1]]; $w = $e = null;
    if (stream_select($r, $w, $e, 0, 50000) && $r) {
        $chunk = fread($pipes[1], 16384);
        if ($chunk !== false && $chunk !== '') {
            $buf .= $chunk;
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                if ($line !== '') echo "data: {$line}\n\n";
            }
            flush();
        }
    }

    $re = [$pipes[2]]; $w = $e = null;
    if (stream_select($re, $w, $e, 0, 0) && $re) {
        $err = fread($pipes[2], 512);
        if ($err) {
            echo 'data: ' . base64_encode("\r\n[relay] " . trim($err) . "\r\n") . "\n\n";
            flush();
        }
    }

    $status = proc_get_status($proc);
    if (!$status['running']) {
        // Drain any remaining output
        while (($chunk = fread($pipes[1], 16384)) !== false && $chunk !== '') $buf .= $chunk;
        foreach (array_filter(explode("\n", $buf)) as $line) echo "data: {$line}\n\n";
        flush();
        break;
    }
}

fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
echo "data: __CLOSED__\n\n";
flush();
