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

// Note when this device last collected its settings, so the manager can show a change
// landing instead of leaving somebody to guess whether it did.
transcriber_mark_fetch($device);

// update_requested carries the "Update devices now" button. A device compares it with
// the last one it honoured and, if this is newer, does a full update — software as well
// as configuration — instead of only re-reading its channels. Nothing is written back,
// so a device that was switched off catches up whenever it returns.
$state = transcriber_state_load();

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'channels'         => transcriber_channels_for($device),
    'update_requested' => $state['update_requested'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
