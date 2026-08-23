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
        // Live on the App Store since 2026-08-18, confirmed against Apple's own lookup
        // API (itunes.apple.com/lookup?id=6781949531), which reports 1.25.0+68. These
        // three had been left at 1.22.1 / build 42 / empty store_url from before the
        // release, and the empty URL made update_check.dart return null for every iOS
        // user — so iPhones were never told an update existed while Android was.
        //
        // The build number is what decides: update_check.dart prompts only when this
        // exceeds the running build. It must match what the App Store actually serves,
        // for the same reason the Android note below says so — set it ahead and every
        // iOS user is prompted forever to fetch something that does not exist.
        'latest'    => '1.25.0',
        'build'     => 68,
        // The id form, not the slug: Apple rewrites the slug when the app is renamed
        // (this listing already moved from /marin-aprs-map/ to /aprs-map/) and keeps
        // the numeric id stable. Linked direct rather than through
        // marsaprs.org/ios/download.php so the prompt opens the App Store app itself
        // instead of bouncing the user through Safari — the redirect is for printed
        // and emailed links, where a stable marsaprs.org URL is what matters.
        'store_url' => 'https://apps.apple.com/app/id6781949531',
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
