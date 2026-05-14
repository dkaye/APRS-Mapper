<?php
/**
 * APRS Tracker Map
 *
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
	$etag = '"' . filemtime($trackerStatusFilename) . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	$fh = fopen($trackerStatusFilename, 'r');
	if (!$fh) { http_response_code(500); exit; }
	flock($fh, LOCK_SH);
	$contents = stream_get_contents($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	header('Content-Type: application/json');
	echo $contents;
	exit;
}

if (isset($_GET['history'])) {
	$cfgReal  = realpath('config.yaml');
	$histPath = $cfgReal ? dirname($cfgReal) . '/tracker_history.yaml' : null;
	$etag     = '"' . ($histPath && file_exists($histPath) ? filemtime($histPath) : '0') . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	header('Content-Type: application/json');
	$result   = [];
	if ($histPath && file_exists($histPath)) {
		$fh = fopen($histPath, 'r');
		if ($fh && flock($fh, LOCK_SH)) {
			$lines = [];
			while (($line = fgets($fh)) !== false) $lines[] = rtrim($line);
			flock($fh, LOCK_UN);
			fclose($fh);
			$cs = null; $entry = null;
			foreach ($lines as $line) {
				if (!$line || $line[0] === '#') continue;
				if (preg_match('/^([A-Z0-9][\w\-]+):$/', $line, $m)) {
					$cs = $m[1]; $result[$cs] = [];
				} elseif (preg_match('/^  - lat:\s*([\-\d.]+)/', $line, $m)) {
					$entry = ['lat' => (float)$m[1], 'lon' => 0.0, 'ts' => 0];
				} elseif (preg_match('/^    lon:\s*([\-\d.]+)/', $line, $m) && $entry !== null) {
					$entry['lon'] = (float)$m[1];
				} elseif (preg_match('/^    ts:\s*(\d+)/', $line, $m) && $entry !== null) {
					$entry['ts'] = (int)$m[1];
					if ($cs !== null) $result[$cs][] = $entry;
					$entry = null;
				}
			}
		}
		foreach ($result as &$entries) $entries = array_slice($entries, 0, 5);
		unset($entries);
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
	$courses = array_values(array_filter($cfg['courses'] ?? [],
	               fn($c) => isset($c['file']) && file_exists(__DIR__ . '/' . $c['file'])));
	header('Content-Type: application/json');
	echo json_encode([
		'event'          => $cfg['event'] ?? '',
		'legend'         => $cfg['legend'] ?? '',
		'tracker_style'  => $cfg['tracker_style'] ?? [],
		'map'            => $cfg['map'],
		'trackers'       => $cfg['trackers'],
		'backgrounds'    => $cfg['backgrounds'],
		'courses'        => $courses,
		'aidstations'    => $cfg['aidstations'] ?? [],
		'igates'         => $cfg['igates'] ?? [],
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

require_once 'config_parse.php';
$_m    = parseConfigYaml('config.yaml')['map'] ?? [];
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
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>MARS APRS Map</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-omnivore@0.3.4/leaflet-omnivore.min.js"></script>
<style>
/* ── Reset & shared ──────────────────────────────────────────────────────── */
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { width: 100%; height: 100%; overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', arial, sans-serif; }

@keyframes blink-anim { 50% { opacity: 0; } }
.blinking { animation: blink-anim 0.4s steps(2,end) infinite; }
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
    background: none; border: none; box-shadow: none;
    font-weight: bold; font-size: 12px; white-space: nowrap;
    color: #000;
}
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

#sidebar {
    width: 160px; min-width: 160px; height: 100vh;
    overflow-y: auto; background: #f4f4f4;
    border-right: 1px solid #ccc; padding: 10px 8px;
}
.section-heading {
    font-size: 13px; color: #555; text-transform: uppercase;
    letter-spacing: 0.05em; margin-bottom: 3px;
    display: flex; justify-content: space-between; align-items: center;
}
.section-heading.top   { margin-top: 0; }
.section-collapsed { display: none !important; }
.section-heading.below { margin-top: 8px; }
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
.legend-time { font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }

.sidebar-item {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px; padding: 4px 0 4px 4px; border-radius: 4px;
    cursor: pointer; margin-bottom: 2px; color: #333; position: relative;
}
.sidebar-item:hover { background: #e0e0e0; }
.course-item  { cursor: default; padding: 2px 0 2px 4px; margin-bottom: 1px; }
.course-label { flex: 1; display: flex; align-items: center; cursor: pointer; min-width: 0; }
.course-name  { flex: 1; }
.course-name:hover { text-decoration: underline; }
.course-color-input { position: absolute; width: 0; height: 0; border: none; padding: 0; overflow: hidden; }
.course-checkbox, .bg-checkbox, .section-toggle {
    appearance: none; -webkit-appearance: none;
    width: 14px; height: 14px; flex-shrink: 0; margin: 0;
    border: 1.5px solid #aaa; border-radius: 2px; background: #fff;
}
.course-checkbox { cursor: pointer; }
.bg-checkbox     { pointer-events: none; }
.section-toggle  { cursor: pointer; }
.course-checkbox:checked,
.bg-checkbox:checked,
.section-toggle:checked {
    border-color: #aaa;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23aaa' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: center; background-size: 10px;
}

#reset-btn {
    display: block; width: 100%; margin-top: 14px; padding: 6px 0;
    background: #e8e8e8; border: 1px solid #bbb; border-radius: 4px;
    font-size: 12px; color: #444; cursor: pointer; text-align: center;
}
#reset-btn:hover { background: #d8d8d8; }
#admin-btn {
    display: block; width: 100%; margin-top: 6px; padding: 6px 0;
    background: #e8e8e8; border: 1px solid #bbb; border-radius: 4px;
    font-size: 12px; color: #555; cursor: pointer; text-align: center; text-decoration: none;
}
#admin-btn:hover { background: #d8d8d8; }
#kiosk-btn {
    display: block; width: 100%; margin-top: 6px; padding: 6px 0;
    background: #e8e8e8; border: 1px solid #bbb; border-radius: 4px;
    font-size: 12px; color: #555; cursor: pointer; text-align: center; text-decoration: none;
}
#kiosk-btn:hover { background: #d8d8d8; }

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
#top-bar, #bottom-sheet { display: none; }

/* ── Mobile layout: portrait phones (≤767px) OR landscape phones (≤500px tall) ── */
@media (max-width: 767px), (max-height: 500px) and (orientation: landscape) {
    body  { display: block; }
    #map  { position: fixed; inset: 0; z-index: 0; }
    #sidebar { display: none; }

    .leaflet-top                  { top:    50px; }
    .leaflet-bottom.leaflet-right { bottom: 68px; }
    .leaflet-bottom.leaflet-left  { bottom: 68px; }

    /* top bar */
    #top-bar {
        display: flex; position: fixed; top: 0; left: 0; right: 0; z-index: 300;
        height: 44px; background: rgba(28,40,51,0.92);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        align-items: center; justify-content: space-between;
        padding: 0 14px; color: #fff;
    }
    #top-bar-title { font-size: 15px; font-weight: 600; letter-spacing: .02em; }
    #top-event {
        font-size: 12px; color: #8aafc8; max-width: 55vw;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #reset-map-btn {
        background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22);
        color: #fff; border-radius: 6px; padding: 5px 11px;
        font-size: 12px; font-family: inherit; cursor: pointer;
    }
    #reset-map-btn:active { background: rgba(255,255,255,0.22); }

    /* bottom sheet */
    #bottom-sheet {
        display: flex; flex-direction: column;
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
        height: 68vh; background: #fff;
        border-radius: 16px 16px 0 0;
        box-shadow: 0 -3px 20px rgba(0,0,0,.18);
        transform: translateY(calc(100% - 60px));
        transition: transform .32s cubic-bezier(.4,0,.2,1);
        will-change: transform;
    }
    #bottom-sheet.open { transform: translateY(0); }

    #sheet-handle {
        flex-shrink: 0; height: 20px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; touch-action: none;
    }
    #handle-pill { width: 36px; height: 4px; background: #ccc; border-radius: 2px; }

    #sheet-tabs {
        flex-shrink: 0; display: flex;
        border-bottom: 1px solid #eee; height: 40px;
    }
    .m-tab {
        flex: 1; background: none; border: none;
        font-size: 13px; font-family: inherit; color: #888;
        cursor: pointer; border-bottom: 2px solid transparent;
        transition: color .15s, border-color .15s;
    }
    .m-tab.active { color: #2980b9; border-bottom-color: #2980b9; font-weight: 600; }

    #sheet-body { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* tracker rows */
    .m-legend-item {
        display: flex; align-items: center; gap: 12px;
        padding: 0 16px; min-height: 48px;
        border-bottom: 1px solid #f2f2f2;
        cursor: pointer; user-select: none;
    }
    .m-legend-item:active   { background: #f0f6ff; }
    .m-legend-item.selected { background: #e8f2ff; }
    .m-dot  { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid #333; flex-shrink: 0; }
    .m-id   { font-size: 14px; min-width: 28px; }
    .m-name { font-size: 14px; flex: 1; color: #222; }
    .m-time { font-size: 12px; color: #888; white-space: nowrap; font-variant-numeric: tabular-nums; }

    /* course rows */
    .m-course-row {
        display: flex; align-items: center; gap: 10px;
        padding: 0 16px; min-height: 48px; border-bottom: 1px solid #f2f2f2;
    }
    .m-course-label { flex: 1; display: flex; align-items: center; cursor: pointer; font-size: 14px; }
    .m-course-name  { flex: 1; }
    .m-course-name:hover { text-decoration: underline; }
    .m-course-color-input { position: absolute; width: 0; height: 0; border: none; padding: 0; overflow: hidden; }
    .m-checkbox {
        appearance: none; -webkit-appearance: none;
        width: 20px; height: 20px; flex-shrink: 0;
        border: 1.5px solid #aaa; border-radius: 4px; background: #fff; cursor: pointer;
    }
    .m-checkbox:checked {
        border-color: #222;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23000' stroke-width='2.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: center; background-size: 14px;
    }

    /* layers tab */
    .m-section-title {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: #999; padding: 12px 16px 4px;
    }
    .m-layer-row {
        display: flex; align-items: center; gap: 12px;
        padding: 0 16px; min-height: 48px;
        border-bottom: 1px solid #f2f2f2; cursor: pointer;
    }
    .m-layer-row:active   { background: #f0f6ff; }
    .m-layer-row.selected { background: #e8f2ff; }
    .m-layer-name  { flex: 1; font-size: 14px; color: #222; }
    .m-layer-check {
        appearance: none; -webkit-appearance: none;
        width: 20px; height: 20px; flex-shrink: 0;
        border: 1.5px solid #aaa; border-radius: 50%; background: #fff; pointer-events: none;
    }
    .m-layer-check.checked {
        border-color: #2980b9; background: #2980b9;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23fff' stroke-width='2.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: center; background-size: 14px;
    }

    /* distance popup — stacked layout on mobile */
    .coord-popup .leaflet-popup-content-wrapper { border-radius: 8px; }
    .dist-popup-inner { padding: 8px 14px; font-size: 14px; font-family: inherit; text-align: center; }
    .dist-popup-inner .dp-dist    { font-size: 18px; font-weight: 700; color: #222; }
    .dist-popup-inner .dp-bearing { font-size: 13px; color: #666; margin-top: 2px; }

    .m-empty { padding: 20px 16px; font-size: 13px; color: #aaa; text-align: center; }

    /* sheet-content wraps tabs + body; needed for landscape re-layout */
    #sheet-content { display: flex; flex-direction: column; flex: 1; overflow: hidden; min-width: 0; }
}

/* ── Mobile landscape: right-side panel instead of bottom sheet ────────── */
@media (max-height: 500px) and (orientation: landscape) {
    /* Shrink top bar to reclaim vertical space */
    #top-bar { height: 36px; padding: 0 10px; }
    #top-bar-title { font-size: 13px; }
    .leaflet-top { top: 40px; }
    .leaflet-bottom.leaflet-right { bottom: 4px; }
    .leaflet-bottom.leaflet-left  { bottom: 4px; }

    /* Side panel: slides in from the right */
    #bottom-sheet {
        top: 36px; bottom: 0;
        right: 0; left: auto; width: 270px;
        height: auto;
        border-radius: 12px 0 0 12px;
        /* Collapsed: 34px tab sticks out as a grab strip */
        transform: translateX(calc(100% - 34px));
        flex-direction: row;
    }
    #bottom-sheet.open { transform: translateX(0); }

    /* Handle becomes a vertical strip on the left edge of the panel */
    #sheet-handle {
        width: 34px; height: auto; flex-shrink: 0;
        flex-direction: column;
        border-right: 1px solid #eee;
        border-radius: 12px 0 0 12px;
    }
    #handle-pill { width: 4px; height: 36px; }

    /* Tabs sit at top of the content area, body scrolls below */
    #sheet-tabs { height: 40px; flex-shrink: 0; }
    #sheet-body { flex: 1; overflow-y: auto; }
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
</style>
</head>
<body>

<!-- ── Desktop sidebar ─────────────────────────────────────────────────── -->
<div id="sidebar">
	<div class="section-heading top"><span>Trackers</span><input type="checkbox" class="section-toggle" id="toggle-trackers" checked></div>
	<div id="legend"></div>

	<div id="courses-section" style="display:none">
		<hr class="section-divider">
		<div class="section-heading"><span>Courses</span><input type="checkbox" class="section-toggle" id="toggle-courses" checked></div>
		<div id="courses"></div>
	</div>

	<div id="aidstations-section" style="display:none">
		<hr class="section-divider">
		<div class="section-heading"><span>Aid Stations</span><input type="checkbox" class="section-toggle" id="toggle-aidstations" checked></div>
		<div id="aidstations"></div>
	</div>

	<div id="igates-section" style="display:none">
		<hr class="section-divider">
		<div class="section-heading"><span>iGates</span><input type="checkbox" class="section-toggle" id="toggle-igates" checked></div>
		<div id="igates"></div>
	</div>

	<div id="backgrounds-section" style="display:none">
		<hr class="section-divider">
		<div class="section-heading"><span>Backgrounds</span><input type="checkbox" class="section-toggle" id="toggle-backgrounds" checked></div>
		<div id="backgrounds"></div>
	</div>

	<div id="sidebar-footer">
		<hr class="section-divider">
		<button id="reset-btn">Reset Map</button>
		<a href="admin/" id="admin-btn">Admin</a>
		<a href="?kiosk=1" id="kiosk-btn">Kiosk Mode</a>
	</div>
</div>

<!-- ── Shared map ──────────────────────────────────────────────────────── -->
<div id="map"></div>

<!-- ── Mobile top bar ─────────────────────────────────────────────────── -->
<div id="top-bar">
	<div>
		<div id="top-bar-title">MARS APRS Map</div>
		<div id="top-event" style="display:none"></div>
	</div>
	<button id="reset-map-btn">Reset</button>
</div>

<!-- ── Mobile bottom sheet ────────────────────────────────────────────── -->
<div id="bottom-sheet">
	<div id="sheet-handle"><div id="handle-pill"></div></div>
	<div id="sheet-content">
	<div id="sheet-tabs">
		<button class="m-tab active" data-tab="trackers">Trackers</button>
		<button class="m-tab" data-tab="courses">Courses</button>
		<button class="m-tab" data-tab="layers">Layers</button>
	</div>
	<div id="sheet-body">
		<div id="tab-trackers" class="tab-pane active">
			<div id="m-legend"></div>
			<div id="m-legend-empty" class="m-empty" style="display:none">Waiting for tracker data…</div>
		</div>
		<div id="tab-courses" class="tab-pane">
			<div id="m-courses-list"></div>
			<div id="m-courses-empty" class="m-empty" style="display:none">No courses configured.</div>
		</div>
		<div id="tab-layers" class="tab-pane">
			<div id="m-aidstations-section" style="display:none">
				<div class="m-section-title">Aid Stations</div>
				<div id="m-aidstations-list"></div>
			</div>
			<div id="m-igates-section" style="display:none">
				<div class="m-section-title">iGates</div>
				<div id="m-igates-list"></div>
			</div>
			<div id="m-backgrounds-section" style="display:none">
				<div class="m-section-title">Map Style</div>
				<div id="m-backgrounds-list"></div>
			</div>
		</div>
	</div>
	</div><!-- #sheet-content -->
</div><!-- #bottom-sheet -->

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

<script>
'use strict';

const isMobile = window.matchMedia('(max-width: 767px)').matches ||
    (window.matchMedia('(orientation: landscape)').matches && window.innerHeight <= 500);

// ── Map init ──────────────────────────────────────────────────────────────
let defaultView = { lat: <?= $_lat ?>, lon: <?= $_lon ?>, zoom: <?= $_zoom ?> };
const clientIp = '<?= htmlspecialchars($_clientIp) ?>';
const serverIp = '<?= htmlspecialchars($_serverIp) ?>';
let mapViewInitialized = true;

const map = L.map('map', { zoomControl: !isMobile })
	.setView([defaultView.lat, defaultView.lon], defaultView.zoom);

if (isMobile) L.control.zoom({ position: 'topright' }).addTo(map);
if (!isMobile) L.control.scale({ position: 'bottomright', imperial: true, metric: false }).addTo(map);

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
			txt.innerHTML = '&ensp;Marin Amateur Radio Society APRS Tracking v1.6 beta &copy; 2026 Doug Kaye (K6DRK)';
		} else {
			if (!isMobile) {
				const exitBtn2 = L.DomUtil.create('button', 'kiosk-footer-btn', d);
				exitBtn2.textContent = 'Exit';
				L.DomEvent.on(exitBtn2, 'click', () => fetch('http://localhost:8080/exit').catch(() => {}));
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
				? 'MARS APRS v1.6 beta &copy; 2026 Doug Kaye (K6DRK)'
				: '&ensp;Marin Amateur Radio Society APRS Tracking v1.6 beta &copy; 2026 Doug Kaye (K6DRK)';
			if (isMobile) d.style.fontSize = '10px';
		}
		return d;
	}
}))({ position: isMobile ? 'bottomright' : 'bottomleft' }).addTo(map);

let eventNameDiv;
if (!isMobile) {
	new (L.Control.extend({
		onAdd() {
			eventNameDiv = L.DomUtil.create('div', '');
			eventNameDiv.style.cssText = 'font-size:13px;font-family:arial,helvetica,sans-serif;color:#000;padding:0 5px 2px;display:none';
			return eventNameDiv;
		}
	}))({ position: 'bottomleft' }).addTo(map);
}

let legendDiv;
if (!isMobile) {
	new (L.Control.extend({
		onAdd() {
			legendDiv = L.DomUtil.create('div', '');
			legendDiv.style.cssText = 'font-size:13px;font-family:arial,helvetica,sans-serif;color:#000;padding:6px 10px;display:none;white-space:pre-line;line-height:1.5;pointer-events:none;max-width:260px;border:1px solid #000;background:rgba(255,255,255,0.15)';
			return legendDiv;
		}
	}))({ position: 'bottomleft' }).addTo(map);
}

let currentBgUrl     = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
let currentTileLayer = L.tileLayer(currentBgUrl, {
	attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
	maxZoom: 19
}).addTo(map);

if (kiosk) {
	document.getElementById('backgrounds-section').style.display = 'none';
	document.getElementById('sidebar-footer').style.display = 'none';
	document.querySelectorAll('.section-toggle').forEach(el => el.style.display = 'none');
	if (localStorage.getItem('aprs_kiosk_sidebar') === '0')
		document.getElementById('sidebar').style.display = 'none';
	document.documentElement.requestFullscreen().catch(() => {});
}

// ── State ─────────────────────────────────────────────────────────────────
const markers              = {};
const trackerPopups        = {};
let   trackerStyle         = { icon: 'circle', size: 8, labelColor: '#000000' };
const courseLayers         = {};
const courseColors         = {};
let   courseOrder          = [];
const lastBeacons          = {};
const blinkTimers          = {};
const historyDots          = {};	// callsign → [L.circleMarker, ...]
const DEFAULT_COURSE_COLOR = '#2196f3';
const LS_COURSE_COLORS     = 'aprs_course_colors';
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
let configEtag         = null;
let jsonEtag           = null;
let historyEtag        = null;
let coursesInitialized = false;

// ── Mobile bottom sheet ───────────────────────────────────────────────────
const sheet  = document.getElementById('bottom-sheet');
const handle = document.getElementById('sheet-handle');
let sheetOpen = false;

function setSheetOpen(open) {
	if (!isMobile) return;
	sheetOpen = open;
	sheet.classList.toggle('open', open);
}

if (isMobile) {
	handle.addEventListener('click', () => setSheetOpen(!sheetOpen));
	map.on('dragstart zoomstart', () => setSheetOpen(false));

	let touchStartX = null, touchStartY = null;
	handle.addEventListener('touchstart', e => {
		touchStartX = e.touches[0].clientX;
		touchStartY = e.touches[0].clientY;
	}, { passive: true });
	handle.addEventListener('touchend', e => {
		if (touchStartY === null) return;
		const landscape = window.innerWidth > window.innerHeight;
		if (landscape) {
			const dx = e.changedTouches[0].clientX - touchStartX;
			if (Math.abs(dx) > 20) setSheetOpen(dx < 0); // swipe left = open
		} else {
			const dy = touchStartY - e.changedTouches[0].clientY;
			if (Math.abs(dy) > 20) setSheetOpen(dy > 0); // swipe up = open
		}
		touchStartX = null; touchStartY = null;
	});

	document.querySelectorAll('.m-tab').forEach(btn => {
		btn.addEventListener('click', () => {
			document.querySelectorAll('.m-tab').forEach(b => b.classList.remove('active'));
			document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
			btn.classList.add('active');
			document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
			setSheetOpen(true);
		});
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
	return `<b>${t.name}</b> (${t.id})<br>${t.callsign}<br>Last heard ${t.time} ago`;
}

function relativeTime(ts) {
	const s = Math.floor(Date.now() / 1000) - ts;
	if (s < 10)  return 'just now';
	if (s < 60)  return s + 's ago';
	const m = Math.floor(s / 60), r = s % 60;
	if (m < 60)  return m + 'm ' + r + 's ago';
	const h = Math.floor(m / 60);
	return h + 'h ' + (m % 60) + 'm ago';
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
	const r = Math.max(3, Math.round(trackerStyle.size * 0.42));
	const headers = historyEtag ? { 'If-None-Match': historyEtag } : {};
	fetch('index.php?history', { headers })
		.then(res => {
			if (res.status === 304) return null;
			historyEtag = res.headers.get('ETag');
			return res.json();
		})
		.then(hist => {
			if (!hist) return;
			const entries = hist[callsign] || [];
			if (!entries.length) return;

			const layers = [];

			// Dots — newest first in entries array
			entries.forEach(e => {
				const dot = L.circleMarker([e.lat, e.lon], {
					radius: r, color: color, fillColor: color,
					fillOpacity: 0.5, weight: 1.5, pane: 'trackerPane'
				});
				dot.bindTooltip(relativeTime(e.ts), { sticky: false, direction: 'top' });
				dot.on('mouseover', function() { dot.setTooltipContent(relativeTime(e.ts)); });
				dot.addTo(map);
				layers.push(dot);
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

function haversineDistance(lat1, lng1, lat2, lng2) {
	const R = 3958.8, r = Math.PI / 180;
	const dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
	const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r) * Math.cos(lat2*r) * Math.sin(dLng/2)**2;
	return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function bearingTo(lat1, lng1, lat2, lng2) {
	const r = Math.PI / 180, dLng = (lng2 - lng1) * r;
	const y = Math.sin(dLng) * Math.cos(lat2 * r);
	const x = Math.cos(lat1*r) * Math.sin(lat2*r) - Math.sin(lat1*r) * Math.cos(lat2*r) * Math.cos(dLng);
	return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

function compassDir(deg) {
	return ['N','NNE','NE','ENE','E','ESE','SE','SSE',
	        'S','SSW','SW','WSW','W','WNW','NW','NNW'][Math.round(deg / 22.5) % 16];
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

function triggerDotBlink(d) {
	const el = d.m.getElement();
	if (el) { el.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; setTimeout(() => { el.style.animation = ''; }, 5000); }
	d.el.classList.add('blinking');
	setTimeout(() => d.el.classList.remove('blinking'), 5000);
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

// ── iGate click cycle ──────────────────────────────────────────────────────
function setIgateTooltip(idx, permanent) {
	const d = igateMarkers[idx]; if (!d) return;
	d.m.unbindTooltip();
	d.m.bindTooltip(d.name, { permanent, direction: 'right', className: 'place-label', offset: [8, 0] });
}

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
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (igateClickCount === 1) {
		igateClickCount = 2; setIgateTooltip(idx, true);
		map.setView(d.latlng, 15); setSheetOpen(false);
	} else {
		setIgateTooltip(idx, false); d.el.classList.remove('selected');
		selectedIgateIdx = -1; igateClickCount = 0;
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	}
}

// ── Aid station click cycle ────────────────────────────────────────────────
function setAidTooltip(idx, permanent) {
	const d = aidMarkers[idx]; if (!d) return;
	d.m.unbindTooltip();
	d.m.bindTooltip(d.name, { permanent, direction: 'right', className: 'place-label', offset: [8, 0] });
}

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
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (aidClickCount === 1) {
		aidClickCount = 2; setAidTooltip(idx, true);
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

// ── Update legend ──────────────────────────────────────────────────────────
function updateLegend(trackers) {
	if (isMobile) updateMobileLegend(trackers);
	else          updateDesktopLegend(trackers);
}

function updateDesktopLegend(trackers) {
	const legend  = document.getElementById('legend');
	const current = new Set(trackers.map(t => t.callsign));
	const sorted  = [...trackers].sort((a, b) => a.id.localeCompare(b.id));

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
			               + `<span class="legend-time">${t.time}</span>`;
			if (hasBeacon) item.addEventListener('click', () => onLegendClick(t.callsign));
			legend.appendChild(item);
		}
		const color = t.color || 'red';
		item.querySelector('.legend-dot').style.background  = color;
		item.querySelector('.legend-time').style.color      = color;
		item.querySelector('.legend-id').textContent        = t.id;
		item.querySelector('.legend-name').textContent      = t.name;
		item.querySelector('.legend-time').textContent      = t.time;
		if (hasBeacon && !item.classList.contains('clickable')) {
			item.classList.add('clickable');
			item.addEventListener('click', () => onLegendClick(t.callsign));
		}
	});
}

function updateMobileLegend(trackers) {
	const legend  = document.getElementById('m-legend');
	const emptyEl = document.getElementById('m-legend-empty');
	const current = new Set(trackers.map(t => t.callsign));
	const sorted  = [...trackers].sort((a, b) => a.id.localeCompare(b.id));

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
			               + `<span class="m-name">${t.name}</span><span class="m-time">${t.time}</span>`;
			item.addEventListener('click', () => onLegendClick(t.callsign));
			legend.appendChild(item);
		}
		const color = t.color || 'red';
		item.querySelector('.m-dot').style.background  = color;
		item.querySelector('.m-dot').style.borderColor = color === 'green' ? '#1a7a1a' : (color === 'blue' ? '#0a5a9a' : '#a00');
		item.querySelector('.m-time').style.color      = color;
		item.querySelector('.m-id').textContent        = t.id;
		item.querySelector('.m-name').textContent      = t.name;
		item.querySelector('.m-time').textContent      = t.time;
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
		.then(trackers => {
			if (!trackers) return;
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
				const sz     = trackerStyle.size;
				const icon   = makeTrackerIcon(trackerStyle.icon, color, sz);
				if (markers[t.callsign]) {
					markers[t.callsign].setLatLng(latlng);
					markers[t.callsign].setIcon(icon);
					if (trackerPopups[t.callsign]) trackerPopups[t.callsign].setContent(popupHtml(t));
					markers[t.callsign].setTooltipContent(kiosk ? (t.name || t.id) : t.id);
				} else {
					const m = L.marker(latlng, { icon, pane: 'trackerPane' }).addTo(map);
					const popup = L.popup({ closeButton: isMobile, autoPan: false })
						.setContent(popupHtml(t));
					trackerPopups[t.callsign] = popup;
					if (isMobile) {
						m.on('click', function(e) {
							L.DomEvent.stopPropagation(e);
							popup.setLatLng(m.getLatLng()).openOn(map);
						});
					} else if (!kiosk) {
						m.on('mouseover', function() { popup.setLatLng(m.getLatLng()).openOn(map); });
						m.on('mouseout',  function() { map.closePopup(popup); });
					}
					m.bindTooltip(kiosk ? (t.name || t.id) : t.id, {
						permanent: true, direction: 'right',
						className: 'tracker-label', offset: [sz + 2, 0]
					});
					markers[t.callsign] = m;
				}
			});
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
		.then(cfg => { if (cfg) applyConfig(cfg); })
		.catch(err => console.error('Config fetch error:', err));
}

function applyConfig(cfg) {
	if (cfg.event          !== undefined) applyEvent(cfg.event);
	if (cfg.legend         !== undefined) applyLegend(cfg.legend);
	if (cfg.tracker_style  !== undefined) applyTrackerStyle(cfg.tracker_style);
	if (cfg.map)         applyMapConfig(cfg.map);
	if (cfg.trackers)    applyTrackerConfig(cfg.trackers);
	if (cfg.backgrounds) applyBackgrounds(cfg.backgrounds);
	if (cfg.courses)     applyCourses(cfg.courses);
	if (cfg.aidstations) applyAidStations(cfg.aidstations);
	if (cfg.igates)      applyIgates(cfg.igates);
}

function applyTrackerStyle(style) {
	trackerStyle.icon       = style?.icon        || 'circle';
	trackerStyle.size       = parseInt(style?.size) || 8;
	trackerStyle.labelColor = style?.label_color || '#000000';
	document.documentElement.style.setProperty('--tracker-label-color', trackerStyle.labelColor);
}

function applyLegend(text) {
	if (!legendDiv) return;
	legendDiv.textContent   = (kiosk && text) ? text : '';
	legendDiv.style.display = (kiosk && text) ? '' : 'none';
}

function applyEvent(name) {
	if (isMobile) {
		const el = document.getElementById('top-event');
		el.textContent   = name || '';
		el.style.display = name ? '' : 'none';
		document.getElementById('top-bar-title').style.display = name ? 'none' : '';
	} else if (eventNameDiv) {
		eventNameDiv.textContent   = name || '';
		eventNameDiv.style.display = name ? '' : 'none';
	}
}

function applyMapConfig(m) {
	const changed = m.lat !== defaultView.lat || m.lon !== defaultView.lon || m.zoom !== defaultView.zoom;
	defaultView = { lat: m.lat, lon: m.lon, zoom: m.zoom };
	if (!mapViewInitialized || changed) {
		map.setView([m.lat, m.lon], m.zoom);
		mapViewInitialized = true;
	}
}

function applyTrackerConfig(trackers) {
	const pfx     = isMobile ? 'm-legend-' : 'legend-';
	const idSel   = isMobile ? '.m-id'     : '.legend-id';
	const nameSel = isMobile ? '.m-name'   : '.legend-name';
	trackers.forEach(t => {
		const item = document.getElementById(pfx + t.callsign);
		if (!item) return;
		item.querySelector(idSel).textContent   = t.id;
		item.querySelector(nameSel).textContent = t.name;
	});
}

function applyBackgrounds(backgrounds) {
	// On first call (any mode), restore the previously selected background from localStorage.
	if (!backgroundsInitialized && backgrounds.length) {
		backgroundsInitialized = true;
		const savedUrl = localStorage.getItem(LS_BG);
		if (savedUrl) {
			const bg = backgrounds.find(b => b.url === savedUrl);
			if (bg && bg.url !== currentBgUrl) {
				map.removeLayer(currentTileLayer);
				currentBgUrl = bg.url;
				currentTileLayer = L.tileLayer(bg.url, { attribution: bg.attribution, maxZoom: 19 }).addTo(map);
				Object.values(courseLayers).forEach(l => l.bringToFront());
			}
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
				currentBgUrl = bg.url;
				localStorage.setItem(LS_BG, bg.url);
				map.removeLayer(currentTileLayer);
				currentTileLayer = L.tileLayer(bg.url, { attribution: bg.attribution, maxZoom: 19 }).addTo(map);
				Object.values(courseLayers).forEach(l => l.bringToFront());
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
			currentBgUrl = bg.url;
			localStorage.setItem(LS_BG, bg.url);
			map.removeLayer(currentTileLayer);
			currentTileLayer = L.tileLayer(bg.url, { attribution: bg.attribution, maxZoom: 19 }).addTo(map);
			Object.values(courseLayers).forEach(l => l.bringToFront());
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
		layer = omnivore.gpx(file);
	} else if (ext === 'kml') {
		layer = omnivore.kml(file);
	} else if (ext === 'geojson' || ext === 'json') {
		const customLayer = L.geoJSON(null, {
			pointToLayer(feature, latlng) {
				const p      = feature.properties || {};
				const color  = courseColors[file] || (p['marker-color'] ? '#' + p['marker-color'] : DEFAULT_COURSE_COLOR);
				const radius = Math.round((parseFloat(p['marker-size']) || 1) * 8);
				const m = L.circleMarker(latlng, {
					radius, color, fillColor: color, fillOpacity: 0.85, weight: 1.5
				});
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
		const c = courseColors[file];
		if (c) layer.setStyle({ color: c, fillColor: c, weight: 3, opacity: 0.9 });
		reorderCourseLayers();
	});
	layer.addTo(map);
	courseLayers[file] = layer;
	return true;
}

function setCourseStyle(file, color) {
	if (courseLayers[file]) courseLayers[file].setStyle({ color, fillColor: color, weight: 3, opacity: 0.9 });
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
			if (courseLayers[course.file]) setCourseStyle(course.file, courseColors[course.file] || DEFAULT_COURSE_COLOR);
			if (!coursesInitialized) loadCourseLayer(course.file);
			const color  = courseColors[course.file] || DEFAULT_COURSE_COLOR;
			const active = !!courseLayers[course.file];
			const row    = document.createElement('div');   row.className = 'm-course-row';
			const label  = document.createElement('label'); label.className = 'm-course-label'; label.title = 'Tap to change colour';
			const nameEl = document.createElement('span');  nameEl.className = 'm-course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
			const cInput = document.createElement('input'); cInput.type = 'color'; cInput.value = color; cInput.className = 'm-course-color-input';
			cInput.addEventListener('input', e => {
				const c = e.target.value;
				nameEl.style.color = c;
				courseColors[course.file] = c;
				saveCourseColor(course.file, c);
				setCourseStyle(course.file, c);
			});
			label.appendChild(nameEl); label.appendChild(cInput);
			const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = active; cb.className = 'm-checkbox';
			cb.addEventListener('click', e => e.stopPropagation());
			cb.addEventListener('change', () => {
				if (!cb.checked) { if (courseLayers[course.file]) { map.removeLayer(courseLayers[course.file]); delete courseLayers[course.file]; } }
				else { if (!courseLayers[course.file]) loadCourseLayer(course.file); else reorderCourseLayers(); }
				saveActiveFiles();
			});
			row.appendChild(label); row.appendChild(cb);
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
	const activeFiles    = loadActiveFiles();
	const wasInitialized = coursesInitialized;
	courses.forEach(course => {
		if (savedColors[course.file]) courseColors[course.file] = savedColors[course.file];
		else if (course.color) courseColors[course.file] = course.color;
		else delete courseColors[course.file];
		if (courseLayers[course.file]) setCourseStyle(course.file, courseColors[course.file] || DEFAULT_COURSE_COLOR);
		if (!wasInitialized) {
			const shouldLoad = activeFiles === null || activeFiles.has(course.file);
			if (shouldLoad) loadCourseLayer(course.file);
		}
		const color  = courseColors[course.file] || DEFAULT_COURSE_COLOR;
		const active = !!courseLayers[course.file];
		if (kiosk) {
			if (!active) return;
			const item   = document.createElement('div');  item.className = 'sidebar-item course-item';
			const nameEl = document.createElement('span'); nameEl.className = 'course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
			item.appendChild(nameEl);
			container.appendChild(item);
			return;
		}
		const item   = document.createElement('div');   item.className = 'sidebar-item course-item';
		const label  = document.createElement('label'); label.className = 'course-label'; label.title = 'Click to change color';
		const nameEl = document.createElement('span');  nameEl.className = 'course-name'; nameEl.textContent = course.name; nameEl.style.color = color;
		const cInput = document.createElement('input'); cInput.type = 'color'; cInput.value = color; cInput.className = 'course-color-input';
		cInput.addEventListener('input', e => {
			const c = e.target.value;
			nameEl.style.color = c;
			courseColors[course.file] = c;
			saveCourseColor(course.file, c);
			setCourseStyle(course.file, c);
		});
		label.appendChild(nameEl); label.appendChild(cInput);
		const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = active; checkbox.className = 'course-checkbox'; checkbox.title = 'Show / hide course';
		checkbox.addEventListener('click', e => e.stopPropagation());
		checkbox.addEventListener('change', () => {
			if (!checkbox.checked) { if (courseLayers[course.file]) { map.removeLayer(courseLayers[course.file]); delete courseLayers[course.file]; } }
			else { if (!courseLayers[course.file]) loadCourseLayer(course.file); else reorderCourseLayers(); }
			saveActiveFiles();
		});
		item.appendChild(label); item.appendChild(checkbox);
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
		const m = L.circleMarker(latlng, {
			pane: 'igatePane', radius: isMobile ? 7 : 6, color: '#222', weight: 1.5, fillColor: '#111', fillOpacity: 0.9
		}).addTo(map);
		m.bindTooltip(g.name, { permanent: false, direction: 'right', className: 'place-label', offset: [8, 0] });

		let item;
		if (isMobile) {
			item = document.createElement('div'); item.className = 'm-layer-row';
			const dot  = document.createElement('span'); dot.style.cssText = 'width:10px;height:10px;border-radius:50%;background:#111;border:1px solid #555;flex-shrink:0';
			const name = document.createElement('span'); name.className = 'm-layer-name'; name.textContent = g.name;
			item.appendChild(dot); item.appendChild(name);
		} else {
			item = document.createElement('div'); item.className = 'legend-item clickable';
			item.innerHTML = `<span class="legend-dot" style="background:#111;border-color:#333"></span>`
			               + `<span class="legend-text"><span class="legend-name">${g.name}</span></span>`;
		}
		const idx = igateMarkers.length;
		igateMarkers.push({ m, name: g.name, latlng, el: item });
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
		const m = L.circleMarker(latlng, {
			pane: 'aidPane', radius: isMobile ? 7 : 6, color: '#222', weight: 1.5, fillColor: '#111', fillOpacity: 0.9
		}).addTo(map);
		m.bindTooltip(g.name, { permanent: kiosk, direction: 'right', className: 'place-label', offset: [8, 0] });

		let item;
		if (isMobile) {
			item = document.createElement('div'); item.className = 'm-layer-row';
			const dot  = document.createElement('span'); dot.style.cssText = 'width:10px;height:10px;border-radius:50%;background:#111;border:1px solid #555;flex-shrink:0';
			const name = document.createElement('span'); name.className = 'm-layer-name'; name.textContent = g.name;
			item.appendChild(dot); item.appendChild(name);
		} else {
			item = document.createElement('div'); item.className = 'legend-item clickable';
			item.innerHTML = `<span class="legend-dot" style="background:#111;border-color:#333"></span>`
			               + `<span class="legend-text"><span class="legend-name">${g.name}</span></span>`;
		}
		const idx = aidMarkers.length;
		aidMarkers.push({ m, name: g.name, latlng, el: item });
		item.addEventListener('click', () => onAidClick(idx));
		container.appendChild(item);
	});

	section.style.display = aidMarkers.length ? '' : 'none';
}

map.on('moveend', function() {
	const c = map.getCenter();
	localStorage.setItem('aprs_map_view', JSON.stringify({
		lat:  parseFloat(c.lat.toFixed(6)),
		lon:  parseFloat(c.lng.toFixed(6)),
		zoom: map.getZoom()
	}));
});

// ── Map interactions ───────────────────────────────────────────────────────
map.on('contextmenu', function(e) {
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

// ── Reset buttons ──────────────────────────────────────────────────────────
document.getElementById('reset-btn').addEventListener('click', function() {
	clearAllSelections();
	map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
});

document.getElementById('reset-map-btn').addEventListener('click', function() {
	clearAllSelections();
	map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	setSheetOpen(false);
});

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

// ── Sidebar section toggles ───────────────────────────────────────────────
const LS_SIDEBAR_STATE = 'aprs_sidebar_state';
(function initSectionToggles() {
	const sections = [
		{ id: 'toggle-trackers',    content: 'legend' },
		{ id: 'toggle-courses',     content: 'courses' },
		{ id: 'toggle-aidstations', content: 'aidstations' },
		{ id: 'toggle-igates',      content: 'igates' },
		{ id: 'toggle-backgrounds', content: 'backgrounds' },
	];
	let state = {};
	try { state = JSON.parse(localStorage.getItem(LS_SIDEBAR_STATE) || '{}'); } catch {}
	sections.forEach(({ id, content }) => {
		const cb = document.getElementById(id);
		const ct = document.getElementById(content);
		if (!cb || !ct) return;
		if (state[id] === false) { cb.checked = false; ct.classList.add('section-collapsed'); }
		cb.addEventListener('change', () => {
			ct.classList.toggle('section-collapsed', !cb.checked);
			try {
				const s = JSON.parse(localStorage.getItem(LS_SIDEBAR_STATE) || '{}');
				s[id] = cb.checked;
				localStorage.setItem(LS_SIDEBAR_STATE, JSON.stringify(s));
			} catch {}
		});
	});
})();

// ── Init ───────────────────────────────────────────────────────────────────
// Check if an event was activated from the admin page
let hasStoredEvent = false;
try {
	const stored = localStorage.getItem('aprs_active_event');
	console.log('Stored event in localStorage:', stored);
	if (stored) {
		const { name, config } = JSON.parse(stored);
		console.log('Parsed stored event:', { name, hasConfig: !!config });
		if (config) {
			console.log('Applying config for event:', name);
			applyConfig(config);
			hasStoredEvent = true;
			// Clear the stored event so we don't keep using it after page reload
			localStorage.removeItem('aprs_active_event');
		}
	} else {
		console.log('No stored event found in localStorage');
	}
} catch (e) {
	console.error('Error loading stored event:', e);
}

// Only load from symlink if no activated event was selected
if (!hasStoredEvent) {
	loadConfig();
	setInterval(loadConfig, 5000);
}

updateMap();
setInterval(updateMap, 5000);
</script>
<div id="no-loc-toast"></div>
</body>
</html>
