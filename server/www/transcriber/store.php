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

function transcriber_token(): string
{
    return bin2hex(random_bytes(16));
}
