<?php
/**
 * iGate WiFi list download — marsaprs.org/igate/wifi/get.php
 *
 * Serves wifi.yaml to authenticated iGates.
 * Authentication: shared token in /home/pi/.wifi-token (not in web root).
 *
 * Usage (on each iGate):
 *   wget -qO /home/pi/wifi.yaml \
 *       "https://marsaprs.org/igate/wifi/get.php?token=$(cat /home/pi/.wifi-token)"
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */

$dataFile  = __DIR__ . '/wifi.yaml';
$tokenFile = '/home/pi/.wifi-token';

$stored = file_exists($tokenFile) ? trim(file_get_contents($tokenFile)) : '';
$given  = trim($_GET['token'] ?? '');

if ($stored === '' || !hash_equals($stored, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Forbidden');
}

if (!file_exists($dataFile)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

header('Content-Type: text/yaml');
header('Cache-Control: no-store');
readfile($dataFile);
