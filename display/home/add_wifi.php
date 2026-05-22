#!/usr/bin/env php
<?php

/**
 * add_wifi.php — MARS APRS Display Pi
 *
 * Adds a WiFi network to wifi.yaml by accepting a friendly name, SSID, and
 * password, hashing via wpa_passphrase, and appending the YAML entry.
 * Runs update-wifi.php automatically to apply the new network.
 *
 * Note: changes will be overwritten by the next nightly auto-update.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */

function prompt(string $label): string {
    echo "$label: ";
    return trim(fgets(STDIN));
}

$name     = $argv[1] ?? prompt('Name');
$ssid     = $argv[2] ?? prompt('SSID');
$password = $argv[3] ?? prompt('Password');

if ($name === '' || $ssid === '' || $password === '') {
    fwrite(STDERR, "Error: name, ssid, and password are all required.\n");
    exit(1);
}

// Run wpa_passphrase
$cmd    = 'wpa_passphrase ' . escapeshellarg($ssid) . ' ' . escapeshellarg($password);
$output = shell_exec($cmd);

if ($output === null) {
    fwrite(STDERR, "Error: wpa_passphrase failed or is not installed.\n");
    exit(1);
}

// Extract the hashed psk (the line without #)
if (!preg_match('/^\s*psk=([0-9a-f]{64})\s*$/m', $output, $m)) {
    fwrite(STDERR, "Error: could not parse psk from wpa_passphrase output.\n");
    exit(1);
}
$psk = $m[1];

// Append YAML entry to wifi.yaml
$entry = "- name: \"$name\"\n  ssid: \"$ssid\"\n  password: \"\"\n  encrypted: \"$psk\"\n";
$file  = '/home/pi/wifi.yaml';

if (file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Error: could not write to $file.\n");
    exit(1);
}

// Run update-wifi.php
passthru('/home/pi/update-wifi.php', $ret);

echo "\n";
echo "\033[0;31m=============================\n";
echo "Added '$name' to wifi.yaml as the lowest priority access point.\n";
echo "Be aware that this device will connect to any other known SSID before it connects to '$name'.\n";
echo "Don't forget to reboot this device for this addition to take effect.\n";
echo "\033[1;31mNB: This addition will be overwritten by the next nightly auto-update!\n";
echo "\033[0;31m=============================\033[0m\n";

exit($ret);
