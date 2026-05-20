<?php
session_start();
if (empty($_SESSION['stats_auth'])) { http_response_code(403); exit; }
header('Content-Type: application/json');

$token = $_POST['token'] ?? '';
$data  = $_POST['data']  ?? '';

if ($token === '' || $data === '') { echo json_encode(['ok' => true]); exit; }

if (empty($_SESSION['ssh_sessions'][$token])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}
session_write_close();

$queueFile = sys_get_temp_dir() . "/aprs_ssh_{$token}.q";
$fp = @fopen($queueFile, 'a');
if ($fp) {
    flock($fp, LOCK_EX);
    fwrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
}

echo json_encode(['ok' => true]);
