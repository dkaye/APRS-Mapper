#!/usr/bin/env php
<?php
/**
 * MARS APRS Stats Daemon
 * Sends "return stats" UDP requests to all configured devices,
 * receives responses, and writes stats.json for the web UI.
 *
 * Usage: sudo php daemon.php [port=1235] [repeat=60] [timeout=180] [debug]
 */

require_once __DIR__ . '/yaml_lib.php';

$config = [
    'port'            => 1235,
    'repeat_seconds'  => 60,
    'timeout_seconds' => 180,       // mark offline after this many seconds with no response
    'yaml_file'       => __DIR__ . '/addresses.yaml',
    'stats_file'      => __DIR__ . '/stats.json',
    'pid_file'        => __DIR__ . '/daemon.pid',
    'log_file'        => __DIR__ . '/daemon.log',
    'request_message' => 'return stats',
    'debug'           => false,
];

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $args);
    foreach ($args as $key => $value) {
        switch ($key) {
            case 'port':    $config['port']            = (int)$value; break;
            case 'repeat':  $config['repeat_seconds']  = (int)$value; break;
            case 'timeout': $config['timeout_seconds'] = (int)$value; break;
            case 'debug':   $config['debug']           = true;        break;
            default:
                fwrite(STDERR, "Unknown argument: $key\n");
                fwrite(STDERR, "Usage: daemon.php [port=N] [repeat=N] [timeout=N] [debug]\n");
                exit(1);
        }
    }
}

function logMsg(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    echo $line;
}

function dbg(string $msg): void {
    global $config;
    if ($config['debug']) logMsg($msg);
}


function saveStats(array &$stats, array $config, int $lastSendTime): void {
    $payload = [
        'last_updated'    => date('c'),
        'last_updated_ts' => time(),
        'last_send_ts'    => $lastSendTime,
        'repeat_seconds'  => $config['repeat_seconds'],
        'devices'         => array_values($stats),
    ];
    $tmp = $config['stats_file'] . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT));
    rename($tmp, $config['stats_file']);
    @chmod($config['stats_file'], 0644);
}

// ── PID file ──────────────────────────────────────────────────────────────────
file_put_contents($config['pid_file'], getmypid());
@chmod($config['pid_file'], 0644);

register_shutdown_function(function () use ($config) {
    @unlink($config['pid_file']);
    logMsg("Daemon stopped.");
});

// Handle SIGTERM / SIGINT gracefully
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () { exit(0); });
    pcntl_signal(SIGINT,  function () { exit(0); });
}

// ── UDP socket ────────────────────────────────────────────────────────────────
$sock = socket_create(AF_INET, SOCK_DGRAM, 0);
if ($sock === false) {
    $e = socket_last_error();
    die("Can't create socket: " . socket_strerror($e) . "\n");
}

// SO_REUSEADDR so restart doesn't fail immediately
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($sock, '0.0.0.0', $config['port'])) {
    $e = socket_last_error($sock);
    die("Can't bind to port {$config['port']}: " . socket_strerror($e) . "\n");
}
socket_set_nonblock($sock);

logMsg("Status daemon started (PID=" . getmypid()
    . ", port={$config['port']}"
    . ", repeat={$config['repeat_seconds']}s"
    . ", timeout={$config['timeout_seconds']}s)");

// ── Main loop ─────────────────────────────────────────────────────────────────
$stats           = [];
$lastSendTime    = 0;
$addresses       = [];
$wasViewerActive = false;
$lastViewerFile  = __DIR__ . '/last_viewer.ts';
$viewerTimeout   = 45; // seconds of silence before pausing polls
$lastConfigCheck = 0;

while (true) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    $now = time();

    // ── Config check every 5 s (picks up interval changes from web UI) ───────
    if ($now - $lastConfigCheck >= 5) {
        $cfgOverride = __DIR__ . '/config.json';
        if (file_exists($cfgOverride)) {
            $ov = json_decode(file_get_contents($cfgOverride), true) ?: [];
            if (isset($ov['repeat_seconds'])) {
                $newRepeat = max(10, min(3600, (int)$ov['repeat_seconds']));
                if ($newRepeat !== $config['repeat_seconds']) {
                    $config['repeat_seconds'] = $newRepeat;
                    logMsg("Config: repeat_seconds → {$config['repeat_seconds']}s");
                    saveStats($stats, $config, $lastSendTime);
                }
            }
        }
        $lastConfigCheck = $now;
    }

    // ── Viewer presence check ─────────────────────────────────────────────────
    $lastViewerTs = file_exists($lastViewerFile) ? (int)file_get_contents($lastViewerFile) : 0;
    $viewerActive = $lastViewerTs > 0 && ($now - $lastViewerTs) < $viewerTimeout;

    if ($viewerActive && !$wasViewerActive) {
        logMsg("Browser connected — resuming polls");
        $lastSendTime = 0; // trigger immediate send
    } elseif (!$viewerActive && $wasViewerActive) {
        logMsg("No active browsers — pausing polls");
    }
    $wasViewerActive = $viewerActive;

    // ── Send cycle (only when browsers are watching) ───────────────────────────
    if ($viewerActive && $now >= $lastSendTime + $config['repeat_seconds']) {
        // Pick up runtime config changes (poll interval set via web UI)
        $cfgOverride = __DIR__ . '/config.json';
        if (file_exists($cfgOverride)) {
            $ov = json_decode(file_get_contents($cfgOverride), true) ?: [];
            if (isset($ov['repeat_seconds'])) {
                $newRepeat = max(10, min(3600, (int)$ov['repeat_seconds']));
                if ($newRepeat !== $config['repeat_seconds']) {
                    $config['repeat_seconds'] = $newRepeat;
                    logMsg("Config: repeat_seconds → {$config['repeat_seconds']}s");
                }
            }
        }

        // Load ALL devices; only enabled ones get UDP requests
        $rawDevices = loadDevices($config['yaml_file']);
        $addresses  = [];
        foreach ($rawDevices as $dev) {
            $addresses[$dev['ip']] = [
                'hostname' => $dev['host'],
                'name'     => $dev['name'],
                'group'    => $dev['group'],
                'enabled'  => $dev['enabled'] ?? true,
            ];
        }

        // Merge new addresses into stats; preserve existing response data
        $seen = [];
        foreach ($addresses as $ip => $info) {
            $seen[$ip] = true;
            if (!isset($stats[$ip])) {
                $stats[$ip] = [
                    'ip'            => $ip,
                    'hostname'      => $info['hostname'],
                    'name'          => $info['name'],
                    'group'         => $info['group'],
                    'enabled'       => $info['enabled'],
                    'enabled_at'    => $info['enabled'] ? $now : null,
                    'last_request'  => null,
                    'last_response' => null,
                    'response_data' => null,
                    'online'        => false,
                ];
            } else {
                // If device just became re-enabled, record the time and clear stale history
                if (!($stats[$ip]['enabled'] ?? true) && $info['enabled']) {
                    $stats[$ip]['enabled_at']    = $now;
                    $stats[$ip]['last_response'] = null;
                    $stats[$ip]['response_data'] = null;
                    $stats[$ip]['online']        = false;
                }
                $stats[$ip]['hostname'] = $info['hostname'];
                $stats[$ip]['name']     = $info['name'];
                $stats[$ip]['group']    = $info['group'];
                $stats[$ip]['enabled']  = $info['enabled'];
            }
        }
        // Remove devices no longer in the config
        foreach (array_keys($stats) as $ip) {
            if (!isset($seen[$ip])) unset($stats[$ip]);
        }

        // Send requests to enabled devices only
        $sent = 0; $enabledCount = 0;
        foreach ($addresses as $ip => $info) {
            if (!$info['enabled']) continue;
            $enabledCount++;
            $r = @socket_sendto($sock, $config['request_message'],
                                strlen($config['request_message']), 0, $ip, $config['port']);
            if ($r === false) {
                $e = socket_last_error($sock);
                logMsg("Send failed → {$ip} ({$info['hostname']} {$info['name']}): " . socket_strerror($e));
            } else {
                $stats[$ip]['last_request'] = $now;
                $sent++;
                dbg("Sent → {$ip} ({$info['hostname']} {$info['name']})");
            }
        }
        logMsg("Requests sent to $sent/$enabledCount enabled (" . count($addresses) . " total)");
        $lastSendTime = $now;
        saveStats($stats, $config, $lastSendTime);
    }

    // ── Receive loop (drain all pending packets) ───────────────────────────────
    $gotAny = false;
    while (true) {
        $buf        = '';
        $remote_ip  = '';
        $remote_port = 0;
        $r = @socket_recvfrom($sock, $buf, 1024, 0, $remote_ip, $remote_port);
        if ($r === false || $r === 0) break;

        $gotAny = true;
        $now    = time();

        if (isset($stats[$remote_ip])) {
            $stats[$remote_ip]['last_response'] = $now;
            $stats[$remote_ip]['response_data'] = trim($buf);
            $stats[$remote_ip]['online']        = true;
            dbg("Response ← {$remote_ip}: " . trim($buf));
        } else {
            logMsg("Response from unknown IP {$remote_ip} (port {$remote_port}): " . trim($buf));
        }
    }

    // ── Timeout check ─────────────────────────────────────────────────────────
    $now = time();
    foreach ($stats as $ip => &$device) {
        if ($device['last_response'] !== null) {
            $device['online'] = ($now - $device['last_response']) < $config['timeout_seconds'];
        }
    }
    unset($device);

    if ($gotAny) saveStats($stats, $config, $lastSendTime);

    usleep(100000); // 100 ms poll interval
}
