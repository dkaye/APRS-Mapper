<?php
/**
 * Map tile proxy + cache for the MARS APRS maps.
 *
 *   URL:  https://marsaprs.org/tiles.php/{z}/{x}/{y}.png
 *
 * Serves a tile from, in order:
 *   1. tiles/base/  — permanent, pre-seeded event areas (Marin etc.). Never
 *      auto-deleted; this is what offline event downloads pull from, so those
 *      downloads never touch OpenStreetMap.
 *   2. tiles/cache/ — on-demand "browse" cache. Filled the first time anyone
 *      views an area outside the seeded regions; trimmed by tiles-clean.sh.
 *   3. OpenStreetMap — fetched once (single tile, proper User-Agent), cached
 *      into tiles/cache/, and returned.
 *
 * The app and the web map both point here, so end users never talk to OSM
 * directly — no more bulk-download blocks — and each tile is fetched from OSM
 * at most once, which is far gentler on OSM than every client fetching its own.
 */

$BASE  = __DIR__ . '/tiles/base';
$CACHE = __DIR__ . '/tiles/cache';
$OSM   = 'https://tile.openstreetmap.org';
$UA    = 'MARS-APRS-tile-proxy/1.0 (+https://marsaprs.org; doug@rds.com)';

// 1×1 transparent PNG — returned when OSM is unreachable, so the map shows a
// gap rather than a broken tile, and retries soon (short cache).
const BLANK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

// ── Parse and validate /{z}/{x}/{y}.png from PATH_INFO ────────────────────────
$pi = $_SERVER['PATH_INFO'] ?? '';
if (!preg_match('#^/(\d{1,2})/(\d{1,7})/(\d{1,7})\.png$#', $pi, $m)) {
    http_response_code(400); header('Content-Type: text/plain'); echo "bad tile path\n"; exit;
}
$z = (int)$m[1]; $x = (int)$m[2]; $y = (int)$m[3];
$max = ($z <= 30) ? (1 << $z) - 1 : 0;
if ($z < 0 || $z > 19 || $x < 0 || $x > $max || $y < 0 || $y > $max) {
    http_response_code(400); header('Content-Type: text/plain'); echo "tile out of range\n"; exit;
}
$rel = "$z/$x/$y.png";

function serve_file(string $file, int $maxage): void {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . $maxage);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ── 1. Permanent base cache (seeded event areas) — long cache ─────────────────
if (is_file("$BASE/$rel")) serve_file("$BASE/$rel", 2592000);   // 30 days
// ── 2. On-demand browse cache — 7-day cache ──────────────────────────────────
if (is_file("$CACHE/$rel")) serve_file("$CACHE/$rel", 604800);

// ── 3. Miss → fetch once from OpenStreetMap, cache into the browse cache ──────
// Uses the HTTP stream wrapper (curl extension isn't installed on the Pi).
$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "User-Agent: $UA\r\n",
    'timeout'       => 12,
    'follow_location'=> 1,
    'max_redirects' => 2,
    'ignore_errors' => true,   // return the body even on 4xx/5xx so we can inspect it
]]);
$data = @file_get_contents("$OSM/$rel", false, $ctx);

// $http_response_header is populated by the HTTP wrapper after the request.
$code = 0; $ctype = '';
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $hm))            $code  = (int)$hm[1];
    elseif (preg_match('#^Content-Type:\s*([^;\r\n]+)#i', $h, $hm)) $ctype = trim($hm[1]);
}

if ($code === 200 && $data !== false && $data !== '' && str_contains($ctype, 'image')) {
    $dir = "$CACHE/$z/$x";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // Atomic write so a concurrent request never reads a half-written tile.
    $tmp = "$dir/.$y." . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) !== false) @rename($tmp, "$CACHE/$rel");
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

// OSM unavailable → transparent tile, short cache so it's retried soon.
http_response_code(200);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=60');
echo base64_decode(BLANK_PNG);
