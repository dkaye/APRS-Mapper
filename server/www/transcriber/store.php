<?php
/**
 * Transcriber registry — read/write for the channel manager and the device download.
 *
 * One file holds both halves of the fleet:
 *
 *   devices   [{host, token, note}]           a Pi, and the token it fetches config with
 *   channels  [{id, device, label, frequency, serial, squelch, model, enabled, token}]
 *
 * Two kinds of token, deliberately. A device token only fetches configuration; a
 * channel token only writes log entries. Neither can do the other's job, so a
 * Transcriber left in a shed with a readable config file cannot be used to read the
 * net's traffic.
 *
 * Stored beside messages.db, outside the web root. A registry of tokens under
 * /var/www/html is how mobile_trackers.json came to be downloadable by anyone.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */

if (!defined('MARSAPRS_CHANNELS')) {
    define('MARSAPRS_CHANNELS', getenv('MARSAPRS_CHANNELS') ?: '/var/lib/marsaprs/transcriber.json');
}

/** Every function takes an optional path so the registry can be pointed at a temp file
 *  in tests. The device-token check below is the one piece of this worth testing, and it
 *  could not be reached while it lived inside get.php, which reads a fixed path and
 *  exits. */
function transcriber_path(?string $path = null): string
{
    return $path ?: MARSAPRS_CHANNELS;
}

function transcriber_load(?string $path = null): array
{
    $file = transcriber_path($path);
    $raw = [];
    if (is_readable($file)) {
        $raw = json_decode((string)file_get_contents($file), true) ?: [];
    }
    return [
        'devices'  => array_values(array_filter($raw['devices']  ?? [], 'is_array')),
        'channels' => array_values(array_filter($raw['channels'] ?? [], 'is_array')),
    ];
}

function transcriber_save(array $data, ?string $path = null): void
{
    $file = transcriber_path($path);
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // Written then renamed: a device fetching mid-write would otherwise get a truncated
    // file, and auto-update.sh validates JSON precisely because that used to be possible.
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $file);
}

/** Whether this device may fetch its configuration.
 *
 *  Both arguments must be non-empty before anything is compared: an absent token must
 *  never match an absent stored one, which is the shape of bug that turns an
 *  unconfigured device into an authenticated one. */
function transcriber_device_ok(string $device, string $token, ?string $path = null): bool
{
    if ($device === '' || $token === '') return false;
    foreach (transcriber_load($path)['devices'] as $d) {
        if (($d['host'] ?? '') !== $device) continue;
        if (empty($d['token'])) continue;
        if (hash_equals((string)$d['token'], $token)) return true;
    }
    return false;
}

/** The channels one device should run, with the fields the worker needs. */
function transcriber_channels_for(string $device, ?string $path = null): array
{
    $out = [];
    foreach (transcriber_load($path)['channels'] as $c) {
        if (($c['device'] ?? '') !== $device) continue;
        $out[] = [
            'id'        => (string)($c['id'] ?? ''),
            'label'     => (string)($c['label'] ?? ''),
            'token'     => (string)($c['token'] ?? ''),
            'frequency' => (string)($c['frequency'] ?? ''),
            'serial'    => (string)($c['serial'] ?? ''),
            'squelch'   => (int)($c['squelch'] ?? 0),
            'model'     => (string)($c['model'] ?? 'ggml-tiny.en.bin'),
            'enabled'   => (bool)($c['enabled'] ?? true),
        ];
    }
    return $out;
}

/** A frequency in Hz, from whatever a person typed.
 *
 *  "147.465" is what anyone actually writes, and the first version of this stripped
 *  every non-digit — turning it into 1474650, or 1.47 MHz. That is not an error anyone
 *  sees: it saves, it looks like a number, the receiver tunes to a band it cannot hear
 *  and the channel simply never logs anything. It happened on the first real edit.
 *
 *  So a value under 1000 is read as MHz, which is the only way a human writes a VHF or
 *  UHF frequency, and anything larger is already Hz. The result is echoed back into the
 *  field on reload, so the interpretation is visible rather than assumed. */
function transcriber_hz($raw): string
{
    $s = preg_replace('/[^0-9.]/', '', (string)$raw);
    if ($s === '' || !is_numeric($s)) return '';
    $n = (float)$s;
    if ($n <= 0) return '';
    if ($n < 1000) $n *= 1_000_000;          // MHz
    return (string)(int)round($n);
}

/** MHz, as a person reads it: 147465000 -> "147.465". */
function transcriber_mhz($hz): string
{
    $hz = (int)$hz;
    if ($hz <= 0) return '';
    return rtrim(rtrim(number_format($hz / 1_000_000, 4, '.', ''), '0'), '.');
}

/** The systemd instance name and log identity for one channel, derived from the two
 *  things that actually define it. No '@' — that is systemd's instance separator. */
function transcriber_channel_id(string $device, $hz): string
{
    $device = preg_replace('/[^A-Za-z0-9_.-]/', '-', trim($device));
    $khz    = (int)round(((int)$hz) / 1000);
    if ($device === '' || $khz <= 0) return '';
    return substr("$device-$khz", 0, 64);
}

function transcriber_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Per-device state the manager shows and the devices act on.
 *
 *  Kept beside the registry rather than inside it: it changes on every device poll, and
 *  rewriting the file that holds every token that often is a good way to eventually lose
 *  one to a truncated write. Nothing here is secret.
 */
function transcriber_state_path(?string $path = null): string
{
    return dirname(transcriber_path($path)) . '/transcriber-state.json';
}

function transcriber_state_load(?string $path = null): array
{
    $f = transcriber_state_path($path);
    if (!is_readable($f)) return ['devices' => [], 'update_requested' => 0];
    $raw = json_decode((string)file_get_contents($f), true) ?: [];
    return ['devices' => $raw['devices'] ?? [], 'update_requested' => (int)($raw['update_requested'] ?? 0)];
}

function transcriber_state_save(array $s, ?string $path = null): void
{
    $f = transcriber_state_path($path);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    @chmod($tmp, 0640);
    rename($tmp, $f);
}

/** What this device's channels currently amount to.
 *
 *  Compared against what it was last served, this answers "has it got the change yet"
 *  without consulting a clock. It also answers it correctly for a device the change did
 *  not touch: its fingerprint still matches, so it is up to date rather than pending
 *  forever on an edit that was never about it.
 */
function transcriber_channels_fingerprint(string $device, ?string $path = null): string
{
    return hash('sha256', json_encode(transcriber_channels_for($device, $path)));
}

/** Record that this device just collected its settings, and what it was given. */
function transcriber_mark_fetch(string $device, ?string $path = null): void
{
    $s = transcriber_state_load($path);
    $s['devices'][$device] = [
        'last_fetch' => time(),
        'served'     => transcriber_channels_fingerprint($device, $path),
    ];
    transcriber_state_save($s, $path);
}

/** Per-device: when it last checked in, and whether what it holds is current. */
function transcriber_device_status(?string $path = null): array
{
    $state = transcriber_state_load($path);
    $out = [];
    foreach (transcriber_load($path)['devices'] as $d) {
        $host = (string)($d['host'] ?? '');
        if ($host === '') continue;
        $seen = $state['devices'][$host] ?? [];
        $out[] = [
            'host'       => $host,
            'last_fetch' => (int)($seen['last_fetch'] ?? 0),
            'up_to_date' => (($seen['served'] ?? '') === transcriber_channels_fingerprint($host, $path)),
        ];
    }
    return $out;
}

/** Ask every device to do a full update — software as well as configuration — the next
 *  time it looks. Devices compare this against the last one they honoured, so nothing
 *  has to be written back and a device that was switched off simply catches up. */
function transcriber_request_update(?string $path = null): int
{
    $s = transcriber_state_load($path);
    $s['update_requested'] = time();
    transcriber_state_save($s, $path);
    return $s['update_requested'];
}

/** A fingerprint of the registry as it stands, for detecting a stale editor.
 *
 *  Over the stored file rather than a version counter: nothing has to be incremented and
 *  it notices a change made by any route, including a hand edit of the JSON — which is
 *  precisely how the registry came to differ from an open page in the first place.
 */
function transcriber_fingerprint(?string $path = null): string
{
    $f = transcriber_path($path);
    return is_readable($f) ? hash('sha256', (string)file_get_contents($f)) : 'empty';
}
