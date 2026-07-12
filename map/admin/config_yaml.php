<?php
/**
 * MARS APRS Map — config YAML serialiser
 *
 * Pure functions: no globals, no I/O, no side effects.
 * Extracted here so they can be required by tests without pulling in admin/index.php.
 */

// Multi-line string: encode newlines as \n in a double-quoted YAML scalar
function ym($v) {
    $v = (string)$v;
    if ($v === '') return "''";
    $escaped = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $v);
    return '"' . $escaped . '"';
}

// Bare YAML scalar; double-quotes only when the value requires it
function ys($v) {
    $v = (string)$v;
    if ($v === '') return "''";
    if (preg_match('/^[\[\]{}\'\"!&*#%@|>,`]/', $v) || strpos($v, ': ') !== false) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return $v;
}

// Always double-quoted (attribution HTML contains characters that need quoting)
function yq($v) {
    return '"' . str_replace('"', "'", (string)$v) . '"';
}

function extractHistory($yaml) {
    $history   = [];
    $inHistory = false;
    foreach (explode("\n", $yaml) as $line) {
        if (strpos($line, '# ── Save history') !== false) { $inHistory = true; continue; }
        if ($inHistory && preg_match('/^# (\d{4}-\d{2}-\d{2} .+)$/', $line, $m)) {
            $history[] = $m[1];
        }
    }
    return $history;
}

function buildConfigYaml($cfg, $history = []) {
    $L = [];
    $L[] = '# APRS Tracker Map — master configuration';
    $L[] = '#';
    $L[] = '# @author    Doug Kaye';
    $L[] = '# @copyright 2026 Doug Kaye. All Rights Reserved.';
    $L[] = '#';
    $L[] = '# Edit this file to change trackers, map backgrounds, courses, or the default map view.';
    $L[] = '# Both aprsDaemon.php and index.php monitor this file via filemtime; changes take effect';
    $L[] = '# within ~5 seconds without restarting the daemon or reloading the browser page.';
    if (!empty($history)) {
        $L[] = '#';
        $L[] = '# ── Save history ─────────────────────────────────────────────────────────────';
        foreach ($history as $ts) {
            $L[] = '# ' . $ts;
        }
    }
    $L[] = '';
    $event = trim($cfg['event'] ?? '');
    if ($event !== '') {
        $L[] = '# ── Event ─────────────────────────────────────────────────────────────────────';
        $L[] = '# Name displayed in the lower-left corner of the map.';
        $L[] = 'event: ' . ys($event);
        if (!empty($cfg['locked'])) $L[] = 'locked: true';
        $evPw = trim($cfg['event_password'] ?? '');
        if ($evPw !== '') $L[] = 'event_password: ' . ys($evPw);
        $msgPw = trim($cfg['messaging_password'] ?? '');
        if ($msgPw !== '') $L[] = 'messaging_password: ' . ys($msgPw);
        $blinkDur = isset($cfg['blink_duration']) ? (int)$cfg['blink_duration'] : 5;
        if ($blinkDur !== 5) $L[] = 'blink_duration: ' . $blinkDur;
        $bcCount = isset($cfg['breadcrumb_count']) ? (int)$cfg['breadcrumb_count'] : 100;
        if ($bcCount !== 100) $L[] = 'breadcrumb_count: ' . $bcCount;
        $L[] = '';
    }
    $legend = trim($cfg['legend'] ?? '');
    if ($legend !== '') {
        $L[] = '# ── Legend ────────────────────────────────────────────────────────────────────';
        $L[] = '# HTML displayed in the lower-left corner of the map in kiosk mode.';
        $L[] = 'legend: ' . ym($legend);
        $L[] = '';
    }
    $L[] = '# ── Trackers ──────────────────────────────────────────────────────────────────';
    $L[] = '# One entry per tracked callsign.';
    $L[] = '#   callsign : APRS callsign (e.g. W6SG-4)';
    $L[] = '#   id       : short label displayed on the map marker (e.g. S4)';
    $L[] = '#   name     : full name shown in the sidebar legend';
    $L[] = 'trackers:';
    foreach ($cfg['trackers'] ?? [] as $t) {
        $L[] = '  - callsign: ' . ys($t['callsign'] ?? '');
        $L[] = '    id: '       . ys($t['id']       ?? '');
        $L[] = '    name: '     . ys($t['name']     ?? '');
    }
    $L[] = '';
    $style = $cfg['tracker_style'] ?? [];
    $L[] = '# ── Tracker style ─────────────────────────────────────────────────────────────';
    $L[] = '# Controls the appearance of all tracker markers on the map.';
    $L[] = '#   icon        : marker shape — circle, square, diamond, triangle, star, cross, person';
    $L[] = '#   label_color : hex color for the name/id label on the map (default: #000000)';
    $L[] = 'tracker_style:';
    $L[] = '  icon: '        . ys(trim($style['icon']        ?? 'circle') ?: 'circle');
    $L[] = '  label_color: ' . ys($style['label_color'] ?? '#000000');
    $L[] = '';
    $L[] = '# ── Section visibility ─────────────────────────────────────────────────────────';
    $L[] = '# Default map visibility for each sidebar section (true/false).';
    $L[] = 'section_visibility:';
    $sv  = $cfg['section_visibility'] ?? [];
    foreach (['trackers','courses','aidstations','igates','backgrounds'] as $k) {
        $v = $sv[$k] ?? true;
        $L[] = '  ' . $k . ': ' . (($v && $v !== 'false') ? 'true' : 'false');
    }
    $L[] = '';
    $L[] = '# ── Map default view ──────────────────────────────────────────────────────────';
    $L[] = '# Sets the map center and zoom at page load and after second-clicking a tracker.';
    $L[] = '#   lat  : latitude of map center  (decimal degrees; positive = North, negative = South)';
    $L[] = '#   lon  : longitude of map center (decimal degrees; positive = East,  negative = West)';
    $L[] = '#   zoom : Leaflet zoom level (0=world, 5=country, 10=city, 13=neighbourhood, 15=street, 19=max)';
    $L[] = 'map:';
    $L[] = '  lat: '  . (float)($cfg['map']['lat']  ?? 37.5);
    $L[] = '  lon: '  . (float)($cfg['map']['lon']  ?? -122.0);
    $L[] = '  zoom: ' . (int)  ($cfg['map']['zoom'] ?? 10);
    $L[] = '';
    $L[] = '# ── Map backgrounds ───────────────────────────────────────────────────────────';
    $L[] = '# Tile layers listed in the sidebar. Click one to switch the base map.';
    $L[] = '#   name        : label shown in the sidebar';
    $L[] = '#   url         : Leaflet tile URL template  ({s} = subdomain, {z}/{x}/{y} = tile coordinates)';
    $L[] = '#   attribution : HTML attribution string shown in the map corner (may contain HTML)';
    $L[] = '#   max_zoom    : maximum zoom level supported by this tile provider (optional)';
    $L[] = 'backgrounds:';
    foreach ($cfg['backgrounds'] ?? [] as $b) {
        $L[] = '  - name: '        . ys($b['name']        ?? '');
        $L[] = '    url: '         . ys($b['url']         ?? '');
        $L[] = '    attribution: ' . yq($b['attribution'] ?? '');
        if (isset($b['maxZoom']) && is_numeric($b['maxZoom']))
            $L[] = '    max_zoom: ' . (int)$b['maxZoom'];
    }
    $bgUrl = trim($cfg['background_url'] ?? '');
    if ($bgUrl !== '') $L[] = 'background_url: ' . ys($bgUrl);
    $L[] = '';
    $L[] = '# ── Courses ───────────────────────────────────────────────────────────────────';
    $L[] = '# GPX/KML/GeoJSON overlays listed in the sidebar. Click a name to toggle it on/off.';
    $L[] = '# Multiple courses may be active simultaneously.';
    $L[] = '# Files live in the configs/ subdirectory.';
    $L[] = '#   name  : label shown in the sidebar';
    $L[] = '#   file  : filename  (supported extensions: .gpx  .kml  .geojson  .json)';
    $L[] = '#   color : hex color for the course line/markers (e.g. #2196f3)';
    $L[] = '#   dash  : line style — omit or solid | dashed | dotted | dash-dot';
    $L[] = 'courses:';
    foreach ($cfg['courses'] ?? [] as $c) {
        $L[] = '  - name:  ' . ys($c['name'] ?? '');
        $L[] = '    file:  ' . ys($c['file'] ?? '');
        if (!empty($c['color'])) $L[] = '    color: ' . ys($c['color']);
        if (!empty($c['dash']))  $L[] = '    dash:  ' . ys($c['dash']);
    }
    $L[] = '';
    $L[] = '# ── Aid Stations ──────────────────────────────────────────────────────────────';
    $L[] = '# Aid station locations shown as black dots on the map and listed in the sidebar.';
    $L[] = '#   name     : label shown in the sidebar and on hover';
    $L[] = '#   callsign : (optional) APRS callsign appended to the tooltip';
    $L[] = '#   lat      : latitude  (decimal degrees; positive = North, negative = South)';
    $L[] = '#   lon      : longitude (decimal degrees; positive = East,  negative = West)';
    $L[] = 'aidstations:';
    foreach ($cfg['aidstations'] ?? [] as $g) {
        $L[] = '  - name: ' . ys($g['name'] ?? '');
        if (!empty($g['callsign'])) $L[] = '    callsign: ' . ys($g['callsign']);
        $L[] = '    lat: '  . (is_numeric($g['lat'] ?? '') ? (float)$g['lat'] : 0);
        $L[] = '    lon: '  . (is_numeric($g['lon'] ?? '') ? (float)$g['lon'] : 0);
    }
    $L[] = '';
    $L[] = '# ── iGates ────────────────────────────────────────────────────────────────────';
    $L[] = '# APRS iGate stations shown as black dots on the map and listed in the sidebar.';
    $L[] = '#   name     : label shown in the sidebar and on hover';
    $L[] = '#   callsign : (optional) APRS callsign appended to the tooltip';
    $L[] = '#   lat      : latitude  (decimal degrees; positive = North, negative = South)';
    $L[] = '#   lon      : longitude (decimal degrees; positive = East,  negative = West)';
    $L[] = 'igates:';
    foreach ($cfg['igates'] ?? [] as $g) {
        $L[] = '  - name: ' . ys($g['name'] ?? '');
        if (!empty($g['callsign'])) $L[] = '    callsign: ' . ys($g['callsign']);
        $L[] = '    lat: '  . (is_numeric($g['lat'] ?? '') ? (float)$g['lat'] : 0);
        $L[] = '    lon: '  . (is_numeric($g['lon'] ?? '') ? (float)$g['lon'] : 0);
        if (!empty($g['digipeater'])) $L[] = '    digipeater: true';
    }
    $L[] = '';
    $mob = $cfg['mobile'] ?? [];
    $mobEnabled = !empty($mob['enabled']) && $mob['enabled'] !== false;
    $mobPin     = trim((string)($mob['pin'] ?? ''));
    $mobRoot    = strtoupper(trim((string)($mob['root'] ?? '')));
    $beaconDefs = ['beacon_walk_interval' => 60,  'beacon_walk_distance' => 0.2,
                   'beacon_cycle_interval' => 30, 'beacon_cycle_distance' => 0.2,
                   'beacon_drive_interval' => 15, 'beacon_drive_distance' => 0.2,
                   'beacon_stat_interval' => 120, 'beacon_stat_distance' => 1.0];
    $hasBeacon = false;
    foreach ($beaconDefs as $k => $default) {
        if (isset($mob[$k]) && (float)$mob[$k] !== (float)$default) { $hasBeacon = true; break; }
    }
    if ($mobEnabled || $mobPin !== '' || $mobRoot !== '' || $hasBeacon) {
        $L[] = 'mobile:';
        $L[] = '  enabled: ' . ($mobEnabled ? 'true' : 'false');
        if ($mobPin  !== '') $L[] = '  pin: '  . ys($mobPin);
        if ($mobRoot !== '') $L[] = '  root: ' . ys($mobRoot);
        foreach ($beaconDefs as $k => $default) {
            $v = isset($mob[$k]) ? $mob[$k] : $default;
            if ((float)$v !== (float)$default) {
                $L[] = '  ' . $k . ': ' . (strpos($k, 'distance') !== false ? number_format((float)$v, 1) : (int)$v);
            }
        }
        $L[] = '';
    }
    $om       = $cfg['offline_map'] ?? [];
    $omRadius = isset($om['radius'])   && is_numeric($om['radius'])   ? (float)$om['radius']   : null;
    $omMax    = isset($om['max_zoom']) && is_numeric($om['max_zoom']) ? (int)$om['max_zoom']   : 0;
    $omUrl    = trim((string)($om['url'] ?? ''));
    if ($omRadius !== null || $omMax > 0 || $omUrl !== '') {
        $L[] = 'offline_map:';
        if ($omRadius !== null) $L[] = '  radius: '   . $omRadius;
        if ($omMax    >  0)     $L[] = '  max_zoom: ' . $omMax;
        if ($omUrl   !== '')    $L[] = '  url: '      . ys($omUrl);
        $L[] = '';
    }
    return implode("\n", $L);
}
