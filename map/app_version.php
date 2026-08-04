<?php
/**
 * app_version.php — version manifest for the mobile apps' soft "update available"
 * check. The Flutter app fetches this on launch, compares the `build` number to
 * its own, and shows a dismissable prompt when a newer build is available.
 *
 * To release: bump `latest` + `build` for the relevant platform when a new app
 * version ships (App Store review passed for iOS; new APK uploaded for Android).
 * See the version-bump checklist. `store_url` is filled in once the iOS app is
 * live on the App Store — while it is empty the iOS check is a silent no-op.
 *
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');   // always fresh; never let Cloudflare cache it

echo json_encode([
    'ios' => [
        'latest'    => '1.21.1',
        'build'     => 13,
        'store_url' => '',   // e.g. https://apps.apple.com/app/id0000000000
    ],
    'android' => [
        'latest'  => '1.21.1',
        'build'   => 13,
        'apk_url' => 'https://marsaprs.org/android/download.php',
    ],
    'notes' => '',           // optional short "what's new" line shown in the prompt
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
