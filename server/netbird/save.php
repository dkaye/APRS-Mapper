<?php
session_start();
$authed = !empty($_SESSION['stats_auth']) || !empty($_SESSION['aprs_admin_authed']);

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$action = $_POST['action'] ?? '';

// save_config (poll interval) is allowed without auth — not security-sensitive
if (!$authed && $action !== 'save_config') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($action === 'save_addresses') {
    $content = $_POST['content'] ?? '';
    $file    = __DIR__ . '/addresses.cfg';

    $fp = @fopen($file, 'c'); // open without truncating so lock can be acquired first
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open addresses.cfg — check permissions']);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'Could not acquire file lock — try again']);
        exit;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $content);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($file, 0664);
    echo json_encode(['ok' => true]);

} elseif ($action === 'save_config') {
    $repeat = max(10, min(3600, (int)($_POST['repeat_seconds'] ?? 60)));
    $file   = __DIR__ . '/config.json';
    if (file_put_contents($file, json_encode(['repeat_seconds' => $repeat])) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write config.json — check permissions']);
        exit;
    }
    @chmod($file, 0664);
    echo json_encode(['ok' => true, 'repeat_seconds' => $repeat]);

} elseif ($action === 'add_device') {
    require_once __DIR__ . '/yaml_lib.php';
    $file    = __DIR__ . '/addresses.yaml';
    $devices = loadDevices($file);
    $devices[] = [
        'name'    => trim($_POST['name']    ?? ''),
        'host'    => trim($_POST['host']    ?? ''),
        'ip'      => trim($_POST['ip']      ?? ''),
        'group'   => trim($_POST['group']   ?? ''),
        'enabled' => (($_POST['enabled'] ?? 'false') === 'true'),
        'web'     => (($_POST['web']     ?? 'false') === 'true'),
    ];
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml — check permissions']);
        exit;
    }
    @file_put_contents(__DIR__ . '/poll_force',     time());
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
    echo json_encode(['ok' => true]);

} elseif ($action === 'update_device') {
    require_once __DIR__ . '/yaml_lib.php';
    $file    = __DIR__ . '/addresses.yaml';
    $devices = loadDevices($file);
    $origIp  = $_POST['orig_ip'] ?? '';
    foreach ($devices as &$d) {
        if ($d['ip'] === $origIp) {
            $d['name']    = trim($_POST['name']    ?? '');
            $d['host']    = trim($_POST['host']    ?? '');
            $d['ip']      = trim($_POST['ip']      ?? '');
            $d['group']   = trim($_POST['group']   ?? '');
            $d['enabled'] = (($_POST['enabled'] ?? 'false') === 'true');
            $d['web']     = (($_POST['web']     ?? 'false') === 'true');
            break;
        }
    }
    unset($d);
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml — check permissions']);
        exit;
    }
    @file_put_contents(__DIR__ . '/poll_force',     time());
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
    echo json_encode(['ok' => true]);

} elseif ($action === 'delete_device') {
    require_once __DIR__ . '/yaml_lib.php';
    $file    = __DIR__ . '/addresses.yaml';
    $devices = loadDevices($file);
    $ip      = $_POST['ip'] ?? '';
    $devices = array_values(array_filter($devices, fn($d) => $d['ip'] !== $ip));
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml — check permissions']);
        exit;
    }
    @file_put_contents(__DIR__ . '/poll_force',     time());
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
    echo json_encode(['ok' => true]);

} elseif ($action === 'toggle_device') {
    require_once __DIR__ . '/yaml_lib.php';
    $file      = __DIR__ . '/addresses.yaml';
    $devices   = loadDevices($file);
    $ip        = $_POST['ip'] ?? '';
    $newEnabled = null;
    foreach ($devices as &$d) {
        if ($d['ip'] === $ip) {
            $d['enabled'] = !($d['enabled'] ?? true);
            $newEnabled = $d['enabled'];
            break;
        }
    }
    unset($d);
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml — check permissions']);
        exit;
    }
    if ($newEnabled) {
        @file_put_contents(__DIR__ . '/poll_single', $ip);
    }
    @file_put_contents(__DIR__ . '/last_viewer.ts', time());
    echo json_encode(['ok' => true, 'enabled' => $newEnabled, 'enabled_at' => $newEnabled ? time() : null]);

} elseif ($action === 'save_ssh_creds') {
    require_once __DIR__ . '/yaml_lib.php';
    $file    = __DIR__ . '/addresses.yaml';
    $devices = loadDevices($file);
    $ip      = $_POST['ip'] ?? '';
    foreach ($devices as &$d) {
        if ($d['ip'] === $ip) {
            $d['ssh_user'] = trim($_POST['ssh_user'] ?? '');
            $d['ssh_pass'] = $_POST['ssh_pass'] ?? '';
            break;
        }
    }
    unset($d);
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml']);
        exit;
    }
    echo json_encode(['ok' => true]);

} elseif ($action === 'toggle_web') {
    require_once __DIR__ . '/yaml_lib.php';
    $file    = __DIR__ . '/addresses.yaml';
    $devices = loadDevices($file);
    $ip      = $_POST['ip'] ?? '';
    $newWeb  = null;
    foreach ($devices as &$d) {
        if ($d['ip'] === $ip) {
            $d['web'] = !($d['web'] ?? false);
            $newWeb = $d['web'];
            break;
        }
    }
    unset($d);
    if (!saveDevices($file, $devices)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write addresses.yaml — check permissions']);
        exit;
    }
    echo json_encode(['ok' => true, 'web' => $newWeb]);

} else {
    http_response_code(400);
    echo json_encode(['error' => "Unknown action: $action"]);
}
