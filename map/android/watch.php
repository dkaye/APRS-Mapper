<?php
/**
 * Permanent download URL: https://marsaprs.org/android/watch.php
 *
 * The Wear OS companion, alongside download.php's phone app. Same indirection,
 * same reasoning: a 302 with no-store so the stable URL in the user guide is
 * never pinned by Cloudflare to a superseded build.
 *
 * This APK is for the *watch*, not the phone. Wear OS has no browser, so the
 * file is fetched on the phone and pushed across — see index.php for the steps.
 */
require_once __DIR__ . '/_apk.php';

$apk = apk_latest(APK_WEAR);
if (!$apk) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No Wear OS build is currently available.\n";
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . rawurlencode($apk['file']), true, 302);
