#!/usr/bin/env php
<?php
/**
 * APRS Tracker Map — background daemon
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 *
 * Connects to an APRS-IS server, filters packets for the callsigns listed in
 * config.yaml, and writes live position/status data to trackers.json.
 *
 * Key behaviours:
 *   - Monitors config.yaml via filemtime; reloads and reconnects automatically
 *     when the file changes (new tracker, renamed callsign, etc.)
 *   - Preserves last-known position and update time across config reloads
 *   - Writes trackers.json immediately on config change so the browser picks
 *     up the new tracker list without waiting for the next APRS packet
 *   - Merges external lastUpdate edits from trackers.json on each packet cycle
 *
 * Usage: php aprsDaemon.php [config=<file>] [server=<host>] [trackerstatus=<file>]
 *                           [sleep=<seconds>] [debug]
 */

require_once 'config_parse.php';

//defaults
$debugging=false;
$configFilename="config.yaml";
$trackerStatusFilename="trackers.json";
$aprsLoginCommand="user K6DRK pass -1 vers AprsTopo 2.0 filter p";
$aprsServer="noam.aprs2.net";
$aprsPort=14580;
$minSecondsGreen=120;
$minSecondsBlue=300;
$sleepSeconds=5;
$socket=null;
$configFileMtime=0;
$igates=array();			// [{callsign, name, lastBeacon}] — only entries with a callsign
$igatesStatusFilename='igates.json';
$aidstations=array();		// [{callsign, name, lastBeacon}] — only entries with a callsign
$aidstationsStatusFilename='aidstations.json';
$trackerHistory  = [];		// callsign → [{lat, lon, ts}, ...]  max 10 entries each
$historyFilePath = null;	// full path to tracker_history.yaml in current event dir
$mobileRoot      = '';		// root callsign for mobile tracking (from config.yaml mobile.root)
$mobileEnabled   = false;
$mobileSessions  = [];		// callsign → ['id' => ..., 'name' => ...]  (active sessions)
$mobileMtime     = 0;
$mobileTrackersFile = 'mobile_trackers.json';

function debug($message) {
	global $debugging;
	if ($debugging) {
		echo("$message");
	}
}

function fatal($message) {
	echo("FATAL: $message\n");
	exit();
}

if(!defined('APRS_DAEMON_INCLUDE_ONLY') && isset($argc) && $argc>1) {
	parse_str(implode('&',array_slice($argv, 1)), $_GET);
	foreach ($_GET as $key=>$value) {
		switch ($key) {
			case "config":
				$configFilename=$value;
				break;
			case "server":
				$aprsServer=$value;
				break;
			case "trackerstatus":
				$trackerStatusFilename=$value;
				break;
			case "sleep":
				$sleepSeconds=(int)$value;
				break;
			case "debug":
				$debugging=true;
				break;
			default:
				commandLine ("Unknown command line argument: $key\n");
		}
	}
}

function commandLine ($message) {
	global $aprsServer,$configFilename,$trackerStatusFilename,$sleepSeconds;
	echo($message);
	echo("Command line syntax:\n");
	echo("  php aprsDaemon.php [server=<aprs_server>] [config=<config file>] [trackerstatus=<status file>] [sleep=<seconds>] [debug]\n");
	echo("Defaults\n");
	echo("  server=$aprsServer\n");
	echo("  config=$configFilename\n");
	echo("  trackerstatus=$trackerStatusFilename\n");
	echo("  sleep=$sleepSeconds\n");
	echo("config.yaml trackers section syntax:\n");
	echo('  - callsign: <callsign>  (e.g. W6SG-4)' . PHP_EOL);
	echo('    id: <shortId>         (e.g. S4)' . PHP_EOL);
	echo('    name: <name>          (e.g. Alice)' . PHP_EOL);
	exit();
}

if (!defined('APRS_DAEMON_INCLUDE_ONLY')) {
	if (!file_exists($configFilename)) {
		commandLine("CONFIG FILE DOESN'T EXIST!!\n");
	}
	if (!file_exists($trackerStatusFilename)) {
		if (file_put_contents($trackerStatusFilename, "") === false) {
			commandLine("CAN'T CREATE TRACKERSTATUS FILE!!\n");
		}
	}
	echo "----------\n";
	echo "config=$configFilename\n";
	echo "trackerstatus=$trackerStatusFilename\n";
	echo "----------\n\n";
}

// Parse latitude and longitude from an APRS packet line.
// Handles uncompressed, compressed (Base91), and Mic-E position formats.
// Supports timestamped packets (/ and @ DTI with DDHHMMz/h prefix) and overlay symbols.
// Returns [lat, lon] in decimal degrees, or [null, null] if not found.
function parseAprsPosition($line) {
	$colonPos = strpos($line, ':');
	if ($colonPos === false) return array(null, null);
	$payload = substr($line, $colonPos + 1);

	// Uncompressed: DDmm.mmN{sym}DDDmm.mmW — search anywhere in the payload.
	// No DTI anchor: handles both plain (!=/@) and timestamped (/@+7-byte prefix) packets.
	// Sym table char accepts /, \, A-Z, 0-9 (overlay symbols).
	if (preg_match('/(\d{4}\.\d{2})([NS])[\/\\\\A-Z0-9](\d{5}\.\d{2})([EW])/', $payload, $m)) {
		$lat = (float)substr($m[1], 0, 2) + (float)substr($m[1], 2) / 60.0;
		if ($m[2] === 'S') $lat = -$lat;
		$lon = (float)substr($m[3], 0, 3) + (float)substr($m[3], 3) / 60.0;
		if ($m[4] === 'W') $lon = -$lon;
		return array(round($lat, 6), round($lon, 6));
	}

	// Compressed Base91: DTI [DDHHMMz/h] sym_table 4-lat 4-lon sym_code
	// Optional 7-byte timestamp prefix handles / and @ (timestamped) DTI packets.
	// Sym table is / or \ only (overlays not used in compressed format per spec).
	if (preg_match('/[!=\/@](?:\d{6}[z\/h])?[\/\\\\]([\x21-\x7b]{4})([\x21-\x7b]{4})[\x21-\x7b]/', $payload, $m)) {
		$latVal = (ord($m[1][0])-33)*91**3 + (ord($m[1][1])-33)*91**2 + (ord($m[1][2])-33)*91 + (ord($m[1][3])-33);
		$lat = 90.0 - $latVal / 380926.0;
		$lonVal = (ord($m[2][0])-33)*91**3 + (ord($m[2][1])-33)*91**2 + (ord($m[2][2])-33)*91 + (ord($m[2][3])-33);
		$lon = -180.0 + $lonVal / 190463.0;
		return array(round($lat, 6), round($lon, 6));
	}

	// Mic-E: DTI '`' (current), '\'' (old), 0x1c (rev0 current), 0x1d (rev0 old)
	// latitude in destination address, longitude in payload bytes 1-3
	if (strlen($payload) >= 8 && ($payload[0] === '`' || $payload[0] === "'" || $payload[0] === "\x1c" || $payload[0] === "\x1d")) {
		// Extract destination field (between '>' and first ',' before ':')
		$arrowPos = strpos($line, '>');
		if ($arrowPos === false) return array(null, null);
		$header   = substr($line, $arrowPos + 1, $colonPos - $arrowPos - 1);
		$commaPos = strpos($header, ',');
		$dest     = $commaPos !== false ? substr($header, 0, $commaPos) : $header;
		$dashPos  = strpos($dest, '-');
		if ($dashPos !== false) $dest = substr($dest, 0, $dashPos);
		if (strlen($dest) < 6) return array(null, null);

		// Each destination char encodes a latitude digit 0-9
		$digits = array();
		for ($i = 0; $i < 6; $i++) {
			$c = ord($dest[$i]);
			if      ($c >= 0x30 && $c <= 0x39) $digits[] = $c - 0x30;  // '0'-'9'
			elseif  ($c >= 0x41 && $c <= 0x4A) $digits[] = $c - 0x41;  // 'A'-'J'
			elseif  ($c >= 0x50 && $c <= 0x59) $digits[] = $c - 0x50;  // 'P'-'Y'
			elseif  ($c === 0x4B || $c === 0x4C || $c === 0x5A) $digits[] = 0;  // K, L, Z (ambiguous)
			else return array(null, null);
		}

		// Latitude: degrees + minutes from dest digits
		$lat_deg = $digits[0] * 10 + $digits[1];
		$lat_min = ($digits[2] * 10 + $digits[3]) + ($digits[4] * 10 + $digits[5]) / 100.0;
		$lat     = $lat_deg + $lat_min / 60.0;

		// N/S: dest[3] is 'A'-'J' or 'P'-'Y' → North
		$c3 = ord($dest[3]);
		if (!(($c3 >= 0x41 && $c3 <= 0x4A) || ($c3 >= 0x50 && $c3 <= 0x59))) $lat = -$lat;

		// Longitude offset: dest[4] is 'A'-'J' or 'P'-'Y' → add 100° to degrees
		$c4        = ord($dest[4]);
		$lonOffset = (($c4 >= 0x41 && $c4 <= 0x4A) || ($c4 >= 0x50 && $c4 <= 0x59)) ? 100 : 0;

		// Longitude degrees from payload byte 1
		$lon_deg = ord($payload[1]) - 28 + $lonOffset;
		if ($lon_deg >= 180 && $lon_deg <= 189) $lon_deg -= 80;   // remap to 100-109
		if ($lon_deg >= 190)                    $lon_deg -= 190;  // remap to 0-9

		// E/W from dest[5]: A-J or P-Y = West per APRS spec; minutes ≥60 is legacy fallback
		$c5          = ord($dest[5]);
		$isWest      = ($c5 >= 0x41 && $c5 <= 0x4A) || ($c5 >= 0x50 && $c5 <= 0x59);
		$lon_min_raw = ord($payload[2]) - 28;
		if ($lon_min_raw >= 60) { $isWest = true; $lon_min_raw -= 60; }
		$lon_min     = $lon_min_raw;

		// Longitude hundredths of minute from payload byte 3
		$lon_h = ord($payload[3]) - 28;

		$lon = $lon_deg + ($lon_min + $lon_h / 100.0) / 60.0;
		if ($isWest) $lon = -$lon;

		return array(round($lat, 6), round($lon, 6));
	}

	return array(null, null);
}

// Resolve the history file path from the config symlink target
function resolveHistoryPath() {
	global $configFilename, $historyFilePath;
	$real = realpath($configFilename);
	$historyFilePath = $real ? dirname($real) . '/tracker_history.yaml' : null;
}

// Read tracker_history.yaml into $trackerHistory on startup / event switch
function readTrackerHistoryFile() {
	global $trackerHistory, $historyFilePath;
	$trackerHistory = [];
	if (!$historyFilePath || !file_exists($historyFilePath)) return;
	$fh = fopen($historyFilePath, 'r');
	if (!$fh) return;
	flock($fh, LOCK_SH);
	$lines = [];
	while (($line = fgets($fh)) !== false) $lines[] = rtrim($line);
	flock($fh, LOCK_UN);
	fclose($fh);
	$cs = null; $entry = null;
	foreach ($lines as $line) {
		if (!$line || $line[0] === '#') continue;
		if (preg_match('/^([A-Z0-9][\w\-]+):$/', $line, $m)) {
			$cs = $m[1]; $trackerHistory[$cs] = [];
		} elseif (preg_match('/^  - lat:\s*([\-\d.]+)/', $line, $m)) {
			$entry = ['lat' => (float)$m[1], 'lon' => 0.0, 'ts' => 0];
		} elseif (preg_match('/^    lon:\s*([\-\d.]+)/', $line, $m) && $entry !== null) {
			$entry['lon'] = (float)$m[1];
		} elseif (preg_match('/^    path:\s*(.+)/', $line, $m) && $entry !== null) {
			$entry['path'] = trim($m[1]);
		} elseif (preg_match('/^    ts:\s*(\d+)/', $line, $m) && $entry !== null) {
			$entry['ts'] = (int)$m[1];
			if (!isset($entry['path'])) $entry['path'] = '';
			if ($cs !== null) $trackerHistory[$cs][] = $entry;
			$entry = null;
		}
	}
	foreach ($trackerHistory as &$entries) $entries = array_slice($entries, 0, 10);
	unset($entries);
}

// Minimum movement (100 ft) required before recording a new breadcrumb or
// updating the displayed position. Filters GPS noise and timer-only beacons.
const MIN_MOVE_METRES = 30.48;

// Returns distance in metres between two WGS-84 lat/lon points (Haversine).
function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
	$R    = 6371000.0;
	$dLat = deg2rad($lat2 - $lat1);
	$dLon = deg2rad($lon2 - $lon1);
	$a    = sin($dLat / 2) ** 2
	      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
	return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Write $trackerHistory to tracker_history.yaml
function writeTrackerHistoryFile() {
	global $trackerHistory, $historyFilePath;
	if (!$historyFilePath) return;
	$yaml = "# Auto-generated by aprsDaemon — do not edit\n";
	foreach ($trackerHistory as $cs => $entries) {
		$yaml .= $cs . ":\n";
		foreach ($entries as $e) {
			$pathLine = (isset($e['path']) && $e['path'] !== '') ? "    path: {$e['path']}\n" : '';
			$yaml .= "  - lat: {$e['lat']}\n    lon: {$e['lon']}\n{$pathLine}    ts: {$e['ts']}\n";
		}
	}
	$fh = fopen($historyFilePath, 'w');
	if (!$fh) return;
	flock($fh, LOCK_EX);
	fwrite($fh, $yaml);
	flock($fh, LOCK_UN);
	fclose($fh);
}

// Close any open socket, rebuild the filter command from current $trackers, and reconnect
function connectToAprsServer() {
	global $socket,$trackers,$aprsLoginCommand,$aprsServer,$aprsPort,$mobileRoot,$mobileEnabled;
	if ($socket) {
		socket_close($socket);
		$socket=null;
	}
	$cmd=$aprsLoginCommand;
	foreach ($trackers as $tracker) $cmd .= "/" . $tracker["callsign"];
	if ($mobileEnabled && $mobileRoot !== '') $cmd .= "/" . $mobileRoot;
	$cmd .= "\r\n";
	debug("Command=$cmd");
	$socket=socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
	if (!$socket) fatal("Can't create socket\n");
	$ipv4=gethostbyname($aprsServer);
	if ($ipv4==$aprsServer) fatal("Can't resolve hostname\n");
	echo("Connecting to $ipv4\n");
	if (!socket_connect($socket,$ipv4,$aprsPort)) fatal("Can't connect to socket\n");
	socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 60, 'usec' => 0]);
	$bytesWritten=socket_write($socket,$cmd);
	if ($bytesWritten===FALSE) fatal("Can't write to socket");
}

// Read the trackers and igates sections from config.yaml, rebuilding both arrays
// while preserving live state for already-known callsigns.
// Returns true if the file was reloaded, false if it was unchanged since the last load.
function loadTrackers() {
	global $trackers,$igates,$aidstations,$configFilename,$configFileMtime,$mobileRoot,$mobileEnabled;
	$mtime = filemtime($configFilename);
	if ($mtime === $configFileMtime) return false;		//unchanged
	$existing = array();
	foreach ($trackers as $t) $existing[$t["callsign"]] = $t;
	$cfg = parseConfigYaml($configFilename);
	$new = array();
	foreach ($cfg['trackers'] as $entry) {
		if (!isset($entry['callsign'])) continue;
		$callsign = $entry['callsign'];
		if (isset($existing[$callsign])) {
			$e = $existing[$callsign];				//preserve live state
			$e["id"]   = $entry['id']   ?? $e["id"];	//allow id/name edits to take effect
			$e["name"] = $entry['name'] ?? $e["name"];
			$new[] = $e;
		} else {
			$new[] = array("callsign"=>$callsign, "id"=>$entry['id'] ?? '', "name"=>$entry['name'] ?? '', "lastUpdate"=>0, "lat"=>null, "lon"=>null, "path"=>'');
		}
	}
	$trackers = $new;

	// Rebuild igates — only entries with a callsign; preserve lastBeacon across reloads
	$existingIgates = array();
	foreach ($igates as $g) $existingIgates[$g["callsign"]] = $g;
	$newIgates = array();
	foreach ($cfg['igates'] ?? [] as $entry) {
		if (empty($entry['callsign'])) continue;
		$cs = $entry['callsign'];
		$newIgates[] = array(
			"callsign"   => $cs,
			"name"       => $entry['name'] ?? $cs,
			"lastBeacon" => $existingIgates[$cs]["lastBeacon"] ?? 0,
		);
	}
	$igates = $newIgates;

	// Rebuild aidstations — only entries with a callsign; preserve lastBeacon across reloads
	$existingAid = array();
	foreach ($aidstations as $g) $existingAid[$g["callsign"]] = $g;
	$newAid = array();
	foreach ($cfg['aidstations'] ?? [] as $entry) {
		if (empty($entry['callsign'])) continue;
		$cs = $entry['callsign'];
		$newAid[] = array(
			"callsign"   => $cs,
			"name"       => $entry['name'] ?? $cs,
			"lastBeacon" => $existingAid[$cs]["lastBeacon"] ?? 0,
		);
	}
	$aidstations = $newAid;

	$mob         = $cfg['mobile'] ?? [];
	$mobileEnabled = !empty($mob['enabled']) && $mob['enabled'] !== false;
	$mobileRoot    = strtoupper(trim($mob['root'] ?? ''));

	$configFileMtime = $mtime;
	return true;
}

// Load active mobile sessions from mobile_trackers.json.
// Returns true if reloaded, false if unchanged.
// Also prunes stale mobile tracker entries from $trackers when sessions are removed.
function loadMobileSessions() {
	global $mobileSessions,$mobileMtime,$mobileTrackersFile,$trackers;
	$mtime = file_exists($mobileTrackersFile) ? filemtime($mobileTrackersFile) : 0;
	if ($mtime === $mobileMtime) return false;
	$mobileMtime = $mtime;
	$fh = @fopen($mobileTrackersFile, 'r');
	$data = [];
	if ($fh) { flock($fh, LOCK_SH); $c = stream_get_contents($fh); flock($fh, LOCK_UN); fclose($fh); $data = json_decode($c, true) ?: []; }
	$now = time();
	$newSessions = [];
	foreach ($data as $t) {
		if (!empty($t['blocked'])) continue;			// blocked: ignore in daemon, ?json filters display
		if (($now - ($t['lastUpdate'] ?? 0)) > 86400) continue;	// expired
		$cs = $t['callsign'] ?? null;
		if (!$cs) continue;
		$newSessions[$cs] = ['id' => $t['id'], 'name' => $t['name']];
	}
	// Remove $trackers entries for sessions that are gone or blocked
	$trackers = array_values(array_filter($trackers, function($t) use ($newSessions) {
		return empty($t['mobile']) || isset($newSessions[$t['callsign']]);
	}));
	// Pre-add new sessions so they appear in trackers.json before first APRS beacon
	$knownCallsigns = array_column($trackers, 'callsign');
	foreach ($newSessions as $cs => $ms) {
		if (!in_array($cs, $knownCallsigns, true)) {
			$trackers[] = ['callsign' => $cs, 'id' => $ms['id'], 'name' => $ms['name'],
			               'lastUpdate' => 0, 'lat' => null, 'lon' => null, 'path' => '', 'mobile' => true];
		}
	}
	$mobileSessions = $newSessions;
	return true;
}

// Write last-beacon timestamps to a JSON file (callsign → unix timestamp map)
function writeBeaconFile($filename, $entries) {
	$output = array();
	foreach ($entries as $g) $output[$g["callsign"]] = $g["lastBeacon"];
	$fh = fopen($filename, 'w');
	if (!$fh) return;
	flock($fh, LOCK_EX);
	fwrite($fh, json_encode($output) . "\n");
	flock($fh, LOCK_UN);
	fclose($fh);
}

// Read trackers.json and update $trackers with the most recent lastUpdate and last known lat/lon for each callsign
function readTrackerstatusFile($filename) {
	global $trackers;
	$fh = fopen($filename, 'r');
	if (!$fh) return;
	if (!flock($fh, LOCK_SH)) { fclose($fh); return; }
	$contents = stream_get_contents($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	if (!$contents) return;
	$history = json_decode($contents, true);
	if (!is_array($history)) return;
	$byCallsign = array();
	foreach ($history as $entry) {
		$byCallsign[$entry["callsign"]] = $entry;
	}
	foreach ($trackers as $key=>$tracker) {
		$callsign = $tracker["callsign"];
		if (!isset($byCallsign[$callsign])) continue;
		$saved = $byCallsign[$callsign];
		if ($saved["lastUpdate"] > $tracker["lastUpdate"]) {
			$trackers[$key]["lastUpdate"] = $saved["lastUpdate"];
		}
		if ($tracker["lat"] === null && isset($saved["lat"])) {
			$trackers[$key]["lat"] = $saved["lat"];
			$trackers[$key]["lon"] = $saved["lon"];
		}
	}
}

// Write tracker status to trackers.json
function writeNewTrackerstatusFile($filename) {
	global $trackers,$minSecondsGreen,$minSecondsBlue;
	$now=time();
	$output=array();
	foreach ($trackers as $tracker) {
		$lastUpdate=$tracker["lastUpdate"];
		$timeSinceLastUpdate=$now-$lastUpdate;
		$seconds=$timeSinceLastUpdate % 60;
		$minutes=($timeSinceLastUpdate-$seconds)/60;
		if ($minutes>=60) {
			$hours = intdiv($minutes, 60);
			$mins  = $minutes % 60;
			$time  = $hours > 0 ? ">{$hours}h {$mins}m" : ">{$minutes}m";
		} else {$time=sprintf("%d:%02d",$minutes,$seconds);}
		if ($timeSinceLastUpdate<=$minSecondsGreen) {$color="green";}
		elseif ($timeSinceLastUpdate<=$minSecondsBlue) {$color="blue";}
		else {$color="red";}
		$entry = array(
			"callsign"=>$tracker["callsign"],
			"id"=>$tracker["id"],
			"name"=>$tracker["name"],
			"lastUpdate"=>$lastUpdate,
			"timeSinceLastUpdate"=>$timeSinceLastUpdate,
			"time"=>$time,
			"color"=>$color,
			"lat"=>$tracker["lat"],
			"lon"=>$tracker["lon"],
			"path"=>$tracker["path"] ?? ''
		);
		if (!empty($tracker["mobile"])) $entry["mobile"] = true;
		$output[]=$entry;
	}
	$fh = fopen($filename, 'w');
	if (!$fh) fatal("Can't open trackerstatus file for writing");
	flock($fh, LOCK_EX);
	fwrite($fh, json_encode($output, JSON_PRETTY_PRINT) . "\n");
	flock($fh, LOCK_UN);
	fclose($fh);
}

if (!defined('APRS_DAEMON_INCLUDE_ONLY')) {

$trackers=array();
loadTrackers();
if (empty($trackers)) fatal("No trackers loaded from $configFilename");

if (!is_writable($trackerStatusFilename)) fatal("Can't write to trackerstatus file");

readTrackerstatusFile($trackerStatusFilename);		//seed lastUpdate history from existing JSON
resolveHistoryPath();
readTrackerHistoryFile();
loadMobileSessions();
connectToAprsServer();

$prevCallsigns=array_column($trackers,'callsign');
sort($prevCallsigns);
$lastWriteTime = time();

while (TRUE) {
	// Watchdog: if APRS-IS hasn't delivered any data for 3 minutes, reconnect.
	// Prevents a rare stall where the socket stays ESTABLISHED but delivers no packets.
	if ((time() - $lastWriteTime) > 180) {
		echo("Watchdog: no data from APRS-IS for 3 minutes — reconnecting\n");
		connectToAprsServer();
		$lastWriteTime = time();
	}

	if (loadTrackers()) {								//only reloaded if mtime changed
		$currCallsigns=array_column($trackers,'callsign');
		sort($currCallsigns);
		if ($currCallsigns !== $prevCallsigns) {
			echo("Tracker list changed, reconnecting...\n");
			connectToAprsServer();
		}
		$prevCallsigns=$currCallsigns;
		writeNewTrackerstatusFile($trackerStatusFilename);	//push tracker list changes to browser immediately
		resolveHistoryPath();
		readTrackerHistoryFile();
	}
	loadMobileSessions();								//reload when mobile_trackers.json changes
	$line=socket_read($socket,1000,PHP_NORMAL_READ);
	if ($line === false || $line === '') fatal("Socket read failed (connection lost or timed out)");
	else {
		debug("Received: $line");
		if (strlen($line)>1) {									//more than just the line terminator?
			if (substr($line,0,1)!="#") {						//not a comment?
				$element = explode('>', $line);
				$callsign=$element[0];							//extract callsign
				list($lat,$lon) = parseAprsPosition($line);		//extract position if present

				// Extract via path: everything between '>' and ':' minus the destination field
				$aprsPath = '';
				if (isset($element[1])) {
					$headerBody = explode(':', $element[1], 2);
					$headerParts = explode(',', $headerBody[0]);
					array_shift($headerParts);					//remove destination (e.g. APRS, APX203)
					$aprsPath = implode(',', $headerParts);
				}

				foreach ($trackers as $key=>$tracker) {			//find it in our array
					if ($tracker["callsign"]==$callsign) {
						$trackers[$key]["lastUpdate"]=time();	//update time last seen for that callsign
						$trackers[$key]["path"]=$aprsPath;
						if ($lat !== null) {
							$lastCrumb = $trackerHistory[$callsign][0] ?? null;
							if ($lastCrumb === null || haversineMeters($lastCrumb['lat'], $lastCrumb['lon'], $lat, $lon) >= MIN_MOVE_METRES) {
								$trackers[$key]["lat"]=$lat;
								$trackers[$key]["lon"]=$lon;
								if (!isset($trackerHistory[$callsign])) $trackerHistory[$callsign] = [];
								array_unshift($trackerHistory[$callsign], ['lat'=>$lat,'lon'=>$lon,'path'=>$aprsPath,'ts'=>time()]);
								if (count($trackerHistory[$callsign]) > 10) array_pop($trackerHistory[$callsign]);
								writeTrackerHistoryFile();
							}
						}
					}
				}

				// Handle mobile tracker packets (callsigns assigned via mobile sessions)
				if ($mobileEnabled && isset($mobileSessions[$callsign])) {
					$ms = $mobileSessions[$callsign];
					$foundKey = null;
					foreach ($trackers as $key => $t) {
						if ($t['callsign'] === $callsign) { $foundKey = $key; break; }
					}
					if ($foundKey === null) {
						// Session pre-added by loadMobileSessions but no beacon yet — update it
						$trackers[] = ['callsign' => $callsign, 'id' => $ms['id'], 'name' => $ms['name'],
						               'lastUpdate' => time(), 'lat' => $lat, 'lon' => $lon,
						               'path' => $aprsPath, 'mobile' => true];
						$foundKey = array_key_last($trackers);
					} else {
						$trackers[$foundKey]['lastUpdate'] = time();
						$trackers[$foundKey]['path']       = $aprsPath;
						$trackers[$foundKey]['name']       = $ms['name'];
					}
					if ($lat !== null) {
						$lastCrumb = $trackerHistory[$callsign][0] ?? null;
						if ($lastCrumb === null || haversineMeters($lastCrumb['lat'], $lastCrumb['lon'], $lat, $lon) >= MIN_MOVE_METRES) {
							$trackers[$foundKey]['lat'] = $lat;
							$trackers[$foundKey]['lon'] = $lon;
							if (!isset($trackerHistory[$callsign])) $trackerHistory[$callsign] = [];
							array_unshift($trackerHistory[$callsign], ['lat'=>$lat,'lon'=>$lon,'path'=>$aprsPath,'ts'=>time()]);
							if (count($trackerHistory[$callsign]) > 10) array_pop($trackerHistory[$callsign]);
							writeTrackerHistoryFile();
						}
					}
				}

				// Detect iGate/aid-station activity: only when a listed tracker's packet was gated
				// through one of our stations (q-construct in path, e.g. qAR,K6DRK-6).
				if (!empty($igates) || !empty($aidstations)) {
					$isOurTracker = false;
					foreach ($trackers as $tracker) {
						if ($tracker["callsign"] === $callsign) { $isOurTracker = true; break; }
					}
					if ($isOurTracker) {
						$pathParts = explode(',', $aprsPath);
						$igateUpdated = false;
						foreach ($igates as $gkey => $igate) {
							$gcs = $igate["callsign"];
							for ($pi = 0; $pi < count($pathParts) - 1; $pi++) {
								if (preg_match('/^q[A-Z]+$/', $pathParts[$pi]) && rtrim($pathParts[$pi+1], '*') === $gcs) {
									$igates[$gkey]["lastBeacon"] = time();
									$igateUpdated = true;
									break;
								}
							}
						}
						if ($igateUpdated) writeBeaconFile($igatesStatusFilename, $igates);
						$aidUpdated = false;
						foreach ($aidstations as $akey => $aid) {
							$gcs = $aid["callsign"];
							for ($pi = 0; $pi < count($pathParts) - 1; $pi++) {
								if (preg_match('/^q[A-Z]+$/', $pathParts[$pi]) && rtrim($pathParts[$pi+1], '*') === $gcs) {
									$aidstations[$akey]["lastBeacon"] = time();
									$aidUpdated = true;
									break;
								}
							}
						}
						if ($aidUpdated) writeBeaconFile($aidstationsStatusFilename, $aidstations);
					}
				}
			}
		}
		readTrackerstatusFile($trackerStatusFilename);		//merge any external lastUpdate changes from JSON
		writeNewTrackerstatusFile($trackerStatusFilename);	//update all any time we receive any line (~20 secs)
		$lastWriteTime = time();
		sleep($sleepSeconds);
	}
}

} // end APRS_DAEMON_INCLUDE_ONLY guard
?>
