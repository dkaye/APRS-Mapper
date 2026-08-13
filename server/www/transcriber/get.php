<?php
/**
 * Transcriber channel list download — marsaprs.org/transcriber/get.php
 *
 * Serves one device its own channels. Called nightly by auto-update.sh:
 *
 *   curl -fsS "https://marsaprs.org/transcriber/get.php?token=$(cat /home/pi/.transcriber-token)&device=$(hostname)"
 *
 * Through PHP rather than as a static file because the registry holds a token per
 * channel and must not sit in the web root at all — mobile_trackers.json was readable
 * by anyone until 2026-08-12 for exactly that reason. A device is also given only its
 * own channels, so one Transcriber's token does not disclose another's.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

require_once __DIR__ . '/store.php';

$given  = trim($_GET['token'] ?? '');
$device = trim($_GET['device'] ?? '');

if (!transcriber_device_ok($device, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden\n");
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['channels' => transcriber_channels_for($device)],
                 JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
