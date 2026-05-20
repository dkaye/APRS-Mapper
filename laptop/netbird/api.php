<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$statsFile     = __DIR__ . '/stats.json';
$pidFile       = __DIR__ . '/daemon.pid';
$addressesFile = __DIR__ . '/addresses.cfg';

// ── Record that a browser is actively watching ────────────────────────────────
// (Skip for the addresses editor fetch — only count stats fetches)
if (($_GET['action'] ?? '') !== 'get_addresses') {
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
    // Page load: force immediate daemon poll so devices appear as Pending right away
    if (!empty($_GET['init'])) {
        @file_put_contents(__DIR__ . '/poll_force', time());
    }
}

// ── Separate action: return addresses.cfg text for the editor ─────────────────
if (($_GET['action'] ?? '') === 'get_addresses') {
    echo json_encode([
        'content' => file_exists($addressesFile) ? file_get_contents($addressesFile) : '',
    ]);
    exit;
}

// ── Check daemon ──────────────────────────────────────────────────────────────
$daemonRunning = false;
if (file_exists($pidFile)) {
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid > 0) {
        $daemonRunning = file_exists("/proc/$pid") || (posix_kill($pid, 0) === true);
    }
}

// ── Request immediate daemon poll if data is stale ───────────────────────────
// Daemon checks poll_now each loop; honours it at most once per repeat_seconds.
$now         = time();
$statsRaw2   = file_exists($statsFile) ? @json_decode(@file_get_contents($statsFile), true) : null;
$lastSendTs  = $statsRaw2['last_send_ts']  ?? 0;
$cfgRepeat   = max(10, (int)($statsRaw2['repeat_seconds'] ?? 60));
if ($now - $lastSendTs >= $cfgRepeat) {
    @file_put_contents(__DIR__ . '/poll_now', $now);
}

// ── Build device list from YAML, supplemented by stats.json ──────────────────
// YAML is the authoritative source of which devices exist and their enabled state.
// stats.json supplies poll results (online, last_response, response_data, etc.).
// Devices in YAML but not yet in stats.json still appear with correct enabled state.

require_once __DIR__ . '/yaml_lib.php';
$yamlDevices = loadDevices(__DIR__ . '/addresses.yaml');

// Parse stats.json if available; build a lookup by IP.
$data    = [];
$statsMap = [];
if (file_exists($statsFile)) {
    $raw = json_decode(file_get_contents($statsFile), true);
    if ($raw !== null) {
        $data = $raw;
        foreach ($raw['devices'] ?? [] as $sd) {
            if (isset($sd['ip'])) $statsMap[$sd['ip']] = $sd;
        }
    }
}
$data['daemon_running'] = $daemonRunning;

$merged = [];
foreach ($yamlDevices as $yd) {
    $ip        = $yd['ip'];
    $sd        = $statsMap[$ip] ?? [];
    $isEnabled = (bool)$yd['enabled'];
    $wasEnabled = (bool)($sd['enabled'] ?? true);

    // Start from stats record (preserves hostname, response_data, last_response, etc.)
    $dev = $sd;

    // YAML fields always win for identity and enabled state
    $dev['ip']       = $ip;
    $dev['name']     = $yd['name']  ?? ($dev['name']  ?? '');
    $dev['host']     = $yd['host']  ?? ($dev['host']  ?? '');
    $dev['hostname'] = $yd['host']  ?? '';   // daemon stores this as 'hostname'; keep in sync
    $dev['group']    = $yd['group'] ?? ($dev['group'] ?? '');
    $dev['enabled']  = $isEnabled;

    if (!$wasEnabled && $isEnabled) {
        // Became enabled since last daemon cycle; reset history so status shows
        // Disabled until the next poll is sent (last_request >= enabled_at).
        $dev['enabled_at']    = $now;   // always current time, not stale stats value
        $dev['online']        = false;
        $dev['last_request']  = null;   // clear so old request time isn't used
        $dev['last_response'] = null;
        $dev['response_data'] = null;
    } elseif ($wasEnabled && !$isEnabled) {
        $dev['online']     = false;
        $dev['enabled_at'] = null;
    }

    // Disabled devices are never online regardless of daemon state
    // (catches stale stats.json data when wasEnabled was already false)
    if (!$isEnabled) $dev['online'] = false;

    $merged[] = $dev;
}
$data['devices'] = $merged;

// On page load (init=1): return all enabled devices as Pending so the browser
// starts clean — fresh poll results will arrive within a few seconds.
if (!empty($_GET['init'])) {
    foreach ($data['devices'] as &$dev) {
        if ($dev['enabled']) {
            $dev['last_request'] = null;
            $dev['online']       = false;
        }
    }
    unset($dev);
}

echo json_encode($data);
