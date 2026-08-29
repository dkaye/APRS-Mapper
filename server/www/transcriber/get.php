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

// The event's vocabulary, re-read from the assignment sheet if nobody has looked in the
// last quarter of an hour. Here rather than on a timer because there is no timer: this is
// the only thing that runs by itself, and the sheet is edited on the morning of the event
// by somebody who will not be opening the channel manager. It is guarded to one fetch per
// TTL across the whole fleet and cannot fail the request — see the comment on it.
transcriber_vocabulary_refresh_if_stale();

header('Content-Type: application/json');
header('Cache-Control: no-store');
// What a receiver is CALLED, how carefully it transcribes, and whether it sends audio are
// facts about the event, not the hardware -- so they are overlaid here from the active
// event's own file rather than read from the registry. The registry keeps only what is
// true of the machine: which channel it is, and the token it logs with.
//
// Overlaid at serve time rather than stored merged, so changing the active event changes
// what the receiver is called at its next poll, with nothing to edit and nothing to
// migrate. An event with no file of its own falls back to the blanks in
// transcriber_event_load(), which is a working receiver with an empty label -- not a
// broken one.
$evName  = transcriber_event_name();
$evSet   = transcriber_event_load($evName);
$channels = transcriber_channels_for($device);
foreach ($channels as &$c) {
    if ($evSet['label'] !== '') $c['label'] = $evSet['label'];
    $c['model']      = $evSet['model'];
    $c['enabled']    = $evSet['enabled'];
    $c['send_audio'] = $evSet['send_audio'];
}
unset($c);

echo json_encode([
    'event'            => $evName,
    'channels'         => $channels,
    'update_requested' => $state['update_requested'],
    // `calibrate_requested` was here until 2026-08-29. It asked one channel to measure
    // its tuner gain and squelch, which a receiver with a hardware squelch does not have
    // and cannot do. Removed rather than left empty: a field that is always {} is a
    // promise the server has stopped keeping, and the updater already treats a request it
    // does not understand as nothing to act on — there is a test for exactly that, so an
    // old device meeting this response starts no measurement.
    // Fleet-wide, not per device: the whole event runs one assignment sheet, and a
    // receiver on any frequency may hear any station on it.
    //
    // Four keys: callsigns, tactical, terms, corrections. The first two are unchanged and
    // stay unchanged, because the field is never all on one version at once — a device
    // fetches this before its worker knows what `terms` is, and a worker on new code polls
    // a server that has not been deployed yet. Extra keys are ignored and absent ones read
    // as empty, at both ends.
    'vocabulary'       => transcriber_vocabulary_words(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
