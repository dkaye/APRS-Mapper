#!/usr/bin/env php
<?php

/**
 * add-wifi.php
 *
 * Adds a Wi-Fi access point entry to /home/pi/wifi.yaml by accepting a
 * friendly name, SSID, and password, hashing the password via wpa_passphrase,
 * and appending the resulting YAML record to the file. After writing, invokes
 * update-wifi.php to apply the new configuration.
 *
 * Note: changes made by this script will be overwritten the next time
 * auto-update.sh runs.
 *
 * @author    Doug Kaye (K6DRK)
 * @copyright 2026 Doug Kaye
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

// Escape a value for a YAML double-quoted string
function yq(string $s): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}

// Append YAML entry to wifi.yaml
$entry = "- name: " . yq($name) . "\n"
       . "  ssid: " . yq($ssid) . "\n"
       . "  password: " . yq($password) . "\n"
       . "  encrypted: \"$psk\"\n";

$file = '/home/pi/wifi.yaml';

if (file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Error: could not write to $file.\n");
    exit(1);
}

// Apply the updated wifi.yaml
passthru('/home/pi/update-wifi.php', $ret);

echo "\n";
echo "\033[0;31m=============================\n";
echo "Added '$name' to wifi.yaml as the lowest priority access point.\n";
echo "Be aware that this device will connect to any other known SSID before '$name'.\n";
echo "Don't forget to reboot this device for this addition to take effect.\n";
echo "\033[1;31mNB: This addition will be overwritten by the next auto-update!\n";
echo "\033[0;31m=============================\033[0m\n";

exit($ret);
