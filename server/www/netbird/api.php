<?php
/**
 * api.php — MARS APRS NetBird Status API
 *
 * JSON endpoint polled by index.php and admin.php for live device status.
 * No authentication required — read-only public status data.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/yaml_lib.php';

// Signal to the poller that a viewer is active
@file_put_contents(__DIR__ . '/last_viewer.ts', time());

// Is the APRS tracker daemon running?
$daemonRunning = (trim(@shell_exec('systemctl is-active aprs-daemon 2>/dev/null') ?? '') === 'active');

// Polling interval from config.json (written by save.php)
$configRaw     = @file_get_contents(__DIR__ . '/config.json');
$config        = $configRaw ? (json_decode($configRaw, true) ?? []) : [];
$repeatSeconds = (int)($config['repeat_seconds'] ?? 60);

// Live status from stats.json (written by the NetBird poller daemon)
$statsRaw   = @file_get_contents(__DIR__ . '/stats.json');
$stats      = $statsRaw ? (json_decode($statsRaw, true) ?? []) : [];
$lastSendTs = $stats['last_send_ts'] ?? null;

$onlineMap       = [];
$lastRequestMap  = [];
$lastResponseMap = [];
$responseDataMap = [];
foreach (($stats['devices'] ?? []) as $sd) {
    $ip = $sd['ip'] ?? '';
    if (!$ip) continue;
    $onlineMap[$ip]       = !empty($sd['online']);
    $lastRequestMap[$ip]  = $sd['last_request']  ?? null;
    $lastResponseMap[$ip] = $sd['last_response'] ?? null;
    $responseDataMap[$ip] = $sd['response_data'] ?? null;
}

// Merge static device list with live status
$devices = loadDevices(__DIR__ . '/addresses.yaml');
$out = [];
foreach ($devices as $d) {
    $ip    = $d['ip'] ?? '';
    $out[] = [
        'ip'            => $ip,
        'hostname'      => $d['host']  ?? '',
        'name'          => $d['name']  ?? '',
        'group'         => $d['group'] ?? '',
        'enabled'       => (bool)($d['enabled'] ?? true),
        'online'        => $onlineMap[$ip]       ?? false,
        'last_request'  => $lastRequestMap[$ip]  ?? null,
        'last_response' => $lastResponseMap[$ip] ?? null,
        'response_data' => $responseDataMap[$ip] ?? null,
    ];
}

echo json_encode([
    'daemon_running' => $daemonRunning,
    'repeat_seconds' => $repeatSeconds,
    'last_send_ts'   => $lastSendTs,
    'devices'        => $out,
]);
