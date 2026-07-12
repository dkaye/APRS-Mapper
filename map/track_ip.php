<?php
/**
 * track_ip.php — Record client IP + page label for the Clients modal.
 * Include at the top of any PHP entry point: require_once '/var/www/html/track_ip.php';
 * Then call: track_client_ip('admin');
 */
function track_client_ip(string $page): void {
    $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
        ?? $_SERVER['REMOTE_ADDR']);
    $file = '/run/aprs/recent_ips.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return; }
    $ips = json_decode(stream_get_contents($fh), true) ?: [];
    $ips[$ip] = ['ts' => time(), 'page' => $page, 'cs' => $ips[$ip]['cs'] ?? null];
    if (count($ips) > 200) {
        uasort($ips, fn($a, $b) => $b['ts'] - $a['ts']);
        $ips = array_slice($ips, 0, 200, true);
    }
    rewind($fh); ftruncate($fh, 0); fwrite($fh, json_encode($ips));
    flock($fh, LOCK_UN); fclose($fh);
}
