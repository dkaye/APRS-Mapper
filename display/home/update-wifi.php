#!/usr/bin/env php
<?php
/**
 * update-wifi.php — apply /home/pi/wifi.yaml to NetworkManager
 *
 * Deletes all non-system WiFi connections, then re-adds every entry from
 * wifi.yaml in order, with descending autoconnect priority so the list order
 * controls which network is preferred.
 *
 * Usage:
 *   /home/pi/update-wifi.php [ssids=<path-to-yaml>] [debug]
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye. All Rights Reserved.
 */

$debugging    = false;
$ssidFilename = '/home/pi/wifi.yaml';
$connectionsToKeep = ['preconfigured', 'lo', 'wt0', 'wlan0', 'eth0'];

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
    foreach ($_GET as $key => $value) {
        switch ($key) {
            case 'ssids':  $ssidFilename = $value; break;
            case 'debug':  $debugging = true;       break;
            default:
                echo "Unknown argument: $key\n";
                echo "Usage: update-wifi.php [ssids=<yaml-file>] [debug]\n";
                exit(1);
        }
    }
}

function debug($msg) {
    global $debugging;
    if ($debugging) echo "$msg\n";
}

function fatal($msg) {
    echo "FATAL: $msg\n";
    exit(1);
}

/* ── YAML parser ── */

function parseWifiYaml(string $file): array {
    $entries = [];
    $cur = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^- name:\s*(.*)$/', $line, $m)) {
            if ($cur !== null) $entries[] = $cur;
            $cur = ['name' => yval($m[1]), 'ssid' => '', 'password' => '', 'encrypted' => ''];
        } elseif ($cur !== null && preg_match('/^\s+(ssid|password|encrypted):\s*(.*)$/', $line, $m)) {
            $cur[$m[1]] = yval($m[2]);
        }
    }
    if ($cur !== null) $entries[] = $cur;
    return $entries;
}

function yval(string $s): string {
    $s = trim($s);
    if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
        $s = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($s, 1, -1));
    }
    return $s;
}

/* ── nmcli helpers ── */

function deleteConnection(string $name): void {
    echo "Deleting: $name\n";
    exec('sudo nmcli connection delete ' . escapeshellarg($name));
}

function setPriority(string $name, int $priority): void {
    exec('sudo nmcli connection modify ' . escapeshellarg($name) .
         ' connection.autoconnect-priority ' . $priority);
}

function addConnection(array $entry, int $priority): void {
    $name      = $entry['name'];
    $ssid      = $entry['ssid'];
    $encrypted = $entry['encrypted'];
    $password  = $entry['password'];

    echo "Adding: $name ($ssid), priority=$priority\n";

    $n = escapeshellarg($name);
    $s = escapeshellarg($ssid);
    $base = "sudo nmcli connection add type wifi con-name $n ifname wlan0 ssid $s";

    if (preg_match('/^[a-f0-9]{64}$/i', $encrypted)) {
        // Pre-computed PSK hash from wpa_passphrase
        exec("$base -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk " . escapeshellarg($encrypted));
    } elseif ($password !== '') {
        // Plain-text password — nmcli hashes it
        exec("$base -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk " . escapeshellarg($password));
    } else {
        // Open network (no password / "NO PASSWORD!")
        exec($base);
    }

    setPriority($name, $priority);
}

function deleteOldConnections(): void {
    global $connectionsToKeep;
    exec('sudo nmcli --terse connection show', $connections);
    foreach ($connections as $connection) {
        $parts  = explode(':', $connection);
        $name   = $parts[0];
        $device = $parts[3] ?? '';
        if (!in_array($name, $connectionsToKeep) && !in_array($device, $connectionsToKeep)) {
            deleteConnection($name);
        }
    }
}

/* ── Main ── */

echo "----------\n";
echo "ssids=$ssidFilename\n";

if (!file_exists($ssidFilename)) {
    fatal("$ssidFilename not found");
}

$entries = parseWifiYaml($ssidFilename);
echo count($entries) . " entries loaded\n";

if (count($entries) === 0) {
    echo "WARNING: no entries parsed — skipping to avoid deleting all connections\n";
    exit(0);
}

$current = trim(str_replace('wlan0', '', exec('nmcli -f NAME,DEVICE c s --active | grep wlan0')));
echo "Current connection: $current\n";
echo "----------\n\n";

deleteOldConnections();

$priority = 999;
foreach ($entries as $entry) {
    if ($entry['name'] === $current) {
        echo "Found current connection: {$entry['name']} — updating priority\n";
        setPriority($entry['name'], $priority);
    } else {
        addConnection($entry, $priority);
    }
    $priority--;
}
