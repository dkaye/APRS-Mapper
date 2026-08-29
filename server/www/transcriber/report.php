<?php
/**
 * Transcriber calibration report — marsaprs.org/transcriber/report.php
 *
 * The one thing a device tells the server about itself. Everything else a Transcriber
 * does is a fetch — it collects its channels and acts on them — but a calibration takes a
 * channel off the air for a couple of minutes, and the manager cannot show an honest
 * countdown for something it can only guess the start of. So the device says when it has
 * started, how long it expects to take, and what it measured:
 *
 *   POST /transcriber/report.php
 *   {"device":"rx1","token":"…","channel":"rx1-146520","state":"started","expected":90}
 *   {"device":"rx1","token":"…","channel":"rx1-146520","state":"done","gain":16.6,"squelch":30}
 *   {"device":"rx1","token":"…","channel":"rx1-146520","state":"failed","error":"…"}
 *   {"device":"rx1","token":"…","channel":"rx1-146520","state":"level","level_db":-27.4}
 *   {"device":"rx1","token":"…","channel":"rx1-146520","state":"alive","last_heard":…}
 *
 * The fourth is the calibration meter's feed: a band-limited (200-4000 Hz) audio level,
 * sent about once a second while a channel is being levelled by hand. It is kept apart
 * from the calibration record because it is worthless three seconds later.
 *
 * The last is the heartbeat, once a minute for as long as the channel is running. It
 * exists because a channel that cannot start and a channel on a quiet band produce the
 * same thing -- no log entries -- and on 2026-08-29 that let this receiver crash-loop for
 * eighty-three minutes with nothing anywhere reporting a fault. A level reading is
 * worthless three seconds later; a heartbeat is worth most when it stops arriving.
 *
 * The DEVICE token, and the channel is checked against the device that owns it. The two
 * kinds of token do not blur: a device token fetches this device's configuration and now
 * reports about this device's channels; a channel token writes log entries and nothing
 * else. A Transcriber left in a shed still cannot read the net's traffic, and cannot
 * speak for a receiver on another hill.
 *
 * In the body rather than the query string, unlike get.php: a token in a URL is a token
 * in the access log, and this is a new path with no deployed callers to keep working.
 *
 * Nothing here reaches the event log. Calibration state is a fact about a receiver, not
 * something that happened on the air.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

require_once __DIR__ . '/store.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST only']));
}

$body    = json_decode((string)file_get_contents('php://input'), true);
$body    = is_array($body) ? $body : [];
$device  = trim((string)($body['device']  ?? ''));
$token   = trim((string)($body['token']   ?? ''));
$channel = trim((string)($body['channel'] ?? ''));

if (!transcriber_device_ok($device, $token)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

// A valid token for the wrong channel is still the wrong channel. Refused the same way an
// unknown one is, and deliberately not distinguished in the answer: which channels exist
// and who owns them is not something a device gets to enumerate.
if (transcriber_channel_device($channel) !== $device) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

// A level reading is not a calibration state, so it does not go through
// record_calibration's state machine at all: it has no started/finished, it does not
// belong in the durable record, and it arrives about once a second while somebody is
// setting a knob. Same authentication, different store, and it answers immediately so a
// device streaming these is never waiting on a lock.
//
// The level is band-limited (200-4000 Hz) by the DEVICE before it is sent. That is not
// an implementation detail to move here later: judging a radio's level by its raw peak is
// what set the volume to zero on 2026-08-29, because the peak belonged to a squelch thump
// generated after the volume control. The number crossing this wire is the audio band or
// it is useless.
if (($body['state'] ?? '') === 'level') {
    transcriber_level_update($channel,
        (float)($body['level_db'] ?? -99),
        (float)($body['wide_db']  ?? -99));
    exit(json_encode(['ok' => true]));
}

// A heartbeat, for the same reason a level reading is here: same authentication, same
// "this device may speak only for its own channels" check, and no business in the
// calibration record. It answers immediately -- a channel posting once a minute must
// never be waiting on a lock to go back to listening.
if (($body['state'] ?? '') === 'alive') {
    transcriber_heartbeat_update($channel, $body);
    exit(json_encode(['ok' => true]));
}

$row = transcriber_record_calibration($channel, $body);
if (!empty($row['refused'])) {
    http_response_code(400);
    exit(json_encode(['error' => $row['error']]));
}
echo json_encode(['ok' => true]);
