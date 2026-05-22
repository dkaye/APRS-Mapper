<?php
/**
 * igate-list.php — public endpoint: web-enabled iGates from addresses.yaml
 *
 * Returns JSON array of {host, name} for every device with web: true.
 * No auth required — host callsigns and location names are not sensitive.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */
require_once __DIR__ . '/yaml_lib.php';
$devices = loadDevices(__DIR__ . '/addresses.yaml');
$out = [];
foreach ($devices as $d) {
    if (!empty($d['web']) && !empty($d['host'])) {
        $out[] = ['host' => $d['host'], 'name' => $d['name']];
    }
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($out);
