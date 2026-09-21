<?php
/**
 * igate-status.php — public endpoint: NetBird online/enabled status by hostname
 *
 * Used by the map sidebar to show blinking-red for offline (but enabled) iGates.
 * No auth required — only exposes connectivity state, not device details.
 *
 * Response: { polling_active: bool, devices: { "<hostname>": { enabled: bool, online: bool|null } } }
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */
require_once __DIR__ . '/yaml_lib.php';

$pollingActive = (trim(@shell_exec('systemctl is-active netbird-poller.service 2>/dev/null') ?? '') === 'active');

$statsRaw = @file_get_contents(__DIR__ . '/stats.json');
$stats    = $statsRaw ? (json_decode($statsRaw, true) ?? []) : [];

$onlineMap = [];
foreach (($stats['devices'] ?? []) as $sd) {
    $ip = $sd['ip'] ?? '';
    if ($ip) $onlineMap[$ip] = !empty($sd['online']);
}

$devices = loadDevices(__DIR__ . '/addresses.yaml');
$out = [];
foreach ($devices as $d) {
    $host = $d['host'] ?? '';
    $ip   = $d['ip']   ?? '';
    if (!$host) continue;
    $out[$host] = [
        'enabled' => (bool)($d['enabled'] ?? true),
        'online'  => array_key_exists($ip, $onlineMap) ? $onlineMap[$ip] : null,
    ];
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['polling_active' => $pollingActive, 'devices' => $out]);
