<?php
/**
 * Receives an iGate SDR self-noise report (JSON) and stores it, one file per
 * host, for the dashboard in index.php. Gates POST here nightly from
 * igate-selftest.sh. No auth — the data is non-sensitive RF measurements, and
 * the host field is sanitised before use as a filename.
 */
header('Content-Type: text/plain; charset=utf-8');

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) === 0 || strlen($raw) > 20000) {
    http_response_code(400); echo "bad request\n"; exit;
}
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['host'])) {
    http_response_code(400); echo "invalid json\n"; exit;
}
$host = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$d['host']);
if ($host === '' || strlen($host) > 64) {
    http_response_code(400); echo "bad host\n"; exit;
}

$dir = __DIR__ . '/data';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
if (!is_dir($dir) || !is_writable($dir)) {
    http_response_code(500); echo "store unavailable\n"; exit;
}

$d['_received'] = date('c');
$d['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
if (@file_put_contents("$dir/$host.json", json_encode($d, JSON_PRETTY_PRINT) . "\n") === false) {
    http_response_code(500); echo "write failed\n"; exit;
}
echo "ok\n";
