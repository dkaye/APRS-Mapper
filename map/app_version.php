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
        // Inert until store_url is filled in — update_check.dart makes the iOS check a
        // silent no-op while it is empty, so this number cannot prompt anyone before
        // the build is actually live on the App Store.
        'latest'    => '1.22.1',
        'build'     => 42,
        'store_url' => '',   // e.g. https://apps.apple.com/app/id0000000000
    ],
    'android' => [
        // Must match what download.php actually serves, or the prompt sends people to
        // fetch a build they already have and never stops asking.
        'latest'  => '1.25.0',
        'build'   => 68,
        'apk_url' => 'https://marsaprs.org/android/download.php',
    ],
    // Optional short "what's new" line shown in the prompt.
    'notes' => 'Spoken messages now pause between sentences.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
