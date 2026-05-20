<?php
session_start();
if (empty($_SESSION['stats_auth'])) { http_response_code(403); exit; }
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

if (!function_exists('ssh2_connect')) {
    echo json_encode(['error' => 'PHP ssh2 extension not available on server.']); exit;
}

$conn = @ssh2_connect($ip, 22);
if (!$conn) {
    echo json_encode(['error' => "Cannot connect to $ip on port 22."]); exit;
}
if (!@ssh2_auth_password($conn, $user, $pass)) {
    echo json_encode(['error' => 'Authentication failed — wrong username or password.']); exit;
}

// Store credentials in session under a one-time token
$token = bin2hex(random_bytes(16));
if (!isset($_SESSION['ssh_sessions'])) $_SESSION['ssh_sessions'] = [];
// Expire old tokens
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
