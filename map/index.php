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

define('WEB_VERSION', '1.19.1+8');

$trackerStatusFilename = 'trackers.json';

// Track real client IP + timestamp + page type for the Clients modal (non-blocking)
{
    $_rip = trim($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
        ?? $_SERVER['REMOTE_ADDR']);
    $_page = 'map';
    if     (isset($_GET['mobile']))         $_page = 'tracker';
    elseif (isset($_GET['messaging']))      $_page = 'msg';
    elseif (isset($_GET['clientstatus']))   $_page = 'status';
    elseif (isset($_GET['history']))        $_page = 'hist';
    $_ipFile = '/run/aprs/recent_ips.json';
    $_fh = @fopen($_ipFile, 'c+');
    if ($_fh && flock($_fh, LOCK_EX | LOCK_NB)) {
        $ips = json_decode(stream_get_contents($_fh), true) ?: [];
        $ips[$_rip] = ['ts' => time(), 'page' => $_page, 'cs' => $ips[$_rip]['cs'] ?? null];
        if (count($ips) > 200) {
            uasort($ips, fn($a, $b) => $b['ts'] - $a['ts']);
            $ips = array_slice($ips, 0, 200, true);
        }
        rewind($_fh); ftruncate($_fh, 0); fwrite($_fh, json_encode($ips));
        flock($_fh, LOCK_UN);
    }
    if ($_fh) fclose($_fh);
    unset($_page, $_ipFile, $_fh, $ips);
}
define('_CLIENT_IP', $_rip); unset($_rip);

if (isset($_GET['json'])) {
	$igatesStatusFilename     = 'igates.json';
	$aidstationsStatusFilename = 'aidstations.json';
	$mobileFile   = __DIR__ . '/mobile_trackers.json';
	$trackerMtime = file_exists($trackerStatusFilename) ? filemtime($trackerStatusFilename) : 0;
	$configMtime  = file_exists('config.yaml')          ? filemtime('config.yaml')          : 0;
	$igateMtime   = file_exists($igatesStatusFilename)  ? filemtime($igatesStatusFilename)  : 0;
	$aidMtime     = file_exists($aidstationsStatusFilename) ? filemtime($aidstationsStatusFilename) : 0;
	$mobileMtime  = file_exists($mobileFile)            ? filemtime($mobileFile)            : 0;
	$etag = '"' . $trackerMtime . '-' . $configMtime . '-' . $igateMtime . '-' . $aidMtime . '-' . $mobileMtime . '"';
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

	// Read active mobile sessions for sidebar reconciliation
	$activeMobile = [];	// callsign → [id, name]
	$fhm = @fopen($mobileFile, 'r');
	if ($fhm) {
		flock($fhm, LOCK_SH); $mc = stream_get_contents($fhm); flock($fhm, LOCK_UN); fclose($fhm);
		$_mt = json_decode($mc, true) ?: [];
		$now = time();
		foreach (isset($_mt['trackers']) ? $_mt['trackers'] : $_mt as $t) {
			$cs = $t['callsign'] ?? null;
			if (!$cs || !empty($t['blocked'])) continue;
			$hasHam       = !empty($t['ham_callsign']);
			$hasSession   = !empty($t['token']) && ($now - ($t['lastUpdate'] ?? 0)) <= 86400;
			if (!$hasSession) continue;
			$displayId = $t['display_id'] ?? $t['id'];
			$activeMobile[$cs] = ['id' => $displayId, 'tracker_id' => $t['id'], 'name' => $t['name'],
				'sharing_mode' => ($t['sharing_mode'] ?? '') ?: ($t['pending_mode'] ?? ''), 'lastUpdate' => (int)($t['lastUpdate'] ?? 0),
				'lat' => $t['aprs_lat'] ?? null, 'lon' => $t['aprs_lon'] ?? null,
				'ham_callsign' => $t['ham_callsign'] ?? null, 'has_session' => $hasSession,
				'carrier' => $t['device_info']['carrier'] ?? null];
		}
	}
	// Index ham tracker entries by callsign so mobile sessions can absorb their position data.
	// Ham entries (radio components of hybrid trackers) are NOT shown as separate map/sidebar entries.
	$hamData = [];
	foreach ($trackers as $t) {
		if (!empty($t['ham'])) $hamData[$t['callsign']] = $t;
	}
	// Remove stale mobile entries and all ham entries (integrated into their mobile session).
	$trackers = array_values(array_filter($trackers, function($t) use ($activeMobile) {
		if (!empty($t['ham']))    return false;
		return empty($t['mobile']) || isset($activeMobile[$t['callsign']]);
	}));
	// Inject sharing_mode and fresher lastUpdate for mobile trackers already in trackers.json.
	// For hybrid trackers also absorb ham radio position if it is more recent.
	$_now = time();
	$fmtAge = function(int $age): string {
		$s = $age % 60; $m = ($age - $s) / 60;
		if ($m >= 60) { $h = intdiv($m, 60); $rm = $m % 60; return ">{$h}h {$rm}m"; }
		return sprintf('%d:%02d', $m, $s);
	};
	foreach ($trackers as &$t) {
		if (!isset($activeMobile[$t['callsign']])) continue;
		$am = $activeMobile[$t['callsign']];
		$t['mobile'] = true;
		$t['id']     = $am['id'];
		$t['name']   = $am['name'];
		if ($am['sharing_mode'] !== '') $t['sharing_mode'] = $am['sharing_mode'];
		if ($am['carrier'] !== null) $t['carrier'] = $am['carrier'];
		if ($am['ham_callsign'] !== null) {
			$t['ham_callsign'] = $am['ham_callsign'];
			// Absorb ham radio position and device type if available
			$hd = $hamData[$am['ham_callsign']] ?? null;
			if ($hd && ($hd['lastUpdate'] ?? 0) > ($t['lastUpdate'] ?? 0) && $hd['lat'] !== null) {
				$t['lat'] = $hd['lat']; $t['lon'] = $hd['lon'];
				$t['lastUpdate'] = $hd['lastUpdate'];
				$t['path'] = $hd['path'] ?? $t['path'];
			}
			if ($hd && !empty($hd['radio_type'])) $t['radio_type'] = $hd['radio_type'];
		}
		if ($am['lat'] !== null && $t['lat'] === null) { $t['lat'] = $am['lat']; $t['lon'] = $am['lon']; }
		if ($am['lastUpdate'] > ($t['lastUpdate'] ?? 0)) {
			$age = $_now - $am['lastUpdate'];
			$t['lastUpdate']          = $am['lastUpdate'];
			$t['timeSinceLastUpdate'] = $age;
			$t['time']                = $fmtAge($age);
			$t['color']               = $age <= 120 ? 'green' : ($age <= 300 ? 'blue' : 'red');
		} elseif (isset($t['lastUpdate']) && $t['lastUpdate'] > 0) {
			$age = $_now - $t['lastUpdate'];
			$t['timeSinceLastUpdate'] = $age;
			$t['time']                = $fmtAge($age);
			$t['color']               = $age <= 120 ? 'green' : ($age <= 300 ? 'blue' : 'red');
		}
	}
	unset($t);
	// Inject placeholder entries for sessions not yet heard by the daemon.
	// Ham-only entries (no active mobile session) are skipped — the daemon supplies position from radio beacons.
	$knownCallsigns = array_column($trackers, 'callsign');
	foreach ($activeMobile as $cs => $ms) {
		if (!in_array($cs, $knownCallsigns, true) && $ms['has_session']) {
			$lu = $ms['lastUpdate'] ?? 0;
			$age = $lu > 0 ? ($_now - $lu) : 0;
			$s = $age % 60; $m = ($age - $s) / 60;
			if ($m >= 60) { $h = intdiv($m, 60); $rm = $m % 60; $tf = ">{$h}h {$rm}m"; }
			elseif ($lu > 0) { $tf = sprintf('%d:%02d', $m, $s); }
			else { $tf = '—'; }
			$entry = ['callsign' => $cs, 'id' => $ms['id'], 'name' => $ms['name'],
			          'lastUpdate' => $lu, 'timeSinceLastUpdate' => $age, 'time' => $tf,
			          'color' => ($age > 0 && $age <= 120) ? 'green' : (($age > 0 && $age <= 300) ? 'blue' : 'red'),
			          'lat' => $ms['lat'], 'lon' => $ms['lon'], 'path' => '', 'mobile' => true];
			if ($ms['sharing_mode'] !== '') $entry['sharing_mode'] = $ms['sharing_mode'];
			if ($ms['ham_callsign'] !== null) $entry['ham_callsign'] = $ms['ham_callsign'];
			if ($ms['carrier'] !== null) $entry['carrier'] = $ms['carrier'];
			$trackers[] = $entry;
		}
	}

	$readBeaconFile = function($path) {
		if (!file_exists($path)) return [];
		$fh = fopen($path, 'r'); if (!$fh) return [];
		flock($fh, LOCK_SH); $c = stream_get_contents($fh); flock($fh, LOCK_UN); fclose($fh);
		return json_decode($c, true) ?: [];
	};
	$_mob = $cfg['mobile'] ?? [];
	$_bc = [
		'walk_interval'  => (int)(  $_mob['beacon_walk_interval']  ?? 60),
		'walk_distance'  => (float)($_mob['beacon_walk_distance']  ?? 0.2),
		'cycle_interval' => (int)(  $_mob['beacon_cycle_interval'] ?? 30),
		'cycle_distance' => (float)($_mob['beacon_cycle_distance'] ?? 0.2),
		'drive_interval' => (int)(  $_mob['beacon_drive_interval'] ?? 15),
		'drive_distance' => (float)($_mob['beacon_drive_distance'] ?? 0.2),
		'stat_interval'  => (int)(  $_mob['beacon_stat_interval']  ?? 120),
		'stat_distance'  => (float)($_mob['beacon_stat_distance']  ?? 1.0),
	];
	header('Content-Type: application/json');
	echo json_encode([
		'default_event'      => $defaultEvent,
		'password_required'  => !empty($cfg['event_password'] ?? ''),
		'blink_duration'     => (int)($cfg['blink_duration'] ?? 5),
		'breadcrumb_count'   => (int)($cfg['breadcrumb_count'] ?? 100),
		'mobile_beacons'     => $_bc,
		'trackers'           => $trackers,
		'igate_beacons'      => $readBeaconFile($igatesStatusFilename),
		'aid_beacons'        => $readBeaconFile($aidstationsStatusFilename),
	]);
	exit;
}

if (isset($_GET['history'])) {
	$cfgReal     = realpath('config.yaml');
	$analyzerDb  = '/home/pi/analyzer/src/aprs.db';

	// Prefer analyzer SQLite DB: it has the full beacon history for all trackers.
	// Fall back to tracker_history.yaml when the DB is unavailable.
	if (file_exists($analyzerDb)) {
		try {
			require_once 'config_parse.php';
			$_hcfg     = parseConfigYaml('config.yaml');
			$_evName   = $_hcfg['event'] ?? '';
			$_hdb      = null;
			$_evId     = null;
			if ($_evName !== '') {
				$_hdb = new SQLite3($analyzerDb, SQLITE3_OPEN_READONLY);
				$_hdb->busyTimeout(500);
				$_evRow = $_hdb->querySingle("SELECT id FROM events WHERE name='" . $_hdb->escapeString($_evName) . "'");
				$_evId  = ($_evRow !== false && $_evRow !== null) ? (int)$_evRow : null;
			}
			if ($_evId !== null) {
				$_maxTs = (int)$_hdb->querySingle("SELECT CAST(MAX(time) AS INTEGER) FROM beacons WHERE event_id=$_evId");
				$etag   = '"db-' . $_evId . '-' . $_maxTs . '"';
				header('ETag: ' . $etag);
				header('Cache-Control: no-cache');
				if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
					http_response_code(304);
					$_hdb->close();
					exit;
				}
				// Fetch beacons newest-first, capped PER CALLSIGN (not globally). A flat
				// "ORDER BY callsign, time DESC LIMIT 20000" let a few busy trackers exhaust
				// the budget, so every callsign sorting after the cutoff (e.g. late MARSQ-###
				// mobiles) returned zero crumbs and its trail vanished. ROW_NUMBER() keeps the
				// newest 500 raw rows for each callsign, guaranteeing every tracker is present
				// while the PHP loop below still trims to 100 kept crumbs after dedup. rn=1 is
				// the newest row, so "ORDER BY callsign, rn" preserves the newest-first order
				// the anti-shuffle filter relies on.
				$_res  = $_hdb->query("SELECT callsign, latitude, longitude, ts, path FROM (SELECT callsign, latitude, longitude, CAST(time AS INTEGER) as ts, path, ROW_NUMBER() OVER (PARTITION BY callsign ORDER BY time DESC) rn FROM beacons WHERE event_id=$_evId) WHERE rn <= 500 ORDER BY callsign, rn");
				$_byCs = []; $_seen = []; $_lastKept = [];
				while ($_r = $_res->fetchArray(SQLITE3_ASSOC)) {
					$_cs = $_r['callsign'];
					if (!isset($_byCs[$_cs])) $_byCs[$_cs] = [];
					if (count($_byCs[$_cs]) >= 100) continue;
					// 5-second bucket dedup (APRS-IS and direct injection produce near-duplicate rows)
					$_bk = $_cs . ':' . (int)($_r['ts'] / 5);
					if (isset($_seen[$_bk])) continue;
					$_seen[$_bk] = true;
					// Anti-shuffle: drop breadcrumbs within 30.48 m (100 ft) of the last *kept*
					// point for this callsign so a stationary tracker's ~20 m GPS drift (heartbeat
					// beacons re-inject the raw fix every 5 min) doesn't render as a cluster.
					// Mirrors aprsDaemon.php's tracker_history.yaml dedup (MIN_MOVE_METRES), but
					// compares against the last kept point rather than just the previous row, so a
					// genuine ping-pong between two spots >30 m apart still collapses. Rows arrive
					// newest-first per callsign, so the current position is always the first crumb.
					if (isset($_lastKept[$_cs])
						&& haversineMeters($_lastKept[$_cs][0], $_lastKept[$_cs][1], (float)$_r['latitude'], (float)$_r['longitude']) < 30.48) {
						continue;
					}
					$_lastKept[$_cs] = [(float)$_r['latitude'], (float)$_r['longitude']];
					$_byCs[$_cs][] = ['lat' => $_r['latitude'], 'lon' => $_r['longitude'], 'ts' => $_r['ts'], 'path' => $_r['path'] ?? ''];
				}
				$_hdb->close();
				header('Content-Type: application/json');
				echo json_encode($_byCs);
				exit;
			}
			if ($_hdb) $_hdb->close();
		} catch (Exception $_he) { /* fall through to YAML */ }
	}

	// YAML fallback
	$histPath   = $cfgReal ? dirname($cfgReal) . '/tracker_history.yaml' : null;
	$mobileHist = __DIR__ . '/mobile_history.yaml';
	$mtime1     = ($histPath && file_exists($histPath))   ? filemtime($histPath)   : 0;
	$mtime2     = file_exists($mobileHist)                ? filemtime($mobileHist) : 0;
	$etag       = '"' . $mtime1 . '-' . $mtime2 . '"';
	header('ETag: ' . $etag);
	header('Cache-Control: no-cache');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}
	header('Content-Type: application/json');
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
	$beaconCfg = [
		'walk_interval'  => (int)(  $mobileCfgTmp['beacon_walk_interval']  ?? 60),
		'walk_distance'  => (float)($mobileCfgTmp['beacon_walk_distance']  ?? 0.2),
		'cycle_interval' => (int)(  $mobileCfgTmp['beacon_cycle_interval'] ?? 30),
		'cycle_distance' => (float)($mobileCfgTmp['beacon_cycle_distance'] ?? 0.2),
		'drive_interval' => (int)(  $mobileCfgTmp['beacon_drive_interval'] ?? 15),
		'drive_distance' => (float)($mobileCfgTmp['beacon_drive_distance'] ?? 0.2),
		'stat_interval'  => (int)(  $mobileCfgTmp['beacon_stat_interval']  ?? 120),
		'stat_distance'  => (float)($mobileCfgTmp['beacon_stat_distance']  ?? 1.0),
	];
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
		'mobile_enabled'      => $mobileEnabled,
		'mobile_beacons'      => $beaconCfg,
		'messaging_enabled'   => !empty(trim($cfg['messaging_password'] ?? '')),
		'offline_map'         => (object)($cfg['offline_map'] ?? []),
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
	$recentIps = [];
	$_ipFile = '/run/aprs/recent_ips.json';
	if (is_readable($_ipFile)) {
		$_ipData = json_decode(file_get_contents($_ipFile), true) ?: [];
		uasort($_ipData, fn($a, $b) => $b['ts'] - $a['ts']);
		$recentIps = array_slice($_ipData, 0, 50, true);
	}
	$csNames = [];
	$_mf = __DIR__ . '/mobile_trackers.json';
	if (is_readable($_mf)) {
		foreach ((json_decode(file_get_contents($_mf), true)['trackers'] ?? []) as $_t) {
			if (!empty($_t['callsign']) && !empty($_t['name'])) $csNames[$_t['callsign']] = $_t['name'];
		}
	}
	echo json_encode([
		'busy'      => isset($stats['BusyWorkers']) ? (int)$stats['BusyWorkers']  : null,
		'idle'      => isset($stats['IdleWorkers'])  ? (int)$stats['IdleWorkers']  : null,
		'rps'       => isset($stats['ReqPerSec'])    ? (float)$stats['ReqPerSec'] : null,
		'uptime'    => $stats['ServerUptime'] ?? null,
		'clients'   => $counts,
		'total'     => count($clients),
		'recentIps' => $recentIps,
		'csNames'   => $csNames,
	]);
	exit;
}

// ── Messaging endpoints ───────────────────────────────────────────────────────
if (isset($_GET['messaging'])) {
	header('Content-Type: application/json');
	$action         = $_GET['messaging'];
	$webSessionsFile = '/run/aprs/web_sessions.json';

	require_once 'config_parse.php';
	$_mcfg       = parseConfigYaml('config.yaml');
	$msgPassword = trim($_mcfg['messaging_password'] ?? '');
	$cfgReal     = realpath('config.yaml');
	$messagesFile = $cfgReal ? dirname($cfgReal) . '/messages.json' : null;

	$validateWebToken = function($token) use ($webSessionsFile) {
		if (!$token || !file_exists($webSessionsFile)) return false;
		$fh = fopen($webSessionsFile, 'r');
		if (!$fh) return false;
		flock($fh, LOCK_SH); $data = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
		foreach ($data as $s) { if (!empty($s['token']) && hash_equals($s['token'], $token)) return $s; }
		return false;
	};

	$modifyJsonFile = function($file, $fn) {
		$fh = fopen($file, 'c+');
		if (!$fh) return false;
		flock($fh, LOCK_EX);
		$c    = stream_get_contents($fh);
		$data = json_decode($c, true) ?: [];
		$data = $fn($data);
		ftruncate($fh, 0); rewind($fh);
		fwrite($fh, json_encode($data, JSON_PRETTY_PRINT) . "\n");
		flock($fh, LOCK_UN); fclose($fh);
		return true;
	};

	if ($action === 'subscribe') {
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$name  = substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $input['name'] ?? '')), 0, 30);
		$pw    = trim($input['password'] ?? '');
		if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
		if ($msgPassword === '' || !hash_equals($msgPassword, $pw)) {
			http_response_code(403); echo json_encode(['error' => 'Incorrect password']); exit;
		}
		$token = bin2hex(random_bytes(16));
		$modifyJsonFile($webSessionsFile, function($data) use ($token, $name) {
			$data[] = ['token' => $token, 'name' => $name, 'ts' => time()];
			return array_slice($data, -50);
		});
		echo json_encode(['token' => $token, 'name' => $name]);
		exit;
	}

	if ($action === 'rename') {
		$input    = json_decode(file_get_contents('php://input'), true) ?: [];
		$webToken = trim($input['web_token'] ?? '');
		$newName  = substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $input['name'] ?? '')), 0, 30);
		if (!$newName) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
		$session = $validateWebToken($webToken);
		if (!$session) { http_response_code(403); echo json_encode(['error' => 'Not subscribed']); exit; }
		$modifyJsonFile($webSessionsFile, function($data) use ($webToken, $newName) {
			foreach ($data as &$s) {
				if (!empty($s['token']) && hash_equals($s['token'], $webToken)) { $s['name'] = $newName; break; }
			}
			return $data;
		});
		echo json_encode(['ok' => true, 'name' => $newName]);
		exit;
	}

	if ($action === 'send') {
		$input    = json_decode(file_get_contents('php://input'), true) ?: [];
		$webToken = trim($input['web_token'] ?? '');
		$session  = $validateWebToken($webToken);
		if (!$session) { http_response_code(403); echo json_encode(['error' => 'Not subscribed']); exit; }
		$to   = trim($input['to'] ?? '');
		$text = substr(trim($input['text'] ?? ''), 0, 280);
		if (!$text) { http_response_code(400); echo json_encode(['error' => 'Message text required']); exit; }
		if (!$to)   { http_response_code(400); echo json_encode(['error' => 'Recipient required']); exit; }
		$broadcast = ($to === '*');
		$mobileFile = __DIR__ . '/mobile_trackers.json';
		$mobileData = [];
		$fh = fopen($mobileFile, 'r');
		if ($fh) { flock($fh, LOCK_SH); $_mtd = json_decode(stream_get_contents($fh), true) ?: []; $mobileData = isset($_mtd['trackers']) ? $_mtd['trackers'] : $_mtd; flock($fh, LOCK_UN); fclose($fh); }
		$toLabel = $broadcast ? '*' : $to;
		if (!$broadcast) { foreach ($mobileData as $t) { if (($t['callsign'] ?? '') === $to) { $toLabel = $t['name'] ?? $to; break; } } }
		$msgId = null;
		if ($messagesFile) {
			$modifyJsonFile($messagesFile, function($msgs) use ($session, $to, $toLabel, $text, $broadcast, &$msgId) {
				$msgId = (int)(end($msgs)['id'] ?? 0) + 1;
				$msgs[] = ['id' => $msgId, 'ts' => time(), 'from' => 'web', 'from_label' => $session['name'],
				           'to' => $to, 'to_label' => $toLabel, 'text' => $text, 'broadcast' => $broadcast];
				return $msgs;
			});
		}
		$pendingEntry = ['id' => $msgId ?? 0, 'from_label' => $session['name'], 'text' => $text, 'ts' => time()];
		$fh = fopen($mobileFile, 'c+');
		if ($fh) {
			flock($fh, LOCK_EX);
			$_mfraw = json_decode(stream_get_contents($fh), true) ?: [];
			$_mfcounter = $_mfraw['counter'] ?? 0;
			$data = isset($_mfraw['trackers']) ? $_mfraw['trackers'] : $_mfraw;
			foreach ($data as &$t) {
				if (empty($t['token'])) continue;
				if ($broadcast || ($t['callsign'] ?? '') === $to) $t['pending_msgs'][] = $pendingEntry;
			}
			unset($t);
			ftruncate($fh, 0); rewind($fh);
			fwrite($fh, json_encode(['counter' => $_mfcounter, 'trackers' => $data], JSON_PRETTY_PRINT) . "\n");
			flock($fh, LOCK_UN); fclose($fh);
		}
		echo json_encode(['ok' => true, 'id' => $msgId]);
		exit;
	}

	if ($action === 'poll') {
		$webToken = trim($_GET['web_token'] ?? '');
		$sinceId  = (int)($_GET['since_id'] ?? 0);
		if (!$validateWebToken($webToken)) { http_response_code(403); echo json_encode(['error' => 'Not subscribed']); exit; }
		$modifyJsonFile($webSessionsFile, function($data) use ($webToken) {
			foreach ($data as &$s) {
				if (!empty($s['token']) && hash_equals($s['token'], $webToken)) { $s['ts'] = time(); break; }
			}
			return $data;
		});
		$msgs = []; $lastId = $sinceId;
		if ($messagesFile && file_exists($messagesFile)) {
			$fh = fopen($messagesFile, 'r');
			if ($fh) {
				flock($fh, LOCK_SH); $all = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
				foreach ($all as $m) {
					if ((int)$m['id'] > $sinceId && ($m['to'] === 'web' || !empty($m['broadcast']))) {
						$msgs[] = $m;
						if ((int)$m['id'] > $lastId) $lastId = (int)$m['id'];
					}
				}
			}
		}
		echo json_encode(['messages' => $msgs, 'last_id' => $lastId]);
		exit;
	}

	if ($action === 'history') {
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$webToken = trim($input['web_token'] ?? $_GET['web_token'] ?? '');
		if (!$validateWebToken($webToken)) { http_response_code(403); echo json_encode(['error' => 'Not subscribed']); exit; }
		$all = [];
		if ($messagesFile && file_exists($messagesFile)) {
			$fh = fopen($messagesFile, 'r');
			if ($fh) { flock($fh, LOCK_SH); $all = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh); }
		}
		$lastId = $all ? max(array_column($all, 'id')) : 0;
		echo json_encode(['messages' => $all, 'last_id' => $lastId]);
		exit;
	}

	http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
}

// ── APRS-IS helpers (used by mobile update) ───────────────────────────────────

// Returns distance in meters between two lat/lon points.
function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
	$R  = 6371000;
	$p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
	$dp = deg2rad($lat2 - $lat1);
	$dl = deg2rad($lon2 - $lon1);
	$a  = sin($dp/2)**2 + cos($p1)*cos($p2)*sin($dl/2)**2;
	return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function validateHamCallsign(string $root): bool {
	$root = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $root));
	// ITU/FCC format: 1–3 prefix chars (letters or digit), one area digit, 1–3 letter suffix
	if (!preg_match('/^[A-Z0-9]{1,3}[0-9][A-Z]{1,3}$/', $root)) return false;
	// callook.info only knows FCC-licensed US callsigns (K, N, W, AA–AL prefixes).
	// For international callsigns, format check above is sufficient.
	$looksUS = preg_match('/^[KNW]/', $root) || preg_match('/^A[A-L]/', $root);
	if (!$looksUS) return true;
	$url  = 'https://callook.info/' . urlencode($root) . '/json';
	$ctx  = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: MARSAPRS/1.0\r\n"]]);
	$json = @file_get_contents($url, false, $ctx);
	if (!$json) return true;  // callook.info unreachable — allow through
	$data = json_decode($json, true);
	return ($data['status'] ?? '') === 'VALID';
}

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

function injectAnalyzerBeacon(string $callsign, string $name, float $lat, float $lon, int $ts, string $eventName): void {
	$dbPath = '/home/pi/analyzer/src/aprs.db';
	if (!file_exists($dbPath) || !is_writable($dbPath)) return;
	try {
		$db = new SQLite3($dbPath);
		$db->busyTimeout(1000);
		$db->exec("CREATE TABLE IF NOT EXISTS tracker_names (callsign TEXT PRIMARY KEY, name TEXT NOT NULL)");
		if ($name !== '') {
			$s = $db->prepare("INSERT INTO tracker_names (callsign,name) VALUES(?,?) ON CONFLICT(callsign) DO UPDATE SET name=excluded.name");
			$s->bindValue(1, $callsign, SQLITE3_TEXT); $s->bindValue(2, $name, SQLITE3_TEXT); $s->execute();
		}
		if ($eventName === '') { $db->close(); return; }
		$s = $db->prepare("SELECT id FROM events WHERE name=?");
		$s->bindValue(1, $eventName, SQLITE3_TEXT);
		$row = $s->execute()->fetchArray(SQLITE3_ASSOC);
		if (!$row) { $db->close(); return; }
		$s = $db->prepare("INSERT INTO beacons (callsign,latitude,longitude,time,receiver,event_id,path) VALUES(?,?,?,?,?,?,?)");
		$s->bindValue(1, $callsign, SQLITE3_TEXT);
		$s->bindValue(2, $lat,      SQLITE3_FLOAT);
		$s->bindValue(3, $lon,      SQLITE3_FLOAT);
		$s->bindValue(4, $ts,       SQLITE3_INTEGER);
		$s->bindValue(5, 'MARSQ',   SQLITE3_TEXT);
		$s->bindValue(6, $row['id'],SQLITE3_INTEGER);
		$s->bindValue(7, '',        SQLITE3_TEXT);
		$s->execute();
		$db->close();
	} catch (Exception $e) { /* don't break beaconing if analyzer DB unavailable */ }
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

	// ── Event password auth (works even when mobile tracking is disabled) ──────
	if ($action === 'auth') {
		$pw      = trim($input['password'] ?? '');
		$evPw    = trim($_mcfg['event_password'] ?? '');
		$evName  = $_mcfg['event'] ?? '';
		if ($evPw === '') {
			echo json_encode(['ok' => true, 'required' => false, 'event' => $evName]);
		} elseif (hash_equals($evPw, $pw)) {
			echo json_encode(['ok' => true, 'required' => true, 'event' => $evName]);
		} else {
			http_response_code(403);
			echo json_encode(['error' => 'Incorrect event password']);
		}
		exit;
	}

	if (!$mobileOn) { http_response_code(403); echo json_encode(['error' => 'Mobile tracking is not enabled']); exit; }

	// Atomic read-modify-write with exclusive lock.
	// File format: {"counter": N, "trackers": [...]} (or legacy plain array on first read).
	// Callback receives ($entries, &$meta) where $meta = ['counter' => N].
	// Callback must return the (possibly modified) $entries array.
	function modifyMobileTrackers($file, $fn) {
		$fh = fopen($file, 'c+');
		if (!$fh) return false;
		flock($fh, LOCK_EX);
		$c      = stream_get_contents($fh);
		$parsed = json_decode($c, true);
		if (is_array($parsed) && array_key_exists('trackers', $parsed)) {
			$meta    = ['counter' => (int)($parsed['counter'] ?? 0)];
			$entries = $parsed['trackers'] ?? [];
		} else {
			// Migrate legacy plain-array format: seed counter from max existing ID.
			$entries = is_array($parsed) ? $parsed : [];
			$counter = 0;
			foreach ($entries as $t) {
				if (preg_match('/^M(\d+)$/', $t['id'] ?? '', $m)) $counter = max($counter, (int)$m[1]);
			}
			$meta = ['counter' => $counter];
		}
		$entries = $fn($entries, $meta);
		ftruncate($fh, 0); rewind($fh);
		fwrite($fh, json_encode(['counter' => $meta['counter'], 'trackers' => $entries], JSON_PRETTY_PRINT) . "\n");
		flock($fh, LOCK_UN); fclose($fh);
		return true;
	}

	if ($action === 'join') {
		$name = preg_replace('/[^A-Za-z0-9 \-]/', '', trim($input['name'] ?? ''));
		$name = substr($name, 0, 12);
		$pin  = (string)($input['pin'] ?? '');
		// device_id: stable per-installation ID from the mobile client (32-char hex)
		$deviceId = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string)($input['device_id'] ?? '')));
		$deviceId = strlen($deviceId) >= 8 ? substr($deviceId, 0, 64) : '';
		// device_info: optional metadata from new clients; absent from old clients (ignored gracefully)
		$rawInfo = $input['device_info'] ?? null;
		$deviceInfo = null;
		if (is_array($rawInfo)) {
			$cleaned = [];
			foreach (['app', 'os', 'model', 'manufacturer', 'browser', 'screen'] as $k) {
				$v = isset($rawInfo[$k]) ? substr(trim((string)$rawInfo[$k]), 0, 80) : '';
				if ($v !== '') $cleaned[$k] = $v;
			}
			if (!empty($cleaned)) $deviceInfo = $cleaned;
		}
		// Carrier/ISP lookup by client IP — no app permissions needed.
		$_joinIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']
		    ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null)
		    ?? $_SERVER['REMOTE_ADDR']);
		if (filter_var($_joinIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		    $_ipCtx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: MARS-APRS/1.0\r\n"]]);
		    $_ipResp = @file_get_contents("http://ip-api.com/json/{$_joinIp}?fields=isp", false, $_ipCtx);
		    if ($_ipResp) {
		        $_ipData = json_decode($_ipResp, true);
		        $_isp = trim($_ipData['isp'] ?? '');
		        if ($_isp !== '') {
		            if ($deviceInfo === null) $deviceInfo = [];
		            $deviceInfo['carrier'] = $_isp;
		        }
		    }
		}
		$rawMode = trim($input['sharing_mode'] ?? '');
		if ($rawMode === 'drive_cycle') $rawMode = 'drive'; // legacy migration
		$sharingMode = in_array($rawMode, ['walk_run', 'cycle', 'drive', 'stationary', 'unknown'], true) ? $rawMode : '';
		if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name is required']); exit; }
		$storedPin = (string)($mobileCfg['pin'] ?? '');
		if ($storedPin === '' || !hash_equals($storedPin, $pin)) {
			http_response_code(403); echo json_encode(['error' => 'Incorrect PIN']); exit;
		}
		$root = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $mobileCfg['root'] ?? ''), 0, 5));
		if ($root === '') { http_response_code(500); echo json_encode(['error' => 'Root callsign not configured']); exit; }
		// Optional ham radio callsign (user-supplied, validated against FCC ULS)
		$hamRoot = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($input['ham_root'] ?? '')));
		$hamSsid = (int)($input['ham_ssid'] ?? 0);
		$hamCallsign = null;
		if ($hamRoot !== '') {
			if ($hamSsid < 1 || $hamSsid > 15) {
				http_response_code(422); echo json_encode(['error' => 'SSID must be between 1 and 15', 'field' => 'ssid']); exit;
			}
			if (!validateHamCallsign($hamRoot)) {
				http_response_code(422); echo json_encode(['error' => 'Callsign not found in license database. Check your callsign and try again.', 'field' => 'callsign']); exit;
			}
			$hamCallsign = "{$hamRoot}-{$hamSsid}";
		}
		$newEntry = null;
		$isBlocked = false;
		$hamConflict = false;
		$isNewCallsign = false;
		modifyMobileTrackers($mobileFile, function($data, &$meta) use ($name, $root, $deviceId, $deviceInfo, $sharingMode, $hamCallsign, &$newEntry, &$isBlocked, &$hamConflict, &$isNewCallsign) {
			$now = time();
			// If device is known, check blocked status before doing anything else.
			if ($deviceId !== '') {
				foreach ($data as $t) {
					if (($t['device_id'] ?? '') === $deviceId && !empty($t['blocked'])) {
						$isBlocked = true;
						return $data; // leave file unchanged
					}
				}
			}
			// Prune: anonymous entries after 24 h; device-linked entries after 30 days;
			// blocked entries are never pruned (admin must remove them explicitly).
			$data = array_values(array_filter($data, function($t) use ($now) {
				if (!empty($t['blocked'])) return true;
				$age = $now - ($t['lastUpdate'] ?? 0);
				return !empty($t['device_id']) ? $age < 2592000 : $age < 86400;
			}));
			// If a ham callsign was requested, verify it isn't already claimed by a different device.
			if ($hamCallsign !== null) {
				foreach ($data as $ot) {
					if (($ot['ham_callsign'] ?? '') === $hamCallsign
						&& ($ot['device_id'] ?? '') !== $deviceId) {
						$hamConflict = true;
						return $data;
					}
				}
			}
			// Re-use existing entry for this device (keeps same callsign across restarts).
			if ($deviceId !== '') {
				foreach ($data as &$t) {
					if (($t['device_id'] ?? '') === $deviceId) {
						$t['token']      = bin2hex(random_bytes(16));
						$t['lastUpdate'] = $now;
						$t['name']       = $name;
						if ($deviceInfo !== null) $t['device_info'] = $deviceInfo;
						if ($sharingMode !== '') $t['sharing_mode'] = $sharingMode;
						if ($hamCallsign !== null) {
							$t['ham_callsign'] = $hamCallsign; // injection callsign stays as MARSQQ-NN
						} else {
							unset($t['ham_callsign']); // user removed ham callsign
						}
						// Sanitize: if injection callsign was corrupted to the ham callsign, reset it.
						if (preg_match('/^M(\d+)$/', $t['id'] ?? '', $mm)
							&& !preg_match('/^\Q' . $root . '\E-\d+$/', $t['callsign'] ?? '')) {
							$t['callsign'] = sprintf("%s-%03d", $root, (int)$mm[1]);
						}
						$newEntry = $t;
						return $data;
					}
				}
				unset($t);
				// No device_id match — clean up any inactive anonymous entry with the same name
				// so the slot becomes available (migration from sessions before device_id was added).
				$data = array_values(array_filter($data, function($t) use ($name) {
					return !(empty($t['device_id']) && empty($t['token']) && ($t['name'] ?? '') === $name);
				}));
			}
			// No existing entry — assign next sequential number (highest-ever + 1).
			// After 999, wrap and find the lowest unused number from 000.
			// Fail if all 1000 slots (000–999) are occupied.
			$next = $meta['counter'] + 1;
			if ($next > 999) {
				$used = [];
				foreach ($data as $t) {
					if (preg_match('/^M(\d+)$/', $t['id'], $m)) $used[(int)$m[1]] = true;
				}
				$next = null;
				for ($k = 0; $k <= 999; $k++) { if (!isset($used[$k])) { $next = $k; break; } }
				if ($next === null) {
					// All 1000 tracker slots are in use.
					$newEntry = 'limit_reached';
					return $data;
				}
			}
			$meta['counter'] = $next;
			$id       = sprintf("M%03d", $next);
			$callsign = sprintf("%s-%03d", $root, $next);
			$newEntry = ['id' => $id, 'callsign' => $callsign, 'name' => $name,
			             'token' => bin2hex(random_bytes(16)), 'lastUpdate' => $now, 'created' => $now];
			if ($deviceId !== '') $newEntry['device_id'] = $deviceId;
			if ($deviceInfo !== null) $newEntry['device_info'] = $deviceInfo;
			if ($sharingMode !== '') $newEntry['sharing_mode'] = $sharingMode;
			if ($hamCallsign !== null) $newEntry['ham_callsign'] = $hamCallsign;
			$data[] = $newEntry;
			$isNewCallsign = true;
			return $data;
		});
		if ($isBlocked) { http_response_code(403); echo json_encode(['error' => 'Incorrect PIN']); exit; }
		if ($hamConflict) { http_response_code(409); echo json_encode(['error' => "{$hamCallsign} is already registered to another tracker", 'field' => 'callsign']); exit; }
		if ($newEntry === 'limit_reached') { http_response_code(503); echo json_encode(['error' => 'The limit of 1000 trackers has been reached. It is not possible to add your tracker at this time.']); exit; }
		// Purge any messages to/from this callsign — it's a fresh assignment, likely a new user.
		if ($isNewCallsign) {
			$_cfgReal = realpath('config.yaml');
			$_msgsFile = $_cfgReal ? dirname($_cfgReal) . '/messages.json' : null;
			if ($_msgsFile && file_exists($_msgsFile)) {
				$_cs = $newEntry['callsign'];
				$_fh = fopen($_msgsFile, 'c+');
				if ($_fh) {
					flock($_fh, LOCK_EX);
					$_msgs = json_decode(stream_get_contents($_fh), true) ?: [];
					$_msgs = array_values(array_filter($_msgs, function($m) use ($_cs) {
						return ($m['from'] ?? '') !== $_cs && ($m['to'] ?? '') !== $_cs;
					}));
					ftruncate($_fh, 0); rewind($_fh);
					fwrite($_fh, json_encode($_msgs, JSON_PRETTY_PRINT) . "\n");
					flock($_fh, LOCK_UN); fclose($_fh);
				}
			}
		}
		echo json_encode([
			'id'       => $newEntry['id'],
			'token'    => $newEntry['token'],
			'callsign' => $newEntry['callsign'],
			'passcode' => aprsPasscode($newEntry['callsign']),
		]);
		exit;
	}

	if ($action === 'update') {
		$token   = $input['token'] ?? '';
		$lat     = isset($input['lat']) ? round((float)$input['lat'], 6) : null;
		$lon     = isset($input['lon']) ? round((float)$input['lon'], 6) : null;
		$ackIds  = array_map('intval', $input['ack_ids'] ?? []);
		$rawMode = trim($input['sharing_mode'] ?? '');
		if ($rawMode === 'drive_cycle') $rawMode = 'drive';
		$updMode = in_array($rawMode, ['walk_run', 'cycle', 'drive', 'stationary', 'unknown'], true) ? $rawMode : '';
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }

		$found = false; $blocked = false; $foundCallsign = null; $foundHamCallsign = null; $foundName = ''; $pendingMsgs = []; $pendingMode = '';
		$shouldInject = false;
		modifyMobileTrackers($mobileFile, function($data) use ($token, $lat, $lon, $ackIds, $updMode, &$found, &$blocked, &$foundCallsign, &$foundHamCallsign, &$foundName, &$pendingMsgs, &$pendingMode, &$shouldInject) {
			$now = time();
			foreach ($data as &$t) {
				if (empty($t['token']) || !hash_equals($t['token'], $token)) continue;
				if (!empty($t['blocked'])) { $blocked = true; break; }
				$found = true; $foundCallsign = $t['callsign'] ?? null; $foundHamCallsign = $t['ham_callsign'] ?? null; $foundName = $t['name'] ?? '';
				$pendingMsgs = $t['pending_msgs'] ?? [];
				$pendingMode = $t['pending_mode'] ?? '';
				if ($ackIds) $t['pending_msgs'] = array_values(array_filter($t['pending_msgs'] ?? [], fn($m) => !in_array((int)$m['id'], $ackIds)));
				if ($pendingMode !== '') unset($t['pending_mode']);
				if ($updMode !== '' && $updMode !== ($t['sharing_mode'] ?? '')) {
					$t['sharing_mode'] = $updMode;
					$t['recent_beacons'] = []; // mode changed; reset delta so old interval doesn't pollute new one
				} elseif ($updMode !== '') {
					$t['sharing_mode'] = $updMode;
				}
				// Track last 10 beacon timestamps for admin delta display
				$t['recent_beacons'] = array_slice(array_merge([$now], $t['recent_beacons'] ?? []), 0, 10);
				$t['lastUpdate'] = $now;
				if ($lat !== null && $lon !== null) {
					$prevLat = $t['aprs_lat'] ?? null;
					$prevLon = $t['aprs_lon'] ?? null;
					$prevTs  = $t['aprs_ts']  ?? 0;
					$moved   = ($prevLat === null)
					         || haversineMeters($prevLat, $prevLon, $lat, $lon) >= 30;
					$stale   = ($now - $prevTs) >= 300;
					if ($moved || $stale) {
						$shouldInject    = true;
						$t['aprs_lat'] = $lat;
						$t['aprs_lon'] = $lon;
						$t['aprs_ts']  = $now;
					}
				}
				break;
			}
			unset($t);
			return $data;
		});
		if ($blocked) { http_response_code(403); echo json_encode(['error' => 'Blocked']); exit; }
		if (!$found)  { http_response_code(404); echo json_encode(['error' => 'Token not found']); exit; }
		if ($shouldInject) {
			$root = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $mobileCfg['root'] ?? ''), 0, 5));
			if ($root !== '' && $foundCallsign) injectAprsPacket($foundCallsign, $lat, $lon, $root);
			if ($foundCallsign && $lat !== null && $lon !== null)
				injectAnalyzerBeacon($foundCallsign, $foundName, $lat, $lon, time(), $_mcfg['event'] ?? '');
		}
		// Update IP record with callsign so the Clients modal can label this tracker
		if ($foundCallsign) {
			$_fh2 = @fopen('/run/aprs/recent_ips.json', 'c+');
			if ($_fh2 && flock($_fh2, LOCK_EX | LOCK_NB)) {
				$_ips2 = json_decode(stream_get_contents($_fh2), true) ?: [];
				if (isset($_ips2[_CLIENT_IP])) $_ips2[_CLIENT_IP]['cs'] = $foundCallsign;
				rewind($_fh2); ftruncate($_fh2, 0); fwrite($_fh2, json_encode($_ips2));
				flock($_fh2, LOCK_UN);
			}
			if ($_fh2) fclose($_fh2);
		}
		$resp = ['ok' => true, 'messages' => $pendingMsgs];
		if ($pendingMode !== '') $resp['set_mode'] = $pendingMode;
		echo json_encode($resp);
		exit;
	}

	if ($action === 'poll') {
		$token  = $input['token'] ?? '';
		$ackIds = array_map('intval', $input['ack_ids'] ?? []);
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }
		$pendingMsgs = [];
		modifyMobileTrackers($mobileFile, function($data) use ($token, $ackIds, &$pendingMsgs) {
			foreach ($data as &$t) {
				if (empty($t['token']) || !hash_equals($t['token'], $token)) continue;
				$pendingMsgs = $t['pending_msgs'] ?? [];
				if ($ackIds) $t['pending_msgs'] = array_values(array_filter($t['pending_msgs'] ?? [], fn($m) => !in_array((int)$m['id'], $ackIds)));
				break;
			}
			unset($t);
			return $data;
		});
		echo json_encode(['messages' => $pendingMsgs]);
		exit;
	}

	if ($action === 'msghistory') {
		$token = $input['token'] ?? '';
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }
		$callsign = null;
		$fh = fopen($mobileFile, 'r');
		if ($fh) { flock($fh, LOCK_SH); $d = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
			$trackers = isset($d['trackers']) ? $d['trackers'] : $d;
			foreach ($trackers as $t) { if (!empty($t['token']) && hash_equals($t['token'], $token)) { $callsign = $t['callsign'] ?? null; break; } } }
		if (!$callsign) { http_response_code(404); echo json_encode(['error' => 'Token not found']); exit; }
		$cfgReal = realpath('config.yaml');
		$messagesFile = $cfgReal ? dirname($cfgReal) . '/messages.json' : null;
		$msgs = [];
		if ($messagesFile && file_exists($messagesFile)) {
			$fh = fopen($messagesFile, 'r');
			if ($fh) { flock($fh, LOCK_SH); $all = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
				foreach ($all as $m) {
					if ($m['from'] === $callsign || $m['to'] === $callsign || !empty($m['broadcast'])) $msgs[] = $m;
				}
				$msgs = array_slice($msgs, -20);
			}
		}
		echo json_encode(['messages' => $msgs]);
		exit;
	}

	if ($action === 'message') {
		$token = $input['token'] ?? '';
		$text  = substr(trim($input['text'] ?? ''), 0, 280);
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }
		if (!$text)  { http_response_code(400); echo json_encode(['error' => 'Message required']); exit; }
		$found = null;
		$fh = fopen($mobileFile, 'r');
		if ($fh) { flock($fh, LOCK_SH); $d = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
			$trackers = isset($d['trackers']) ? $d['trackers'] : $d;
			foreach ($trackers as $t) { if (!empty($t['token']) && hash_equals($t['token'], $token)) { $found = $t; break; } } }
		if (!$found) { http_response_code(404); echo json_encode(['error' => 'Token not found']); exit; }
		$webSessionsFile = '/run/aprs/web_sessions.json';
		$hasActiveReceiver = false;
		if (file_exists($webSessionsFile)) {
			$fh = fopen($webSessionsFile, 'r');
			if ($fh) {
				flock($fh, LOCK_SH); $sessions = json_decode(stream_get_contents($fh), true) ?: []; flock($fh, LOCK_UN); fclose($fh);
				foreach ($sessions as $s) {
					if (!empty($s['ts']) && (time() - (int)$s['ts']) < 60) { $hasActiveReceiver = true; break; }
				}
			}
		}
		if (!$hasActiveReceiver) {
			http_response_code(503);
			echo json_encode(['error' => 'no_receivers', 'message' => 'No one is currently monitoring messages. Try again later.']);
			exit;
		}
		$cfgReal = realpath('config.yaml');
		$messagesFile = $cfgReal ? dirname($cfgReal) . '/messages.json' : null;
		$msgId = null;
		if ($messagesFile) {
			$fh = fopen($messagesFile, 'c+');
			if ($fh) {
				flock($fh, LOCK_EX);
				$msgs = json_decode(stream_get_contents($fh), true) ?: [];
				$msgId = (int)(end($msgs)['id'] ?? 0) + 1;
				$msgs[] = ['id' => $msgId, 'ts' => time(), 'from' => $found['callsign'],
				           'from_label' => $found['name'] ?? $found['callsign'],
				           'to' => 'web', 'to_label' => 'web', 'text' => $text, 'broadcast' => false];
				ftruncate($fh, 0); rewind($fh);
				fwrite($fh, json_encode($msgs, JSON_PRETTY_PRINT) . "\n");
				flock($fh, LOCK_UN); fclose($fh);
			}
		}
		echo json_encode(['ok' => true, 'id' => $msgId]);
		exit;
	}

	if ($action === 'leave') {
		$token = $input['token'] ?? '';
		if (!$token) { http_response_code(400); echo json_encode(['error' => 'Missing token']); exit; }
		modifyMobileTrackers($mobileFile, function($data) use ($token) {
			return array_values(array_filter($data, function($t) use ($token) {
				// Remove the matching entry entirely; fresh join gets a fresh ID and callsign.
				return empty($t['token']) || !hash_equals($t['token'], $token);
			}));
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
$_eventName  = $_cfg['event'] ?? '';
$_eventPw    = trim($_cfg['event_password'] ?? '');
$_lat  = isset($_m['lat'])  ? (float)$_m['lat']  : 37.5;
$_lon  = isset($_m['lon'])  ? (float)$_m['lon']  : -122.0;
$_zoom = isset($_m['zoom']) ? (int)$_m['zoom']   : 10;

// ── Event password gate ───────────────────────────────────────────────────────
// Uses signed cookies (no PHP sessions) so the auth persists a full 24 hours
// regardless of the system cron that purges PHP session files every ~24 minutes.
if ($_eventPw !== '') {
    $__cookieSecs  = 86400; // 24 hours
    $__cookieName  = 'map_auth';
    $__opCookie    = 'map_op';
    $__cookieVal   = hash_hmac('sha256', $_eventPw, 'marsaprs_auth_k6drk');
    $__isAuthed    = isset($_COOKIE[$__cookieName]) && hash_equals($__cookieVal, $_COOKIE[$__cookieName]);
    $__cookieOpts  = ['expires' => time() + $__cookieSecs, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true];
    $_pwError      = false;
    // Auto-authenticate via ?autologin parameter (Display Pi kiosk)
    if (isset($_GET['autologin'])) {
        setcookie($__cookieName, $__cookieVal, $__cookieOpts);
        if (!empty($_GET['operator'])) {
            setcookie($__opCookie, trim($_GET['operator']), $__cookieOpts);
        }
        // Preserve non-autologin params (e.g. ?kiosk=1) in the redirect
        $params = $_GET;
        unset($params['autologin'], $params['operator']);
        $base = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $base . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['map_event_pw'])) {
        if ($_POST['map_event_pw'] === $_eventPw) {
            setcookie($__cookieName, $__cookieVal, $__cookieOpts);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $_pwError = true;
    }
    if (!$__isAuthed) {
        $esc = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>MARS APRS Map</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#1a2a3a;min-height:100dvh;display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
.gate{background:#fff;border-radius:12px;padding:36px 32px;width:100%;max-width:380px;box-shadow:0 8px 40px rgba(0,0,0,0.45)}
.gate-logo{background:#2c3e50;color:#fff;border-radius:8px 8px 0 0;margin:-36px -32px 28px;padding:22px 28px}
.gate-logo h1{font-size:17px;font-weight:700;letter-spacing:.01em}
.gate-logo p{font-size:13px;opacity:.75;margin-top:4px}
label{display:block;font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
input[type=password]{width:100%;padding:11px 13px;border:1.5px solid #ccc;border-radius:6px;font-size:15px;outline:none;transition:border-color .15s}
input[type=password]:focus{border-color:#2980b9}
.err{color:#c0392b;font-size:13px;margin-top:8px;min-height:20px}
button{width:100%;margin-top:20px;padding:12px;background:#2980b9;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.02em}
button:hover{background:#2471a3}
.cancel{display:block;text-align:center;margin-top:14px;font-size:13px;color:#888;cursor:pointer;text-decoration:none}
.cancel:hover{color:#555}
</style>
</head>
<body>
<div class="gate">
  <div class="gate-logo">
    <h1>MARS APRS Tracker</h1>
    <?php if ($_eventName !== ''): ?><p><?= $esc($_eventName) ?></p><?php endif; ?>
  </div>
  <form method="POST">
    <label for="epw">Event Password</label>
    <input type="password" id="epw" name="map_event_pw" autofocus autocomplete="current-password" placeholder="">
    <div class="err"><?= $_pwError ? 'Incorrect password — please try again.' : '' ?></div>
    <button type="submit">Enter</button>
  </form>
  <a class="cancel" onclick="history.back()">Cancel</a>
</div>
</body>
</html><?php
        exit;
    }
}

$_autoOp    = isset($__opCookie) ? ($_COOKIE[$__opCookie] ?? null) : null;
$_autoMsgPw = ($_autoOp && !empty(trim($_cfg['messaging_password'] ?? '')))
              ? trim($_cfg['messaging_password']) : null;
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
<?php if ($_autoOp): ?>
<script>
window._aprsAutoOp    = <?= json_encode($_autoOp) ?>;
window._aprsAutoMsgPw = <?= json_encode($_autoMsgPw) ?>;
</script>
<?php endif; ?>
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
#noloc-modal {
    display: none; position: fixed; inset: 0; z-index: 19000;
    align-items: center; justify-content: center;
    background: rgba(0,0,0,0.45);
}
#noloc-box {
    background: #fff; border-radius: 10px; padding: 24px 28px;
    max-width: 320px; width: 90%; box-shadow: 0 6px 24px rgba(0,0,0,.3);
    text-align: center;
}
#noloc-title { font-size: 15px; font-weight: 700; margin-bottom: 8px; color: #222; }
#noloc-body  { font-size: 13px; color: #555; line-height: 1.5; margin-bottom: 18px; }
#noloc-ok {
    background: #2c3e50; color: #fff; border: none; border-radius: 6px;
    padding: 8px 28px; font-size: 13px; cursor: pointer;
}
#noloc-ok:hover { background: #3d5166; }

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

/* Desktop lower-right corner icon buttons (reset + my location) */
.map-corner-btns { display: flex; flex-direction: column; gap: 6px; margin-bottom: 6px; }
.map-corner-btn {
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.92);
    color: #555;
    border: 1px solid #bbb;
    border-radius: 6px;
    cursor: pointer;
    box-shadow: 0 1px 4px rgba(0,0,0,.2);
    pointer-events: auto;
    transition: background 0.1s, color 0.1s;
}
.map-corner-btn:hover { background: #fff; color: #222; }
@keyframes corner-btn-blink { 0%,100% { opacity:1; } 50% { opacity:0.25; } }
.map-corner-btn.locating { animation: corner-btn-blink 1s ease-in-out infinite; }

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
.aprs-hide-trackers .tracker-label        { display: none !important; }
.aprs-hide-tracker-labels .tracker-label  { display: none !important; }
.aprs-hide-aid-labels .aid-station-label  { display: none !important; }
.aprs-hide-igate-labels .igate-label      { display: none !important; }
.aprs-hide-tracker-labels .tracker-label.label-force-show  { display: block !important; }
.aprs-hide-aid-labels .aid-station-label.label-force-show  { display: block !important; }
.aprs-hide-igate-labels .igate-label.label-force-show      { display: block !important; }
.sec-label-btn {
    background: none; border: none; padding: 0 3px 0 0; margin: 0; cursor: pointer;
    color: #888; display: flex; align-items: center; line-height: 1;
}
.sec-label-btn:hover { color: #333; }
.sec-label-btn.labels-off { color: #bbb; }
.section-dimmed { opacity: 0.35; }
.section-divider { border: none; border-top: 1px solid #ccc; margin: 6px 0 4px; }

.legend-item {
    display: flex; align-items: center;
    padding: 2px 4px; border-radius: 4px; cursor: default; margin-bottom: 1px;
}
.legend-item.clickable { cursor: pointer; }
.legend-item.clickable:hover { background: #e0e0e0; }
.legend-item.selected  { background: #d0e8ff; }
.legend-dot  { width: 12px; height: 12px; border-radius: 50%; border: 1px solid #333; flex-shrink: 0; margin-right: 7px; }
.legend-text { font-size: 13px; line-height: 1.3; flex: 1; }
.legend-id   { }
.legend-name { color: #444; }
.legend-sub  { font-size: 11px; color: #888; }
.legend-time { font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; flex-shrink: 0; margin-left: 3px; }

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
.course-checkbox, .sec-vis-cb {
    appearance: none; -webkit-appearance: none;
    width: 14px; height: 14px; flex-shrink: 0; margin: 0;
    border: 1.5px solid #aaa; border-radius: 2px; background: #fff;
}
.course-checkbox { cursor: pointer; }
.sec-vis-cb      { cursor: pointer; }
.course-checkbox:checked,
.sec-vis-cb:checked {
    border-color: #aaa;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpolyline points='1.5,6 4.5,9.5 10.5,2.5' stroke='%23aaa' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: center; background-size: 10px;
}
.bg-radio {
    appearance: none; -webkit-appearance: none;
    width: 14px; height: 14px; flex-shrink: 0; margin: 0;
    border: 1.5px solid #aaa; border-radius: 50%; background: #fff;
    pointer-events: none;
}
.bg-radio:checked {
    border-color: #2980b9; background-color: #2980b9;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Ccircle cx='6' cy='6' r='2.8' fill='%23fff'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: center; background-size: 10px;
}

#sidebar-btn-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 5px; margin-top: 8px;
}
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

/* Sharing badge: fixed top-right on desktop */
#sharing-badge {
    display: none; position: fixed;
    top: 10px; right: 10px; z-index: 1400;
    background: #c0392b; color: #fff;
    font-size: 11px; font-family: arial, helvetica, sans-serif;
    padding: 3px 8px; border-radius: 10px;
    pointer-events: none;
}

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
    #sidebar-btn-grid { gap: 6px; margin-top: 10px; }
    .course-checkbox, .bg-radio { width: 18px; height: 18px; }
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

    /* Sharing badge: below gear button on mobile */
    #sharing-badge {
        display: none; position: absolute;
        top: max(54px, calc(env(safe-area-inset-top) + 44px));
        right: max(10px, env(safe-area-inset-right));
        z-index: 1400;
        background: #c0392b; color: #fff;
        font-size: 11px; font-family: arial, helvetica, sans-serif;
        padding: 3px 8px; border-radius: 10px;
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
        display: flex; align-items: center;
        padding: 0 10px; min-height: 26px;
        cursor: pointer; user-select: none; -webkit-user-select: none;
    }
    .m-legend-item:active   { background: #f0f6ff; }
    .m-legend-item.selected { background: #e8f2ff; }
    .m-dot  { width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid #333; flex-shrink: 0; margin-right: 8px; }
    .m-id   { font-size: 12px; min-width: 22px; margin-right: 8px; }
    .m-name { font-size: 12px; flex: 1; color: #222; }
    .m-time { font-size: 10px; color: #888; white-space: nowrap; font-variant-numeric: tabular-nums; margin-left: 3px; }

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
        border-color: #2980b9; background-color: #2980b9;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Ccircle cx='6' cy='6' r='2.8' fill='%23fff'/%3E%3C/svg%3E");
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
    padding: 0; min-width: 280px; max-width: 420px; width: 90%;
    max-height: 90vh; display: flex; flex-direction: column;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px;
}
#clients-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 18px 12px; font-size: 14px; font-weight: bold;
    border-bottom: 1px solid #eee; flex-shrink: 0;
}
#clients-body {
    overflow-y: auto; padding: 14px 18px 18px; flex: 1;
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
.help-modal-btn {
    display: block; width: 100%; padding: 9px 0; margin-top: 10px;
    background: #f0f4f8; border: 1px solid #bbb; border-radius: 5px;
    font-size: 13px; font-family: arial,helvetica,sans-serif;
    color: #333; text-align: center; text-decoration: none; cursor: pointer;
}
.help-modal-btn:hover { background: #e0e8f0; }

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
.mjoin-chip { padding:5px 12px;border:1px solid #ccc;border-radius:16px;background:#f0f0f0;color:#555;font-size:12px;cursor:pointer;font-family:inherit; }
.mjoin-chip-sel { background:#37526d;color:#fff;border-color:#37526d; }

#mchange-modal { position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center; }
#mchange-backdrop { position:absolute;inset:0;background:rgba(0,0,0,0.45); }
#mchange-box {
    position:relative;background:#fff;border-radius:8px;
    padding:18px 22px;min-width:260px;max-width:320px;width:88%;
    box-shadow:0 4px 24px rgba(0,0,0,.35);z-index:1;
    font-family:arial,helvetica,sans-serif;font-size:13px;
}
#mchange-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;font-size:14px;font-weight:bold; }
#mchange-close { background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:#888;padding:0 2px; }
#mchange-close:hover { color:#000; }
#mchange-stop {
    width:100%;padding:9px;background:#c0392b;color:#fff;
    border:none;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:8px;
}
#mchange-stop:hover { background:#a93226; }
#mchange-cancel {
    width:100%;padding:9px;background:none;color:#555;
    border:1px solid #ccc;border-radius:5px;font-size:13px;cursor:pointer;
}
#mchange-cancel:hover { background:#f5f5f5; }

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

#mshare-info-modal {
    position: fixed; inset: 0; z-index: 10001;
    display: flex; align-items: center; justify-content: center;
}
#mshare-info-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
#mshare-info-box {
    position: relative; background: #fff; border-radius: 8px;
    padding: 20px 24px; min-width: 240px; max-width: 320px; width: 88%;
    box-shadow: 0 4px 24px rgba(0,0,0,.35); z-index: 1;
    font-family: arial, helvetica, sans-serif; font-size: 13px;
}
#mshare-info-title { font-size: 14px; font-weight: bold; margin-bottom: 12px; color: #1a6fa0; text-align: center; }
#mshare-info-body { color: #444; line-height: 1.6; margin-bottom: 16px; }
#mshare-info-body a { color: #2980b9; }
#mshare-info-ok {
    display: block; margin: 0 auto; padding: 8px 28px;
    background: #2980b9; color: #fff;
    border: none; border-radius: 5px; font-size: 13px; font-weight: 600; cursor: pointer;
}
#mshare-info-ok:hover { background: #1a6fa0; }

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

/* ── Messaging ──────────────────────────────────────────────────────────── */
#msg-sub-modal, #msg-compose-modal {
    position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 9000;
}
#msg-sub-backdrop, #msg-compose-backdrop {
    position: absolute; inset: 0; background: rgba(0,0,0,0.45);
}
#msg-sub-box, #msg-compose-box {
    position: relative; background: #fff; border-radius: 8px; padding: 18px 20px 16px;
    width: 480px; max-width: calc(100vw - 32px); z-index: 1; box-shadow: 0 4px 24px rgba(0,0,0,0.25);
}
#msg-sub-header, #msg-compose-header {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 15px; font-weight: 600; margin-bottom: 14px;
}
#msg-sub-header button, #msg-compose-header button {
    background: none; border: none; font-size: 20px; cursor: pointer; color: #888; padding: 0 2px; line-height: 1;
}
#msg-sub-submit, #msg-compose-send {
    width: 100%; padding: 9px; background: #2980b9; color: #fff;
    border: none; border-radius: 5px; font-size: 14px; cursor: pointer; font-family: inherit;
}
#msg-sub-submit:hover, #msg-compose-send:hover { background: #2471a3; }

#msg-incoming-modal {
    position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 9500;
}
#msg-incoming-backdrop {
    position: absolute; inset: 0; background: rgba(0,0,0,0.55);
}
#msg-incoming-box {
    position: relative; background: #fff; border-radius: 8px; padding: 18px 20px 16px;
    width: 480px; max-width: calc(100vw - 32px); z-index: 1;
    box-shadow: 0 4px 24px rgba(0,0,0,0.25);
    animation: msg-pop-in 0.15s ease;
}
@keyframes msg-pop-in { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
#msg-incoming-header {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 15px; font-weight: 700; color: #1a5276; margin-bottom: 12px;
}
#msg-incoming-header button {
    background: none; border: none; font-size: 20px; cursor: pointer; color: #888; padding: 0 2px; line-height: 1;
}
#msg-incoming-reply {
    flex: 1; padding: 9px; background: #2980b9; color: #fff;
    border: none; border-radius: 5px; font-size: 14px; cursor: pointer; font-family: inherit;
}
#msg-incoming-reply:hover { background: #2471a3; }
#msg-incoming-ok {
    flex: 1; padding: 9px; background: #e8e8e8; color: #333;
    border: 1px solid #ccc; border-radius: 5px; font-size: 14px; cursor: pointer; font-family: inherit;
}
#msg-incoming-ok:hover { background: #d8d8d8; }
.msg-thread-entry { padding: 4px 0; border-bottom: 1px solid #ececec; line-height: 1.4; }
.msg-thread-entry:last-child { border-bottom: none; }
.msg-thread-from { font-weight: 600; color: #333; }
.msg-thread-web { color: #1a5276; }
.msg-thread-time { color: #aaa; font-size: 10px; margin-left: 6px; }
</style>
</head>
<body>

<!-- ── Desktop sidebar ─────────────────────────────────────────────────── -->
<div id="sidebar">
	<div id="sidebar-scroll">
		<div class="sec-hdr open" data-body="legend"><span>Trackers</span><span class="sec-hdr-right"><button class="sec-label-btn" data-section="trackers" title="Toggle labels"></button><input type="checkbox" class="sec-vis-cb" checked data-section="trackers"></span></div>
		<div id="legend"></div>

		<div id="courses-section" style="display:none">
			<div class="sec-hdr open" data-body="courses"><span>Courses</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="courses"></span></div>
			<div id="courses"></div>
		</div>

		<div id="aidstations-section" style="display:none">
			<div class="sec-hdr open" data-body="aidstations"><span>Aid/Rest Stops</span><span class="sec-hdr-right"><button class="sec-label-btn" data-section="aidstations" title="Toggle labels"></button><input type="checkbox" class="sec-vis-cb" checked data-section="aidstations"></span></div>
			<div id="aidstations"></div>
		</div>

		<div id="igates-section" style="display:none">
			<div class="sec-hdr open" data-body="igates"><span>iGates</span><span class="sec-hdr-right"><button class="sec-label-btn" data-section="igates" title="Toggle labels"></button><input type="checkbox" class="sec-vis-cb" checked data-section="igates"></span></div>
			<div id="igates"></div>
		</div>

		<div id="backgrounds-section" style="display:none">
			<div class="sec-hdr open" data-body="backgrounds"><span>Backgrounds</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="backgrounds"></span></div>
			<div id="backgrounds"></div>
		</div>
	</div>

	<div id="sidebar-footer">
		<hr class="section-divider">
		<div id="sidebar-btn-grid">
			<button id="about-btn" class="sidebar-btn">Help</button>
			<button id="save-map-btn" class="sidebar-btn">Save Map</button>
			<a href="?kiosk=1" id="kiosk-btn" class="sidebar-btn">Kiosk Mode</a>
			<button id="share-loc-btn" class="sidebar-btn" style="display:none">Share Location</button>
			<a href="admin/" id="admin-btn" class="sidebar-btn">Admin</a>
			<button id="msg-messaging-btn" class="sidebar-btn" style="display:none">Messaging</button>
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

<!-- ── Sharing badge (top-right, all layouts) ──────────────────────────── -->
<div id="sharing-badge">Sharing</div>

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
				<span>Aid/Rest Stops</span><span class="sec-hdr-right"><input type="checkbox" class="sec-vis-cb" checked data-section="aidstations"></span>
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
				<span>Help</span>			</div>
			<div id="m-about-body" style="display:none"></div>
		</div>
	</div>
	<div id="drawer-footer">
		<div class="m-actions-grid">
			<button id="m-reset-btn" class="m-action-btn">Reset Map</button>
			<button id="m-save-map-btn" class="m-action-btn">Save Map</button>
			<a href="admin/" class="m-action-btn">Admin</a>
			<button id="m-help-btn" class="m-action-btn">Help</button>
			<button id="m-share-loc-btn" class="m-action-btn" style="display:none">Share Location</button>
		</div>
	</div>
</div><!-- #mobile-drawer -->

<div id="about-modal" style="display:none">
	<div id="about-backdrop"></div>
	<div id="about-box">
		<div id="about-header">
			<span>Help</span>
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
		<label class="mjoin-label" for="mjoin-name">Your first name (12 characters max)</label>
		<input id="mjoin-name" class="mjoin-input" type="text" maxlength="12" autocomplete="off" autocorrect="off" spellcheck="false">
		<label class="mjoin-label" for="mjoin-pin">PIN code</label>
		<input id="mjoin-pin" class="mjoin-input" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
		<div style="border-top:1px solid #e8e8e8;margin:10px 0 8px;padding-top:10px">
			<div style="color:#999;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px">Ham Radio <span style="font-weight:normal;text-transform:none;letter-spacing:normal;color:#bbb">— optional</span></div>
			<div style="display:flex;gap:8px">
				<div style="flex:1">
					<label class="mjoin-label" for="mjoin-ham-call">Callsign</label>
					<input id="mjoin-ham-call" class="mjoin-input" type="text" maxlength="10" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false" placeholder="e.g. K6DRK">
				</div>
				<div style="width:72px">
					<label class="mjoin-label" for="mjoin-ham-ssid">SSID (1–15)</label>
					<input id="mjoin-ham-ssid" class="mjoin-input" type="number" min="1" max="15" style="padding:7px 6px" placeholder="1–15">
				</div>
			</div>
		</div>
		<div id="mjoin-error"></div>
		<button id="mjoin-submit">Share Location</button>
		<button id="mjoin-cancel" style="width:100%;padding:9px;background:none;color:#555;border:1px solid #ccc;border-radius:5px;font-size:13px;cursor:pointer;margin-top:6px">Cancel</button>
	</div>
</div>

<div id="mchange-modal" style="display:none">
	<div id="mchange-backdrop"></div>
	<div id="mchange-box">
		<div id="mchange-header">
			<span>Location Sharing</span>
			<button id="mchange-close">&times;</button>
		</div>
		<button id="mchange-stop">Stop Sharing</button>
		<button id="mchange-cancel">Keep Sharing</button>
	</div>
</div>

<div id="noloc-modal" style="display:none">
  <div id="noloc-box">
    <div id="noloc-title"></div>
    <div id="noloc-body">No location data has been received for this tracker yet.</div>
    <button id="noloc-ok">OK</button>
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

<div id="mshare-info-modal" style="display:none">
	<div id="mshare-info-backdrop"></div>
	<div id="mshare-info-box">
		<div id="mshare-info-title">Location Sharing Started</div>
		<div id="mshare-info-body"></div>
		<button id="mshare-info-ok">OK</button>
	</div>
</div>

<!-- ── Messaging: subscribe modal ─────────────────────────────────────────── -->
<div id="msg-sub-modal" style="display:none">
	<div id="msg-sub-backdrop"></div>
	<div id="msg-sub-box">
		<div id="msg-sub-header"><span>Subscribe to Messages</span><button id="msg-sub-close">&times;</button></div>
		<label class="mjoin-label" for="msg-sub-name">Your name or role (e.g. "Net Control")</label>
		<input id="msg-sub-name" class="mjoin-input" type="text" maxlength="30" autocomplete="off" autocorrect="off" spellcheck="false">
		<label class="mjoin-label" for="msg-sub-pw">Messaging password</label>
		<input id="msg-sub-pw" class="mjoin-input" type="password" autocomplete="off">
		<div id="msg-sub-error" style="color:#c0392b;font-size:13px;min-height:18px;margin-bottom:6px"></div>
		<button id="msg-sub-submit">Subscribe</button>
	</div>
</div>

<!-- ── Messaging: compose modal ───────────────────────────────────────────── -->
<div id="msg-compose-modal" style="display:none">
	<div id="msg-compose-backdrop"></div>
	<div id="msg-compose-box">
		<div id="msg-compose-header"><span id="msg-compose-title">Send Message</span><button id="msg-compose-close">&times;</button></div>
		<div id="msg-compose-to" style="font-size:13px;color:#555;margin-bottom:8px"></div>
		<div id="msg-compose-thread" style="display:none;max-height:280px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:4px;padding:6px 8px;margin-bottom:10px;background:#f9f9f9;font-size:12px"></div>
		<textarea id="msg-compose-text" maxlength="280" rows="3" placeholder="Type your message…" style="width:100%;box-sizing:border-box;font-size:14px;font-family:inherit;border:1px solid #ccc;border-radius:4px;padding:8px;resize:vertical"></textarea>
		<div style="display:flex;align-items:center;gap:8px;margin-top:8px">
			<label style="font-size:13px;display:flex;align-items:center;gap:5px;cursor:pointer">
				<input type="checkbox" id="msg-compose-broadcast"> Send to all trackers
			</label>
		</div>
		<div id="msg-compose-error" style="color:#c0392b;font-size:13px;min-height:18px;margin-top:6px"></div>
		<button id="msg-compose-send" style="margin-top:8px">Send</button>
		<div style="margin-top:12px;padding-top:10px;border-top:1px solid #eee;display:flex;justify-content:space-between">
			<a href="#" id="msg-compose-rename-link" style="font-size:12px;color:#555;text-decoration:none">Change my name</a>
			<a href="#" id="msg-compose-disable-link" style="font-size:12px;color:#c0392b;text-decoration:none">Disable messaging</a>
		</div>
		<div id="msg-rename-panel" style="display:none;margin-top:10px">
			<label style="font-size:12px;color:#555">New name:</label>
			<input id="msg-rename-input" type="text" maxlength="30" autocomplete="off" autocorrect="off" spellcheck="false" style="width:100%;box-sizing:border-box;margin-top:4px;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;font-family:inherit">
			<div id="msg-rename-error" style="color:#c0392b;font-size:12px;min-height:14px;margin-top:4px"></div>
			<div style="display:flex;gap:8px;margin-top:8px">
				<button id="msg-rename-save">Save Name</button>
				<button id="msg-rename-cancel" style="background:#eee;color:#444;border-color:#ccc">Cancel</button>
			</div>
		</div>
	</div>
</div>

<!-- ── Messaging: incoming message modal ──────────────────────────────────── -->
<div id="msg-incoming-modal" style="display:none">
	<div id="msg-incoming-backdrop"></div>
	<div id="msg-incoming-box">
		<div id="msg-incoming-header"><span>📨 Message</span><button id="msg-incoming-close">&times;</button></div>
		<div id="msg-incoming-from" style="font-weight:700;font-size:14px;margin-bottom:6px"></div>
		<div id="msg-incoming-thread" style="display:none;max-height:280px;overflow-y:auto;font-size:12px"></div>
		<div id="msg-incoming-text" style="font-size:15px;line-height:1.5;margin-bottom:14px"></div>
		<div style="display:flex;gap:8px">
			<button id="msg-incoming-reply" style="display:none">Reply</button>
			<button id="msg-incoming-ok">Close</button>
		</div>
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

// ── Desktop corner buttons: My Location + Reset Map ───────────────────────
if (!isMobile) {
	new (L.Control.extend({
		options: { position: 'bottomright' },
		onAdd() {
			const wrap = L.DomUtil.create('div', 'map-corner-btns');

			// My Location button
			const locBtn = L.DomUtil.create('button', 'map-corner-btn', wrap);
			locBtn.title = 'Zoom to my location';
			locBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>';
			if (!navigator.geolocation) {
				locBtn.style.opacity = '0.35';
				locBtn.title = 'Geolocation not available';
			} else {
				L.DomEvent.on(locBtn, 'click', () => {
					if (locBtn.classList.contains('locating')) return;
					locBtn.classList.add('locating');
					navigator.geolocation.getCurrentPosition(
						pos => {
							locBtn.classList.remove('locating');
							map.flyTo([pos.coords.latitude, pos.coords.longitude], 15);
						},
						err => {
							locBtn.classList.remove('locating');
							const msg = err.code === 1
								? 'Location access denied — enable it in your browser settings.'
								: 'Location unavailable — check that your browser has permission to access your location.';
							_mapTip(msg);
						},
						{ timeout: 8000, maximumAge: 60000 }
					);
				});
			}
			L.DomEvent.disableClickPropagation(wrap);

			// Reset Map button
			const resetBtn = L.DomUtil.create('button', 'map-corner-btn', wrap);
			resetBtn.title = 'Reset Map';
			resetBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
			L.DomEvent.on(resetBtn, 'click', () => {
				clearAllSelections();
				map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
			});

			return wrap;
		}
	}))().addTo(map);
}

map.createPane('coursePane');
map.getPane('coursePane').style.zIndex = 410;
const courseRenderer = L.svg({ pane: 'coursePane' });
map.createPane('historyPane');
map.getPane('historyPane').style.zIndex = 445;
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
			txt.innerHTML = '&ensp;Marin Amateur Radio Society APRS Tracking v1.19.1+8 &copy; 2026 Doug Kaye (K6DRK)';
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
				? 'MARS APRS v1.19.1+8 &copy; 2026 Doug Kaye (K6DRK)'
				: '&ensp;Marin Amateur Radio Society APRS Tracking v1.19.1+8 &copy; 2026 Doug Kaye (K6DRK)';
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
let   _blinkDuration       = 5000; // ms; overridden by blink_duration from ?json
let   _breadcrumbCount     = 100;  // overridden by breadcrumb_count from ?json
let   _histBlinkTimer      = null; // setInterval handle for history-dot blinking
// Beacon settings; overridden from ?config mobile_beacons
let _beaconIntervalMs = [60000, 30000, 15000, 120000, 60000]; // Walk, Cycle, Drive, Stationary, Unknown
let _beaconDistMi     = [0.2,   0.2,   0.2,   1.0,   0.2];   // Walk, Cycle, Drive, Stationary, Unknown
const lastIgateBeacons     = {};	// callsign → lastBeacon timestamp (from igates.json)
const igateFlashTimers     = {};	// callsign → setTimeout id for green blink
const lastAidBeacons       = {};	// callsign → lastBeacon timestamp (from aidstations.json)
const aidFlashTimers       = {};	// callsign → setTimeout id for green blink
const historyDots          = {};	// callsign → [L.circleMarker, ...]
// APRS destination-field device-type codes → human-readable radio model names
const APRS_DEVICES = {
	APK003:'Kenwood TH-D7A', APK004:'Kenwood TH-D72A/TM-D710', APK005:'Kenwood TH-D74A',
	APY001:'Yaesu VX-8G',    APY008:'Yaesu FT1D/FT2D',         APY300:'Yaesu FTM-350',
	APY350:'Yaesu FTM-350R', APY400:'Yaesu FTM-400XDR',        APYS:'Yaesu HT',
	APDR:'APRSDroid',        APDW:'DireWolf',                   APXG:'Xastir',
	APAG:'AGWtracker',       APOT:'OpenTracker',                APTT4:'TinyTrak4',
	APUDR:'Uniden DR',       APTR:'Pocket Tracker',             APW:'WinAPRS',
};
function aprsDeviceName(code) {
	if (!code || code === 'APRS') return null;
	if (APRS_DEVICES[code]) return APRS_DEVICES[code];
	// Prefix match (e.g. APDR15 → APRSDroid)
	for (const [prefix, name] of Object.entries(APRS_DEVICES)) {
		if (prefix.length >= 3 && code.startsWith(prefix)) return name;
	}
	return code; // show raw code if unrecognised
}
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

// ── Mobile sharing state — declared here so refreshMobileAbout() (called
// during drawer setup below) can safely reference mobileCallsign without
// hitting the temporal dead zone.
let mobileTrackingInitialized = false;
let mobileToken    = null;
let mobileId       = null;
let mobileCallsign = null;
let mobileWatcher  = null;
let mobileWakeLock = null;

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

// ── Page-level wake lock (keeps screen on while map is visible) ───────────
// Acquired for all views (including Tesla desktop browser) to prevent the
// screen from dimming due to browser idle timeout.
if ('wakeLock' in navigator) {
	let _pageWakeLock = null;
	async function _acquirePageWakeLock() {
		if (_pageWakeLock) return;
		try { _pageWakeLock = await navigator.wakeLock.request('screen'); } catch {}
	}
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') _acquirePageWakeLock();
		else { _pageWakeLock?.release(); _pageWakeLock = null; }
	});
	_acquirePageWakeLock();
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

function trackerIconSize(callsign) {
	const base = Math.max(4, Math.round(trackerStyle.size * markerScale()));
	return (callsign === selectedCallsign && trackerClickCount === 1) ? Math.round(base * 2) : base;
}
function refreshTrackerIcon(callsign) {
	const m = markers[callsign];
	if (!m) return;
	const enlarged = callsign === selectedCallsign && trackerClickCount === 1;
	m.setIcon(makeTrackerIcon(m._mobile && m._hamCallsign ? 'triangle' : m._mobile ? 'square' : 'circle', m._trackerColor, trackerIconSize(callsign)));
	const tipEl = m.getTooltip()?.getElement();
	if (tipEl) tipEl.classList.toggle('label-force-show', enlarged);
}
function refreshIgateRadius(idx) {
	const d = igateMarkers[idx]; if (!d) return;
	const enlarged = selectedIgateIdx === idx && igateClickCount === 1;
	d.m.setRadius(enlarged ? Math.round(scaledRadius(d.m._baseRadius) * 2) : scaledRadius(d.m._baseRadius));
	const tipEl = d.m.getTooltip()?.getElement();
	if (tipEl) tipEl.classList.toggle('label-force-show', enlarged);
}
function refreshAidRadius(idx) {
	const d = aidMarkers[idx]; if (!d) return;
	const enlarged = selectedAidIdx === idx && aidClickCount === 1;
	d.m.setRadius(enlarged ? Math.round(scaledRadius(d.m._baseRadius) * 2) : scaledRadius(d.m._baseRadius));
	const tipEl = d.m.getTooltip()?.getElement();
	if (tipEl) tipEl.classList.toggle('label-force-show', enlarged);
}
function _deselectIgate() {
	if (selectedIgateIdx < 0) return;
	const idx = selectedIgateIdx;
	setIgateTooltip(idx, true);
	selectedIgateIdx = -1; igateClickCount = 0;
	refreshIgateRadius(idx); // restores normal radius and removes label-force-show
	const d = igateMarkers[idx];
	if (d) d.el.classList.remove('selected');
}
function _deselectAid() {
	if (selectedAidIdx < 0) return;
	const idx = selectedAidIdx;
	setAidTooltip(idx, true);
	selectedAidIdx = -1; aidClickCount = 0;
	refreshAidRadius(idx); // restores normal radius and removes label-force-show
	const d = aidMarkers[idx];
	if (d) d.el.classList.remove('selected');
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

function _mapTip(text, ms = 5000) {
	const el = document.createElement('div');
	el.textContent = text;
	el.style.cssText = 'position:absolute;bottom:80px;right:10px;z-index:1500;background:rgba(40,40,40,0.9);color:#fff;padding:8px 12px;border-radius:6px;font-size:13px;max-width:240px;line-height:1.4;pointer-events:none;';
	map.getContainer().appendChild(el);
	setTimeout(() => el.remove(), ms);
}

function popupHtml(t) {
	let html = `<b>${esc(t.name)}</b> (${esc(t.id)})<br>${esc(t.callsign)}<br>Last heard ${esc(t.time)} ago`;
	if (t.mobile) {
		const carrier = t.carrier ? ` · ${esc(t.carrier)}` : '';
		html += `<div class="popup-path" style="color:#555;font-style:italic">Mobile device${carrier}</div>`;
	} else if (t.path) {
		html += `<div class="popup-path">${formatAprsPath(t.path)}</div>`;
	}
	return html;
}

function showNoLocation(name) {
	document.getElementById('noloc-title').textContent = name;
	document.getElementById('noloc-modal').style.display = 'flex';
}
function closeNoLocation() { document.getElementById('noloc-modal').style.display = 'none'; }

function hideAllHistoryDots() {
	if (_histBlinkTimer) { clearInterval(_histBlinkTimer); _histBlinkTimer = null; }
	const _hp = map.getPane('historyPane'); if (_hp) _hp.style.opacity = '';
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

			const hamCallsign     = markers[callsign]?._hamCallsign || null;
			const isMobileTracker = markers[callsign]?._mobile || false;

			// Build a deduplicated, capped entry list for one callsign.
			const buildEntries = (cs, isCell) => {
				if (!hist[cs] || !hist[cs].length) return [];
				const raw = [...hist[cs]]
					.sort((a, b) => b.ts - a.ts)
					.map(e => ({...e, _isCell: isCell, _cs: cs}));
				const deduped = raw.filter((e, i) => i === 0 || e.lat !== raw[i-1].lat || e.lon !== raw[i-1].lon);
				if (_breadcrumbCount === 0) return [];
				return _breadcrumbCount < deduped.length ? deduped.slice(0, _breadcrumbCount) : deduped;
			};

			// For hybrid trackers, cellular and radio trails are drawn independently —
			// no cross-type segments. The cellular trail connects to the current marker;
			// the radio trail ends at its own last known position.
			const cellEntries  = buildEntries(callsign,   isMobileTracker);
			const radioEntries = hamCallsign ? buildEntries(hamCallsign, false) : [];

			if (!cellEntries.length && !radioEntries.length) return;

			if (historyDots[callsign]) { historyDots[callsign].forEach(d => d.remove()); }
			const layers = [];

			const _carrier    = markers[callsign]?._carrier    || null;
			const _radioModel = markers[callsign]?._radioModel || null;
			const _name       = markers[callsign]?._trackerName || null;
			const _id         = markers[callsign]?._trackerId   || null;

			// Draw dots + connecting lines for one trail.
			// connectToCurrent: true for cellular (extends to live marker), false for radio.
			const drawTrail = (trailEntries, connectToCurrent) => {
				const dotColor = trailEntries[0]._isCell ? '#27ae60' : '#e74c3c';

				// Dots — newest first
				trailEntries.forEach(e => {
					const dot = L.circleMarker([e.lat, e.lon], {
						radius: scaledRadius(r), color: dotColor, fillColor: dotColor,
						fillOpacity: 0.5, weight: 1.5, pane: 'historyPane'
					});
					dot._baseRadius = r;
					const tipHtml = () => {
						const time = `<div class="aprs-path-time">${esc(relativeTime(e.ts))}</div>`;
						if (e._isCell) {
							const head = `<div style="font-weight:600;margin-bottom:2px">${_name ? esc(_name) + ' ' : ''}(${esc(_id || e._cs)})</div>`;
							let th = head + time;
							if (_carrier) th += `<div style="color:#888;font-size:11px;margin-top:3px">${esc(_carrier)}</div>`;
							return th;
						}
						const label = `<div style="color:#888;font-size:11px;margin-top:3px">${esc(_radioModel || 'Radio')}</div>`;
						const head  = `<div style="font-weight:600;margin-bottom:2px">${_name ? esc(_name) + ' ' : ''}(${esc(e._cs)})</div>`;
						return `<div style="min-width:260px">${head}${time}${label}${formatAprsPath(e.path)}</div>`;
					};
					dot.addTo(map);
					layers.push(dot);
					if (isMobile) {
						const hit = L.circleMarker([e.lat, e.lon], {
							radius: Math.max(scaledRadius(r) + 12, 18), stroke: false,
							fillOpacity: 0, fillColor: dotColor, pane: 'historyPane'
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

				// Connecting lines oldest → newest [→ current marker if cellular]
				const ordered = [...trailEntries].reverse();
				const pts = ordered.map(e => [e.lat, e.lon]);
				const cur = connectToCurrent ? markers[callsign] : null;
				if (cur) pts.push([cur.getLatLng().lat, cur.getLatLng().lng]);

				for (let i = 0; i < pts.length - 1; i++) {
					const [lat1, lon1] = pts[i], [lat2, lon2] = pts[i + 1];
					layers.push(L.polyline([[lat1, lon1], [lat2, lon2]], {
						color: dotColor, weight: 3, dashArray: '4 7', opacity: 0.80, pane: 'historyPane'
					}).addTo(map));
					const brg = bearingTo(lat1, lon1, lat2, lon2);
					layers.push(L.marker([(lat1 + lat2) / 2, (lon1 + lon2) / 2], {
						icon: L.divIcon({
							html: `<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg" style="transform:rotate(${brg}deg);display:block"><polygon points="10,2 18,18 10,12 2,18" fill="${dotColor}" opacity="0.85"/></svg>`,
							className: '', iconSize: [20, 20], iconAnchor: [10, 10]
						}),
						pane: 'historyPane', interactive: false
					}).addTo(map));
				}
			};

			if (cellEntries.length)  drawTrail(cellEntries,  true);
			if (radioEntries.length) drawTrail(radioEntries, false);

			historyDots[callsign] = layers;
			if (blinkTimers[callsign]) blinkHistoryLayers(callsign, true);
		});
}

// ── Blink ─────────────────────────────────────────────────────────────────
function blinkHistoryLayers(callsign, on) {
	if (_histBlinkTimer) { clearInterval(_histBlinkTimer); _histBlinkTimer = null; }
	const pane = map.getPane('historyPane');
	if (!pane) return;
	pane.style.opacity = '';    // always restore first
	if (!on) return;
	let vis = true;
	_histBlinkTimer = setInterval(() => { vis = !vis; pane.style.opacity = vis ? '' : '0'; }, 200);
}

function triggerBlink(callsign) {
	if (blinkTimers[callsign]) clearTimeout(blinkTimers[callsign]);
	const id   = (isMobile ? 'm-legend-' : 'legend-') + callsign;
	const item = document.getElementById(id);
	if (_blinkDuration <= 0) return;
	if (item) item.classList.add('blinking');
	const el = markers[callsign]?.getElement();
	if (el) el.style.animation = 'blink-anim 0.4s steps(2,end) infinite';
	const tipEl = markers[callsign]?.getTooltip()?.getElement();
	if (tipEl) tipEl.style.animation = 'blink-anim 0.4s steps(2,end) infinite';
	// Expand tooltip to show name while blinking
	const m = markers[callsign];
	if (m && !kiosk) {
		m.setTooltipContent(m._trackerName || m._trackerId || callsign);
	}
	blinkTimers[callsign] = setTimeout(() => {
		if (item) item.classList.remove('blinking');
		const e2 = markers[callsign]?.getElement();
		if (e2) e2.style.animation = '';
		const t2 = markers[callsign]?.getTooltip()?.getElement();
		if (t2) t2.style.animation = '';
		// Collapse tooltip back to resting label
		if (markers[callsign] && !kiosk) {
			const _m = markers[callsign];
			_m.setTooltipContent(_m._trackerName || _m._trackerId || callsign);
		}
		blinkHistoryLayers(callsign, false);
		delete blinkTimers[callsign];
	}, _blinkDuration);
}

const courseBlinkTimers = {};
function triggerCourseBlink(file, nameEl) {
	if (_blinkDuration <= 0) return;
	if (courseBlinkTimers[file]) clearTimeout(courseBlinkTimers[file]);
	if (nameEl) nameEl.classList.add('blinking');
	const wasLoaded = !!courseLayers[file];

	const startBlink = () => {
		const layer = courseLayers[file];
		if (layer) layer.eachLayer(l => { const el = l.getElement?.(); if (el) el.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; });
		courseBlinkTimers[file] = setTimeout(() => {
			if (nameEl) nameEl.classList.remove('blinking');
			const l2 = courseLayers[file];
			if (l2) l2.eachLayer(l => { const el = l.getElement?.(); if (el) el.style.animation = ''; });
			if (!wasLoaded && courseLayers[file]) { map.removeLayer(courseLayers[file]); delete courseLayers[file]; }
			delete courseBlinkTimers[file];
		}, _blinkDuration);
	};

	if (wasLoaded) {
		startBlink();
	} else {
		loadCourseLayer(file);
		const layer = courseLayers[file];
		if (layer) layer.once('ready', startBlink);
	}
}

function triggerDotBlink(d) {
	if (_blinkDuration <= 0) return;
	const el = d.m.getElement();
	if (el) { el.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; setTimeout(() => { el.style.animation = ''; }, _blinkDuration); }
	const tipEl = d.m.getTooltip()?.getElement();
	if (tipEl) { tipEl.style.animation = 'blink-anim 0.4s steps(2,end) infinite'; setTimeout(() => { tipEl.style.animation = ''; }, _blinkDuration); }
	d.el.classList.add('blinking');
	setTimeout(() => d.el.classList.remove('blinking'), _blinkDuration);
}

function flashIgateBeacon(callsign) {
	if (_blinkDuration <= 0) return;
	const d = igateMarkers.find(ig => ig.callsign === callsign);
	if (!d) return;
	if (igateFlashTimers[callsign]) clearTimeout(igateFlashTimers[callsign]);
	d.el.classList.add('igate-beaconing');
	igateFlashTimers[callsign] = setTimeout(() => {
		d.el.classList.remove('igate-beaconing');
		delete igateFlashTimers[callsign];
	}, _blinkDuration);
}

function flashAidBeacon(callsign) {
	if (_blinkDuration <= 0) return;
	const d = aidMarkers.find(a => a.callsign === callsign);
	if (!d) return;
	if (aidFlashTimers[callsign]) clearTimeout(aidFlashTimers[callsign]);
	d.el.classList.add('igate-beaconing');
	aidFlashTimers[callsign] = setTimeout(() => {
		d.el.classList.remove('igate-beaconing');
		delete aidFlashTimers[callsign];
	}, _blinkDuration);
}

// ── Clear all selections ──────────────────────────────────────────────────
function clearAllSelections() {
	_deselectIgate();
	_deselectAid();
	const prevCS = selectedCallsign;
	selectedCallsign = null; trackerClickCount = 0;
	if (prevCS) refreshTrackerIcon(prevCS); // restore enlarged icon
	document.querySelectorAll('.legend-item, .m-legend-item').forEach(el => el.classList.remove('selected'));
	if (originMarker) { originMarker.remove(); originMarker = null; origin = null; }
	hideAllHistoryDots();
	map.closePopup();
}

// ── Place marker tooltip (shared by iGates and aid stations) ──────────────
function setPlaceTooltip(markers, idx, permanent, typeClass) {
	const d = markers[idx]; if (!d) return;
	d.m.unbindTooltip();
	const label = d.callsign ? `${d.name} (${d.callsign})` : d.name;
	d.m.bindTooltip(label, { permanent, direction: 'right', className: `place-label ${typeClass}`, offset: [8, 0] });
}
function setIgateTooltip(idx, permanent) { setPlaceTooltip(igateMarkers, idx, permanent, 'igate-label'); }
function setAidTooltip(idx, permanent)   { if (!kiosk) setPlaceTooltip(aidMarkers, idx, permanent, 'aid-station-label'); }

function onIgateClick(idx) {
	const d = igateMarkers[idx];
	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	if (selectedIgateIdx !== idx) {
		_deselectIgate();
		_deselectAid();
		selectedCallsign = null; trackerClickCount = 0;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		selectedIgateIdx = idx; igateClickCount = 1;
		d.el.classList.add('selected');
		setIgateTooltip(idx, true);
		refreshIgateRadius(idx);
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (igateClickCount === 1) {
		igateClickCount = 2;
		refreshIgateRadius(idx);
		map.setView(d.latlng, 15); setSheetOpen(false);
	} else {
		_deselectIgate();
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	}
}

// ── Aid station click cycle ────────────────────────────────────────────────
function onAidClick(idx) {
	const d = aidMarkers[idx];
	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	if (selectedAidIdx !== idx) {
		_deselectAid();
		_deselectIgate();
		selectedCallsign = null; trackerClickCount = 0;
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		selectedAidIdx = idx; aidClickCount = 1;
		d.el.classList.add('selected');
		setAidTooltip(idx, true);
		refreshAidRadius(idx);
		triggerDotBlink(d);
		setSheetOpen(false);
	} else if (aidClickCount === 1) {
		aidClickCount = 2;
		refreshAidRadius(idx);
		map.setView(d.latlng, 15); setSheetOpen(false);
	} else {
		_deselectAid();
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
	}
}

// ── Tracker click cycle ────────────────────────────────────────────────────
function onLegendClick(callsign) {
	_deselectIgate();
	_deselectAid();

	const legSel = isMobile ? '.m-legend-item' : '.legend-item';
	const legPfx = isMobile ? 'm-legend-'      : 'legend-';

	if (selectedCallsign !== callsign) {
		const prevCS = selectedCallsign;
		selectedCallsign = callsign; trackerClickCount = 1;
		if (prevCS) refreshTrackerIcon(prevCS); // restore previously enlarged tracker
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
		document.getElementById(legPfx + callsign)?.classList.add('selected');
		refreshTrackerIcon(callsign); // enlarge to 2x
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
		refreshTrackerIcon(callsign); // restore to normal on zoom click
		const m = markers[callsign];
		if (m) { map.setView(m.getLatLng(), 15); setSheetOpen(false); }
	} else {
		hideAllHistoryDots();
		map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);
		selectedCallsign = null; trackerClickCount = 0;
		refreshTrackerIcon(callsign); // restore to normal on reset
		document.querySelectorAll(legSel).forEach(el => el.classList.remove('selected'));
	}
}

// ── Mobile tracker tap / long-press ───────────────────────────────────────
function onMobileTrackerTap(callsign) {
	_deselectIgate();
	_deselectAid();
	const prevCS = selectedCallsign;
	selectedCallsign = callsign; trackerClickCount = 1;
	if (prevCS && prevCS !== callsign) refreshTrackerIcon(prevCS);
	document.querySelectorAll('.m-legend-item').forEach(el => el.classList.remove('selected'));
	document.getElementById('m-legend-' + callsign)?.classList.add('selected');
	hideAllHistoryDots();
	refreshTrackerIcon(callsign);
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
			item.className = 'legend-item clickable';
			item.innerHTML = `<span class="legend-dot"></span>`
			               + `<span class="legend-text"><span class="legend-id">${t.id}</span> <span class="legend-name">${t.name}</span></span>`
			               + `<span class="legend-mode" style="font-size:11px;filter:grayscale(1) brightness(0.5)"></span>`
			               + `<span class="legend-time">${t.lat===null?'—':(t.color||'red')==='red'?'stale':t.time}</span>`;
		}
		const color = t.color || 'red';
		item.querySelector('.legend-dot').style.background    = color;
		item.querySelector('.legend-dot').style.borderRadius  = t.mobile && !t.ham_callsign ? '3px' : t.mobile ? '0' : '50%';
		item.querySelector('.legend-dot').style.clipPath      = t.mobile && t.ham_callsign ? 'polygon(50% 0%,100% 100%,0% 100%)' : '';
		item.querySelector('.legend-time').style.color        = color;
		item.querySelector('.legend-id').textContent        = t.id;
		item.querySelector('.legend-name').textContent      = t.name;
		item.querySelector('.legend-mode').innerHTML        = _modeIcon(t.sharing_mode || '', t.mobile);
		item.querySelector('.legend-time').textContent      = t.lat === null ? '—' : color === 'red' ? 'stale' : t.time;
		// Update onclick every poll so beacon-status transitions take effect immediately.
		item.onclick = hasBeacon
			? () => onLegendClick(t.callsign)
			: () => showNoLocation(t.name || t.id);
		legend.appendChild(item);  // re-insert in sorted position (moves existing elements)
	});
}

function _modeIcon(mode, isMobile) {
	const s = 'vertical-align:middle';
	const b = `fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"`;
	// Running stick figure
	const walk  = `<svg viewBox="0 0 10 14" width="8" height="11" ${b} style="${s}"><circle cx="5" cy="1.8" r="1.8" fill="currentColor" stroke="none"/><path d="M5 3.5L5 8M3.5 5.5L6.5 6.5M5 8L3 12M5 8L7 12"/></svg>`;
	// Bicycle: two wheels + frame triangle + handlebar
	const cycle = `<svg viewBox="0 0 14 10" width="12" height="9" ${b} style="${s}"><circle cx="2.5" cy="7" r="2.5"/><circle cx="11.5" cy="7" r="2.5"/><path d="M2.5 7L6.5 3L11.5 7M6.5 3L6.5 1L9.5 1"/></svg>`;
	// Car profile using paths (no rect): roof slope + body bottom curve + filled wheel dots
	const drive = `<svg viewBox="0 0 18 11" width="14" height="9" ${b} style="${s}"><path d="M1 8L1 6L4 6L6 3L12 3L14 6L17 6L17 8Q17 9.5 15.5 9.5L2.5 9.5Q1 9.5 1 8Z"/><circle cx="5" cy="9.5" r="1.5" fill="currentColor" stroke="none"/><circle cx="13" cy="9.5" r="1.5" fill="currentColor" stroke="none"/></svg>`;
	// Location pin: teardrop + inner dot
	const pin   = `<svg viewBox="0 0 8 12" width="7" height="10" ${b} style="${s}"><path d="M4 11Q0.5 7 0.5 4A3.5 3.5 0 0 1 7.5 4Q7.5 7 4 11Z"/><circle cx="4" cy="4" r="1.2" fill="currentColor" stroke="none"/></svg>`;
	// Radio/antenna waves (for fixed trackers)
	const radio = `<svg viewBox="0 0 12 10" width="11" height="9" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" style="${s}"><circle cx="6" cy="9" r="1.1" fill="currentColor" stroke="none"/><path d="M3.5 7.5Q6 5 8.5 7.5"/><path d="M1 4.5Q6 0 11 4.5"/></svg>`;
	if (mode === 'drive' || mode === 'drive_cycle') return drive;
	if (mode === 'cycle')      return cycle;
	if (mode === 'walk_run')   return walk;
	if (mode === 'stationary') return pin;
	if (mode === 'unknown')    return `<span style="${s}">?</span>`;
	return isMobile ? '' : radio;
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
			               + `<span class="m-name">${t.name}</span>`
			               + `<span class="m-mode" style="filter:grayscale(1) brightness(0.5)"></span>`
			               + `<span class="m-time">${t.lat===null?'—':(t.color||'red')==='red'?'stale':t.time}</span>`;
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
		item.querySelector('.m-dot').style.background    = color;
		item.querySelector('.m-dot').style.borderColor   = color === 'green' ? '#1a7a1a' : (color === 'blue' ? '#0a5a9a' : '#a00');
		item.querySelector('.m-dot').style.borderRadius  = t.mobile && !t.ham_callsign ? '3px' : t.mobile ? '0' : '50%';
		item.querySelector('.m-dot').style.clipPath      = t.mobile && t.ham_callsign ? 'polygon(50% 0%,100% 100%,0% 100%)' : '';
		item.querySelector('.m-time').style.color      = color;
		item.querySelector('.m-id').textContent        = t.id;
		item.querySelector('.m-name').textContent      = t.name;
		item.querySelector('.m-mode').innerHTML        = _modeIcon(t.sharing_mode || '', t.mobile);
		item.querySelector('.m-time').textContent      = t.lat === null ? '—' : color === 'red' ? 'stale' : t.time;
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
			if (typeof data.blink_duration === 'number') _blinkDuration = data.blink_duration * 1000;
			if (typeof data.breadcrumb_count === 'number') _breadcrumbCount = data.breadcrumb_count;
			// Skip tracker update if this client is no longer viewing the default event.
			// Allow through if currentEventName is not yet set (config still loading).
			if (currentEventName !== '' && defaultEvent !== currentEventName) return;
			updateLegend(trackers);

			// If we resumed a session whose mode wasn't saved in localStorage, infer it
			// from the server's sharing_mode so the beacon interval self-corrects.
			if (mobileToken && mobileCallsign) {
				const _strToMode = { walk_run: 0, cycle: 1, drive: 2, stationary: 3, drive_cycle: 2, unknown: 4 };
				const ours = trackers.find(t => t.callsign === mobileCallsign);
				if (ours?.sharing_mode) {
					const inferred = _strToMode[ours.sharing_mode];
					if (inferred !== undefined && inferred !== _mobileActivityMode) {
						_mobileActivityMode = inferred;
						try {
							const _s = JSON.parse(localStorage.getItem('aprs_mobile_tracker') || 'null');
							if (_s) localStorage.setItem('aprs_mobile_tracker', JSON.stringify(Object.assign(_s, { mode: inferred })));
						} catch {}
						_restartMobileInterval();
					}
				}
			}

			trackers.forEach(t => {
				const prev = lastBeacons[t.callsign];
				if (prev !== undefined && t.lastUpdate !== prev && t.lat !== null) triggerBlink(t.callsign);
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
				const sz     = trackerIconSize(t.callsign);
				const shape  = t.mobile && t.ham_callsign ? 'triangle' : t.mobile ? 'square' : 'circle';
				const icon   = makeTrackerIcon(shape, color, sz);
				if (markers[t.callsign]) {
					const oldLL = markers[t.callsign].getLatLng();
					const moved = oldLL.lat !== t.lat || oldLL.lng !== t.lon;
					const prevColor = markers[t.callsign]._trackerColor;
					markers[t.callsign]._trackerColor = color;
					markers[t.callsign]._mobile = t.mobile;
					markers[t.callsign]._hamCallsign = t.ham_callsign || null;
					markers[t.callsign]._carrier = t.carrier || null;
					markers[t.callsign]._radioModel = aprsDeviceName(t.radio_type || '');
					markers[t.callsign]._trackerId = t.id;
					markers[t.callsign]._trackerName = t.name;
					markers[t.callsign].setLatLng(latlng);
					markers[t.callsign].setIcon(icon);
					if (trackerPopups[t.callsign]) trackerPopups[t.callsign].setContent(popupHtml(t));
					if (!blinkTimers[t.callsign])
						markers[t.callsign].setTooltipContent(t.name || t.id);
					// Redraw breadcrumb trail whenever the selected tracker moves or changes staleness color.
					if ((moved || color !== prevColor) && t.callsign === selectedCallsign) showTrackerHistory(t.callsign, color);
				} else {
					const m = L.marker(latlng, { icon, pane: 'trackerPane' }).addTo(map);
					m._trackerColor = color;
					m._mobile = t.mobile;
					m._hamCallsign = t.ham_callsign || null;
					m._carrier = t.carrier || null;
					m._radioModel = aprsDeviceName(t.radio_type || '');
					m._trackerId = t.id;
					m._trackerName = t.name;
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
					m.bindTooltip(t.name || t.id, {
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
			} else {
				// Subsequent polls (or first poll after admin nav): always refresh sections from
				// live server so they're never stale from an incomplete locally-stored config.
				if (cfg.backgrounds) applyBackgrounds(cfg.backgrounds, cfg.background_url || '');
				if (cfg.courses)     applyCourses(cfg.courses);
				if (cfg.aidstations) applyAidStations(cfg.aidstations);
				if (cfg.igates)      applyIgates(cfg.igates);
				if (cfg.section_visibility) applySectionVisibility(cfg.section_visibility);
				// Trackers: skip if admin made local edits not yet saved to server
				if (!storedLocalConfig?._localTrackerEdited && cfg.trackers) applyTrackerConfig(cfg.trackers);
				if (cfg.mobile_beacons) _applyBeaconConfig(cfg.mobile_beacons);
				if (cfg.mobile_enabled !== undefined) initMobileTracking(cfg.mobile_enabled);
				if (cfg.messaging_enabled !== undefined) _initMsgUI(cfg.messaging_enabled);
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
	if (cfg.mobile_beacons) _applyBeaconConfig(cfg.mobile_beacons);
	if (cfg.mobile_enabled !== undefined) initMobileTracking(cfg.mobile_enabled);
	if (cfg.messaging_enabled !== undefined) _initMsgUI(cfg.messaging_enabled);
}

function _applyBeaconConfig(bc) {
	const prevIntervalMs = _beaconIntervalMs[_mobileActivityMode];
	_beaconIntervalMs = [
		(bc.walk_interval  ?? 60)  * 1000,
		(bc.cycle_interval ?? 30)  * 1000,
		(bc.drive_interval ?? 15)  * 1000,
		(bc.stat_interval  ?? 120) * 1000,
		(bc.walk_interval  ?? 60)  * 1000, // unknown: same as walk_run
	];
	_beaconDistMi = [
		bc.walk_distance  ?? 0.2,
		bc.cycle_distance ?? 0.2,
		bc.drive_distance ?? 0.2,
		bc.stat_distance  ?? 1.0,
		bc.walk_distance  ?? 0.2,  // unknown: same as walk_run
	];
	// Restart the interval if sharing and the active-mode interval changed.
	if (_beaconIntervalMs[_mobileActivityMode] !== prevIntervalMs) _restartMobileInterval();
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
		{ label: 'Version', val: 'APRS Tracker Map · v1.19.1+8' },
		mobileCallsign ? { label: 'Callsign', val: mobileCallsign } : null,
		{ label: 'Map',     val: currentBgAttribution || '' },
		{ label: 'Credit',  val: '&copy; 2026 Doug Kaye (K6DRK)' },
	].filter(Boolean);
	el.innerHTML = `<div id="m-about-body-inner">${rows.map(r =>
		`<div class="m-about-row"><span class="m-about-label">${r.label}</span><span class="m-about-val">${r.val}</span></div>`
	).join('')}<a href="https://marsaprs.org/userguide.html" target="_blank" class="help-modal-btn" style="margin-top:8px;font-size:12px">User Guide</a><a href="https://marsaprs.org/tickets/" target="_blank" class="help-modal-btn" style="font-size:12px">Submit a Bug or Suggestion</a></div>`;
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
		const radioEl = document.createElement('input'); radioEl.type = 'radio'; radioEl.name = 'bg'; radioEl.checked = active; radioEl.className = 'bg-radio';
		item.appendChild(nameEl); item.appendChild(radioEl);
		item.addEventListener('click', () => {
			localStorage.setItem(LS_BG, bg.url);
			switchBackground(bg);
			document.querySelectorAll('#backgrounds .bg-radio').forEach(e => { e.checked = false; });
			radioEl.checked = true;
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
		m.bindTooltip(tipLabel, { permanent: true, direction: 'right', className: 'place-label igate-label', offset: [8, 0] });
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
		if (!isMobile) {
			let _pop = null, _ct = null;
			m.on('mouseover', function() {
				clearTimeout(_ct);
				const d = igateMarkers[idx];
				const lastTs = d.callsign ? lastIgateBeacons[d.callsign] : null;
				const html = `<b>${esc(d.name)}</b>`
					+ (d.callsign ? `<br>${esc(d.callsign)}` : '')
					+ (lastTs ? `<br><span style="color:#888;font-size:11px">Last beacon: ${relativeTime(lastTs)} ago</span>` : '');
				if (!_pop) _pop = L.popup({ closeButton: false, autoPan: false, className: 'aprs-path-popup' });
				_pop.setContent(html).setLatLng(m.getLatLng()).openOn(map);
			});
			m.on('mouseout', function() { _ct = setTimeout(() => { if (_pop) map.closePopup(_pop); }, 300); });
		}
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
			: { permanent: true, direction: 'right', className: 'place-label aid-station-label', offset: [8, 0] });
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
		if (!isMobile && !kiosk) {
			let _pop = null, _ct = null;
			m.on('mouseover', function() {
				clearTimeout(_ct);
				const d = aidMarkers[idx];
				const lastTs = d.callsign ? lastAidBeacons[d.callsign] : null;
				const html = `<b>${esc(d.name)}</b>`
					+ (d.callsign ? `<br>${esc(d.callsign)}` : '')
					+ (lastTs ? `<br><span style="color:#888;font-size:11px">Last beacon: ${relativeTime(lastTs)} ago</span>` : '');
				if (!_pop) _pop = L.popup({ closeButton: false, autoPan: false, className: 'aprs-path-popup' });
				_pop.setContent(html).setLatLng(m.getLatLng()).openOn(map);
			});
			m.on('mouseout', function() { _ct = setTimeout(() => { if (_pop) map.closePopup(_pop); }, 300); });
		}
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
	// Rescale tracker markers (selected tracker stays 2x)
	Object.entries(markers).forEach(([cs, m]) => {
		if (m._trackerColor !== undefined) {
			m.setIcon(makeTrackerIcon(m._mobile && m._hamCallsign ? 'triangle' : m._mobile ? 'square' : 'circle', m._trackerColor, trackerIconSize(cs)));
		}
	});
	// Rescale igate and aid station markers (selected ones stay 2x)
	igateMarkers.forEach((d, idx) => { if (d.m._baseRadius !== undefined) refreshIgateRadius(idx); });
	aidMarkers.forEach((d, idx)   => { if (d.m._baseRadius !== undefined) refreshAidRadius(idx); });
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
		{ label: 'Application',  val: 'APRS Tracker Map · v1.19.1+8' },
		currentEventName ? { label: 'Event', val: currentEventName } : null,
		mobileCallsign ? { label: 'My Callsign', val: mobileCallsign } : null,
		mobileCallsign ? { label: 'Activity', val: ['Walk / Run', 'Cycle', 'Drive', 'Stationary', 'Unknown'][_mobileActivityMode] } : null,
		{ label: 'Map Data',     val: attrText },
		{ label: 'Copyright',    val: '&copy; 2026 Doug Kaye (K6DRK). All Rights Reserved.' },
	].filter(Boolean);
	body.innerHTML = rows.map(r => `<div class="about-row"><div class="about-label">${r.label}</div><div class="about-val">${r.val}</div></div>`).join('')
		+ '<a href="https://marsaprs.org/userguide.html" target="_blank" class="help-modal-btn">User Guide</a>'
		+ '<a href="https://marsaprs.org/tickets/" target="_blank" class="help-modal-btn">Submit a Bug or Suggestion</a>';
	document.getElementById('about-modal').style.display = 'flex';
}
function closeAboutModal() { document.getElementById('about-modal').style.display = 'none'; }
document.getElementById('about-close').addEventListener('click', closeAboutModal);
document.getElementById('about-backdrop').addEventListener('click', closeAboutModal);

// Q_LABELS, formatAprsPath: loaded from utils.js
if (document.getElementById('about-btn'))  document.getElementById('about-btn').addEventListener('click', openAboutModal);
if (document.getElementById('m-help-btn')) document.getElementById('m-help-btn').addEventListener('click', openAboutModal);

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
		const now = Math.floor(Date.now() / 1000);
		const recentEntries = Object.entries(d.recentIps || {})
			.filter(([, rec]) => (now - (rec.ts ?? rec)) < 3600);
		if (recentEntries.length) {
			const pageLabel = { map:'Map', tracker:'Tracker', msg:'Msg', hist:'History', status:'Status', admin:'Admin', analyzer:'Analyzer', netbird:'NetBird', wifi:'WiFi' };
			const pageColor = { map:'#2e7d32', tracker:'#1565c0', msg:'#6a1b9a', hist:'#888', status:'#888', admin:'#b71c1c', analyzer:'#e65100', netbird:'#00695c', wifi:'#4527a0' };
			html += `<div class="clients-ip-list" style="margin-top:12px">`;
			html += `<div class="clients-ip-title">Recent requests (past hour) &mdash; ${recentEntries.length} IPs</div>`;
			recentEntries.forEach(([ip, rec]) => {
				const ts   = rec.ts ?? rec;
				const page = rec.page ?? 'map';
				const cs   = rec.cs ?? null;
				const sec  = now - ts;
				const ago  = sec < 60 ? `${sec}s ago` : sec < 3600 ? `${Math.floor(sec/60)}m ago` : `${Math.floor(sec/3600)}h ago`;
				const label = cs ? ((d.csNames || {})[cs] ?? cs) : (pageLabel[page] ?? page);
				const color = cs ? '#1565c0' : (pageColor[page] ?? '#555');
				const badge = `<span style="font-size:10px;padding:1px 5px;border-radius:3px;background:${color};color:#fff;margin-left:5px">${label}</span>`;
				html += `<div class="clients-ip-row"><span>${ip}${badge}</span><span style="color:#888">${ago}</span></div>`;
			});
			html += `</div>`;
		}
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
	// Sync label visibility: hide labels when section is off; restore eye state when section comes back on
	if (LABEL_HIDE_CLASS[section]) {
		map._container.classList.toggle(LABEL_HIDE_CLASS[section], !visible || !labelVisible[section]);
	}
}

// ── Label visibility (eye buttons, desktop sidebar only) ──────────────────
const EYE_ON  = `<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5" fill="currentColor" stroke="none"/></svg>`;
const EYE_OFF = `<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5" fill="currentColor" stroke="none"/><line x1="2" y1="14" x2="14" y2="2"/></svg>`;
const LS_LABELS = 'aprs_label_vis';
const LABEL_HIDE_CLASS = { trackers: 'aprs-hide-tracker-labels', aidstations: 'aprs-hide-aid-labels', igates: 'aprs-hide-igate-labels' };
let labelVisible = { trackers: true, aidstations: true, igates: false };
try { const s = JSON.parse(localStorage.getItem(LS_LABELS) || '{}'); Object.keys(labelVisible).forEach(k => { if (s[k] !== undefined) labelVisible[k] = !!s[k]; }); } catch {}

function setLabelVisible(section, visible) {
	labelVisible[section] = visible;
	// Only show labels if both the eye is on AND the section itself is visible
	map._container.classList.toggle(LABEL_HIDE_CLASS[section], !visible || !sectionVisible[section]);
	document.querySelectorAll(`.sec-label-btn[data-section="${section}"]`).forEach(btn => {
		btn.innerHTML = visible ? EYE_ON : EYE_OFF;
		btn.classList.toggle('labels-off', !visible);
	});
	try { localStorage.setItem(LS_LABELS, JSON.stringify(labelVisible)); } catch {}
}

document.querySelectorAll('.sec-label-btn').forEach(btn => {
	btn.addEventListener('click', e => {
		e.stopPropagation();
		setLabelVisible(btn.dataset.section, !labelVisible[btn.dataset.section]);
	});
});

// Apply initial state (on by default; localStorage may override)
Object.keys(labelVisible).forEach(k => setLabelVisible(k, labelVisible[k]));

// ── Mobile location sharing ────────────────────────────────────────────────

function initMobileTracking(enabled) {
	const btnDesk = document.getElementById('share-loc-btn');
	const btnMob  = document.getElementById('m-share-loc-btn');
	const avail   = !!(enabled && navigator.geolocation);
	if (btnDesk) btnDesk.style.display = avail ? '' : 'none';
	if (btnMob)  btnMob.style.display  = avail ? '' : 'none';
	if (!avail || mobileTrackingInitialized) return;
	mobileTrackingInitialized = true;
	// Resume from previous session
	try {
		const saved = JSON.parse(localStorage.getItem('aprs_mobile_tracker') || 'null');
		if (saved && saved.token && saved.id) {
			mobileToken = saved.token; mobileId = saved.id; mobileCallsign = saved.callsign || null;
			_mobileActivityMode = saved.mode ?? 0;
			setShareLocBtnState('tracking');
			startMobileGeolocation();
			if (isMobile) { acquireWakeLock(); startDimTimer(); }
		}
	} catch {}
	if (btnDesk) btnDesk.addEventListener('click', onShareLocClick);
	if (btnMob)  btnMob.addEventListener('click',  onShareLocClick);
}

function onShareLocClick() {
	if (mobileToken) { openModeChangeModal(); } else { openMobileJoinModal(); }
}

function setShareLocBtnState(state) {
	const tracking = state === 'tracking';
	const label  = tracking ? 'Sharing' : 'Share Location';
	const btnD   = document.getElementById('share-loc-btn');
	const btnM   = document.getElementById('m-share-loc-btn');
	if (btnD) btnD.textContent = label;
	if (btnM) btnM.textContent = label;
	const badge  = document.getElementById('sharing-badge');
	if (badge) badge.style.display = tracking ? 'block' : 'none';
}

function openModeChangeModal() {
	document.getElementById('mchange-modal').style.display = 'flex';
}

function closeModeChangeModal() {
	document.getElementById('mchange-modal').style.display = 'none';
}

document.getElementById('mchange-close').addEventListener('click', closeModeChangeModal);
document.getElementById('mchange-backdrop').addEventListener('click', closeModeChangeModal);
document.getElementById('mchange-cancel').addEventListener('click', closeModeChangeModal);
document.getElementById('mchange-stop').addEventListener('click', () => { closeModeChangeModal(); stopMobileTracking(); });

let _shareBlinkTimer = null;
function blinkShareBtn() {
	const els = [
		document.getElementById('share-loc-btn'),
		document.getElementById('m-share-loc-btn'),
		document.getElementById('sharing-badge'),
	];
	els.forEach(b => { if (b) b.style.opacity = '0.15'; });
	if (_shareBlinkTimer) clearTimeout(_shareBlinkTimer);
	_shareBlinkTimer = setTimeout(() => {
		els.forEach(b => { if (b) b.style.opacity = '1'; });
		_shareBlinkTimer = null;
	}, 400);
}

function openMobileJoinModal() {
	const savedName = localStorage.getItem('aprs_sharing_name') || '';
	document.getElementById('mjoin-name').value     = savedName;
	document.getElementById('mjoin-pin').value      = '';
	document.getElementById('mjoin-ham-call').value = '';
	document.getElementById('mjoin-ham-ssid').value = '';
	document.getElementById('mjoin-error').textContent = '';
	const sub = document.getElementById('mjoin-submit');
	sub.disabled = false; sub.textContent = 'Share Location';
	document.getElementById('mobile-join-modal').style.display = 'flex';
	(savedName ? document.getElementById('mjoin-pin') : document.getElementById('mjoin-name')).focus();
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

function showShareInfoModal(cs) {
	if (!cs) return;
	const url = 'https://aprs.fi/#!call=' + encodeURIComponent(cs);
	document.getElementById('mshare-info-body').innerHTML =
		'Your location is now shared using callsign <b>' + cs + '</b>.<br><br>' +
		'In addition to this map, you can track your position on aprs.fi:<br>' +
		'<a href="' + url + '" target="_blank" rel="noopener">aprs.fi/?call=' + cs + '</a><br><br>' +
		'The callsign <b>' + cs + '</b> can also be entered in CalTopo.com to show your position there.';
	document.getElementById('mshare-info-modal').style.display = 'flex';
}

function closeShareInfoModal() {
	document.getElementById('mshare-info-modal').style.display = 'none';
}

document.getElementById('noloc-ok').addEventListener('click', closeNoLocation);
document.getElementById('noloc-modal').addEventListener('click', e => { if (e.target === document.getElementById('noloc-modal')) closeNoLocation(); });

document.getElementById('mshare-info-ok').addEventListener('click', closeShareInfoModal);
document.getElementById('mshare-info-backdrop').addEventListener('click', closeShareInfoModal);

document.getElementById('mjoin-close').addEventListener('click', closeMobileJoinModal);
document.getElementById('mjoin-backdrop').addEventListener('click', closeMobileJoinModal);
document.getElementById('malert-ok').addEventListener('click', closeMobileAlert);
document.getElementById('malert-backdrop').addEventListener('click', closeMobileAlert);
document.getElementById('mjoin-cancel').addEventListener('click', closeMobileJoinModal);
document.getElementById('mjoin-name').addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('mjoin-pin').focus(); });
document.getElementById('mjoin-pin').addEventListener('keydown',  e => { if (e.key === 'Enter') submitMobileJoin(); });

document.getElementById('mjoin-submit').addEventListener('click', submitMobileJoin);
document.getElementById('mjoin-ham-call').addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('mjoin-ham-ssid').focus(); });
document.getElementById('mjoin-ham-ssid').addEventListener('keydown', e => { if (e.key === 'Enter') submitMobileJoin(); });

function _collectWebDeviceInfo() {
	const ua = navigator.userAgent;
	// OS
	let os = 'Unknown';
	if      (/CrOS/.test(ua))                                    os = 'ChromeOS';
	else if (/Android ([0-9.]+)/.test(ua))                       os = 'Android ' + ua.match(/Android ([0-9.]+)/)[1];
	else if (/iPhone OS ([0-9_]+)/.test(ua))                     os = 'iOS ' + ua.match(/iPhone OS ([0-9_]+)/)[1].replace(/_/g, '.');
	else if (/iPad.*OS ([0-9_]+)/.test(ua))                      os = 'iPadOS ' + ua.match(/OS ([0-9_]+)/)[1].replace(/_/g, '.');
	else if (/Mac OS X ([0-9_]+)/.test(ua))                      os = 'macOS ' + ua.match(/Mac OS X ([0-9_]+)/)[1].replace(/_/g, '.');
	else if (/Windows NT 10\.0/.test(ua))                        os = 'Windows 10/11';
	else if (/Windows NT 6\.3/.test(ua))                         os = 'Windows 8.1';
	else if (/Windows/.test(ua))                                  os = 'Windows';
	else if (/Linux/.test(ua))                                    os = 'Linux';
	// Browser
	let browser = 'Unknown';
	if      (/Tesla\/([0-9.]+)/.test(ua))                        browser = 'Tesla ' + ua.match(/Tesla\/([0-9.]+)/)[1];
	else if (/Tesla/.test(ua))                                    browser = 'Tesla Browser';
	else if (/Edg\/([0-9.]+)/.test(ua))                          browser = 'Edge ' + ua.match(/Edg\/([0-9.]+)/)[1].split('.')[0];
	else if (/OPR\/([0-9.]+)/.test(ua))                          browser = 'Opera ' + ua.match(/OPR\/([0-9.]+)/)[1].split('.')[0];
	else if (/SamsungBrowser\/([0-9.]+)/.test(ua))               browser = 'Samsung ' + ua.match(/SamsungBrowser\/([0-9.]+)/)[1].split('.')[0];
	else if (/Firefox\/([0-9.]+)/.test(ua))                      browser = 'Firefox ' + ua.match(/Firefox\/([0-9.]+)/)[1].split('.')[0];
	else if (/Chromium\/([0-9.]+)/.test(ua))                     browser = 'Chromium ' + ua.match(/Chromium\/([0-9.]+)/)[1].split('.')[0];
	else if (/Chrome\/([0-9.]+)/.test(ua))                       browser = 'Chrome ' + ua.match(/Chrome\/([0-9.]+)/)[1].split('.')[0];
	else if (/Version\/([0-9.]+).*Safari/.test(ua))              browser = 'Safari ' + ua.match(/Version\/([0-9.]+)/)[1];
	else if (/Safari/.test(ua))                                   browser = 'Safari';
	return {
		app:     'Web v<?= WEB_VERSION ?>',
		os,
		browser,
		screen:  window.screen.width + '×' + window.screen.height,
	};
}

function submitMobileJoin() {
	const name = document.getElementById('mjoin-name').value.trim();
	const pin  = document.getElementById('mjoin-pin').value;
	const errEl = document.getElementById('mjoin-error');
	const cancelBtn = document.getElementById('mjoin-cancel');
	const submitBtn = document.getElementById('mjoin-submit');
	if (!name) { errEl.textContent = 'Please enter your name.'; return; }
	cancelBtn.disabled = true;
	submitBtn.disabled = true; submitBtn.textContent = 'Sharing…';
	errEl.textContent = '';
	const hamCall = document.getElementById('mjoin-ham-call').value.trim().toUpperCase();
	const hamSsid = parseInt(document.getElementById('mjoin-ham-ssid').value, 10) || 0;
	const joinBody = { name, pin, device_info: _collectWebDeviceInfo(), sharing_mode: 'unknown' };
	if (hamCall) { joinBody.ham_root = hamCall; joinBody.ham_ssid = hamSsid; }
	fetch('index.php?mobile=join', {
		method: 'POST', headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(joinBody)
	})
	.then(r => r.json().then(d => ({ ok: r.ok, d })))
	.then(({ ok, d }) => {
		if (!ok) {
			errEl.textContent = d.error === 'Incorrect PIN'
				? 'Incorrect PIN. Please try again.'
				: (d.error || 'Could not connect. Please try again.');
			if (d.field === 'callsign') document.getElementById('mjoin-ham-call').focus();
			if (d.field === 'ssid')    document.getElementById('mjoin-ham-ssid').focus();
			cancelBtn.disabled = false;
			submitBtn.disabled = false; submitBtn.textContent = 'Share Location';
			return;
		}
		mobileToken = d.token; mobileId = d.id; mobileCallsign = d.callsign || null;
			try { localStorage.setItem('aprs_mobile_tracker', JSON.stringify({ token: d.token, id: d.id, callsign: d.callsign || null, mode: 4 })); } catch {}
			try { localStorage.setItem('aprs_sharing_name', name); } catch {}
			_mobileActivityMode = 4;
		// Clear stale breadcrumbs for this callsign so old history doesn't linger
		if (mobileCallsign && historyDots[mobileCallsign]) {
			historyDots[mobileCallsign].forEach(dot => dot.remove());
			delete historyDots[mobileCallsign];
		}
		closeMobileJoinModal();
		setShareLocBtnState('tracking');
		startMobileGeolocation();
		if (isMobile) { acquireWakeLock(); startDimTimer(); }
		showShareInfoModal(d.callsign || '');
	})
	.catch(() => { errEl.textContent = 'Network error. Try again.'; cancelBtn.disabled = false; });
}

let _mobileLastPos = null;
let _mobileInterval = null;
let _mobileActivityMode = 0; // 0=Walk/Run, 1=Cycle, 2=Drive, 3=Stationary
let _mobileLastSentPos = null;
let _activeSendUpdate = null; // reference to current session's sendUpdate, for interval restart

// ── Auto activity-mode detection ───────────────────────────────────────────
const _kAutoSpeedStationary   = 1.0;   // m/s
const _kAutoSpeedWalkRun      = 4.5;
const _kAutoSpeedCycle        = 11.0;
const _kAutoGeneralWindow     = 15;    // consecutive GPS samples required
const _kAutoStationaryWindow  = 20;
const _kAutoStationaryMinSecs = 300;   // 5 minutes wall-clock for stationary
const _kAutoStartupWindow     = 3;    // samples needed during startup phase
const _kAutoStartupTotal      = 10;   // total samples that define startup phase

let _autoLastMovementAt       = null;  // epoch ms of last sustained movement
let _autoMovementAboveCount   = 0;    // consecutive above-threshold readings (noise guard)
let _autoCandidateMode        = -1;
let _autoCandidateCount     = 0;
let _autoCandidateFirstSeen = null;
let _autoStationaryTimer    = null;
let _autoModeChanged        = false;   // send sharing_mode on next beacon
let _autoTotalSamples       = 0;      // total since session start; drives startup fast-window

function _autoRawMode(speedMs) {
	if (speedMs <= _kAutoSpeedStationary) return 3;
	if (speedMs <= _kAutoSpeedWalkRun)   return 0;
	if (speedMs <= _kAutoSpeedCycle)     return 1;
	return 2;
}

function _autoDetectFromSpeed(speedMs, accuracy) {
	if (!mobileToken) return;
	if (speedMs > _kAutoSpeedStationary && accuracy <= 20) {
		_autoMovementAboveCount++;
		if (_autoMovementAboveCount >= 3) _autoLastMovementAt = Date.now();
	} else {
		_autoMovementAboveCount = 0;
	}
	_autoTotalSamples++;
	const inStartup = _autoTotalSamples <= _kAutoStartupTotal;
	const candidate = _autoRawMode(speedMs);
	if (candidate !== _autoCandidateMode) {
		_autoCandidateMode = candidate;
		_autoCandidateCount = 1;
		_autoCandidateFirstSeen = Date.now();
	} else {
		_autoCandidateCount++;
	}
	if (_autoCandidateMode === _mobileActivityMode) return;
	const isStationary = _autoCandidateMode === 3;
	const windowNeeded = inStartup ? _kAutoStartupWindow :
		(isStationary ? _kAutoStationaryWindow : _kAutoGeneralWindow);
	if (_autoCandidateCount < windowNeeded) return;
	if (!inStartup && isStationary) {
		if ((Date.now() - _autoCandidateFirstSeen) / 1000 < _kAutoStationaryMinSecs) return;
	}
	_applyAutoMode(_autoCandidateMode);
}

function _checkStationaryByTime() {
	if (!mobileToken || _mobileActivityMode === 3 || !_autoLastMovementAt) return;
	const elapsed = (Date.now() - _autoLastMovementAt) / 1000;
	const inStartup = _autoTotalSamples <= _kAutoStartupTotal;
	if (elapsed >= (inStartup ? 90 : _kAutoStationaryMinSecs)) _applyAutoMode(3);
}

function _applyAutoMode(newMode) {
	if (newMode === _mobileActivityMode) return;
	_mobileActivityMode = newMode;
	_autoModeChanged = true;
	_restartMobileInterval();
}

function _resetAutoModeDetection() {
	_autoCandidateMode = -1;
	_autoCandidateCount = 0;
	_autoCandidateFirstSeen = null;
	_autoLastMovementAt = Date.now();
	_autoMovementAboveCount = 0;
	_autoModeChanged = false;
	_autoTotalSamples = 0;
	clearInterval(_autoStationaryTimer);
	_autoStationaryTimer = null;
}

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
	_resetAutoModeDetection();
	_autoStationaryTimer = setInterval(_checkStationaryByTime, 30000);
	let sentFirst = false;
	const _modeKeys = ['walk_run', 'cycle', 'drive', 'stationary', 'unknown'];
	function sendUpdate() {
		if (!mobileToken || !_mobileLastPos) return;
		_mobileLastSentPos = { lat: _mobileLastPos.coords.latitude, lon: _mobileLastPos.coords.longitude };
		const body = { token: mobileToken, lat: _mobileLastSentPos.lat, lon: _mobileLastSentPos.lon };
		if (_autoModeChanged) { body.sharing_mode = _modeKeys[_mobileActivityMode]; _autoModeChanged = false; }
		fetch('index.php?mobile=update', {
			method: 'POST', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
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
			} else {
				if (d.set_mode) {
					const m = { walk_run: 0, cycle: 1, drive: 2, stationary: 3, drive_cycle: 2 }[d.set_mode];
					if (m !== undefined && m !== _mobileActivityMode) _applyAutoMode(m);
				}
				blinkShareBtn();
			}
		  }).catch(() => {});
	}
	_activeSendUpdate = sendUpdate;
	mobileWatcher = navigator.geolocation.watchPosition(
		pos => {
			_mobileLastPos = pos;
			const speedMs = pos.coords.speed ?? -1;
			if (speedMs >= 0) _autoDetectFromSpeed(speedMs, pos.coords.accuracy);
			if (!sentFirst) { sentFirst = true; sendUpdate(); return; }
			if (_mobileLastSentPos) {
				const d = haversineDistance(_mobileLastSentPos.lat, _mobileLastSentPos.lon,
				                            pos.coords.latitude, pos.coords.longitude);
				if (d >= _beaconDistMi[_mobileActivityMode]) sendUpdate();
			}
		},
		() => {},
		{ enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 }
	);
	_mobileInterval = setInterval(sendUpdate, _beaconIntervalMs[_mobileActivityMode]);
}

function _restartMobileInterval() {
	if (!_activeSendUpdate || mobileWatcher === null) return;
	clearInterval(_mobileInterval);
	_mobileInterval = setInterval(_activeSendUpdate, _beaconIntervalMs[_mobileActivityMode]);
}

async function stopMobileTracking() {
	if (mobileToken) {
		try {
			await fetch('index.php?mobile=leave', {
				method: 'POST', headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ token: mobileToken })
			});
		} catch (_) {}
	}
	clearMobileState();
	updateMap(); // force immediate refresh so tracker disappears from sidebar
}

function clearMobileState() {
	if (mobileWatcher !== null) { navigator.geolocation.clearWatch(mobileWatcher); mobileWatcher = null; }
	if (_mobileInterval !== null) { clearInterval(_mobileInterval); _mobileInterval = null; }
	clearInterval(_autoStationaryTimer); _autoStationaryTimer = null;
	_mobileLastPos = null;
	_mobileLastSentPos = null;
	_activeSendUpdate = null;
	_mobileActivityMode = 0;
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

// CrOS: suppress context menu and text selection on touch devices
if (/CrOS/.test(navigator.userAgent)) {
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
	resetBtn.addEventListener('touchend', e => { e.stopPropagation(); clearAllSelections(); map.setView([defaultView.lat, defaultView.lon], defaultView.zoom); closeMobileDrawer(); });
	resetBtn.addEventListener('click',    e => { e.stopPropagation(); clearAllSelections(); map.setView([defaultView.lat, defaultView.lon], defaultView.zoom); closeMobileDrawer(); });
}

// ── Messaging ─────────────────────────────────────────────────────────────────

let _msgToken     = null;
let _msgName      = null;
let _msgLastId    = 0;
let _msgPollTimer = null;
let _msgLog       = [];   // [{id, ts, from, from_label, to, to_label, text}]
let _msgEnabled   = false;
const _msgNotifiedIds = new Set(); // tracks IDs already shown as modals

// Restore saved subscription from localStorage
try {
	const _saved = JSON.parse(localStorage.getItem('aprs_msg_session') || 'null');
	if (_saved?.token) { _msgToken = _saved.token; _msgName = _saved.name; _msgLastId = _saved.last_id || 0; }
} catch {}

function _msgIsSubscribed() { return !!_msgToken; }

async function _loadMsgHistory() {
	if (!_msgToken) return false;
	try {
		const r = await fetch('index.php?messaging=history', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({web_token: _msgToken})
		});
		if (r.status === 403) {
			_msgToken = null; _msgName = null;
			try { localStorage.removeItem('aprs_msg_session'); } catch {}
			return false;
		}
		const d = await r.json();
		_msgLog = d.messages || [];
		_msgLastId = d.last_id || 0;
		try {
			const _s = JSON.parse(localStorage.getItem('aprs_msg_session') || 'null');
			if (_s) { _s.last_id = _msgLastId; localStorage.setItem('aprs_msg_session', JSON.stringify(_s)); }
		} catch {}
		// Mark all history as seen — modals only fire for messages arriving via poll after page load
		for (const m of _msgLog) _msgNotifiedIds.add(m.id);
		return true;
	} catch {}
	return true; // network error — keep token, polling will detect if truly gone
}

function _setSubscribedUI(subscribed) {
	// msg-messaging-btn stays visible whenever messaging is enabled
}

let _msgUiInitialized = false;
function _initMsgUI(enabled) {
	if (!enabled) return;
	if (_msgUiInitialized) return;
	_msgUiInitialized = true;
	_msgEnabled = true;
	document.getElementById('msg-messaging-btn').style.display = '';
	if (_msgIsSubscribed()) {
		_setSubscribedUI(true);
		_loadMsgHistory().then(ok => {
			if (!ok) {
				_setSubscribedUI(false);
				if (window._aprsAutoMsgPw) _autoSubscribe(window._aprsAutoMsgPw, window._aprsAutoOp);
			} else {
				_startMsgPoll();
			}
		});
	} else if (window._aprsAutoMsgPw) {
		_autoSubscribe(window._aprsAutoMsgPw, window._aprsAutoOp);
	}
}

async function _autoSubscribe(password, name) {
	try {
		const r = await fetch('index.php?messaging=subscribe', {
			method: 'POST', headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({name: name || 'Operator', password})
		});
		const d = await r.json();
		if (d.error) return;
		_msgToken = d.token; _msgName = d.name; _msgLastId = 0;
		// Don't persist to localStorage — session is ephemeral so removing line 2
		// from login.txt on the next reload cleanly unsubscribes this client
		try { localStorage.removeItem('aprs_msg_session'); } catch {}
		_setSubscribedUI(true);
		await _loadMsgHistory();
		_startMsgPoll();
	} catch {}
}

document.getElementById('msg-sub-backdrop').addEventListener('click', () => document.getElementById('msg-sub-modal').style.display = 'none');
document.getElementById('msg-sub-close').addEventListener('click',    () => document.getElementById('msg-sub-modal').style.display = 'none');
document.getElementById('msg-sub-submit').addEventListener('click', async () => {
	const name = document.getElementById('msg-sub-name').value.trim();
	const pw   = document.getElementById('msg-sub-pw').value;
	const errEl = document.getElementById('msg-sub-error');
	if (!name) { errEl.textContent = 'Please enter your name.'; return; }
	if (!pw)   { errEl.textContent = 'Please enter the messaging password.'; return; }
	errEl.textContent = '';
	try {
		const r = await fetch('index.php?messaging=subscribe', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({name, password: pw}) });
		const d = await r.json();
		if (d.error) { errEl.textContent = d.error; return; }
		_msgToken = d.token; _msgName = d.name; _msgLastId = 0;
		_warmMsgAudio(); // unlock AudioContext while we're inside a user gesture
		try { localStorage.setItem('aprs_msg_session', JSON.stringify({token: _msgToken, name: _msgName, last_id: 0})); } catch {}
		document.getElementById('msg-sub-modal').style.display = 'none';
		_setSubscribedUI(true);
		await _loadMsgHistory();
		_startMsgPoll();
	} catch { errEl.textContent = 'Connection error. Please try again.'; }
});

// Messaging button — opens compose when subscribed, subscribe modal otherwise
document.getElementById('msg-messaging-btn').addEventListener('click', () => {
	if (_msgIsSubscribed()) {
		_showComposeModal('*', 'All Trackers');
	} else {
		const _savedMsg = JSON.parse(localStorage.getItem('aprs_msg_session') || 'null');
		const _savedName = _savedMsg?.name || '';
		document.getElementById('msg-sub-name').value = _savedName;
		document.getElementById('msg-sub-pw').value   = '';
		document.getElementById('msg-sub-error').textContent = '';
		document.getElementById('msg-sub-modal').style.display = 'flex';
		setTimeout(() => (_savedName ? document.getElementById('msg-sub-pw') : document.getElementById('msg-sub-name')).focus(), 50);
	}
});

// Right-click / Ctrl+click on sidebar tracker rows → compose to that tracker
function _handleTrackerActivate(cs, name) {
	if (_msgIsSubscribed()) {
		_showComposeModal(cs, name + ' (' + cs + ')');
	} else {
		// Prompt to subscribe first
		document.getElementById('msg-sub-name').value = '';
		document.getElementById('msg-sub-pw').value   = '';
		document.getElementById('msg-sub-error').textContent = '';
		document.getElementById('msg-sub-modal').style.display = 'flex';
		setTimeout(() => document.getElementById('msg-sub-name').focus(), 50);
	}
}
document.getElementById('legend').addEventListener('contextmenu', e => {
	const item = e.target.closest('.legend-item');
	if (!item || !_msgEnabled) return;
	e.preventDefault();
	const cs   = item.dataset.callsign;
	const name = item.querySelector('.legend-name')?.textContent || cs;
	_handleTrackerActivate(cs, name);
});
document.getElementById('legend').addEventListener('click', e => {
	if (!e.ctrlKey && !e.metaKey) return;
	const item = e.target.closest('.legend-item');
	if (!item || !_msgEnabled) return;
	e.stopImmediatePropagation();
	const cs   = item.dataset.callsign;
	const name = item.querySelector('.legend-name')?.textContent || cs;
	_handleTrackerActivate(cs, name);
}, true);

// Compose modal
function _showComposeModal(toCallsign, toLabel, prefill) {
	document.getElementById('msg-compose-to').textContent    = 'To: ' + toLabel;
	document.getElementById('msg-compose-broadcast').checked = (toCallsign === '*');
	document.getElementById('msg-compose-text').value        = prefill || '';
	document.getElementById('msg-compose-error').textContent = '';
	document.getElementById('msg-compose-modal').dataset.to      = toCallsign;
	document.getElementById('msg-compose-modal').dataset.toLabel = toLabel;
	// Show thread — full history for this callsign, oldest first, scroll to bottom
	const threadEl = document.getElementById('msg-compose-thread');
	const relevant = toCallsign === '*'
		? _msgLog.filter(m => m.broadcast || m.to === '*')
		: _msgLog.filter(m => m.from === toCallsign || m.to === toCallsign || m.broadcast);
	if (relevant.length) {
		threadEl.innerHTML = relevant.map(m => {
			const isMe = m.from === 'web';
			const who  = isMe ? `<span class="msg-thread-from msg-thread-web">${_esc(m.from_label)}</span>` : `<span class="msg-thread-from">${_esc(m.from_label)}</span>`;
			const d    = new Date(m.ts * 1000);
			const t    = d.toLocaleDateString([], {month:'2-digit',day:'2-digit'}) + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
			return `<div class="msg-thread-entry">${who}<span class="msg-thread-time">${t}</span><br>${_esc(m.text)}</div>`;
		}).join('');
		threadEl.style.display = '';
		setTimeout(() => { threadEl.scrollTop = threadEl.scrollHeight; }, 0);
	} else {
		threadEl.style.display = 'none';
	}
	document.getElementById('msg-rename-panel').style.display = 'none';
	document.getElementById('msg-compose-modal').style.display = 'flex';
	setTimeout(() => document.getElementById('msg-compose-text').focus(), 50);
}
document.getElementById('msg-compose-backdrop').addEventListener('click', () => document.getElementById('msg-compose-modal').style.display = 'none');
document.getElementById('msg-compose-close').addEventListener('click',    () => document.getElementById('msg-compose-modal').style.display = 'none');
document.getElementById('msg-compose-broadcast').addEventListener('change', function() {
	const modal = document.getElementById('msg-compose-modal');
	if (this.checked) { modal.dataset.to = '*'; document.getElementById('msg-compose-to').textContent = 'To: All Trackers'; }
	else { modal.dataset.to = modal.dataset.toLabel; document.getElementById('msg-compose-to').textContent = 'To: ' + modal.dataset.toLabel; }
});
document.getElementById('msg-compose-send').addEventListener('click', async () => {
	const modal  = document.getElementById('msg-compose-modal');
	const to     = modal.dataset.to;
	const toLabel = modal.dataset.toLabel;
	const text   = document.getElementById('msg-compose-text').value.trim();
	const errEl  = document.getElementById('msg-compose-error');
	if (!text) { errEl.textContent = 'Please enter a message.'; return; }
	errEl.textContent = '';
	try {
		const r = await fetch('index.php?messaging=send', { method: 'POST', headers: {'Content-Type':'application/json'},
			body: JSON.stringify({web_token: _msgToken, to, text}) });
		const d = await r.json();
		if (d.error) { errEl.textContent = d.error; return; }
		_msgLog.push({id: d.id || 0, ts: Math.floor(Date.now()/1000), from: 'web', from_label: _msgName,
		              to, to_label: toLabel, text, broadcast: to === '*'});
		if (_msgLog.length > 50) _msgLog.shift();
		modal.style.display = 'none';
	} catch { errEl.textContent = 'Send failed. Please try again.'; }
});

// Rename link
document.getElementById('msg-compose-rename-link').addEventListener('click', e => {
	e.preventDefault();
	const panel = document.getElementById('msg-rename-panel');
	panel.style.display = panel.style.display === 'none' ? '' : 'none';
	if (panel.style.display !== 'none') {
		const inp = document.getElementById('msg-rename-input');
		inp.value = _msgName || '';
		document.getElementById('msg-rename-error').textContent = '';
		setTimeout(() => inp.focus(), 50);
	}
});
document.getElementById('msg-rename-cancel').addEventListener('click', () => {
	document.getElementById('msg-rename-panel').style.display = 'none';
});
document.getElementById('msg-rename-save').addEventListener('click', async () => {
	const name  = document.getElementById('msg-rename-input').value.trim();
	const errEl = document.getElementById('msg-rename-error');
	if (!name) { errEl.textContent = 'Please enter a name.'; return; }
	errEl.textContent = '';
	try {
		const r = await fetch('index.php?messaging=rename', { method: 'POST', headers: {'Content-Type':'application/json'},
			body: JSON.stringify({web_token: _msgToken, name}) });
		const d = await r.json();
		if (d.error) { errEl.textContent = d.error; return; }
		_msgName = d.name;
		try { const s = JSON.parse(localStorage.getItem('aprs_msg_session') || '{}'); s.name = _msgName; localStorage.setItem('aprs_msg_session', JSON.stringify(s)); } catch {}
		document.getElementById('msg-rename-panel').style.display = 'none';
	} catch { errEl.textContent = 'Failed. Please try again.'; }
});

// Disable messaging link
document.getElementById('msg-compose-disable-link').addEventListener('click', e => {
	e.preventDefault();
	_msgToken = null; _msgName = null; _msgLastId = 0; _msgLog = [];
	clearInterval(_msgPollTimer); _msgPollTimer = null;
	try { localStorage.removeItem('aprs_msg_session'); } catch {}
	_setSubscribedUI(false);
	document.getElementById('msg-compose-modal').style.display = 'none';
});

// Poll for incoming messages
function _startMsgPoll() {
	if (_msgPollTimer) return;
	_msgPollTimer = setInterval(_pollMessages, 5000);
	_pollMessages();
}
async function _pollMessages() {
	if (!_msgToken) return;
	try {
		const r = await fetch(`index.php?messaging=poll&web_token=${encodeURIComponent(_msgToken)}&since_id=${_msgLastId}`);
		if (r.status === 403) { _msgToken = null; _msgName = null; try { localStorage.removeItem('aprs_msg_session'); } catch {} clearInterval(_msgPollTimer); _msgPollTimer = null; _setSubscribedUI(false); return; }
		const d = await r.json();
		if (d.messages?.length) {
			d.messages.forEach(m => {
				_msgLog.push(m);
				if (_msgLog.length > 50) _msgLog.shift();
				if (!_msgNotifiedIds.has(m.id)) _showMsgModal(m);
				_msgNotifiedIds.add(m.id);
			});
			_msgLastId = d.last_id;
			try { const _s = JSON.parse(localStorage.getItem('aprs_msg_session') || 'null'); if (_s) { _s.last_id = _msgLastId; localStorage.setItem('aprs_msg_session', JSON.stringify(_s)); } } catch {}
		}
	} catch {}
}

let _msgAudioCtx = null;
function _getMsgAudioCtx() {
	if (!_msgAudioCtx || _msgAudioCtx.state === 'closed') _msgAudioCtx = new AudioContext();
	return _msgAudioCtx;
}
function _warmMsgAudio() {
	try { _getMsgAudioCtx().resume(); } catch {}
}
function _playMsgTone() {
	try {
		const ctx = _getMsgAudioCtx();
		ctx.resume().then(() => {
			const osc1 = ctx.createOscillator();
			const osc2 = ctx.createOscillator();
			const gain = ctx.createGain();
			osc1.connect(gain); osc2.connect(gain); gain.connect(ctx.destination);
			osc1.frequency.value = 880; osc2.frequency.value = 1109;
			gain.gain.setValueAtTime(0, ctx.currentTime);
			gain.gain.linearRampToValueAtTime(0.35, ctx.currentTime + 0.05);
			gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.7);
			osc1.start(); osc2.start(ctx.currentTime + 0.25);
			osc1.stop(ctx.currentTime + 0.7); osc2.stop(ctx.currentTime + 0.7);
		});
	} catch {}
}

let _msgQueue = [];   // queued incoming messages waiting to display
let _msgModalOpen = false;
// Suppress incoming modals briefly on autologin startup to avoid showing
// messages that arrived while the Pi was offline (history-fail race condition).
const _msgStartupTs = Date.now();

function _showMsgModal(msg) {
	if (window._aprsAutoMsgPw && Date.now() - _msgStartupTs < 8000) return;
	_playMsgTone();
	_msgQueue.push(msg);
	if (!_msgModalOpen) _showNextMsgModal();
}

function _showNextMsgModal() {
	if (!_msgQueue.length) { _msgModalOpen = false; return; }
	_msgModalOpen = true;
	const msg = _msgQueue.shift();
	const canReply = msg.from !== 'web';
	document.getElementById('msg-incoming-from').style.display = 'none';
	// Show thread (prior messages with this sender)
	const threadEl = document.getElementById('msg-incoming-thread');
	const prior = _msgLog.filter(m => m.id !== msg.id && (m.from === msg.from || m.to === msg.from)).slice(-8);
	if (prior.length) {
		threadEl.innerHTML = prior.map(m => {
				const isMe = m.from === 'web';
				const who  = isMe ? `<span class="msg-thread-from msg-thread-web">${_esc(m.from_label)}</span>` : `<span class="msg-thread-from">${_esc(m.from_label)}</span>`;
				const t    = new Date(m.ts * 1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
				return `<div class="msg-thread-entry">${who}<span class="msg-thread-time">${t}</span><br>${_esc(m.text)}</div>`;
			}).join('');
		threadEl.style.display = '';
		threadEl.style.marginBottom = '0';
		setTimeout(() => { threadEl.scrollTop = threadEl.scrollHeight; }, 0);
	} else {
		threadEl.style.display = 'none';
	}
	// Render the new message as a thread entry with hr separator
	const isNewMe = msg.from === 'web';
	const newWho  = isNewMe ? `<span class="msg-thread-from msg-thread-web">${_esc(msg.from_label)}</span>` : `<span class="msg-thread-from">${_esc(msg.from_label)}</span>`;
	const newT    = new Date(msg.ts * 1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
	document.getElementById('msg-incoming-text').innerHTML =
		(prior.length ? '<hr style="margin:8px 0 10px">' : '')
		+ `<div class="msg-thread-entry" style="font-size:15px">${newWho}<span class="msg-thread-time">${newT}</span><br>${_esc(msg.text)}</div>`;
	const replyBtn = document.getElementById('msg-incoming-reply');
	replyBtn.style.display = canReply ? '' : 'none';
	replyBtn.onclick = () => {
		document.getElementById('msg-incoming-modal').style.display = 'none';
		_msgModalOpen = false;
		_showNextMsgModal();
		_showComposeModal(msg.from, msg.from_label + ' (' + msg.from + ')');
	};
	document.getElementById('msg-incoming-ok').onclick = () => {
		document.getElementById('msg-incoming-modal').style.display = 'none';
		_showNextMsgModal();
	};
	document.getElementById('msg-incoming-close').onclick = () => {
		document.getElementById('msg-incoming-modal').style.display = 'none';
		_msgQueue = [];
		_msgModalOpen = false;
	};
	document.getElementById('msg-incoming-backdrop').onclick = null; // don't close on backdrop click
	document.getElementById('msg-incoming-modal').style.display = 'flex';
}

function _esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Always poll for live tracker data; skip config polling only when previewing a non-default event
updateMap();
setInterval(updateMap, 5000);
if (!isNonDefaultEvent) {
	loadConfig();
	setInterval(loadConfig, 5000);
}
</script>
</body>
</html>
