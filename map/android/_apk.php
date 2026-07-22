<?php
/**
 * Locates the newest Android build in this directory.
 *
 * APKs are named `aprs-map-<version>-<build>.apk` (e.g. aprs-map-1.20.0-9.apk)
 * and are NOT in git — they are uploaded straight to the Pi. Keeping the version
 * in the filename means a released binary is never silently overwritten, and the
 * browser saves a file whose name says what it is. The stable public URL lives in
 * download.php, which redirects here, so the link handed to users never changes.
 */

function apk_list(): array {
    $out = [];
    foreach (glob(__DIR__ . '/aprs-map-*.apk') ?: [] as $path) {
        $base = basename($path);
        if (!preg_match('/^aprs-map-(\d+)\.(\d+)\.(\d+)-(\d+)\.apk$/', $base, $m)) continue;
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

function apk_latest(): ?array {
    $all = apk_list();
    return $all ? $all[0] : null;
}

function apk_human_size(int $bytes): string {
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}
