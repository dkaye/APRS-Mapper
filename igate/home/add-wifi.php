#!/usr/bin/env php
<?php

/**
 * add_wifi.ph
 *
 * Adds a Wi-Fi access point entry to wifi.conf by accepting a friendly name,
 * SSID, and password, hashing the password via wpa_passphrase, and appending
 * the resulting record to the configuration file. After writing the entry,
 * update-wifi.php is invoked automatically to apply the new configuration.
 *
 * Note: changes made by this script will be overwritten the next time
 * auto_update runs.
 *
 * @author    Doug Kaye (K6DRK)
 * @copyright 2026 Doug Kaye
 * @version   1.0
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

// Append to wifi.conf
$line = "$name,$ssid,$psk\n";
$file = __DIR__ . '/wifi.conf';

if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "Error: could not write to $file.\n");
    exit(1);
}

// Run update-wifi.php
passthru(__DIR__ . '/update-wifi.php', $ret);

echo "\n";
echo "\033[0;31m=============================\n";
echo "Added '$name' to wifi.conf as the lowest priority access point\n";
echo "Be aware that this device will connect to any other known SSID before it connects to '$name'.\n";
echo "Don't forget to reboot this device for this addition to take effect.\n";
echo "\033[1;31mNB: This addition will be overwritten by the next auto_update!\n";
echo "\033[0;31m=============================\033[0m\n";

exit($ret);
