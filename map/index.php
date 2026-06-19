<?php
/**
 * APRS Tracker Map
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 *
 * Single entry point for desktop and mobile browsers.
 * Layout is chosen via CSS media query (≤767 px = mobile).
 *
 * Endpoints:
 *   (none)   HTML/JS Leaflet map page
 *   ?json    Live tracker status from trackers.json
 *   ?config  Map/background/course/tracker config from config.yaml (ETag-cached)
 */

$trackerStatusFilename = 'trackers.json';

if (isset($_GET['json'])) {
	$igatesStatusFilename     = 'igates.json';
	$aidstationsStatusFilename = 'aidstations.json';
	$trackerMtime = file_exists($trackerStatusFilename)      ? filemtime($trackerStatusFilename)      : 0;
	$configMtime  = file_exists('config.yaml')               ? filemtime('config.yaml')               : 0;
	$igateMtime   = file_exists($igatesStatusFilename)       ? filemtime($igatesStatusFilename)       : 0;
	$aidMtime     = file_exists($aidstationsStatusFilename)  ? filemtime($aidstationsStatusFilename)  : 0;
	$etag = '"' . $trackerMtime . '-' . $configMtime . '-' . $igateMtime . '-' . $aidMtime . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	require_once 'config_parse.php';
	$cfg          = parseConfigYaml('config.yaml');
	$defaultEvent = $cfg['event'] ?? '';
	$fh = fopen($trackerStatusFilename, 'r');
	if (!$fh) { http_response_code(500); exit; }
	flock($fh, LOCK_SH);
	$contents = stream_get_contents($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	$trackers = json_decode($contents, true) ?: [];
	$readBeaconFile = function($path) {
		if (!file_exists($path)) return [];
		$fh = fopen($path, 'r'); if (!$fh) return [];
		flock($fh, LOCK_SH); $c = stream_get_contents($fh); flock($fh, LOCK_UN); fclose($fh);
		return json_decode($c, true) ?: [];
	};
	header('Content-Type: application/json');
	echo json_encode([
		'default_event'  => $defaultEvent,
		'trackers'       => $trackers,
		'igate_beacons'  => $readBeaconFile($igatesStatusFilename),
		'aid_beacons'    => $readBeaconFile($aidstationsStatusFilename),
	]);
	exit;
}

if (isset($_GET['history'])) {
	$cfgReal      = realpath('config.yaml');
	$histPath     = $cfgReal ? dirname($cfgReal) . '/tracker_history.yaml' : null;
	$mobileHist   = __DIR__ . '/mobile_history.yaml';
	$mtime1       = ($histPath && file_exists($histPath))   ? filemtime($histPath)   : 0;
	$mtime2       = file_exists($mobileHist)                ? filemtime($mobileHist) : 0;
	$etag         = '"' . $mtime1 . '-' . $mtime2 . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	header('Content-Type: application/json');

	// Shared YAML history parser
	$parseHistYaml = function($path) {
		$out = [];
		$fh = fopen($path, 'r');
		if (!$fh || !flock($fh, LOCK_SH)) return $out;
		$lines = [];
		while (($line = fgets($fh)) !== false) $lines[] = rtrim($line);
		flock($fh, LOCK_UN); fclose($fh);
		$cs = null; $entry = null;
		foreach ($lines as $line) {
			if (!$line || $line[0] === '#') continue;
			if (preg_match('/^([A-Z0-9][\w\-]+):$/', $line, $m)) {
				$cs = $m[1]; $out[$cs] = [];
			} elseif (preg_match('/^  - lat:\s*([\-\d.]+)/', $line, $m)) {
				$entry = ['lat' => (float)$m[1], 'lon' => 0.0, 'ts' => 0];
			} elseif (preg_match('/^    lon:\s*([\-\d.]+)/', $line, $m) && $entry !== null) {
				$entry['lon'] = (float)$m[1];
			} elseif (preg_match('/^    path:\s*(.+)/', $line, $m) && $entry !== null) {
				$entry['path'] = trim($m[1]);
			} elseif (preg_match('/^    ts:\s*(\d+)/', $line, $m) && $entry !== null) {
				$entry['ts'] = (int)$m[1];
				if (!isset($entry['path'])) $entry['path'] = '';
				if ($cs !== null) $out[$cs][] = $entry;
				$entry = null;
			}
		}
		foreach ($out as &$e) $e = array_slice($e, 0, 10);
		unset($e);
		return $out;
	};

	$result = ($histPath && file_exists($histPath)) ? $parseHistYaml($histPath) : [];
	if (file_exists($mobileHist)) {
		foreach ($parseHistYaml($mobileHist) as $cs => $entries) $result[$cs] = $entries;
	}
	echo json_encode($result);
	exit;
}

if (isset($_GET['config'])) {
	require_once 'config_parse.php';
	$mtime = file_exists('config.yaml') ? filemtime('config.yaml') : 0;
	$etag  = '"' . $mtime . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	$cfg     = parseConfigYaml('config.yaml');
	$courses = array_values(array_filter($cfg['courses'] ?? [], fn($c) => isset($c['file'])));
	$rawSv = $cfg['section_visibility'] ?? [];
	$sv = [];
	foreach (['trackers','courses','aidstations','igates','backgrounds'] as $k) {
		$v = $rawSv[$k] ?? true;
		$sv[$k] = ($v !== false && $v !== 'false' && $v !== 0 && $v !== '0');
	}
	header('Content-Type: application/json');
	$mobileCfgTmp = $cfg['mobile'] ?? [];
	$mobileEnabled = !empty($mobileCfgTmp['enabled']) && $mobileCfgTmp['enabled'] !== false;
	echo json_encode([
		'event'              => $cfg['event'] ?? '',
		'legend'             => $cfg['legend'] ?? '',
		'tracker_style'      => $cfg['tracker_style'] ?? [],
		'map'                => $cfg['map'],
		'trackers'           => $cfg['trackers'],
		'backgrounds'        => $cfg['backgrounds'],
		'background_url'     => $cfg['background_url'] ?? '',
		'courses'            => $courses,
		'aidstations'        => $cfg['aidstations'] ?? [],
		'igates'             => $cfg['igates'] ?? [],
		'section_visibility' => $sv,
		'mobile_enabled'     => $mobileEnabled,
	]);
	exit;
}

if (isset($_GET['clientstatus'])) {
	header('Content-Type: application/json');
	$stats = [];
	$raw = @file_get_contents('http://localhost/server-status?auto');
	if ($raw !== false) {
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			$pos  = strpos($line, ': ');
			if ($pos !== false) $stats[substr($line, 0, $pos)] = substr($line, $pos + 2);
		}
	}
	$clients = [];
	exec("ss -tn state established 'sport = :80' 2>/dev/null", $ssLines);
	foreach (array_slice($ssLines, 1) as $line) {
		$parts = preg_split('/\s+/', trim($line));
		if (count($parts) >= 5 && preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})/', $parts[4], $m)) {
			$clients[] = $m[1];
		}
	}
	$counts = array_count_values($clients);
	arsort($counts);
	echo json_encode([
		'busy'    => isset($stats['BusyWorkers']) ? (int)$stats['BusyWorkers']  : null,
		'idle'    => isset($stats['IdleWorkers'])  ? (int)$stats['IdleWorkers']  : null,
		'rps'     => isset($stats['ReqPerSec'])    ? (float)$stats['ReqPerSec'] : null,
		'uptime'  => $stats['ServerUptime'] ?? null,
		'clients' => $counts,
		'total'   => count($clients),
	]);
	exit;
}

// ── APRS-IS helpers (used by mobile update) ───────────────────────────────────

function aprsPasscode(string $callsign): int {
	$call = strtoupper(preg_replace('/-.*/', '', $callsign));
	$hash = 0x73e2;
	for ($i = 0; $i < strlen($call); $i += 2) {
		$hash ^= (ord($call[$i]) << 8);
		if (isset($call[$i + 1])) $hash ^= ord($call[$i + 1]);
	}
	return $hash & 0x7fff;
}

function decToAprsLat(float $lat): string {
	$d = (int)abs($lat); $m = (abs($lat) - $d) * 60;
	return sprintf('%02d%05.2f%s', $d, $m, $lat >= 0 ? 'N' : 'S');
}

function decToAprsLon(float $lon): string {
	$d = (int)abs($lon); $m = (abs($lon) - $d) * 60;
	return sprintf('%03d%05.2f%s', $d, $m, $lon >= 0 ? 'E' : 'W');
}

function injectAprsPacket(string $callsign, float $lat, float $lon, string $root): void {
	$passcode = aprsPasscode($root);
	$packet   = "{$callsign}>APRS,TCPIP*:!" . decToAprsLat($lat) . '/' . decToAprsLon($lon) . ">Mobile\r\n";
	$sock = @fsockopen('noam.aprs2.net', 14580, $errno, $errstr, 5);
	if (!$sock) return;
	stream_set_timeout($sock, 5);
	fgets($sock, 512);		// read server banner
	fwrite($sock, "user {$root} pass {$passcode} vers AprsTopo 2.0\r\n");
	fgets($sock, 512);		// read login response
	fwrite($sock, $packet);
	fclose($sock);
}

if (isset($_GET['mobile'])) {
	header('Content-Type: application/json');
	$mobileFile = __DIR__ . '/mobile_trackers.json';
	$input      = json_decode(file_get_contents('php://input'), true) ?: [];
	$action     = $_GET['mobile'];

	require_once 'config_parse.php';
	$_mcfg      = parseConfigYaml('config.yaml');
	$mobileCfg  = $_mcfg['mobile'] ?? [];
	$mobileOn   = !empty($mobileCfg['enabled']) && $mobileCfg['enabled'] !== false;

	if (!$mobileOn) { http_response_code(403); echo json_encode(['error' => 'Mobile tracking is not enabled']); exit; }

	// Atomic read-modify-write with exclusive lock
	function modifyMobileTrackers($file, $fn) {
		$fh = fopen($file, 'c+');
		if (!$fh) return false;
		flock($fh, LOCK_EX);
		$c = stream_get_contents($fh);
		$data = json_decode($c, true) ?: [];
		$data = $fn($data);
		ftruncate($fh, 0); rewind($fh);
		fwrite($fh, json_encode($data, JSON_PRETTY_PRINT) . "\n");
		flock($fh, LOCK_UN); fclose($fh);
		return true;
	}

	if ($action === 'join') {
		$name = preg_replace('/[^A-Za-z0-9 \-]/', '', trim($input['name'] ?? ''));
		$name = substr($name, 0, 12);
		$pin  = (string)($input['pin'] ?? '');
		if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name is required']); exit; }
		$storedPin = (string)($mobileCfg['pin'] ?? '');
		if ($storedPin === '' || !hash_equals($storedPin, $pin)) {
			http_response_code(403); echo json_encode(['error' => 'Incorrect PIN']); exit;
		}
		$root = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $mobileCfg['root'] ?? ''), 0, 6));
		if ($root === '') { http_response_code(500); echo json_encode(['error' => 'Root callsign not configured']); exit; }
		$newEntry = null;
		modifyMobileTrackers($mobileFile, function($data) use ($name, $root, &$newEntry) {
			$now = time();
			// Prune entries not updated in 24 h
			$data = array_values(array_filter($data, fn($t) => ($now - $t['lastUpdate']) < 86400));
			// Next available number (lowest unused, with leading zeros)
			$used = [];
			foreach ($data as $t) {
				if (preg_match('/^M(\d+)$/', $t['id'], $m)) $used[(int)$m[1]] = true;
			}
			for ($n = 1; isset($used[$n]); $n++);
			$id       = sprintf('M%02d', $n);
			$callsign = sprintf('%s-%02d', $root, $n);
			$newEntry = ['id' => $id, 'callsign' => $callsign, 'name' => $name,
			             'token' => bin2hex(random_bytes(16)), 'lastUpdate' => $now, 'created' => $now];
			$data[] = $newEntry;
			return $data;
		});
		echo json_encode(['id' => $newEntry['id'], 'token' => $newEntry['token']]);
		exit;
	}

	if ($action === 'update') {
		$token = $input['token'] ?? '';
		$lat   = isset($input['lat']) ? round((float)$input['lat'], 6) : null;
		$lon   = isset($input['lon']) ? round((float)$input['lon'], 6) : null;
		if (!$token || $lat === null || $lon === null) { http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit; }
		// Find session by token (read-only)
		$found         = false;
		$foundCallsign = null;
		$fh = fopen($mobileFile, 'r');
		if ($fh) {
			flock($fh, LOCK_SH); $c = stream_get_contents($fh); flock($fh, LOCK_UN); fclose($fh);
			foreach (json_decode($c, true) ?: [] as $t) {
				if (hash_equals($t['token'], $token)) {
					if (!empty($t['blocked'])) { $found = 'blocked'; break; }
					$found = true; $foundCallsign = $t['callsign'] ?? null; break;
				}
			}
		}
		if ($found === 'blocked') { http_response_code(403); echo json_encode(['error' => 'Blocked']); exit; }
		if (!$found) { http_response_code(404); echo json_encode(['error' => 'Token not found']); exit; }
		// Forward position to APRS-IS; daemon picks it up and writes trackers.json
		$root = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $mobileCfg['root'] ?? ''), 0, 6));
		if ($root !== '' && $foundCallsign) injectAprsPacket($foundCallsign, $lat, $lon, $root);
		// Refresh lastUpdate so the admin "last seen" age stays current
		modifyMobileTrackers($mobileFile, function($data) use ($token) {
			foreach ($data as &$t) { if (hash_equals($t['token'], $token)) { $t['lastUpdate'] = time(); break; } }
			return $data;
		});
		echo json_encode(['ok' => true]);
		exit;
	}

	if ($action === 'leave') {
		$token = $input['token'] ?? '';
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }
		modifyMobileTrackers($mobileFile, function($data) use ($token) {
			return array_values(array_filter($data, fn($t) => !hash_equals($t['token'], $token)));
		});
		echo json_encode(['ok' => true]);
		exit;
	}

	http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
}

// Reached only for the HTML page (every API endpoint above exits). Stop iOS
// Safari / installed-PWA from serving a stale cached copy so code changes land.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'config_parse.php';
$_cfg  = parseConfigYaml('config.yaml');
$_m    = $_cfg['map'] ?? [];
$_eventName = $_cfg['event'] ?? '';
$_lat  = isset($_m['lat'])  ? (float)$_m['lat']  : 37.5;
$_lon  = isset($_m['lon'])  ? (float)$_m['lon']  : -122.0;
$_zoom = isset($_m['zoom']) ? (int)$_m['zoom']   : 10;

$_clientIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']
    ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
    ?? $_SERVER['REMOTE_ADDR']);
$_serverIp = trim(shell_exec("hostname -I 2>/dev/null") ?: '');
$_serverIp = $_serverIp ? explode(' ', $_serverIp)[0] : $_SERVER['SERVER_ADDR'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<title>MARS APRS Map</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-omnivore@0.3.4/leaflet-omnivore.min.js"></script>
<script src="utils.js"></script>
<style>
/* ── Reset & shared ──────────────────────────────────────────────────────── */
:root { --aprs-sat: env(safe-area-inset-top, 0px); } /* read in JS for standalone status-bar floor */
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { width: 100%; height: 100%; overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', arial, sans-serif; }

@keyframes blink-anim { 50% { opacity: 0; } }
.blinking { animation: blink-anim 0.4s steps(2,end) infinite; }
.igate-beaconing { animation: blink-anim 0.4s steps(2,end) infinite; }
.igate-beaconing .legend-name, .igate-beaconing .m-layer-name { color: #2a8a2a; }
#no-loc-toast {
    display: none; position: fixed; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    background: #444; color: #fff; padding: 10px 20px;
    border-radius: 8px; font-size: 14px; z-index: 9999;
    box-shadow: 0 2px 8px rgba(0,0,0,.4); pointer-events: none;
    white-space: nowrap;
}

.tracker-label {
    background: none; border: none; box-shadow: none;
    font-weight: bold; font-size: 12px; white-space: nowrap;
    color: var(--tracker-label-color, #000);
}
.place-label {
    background: #fff; border: 1px solid #bbb; box-shadow: 0 1px 3px rgba(0,0,0,.15);
    font-weight: bold; font-size: 12px; white-space: nowrap;
    color: #000;
}
.place-label::before { display: none; }
.place-label-kiosk {
    background: none; border: none; box-shadow: none;
    font-weight: bold; font-size: 12px; white-space: nowrap; color: #000;
}
.place-label-kiosk::before { display: none; }
.tracker-marker { background: none !important; border: none !important; box-shadow: none !important; }

.coord-popup .leaflet-popup-content-wrapper { padding: 0; border-radius: 6px; }
.coord-popup .leaflet-popup-content { margin: 0; }
.coord-copy {
    background: none; border: none; cursor: pointer; padding: 2px 3px;
    color: #666; display: flex; align-items: center; flex-shrink: 0;
}
.coord-copy:hover, .coord-copy:active { color: #111; }
.coord-copy .icon-copy  { display: block; }
.coord-copy .icon-check { display: none; color: #2a8a2a; }
.coord-copy.copied .icon-copy  { display: none; }
.coord-copy.copied .icon-check { display: block; }

.origin-overlay {
    position: absolute; z-index: 900; pointer-events: auto;
    background: rgba(255,255,255,0.95); border: 1px solid #aaa;
    border-radius: 5px; padding: 4px 10px;
    font-size: 13px; font-family: monospace;
    white-space: nowrap; box-shadow: 0 1px 6px rgba(0,0,0,.2);
    display: flex; align-items: center; gap: 7px;
}

/* ── Desktop layout (default) ────────────────────────────────────────────── */
body { display: flex; }
#map { flex: 1; height: 100vh; }

.leaflet-interactive,
.leaflet-grab,
.leaflet-dragging .leaflet-grab { cursor: default !important; }

/* Custom scale control: an opaque, padded button. Click toggles miles ↔ km. */
.aprs-scale {
    background: rgba(255,255,255,0.92);
    padding: 4px 8px 5px;
    border: 1px solid #bbb;
    border-radius: 5px;
    box-shadow: 0 1px 4px rgba(0,0,0,.2);
    cursor: pointer;
    pointer-events: auto !important;
    user-select: none;
    font: 11px/1.1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: #333;
}
.aprs-scale:hover { background: #fff; }
.aprs-scale-bar {
    border: 2px solid #555; border-top: none;
    padding: 1px 4px 2px; text-align: center; white-space: nowrap;
    box-sizing: content-box; min-width: 24px;
}

#sidebar {
    display: flex; flex-direction: column;
    width: 160px; min-width: 160px; height: 100vh;
    overflow: hidden; background: #f4f4f4;
    border-right: none;
}
#sidebar-resizer {
    width: 5px; flex-shrink: 0; cursor: ew-resize;
    background: #ccc;
    transition: background 0.15s;
    z-index: 10;
}
#sidebar-resizer:hover, body.sidebar-resizing #sidebar-resizer { background: #999; }
body.sidebar-resizing { cursor: ew-resize !important; user-select: none !important; }
#sidebar-scroll {
    flex: 1; overflow-y: auto; padding: 10px 8px;
}
#sidebar-footer {
    flex-shrink: 0; padding: 0 8px 10px;
}
.sec-hdr {
    display: flex; align-items: center; justify-content: space-between;
    width: 100%; padding: 0 6px; min-height: 26px;
    background: #eaeaea; border-top: 1px solid #ccc;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: #666; cursor: pointer;
}
.sec-hdr:first-child { border-top: none; }
.sec-hdr:hover, .sec-hdr:active { background: #e0e0e0; }
.sec-hdr-right { display: flex; align-items: center; }
.aprs-hide-trackers .tracker-label { display: none !important; }
.section-dimmed { opacity: 0.35; }
.section-divider { border: none; border-top: 1px solid #ccc; margin: 6px 0 4px; }

.legend-item {
    display: flex; align-items: center; gap: 7px;
    padding: 2px 4px; border-radius: 4px; cursor: default; margin-bottom: 1px;
}
.legend-item.clickable { cursor: pointer; }
.legend-item.clickable:hover { background: #e0e0e0; }
.legend-item.selected  { background: #d0e8ff; }
.legend-dot  { width: 12px; height: 12px; border-radius: 50%; border: 1px solid #333; flex-shrink: 0; }
.legend-text { font-size: 13px; line-height: 1.3; flex: 1; }
.legend-id   { }
.legend-name { color: #444; }
.legend-sub  { font-size: 11px; color: #888; }
.legend-time { font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }

.sidebar-item {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px; padding: 4px 0 4px 4px; border-radius: 4px;
    cursor: pointer; margin-bottom: 2px; color: #333; position: relative;
}
.sidebar-item:hover { background: #e0e0e0; }
.course-item  { cursor: default; padding: 2px 0 2px 4px; margin-bottom: 1px; }
.course-item, #backgrounds .sidebar-item { padding-right: 6px; }
.course-label { flex: 1; display: flex; align-items: center; cursor: pointer; min-width: 0; }
.course-name  { flex: 1; }
.course-name:hover { text-decoration: underline; }
.course-color-input { position: absolute; width: 0; height: 0; border: none; padding: 0; overflow: hidden; }
.course-checkbox, .bg-checkbox, .sec-vis-cb {
    appearance: none; -webkit-appearance: none;
    width: 14px; height: 14px; flex-shrink: 0; margin: 0;
    border: 1.5px solid #aaa; border-radius: 2px; background: #fff;
}
.course-checkbox { cursor: pointer; }
.bg-checkbox     { pointer-events: none; }
.sec-vis-cb      { cursor: pointer; }
.course-checkbox:checked,
.bg-checkbox:checked,
.sec-vis-cb:checked {
    border-color: #aaa;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23aaa' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: center; background-size: 10px;
}

.sidebar-btn-row {
    display: flex; gap: 5px; margin-top: 8px;
}
.sidebar-btn-row:first-of-type { margin-top: 14px; }
.sidebar-btn {
    flex: 1; padding: 6px 0;
    background: #e8e8e8; border: 1px solid #bbb; border-radius: 4px;
    font-size: 12px; color: #555; cursor: pointer; text-align: center;
    text-decoration: none; display: block;
}
.sidebar-btn:hover { background: #d8d8d8; }
#userguide-btn { text-decoration: none; }

.kiosk-footer-btn {
    padding: 2px 8px; background: rgba(255,255,255,0.85); border: 1px solid #bbb;
    border-radius: 3px; font-size: 11px; font-family: arial,helvetica,sans-serif;
    color: #333; cursor: pointer; margin-right: 4px; vertical-align: middle;
}
.kiosk-footer-btn:hover { background: #fff; }

.coord-popup-inner {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 8px; font-family: monospace; font-size: 13px; white-space: nowrap;
}

/* hide mobile elements on desktop */
#mobile-gear-btn, #mobile-backdrop, #mobile-drawer { display: none; }

/* ── Tablet layout (768px–1024px): sidebar stays, touch-friendly sizing ── */
@media (min-width: 768px) and (max-width: 1024px) {
    #sidebar { width: 200px; min-width: 200px; }
    #sidebar-scroll { padding: 12px 10px; }
    #sidebar-footer { padding: 0 10px 10px; }
    .section-heading { font-size: 12px; }
    .legend-dot  { width: 12px; height: 12px; }
    .legend-text { font-size: 12px; }
    .legend-time { font-size: 11px; }
    .sidebar-item { font-size: 12px; }
    .course-item  { font-size: 12px; }
    .sidebar-btn  { padding: 10px 0; font-size: 12px; }
    .sidebar-btn-row { gap: 6px; margin-top: 10px; }
    .sidebar-btn-row:first-of-type { margin-top: 18px; }
    .sidebar-btn-row:last-of-type { margin-top: 10px; }
    .course-checkbox, .bg-checkbox { width: 18px; height: 18px; }
}
/* Portrait, larger tablets (820px+): slightly generous rows */
@media (min-width: 820px) and (max-width: 1024px) and (orientation: portrait) {
    .legend-item  { padding: 3px 4px; }
    .sidebar-item { padding: 5px 0 5px 4px; }
    .course-item  { padding: 3px 0 3px 4px; }
}
/* Portrait, small tablets (<820px): desktop-density rows */
@media (min-width: 768px) and (max-width: 819px) and (orientation: portrait) {
    .legend-item  { padding: 2px 4px; }
    .sidebar-item { padding: 3px 0 3px 4px; }
    .course-item  { padding: 2px 0 2px 4px; }
}
/* Landscape, small tablets (≤1024px wide = small tablets only): same compact rows as portrait */
@media (min-width: 768px) and (max-width: 1024px) and (orientation: landscape) {
    .legend-item  { padding: 2px 4px; }
    .sidebar-item { padding: 3px 0 3px 4px; }
    .course-item  { padding: 2px 0 2px 4px; }
}

/* ── Tablet sidebar gear toggle ────────────────────────────────────────── */
#sidebar-toggle-btn { display: none; }
@media (pointer: coarse) and (min-width: 768px) {
    #sidebar-toggle-btn {
        display: flex; align-items: center; justify-content: center;
        position: fixed; top: 80px; left: 0; z-index: 500;
        width: 30px; height: 36px;
        background: rgba(255,255,255,0.95); color: #555;
        border: 1px solid #ccc; border-left: none;
        border-radius: 0 8px 8px 0;
        cursor: pointer;
        box-shadow: 2px 1px 8px rgba(0,0,0,.15);
        transition: left 0.28s cubic-bezier(.4,0,.2,1);
    }
    #sidebar-toggle-btn:active { background: #f0f0f0; }
    #sidebar-toggle-btn.sidebar-shown { left: 200px; }
}
/* Large tablet landscape (>1024px wide): sidebar falls back to desktop 160px width */
@media (pointer: coarse) and (min-width: 1025px) {
    #sidebar-toggle-btn.sidebar-shown { left: 160px; }
}

/* ── Mobile layout: phones + all touch devices (tablets any orientation) ── */
@media (max-width: 767px),
       (max-height: 500px) and (orientation: landscape),
       (pointer: coarse) {
    body  { display: block; }
    #map  { position: fixed; inset: 0; z-index: 0; }
    #sidebar { display: none; }
    #sidebar-resizer { display: none; }
    #sidebar-toggle-btn { display: none !important; }

    .leaflet-top                  { top:    10px; }
    .leaflet-bottom.leaflet-right { bottom: 10px; }
    .leaflet-bottom.leaflet-left  { bottom: 10px; }

    /* Event name label: fixed bottom-left */
    #mobile-event-name {
        display: none; position: absolute;
        bottom: max(10px, env(safe-area-inset-bottom));
        left:   max(10px, env(safe-area-inset-left));
        z-index: 1100;
        font-size: 12px; font-family: arial, helvetica, sans-serif;
        color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.8), 0 0 6px rgba(0,0,0,0.5);
        pointer-events: none;
    }

    /* Gear button: fixed top-right, respects safe area for notch/dynamic island */
    /* Positioned absolutely INSIDE the Leaflet map container (see JS), not
       fixed on <body>: iOS Safari anchors position:fixed to the layout
       viewport and hides such elements behind its toolbars during the
       URL-bar transition. Leaflet's own controls work because they live in
       the map container, so these overlays do too. */
    #mobile-gear-btn {
        display: flex; align-items: center; justify-content: center;
        position: absolute;
        /* Fallback position; JS re-pins to the visible viewport (handles zoom). */
        top: max(10px, env(safe-area-inset-top));
        right: max(10px, env(safe-area-inset-right));
        z-index: 1400;
        width: 36px; height: 36px;
        background: rgba(255,255,255,0.95); color: #555;
        border: 1px solid #ccc; border-radius: 6px;
        cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.18);
        touch-action: manipulation; /* prevent iOS scroll-gesture delay on 1px-scrollable doc */
        pointer-events: auto;
    }
    #mobile-gear-btn:active { background: #f0f0f0; }

    /* Backdrop — visual dim only; pointer-events:none lets map touches through */
    #mobile-backdrop {
        display: none; position: fixed; inset: 0; z-index: 1200;
        background: rgba(0,0,0,0.35); pointer-events: none;
    }
    #mobile-backdrop.open { display: block; }

    /* Drawer: slides in from left */
    #mobile-drawer {
        display: flex; flex-direction: column;
        position: fixed; top: 0; bottom: 0; left: 0;
        width: min(72vw, 260px); z-index: 1300;
        background: #fff; box-shadow: 3px 0 16px rgba(0,0,0,.22);
        transform: translateX(-100%);
        transition: transform 0.28s cubic-bezier(.4,0,.2,1);
        pointer-events: none;
    }
    #mobile-drawer.open { transform: translateX(0); pointer-events: auto; }

    /* Drawer scrollable body — top padding clears status bar / notch */
    #drawer-body { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding-top: env(safe-area-inset-top); }

    /* Accordion sections */
    .drawer-sec { }
    .drawer-sec-hdr {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; padding: 0 10px; min-height: 22px;
        background: #f0f0f0; border: none;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; font-family: inherit; color: #777;
        cursor: pointer; text-align: left;
    }
    .drawer-sec-hdr:active { background: #e6e6e6; }

    /* About section inline content */
    #m-about-body { padding: 8px 10px; font-size: 11px; color: #555; }
    .m-about-row { display: flex; gap: 6px; margin-bottom: 4px; align-items: baseline; }
    .m-about-label { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #999; white-space: nowrap; min-width: 52px; }
    .m-about-val { color: #333; line-height: 1.3; }
    .m-about-val a { color: #2980b9; }

    /* tracker rows */
    .m-legend-item {
        display: flex; align-items: center; gap: 8px;
        padding: 0 10px; min-height: 26px;
        cursor: pointer; user-select: none; -webkit-user-select: none;
    }
    .m-legend-item:active   { background: #f0f6ff; }
    .m-legend-item.selected { background: #e8f2ff; }
    .m-dot  { width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid #333; flex-shrink: 0; }
    .m-id   { font-size: 12px; min-width: 22px; }
    .m-name { font-size: 12px; flex: 1; color: #222; }
    .m-time { font-size: 10px; color: #888; white-space: nowrap; font-variant-numeric: tabular-nums; }

    /* course rows */
    .m-course-row {
        display: flex; align-items: center; gap: 8px;
        padding: 0 10px; min-height: 26px;
    }
    .m-course-label { flex: 1; display: flex; align-items: center; cursor: pointer; font-size: 12px; }
    .m-course-name  { flex: 1; }
    .m-course-name:hover { text-decoration: underline; }
    .m-course-color-input { position: absolute; width: 0; height: 0; border: none; padding: 0; overflow: hidden; }
    .m-checkbox {
        appearance: none; -webkit-appearance: none;
        width: 16px; height: 16px; flex-shrink: 0;
        border: 1.5px solid #aaa; border-radius: 3px; background: #fff; cursor: pointer;
    }
    .m-checkbox:checked {
        border-color: #222;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23000' stroke-width='2.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: center; background-size: 11px;
    }

    .m-layer-row {
        display: flex; align-items: center; gap: 8px;
        padding: 0 10px; min-height: 26px; cursor: pointer;
    }
    .m-layer-row:active   { background: #f0f6ff; }
    .m-layer-row.selected { background: #e8f2ff; }
    .m-layer-name  { flex: 1; font-size: 12px; color: #222; }
    .m-layer-check {
        appearance: none; -webkit-appearance: none;
        width: 16px; height: 16px; flex-shrink: 0;
        border: 1.5px solid #aaa; border-radius: 50%; background: #fff; pointer-events: none;
    }
    .m-layer-check.checked {
        border-color: #2980b9; background: #2980b9;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23fff' stroke-width='2.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: center; background-size: 11px;
    }

    /* Actions grid */
    .m-actions-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 5px; padding: 6px 10px;
    }
    .m-action-btn {
        display: flex; align-items: center; justify-content: center;
        min-height: 32px; background: #f5f5f5; border: 1px solid #e0e0e0;
        border-radius: 5px; font-size: 11px; font-family: inherit; color: #333;
        text-decoration: none; cursor: pointer; text-align: center;
    }
    .m-action-btn:active { background: #e5e5e5; }

    /* distance popup */
    .coord-popup .leaflet-popup-content-wrapper { border-radius: 8px; }
    .dist-popup-inner { padding: 8px 14px; font-size: 14px; font-family: inherit; text-align: center; }
    .dist-popup-inner .dp-dist    { font-size: 18px; font-weight: 700; color: #222; }
    .dist-popup-inner .dp-bearing { font-size: 13px; color: #666; margin-top: 2px; }

    .m-empty { padding: 20px 16px; font-size: 13px; color: #aaa; text-align: center; }

    /* Always-visible action buttons pinned to drawer bottom, above home indicator */
    #drawer-footer {
        flex-shrink: 0;
        border-top: 1px solid #e8e8e8;
        padding: 6px 10px max(6px, env(safe-area-inset-bottom));
        background: #fff;
    }
}

/* ── Clients modal ─────────────────────────────────────────────────────── */
#clients-modal {
    position: fixed; inset: 0; z-index: 10000;
    display: flex; align-items: center; justify-content: center;
}
#clients-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
#clients-box {
    position: relative; background: #fff; border-radius: 8px;
    padding: 18px 22px; min-width: 280px; max-width: 420px; width: 90%;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px;
}
#clients-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; font-size: 14px; font-weight: bold;
}
#clients-close {
    background: none; border: none; font-size: 20px; line-height: 1;
    cursor: pointer; color: #888; padding: 0 2px;
}
#clients-close:hover { color: #000; }
.clients-stat {
    display: flex; justify-content: space-between;
    padding: 4px 0; border-bottom: 1px solid #f0f0f0;
}
.clients-stat-label { color: #666; }
.clients-stat-value { font-weight: 600; }
.clients-ip-list { margin-top: 14px; }
.clients-ip-title { font-weight: bold; color: #444; margin-bottom: 6px; }
.clients-ip-row {
    display: flex; justify-content: space-between;
    padding: 3px 0; font-family: monospace; font-size: 12px;
    border-bottom: 1px solid #f8f8f8;
}
.clients-none { color: #aaa; font-style: italic; }

/* ── Screen dim overlay (mobile tracking battery saver) ───────────────── */
#screen-dim {
    display: none; position: fixed; inset: 0; z-index: 19000;
    background: rgba(0,0,0,0.88);
    align-items: center; justify-content: center;
    cursor: pointer;
}
#screen-dim.active { display: flex; }
#screen-dim span {
    color: rgba(255,255,255,0.35); font-size: 14px;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    pointer-events: none; user-select: none;
}

/* ── Connection modal ──────────────────────────────────────────────────── */
#conn-modal {
    position: fixed; inset: 0; z-index: 10000;
    display: flex; align-items: center; justify-content: center;
}
#conn-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
#conn-box {
    position: relative; background: #fff; border-radius: 8px;
    padding: 18px 22px; min-width: 260px;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px;
}
#conn-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; font-size: 14px; font-weight: bold;
}
#conn-close {
    background: none; border: none; font-size: 20px; line-height: 1;
    cursor: pointer; color: #888; padding: 0 2px;
}
#conn-close:hover { color: #000; }
.conn-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #f0f0f0; gap: 16px; }
.conn-row:last-child { border-bottom: none; }
.conn-label { color: #666; white-space: nowrap; }
.conn-value { font-family: monospace; font-size: 13px; font-weight: 600; }

/* ── About modal ───────────────────────────────────────────────────────── */
#about-modal {
    position: fixed; inset: 0; z-index: 10000;
    display: flex; align-items: center; justify-content: center;
}
#about-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
#about-box {
    position: relative; background: #fff; border-radius: 8px;
    min-width: 260px; max-width: 340px; width: 88%;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1; overflow: hidden;
    font-family: arial, helvetica, sans-serif;
}
#about-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 16px; background: rgba(28,40,51,0.95); color: #fff;
    font-size: 14px; font-weight: bold;
}
#about-close {
    background: none; border: none; font-size: 20px; line-height: 1;
    cursor: pointer; color: #aaa; padding: 0 2px;
}
#about-close:hover { color: #fff; }
#about-body { padding: 14px 16px; }
.about-row { margin-bottom: 10px; }
.about-row:last-child { margin-bottom: 0; }
.about-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #999; margin-bottom: 2px; }
.about-val { font-size: 13px; color: #222; line-height: 1.4; }
.about-val a { color: #2980b9; }

/* ── Mobile join modal ─────────────────────────────────────────────────── */
#mobile-join-modal {
    position: fixed; inset: 0; z-index: 10000;
    display: flex; align-items: center; justify-content: center;
}
#mjoin-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
#mjoin-box {
    position: relative; background: #fff; border-radius: 8px;
    padding: 18px 22px; min-width: 260px; max-width: 320px; width: 88%;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px;
}
#mjoin-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; font-size: 14px; font-weight: bold;
}
#mjoin-close {
    background: none; border: none; font-size: 20px; line-height: 1;
    cursor: pointer; color: #888; padding: 0 2px;
}
#mjoin-close:hover { color: #000; }
.mjoin-label { display: block; margin-bottom: 4px; color: #555; font-size: 12px; }
.mjoin-input {
    display: block; width: 100%; box-sizing: border-box;
    padding: 7px 10px; border: 1px solid #ccc; border-radius: 5px;
    font-size: 13px; margin-bottom: 12px;
}
.mjoin-input:focus { outline: none; border-color: #2980b9; }
#mjoin-error { color: #c0392b; font-size: 12px; min-height: 18px; margin-bottom: 8px; }
#mjoin-submit {
    width: 100%; padding: 9px; background: #2980b9; color: #fff;
    border: none; border-radius: 5px; font-size: 13px; font-weight: 600;
    cursor: pointer;
}
#mjoin-submit:hover { background: #1a6fa0; }
#mjoin-submit:disabled { background: #aaa; cursor: default; }

#mobile-alert-modal {
    position: fixed; inset: 0; z-index: 10001;
    display: flex; align-items: center; justify-content: center;
}
#malert-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
#malert-box {
    position: relative; background: #fff; border-radius: 8px;
    padding: 20px 24px; min-width: 220px; max-width: 300px; width: 82%;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px; text-align: center;
}
#malert-title { font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #c0392b; }
#malert-message { margin-bottom: 18px; color: #444; line-height: 1.5; }
#malert-ok {
    padding: 8px 28px; background: #2980b9; color: #fff;
    border: none; border-radius: 5px; font-size: 13px; font-weight: 600; cursor: pointer;
}
#malert-ok:hover { background: #1a6fa0; }

/* ── APRS Path (inline in tracker popup + breadcrumb tooltip) ─────────────── */
.popup-path { margin-top: 6px; padding-top: 6px; border-top: 1px solid #e2e2e2; }
.aprs-path-tip { white-space: normal; max-width: 240px; }
.aprs-path-popup .leaflet-popup-content { margin: 9px 12px; max-width: 240px; }
.aprs-path-time { font-size: 12px; color: #888; margin-bottom: 6px; }
.aprs-path-hops { display: flex; flex-direction: column; gap: 5px; }
.aprs-path-hop { display: flex; align-items: baseline; gap: 8px; font-size: 13px; }
.aprs-path-hop code {
    font-family: monospace; background: #eef; padding: 1px 5px;
    border-radius: 3px; font-size: 12px; color: #1a1a6e;
}
.aprs-path-desc { font-size: 12px; color: #666; }
.aprs-path-digi { font-size: 12px; color: #2a7a2a; }
.aprs-path-empty { color: #999; font-style: italic; font-size: 13px; }
</style>
</head>
<body>

<!-- ── Desktop sidebar ─────────────────────────────────────────────────── -->
<div id="sidebar">
	<div id="sidebar-scroll">
		<div class="sec-hdr open" data-body="legend"><span>Trackers</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="trackers"></span></div>
		<div id="legend"></div>

		<div id="courses-section" style="display:none">
			<div class="sec-hdr open" data-body="courses"><span>Courses</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="courses"></span></div>
			<div id="courses"></div>
		</div>

		<div id="aidstations-section" style="display:none">
			<div class="sec-hdr open" data-body="aidstations"><span>Aid Stations</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="aidstations"></span></div>
			<div id="aidstations"></div>
		</div>

		<div id="igates-section" style="display:none">
			<div class="sec-hdr open" data-body="igates"><span>iGates</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="igates"></span></div>
			<div id="igates"></div>
		</div>

		<div id="backgrounds-section" style="display:none">
			<div class="sec-hdr open" data-body="backgrounds"><span>Backgrounds</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="backgrounds"></span></div>
			<div id="backgrounds"></div>
		</div>
	</div>

	<div id="sidebar-footer">
		<hr class="section-divider">
		<div class="sidebar-btn-row">
			<button id="reset-btn" class="sidebar-btn">Reset Map</button>
			<button id="save-map-btn" class="sidebar-btn">Save Map</button>
		</div>
		<div class="sidebar-btn-row">
			<a href="?kiosk=1" id="kiosk-btn" class="sidebar-btn">Kiosk Mode</a>
			<a href="admin/" id="admin-btn" class="sidebar-btn">Admin</a>
		</div>
		<div class="sidebar-btn-row">
			<a href="https://marsaprs.org/userguide.html?back=/" id="userguide-btn" class="sidebar-btn" target="_blank">User Guide</a>
			<button id="about-btn" class="sidebar-btn">About</button>
		</div>
		<div class="sidebar-btn-row" id="share-loc-row" style="display:none">
			<button id="share-loc-btn" class="sidebar-btn">Share Location</button>
		</div>
		<div class="sidebar-btn-row" id="fs-sidebar-row" style="display:none">
			<button id="fs-btn" class="sidebar-btn">Full Screen</button>
		</div>
	</div>
</div>

<!-- ── Tablet sidebar gear toggle ─────────────────────────────────────── -->
<button id="sidebar-toggle-btn" title="Toggle sidebar">
	<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
</button>

<!-- ── Sidebar resize handle ───────────────────────────────────────────── -->
<div id="sidebar-resizer"></div>

<!-- ── Shared map ──────────────────────────────────────────────────────── -->
<div id="map"></div>

<!-- ── Mobile event name label ─────────────────────────────────────────── -->
<div id="mobile-event-name"></div>

<!-- ── Mobile gear button ──────────────────────────────────────────────── -->
<button id="mobile-gear-btn" title="Menu">
	<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
</button>

<!-- ── Mobile backdrop ─────────────────────────────────────────────────── -->
<div id="mobile-backdrop"></div>

<!-- ── Mobile drawer ──────────────────────────────────────────────────── -->
<div id="mobile-drawer">
	<div id="drawer-body">
		<div class="drawer-sec">
			<div class="drawer-sec-hdr open" data-body="m-trackers-body">
				<span>Trackers</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="trackers"></span>
			</div>
			<div id="m-trackers-body">
				<div id="m-legend"></div>
				<div id="m-legend-empty" class="m-empty" style="display:none">Waiting for tracker data…</div>
			</div>
		</div>
		<div class="drawer-sec">
			<div class="drawer-sec-hdr" data-body="m-courses-body">
				<span>Courses</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="courses"></span>
			</div>
			<div id="m-courses-body" style="display:none">
				<div id="m-courses-list"></div>
				<div id="m-courses-empty" class="m-empty" style="display:none">No courses configured.</div>
			</div>
		</div>
		<div class="drawer-sec" id="m-backgrounds-section" style="display:none">
			<div class="drawer-sec-hdr" data-body="m-backgrounds-body">
				<span>Backgrounds</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="backgrounds"></span>
			</div>
			<div id="m-backgrounds-body" style="display:none">
				<div id="m-backgrounds-list"></div>
			</div>
		</div>
		<div class="drawer-sec" id="m-aidstations-section" style="display:none">
			<div class="drawer-sec-hdr" data-body="m-aidstations-body">
				<span>Aid Stations</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="aidstations"></span>
			</div>
			<div id="m-aidstations-body" style="display:none">
				<div id="m-aidstations-list"></div>
			</div>
		</div>
		<div class="drawer-sec" id="m-igates-section" style="display:none">
			<div class="drawer-sec-hdr" data-body="m-igates-body">
				<span>iGates</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="igates"></span>
			</div>
			<div id="m-igates-body" style="display:none">
				<div id="m-igates-list"></div>
			</div>
		</div>
		<div class="drawer-sec">
			<div class="drawer-sec-hdr" data-body="m-about-body">
				<span>About</span>			</div>
			<div id="m-about-body" style="display:none"></div>
		</div>
	</div>
	<div id="drawer-footer">
		<div class="m-actions-grid">
			<button id="m-reset-btn" class="m-action-btn">Reset Map</button>
			<button id="m-save-map-btn" class="m-action-btn">Save Map</button>
			<a href="admin/" class="m-action-btn">Admin</a>
			<a href="https://marsaprs.org/userguide.html?back=/" class="m-action-btn" target="_blank">User Guide</a>
			<button id="m-share-loc-btn" class="m-action-btn" style="display:none">Share Location</button>
			<button id="m-fs-btn" class="m-action-btn" style="display:none">Full Screen</button>
		</div>
	</div>
</div><!-- #mobile-drawer -->

<div id="about-modal" style="display:none">
	<div id="about-backdrop"></div>
	<div id="about-box">
		<div id="about-header">
			<span>About</span>
			<button id="about-close">&times;</button>
		</div>
		<div id="about-body"></div>
	</div>
</div>

<div id="clients-modal" style="display:none">
	<div id="clients-backdrop"></div>
	<div id="clients-box">
		<div id="clients-header">
			<span>Connected Clients</span>
			<button id="clients-close">&times;</button>
		</div>
		<div id="clients-body"></div>
	</div>
</div>

<div id="conn-modal" style="display:none">
	<div id="conn-backdrop"></div>
	<div id="conn-box">
		<div id="conn-header">
			<span>Connection</span>
			<button id="conn-close">&times;</button>
		</div>
		<div class="conn-row"><span class="conn-label">Client</span><span id="conn-client" class="conn-value"></span></div>
		<div class="conn-row"><span class="conn-label">Server</span><span id="conn-server" class="conn-value"></span></div>
	</div>
</div>

<div id="screen-dim"><span>Tap to wake</span></div>

<div id="mobile-join-modal" style="display:none">
	<div id="mjoin-backdrop"></div>
	<div id="mjoin-box">
		<div id="mjoin-header">
			<span id="mjoin-title">Share My Location</span>
			<button id="mjoin-close">&times;</button>
		</div>
		<label class="mjoin-label" for="mjoin-name">Your name (12 characters max)</label>
		<input id="mjoin-name" class="mjoin-input" type="text" maxlength="12" autocomplete="off" autocorrect="off" spellcheck="false">
		<label class="mjoin-label" for="mjoin-pin">PIN code</label>
		<input id="mjoin-pin" class="mjoin-input" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
		<div id="mjoin-error"></div>
		<button id="mjoin-submit">Share Location</button>
	</div>
</div>

<div id="mobile-alert-modal" style="display:none">
	<div id="malert-backdrop"></div>
	<div id="malert-box">
		<div id="malert-title"></div>
		<div id="malert-message"></div>
		<button id="malert-ok">OK</button>
	</div>
</div>

<script>
'use strict';

// All touch (coarse-pointer) devices use the slide-in drawer; fine-pointer
// (mouse) desktops use the sidebar. visualViewport.width is used for the phone
// breakpoint to avoid iOS Safari's 980px layout-viewport timing issue on reload.
const _vvw    = (window.visualViewport && window.visualViewport.width) || window.innerWidth;
const _coarse = window.matchMedia('(pointer: coarse)').matches;
const isMobile = _vvw <= 767
    || window.matchMedia('(max-height: 500px) and (orientation: landscape)').matches
    || _coarse;
const isTablet = false;

// ── Map init ──────────────────────────────────────────────────────────────
let defaultView = { lat: <?= $_lat ?>, lon: <?= $_lon ?>, zoom: <?= $_zoom ?> };
const serverDefaultEvent = <?= json_encode($_eventName) ?>;
try {
	const _sv = localStorage.getItem('aprs_default_view');
	if (_sv) {
		const _parsed = JSON.parse(_sv);
		if (_parsed.event === serverDefaultEvent) {
			defaultView = _parsed;
		} else {
			localStorage.removeItem('aprs_default_view');
		}
	}
} catch {}
const clientIp = '<?= htmlspecialchars($_clientIp) ?>';
const serverIp = '<?= htmlspecialchars($_serverIp) ?>';
let mapViewInitialized = true;

const map = L.map('map', { zoomControl: false })
	.setView([defaultView.lat, defaultView.lon], defaultView.zoom);

L.control.zoom({ position: 'topleft' }).addTo(map);

// Scale control — fully custom so we own the click target and the unit toggle.
// Click anywhere on the box to switch miles/feet ↔ km/m.
let scaleImperial = true;
const ScaleControl = L.Control.extend({
	options: { position: 'bottomright' },
	onAdd: function (m) {
		const box = L.DomUtil.create('div', 'aprs-scale');
		const bar = L.DomUtil.create('div', 'aprs-scale-bar', box);
		this._box = box; this._bar = bar; this._map = m;
		const refresh = () => this._update();
		m.on('move zoom moveend zoomend', refresh);
		m.whenReady(refresh);
		// Own the gesture: stop it reaching the map (no pan/click/dblclick-zoom).
		L.DomEvent.disableClickPropagation(box);
		L.DomEvent.disableScrollPropagation(box);
		L.DomEvent.on(box, 'dblclick', L.DomEvent.stop);
		L.DomEvent.on(box, 'click', (e) => {
			L.DomEvent.stop(e);
			scaleImperial = !scaleImperial;
			this._update();
		});
		return box;
	},
	_roundNum: function (n) {
		const pow10 = Math.pow(10, (Math.floor(n) + '').length - 1);
		let d = n / pow10;
		d = d >= 10 ? 10 : d >= 5 ? 5 : d >= 3 ? 3 : d >= 2 ? 2 : 1;
		return pow10 * d;
	},
	_update: function () {
		const m = this._map, maxWidth = 100, y = m.getSize().y / 2;
		const maxMeters = m.distance(
			m.containerPointToLatLng([0, y]),
			m.containerPointToLatLng([maxWidth, y]));
		if (!maxMeters) return;
		let dist, label, ratio;
		if (scaleImperial) {
			const maxFeet = maxMeters * 3.2808399;
			if (maxFeet > 5280) {
				const maxMiles = maxFeet / 5280;
				dist = this._roundNum(maxMiles);
				label = dist + ' mi'; ratio = dist / maxMiles;
			} else {
				dist = this._roundNum(maxFeet);
				label = dist + ' ft'; ratio = dist / maxFeet;
			}
		} else {
			const meters = this._roundNum(maxMeters);
			label = meters < 1000 ? meters + ' m' : (meters / 1000) + ' km';
			ratio = meters / maxMeters;
		}
		this._bar.style.width = Math.round(maxWidth * ratio) + 'px';
		this._bar.textContent = label;
		this._box.title = 'Click to switch to ' + (scaleImperial ? 'kilometers' : 'miles');
	}
});
new ScaleControl().addTo(map);

map.createPane('coursePane');
map.getPane('coursePane').style.zIndex = 410;
const courseRenderer = L.svg({ pane: 'coursePane' });
map.createPane('trackerPane');
map.getPane('trackerPane').style.zIndex = 450;
map.createPane('aidPane');
map.getPane('aidPane').style.zIndex = 430;
map.createPane('igatePane');
map.getPane('igatePane').style.zIndex = 390;

// ── Kiosk mode (desktop only) ─────────────────────────────────────────────
const kiosk = !isMobile && new URLSearchParams(location.search).get('kiosk') === '1';
let backgroundsInitialized = false;

new (L.Control.extend({
	onAdd() {
		const d = L.DomUtil.create('div', 'leaflet-control-attribution');
		if (kiosk) {
			const sidebarBtn = L.DomUtil.create('button', 'kiosk-footer-btn', d);
			sidebarBtn.textContent = 'Sidebar';
			L.DomEvent.on(sidebarBtn, 'click', () => {
				const sb = document.getElementById('sidebar');
				const hidden = sb.style.display !== 'none';
				sb.style.display = hidden ? 'none' : '';
				localStorage.setItem('aprs_kiosk_sidebar', hidden ? '0' : '1');
			});
			L.DomEvent.disableClickPropagation(sidebarBtn);
			const resetBtn = L.DomUtil.create('button', 'kiosk-footer-btn', d);
			resetBtn.textContent = 'Reset Map';
			L.DomEvent.on(resetBtn, 'click', () => {
				clearAllSelections();
				map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
			});
			L.DomEvent.disableClickPropagation(resetBtn);
			const exitBtn = L.DomUtil.create('button', 'kiosk-footer-btn', d);
			exitBtn.textContent = 'Exit';
			L.DomEvent.on(exitBtn, 'click', () => { location.href = location.pathname; });
			L.DomEvent.disableClickPropagation(exitBtn);
			const txt = L.DomUtil.create('span', '', d);
			txt.innerHTML = '&ensp;Marin Amateur Radio Society APRS Tracking v1.12 &copy; 2026 Doug Kaye (K6DRK)';
		} else {
			if (!isMobile) {
				const exitBtn2 = L.DomUtil.create('button', 'kiosk-footer-btn', d);
				exitBtn2.textContent = 'Exit';
				L.DomEvent.on(exitBtn2, 'click', () => { window.location.href = 'http://localhost:8080/exit'; });
				L.DomEvent.disableClickPropagation(exitBtn2);
				const connBtn2 = L.DomUtil.create('button', 'kiosk-footer-btn', d);
				connBtn2.textContent = 'IP';
				L.DomEvent.on(connBtn2, 'click', openConnModal);
				L.DomEvent.disableClickPropagation(connBtn2);
				const clientsBtn = L.DomUtil.create('button', 'kiosk-footer-btn', d);
				clientsBtn.textContent = 'Clients';
				L.DomEvent.on(clientsBtn, 'click', openClientsModal);
				L.DomEvent.disableClickPropagation(clientsBtn);
			}
			const ftxt = L.DomUtil.create('span', '', d);
			ftxt.innerHTML = isMobile
				? 'MARS APRS v1.12 &copy; 2026 Doug Kaye (K6DRK)'
				: '&ensp;Marin Amateur Radio Society APRS Tracking v1.12 &copy; 2026 Doug Kaye (K6DRK)';
			if (isMobile) d.style.fontSize = '10px';
		}
		return d;
	}
}))({ position: isMobile ? 'bottomright' : 'bottomleft' }).addTo(map);

let eventNameDiv;
if (isMobile) {
	eventNameDiv = document.getElementById('mobile-event-name');
	// Move into the Leaflet map container so iOS Safari keeps it on screen
	// (position:fixed on <body> gets hidden behind Safari's toolbars).
	map.getContainer().appendChild(eventNameDiv);
} else {
	new (L.Control.extend({
		onAdd() {
			eventNameDiv = L.DomUtil.create('div', '');
			eventNameDiv.style.cssText = 'font-size:13px;font-family:arial,helvetica,sans-serif;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,0.8),0 0 6px rgba(0,0,0,0.5);padding:0 5px 2px;display:none';
			return eventNameDiv;
		}
	}))({ position: 'bottomleft' }).addTo(map);
}

let legendDiv;
if (!isMobile) {
	new (L.Control.extend({
		onAdd() {
			legendDiv = L.DomUtil.create('div', '');
			legendDiv.style.cssText = 'font-size:13px;font-family:arial,helvetica,sans-serif;color:#000;padding:6px 10px;display:none;line-height:1.5;max-width:300px;border:1px solid #000;background:rgba(255,255,255,0.85)';
			return legendDiv;
		}
	}))({ position: 'bottomleft' }).addTo(map);
}

let currentBgUrl          = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
let currentBgAttribution  = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
let currentTileLayer = L.tileLayer(currentBgUrl, {
	attribution: currentBgAttribution,
	maxZoom: 19
}).addTo(map);

function switchBackground(bg) {
	const mz = bg.maxZoom ?? bg.max_zoom ?? 19;
	map.removeLayer(currentTileLayer);
	currentBgUrl = bg.url; currentBgAttribution = bg.attribution;
	currentTileLayer = L.tileLayer(bg.url, { attribution: bg.attribution, maxZoom: mz }).addTo(map);
	if (!sectionVisible.backgrounds) currentTileLayer.setOpacity(0);
	map.setMaxZoom(mz);
	if (map.getZoom() > mz) map.setZoom(mz);
	reorderCourseLayers();
}

if (kiosk) {
	document.getElementById('backgrounds-section').style.display = 'none';
	document.getElementById('sidebar-footer').style.display = 'none';
	document.querySelectorAll('#sidebar .sec-hdr').forEach(el => { el.style.pointerEvents = 'none'; el.style.cursor = 'default'; });
	if (localStorage.getItem('aprs_kiosk_sidebar') === '0')
		document.getElementById('sidebar').style.display = 'none';
	document.documentElement.requestFullscreen().catch(() => {});
}

// ── State ─────────────────────────────────────────────────────────────────
const markers              = {};
const trackerPopups        = {};
let   trackerStyle         = { icon: 'circle', size: 8, labelColor: '#000000' };
const courseLayers         = {};
const courseDashes         = {};
const courseColors         = {};
let   courseOrder          = [];
const lastBeacons          = {};
const blinkTimers          = {};
const lastIgateBeacons     = {};	// callsign → lastBeacon timestamp (from igates.json)
const igateFlashTimers     = {};	// callsign → setTimeout id for green blink
const lastAidBeacons       = {};	// callsign → lastBeacon timestamp (from aidstations.json)
const aidFlashTimers       = {};	// callsign → setTimeout id for green blink
const historyDots          = {};	// callsign → [L.circleMarker, ...]
const DEFAULT_COURSE_COLOR = '#2196f3';
const LS_COURSE_COLORS     = 'aprs_course_colors';
let   currentEventName     = '';
const LS_COURSE_ACTIVE     = 'aprs_course_active';
const LS_BG                = 'aprs_bg_url';

function loadSavedColors() {
	try { return JSON.parse(localStorage.getItem(LS_COURSE_COLORS) || '{}'); } catch { return {}; }
}
function saveCourseColor(file, color) {
	const m = loadSavedColors();
	m[file] = color;
	localStorage.setItem(LS_COURSE_COLORS, JSON.stringify(m));
}
function loadActiveFiles() {
	try { const v = localStorage.getItem(LS_COURSE_ACTIVE); return v ? new Set(JSON.parse(v)) : null; }
	catch { return null; }
}
function saveActiveFiles() {
	localStorage.setItem(LS_COURSE_ACTIVE, JSON.stringify(Object.keys(courseLayers)));
}

let selectedCallsign   = null;
let trackerClickCount  = 0;
let igateMarkers       = [];
let selectedIgateIdx   = -1;
let igateClickCount    = 0;
let aidMarkers         = [];
let selectedAidIdx     = -1;
let aidClickCount      = 0;
let origin             = null;
let originMarker       = null;
let suppressOriginUntil = 0;  // ignore map contextmenu briefly after a sidebar long-press
let configEtag         = null;
let jsonEtag           = null;
let historyEtag        = null;
let historyCache       = null;	// last full ?history response; reused on 304
let coursesInitialized = false;

// ── Mobile drawer ─────────────────────────────────────────────────────────
// Always resolve elements by ID — isMobile JS flag can be wrong on iOS Safari
// due to viewport timing on reload; CSS media query works independently.
const mobileDrawer   = document.getElementById('mobile-drawer');
const mobileBackdrop = document.getElementById('mobile-backdrop');
const mobileGearBtn  = document.getElementById('mobile-gear-btn');
let drawerOpen = false;

function openMobileDrawer()  { if (!mobileDrawer) return; drawerOpen = true;  mobileDrawer.classList.add('open'); }
function closeMobileDrawer() { if (!mobileDrawer) return; drawerOpen = false; mobileDrawer.classList.remove('open'); }
function setSheetOpen(open)  { if (open) openMobileDrawer(); else closeMobileDrawer(); }

if (mobileGearBtn) {
	// Live inside the Leaflet map container (which fills the layout viewport).
	map.getContainer().appendChild(mobileGearBtn);

	// Pin the gear to the VISIBLE viewport's top-right corner. When Safari is
	// zoomed (Page Zoom or pinch), the visual viewport is smaller than the
	// layout viewport the map fills, so a right-anchored button lands off the
	// right edge. visualViewport gives the visible window; follow it on
	// zoom/pan. Falls back to the CSS top/right when unsupported.
	const pinGear = () => {
		const vv = window.visualViewport;
		if (!vv) return;
		const w   = mobileGearBtn.offsetWidth || 36;
		const sat = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--aprs-sat')) || 0;
		mobileGearBtn.style.right = 'auto';
		mobileGearBtn.style.left  = (vv.offsetLeft + vv.width - w - 10) + 'px';
		mobileGearBtn.style.top   = Math.max(vv.offsetTop + 10, sat + 10) + 'px';
	};
	if (window.visualViewport) {
		visualViewport.addEventListener('resize', pinGear);
		visualViewport.addEventListener('scroll', pinGear);
		pinGear();
		setTimeout(pinGear, 300);
	}

	refreshMobileAbout();
	let gearTouched = false;
	mobileGearBtn.addEventListener('touchstart', e => {
		e.preventDefault(); e.stopPropagation(); gearTouched = true;
		if (drawerOpen) closeMobileDrawer(); else openMobileDrawer();
	}, { passive: false });
	mobileGearBtn.addEventListener('click', e => {
		e.stopPropagation();
		if (gearTouched) { gearTouched = false; return; }
		if (drawerOpen) closeMobileDrawer(); else openMobileDrawer();
	});

	// Accordion: touchstart + click (touchstart fires immediately; click suppressed after touch)
	document.querySelectorAll('.drawer-sec-hdr').forEach(hdr => {
		let hdrTouched = false;
		function toggleSection() {
			const body = document.getElementById(hdr.dataset.body);
			if (!body) return;
			const isOpen = hdr.classList.contains('open');
			if (!isOpen) {
				document.querySelectorAll('.drawer-sec-hdr.open').forEach(oh => {
					oh.classList.remove('open');
					const ob = document.getElementById(oh.dataset.body);
					if (ob) ob.style.display = 'none';
				});
			}
			hdr.classList.toggle('open', !isOpen);
			body.style.display = isOpen ? 'none' : '';
		}
		hdr.addEventListener('touchstart', e => {
			if (e.target.classList.contains('sec-vis-cb')) return;
			e.preventDefault(); hdrTouched = true; toggleSection();
		}, { passive: false });
		hdr.addEventListener('click', e => {
			if (e.target.classList.contains('sec-vis-cb')) return;
			if (hdrTouched) { hdrTouched = false; return; }
			toggleSection();
		});
	});
}

// ── iOS Safari URL-bar retraction ─────────────────────────────────────────
// The map is position:fixed so the page has no scrollable height and Safari
// never auto-hides its URL bar. Give the body a 1px overshoot so the page is
// technically scrollable, then park at scrollY=1 so the bar retracts.
if (isMobile) {
	const retract = () => {
		document.body.style.height = (window.innerHeight + 1) + 'px';
		window.scrollTo(0, 1);
	};
	window.addEventListener('load', retract, { once: true });

	// On orientation change, wait for the viewport to actually report the new
	// dimensions before retracting and telling Leaflet to recalculate.
	// orientationchange fires *before* iOS updates innerHeight/visualViewport,
	// so a fixed setTimeout races; visualViewport.resize fires once the new
	// size is ready. We also call map.invalidateSize() so Leaflet redraws for
	// the new container dimensions (otherwise the map renders in the wrong half).
	let _awaitingOrient = false;
	window.addEventListener('orientationchange', () => { _awaitingOrient = true; });
	const _afterOrient = () => {
		if (!_awaitingOrient) return;
		_awaitingOrient = false;
		retract();
		map.invalidateSize();
	};
	if (window.visualViewport) visualViewport.addEventListener('resize', _afterOrient);
	else window.addEventListener('resize', _afterOrient);

	// Prevent accidental scroll-to-0 from re-showing the bar
	window.addEventListener('scroll', () => { if (window.scrollY === 0) window.scrollTo(0, 1); }, { passive: true });
}

// ── iOS "Add to Home Screen" nudge ───────────────────────────────────────────
// Shown on any iOS browser (Safari, Chrome, Firefox) when not already a PWA.
// Dismissed permanently via localStorage.
const _isIos       = /iP(hone|ad|od)/.test(navigator.userAgent);
const _isIosChrome = _isIos && /CriOS/.test(navigator.userAgent);
const _isStandalone = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
if (_isIos && !_isStandalone && !localStorage.getItem('a2hs-dismissed')) {
	const _instruction = _isIosChrome
		? 'tap <b>⋯</b> → <b>Add to Home Screen</b>'
		: 'tap <b>Share ⬆</b> → <b>Add to Home Screen</b>';
	const nudge = document.createElement('div');
	nudge.id = 'a2hs-nudge';
	nudge.innerHTML = 'For full-screen: ' + _instruction + ' <button id="a2hs-close" aria-label="Dismiss">Got it!</button>';
	nudge.style.cssText = 'position:fixed;bottom:max(12px,env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);z-index:9000;background:rgba(30,30,30,0.92);color:#fff;font-size:13px;padding:9px 14px;border-radius:10px;white-space:nowrap;box-shadow:0 2px 12px rgba(0,0,0,.4);display:flex;align-items:center;gap:10px';
	document.body.appendChild(nudge);
	document.getElementById('a2hs-close').style.cssText = 'background:none;border:1px solid rgba(255,255,255,0.4);border-radius:5px;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:4px 8px;flex-shrink:0';
	document.getElementById('a2hs-close').addEventListener('click', () => {
		nudge.remove();
		localStorage.setItem('a2hs-dismissed', '1');
	});
}

// ── Origin overlay ────────────────────────────────────────────────────────
const originOverlay = document.createElement('div');
originOverlay.className = 'origin-overlay';
originOverlay.style.display = 'none';
map.getContainer().appendChild(originOverlay);
let originOverlayTimer = null;

function showOriginOverlay() {
	if (!origin) return;
	clearTimeout(originOverlayTimer);
	const lat = origin.lat.toFixed(6), lon = origin.lng.toFixed(6);
	const text = `${lat}, ${lon}`;
	originOverlay.innerHTML =
		`<span>${text}</span>` +
		`<button class="coord-copy" title="Copy">` +
			`<svg class="icon-copy" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">` +
				`<rect x="9" y="2" width="13" height="13" rx="2"/>` +
				`<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>` +
			`</svg>` +
			`<svg class="icon-check" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">` +
				`<polyline points="20 6 9 17 4 12"/>` +
			`</svg>` +
		`</button>`;
	originOverlay.querySelector('.coord-copy').addEventListener('click', function() {
		navigator.clipboard.writeText(text).then(() => {
			this.classList.add('copied');
			setTimeout(() => this.classList.remove('copied'), 1500);
		});
	});
	const pt = map.latLngToContainerPoint(origin);
	originOverlay.style.left    = (pt.x + 14) + 'px';
	originOverlay.style.top     = (pt.y - 28) + 'px';
	originOverlay.style.display = 'flex';
}

function hideOriginOverlayDelayed() {
	originOverlayTimer = setTimeout(() => { originOverlay.style.display = 'none'; }, 280);
}

if (!isMobile) {
	originOverlay.addEventListener('mouseover', () => clearTimeout(originOverlayTimer));
	originOverlay.addEventListener('mouseout',  hideOriginOverlayDelayed);
}

// ── Helpers ───────────────────────────────────────────────────────────────
function markerScale() {
	const z = map.getZoom();
	return z >= 14 ? 1 : Math.max(0.3, (z - 7) / 7);
}
function scaledRadius(base) {
	return Math.max(2, Math.round(base * markerScale()));
}

function makeTrackerIcon(shape, fillColor, size) {
	const sz = Math.max(4, Math.min(20, size || 8));
	const d = sz * 2, c = sz, r = sz - 1.5;
	let inner;
	switch (shape) {
		case 'square':
			inner = `<rect x="1.5" y="1.5" width="${d-3}" height="${d-3}"/>`;
			break;
		case 'diamond':
			inner = `<polygon points="${c},1 ${d-1},${c} ${c},${d-1} 1,${c}"/>`;
			break;
		case 'triangle':
			inner = `<polygon points="${c},1 ${d-1},${d-1} 1,${d-1}"/>`;
			break;
		case 'star': {
			const pts = [];
			for (let i = 0; i < 10; i++) {
				const a  = (i * 36 - 90) * Math.PI / 180;
				const rr = i % 2 === 0 ? r : r * 0.42;
				pts.push(`${(c + rr * Math.cos(a)).toFixed(2)},${(c + rr * Math.sin(a)).toFixed(2)}`);
			}
			inner = `<polygon points="${pts.join(' ')}"/>`;
			break;
		}
		case 'xmark': {
			const hw = sz * 5 / 18, al = sz * 5 / 6, k = 0.7071;
			const xp = (x, y) => (c + (x - y) * k).toFixed(1) + ',' + (c + (x + y) * k).toFixed(1);
			inner = `<polygon points="${
				[[-hw,-al],[hw,-al],[hw,-hw],[al,-hw],[al,hw],[hw,hw],[hw,al],[-hw,al],[-hw,hw],[-al,hw],[-al,-hw],[-hw,-hw]]
				.map(([x, y]) => xp(x, y)).join(' ')}"/>`;
			break;
		}
		case 'hexagon': {
			const pts = [];
			for (let i = 0; i < 6; i++) {
				const a = (i * 60 - 90) * Math.PI / 180;
				pts.push(`${(c + r * Math.cos(a)).toFixed(2)},${(c + r * Math.sin(a)).toFixed(2)}`);
			}
			inner = `<polygon points="${pts.join(' ')}"/>`;
			break;
		}
		case 'person': {
			const hr = Math.round(r * 0.38), hy = Math.round(sz * 0.35) + hr;
			const by = hy + Math.round(hr * 1.3), bw = Math.round(r * 0.55);
			inner = `<circle cx="${c}" cy="${hy}" r="${hr}"/><polygon points="${c},${by} ${c-bw},${d-1.5} ${c+bw},${d-1.5}"/>`;
			break;
		}
		default:
			inner = `<circle cx="${c}" cy="${c}" r="${r}"/>`;
	}
	const svg = `<svg width="${d}" height="${d}" xmlns="http://www.w3.org/2000/svg"><g fill="${fillColor}" stroke="#333" stroke-width="1.5" stroke-linejoin="round">${inner}</g></svg>`;
	return L.divIcon({ html: svg, className: 'tracker-marker', iconSize: [d, d], iconAnchor: [sz, sz], popupAnchor: [0, -sz] });
}

function popupHtml(t) {
	let html = `<b>${esc(t.name)}</b> (${esc(t.id)})<br>${esc(t.callsign)}<br>Last heard ${esc(t.time)} ago`;
	if (t.mobile) {
		html += `<div class="popup-path" style="color:#555;font-style:italic">Mobile device</div>`;
	} else if (t.path) {
		html += `<div class="popup-path">${formatAprsPath(t.path)}</div>`;
	}
	return html;
}

function showNoLocation(name) {
	const el = document.getElementById('no-loc-toast');
	el.textContent = name + ': No location received';
	el.style.display = 'block';
	clearTimeout(showNoLocation._t);
	showNoLocation._t = setTimeout(() => el.style.display = 'none', 3000);
}

function hideAllHistoryDots() {
	Object.values(historyDots).forEach(dots => dots.forEach(d => d.remove()));
	Object.keys(historyDots).forEach(k => delete historyDots[k]);
}

function showTrackerHistory(callsign, color) {
	const r = Math.max(4, Math.round(trackerStyle.size * (isMobile ? 0.65 : 0.75)));
	const headers = historyEtag ? { 'If-None-Match': historyEtag } : {};
	fetch('index.php?history', { headers })
		.then(res => {
			if (res.status === 304) return historyCache;
			historyEtag = res.headers.get('ETag');
			return res.json().then(d => { historyCache = d; return d; });
		})
		.then(hist => {
			if (!hist) return;
			const raw = hist[callsign] || [];
			// Drop consecutive duplicate positions (same lat+lon reported twice in a row)
			const entries = raw.filter((e, i) => i === 0 || e.lat !== raw[i-1].lat || e.lon !== raw[i-1].lon);
			if (!entries.length) return;

			// Remove stale layers before redrawing
			if (historyDots[callsign]) { historyDots[callsign].forEach(d => d.remove()); }

			const layers = [];

			// Dots — newest first in entries array
			entries.forEach(e => {
				const dot = L.circleMarker([e.lat, e.lon], {
					radius: scaledRadius(r), color: color, fillColor: color,
					fillOpacity: 0.5, weight: 1.5, pane: 'trackerPane'
				});
				dot._baseRadius = r;
				const tipHtml = () => `<div class="aprs-path-time">${esc(relativeTime(e.ts))}</div>` + (e.path ? formatAprsPath(e.path) : '');
				dot.addTo(map);
				layers.push(dot);
				if (isMobile) {
					// No hover on touch. A larger, transparent hit circle on top of
					// the dot widens the tap target; tapping opens a popup (auto-pans
					// into view, dismissable) instead of an off-screen tooltip.
					const hit = L.circleMarker([e.lat, e.lon], {
						radius: Math.max(scaledRadius(r) + 12, 18), stroke: false,
						fillOpacity: 0, fillColor: color, pane: 'trackerPane'
					});
					hit.on('click', function(ev) {
						L.DomEvent.stopPropagation(ev);
						L.popup({ className: 'aprs-path-popup', autoPanPadding: [16, 16], offset: [0, -2] })
							.setLatLng(dot.getLatLng()).setContent(tipHtml()).openOn(map);
					});
					hit.addTo(map);
					layers.push(hit);
				} else {
					dot.bindTooltip(tipHtml(), { sticky: false, direction: 'top', className: 'aprs-path-tip' });
					dot.on('mouseover', function() { dot.setTooltipContent(tipHtml()); });
				}
			});

			// Build ordered path: oldest history → … → newest history → current position
			const histPts = [...entries].reverse().map(e => [e.lat, e.lon]);
			const cur = markers[callsign];
			const allPts = cur ? [...histPts, [cur.getLatLng().lat, cur.getLatLng().lng]] : histPts;

			if (allPts.length > 1) {
				// Dotted connecting line
				const line = L.polyline(allPts, {
					color, weight: 2, dashArray: '4 7', opacity: 0.65, pane: 'trackerPane'
				}).addTo(map);
				layers.push(line);

				// One arrowhead per segment, placed at the midpoint and rotated to bearing
				for (let i = 0; i < allPts.length - 1; i++) {
					const [lat1, lon1] = allPts[i], [lat2, lon2] = allPts[i + 1];
					const brg = bearingTo(lat1, lon1, lat2, lon2);
					const arw = L.marker([(lat1 + lat2) / 2, (lon1 + lon2) / 2], {
						icon: L.divIcon({
							html: `<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg" style="transform:rotate(${brg}deg);display:block"><polygon points="10,2 18,18 10,12 2,18" fill="${color}" opacity="0.85"/></svg>`,
							className: '', iconSize: [20, 20], iconAnchor: [10, 10]
						}),
						pane: 'trackerPane', interactive: false
					}).addTo(map);
					layers.push(arw);
				}
			}

			historyDots[callsign] = layers;
			if (blinkTimers[callsign]) blinkHistoryLayers(callsign, true);
		});
}

// ── Blink ─────────────────────────────────────────────────────────────────
function blinkHistoryLayers(callsign, on) {
	(historyDots[callsign] || []).forEach(layer => {
		const he = layer.getElement?.();
		if (he) on ? he.classList.add('blinking') : he.classList.remove('blinking');
	});
}

function triggerBlink(callsign) {
	if (blinkTimers[callsign]) clearTimeout(blinkTimers[callsign]);
	const id   = (isMobile ? 'm-legend-' : 'legend-') + callsign;
	const item = document.getElementById(id);
	if (item) item.classList.add('blinking');
	const el = markers[callsign]?.getElement();
	if (el) el.style.animation = 'blink-anim 0.4s steps(2,end) infinite';
	blinkHistoryLayers(callsign, true);
	blinkTimers[callsign] = setTimeout(() => {
		if (item) item.classList.remove('blinking');
		const e2 = markers[callsign]?.getElement();
		if (e2) e2.style.animation = '';
		blinkHistoryLayers(callsign, false);
		delete blinkTimers[callsign];
	}, 5000);
}

const courseBlinkTimers = {};
function triggerCourseBlink(file, nameEl) {
	if (courseBlinkTimers[file]) clearTimeout(courseBlinkTimers[file]);
	if (nameEl) nameEl.classList.add('blinking');
	const layer = courseLayers[file];
	if (layer) layer.eachLayer(l => { const el = l.getElement?.(); if (el) el.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; });
	courseBlinkTimers[file] = setTimeout(() => {
		if (nameEl) nameEl.classList.remove('blinking');
		const l2 = courseLayers[file];
		if (l2) l2.eachLayer(l => { const el = l.getElement?.(); if (el) el.style.animation = ''; });
		delete courseBlinkTimers[file];
	}, 5000);
}

function triggerDotBlink(d) {
	const el = d.m.getElement();
	if (el) { el.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; setTimeout(() => { el.style.animation = ''; }, 5000); }
	d.el.classList.add('blinking');
	setTimeout(() => d.el.classList.remove('blinking'), 5000);
}

function flashIgateBeacon(callsign) {
	const d = igateMarkers.find(ig => ig.callsign === callsign);
	if (!d) return;
	if (igateFlashTimers[callsign]) clearTimeout(igateFlashTimers[callsign]);
	d.el.classList.add('igate-beaconing');
	igateFlashTimers[callsign] = setTimeout(() => {
		d.el.classList.remove('igate-beaconing');
		delete igateFlashTimers[callsign];
	}, 5000);
}

function flashAidBeacon(callsign) {
	const d = aidMarkers.find(a => a.callsign === callsign);
	if (!d) return;
	if (aidFlashTimers[callsign]) clearTimeout(aidFlashTimers[callsign]);
	d.el.classList.add('igate-beaconing');
	aidFlashTimers[callsign] = setTimeout(() => {
		d.el.classList.remove('igate-beaconing');
		delete aidFlashTimers[callsign];
	}, 5000);
}

// ── Clear all selections ──────────────────────────────────────────────────
function clearAllSelections() {
	if (selectedIgateIdx >= 0) { setIgateTooltip(selectedIgateIdx, false); igateMarkers[selectedIgateIdx]?.el.classList.remove('selected'); selectedIgateIdx = -1; igateClickCount = 0; }
	if (selectedAidIdx  >= 0) { setAidTooltip(selectedAidIdx, false);     aidMarkers[selectedAidIdx]?.el.classList.remove('selected');   selectedAidIdx  = -1; aidClickCount  = 0; }
	selectedCallsign = null; trackerClickCount = 0;
	document.querySelectorAll('.legend-item, .m-legend-item').forEach(el => el.classList.remove('selected'));
	if (originMarker) { originMarker.remove(); originMarker = null; origin = null; }
	hideAllHistoryDots();
	map.closePopup();
}

// ── Place marker tooltip (shared by iGates and aid stations) ──────────────
function setPlaceTooltip(markers, idx, permanent) {
	const d = markers[idx]; if (!d) return;
	d.m.unbindTooltip();
	const label = d.callsign ? `${d.name} (${d.callsign})` : d.name;
	d.m.bindTooltip(label, { permanent, direction: 'right', className: 'place-label', offset: [8, 0] });
}
function setIgateTooltip(idx, permanent) { setPlaceTooltip(igateMarkers, idx, permanent); }
function setAidTooltip(idx, permanent)   { if (!kiosk) setPlaceTooltip(aidMarkers, idx, permanent); }

function onIgateClick(idx) {
	const d = igateMarkers[idx];
	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	if (selectedIgateIdx !== idx) {
		if (selectedIgateIdx >= 0) { setIgateTooltip(selectedIgateIdx, false); igateMarkers[selectedIgateIdx].el.classList.remove('selected'); }
		if (selectedAidIdx  >= 0) { setAidTooltip(selectedAidIdx, false); aidMarkers[selectedAidIdx].el.classList.remove('selected'); selectedAidIdx = -1; aidClickCount = 0; }
		selectedCallsign = null; trackerClickCount = 0;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		selectedIgateIdx = idx; igateClickCount = 1;
		d.el.classList.add('selected');
		setIgateTooltip(idx, true);
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (igateClickCount === 1) {
		igateClickCount = 2;
		map.setView(d.latlng, 15); setSheetOpen(false);
	} else {
		setIgateTooltip(idx, false); d.el.classList.remove('selected');
		selectedIgateIdx = -1; igateClickCount = 0;
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	}
}

// ── Aid station click cycle ────────────────────────────────────────────────
function onAidClick(idx) {
	const d = aidMarkers[idx];
	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	if (selectedAidIdx !== idx) {
		if (selectedAidIdx  >= 0) { setAidTooltip(selectedAidIdx, false); aidMarkers[selectedAidIdx].el.classList.remove('selected'); }
		if (selectedIgateIdx >= 0) { setIgateTooltip(selectedIgateIdx, false); igateMarkers[selectedIgateIdx].el.classList.remove('selected'); selectedIgateIdx = -1; igateClickCount = 0; }
		selectedCallsign = null; trackerClickCount = 0;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		selectedAidIdx = idx; aidClickCount = 1;
		d.el.classList.add('selected');
		setAidTooltip(idx, true);
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (aidClickCount === 1) {
		aidClickCount = 2;
		map.setView(d.latlng, 15); setSheetOpen(false);
	} else {
		setAidTooltip(idx, false); d.el.classList.remove('selected');
		selectedAidIdx = -1; aidClickCount = 0;
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	}
}

// ── Tracker click cycle ────────────────────────────────────────────────────
function onLegendClick(callsign) {
	if (selectedIgateIdx >= 0) { setIgateTooltip(selectedIgateIdx, false); igateMarkers[selectedIgateIdx].el.classList.remove('selected'); selectedIgateIdx = -1; igateClickCount = 0; }
	if (selectedAidIdx  >= 0) { setAidTooltip(selectedAidIdx, false);     aidMarkers[selectedAidIdx].el.classList.remove('selected');   selectedAidIdx  = -1; aidClickCount  = 0; }

	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	const legPfx = isMobile ? 'm-legend-'      : 'legend-';

	if (selectedCallsign !== callsign) {
		selectedCallsign = callsign; trackerClickCount = 1;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		document.getElementById(legPfx + callsign)?.classList.add('selected');
		triggerBlink(callsign);
		setSheetOpen(false);
		hideAllHistoryDots();
		const color = document.querySelector('#legend-' + callsign + ' .legend-dot, #m-legend-' + callsign + ' .m-dot')?.style.background || 'red';
		if (!markers[callsign]) {
			const name = document.querySelector('#legend-' + callsign + ' .legend-name, #m-legend-' + callsign + ' .m-name')?.textContent || callsign;
			showNoLocation(name);
		}
		showTrackerHistory(callsign, color);
	} else if (trackerClickCount === 1) {
		trackerClickCount = 2;
		const m = markers[callsign];
		if (m) { map.setView(m.getLatLng(), 15); setSheetOpen(false); }
	} else {
		hideAllHistoryDots();
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
		selectedCallsign = null; trackerClickCount = 0;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
	}
}

// ── Mobile tracker tap / long-press ───────────────────────────────────────
function onMobileTrackerTap(callsign) {
	if (selectedIgateIdx >= 0) { setIgateTooltip(selectedIgateIdx, false); igateMarkers[selectedIgateIdx].el.classList.remove('selected'); selectedIgateIdx = -1; igateClickCount = 0; }
	if (selectedAidIdx  >= 0) { setAidTooltip(selectedAidIdx, false);     aidMarkers[selectedAidIdx].el.classList.remove('selected');   selectedAidIdx  = -1; aidClickCount  = 0; }
	selectedCallsign = callsign; trackerClickCount = 1;
	document.querySelectorAll('.m-legend-item').forEach(el => el.classList.remove('selected'));
	document.getElementById('m-legend-' + callsign)?.classList.add('selected');
	hideAllHistoryDots();
	triggerBlink(callsign);
	const color = document.querySelector('#m-legend-' + callsign + ' .m-dot')?.style.background || 'red';
	if (!markers[callsign]) {
		const name = document.querySelector('#m-legend-' + callsign + ' .m-name')?.textContent || callsign;
		showNoLocation(name);
	}
	showTrackerHistory(callsign, color);
}

function onMobileTrackerLongPress(callsign) {
	if (originMarker) { originMarker.remove(); originMarker = null; origin = null; }
	// The drawer closes under the still-held finger; suppress the native
	// contextmenu the OS fires on the now-exposed map at its long-press threshold.
	suppressOriginUntil = Date.now() + 1500;
	onMobileTrackerTap(callsign);
	closeMobileDrawer();
	const m = markers[callsign];
	// Delay setView until the drawer slide animation (280ms) finishes so the
	// marker isn't behind the drawer when Leaflet computes the center, and
	// invalidateSize so Leaflet uses the current container dimensions.
	if (m) setTimeout(() => { map.invalidateSize(); map.setView(m.getLatLng(), 15); }, 310);
}

// ── Update legend ──────────────────────────────────────────────────────────
function updateLegend(trackers) {
	if (isMobile) updateMobileLegend(trackers);
	else          updateDesktopLegend(trackers);
}

function naturalCompare(a, b) {
	const re = /(\d+)|(\D+)/g;
	const pa = String(a).match(re) || [];
	const pb = String(b).match(re) || [];
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		if (i >= pa.length) return -1;
		if (i >= pb.length) return  1;
		if (/^\d+$/.test(pa[i]) && /^\d+$/.test(pb[i])) {
			const d = parseInt(pa[i], 10) - parseInt(pb[i], 10);
			if (d) return d;
		} else {
			const d = pa[i].localeCompare(pb[i]);
			if (d) return d;
		}
	}
	return 0;
}

function updateDesktopLegend(trackers) {
	const legend  = document.getElementById('legend');
	const current = new Set(trackers.map(t => t.callsign));
	const sorted  = [...trackers].sort((a, b) => naturalCompare(a.id, b.id));

	legend.querySelectorAll('.legend-item').forEach(el => {
		if (!current.has(el.dataset.callsign)) el.remove();
	});

	sorted.forEach(t => {
		const hasPos = t.lat !== null && t.lon !== null;
		const hasBeacon = t.lastUpdate > 0;
		let item = document.getElementById('legend-' + t.callsign);
		if (!item) {
			item = document.createElement('div');
			item.id = 'legend-' + t.callsign;
			item.dataset.callsign = t.callsign;
			item.className = 'legend-item' + (hasBeacon ? ' clickable' : '');
			item.innerHTML = `<span class="legend-dot"></span>`
			               + `<span class="legend-text"><span class="legend-id">${t.id}</span> <span class="legend-name">${t.name}</span></span>`
			               + `<span class="legend-time">${(t.color||'red')==='red'?'stale':t.time}</span>`;
			if (hasBeacon) item.addEventListener('click', () => onLegendClick(t.callsign));
		}
		const color = t.color || 'red';
		item.querySelector('.legend-dot').style.background  = color;
		item.querySelector('.legend-time').style.color      = color;
		item.querySelector('.legend-id').textContent        = t.id;
		item.querySelector('.legend-name').textContent      = t.name;
		item.querySelector('.legend-time').textContent      = color === 'red' ? 'stale' : t.time;
		if (hasBeacon && !item.classList.contains('clickable')) {
			item.classList.add('clickable');
			item.addEventListener('click', () => onLegendClick(t.callsign));
		}
		legend.appendChild(item);  // re-insert in sorted position (moves existing elements)
	});
}

function updateMobileLegend(trackers) {
	const legend  = document.getElementById('m-legend');
	const emptyEl = document.getElementById('m-legend-empty');
	const current = new Set(trackers.map(t => t.callsign));
	const sorted  = [...trackers].sort((a, b) => naturalCompare(a.id, b.id));

	legend.querySelectorAll('.m-legend-item').forEach(el => {
		if (!current.has(el.dataset.callsign)) el.remove();
	});

	sorted.forEach(t => {
		let item = document.getElementById('m-legend-' + t.callsign);
		if (!item) {
			item = document.createElement('div');
			item.id = 'm-legend-' + t.callsign;
			item.dataset.callsign = t.callsign;
			item.className = 'm-legend-item';
			item.innerHTML = `<span class="m-dot"></span><span class="m-id">${t.id}</span>`
			               + `<span class="m-name">${t.name}</span><span class="m-time">${(t.color||'red')==='red'?'stale':t.time}</span>`;
			let pressTimer = null, didLongPress = false, _lpX = 0, _lpY = 0;
			item.addEventListener('touchstart', e => {
				didLongPress = false;
				_lpX = e.touches[0].clientX; _lpY = e.touches[0].clientY;
				pressTimer = setTimeout(() => { pressTimer = null; didLongPress = true; onMobileTrackerLongPress(t.callsign); }, 500);
			}, { passive: true });
			item.addEventListener('touchend', () => {
				if (pressTimer !== null) { clearTimeout(pressTimer); pressTimer = null; }
				if (!didLongPress) onMobileTrackerTap(t.callsign);
			});
			item.addEventListener('touchcancel', () => { if (pressTimer !== null) { clearTimeout(pressTimer); pressTimer = null; } });
			item.addEventListener('touchmove', e => {
				if (pressTimer !== null) {
					const dx = e.touches[0].clientX - _lpX, dy = e.touches[0].clientY - _lpY;
					if (Math.abs(dx) > 8 || Math.abs(dy) > 8) { clearTimeout(pressTimer); pressTimer = null; didLongPress = true; }
				}
			}, { passive: true });
		}
		const color = t.color || 'red';
		item.querySelector('.m-dot').style.background  = color;
		item.querySelector('.m-dot').style.borderColor = color === 'green' ? '#1a7a1a' : (color === 'blue' ? '#0a5a9a' : '#a00');
		item.querySelector('.m-time').style.color      = color;
		item.querySelector('.m-id').textContent        = t.id;
		item.querySelector('.m-name').textContent      = t.name;
		item.querySelector('.m-time').textContent      = color === 'red' ? 'stale' : t.time;
		legend.appendChild(item);  // re-insert in sorted position (moves existing elements)
	});

	emptyEl.style.display = legend.querySelectorAll('.m-legend-item').length ? 'none' : '';
}

// ── Update map markers ────────────────────────────────────────────────────
function updateMap() {
	const headers = jsonEtag ? { 'If-None-Match': jsonEtag } : {};
	fetch('index.php?json', { headers })
		.then(r => {
			if (r.status === 304) return null;
			jsonEtag = r.headers.get('ETag');
			return r.json();
		})
		.then(data => {
			if (!data) return;
			const { default_event: defaultEvent, trackers } = data;
			// Skip tracker update if this client is no longer viewing the default event.
			// Allow through if currentEventName is not yet set (config still loading).
			if (currentEventName !== '' && defaultEvent !== currentEventName) return;
			updateLegend(trackers);

			trackers.forEach(t => {
				const prev = lastBeacons[t.callsign];
				if (prev !== undefined && t.lastUpdate !== prev) triggerBlink(t.callsign);
				lastBeacons[t.callsign] = t.lastUpdate;
			});

			const located = trackers.filter(t => t.lat !== null && t.lon !== null);
			const current = new Set(trackers.map(t => t.callsign));

			Object.keys(markers).forEach(cs => {
				if (!current.has(cs)) {
					markers[cs].remove();
					delete markers[cs];
					if (trackerPopups[cs]) { trackerPopups[cs].remove(); delete trackerPopups[cs]; }
					if (selectedCallsign === cs) { selectedCallsign = null; trackerClickCount = 0; }
				}
			});

			located.forEach(t => {
				const latlng = [t.lat, t.lon];
				const color  = t.color || 'red';
				const sz     = Math.max(4, Math.round(trackerStyle.size * markerScale()));
				const icon   = makeTrackerIcon(trackerStyle.icon, color, sz);
				if (markers[t.callsign]) {
					const oldLL = markers[t.callsign].getLatLng();
					const moved = oldLL.lat !== t.lat || oldLL.lng !== t.lon;
					markers[t.callsign]._trackerColor = color;
					markers[t.callsign].setLatLng(latlng);
					markers[t.callsign].setIcon(icon);
					if (trackerPopups[t.callsign]) trackerPopups[t.callsign].setContent(popupHtml(t));
					markers[t.callsign].setTooltipContent(kiosk ? (t.name || t.id) : t.id);
					// Selected tracker moved → redraw its breadcrumb trail and arrows
					if (moved && t.callsign === selectedCallsign) showTrackerHistory(t.callsign, color);
				} else {
					const m = L.marker(latlng, { icon, pane: 'trackerPane' }).addTo(map);
					m._trackerColor = color;
					const popup = L.popup({ closeButton: true, autoPan: isMobile, autoPanPadding: [16, 16] })
						.setContent(popupHtml(t));
					trackerPopups[t.callsign] = popup;
					if (isMobile) {
						m.on('click', function(e) {
							L.DomEvent.stopPropagation(e);
							popup.setLatLng(m.getLatLng()).openOn(map);
						});
						m.on('contextmenu', L.DomEvent.stopPropagation);
					} else if (!kiosk) {
						// Delay close so the mouse can reach interactive popup content
						let _ct = null, _listenersAdded = false;
						const cancelClose = () => clearTimeout(_ct);
						const scheduleClose = () => { _ct = setTimeout(() => map.closePopup(popup), 350); };
						m.on('mouseover', function() {
							cancelClose();
							popup.setLatLng(m.getLatLng()).openOn(map);
							if (!_listenersAdded) {
								_listenersAdded = true;
								setTimeout(() => {
									const el = popup.getElement();
									if (el) { el.addEventListener('mouseenter', cancelClose); el.addEventListener('mouseleave', scheduleClose); }
								}, 0);
							}
						});
						m.on('mouseout', scheduleClose);
					}
					m.bindTooltip(kiosk ? (t.name || t.id) : t.id, {
						permanent: true, direction: 'right',
						className: 'tracker-label', offset: [sz + 2, 0]
					});
					markers[t.callsign] = m;
				}
			});

			if (data.igate_beacons) {
				Object.entries(data.igate_beacons).forEach(([cs, ts]) => {
					if (ts && lastIgateBeacons[cs] !== undefined && ts !== lastIgateBeacons[cs]) flashIgateBeacon(cs);
					lastIgateBeacons[cs] = ts;
				});
			}
			if (data.aid_beacons) {
				Object.entries(data.aid_beacons).forEach(([cs, ts]) => {
					if (ts && lastAidBeacons[cs] !== undefined && ts !== lastAidBeacons[cs]) flashAidBeacon(cs);
					lastAidBeacons[cs] = ts;
				});
			}
		})
		.catch(err => console.error('Tracker fetch error:', err));
}

// ── Config ────────────────────────────────────────────────────────────────
function loadConfig() {
	const headers = configEtag ? { 'If-None-Match': configEtag } : {};
	fetch('index.php?config', { headers })
		.then(r => {
			if (r.status === 304) return null;
			configEtag = r.headers.get('ETag');
			return r.json();
		})
		.then(cfg => {
			if (!cfg) return;
			if (!configInitialized) {
				// First load: apply everything (only happens on a fresh page load, not from admin)
				configInitialized = true;
				applyConfig(cfg);
			} else if (!storedLocalConfig?._localTrackerEdited) {
				// Subsequent polls: only update the trackers list, nothing else
				if (cfg.trackers) applyTrackerConfig(cfg.trackers);
			}
		})
		.catch(err => console.error('Config fetch error:', err));
}

function applyConfig(cfg) {
	if (cfg.event          !== undefined) applyEvent(cfg.event);
	if (cfg.legend         !== undefined) applyLegend(cfg.legend);
	if (cfg.tracker_style  !== undefined) applyTrackerStyle(cfg.tracker_style);
	if (cfg.map)         applyMapConfig(cfg.map);
	if (cfg.trackers)    applyTrackerConfig(cfg.trackers);
	if (cfg.backgrounds) applyBackgrounds(cfg.backgrounds, cfg.background_url || '');
	if (cfg.courses)     applyCourses(cfg.courses);
	if (cfg.aidstations) applyAidStations(cfg.aidstations);
	if (cfg.igates)      applyIgates(cfg.igates);
	if (cfg.section_visibility) applySectionVisibility(cfg.section_visibility);
	if (cfg.mobile_enabled !== undefined) initMobileTracking(cfg.mobile_enabled);
}

function applyTrackerStyle(style) {
	trackerStyle.icon       = style?.icon        || 'circle';
	trackerStyle.size       = parseInt(style?.size) || 8;
	trackerStyle.labelColor = style?.label_color || '#000000';
	document.documentElement.style.setProperty('--tracker-label-color', trackerStyle.labelColor);
}

function applyLegend(text) {
	if (!legendDiv) return;
	legendDiv.innerHTML     = (kiosk && text) ? text : '';
	legendDiv.style.display = (kiosk && text) ? '' : 'none';
}

function refreshMobileAbout() {
	const el = document.getElementById('m-about-body');
	if (!el) return;
	const rows = [
		currentEventName ? { label: 'Event',   val: currentEventName } : null,
		{ label: 'Org',     val: 'Marin Amateur Radio Society' },
		{ label: 'Version', val: 'APRS Tracker Map · v1.12' },
		{ label: 'Map',     val: currentBgAttribution || '' },
		{ label: 'Credit',  val: '&copy; 2026 Doug Kaye (K6DRK)' },
	].filter(Boolean);
	el.innerHTML = `<div id="m-about-body-inner">${rows.map(r =>
		`<div class="m-about-row"><span class="m-about-label">${r.label}</span><span class="m-about-val">${r.val}</span></div>`
	).join('')}</div>`;
}

function applyEvent(name) {
	currentEventName = name || '';
	if (eventNameDiv) {
		eventNameDiv.textContent   = name || '';
		eventNameDiv.style.display = name ? 'block' : 'none';
	}
	if (isMobile) refreshMobileAbout();
}

function applyMapConfig(m) {
	const saved = (() => { try { const s = localStorage.getItem('aprs_default_view'); return s ? JSON.parse(s) : null; } catch { return null; } })();
	const d = saved || m;
	const changed = d.lat !== defaultView.lat || d.lon !== defaultView.lon || d.zoom !== defaultView.zoom;
	defaultView = { lat: d.lat, lon: d.lon, zoom: d.zoom };
	if (!mapViewInitialized || (!saved && changed)) {
		map.setView([d.lat, d.lon], d.zoom);
		mapViewInitialized = true;
	}
}

function applyTrackerConfig(trackers) {
	const pfx     = isMobile ? 'm-legend-' : 'legend-';
	const idSel   = isMobile ? '.m-id'     : '.legend-id';
	const nameSel = isMobile ? '.m-name'   : '.legend-name';

	// If any config tracker is absent from the DOM (e.g. non-default event with
	// different callsigns), rebuild the entire list with placeholder live-data fields.
	// updateDesktopLegend / updateMobileLegend handle create, update, and remove.
	if (trackers.some(t => !document.getElementById(pfx + t.callsign))) {
		const synth = trackers.map(t => ({
			callsign: t.callsign, id: t.id, name: t.name,
			color: 'red', time: '—', lastUpdate: 0, lat: null, lon: null
		}));
		if (isMobile) updateMobileLegend(synth);
		else updateDesktopLegend(synth);
		return;
	}

	trackers.forEach(t => {
		const item = document.getElementById(pfx + t.callsign);
		if (!item) return;
		item.querySelector(idSel).textContent   = t.id;
		item.querySelector(nameSel).textContent = t.name;
	});
}

function applyBackgrounds(backgrounds, backgroundUrl = '') {
	// On first call: pick the starting background.
	// User's last explicit click (LS_BG) takes priority so the choice persists
	// across kiosk ↔ normal mode switches. Falls back to the event-config URL.
	if (!backgroundsInitialized && backgrounds.length) {
		backgroundsInitialized = true;
		const stored = localStorage.getItem(LS_BG);
		const urlToUse = (stored && backgrounds.find(b => b.url === stored)) ? stored : backgroundUrl;
		if (urlToUse && urlToUse !== currentBgUrl) {
			const bg = backgrounds.find(b => b.url === urlToUse);
			if (bg) switchBackground(bg);
		}
	}

	if (isMobile) {
		const section   = document.getElementById('m-backgrounds-section');
		const container = document.getElementById('m-backgrounds-list');
		if (!backgrounds.length) { section.style.display = 'none'; return; }
		section.style.display = '';
		container.innerHTML = '';
		backgrounds.forEach(bg => {
			const row  = document.createElement('div');   row.className = 'm-layer-row';
			const name = document.createElement('span');  name.className = 'm-layer-name'; name.textContent = bg.name;
			const dot  = document.createElement('span');  dot.className = 'm-layer-check' + (bg.url === currentBgUrl ? ' checked' : '');
			row.appendChild(name); row.appendChild(dot);
			row.addEventListener('click', () => {
				localStorage.setItem(LS_BG, bg.url);
				switchBackground(bg);
				refreshMobileAbout();
				container.querySelectorAll('.m-layer-check').forEach(d => d.classList.remove('checked'));
				dot.classList.add('checked');
			});
			container.appendChild(row);
		});
		return;
	}

	if (kiosk) return;

	const section   = document.getElementById('backgrounds-section');
	const container = document.getElementById('backgrounds');
	if (!backgrounds.length) { section.style.display = 'none'; return; }
	section.style.display = '';
	container.innerHTML = '';
	backgrounds.forEach(bg => {
		const active  = bg.url === currentBgUrl;
		const item    = document.createElement('div');   item.className = 'sidebar-item';
		const nameEl  = document.createElement('span');  nameEl.textContent = bg.name; nameEl.style.flex = '1';
		const checkEl = document.createElement('input'); checkEl.type = 'checkbox'; checkEl.checked = active; checkEl.className = 'bg-checkbox';
		item.appendChild(nameEl); item.appendChild(checkEl);
		item.addEventListener('click', () => {
			localStorage.setItem(LS_BG, bg.url);
			switchBackground(bg);
			document.querySelectorAll('#backgrounds .bg-checkbox').forEach(e => { e.checked = false; });
			checkEl.checked = true;
		});
		container.appendChild(item);
	});
}

function loadCourseLayer(file) {
	const ext = file.split('.').pop().toLowerCase();
	let layer;
	if (ext === 'gpx') {
		layer = omnivore.gpx(file, null, L.geoJSON(null, { renderer: courseRenderer }));
	} else if (ext === 'kml') {
		layer = omnivore.kml(file, null, L.geoJSON(null, { renderer: courseRenderer }));
	} else if (ext === 'geojson' || ext === 'json') {
		const customLayer = L.geoJSON(null, {
			renderer: courseRenderer,
			pointToLayer(feature, latlng) {
				const p      = feature.properties || {};
				const color  = courseColors[file] || (p['marker-color'] ? '#' + p['marker-color'] : DEFAULT_COURSE_COLOR);
				const radius = Math.round((parseFloat(p['marker-size']) || 1) * 8);
				const m = L.circleMarker(latlng, {
					renderer: courseRenderer,
					radius: scaledRadius(radius), color, fillColor: color, fillOpacity: 0.85, weight: 1.5
				});
				m._baseRadius = radius;
				const label = p.title || p.name || p.description;
				if (label) m.bindTooltip(label, { direction: 'right', sticky: false });
				return m;
			}
		});
		layer = omnivore.geojson(file, null, customLayer);
	} else {
		return false;
	}
	layer.on('ready', () => {
		const c  = courseColors[file];
		const da = dashToArray(courseDashes[file]);
		const style = { weight: 3, opacity: 0.9 };
		if (c)  { style.color = c; style.fillColor = c; }
		style.dashArray = da || null;
		layer.setStyle(style);
		if (!sectionVisible.courses) layer.setStyle({ opacity: 0, fillOpacity: 0, weight: 0 });
		reorderCourseLayers();
	});
	layer.addTo(map);
	courseLayers[file] = layer;
	return true;
}

function dashToArray(dash) {
	if (dash === 'dashed')   return '10 7';
	if (dash === 'dotted')   return '2 6';
	if (dash === 'dash-dot') return '10 4 2 4';
	return null;
}

function setCourseStyle(file, color, dash) {
	if (!courseLayers[file]) return;
	const style = { color, fillColor: color, weight: 3, opacity: 0.9 };
	const da = dashToArray(dash !== undefined ? dash : courseDashes[file]);
	style.dashArray = da || null;
	courseLayers[file].setStyle(style);
}

function reorderCourseLayers() {
	for (let i = courseOrder.length - 1; i >= 0; i--) {
		const layer = courseLayers[courseOrder[i]];
		if (layer) layer.bringToFront();
	}
}

function applyCourses(courses) {
	courseOrder = courses.map(c => c.file);
	const newFiles = new Set(courseOrder);
	Object.keys(courseLayers).forEach(file => {
		if (!newFiles.has(file)) { map.removeLayer(courseLayers[file]); delete courseLayers[file]; }
	});
	// Prune localStorage entries for courses no longer in the config
	const saved = loadSavedColors();
	let pruned = false;
	Object.keys(saved).forEach(f => { if (!newFiles.has(f)) { delete saved[f]; pruned = true; } });
	if (pruned) localStorage.setItem(LS_COURSE_COLORS, JSON.stringify(saved));

	if (isMobile) {
		const container = document.getElementById('m-courses-list');
		const emptyEl   = document.getElementById('m-courses-empty');
		if (!courses.length) { container.innerHTML = ''; emptyEl.style.display = ''; return; }
		emptyEl.style.display = 'none';
		container.innerHTML = '';
		const savedColors = loadSavedColors();
		courses.forEach(course => {
			if (savedColors[course.file]) courseColors[course.file] = savedColors[course.file];
			else if (course.color) courseColors[course.file] = course.color;
			else delete courseColors[course.file];
			courseDashes[course.file] = course.dash || '';
			if (courseLayers[course.file]) setCourseStyle(course.file, courseColors[course.file] || DEFAULT_COURSE_COLOR);
			if (!coursesInitialized) loadCourseLayer(course.file);
			const color  = courseColors[course.file] || DEFAULT_COURSE_COLOR;
			const active = !!courseLayers[course.file];
			const row    = document.createElement('div');   row.className = 'm-course-row'; row.style.cursor = 'pointer';
			const nameEl = document.createElement('span');  nameEl.className = 'm-course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
			const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = active; cb.className = 'm-checkbox';
			row.addEventListener('click', e => { if (e.target !== cb) triggerCourseBlink(course.file, nameEl); });
			cb.addEventListener('click', e => e.stopPropagation());
			cb.addEventListener('change', () => {
				if (!cb.checked) { if (courseLayers[course.file]) { map.removeLayer(courseLayers[course.file]); delete courseLayers[course.file]; } }
				else { if (!courseLayers[course.file]) loadCourseLayer(course.file); else reorderCourseLayers(); }
				saveActiveFiles();
			});
			row.appendChild(nameEl); row.appendChild(cb);
			container.appendChild(row);
		});
		coursesInitialized = true;
		return;
	}

	// desktop
	const section   = document.getElementById('courses-section');
	const container = document.getElementById('courses');
	if (!courses.length) { section.style.display = 'none'; return; }
	section.style.display = '';
	container.innerHTML = '';
	const savedColors    = loadSavedColors();
	const activeFiles    = isTablet ? null : loadActiveFiles();
	const wasInitialized = coursesInitialized;
	courses.forEach(course => {
		if (savedColors[course.file]) courseColors[course.file] = savedColors[course.file];
		else if (course.color) courseColors[course.file] = course.color;
		else delete courseColors[course.file];
		courseDashes[course.file] = course.dash || '';
		if (courseLayers[course.file]) setCourseStyle(course.file, courseColors[course.file] || DEFAULT_COURSE_COLOR);
		if (!wasInitialized) {
			const shouldLoad = activeFiles === null || activeFiles.has(course.file);
			if (shouldLoad) loadCourseLayer(course.file);
		}
		const color  = courseColors[course.file] || DEFAULT_COURSE_COLOR;
		const active = !!courseLayers[course.file];
		if (kiosk) {
			if (!active) return;
			const item   = document.createElement('div');  item.className = 'sidebar-item course-item'; item.style.cursor = 'pointer';
			const nameEl = document.createElement('span'); nameEl.className = 'course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
			item.addEventListener('click', () => triggerCourseBlink(course.file, nameEl));
			item.appendChild(nameEl);
			container.appendChild(item);
			return;
		}
		const item   = document.createElement('div');   item.className = 'sidebar-item course-item'; item.style.cursor = 'pointer';
		const nameEl = document.createElement('span');  nameEl.className = 'course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
		const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = active; checkbox.className = 'course-checkbox'; checkbox.title = 'Show / hide course';
		item.addEventListener('click', e => { if (e.target !== checkbox) triggerCourseBlink(course.file, nameEl); });
		checkbox.addEventListener('click', e => e.stopPropagation());
		checkbox.addEventListener('change', () => {
			if (!checkbox.checked) { if (courseLayers[course.file]) { map.removeLayer(courseLayers[course.file]); delete courseLayers[course.file]; } }
			else { if (!courseLayers[course.file]) loadCourseLayer(course.file); else reorderCourseLayers(); }
			saveActiveFiles();
		});
		item.appendChild(nameEl); item.appendChild(checkbox);
		container.appendChild(item);
	});
	coursesInitialized = true;
	if (!wasInitialized) saveActiveFiles();
}

function applyIgates(igates) {
	const secId = isMobile ? 'm-igates-section' : 'igates-section';
	const conId = isMobile ? 'm-igates-list'    : 'igates';
	const section   = document.getElementById(secId);
	const container = document.getElementById(conId);

	igateMarkers.forEach(d => d.m.remove());
	igateMarkers = []; selectedIgateIdx = -1; igateClickCount = 0;

	if (kiosk || !igates || !igates.length) { section.style.display = 'none'; return; }
	section.style.display = '';
	container.innerHTML = '';

	igates.forEach(g => {
		const lat = parseFloat(g.lat), lon = parseFloat(g.lon);
		if (isNaN(lat) || isNaN(lon)) return;
		const latlng = [lat, lon];
		const igateBase = isMobile ? 7 : 6;
		const m = L.circleMarker(latlng, {
			pane: 'igatePane', radius: scaledRadius(igateBase), color: '#222', weight: 1.5, fillColor: '#111', fillOpacity: 0.9
		}).addTo(map);
		m._baseRadius = igateBase;
		const tipLabel = g.callsign ? `${g.name} (${g.callsign})` : g.name;
		m.bindTooltip(tipLabel, { direction: 'right', sticky: false, className: 'place-label' });
		if (isMobile) m.on('click', function(e) { L.DomEvent.stopPropagation(e); onIgateClick(igateMarkers.length); });

		let item;
		if (isMobile) {
			item = document.createElement('div'); item.className = 'm-layer-row';
			const dot  = document.createElement('span'); dot.style.cssText = 'width:10px;height:10px;border-radius:50%;background:#111;border:1px solid #555;flex-shrink:0';
			const name = document.createElement('span'); name.className = 'm-layer-name'; name.textContent = g.name;
			item.appendChild(dot); item.appendChild(name);
		} else {
			item = document.createElement('div'); item.className = 'legend-item clickable';
			item.innerHTML = `<span class="legend-dot" style="background:#111;border-color:#333"></span>`
			               + `<span class="legend-text"><span class="legend-name">${esc(g.name)}</span></span>`;
		}
		const idx = igateMarkers.length;
		igateMarkers.push({ m, name: g.name, callsign: g.callsign || '', latlng, el: item });
		item.addEventListener('click', () => onIgateClick(idx));
		container.appendChild(item);
	});

	section.style.display = igateMarkers.length ? '' : 'none';
}

function applyAidStations(stations) {
	const secId = isMobile ? 'm-aidstations-section' : 'aidstations-section';
	const conId = isMobile ? 'm-aidstations-list'    : 'aidstations';
	const section   = document.getElementById(secId);
	const container = document.getElementById(conId);

	aidMarkers.forEach(d => d.m.remove());
	aidMarkers = []; selectedAidIdx = -1; aidClickCount = 0;

	if (!stations || !stations.length) { section.style.display = 'none'; return; }
	section.style.display = '';
	container.innerHTML = '';

	stations.forEach(g => {
		const lat = parseFloat(g.lat), lon = parseFloat(g.lon);
		if (isNaN(lat) || isNaN(lon)) return;
		const latlng = [lat, lon];
		const aidBase = isMobile ? 7 : 6;
		const m = L.circleMarker(latlng, {
			pane: 'aidPane', radius: scaledRadius(aidBase), color: '#222', weight: 1.5, fillColor: '#111', fillOpacity: 0.9
		}).addTo(map);
		m._baseRadius = aidBase;
		const aidTipLabel = g.callsign ? `${g.name} (${g.callsign})` : g.name;
		m.bindTooltip(aidTipLabel, kiosk
			? { permanent: true, direction: 'right', className: 'place-label-kiosk' }
			: { direction: 'right', sticky: false, className: 'place-label' });
		if (isMobile) m.on('click', function(e) { L.DomEvent.stopPropagation(e); onAidClick(aidMarkers.length); });

		let item;
		if (isMobile) {
			item = document.createElement('div'); item.className = 'm-layer-row';
			const dot  = document.createElement('span'); dot.style.cssText = 'width:10px;height:10px;border-radius:50%;background:#111;border:1px solid #555;flex-shrink:0';
			const name = document.createElement('span'); name.className = 'm-layer-name'; name.textContent = g.name;
			item.appendChild(dot); item.appendChild(name);
		} else {
			item = document.createElement('div'); item.className = 'legend-item clickable';
			item.innerHTML = `<span class="legend-dot" style="background:#111;border-color:#333"></span>`
			               + `<span class="legend-text"><span class="legend-name">${esc(g.name)}</span></span>`;
		}
		const idx = aidMarkers.length;
		aidMarkers.push({ m, name: g.name, callsign: g.callsign || '', latlng, el: item });
		item.addEventListener('click', () => onAidClick(idx));
		container.appendChild(item);
	});

	section.style.display = aidMarkers.length ? '' : 'none';
	if (kiosk) resolveAidTooltipOverlaps();
}

function resolveAidTooltipOverlaps() {
	if (!kiosk || aidMarkers.length < 2) return;
	// Reset any previous margin adjustments so Leaflet positions are clean
	aidMarkers.forEach(d => {
		const el = d.m.getTooltip()?.getElement();
		if (el) { el.style.marginLeft = ''; el.style.marginTop = ''; }
	});
	requestAnimationFrame(() => {
		const items = aidMarkers.map(d => {
			const el = d.m.getTooltip()?.getElement();
			if (!el) return null;
			const r = el.getBoundingClientRect();
			return { el, x: r.left, y: r.top, w: r.width, h: r.height, dx: 0, dy: 0 };
		}).filter(Boolean);
		if (items.length < 2) return;
		const PAD = 4;
		for (let iter = 0; iter < 30; iter++) {
			let moved = false;
			for (let i = 0; i < items.length; i++) {
				for (let j = i + 1; j < items.length; j++) {
					const a = items[i], b = items[j];
					const ox = Math.min(a.x+a.dx+a.w, b.x+b.dx+b.w) - Math.max(a.x+a.dx, b.x+b.dx) + PAD;
					const oy = Math.min(a.y+a.dy+a.h, b.y+b.dy+b.h) - Math.max(a.y+a.dy, b.y+b.dy) + PAD;
					if (ox > 0 && oy > 0) {
						moved = true;
						if (oy <= ox) {
							const push = oy / 2;
							if (a.y+a.dy < b.y+b.dy) { a.dy -= push; b.dy += push; }
							else                       { a.dy += push; b.dy -= push; }
						} else {
							const push = ox / 2;
							if (a.x+a.dx < b.x+b.dx) { a.dx -= push; b.dx += push; }
							else                       { a.dx += push; b.dx -= push; }
						}
					}
				}
			}
			if (!moved) break;
		}
		items.forEach(({ el, dx, dy }) => {
			el.style.marginLeft = dx ? `${Math.round(dx)}px` : '';
			el.style.marginTop  = dy ? `${Math.round(dy)}px` : '';
		});
	});
}

map.on('zoomend', resolveAidTooltipOverlaps);

map.on('moveend', function() {
	const c = map.getCenter();
	localStorage.setItem('aprs_map_view', JSON.stringify({
		lat:  parseFloat(c.lat.toFixed(6)),
		lon:  parseFloat(c.lng.toFixed(6)),
		zoom: map.getZoom()
	}));
});

map.on('zoomend', function() {
	// Rescale tracker markers
	Object.values(markers).forEach(m => {
		if (m._trackerColor !== undefined) {
			const sz = Math.max(4, Math.round(trackerStyle.size * markerScale()));
			m.setIcon(makeTrackerIcon(trackerStyle.icon, m._trackerColor, sz));
		}
	});
	// Rescale igate, aid station, course, and history dots
	[...igateMarkers, ...aidMarkers].forEach(d => {
		if (d.m._baseRadius !== undefined) d.m.setRadius(scaledRadius(d.m._baseRadius));
	});
	Object.values(courseLayers).forEach(layer => {
		layer.eachLayer(sub => {
			if (sub._baseRadius !== undefined) sub.setRadius(scaledRadius(sub._baseRadius));
		});
	});
	Object.values(historyDots).forEach(dots => {
		dots.forEach(d => {
			if (d._baseRadius !== undefined) d.setRadius(scaledRadius(d._baseRadius));
		});
	});
});

// ── Sidebar resizer ────────────────────────────────────────────────────────
const LS_SIDEBAR_WIDTH = 'aprs_sidebar_width';
if (!isMobile) {
	const _sidebar  = document.getElementById('sidebar');
	const _resizer  = document.getElementById('sidebar-resizer');
	const _savedW   = parseInt(localStorage.getItem(LS_SIDEBAR_WIDTH));
	if (_savedW >= 100 && _savedW <= 600) {
		_sidebar.style.width    = _savedW + 'px';
		_sidebar.style.minWidth = _savedW + 'px';
	}
	let _dragging = false, _startX = 0, _startW = 0;
	_resizer.addEventListener('mousedown', e => {
		_dragging = true;
		_startX   = e.clientX;
		_startW   = _sidebar.offsetWidth;
		document.body.classList.add('sidebar-resizing');
		e.preventDefault();
	});
	document.addEventListener('mousemove', e => {
		if (!_dragging) return;
		const w = Math.max(100, Math.min(600, _startW + e.clientX - _startX));
		_sidebar.style.width    = w + 'px';
		_sidebar.style.minWidth = w + 'px';
		map.invalidateSize({ animate: false, pan: false });
	});
	document.addEventListener('mouseup', () => {
		if (!_dragging) return;
		_dragging = false;
		document.body.classList.remove('sidebar-resizing');
		localStorage.setItem(LS_SIDEBAR_WIDTH, _sidebar.offsetWidth);
	});
}

// ── Map interactions ───────────────────────────────────────────────────────
map.on('contextmenu', function(e) {
	if (Date.now() < suppressOriginUntil) return;
	if (e.originalEvent?.target?.closest('.tracker-marker, .tracker-label')) return;
	origin = e.latlng;
	if (originMarker) {
		originMarker.setLatLng(e.latlng);
	} else {
		originMarker = L.circleMarker(e.latlng, {
			radius: isMobile ? 8 : 7, color: '#c0392b', weight: 2.5,
			fillColor: '#e74c3c', fillOpacity: 0.25
		}).addTo(map);
		if (isMobile) {
			originMarker.on('click', function(ev) {
				L.DomEvent.stopPropagation(ev);
				showOriginOverlay();
			});
		} else {
			originMarker.on('mouseover', showOriginOverlay);
			originMarker.on('mouseout',  hideOriginOverlayDelayed);
		}
	}
	if (isMobile && navigator.vibrate) navigator.vibrate(40);
});

map.on('click', function(e) {
	if (!origin) return;
	const dist = haversineDistance(origin.lat, origin.lng, e.latlng.lat, e.latlng.lng);
	const brng = bearingTo(origin.lat, origin.lng, e.latlng.lat, e.latlng.lng);
	const content = isMobile
		? `<div class="dist-popup-inner"><div class="dp-dist">${dist.toFixed(1)} mi</div><div class="dp-bearing">${Math.round(brng)}&deg; ${compassDir(brng)}</div></div>`
		: `<div class="coord-popup-inner">${dist.toFixed(1)} mi &middot; ${Math.round(brng)}&deg; ${compassDir(brng)}</div>`;
	L.popup({ closeButton: false, className: 'coord-popup', offset: [0, isMobile ? -4 : 0] })
		.setLatLng(e.latlng).setContent(content).openOn(map);
});

// ── Save Map button ────────────────────────────────────────────────────────
document.getElementById('save-map-btn').addEventListener('click', function() {
	const c = map.getCenter();
	const v = { lat: parseFloat(c.lat.toFixed(6)), lon: parseFloat(c.lng.toFixed(6)), zoom: map.getZoom(), event: currentEventName };
	localStorage.setItem('aprs_default_view', JSON.stringify(v));
	defaultView = v;
	const btn = this;
	btn.textContent = 'Map Saved ✓';
	setTimeout(() => { btn.textContent = 'Save Map'; }, 2000);
});

// ── Reset buttons ──────────────────────────────────────────────────────────
document.getElementById('reset-btn').addEventListener('click', function() {
	clearAllSelections();
	map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
});

document.addEventListener('keydown', function(e) {
	if (e.key !== 'Escape') return;
	if (e.target.matches('input, textarea, select')) return;
	clearAllSelections();
	map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
});

if (isMobile) {
	document.getElementById('m-reset-btn').addEventListener('click', () => {
		clearAllSelections();
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
		closeMobileDrawer();
	});
	document.getElementById('m-save-map-btn').addEventListener('click', function() {
		const c = map.getCenter();
		const v = { lat: parseFloat(c.lat.toFixed(6)), lon: parseFloat(c.lng.toFixed(6)), zoom: map.getZoom(), event: currentEventName };
		localStorage.setItem('aprs_default_view', JSON.stringify(v));
		defaultView = v;
		this.textContent = 'Saved ✓';
		setTimeout(() => { this.textContent = 'Save Map'; }, 2000);
	});
}

// ── About modal ───────────────────────────────────────────────────────────
function openAboutModal() {
	const body = document.getElementById('about-body');
	const attrText = currentBgAttribution || '';
	const rows = [
		{ label: 'Organization', val: 'Marin Amateur Radio Society' },
		{ label: 'Application',  val: 'APRS Tracker Map · v1.12' },
		currentEventName ? { label: 'Event', val: currentEventName } : null,
		{ label: 'Map Data',     val: attrText },
		{ label: 'Copyright',    val: '&copy; 2026 Doug Kaye (K6DRK). All Rights Reserved.' },
	].filter(Boolean);
	body.innerHTML = rows.map(r => `<div class="about-row"><div class="about-label">${r.label}</div><div class="about-val">${r.val}</div></div>`).join('');
	document.getElementById('about-modal').style.display = 'flex';
}
function closeAboutModal() { document.getElementById('about-modal').style.display = 'none'; }
document.getElementById('about-close').addEventListener('click', closeAboutModal);
document.getElementById('about-backdrop').addEventListener('click', closeAboutModal);

// Q_LABELS, formatAprsPath: loaded from utils.js
if (document.getElementById('about-btn'))  document.getElementById('about-btn').addEventListener('click', openAboutModal);

// ── bfcache reload ─────────────────────────────────────────────────────────
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

// ── Clients modal ────────────────────────────────────────────────────────────
function openConnModal() {
	document.getElementById('conn-client').textContent = clientIp;
	document.getElementById('conn-server').textContent = serverIp;
	document.getElementById('conn-modal').style.display = 'flex';
}
function closeConnModal() {
	document.getElementById('conn-modal').style.display = 'none';
}
document.getElementById('conn-close').addEventListener('click', closeConnModal);
document.getElementById('conn-backdrop').addEventListener('click', closeConnModal);

function openClientsModal() {
	document.getElementById('clients-modal').style.display = 'flex';
	fetchClients();
}
function closeClientsModal() {
	document.getElementById('clients-modal').style.display = 'none';
}
document.getElementById('clients-close').addEventListener('click', closeClientsModal);
document.getElementById('clients-backdrop').addEventListener('click', closeClientsModal);

async function fetchClients() {
	const body = document.getElementById('clients-body');
	body.innerHTML = 'Loading&hellip;';
	try {
		const r = await fetch('index.php?clientstatus');
		const d = await r.json();
		const fmtRps = d.rps !== null ? d.rps.toFixed(3) : '–';
		let html = '';
		html += `<div class="clients-stat"><span class="clients-stat-label">Active workers</span><span class="clients-stat-value">${d.busy ?? '–'}</span></div>`;
		html += `<div class="clients-stat"><span class="clients-stat-label">Idle workers</span><span class="clients-stat-value">${d.idle ?? '–'}</span></div>`;
		html += `<div class="clients-stat"><span class="clients-stat-label">Requests / sec</span><span class="clients-stat-value">${fmtRps}</span></div>`;
		if (d.uptime) html += `<div class="clients-stat"><span class="clients-stat-label">Server uptime</span><span class="clients-stat-value">${d.uptime}</span></div>`;
		html += `<div class="clients-ip-list">`;
		html += `<div class="clients-ip-title">TCP connections &mdash; ${d.total} total</div>`;
		const entries = Object.entries(d.clients || {});
		if (entries.length) {
			entries.forEach(([ip, n]) => {
				html += `<div class="clients-ip-row"><span>${ip}</span><span>${n} conn</span></div>`;
			});
		} else {
			html += `<div class="clients-none">None</div>`;
		}
		html += `</div>`;
		body.innerHTML = html;
	} catch(e) {
		body.innerHTML = '<span style="color:#c00">Failed to fetch status.</span>';
	}
}

// ── Tablet sidebar toggle ─────────────────────────────────────────────────
// Use getComputedStyle to detect tablet mode from CSS — avoids matchMedia timing
// issues on iOS Safari reload (same pattern as mobile gear button detection).
{
	const sidebarEl = document.getElementById('sidebar');
	const toggleBtn = document.getElementById('sidebar-toggle-btn');
	if (toggleBtn && getComputedStyle(toggleBtn).display !== 'none') {
		sidebarEl.style.display = 'none';
		setTimeout(() => map.invalidateSize(), 0);
		toggleBtn.addEventListener('click', () => {
			const hidden = sidebarEl.style.display === 'none';
			sidebarEl.style.display = hidden ? '' : 'none';
			toggleBtn.classList.toggle('sidebar-shown', hidden);
			setTimeout(() => map.invalidateSize(), 50);
		});
	}
}

// ── Sidebar section visibility ────────────────────────────────────────────
const sectionVisible = { trackers: true, courses: true, aidstations: true, igates: true, backgrounds: true };

function setSectionVisible(section, visible) {
	sectionVisible[section] = visible;
	switch (section) {
		case 'trackers':
			map.getPane('trackerPane').style.display = visible ? '' : 'none';
			map._container.classList.toggle('aprs-hide-trackers', !visible);
			break;
		case 'aidstations':
			map.getPane('aidPane').style.display = visible ? '' : 'none';
			break;
		case 'igates':
			map.getPane('igatePane').style.display = visible ? '' : 'none';
			break;
		case 'courses':
			Object.entries(courseLayers).forEach(([file, layer]) => {
				if (visible) {
					const c = courseColors[file] || DEFAULT_COURSE_COLOR;
					layer.setStyle({ color: c, fillColor: c, weight: 3, opacity: 0.9, fillOpacity: 0.85 });
				} else {
					layer.setStyle({ opacity: 0, fillOpacity: 0, weight: 0 });
				}
			});
			break;
		case 'backgrounds':
			currentTileLayer.setOpacity(visible ? 1 : 0);
			break;
	}
	const SECTION_BODIES = {
		trackers:    ['legend',      'm-trackers-body'],
		courses:     ['courses',     'm-courses-body'],
		aidstations: ['aidstations', 'm-aidstations-body'],
		igates:      ['igates',      'm-igates-body'],
		backgrounds: ['backgrounds', 'm-backgrounds-body'],
	};
	(SECTION_BODIES[section] || []).forEach(id => {
		const el = document.getElementById(id);
		if (el) el.classList.toggle('section-dimmed', !visible);
	});
}

// ── Mobile location sharing ────────────────────────────────────────────────
let mobileToken    = null;
let mobileId       = null;
let mobileWatcher  = null;
let mobileWakeLock = null;

function initMobileTracking(enabled) {
	const btnDesk  = document.getElementById('share-loc-btn');
	const btnRow   = document.getElementById('share-loc-row');
	const btnMob   = document.getElementById('m-share-loc-btn');
	if (!enabled || !navigator.geolocation) return;
	if (btnRow)  btnRow.style.display  = '';
	if (btnMob)  btnMob.style.display  = '';
	// Resume from previous session
	try {
		const saved = JSON.parse(localStorage.getItem('aprs_mobile_tracker') || 'null');
		if (saved && saved.token && saved.id) { mobileToken = saved.token; mobileId = saved.id; setShareLocBtnState('tracking'); startMobileGeolocation(); acquireWakeLock(); startDimTimer(); }
	} catch {}
	if (btnDesk) btnDesk.addEventListener('click', onShareLocClick);
	if (btnMob)  btnMob.addEventListener('click',  onShareLocClick);
}

function onShareLocClick() {
	if (mobileToken) { stopMobileTracking(); } else { openMobileJoinModal(); }
}

function setShareLocBtnState(state) {
	const label  = state === 'tracking' ? 'Stop Sharing' : 'Share Location';
	const btnD   = document.getElementById('share-loc-btn');
	const btnM   = document.getElementById('m-share-loc-btn');
	if (btnD) btnD.textContent = label;
	if (btnM) btnM.textContent = label;
}

function openMobileJoinModal() {
	document.getElementById('mjoin-name').value  = '';
	document.getElementById('mjoin-pin').value   = '';
	document.getElementById('mjoin-error').textContent = '';
	document.getElementById('mjoin-submit').disabled    = false;
	document.getElementById('mjoin-submit').textContent = 'Share Location';
	document.getElementById('mobile-join-modal').style.display = 'flex';
	document.getElementById('mjoin-name').focus();
}

function closeMobileJoinModal() {
	document.getElementById('mobile-join-modal').style.display = 'none';
}

function showMobileAlert(title, message) {
	document.getElementById('malert-title').textContent   = title;
	document.getElementById('malert-message').textContent = message;
	document.getElementById('mobile-alert-modal').style.display = 'flex';
}

function closeMobileAlert() {
	document.getElementById('mobile-alert-modal').style.display = 'none';
}

document.getElementById('mjoin-close').addEventListener('click', closeMobileJoinModal);
document.getElementById('mjoin-backdrop').addEventListener('click', closeMobileJoinModal);
document.getElementById('malert-ok').addEventListener('click', closeMobileAlert);
document.getElementById('malert-backdrop').addEventListener('click', closeMobileAlert);
document.getElementById('mjoin-submit').addEventListener('click', submitMobileJoin);
document.getElementById('mjoin-name').addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('mjoin-pin').focus(); });
document.getElementById('mjoin-pin').addEventListener('keydown',  e => { if (e.key === 'Enter') submitMobileJoin(); });

function submitMobileJoin() {
	const name = document.getElementById('mjoin-name').value.trim();
	const pin  = document.getElementById('mjoin-pin').value;
	const errEl = document.getElementById('mjoin-error');
	const btn   = document.getElementById('mjoin-submit');
	if (!name) { errEl.textContent = 'Please enter your name.'; return; }
	btn.disabled = true;
	errEl.textContent = '';
	fetch('index.php?mobile=join', {
		method: 'POST', headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ name, pin })
	})
	.then(r => r.json().then(d => ({ ok: r.ok, d })))
	.then(({ ok, d }) => {
		if (!ok) {
			errEl.textContent = d.error === 'Incorrect PIN'
				? 'Incorrect PIN. Please try again.'
				: (d.error || 'Could not connect. Please try again.');
			btn.disabled = false;
			return;
		}
		mobileToken = d.token; mobileId = d.id;
			try { localStorage.setItem('aprs_mobile_tracker', JSON.stringify({ token: d.token, id: d.id })); } catch {}
		closeMobileJoinModal();
		setShareLocBtnState('tracking');
		startMobileGeolocation();
		acquireWakeLock();
		startDimTimer();
	})
	.catch(() => { errEl.textContent = 'Network error. Try again.'; btn.disabled = false; });
}

let _mobileLastPos = null;
let _mobileInterval = null;

async function acquireWakeLock() {
	if (!('wakeLock' in navigator)) return;
	try {
		mobileWakeLock = await navigator.wakeLock.request('screen');
		// iOS/Android can release it automatically when the tab is hidden; re-acquire on visibility restore
		mobileWakeLock.addEventListener('release', () => {
			mobileWakeLock = null;
			if (mobileToken) acquireWakeLock();
		});
	} catch {}
}

function releaseWakeLock() {
	if (mobileWakeLock) { mobileWakeLock.release().catch(() => {}); mobileWakeLock = null; }
}

// ── Screen dim (battery saver while Wake Lock is held) ────────────────────
const _dimOverlay  = document.getElementById('screen-dim');
const _DIM_DELAY   = 30000;
let   _dimTimer    = null;
let   _dimActive   = false;

function _resetDimTimer() {
	if (!mobileToken) return;              // only active while sharing
	clearTimeout(_dimTimer);
	if (_dimActive) _undim();
	_dimTimer = setTimeout(_dim, _DIM_DELAY);
}

function _dim() {
	_dimActive = true;
	_dimOverlay.classList.add('active');
}

function _undim() {
	_dimActive = false;
	_dimOverlay.classList.remove('active');
}

function startDimTimer() {
	['touchstart','touchmove','mousedown','mousemove','keydown'].forEach(ev =>
		document.addEventListener(ev, _resetDimTimer, { passive: true }));
	_dimOverlay.addEventListener('touchstart', e => { e.preventDefault(); _resetDimTimer(); }, { passive: false });
	_dimOverlay.addEventListener('mousedown',  e => { e.preventDefault(); _resetDimTimer(); });
	_resetDimTimer();
}

function stopDimTimer() {
	clearTimeout(_dimTimer); _dimTimer = null;
	_undim();
}

function startMobileGeolocation() {
	if (mobileWatcher !== null) return;
	let sentFirst = false;
	function sendUpdate() {
		if (!mobileToken || !_mobileLastPos) return;
		fetch('index.php?mobile=update', {
			method: 'POST', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ token: mobileToken, lat: _mobileLastPos.coords.latitude, lon: _mobileLastPos.coords.longitude })
		}).then(r => r.json().then(d => ({ status: r.status, d })))
		  .then(({ status, d }) => {
			if (status === 404) {
				clearMobileState();
				showMobileAlert('Session Ended', d.error === 'Token not found'
					? 'Your location sharing session has ended. Tap Share Location to rejoin.'
					: (d.error || 'Your session is no longer active.'));
			} else if (status === 403) {
				clearMobileState();
				showMobileAlert('Access Denied', 'Your location sharing session was ended by the administrator.');
			}
		  }).catch(() => {});
	}
	// watchPosition keeps GPS active; send immediately on first fix, then every 60s
	mobileWatcher = navigator.geolocation.watchPosition(
		pos => {
			_mobileLastPos = pos;
			if (!sentFirst) { sentFirst = true; sendUpdate(); }
		},
		() => {},
		{ enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 }
	);
	_mobileInterval = setInterval(sendUpdate, 60000);
}

function stopMobileTracking() {
	if (mobileToken) {
		fetch('index.php?mobile=leave', {
			method: 'POST', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ token: mobileToken })
		}).catch(() => {});
	}
	clearMobileState();
}

function clearMobileState() {
	if (mobileWatcher !== null) { navigator.geolocation.clearWatch(mobileWatcher); mobileWatcher = null; }
	if (_mobileInterval !== null) { clearInterval(_mobileInterval); _mobileInterval = null; }
	_mobileLastPos = null;
	mobileToken = null; mobileId = null;
	releaseWakeLock();
	stopDimTimer();
	try { localStorage.removeItem('aprs_mobile_tracker'); } catch {}
	setShareLocBtnState('idle');
}

function applySectionVisibility(sv) {
	if (kiosk) return;
	['trackers','courses','aidstations','igates','backgrounds'].forEach(section => {
		if (sv[section] === false) {
			document.querySelectorAll(`.sec-vis-cb[data-section="${section}"]`).forEach(cb => { cb.checked = false; });
			setSectionVisible(section, false);
		}
	});
}

// Visibility checkboxes — desktop and mobile; stopPropagation keeps header-click from toggling collapse
document.querySelectorAll('.sec-vis-cb').forEach(cb => {
	cb.addEventListener('click', e => e.stopPropagation());
	if (!kiosk) cb.addEventListener('change', () => setSectionVisible(cb.dataset.section, cb.checked));
});

// Desktop section collapse (localStorage-persisted)
const LS_SIDEBAR_STATE = 'aprs_sidebar_state';
if (!isMobile) {
	let colState = {};
	try { colState = JSON.parse(localStorage.getItem(LS_SIDEBAR_STATE) || '{}'); } catch {}
	document.querySelectorAll('#sidebar .sec-hdr[data-body]').forEach(hdr => {
		const bodyId = hdr.dataset.body;
		const body   = document.getElementById(bodyId);
		if (!body) return;
		if (colState[bodyId] === false) {
			hdr.classList.remove('open');
			body.style.display = 'none';
		}
		hdr.addEventListener('click', e => {
			if (e.target.classList.contains('sec-vis-cb')) return;
			const isOpen = hdr.classList.contains('open');
			hdr.classList.toggle('open', !isOpen);
			body.style.display = isOpen ? 'none' : '';
			try {
				const s = JSON.parse(localStorage.getItem(LS_SIDEBAR_STATE) || '{}');
				s[bodyId] = !isOpen;
				localStorage.setItem(LS_SIDEBAR_STATE, JSON.stringify(s));
			} catch {}
		});
	});
}

// ── Init ───────────────────────────────────────────────────────────────────
// On first load or reload: use symlink default and clear local event.
// On navigation from admin: use locally stored event.
let hasStoredEvent    = false;
let isNonDefaultEvent = false;
let configInitialized = false;
let storedLocalConfig = null; // config saved from admin; used to suppress tracker poll overwrites
const isReload = performance.getEntriesByType('navigation')[0]?.type === 'reload';
const fromSameHost = document.referrer && new URL(document.referrer).host === location.host;
const fromAdmin = !isReload && fromSameHost;
if (!fromAdmin) {
	localStorage.removeItem('aprs_current_event');
} else {
	try {
		const stored = localStorage.getItem('aprs_current_event');
		if (stored) {
			const { name, config, isDefault } = JSON.parse(stored);
			if (config) {
				storedLocalConfig = config;
				applyConfig(config);
				configInitialized = true;
				hasStoredEvent    = true;
				isNonDefaultEvent = !isDefault;
			}
		}
	} catch (e) {}
}

if (isNonDefaultEvent) {
	if (!document.getElementById('aprs-blink-style')) {
		const s = document.createElement('style');
		s.id = 'aprs-blink-style';
		s.textContent = '@keyframes aprs-blink{0%,100%{color:#000}50%{color:#fff}}';
		document.head.appendChild(s);
	}
	const note = document.createElement('span');
	note.id = 'non-default-note';
	note.textContent = 'Not the active event. Tracker data are not being updated. ';
	note.style.cssText = 'font-size:11px;color:#000;white-space:nowrap;animation:aprs-blink 0.5s step-start 10';
	setTimeout(() => { note.style.animation = 'none'; note.style.color = '#000'; }, 5000);
	// Insert into Leaflet attribution bar, to the left of existing text
	const attrEl = document.querySelector('.leaflet-control-attribution');
	if (attrEl) attrEl.insertBefore(note, attrEl.firstChild);
}

// ── Full-screen toggle ────────────────────────────────────────────────────────
const toggleFullScreen = () => {
	if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
	else document.exitFullscreen();
};

// Full Screen button in the mobile drawer — non-iOS mobile only.
// iOS doesn't support requestFullscreen() in Safari; standalone PWA is already
// fullscreen. The Add to Home Screen nudge handles the iOS fullscreen path.
if (isMobile && !_isIos) {
	const mFsBtn = document.getElementById('m-fs-btn');
	mFsBtn.style.display = '';
	mFsBtn.onclick = toggleFullScreen;
	document.addEventListener('fullscreenchange', () => {
		mFsBtn.textContent = document.fullscreenElement ? 'Exit Full Screen' : 'Full Screen';
	});
}

// CrOS-only: sidebar fs button, auto-overlay on touch, context menu suppression
if (/CrOS/.test(navigator.userAgent)) {
	document.getElementById('fs-sidebar-row').style.display = '';
	const fsSidebarBtn = document.getElementById('fs-btn');
	fsSidebarBtn.onclick = toggleFullScreen;
	document.addEventListener('fullscreenchange', () => {
		fsSidebarBtn.textContent = document.fullscreenElement ? 'Exit Full Screen' : 'Full Screen';
	});

	// Auto full-screen overlay: CrOS touch only (not laptops)
	if (!document.fullscreenElement && isMobile) {
		const fsOverlay = document.createElement('div');
		fsOverlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer';
		fsOverlay.innerHTML = '<div style="color:#fff;font-size:22px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,sans-serif;text-align:center;pointer-events:none">Tap to enter full screen</div>';
		document.body.appendChild(fsOverlay);
		const enterFs = e => {
			e.preventDefault();
			document.documentElement.requestFullscreen().catch(() => {});
			fsOverlay.remove();
		};
		fsOverlay.addEventListener('touchend', enterFs, { once: true, passive: false });
		fsOverlay.addEventListener('click',    enterFs, { once: true });
	}

	document.addEventListener('contextmenu', e => e.preventDefault());
	const crosStyle = document.createElement('style');
	crosStyle.textContent = '#map, #map * { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }';
	document.head.appendChild(crosStyle);
}

// Reset Map button — all mobile devices (must live in Leaflet container and be
// pinned via visualViewport; position:fixed on body anchors to the layout
// viewport and vanishes behind Safari's toolbars)
if (isMobile) {
	const resetBtn = document.createElement('button');
	resetBtn.title = 'Reset Map';
	resetBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
	resetBtn.style.cssText = 'position:absolute;right:auto;z-index:1400;width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.95);color:#555;border:1px solid #ccc;border-radius:6px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.18);touch-action:manipulation;pointer-events:auto;';
	map.getContainer().appendChild(resetBtn);
	const pinReset = () => {
		const vv = window.visualViewport;
		if (!vv) return;
		const w   = resetBtn.offsetWidth || 36;
		const sat = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--aprs-sat')) || 0;
		resetBtn.style.left = (vv.offsetLeft + vv.width - w - 10) + 'px';
		resetBtn.style.top  = Math.max(vv.offsetTop + 54, sat + 54) + 'px';
	};
	if (window.visualViewport) {
		visualViewport.addEventListener('resize', pinReset);
		visualViewport.addEventListener('scroll', pinReset);
		pinReset();
		setTimeout(pinReset, 300);
	}
	resetBtn.addEventListener('touchend', e => { e.stopPropagation(); clearAllSelections(); map.setView([defaultView.lat, defaultView.lon], defaultView.zoom); });
	resetBtn.addEventListener('click',    e => { e.stopPropagation(); clearAllSelections(); map.setView([defaultView.lat, defaultView.lon], defaultView.zoom); });
}

// Always poll for live tracker data; skip config polling only when previewing a non-default event
updateMap();
setInterval(updateMap, 5000);
if (!isNonDefaultEvent) {
	loadConfig();
	setInterval(loadConfig, 5000);
}
</script>
<div id="no-loc-toast"></div>
</body>
</html>
