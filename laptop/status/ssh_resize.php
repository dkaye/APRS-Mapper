<?php
session_start();
if (empty($_SESSION['stats_auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
$cols  = max(10, min(500, (int)($_POST['cols'] ?? 80)));
$rows  = max(5,  min(300, (int)($_POST['rows'] ?? 24)));

if (empty($_SESSION['ssh_sessions'][$token])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
session_write_close();

$resizeFile = sys_get_temp_dir() . "/aprs_ssh_{$token}.q.resize";
file_put_contents($resizeFile, "$cols:$rows");
echo json_encode(['ok' => true]);
