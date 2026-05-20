<?php
session_start();
if (empty($_SESSION['stats_auth']) && empty($_SESSION['aprs_admin_authed'])) { http_response_code(403); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$ip       = trim($_POST['ip']       ?? '');
$user     = trim($_POST['user']     ?? '');
$pass     =      $_POST['pass']     ?? '';
$remember = (($_POST['remember']    ?? '0') === '1');

if (!$ip || !$user || $pass === '') {
    echo json_encode(['error' => 'Username and password are required.']); exit;
}

// Validate credentials via Python/paramiko
$script = 'import sys,paramiko; c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy()); c.connect(sys.argv[1],username=sys.argv[2],password=sys.argv[3],timeout=10); c.close(); print("ok")';
$cmd    = '/usr/bin/python3 -c ' . escapeshellarg($script) . ' '
        . escapeshellarg($ip) . ' '
        . escapeshellarg($user) . ' '
        . escapeshellarg($pass) . ' 2>&1';

$out = shell_exec($cmd);
if (trim($out) !== 'ok') {
    $msg = str_contains((string)$out, 'Authentication failed') ? 'Authentication failed — wrong username or password.'
         : (str_contains((string)$out, 'timed out') || str_contains((string)$out, 'Connection refused') ? "Cannot connect to $ip on port 22."
         : 'SSH error: ' . trim((string)$out));
    echo json_encode(['error' => $msg]); exit;
}

// Store credentials in session under a one-time token
$token = bin2hex(random_bytes(16));
if (!isset($_SESSION['ssh_sessions'])) $_SESSION['ssh_sessions'] = [];
foreach ($_SESSION['ssh_sessions'] as $k => $s) {
    if (time() - ($s['ts'] ?? 0) > 7200) unset($_SESSION['ssh_sessions'][$k]);
}
$_SESSION['ssh_sessions'][$token] = [
    'ip'   => $ip,
    'user' => $user,
    'pass' => $pass,
    'ts'   => time(),
];

// Persist credentials to YAML
require_once __DIR__ . '/yaml_lib.php';
$yamlFile = __DIR__ . '/addresses.yaml';
$devices  = loadDevices($yamlFile);
foreach ($devices as &$d) {
    if ($d['ip'] === $ip) {
        $d['ssh_user'] = $user;
        $d['ssh_pass'] = $remember ? $pass : '';
        break;
    }
}
unset($d);
saveDevices($yamlFile, $devices);

echo json_encode(['ok' => true, 'token' => $token]);
