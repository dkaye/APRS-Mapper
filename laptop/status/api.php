<?php
session_start();
if (empty($_SESSION['stats_auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');

$statsFile     = __DIR__ . '/stats.json';
$pidFile       = __DIR__ . '/daemon.pid';
$addressesFile = __DIR__ . '/addresses.cfg';

// ── Record that a browser is actively watching ────────────────────────────────
// (Skip for the addresses editor fetch — only count stats fetches)
if (($_GET['action'] ?? '') !== 'get_addresses') {
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
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

// ── Return stats ──────────────────────────────────────────────────────────────
if (!file_exists($statsFile)) {
    echo json_encode([
        'error'          => 'No stats data yet — is the daemon running?',
        'daemon_running' => $daemonRunning,
        'devices'        => [],
    ]);
    exit;
}

$data = json_decode(file_get_contents($statsFile), true);
if ($data === null) {
    echo json_encode([
        'error'          => 'stats.json is corrupt or unreadable.',
        'daemon_running' => $daemonRunning,
        'devices'        => [],
    ]);
    exit;
}

$data['daemon_running'] = $daemonRunning;

// Merge current YAML enabled state so toggling is reflected immediately,
// without waiting for the daemon's next cycle.
require_once __DIR__ . '/yaml_lib.php';
$yamlEnabled = [];
foreach (loadDevices(__DIR__ . '/addresses.yaml') as $yd) {
    $yamlEnabled[$yd['ip']] = (bool)$yd['enabled'];
}
$now = time();
foreach ($data['devices'] as &$dev) {
    $ip = $dev['ip'] ?? '';
    if (!array_key_exists($ip, $yamlEnabled)) continue;
    $wasEnabled = (bool)($dev['enabled'] ?? false);
    $isEnabled  = $yamlEnabled[$ip];
    $dev['enabled'] = $isEnabled;
    if (!$wasEnabled && $isEnabled) {
        // Just became enabled — use fresh enabled_at so JS shows Enabling
        $dev['enabled_at'] = $now;
        $dev['online']     = false;
    } elseif ($wasEnabled && !$isEnabled) {
        // Just disabled
        $dev['online']     = false;
        $dev['enabled_at'] = null;
    }
}
unset($dev);

echo json_encode($data);
