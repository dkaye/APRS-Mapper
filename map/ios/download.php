<?php
/**
 * Permanent download URL: https://marsaprs.org/ios/download.php
 *
 * Redirects to the App Store listing, mirroring android/download.php. The point
 * is the same one: the URL printed in the user guide, mailed out, or turned into
 * a QR code never changes, and the destination can be repointed here if Apple's
 * listing URL ever moves — as it already has once, from /marin-aprs-map/ to
 * /aprs-map/. Apple keeps the numeric id stable across those renames, so the id
 * is what this links on.
 *
 * 302 with no-store, not 301, so neither Cloudflare nor a browser pins the
 * current destination forever.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/ADMIN.MD
 * ©2026 Doug Kaye, K6DRK <doug@rds.com>
 */
const APP_STORE_URL = 'https://apps.apple.com/us/app/id6781949531';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . APP_STORE_URL, true, 302);
