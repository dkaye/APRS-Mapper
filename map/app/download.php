<?php
/**
 * Permanent download URL: https://marsaprs.org/app/download.php
 *
 * Redirects to the newest versioned APK. This indirection is the whole point:
 * the URL in emails, QR codes and the user guide never changes, while each
 * release lands at its own versioned path. A 302 (not 301) with no-store keeps
 * Cloudflare and browsers from pinning an old release to the stable URL — the
 * failure mode of simply overwriting a fixed filename.
 */
require_once __DIR__ . '/_apk.php';

$apk = apk_latest();
if (!$apk) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No Android build is currently available.\n";
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . rawurlencode($apk['file']), true, 302);
