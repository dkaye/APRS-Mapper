<?php
/**
 * Locates the newest Android builds in this directory.
 *
 * APKs are named `<prefix>-<version>-<build>.apk` and are NOT in git — they are
 * uploaded straight to the Pi. Keeping the version in the filename means a
 * released binary is never silently overwritten, and the browser saves a file
 * whose name says what it is. The stable public URLs live in download.php and
 * watch.php, which redirect here, so the links handed to users never change.
 *
 * Two products ship from this directory and must never be confused with each
 * other: the phone app (`aprs-map-*`, a Flutter universal APK) and the Wear OS
 * companion (`aprs-wear-*`, the `:wear` Gradle module). They share an
 * applicationId and a signing key — see README.md ("Wear OS Companion") — so
 * only the filename prefix tells them apart, and a watch APK offered as the
 * phone download would install over the phone app and replace it.
 */

const APK_PHONE = 'aprs-map';
const APK_WEAR  = 'aprs-wear';

function apk_list(string $prefix = APK_PHONE): array {
    $out = [];
    foreach (glob(__DIR__ . '/' . $prefix . '-*.apk') ?: [] as $path) {
        $base = basename($path);
        if (!preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)\.(\d+)\.(\d+)-(\d+)\.apk$/', $base, $m)) continue;
        $out[] = [
            'file'    => $base,
            'path'    => $path,
            'version' => "$m[1].$m[2].$m[3]",
            'build'   => (int)$m[4],
            // Sort key: build number is monotonic across releases by policy
            // (TestFlight requires it), so it alone orders correctly; the version
            // triple is the tie-breaker in case a build number is ever reused.
            'sort'    => [(int)$m[4], (int)$m[1], (int)$m[2], (int)$m[3]],
            'size'    => filesize($path),
            'mtime'   => filemtime($path),
        ];
    }
    usort($out, fn($a, $b) => $b['sort'] <=> $a['sort']);
    return $out;
}

function apk_latest(string $prefix = APK_PHONE): ?array {
    $all = apk_list($prefix);
    return $all ? $all[0] : null;
}

function apk_human_size(int $bytes): string {
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}

/**
 * SHA-256 of a build, cached beside it. Hashing 60+ MB on a Pi for every page
 * view is wasteful and the file never changes once published.
 */
function apk_sha256(array $apk): ?string {
    $cache = $apk['path'] . '.sha256';
    if (is_readable($cache) && filemtime($cache) >= $apk['mtime']) {
        return trim((string)file_get_contents($cache));
    }
    $sha = hash_file('sha256', $apk['path']);
    if ($sha === false) return null;
    @file_put_contents($cache, $sha . "\n");
    return $sha;
}
