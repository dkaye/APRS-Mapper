<?php
ini_set('display_errors', '0');
session_start();
/**
 * MARS APRS Map Admin
 *
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 *
 * Serves at http://<host>/admin (Apache redirects /admin → /admin/).
 * Provides a web GUI for editing all fields in config.yaml.
 *
 * Password is stored in plain text in admin/password.txt.
 * To change it, edit that file directly on the server.
 *
 * Endpoints (same file, query-string routed):
 *   (none)  Login form (if not authenticated) or admin UI
 *   ?load   GET  — returns current config.yaml as JSON
 *   ?save   POST — accepts JSON body, validates, and writes config.yaml
 *   ?logout GET  — destroys the session and returns to the login form
 */

$configPath   = __DIR__ . '/../config.yaml';
$passwordFile = __DIR__ . '/password.txt';
$versionsDir  = __DIR__ . '/../configs';	// named config snapshots live here

// ── Authentication ────────────────────────────────────────────────────────────

$storedPass = file_exists($passwordFile) ? trim(file_get_contents($passwordFile)) : '';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Login form POST
$loginError = '';
if (!isset($_SESSION['aprs_admin_authed'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pw'])) {
    if ($storedPass !== '' && $_POST['pw'] === $storedPass) {
        $_SESSION['aprs_admin_authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Incorrect password';
}

$authed = isset($_SESSION['aprs_admin_authed']) && $_SESSION['aprs_admin_authed'] === true;

if (!$authed) {
    // AJAX endpoints return 401 JSON; all other requests get the login form
    if (isset($_GET['load']) || isset($_GET['save']) || isset($_GET['versions']) || isset($_GET['saveversion']) || isset($_GET['loadversion']) || isset($_GET['deleteversion']) || isset($_GET['locationfiles']) || isset($_GET['upload']) || isset($_GET['renamefile']) || isset($_GET['deletefile'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Session expired — reload and log in again']);
        exit;
    }
    renderLogin($loginError);
    exit;
}

// ── AJAX: load ────────────────────────────────────────────────────────────────

if (isset($_GET['load'])) {
    require_once __DIR__ . '/../config_parse.php';
    $cfg = parseConfigYaml($configPath);
    // Annotate each course with whether its file exists on disk
    foreach (($cfg['courses'] ?? []) as $i => $c) {
        $cfg['courses'][$i]['_exists'] = isset($c['file']) && file_exists(__DIR__ . '/../' . $c['file']);
    }
    header('Content-Type: application/json');
    echo json_encode($cfg);
    exit;
}

// ── AJAX: save ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['save'])) {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $cfg  = json_decode($body, true);
    if (!is_array($cfg)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }

    $errors = [];
    foreach ($cfg['trackers'] ?? [] as $i => $t) {
        if (trim($t['callsign'] ?? '') === '') $errors[] = 'Tracker ' . ($i + 1) . ': callsign is required';
        if (trim($t['id']       ?? '') === '') $errors[] = 'Tracker ' . ($i + 1) . ': ID is required';
    }
    foreach ($cfg['aidstations'] ?? [] as $i => $g) {
        if (trim($g['name'] ?? '') === '')                                         $errors[] = 'Aid Station ' . ($i + 1) . ': name is required';
        if (!is_numeric($g['lat'] ?? '') || $g['lat'] < -90  || $g['lat'] > 90)   $errors[] = 'Aid Station ' . ($i + 1) . ': latitude must be −90 to 90';
        if (!is_numeric($g['lon'] ?? '') || $g['lon'] < -180 || $g['lon'] > 180)  $errors[] = 'Aid Station ' . ($i + 1) . ': longitude must be −180 to 180';
    }
    foreach ($cfg['igates'] ?? [] as $i => $g) {
        if (trim($g['name'] ?? '') === '')                                         $errors[] = 'iGate ' . ($i + 1) . ': name is required';
        if (!is_numeric($g['lat'] ?? '') || $g['lat'] < -90  || $g['lat'] > 90)   $errors[] = 'iGate ' . ($i + 1) . ': latitude must be −90 to 90';
        if (!is_numeric($g['lon'] ?? '') || $g['lon'] < -180 || $g['lon'] > 180)  $errors[] = 'iGate ' . ($i + 1) . ': longitude must be −180 to 180';
    }
    $lat  = $cfg['map']['lat']  ?? '';
    $lon  = $cfg['map']['lon']  ?? '';
    $zoom = $cfg['map']['zoom'] ?? '';
    if (!is_numeric($lat)  || $lat < -90   || $lat > 90)   $errors[] = 'Map: latitude must be −90 to 90';
    if (!is_numeric($lon)  || $lon < -180  || $lon > 180)  $errors[] = 'Map: longitude must be −180 to 180';
    if (!is_numeric($zoom) || $zoom < 0    || $zoom > 19)  $errors[] = 'Map: zoom must be 0 to 19';

    if ($errors) {
        http_response_code(422);
        echo json_encode(['errors' => $errors]);
        exit;
    }

    $existing = file_exists($configPath) ? file_get_contents($configPath) : '';
    $history  = extractHistory($existing);
    array_unshift($history, gmdate('Y-m-d H:i:s') . ' UTC');
    $yaml = buildConfigYaml($cfg, $history);
    if (file_put_contents($configPath, $yaml, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write config.yaml — check file permissions']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: list saved versions ─────────────────────────────────────────────────

if (isset($_GET['versions'])) {
    $list = [];
    if (is_dir($versionsDir)) {
        foreach (glob($versionsDir . '/*.yaml') ?: [] as $f) {
            $list[] = ['name' => basename($f, '.yaml'), 'mtime' => filemtime($f)];
        }
    }
    usort($list, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    header('Content-Type: application/json');
    echo json_encode($list);
    exit;
}

// ── AJAX: save a named version ────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['saveversion'])) {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['name']) || !isset($data['cfg'])) {
        http_response_code(400); echo json_encode(['error' => 'Invalid request']); exit;
    }
    $name = trim($data['name']);
    if (!preg_match('/^[a-zA-Z0-9 _\-\.]{1,80}$/', $name)) {
        http_response_code(400); echo json_encode(['error' => 'Name may only contain letters, numbers, spaces, hyphens, underscores, periods (max 80 chars)']); exit;
    }
    if (!is_dir($versionsDir)) mkdir($versionsDir, 0755, true);
    $path     = $versionsDir . '/' . $name . '.yaml';
    $existing = file_exists($path) ? file_get_contents($path) : '';
    $history  = extractHistory($existing);
    array_unshift($history, gmdate('Y-m-d H:i:s') . ' UTC');
    $yaml = buildConfigYaml($data['cfg'], $history);
    if (file_put_contents($path, $yaml, LOCK_EX) === false) {
        http_response_code(500); echo json_encode(['error' => 'Cannot write version file — check permissions']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: load a named version ────────────────────────────────────────────────

if (isset($_GET['loadversion'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['name'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9 _\-\.]{1,80}$/', $name)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid name']); exit;
    }
    $path = $versionsDir . '/' . $name . '.yaml';
    if (!file_exists($path)) {
        http_response_code(404); echo json_encode(['error' => 'Version not found']); exit;
    }
    require_once __DIR__ . '/../config_parse.php';
    $cfg = parseConfigYaml($path);
    foreach (($cfg['courses'] ?? []) as $i => $c) {
        $cfg['courses'][$i]['_exists'] = isset($c['file']) && file_exists(__DIR__ . '/../' . $c['file']);
    }
    echo json_encode($cfg);
    exit;
}

// ── AJAX: delete a named version ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['deleteversion'])) {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    $name = trim($data['name'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9 _\-\.]{1,80}$/', $name)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid name']); exit;
    }
    $path = $versionsDir . '/' . $name . '.yaml';
    if (!file_exists($path)) {
        http_response_code(404); echo json_encode(['error' => 'Not found']); exit;
    }
    if (!unlink($path)) {
        http_response_code(500); echo json_encode(['error' => 'Cannot delete — check permissions']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: list location files ─────────────────────────────────────────────────

if (isset($_GET['locationfiles'])) {
    header('Content-Type: application/json');
    $exts  = ['gpx', 'kml', 'geojson', 'json'];
    $files = [];
    if (is_dir($versionsDir)) {
        foreach (glob($versionsDir . '/*') ?: [] as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $exts, true) && is_file($f)) {
                $files[] = ['path' => 'configs/' . basename($f), 'mtime' => filemtime($f)];
            }
        }
    }
    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
    echo json_encode($files);
    exit;
}

// ── AJAX: upload a location file ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['upload'])) {
    header('Content-Type: application/json');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $code = $_FILES['file']['error'] ?? -1;
        echo json_encode(['error' => 'Upload failed (error code ' . $code . ')']);
        exit;
    }
    $origName = basename($_FILES['file']['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['gpx', 'kml', 'geojson', 'json'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported file type — use .gpx, .kml, .geojson, or .json']);
        exit;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
    if (!is_dir($versionsDir)) mkdir($versionsDir, 0755, true);
    $dest = $versionsDir . '/' . $safeName;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not save file — check directory permissions']);
        exit;
    }
    echo json_encode(['ok' => true, 'file' => 'configs/' . $safeName]);
    exit;
}

// ── AJAX: rename a location file ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['renamefile'])) {
    header('Content-Type: application/json');
    $body    = file_get_contents('php://input');
    $data    = json_decode($body, true);
    $oldName = basename(trim($data['oldName'] ?? ''));
    $newName = basename(trim($data['newName'] ?? ''));
    $nameRx  = '/^[a-zA-Z0-9_\-\.]{1,80}$/';
    $locExts = ['gpx', 'kml', 'geojson', 'json'];
    if (!preg_match($nameRx, $oldName) || !preg_match($nameRx, $newName)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid filename']); exit;
    }
    if (!in_array(strtolower(pathinfo($newName, PATHINFO_EXTENSION)), $locExts, true)) {
        http_response_code(400); echo json_encode(['error' => 'Extension must be .gpx, .kml, .geojson, or .json']); exit;
    }
    $oldPath = $versionsDir . '/' . $oldName;
    $newPath = $versionsDir . '/' . $newName;
    if (!file_exists($oldPath)) {
        http_response_code(404); echo json_encode(['error' => 'File not found']); exit;
    }
    if ($oldName !== $newName && file_exists($newPath)) {
        http_response_code(409); echo json_encode(['error' => 'A file with that name already exists']); exit;
    }
    if (!rename($oldPath, $newPath)) {
        http_response_code(500); echo json_encode(['error' => 'Rename failed — check permissions']); exit;
    }
    echo json_encode(['ok' => true, 'oldFile' => 'configs/' . $oldName, 'newFile' => 'configs/' . $newName]);
    exit;
}

// ── AJAX: delete a location file ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['deletefile'])) {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    $name = basename(trim($data['name'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_\-\.]{1,80}$/', $name)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid filename']); exit;
    }
    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['gpx', 'kml', 'geojson', 'json'], true)) {
        http_response_code(400); echo json_encode(['error' => 'Not a location file']); exit;
    }
    $path = $versionsDir . '/' . $name;
    if (!file_exists($path)) {
        http_response_code(404); echo json_encode(['error' => 'File not found']); exit;
    }
    if (!unlink($path)) {
        http_response_code(500); echo json_encode(['error' => 'Delete failed — check permissions']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Login form ────────────────────────────────────────────────────────────────

function renderLogin($error = '') { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MARS APRS Map Admin</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: arial, helvetica, sans-serif; font-size: 14px;
    background: #eef0f3; min-height: 100vh;
    display: flex; flex-direction: column;
}
#hdr {
    background: #2c3e50; color: #fff;
    padding: 10px 20px;
}
#hdr h1 { font-size: 16px; font-weight: bold; }
#content {
    flex: 1; display: flex; align-items: center; justify-content: center;
    padding: 40px 20px;
}
.card {
    background: #fff; border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,.12);
    padding: 32px 36px; width: 100%; max-width: 300px;
}
.card h2 { font-size: 15px; color: #333; margin-bottom: 20px; }
.field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.field label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .04em; }
.field input {
    padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
    font-size: 14px; font-family: inherit;
}
.field input:focus { outline: none; border-color: #2980b9; }
.submit-btn {
    width: 100%; padding: 9px; background: #2980b9; color: #fff;
    border: none; border-radius: 4px; font-size: 14px;
    font-weight: bold; cursor: pointer;
}
.submit-btn:hover { background: #1f6da0; }
.error {
    background: #fff0f0; border: 1px solid #f5c6c6; border-radius: 4px;
    padding: 8px 12px; color: #c0392b; font-size: 13px; margin-bottom: 14px;
}
</style>
</head>
<body>
<div id="hdr"><h1>MARS APRS Map Admin</h1></div>
<div id="content">
    <div class="card">
        <h2>Sign in</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="field">
                <label for="pw">Password</label>
                <input type="password" id="pw" name="pw" autofocus autocomplete="current-password">
            </div>
            <button type="submit" class="submit-btn">Sign In</button>
        </form>
    </div>
</div>
</body>
</html>
<?php }

// ── YAML serialiser ───────────────────────────────────────────────────────────

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
    $L[] = 'backgrounds:';
    foreach ($cfg['backgrounds'] ?? [] as $b) {
        $L[] = '  - name: '        . ys($b['name']        ?? '');
        $L[] = '    url: '         . ys($b['url']         ?? '');
        $L[] = '    attribution: ' . yq($b['attribution'] ?? '');
    }
    $L[] = '';
    $L[] = '# ── Courses ───────────────────────────────────────────────────────────────────';
    $L[] = '# GPX/KML/GeoJSON overlays listed in the sidebar. Click a name to toggle it on/off.';
    $L[] = '# Multiple courses may be active simultaneously.';
    $L[] = '# Files live in the configs/ subdirectory.';
    $L[] = '#   name  : label shown in the sidebar';
    $L[] = '#   file  : filename  (supported extensions: .gpx  .kml  .geojson  .json)';
    $L[] = '#   color : hex color for the course line/markers (e.g. #2196f3)';
    $L[] = 'courses:';
    foreach ($cfg['courses'] ?? [] as $c) {
        $L[] = '  - name:  ' . ys($c['name'] ?? '');
        $L[] = '    file:  ' . ys($c['file'] ?? '');
        if (!empty($c['color'])) $L[] = '    color: ' . ys($c['color']);
    }
    $L[] = '';
    $L[] = '# ── Aid Stations ──────────────────────────────────────────────────────────────';
    $L[] = '# Aid station locations shown as black dots on the map and listed in the sidebar.';
    $L[] = '#   name : label shown in the sidebar and on hover';
    $L[] = '#   lat  : latitude  (decimal degrees; positive = North, negative = South)';
    $L[] = '#   lon  : longitude (decimal degrees; positive = East,  negative = West)';
    $L[] = 'aidstations:';
    foreach ($cfg['aidstations'] ?? [] as $g) {
        $L[] = '  - name: ' . ys($g['name'] ?? '');
        $L[] = '    lat: '  . (is_numeric($g['lat'] ?? '') ? (float)$g['lat'] : 0);
        $L[] = '    lon: '  . (is_numeric($g['lon'] ?? '') ? (float)$g['lon'] : 0);
    }
    $L[] = '';
    $L[] = '# ── iGates ────────────────────────────────────────────────────────────────────';
    $L[] = '# APRS iGate stations shown as black dots on the map and listed in the sidebar.';
    $L[] = '#   name : label shown in the sidebar and on hover';
    $L[] = '#   lat  : latitude  (decimal degrees; positive = North, negative = South)';
    $L[] = '#   lon  : longitude (decimal degrees; positive = East,  negative = West)';
    $L[] = 'igates:';
    foreach ($cfg['igates'] ?? [] as $g) {
        $L[] = '  - name: ' . ys($g['name'] ?? '');
        $L[] = '    lat: '  . (is_numeric($g['lat'] ?? '') ? (float)$g['lat'] : 0);
        $L[] = '    lon: '  . (is_numeric($g['lon'] ?? '') ? (float)$g['lon'] : 0);
    }
    $L[] = '';
    return implode("\n", $L);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MARS APRS Map Admin</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: arial, helvetica, sans-serif; font-size: 14px; background: #eef0f3; color: #222; }

/* ── Layout ── */
#wrap { max-width: 860px; margin: 0 auto; padding: 0 16px 40px; }

/* ── Sticky header ── */
#hdr {
    position: sticky; top: 0; z-index: 100;
    display: flex; align-items: center; justify-content: space-between;
    background: #2c3e50; color: #fff;
    padding: 10px 32px;
    margin: 0 0 24px;
    box-shadow: 0 2px 6px rgba(0,0,0,.25);
}
#hdr-left { display: flex; flex-direction: column; gap: 2px; }
#hdr h1 { font-size: 16px; font-weight: bold; letter-spacing: .02em; }
#current-file { font-size: 12px; color: #8aafc8; font-family: monospace; letter-spacing: .01em; }
#hdr-right { display: flex; align-items: center; gap: 14px; }
#hdr a { color: #adc8e6; font-size: 13px; text-decoration: none; }
#hdr a:hover { color: #fff; }
#hdr a.signout { color: #c8a8a8; }
#hdr a.signout:hover { color: #ffaaaa; }
#status { font-size: 13px; min-width: 110px; text-align: right; }
.st-dirty  { color: #f0c060; }
.st-ok     { color: #7ed97e; }
.st-error  { color: #ff7c7c; }

/* ── Save button ── */
.save-btn {
    padding: 7px 20px; background: #2980b9; color: #fff;
    border: none; border-radius: 4px; font-size: 13px; font-weight: bold;
    cursor: pointer; white-space: nowrap;
}
.save-btn:hover:not(:disabled) { background: #1f6da0; }
.save-btn:disabled { opacity: .5; cursor: default; }

/* ── Section cards ── */
.section { background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 20px; overflow: hidden; }
.sec-title {
    font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .08em;
    color: #555; background: #f5f6f8; border-bottom: 1px solid #e0e0e0;
    padding: 8px 14px;
}
.sec-body { padding: 10px 14px 14px; }

/* ── List rows ── */
.list-row {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 7px 6px; border-radius: 4px; margin-bottom: 4px;
    border: 1px solid transparent;
    transition: background .1s;
}
.list-row:hover { background: #f9f9fb; }
.list-row.dragging { opacity: .45; }
.list-row.drag-over { border-color: #2980b9; background: #eaf4fd; }

.drag-handle {
    cursor: grab; color: #aaa; font-size: 16px; padding: 4px 2px 0; line-height: 1;
    user-select: none; flex-shrink: 0;
}
.drag-handle:active { cursor: grabbing; }

.row-fields { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; flex: 1; }

/* ── Backgrounds have 3-line layout ── */
.bg-fields { flex-direction: column; align-items: stretch; gap: 5px; }
.bg-line { display: flex; align-items: center; gap: 10px; }

/* ── Field labels ── */
.field-label { display: flex; flex-direction: column; gap: 2px; }
.field-label span { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .04em; }

input[type=text], input[type=number] {
    padding: 5px 7px; border: 1px solid #ccc; border-radius: 3px;
    font-size: 13px; font-family: inherit;
    transition: border-color .15s;
}
input[type=text]:focus, input[type=number]:focus { outline: none; border-color: #2980b9; }
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; appearance: textfield; }

/* ── Delete button ── */
.del-btn {
    flex-shrink: 0; margin-top: 14px;
    background: none; border: none; color: #bbb; font-size: 16px;
    cursor: pointer; padding: 2px 4px; border-radius: 3px; line-height: 1;
}
.del-btn:hover { color: #e74c3c; background: #fdf0f0; }

/* ── Add button ── */
.add-btn {
    margin-top: 4px; padding: 6px 14px;
    background: #f0f4f8; border: 1px solid #c8d4e0; border-radius: 4px;
    font-size: 13px; color: #3a6ea8; cursor: pointer;
}
.add-btn:hover { background: #e2eaf4; }

/* ── Map view fields ── */
.map-fields { display: flex; gap: 20px; flex-wrap: wrap; padding: 4px 0; }
.map-field { display: flex; flex-direction: column; gap: 4px; }
.map-field label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .04em; }
.map-hint { font-size: 11px; color: #aaa; margin-top: 6px; }

/* ── Course file status / timestamp ── */
.file-status { font-size: 11px; color: #aaa; margin-top: 20px; flex-shrink: 0; white-space: nowrap; }
.file-status.missing { font-size: 14px; color: #e74c3c; }

/* ── Course file select ── */
select.f-file-select {
    padding: 5px 7px; border: 1px solid #ccc; border-radius: 3px;
    font-size: 13px; font-family: inherit; background: #fff;
    min-width: 200px; max-width: 280px;
    transition: border-color .15s;
}
select.f-file-select:focus { outline: none; border-color: #2980b9; }

/* ── Location file manager modal ── */
.lf-upload-zone {
    border: 2px dashed #c8d4e0; border-radius: 6px; padding: 16px 12px;
    text-align: center; color: #888; font-size: 13px; cursor: pointer;
    transition: border-color .15s, background .15s; margin-bottom: 12px;
}
.lf-upload-zone:hover, .lf-upload-zone.drag-over {
    border-color: #2980b9; background: #eaf4fd; color: #2980b9;
}
.lf-msg { font-size: 12px; min-height: 16px; margin-bottom: 8px; }
.lf-section-label {
    font-size: 11px; font-weight: bold; text-transform: uppercase;
    letter-spacing: .06em; color: #999; margin-bottom: 5px;
}
.lf-list { border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; max-height: 300px; overflow-y: auto; }
.lf-row {
    display: flex; align-items: center; gap: 4px;
    padding: 7px 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px;
}
.lf-row:last-child { border-bottom: none; }
.lf-name { flex: 1; word-break: break-all; }
.lf-input {
    flex: 1; padding: 3px 6px; border: 1px solid #2980b9; border-radius: 3px;
    font-size: 13px; font-family: inherit;
}
.lf-input:focus { outline: none; }
.lf-icon-btn {
    background: none; border: none; cursor: pointer; color: #999;
    font-size: 14px; padding: 2px 5px; border-radius: 3px; line-height: 1;
    flex-shrink: 0; text-decoration: none;
}
.lf-icon-btn:hover         { color: #333;     background: #f0f0f0; }
.lf-icon-btn.del:hover     { color: #e74c3c;  background: #fdf0f0; }
.lf-icon-btn.save:hover    { color: #2980b9;  background: #eaf4fd; }
.lf-small-btn {
    padding: 3px 9px; border-radius: 3px; font-size: 12px;
    cursor: pointer; flex-shrink: 0; border: 1px solid transparent;
}
.lf-small-btn.ok     { background: #2980b9; color: #fff; }
.lf-small-btn.ok:hover   { background: #1f6da0; }
.lf-small-btn.cancel { background: #f0f0f0; color: #555; border-color: #ccc; }
.lf-small-btn.cancel:hover { background: #e0e0e0; }

/* ── Action footer (Save/Update buttons) ── */
#footer { display: flex; justify-content: flex-end; padding-top: 4px; }

/* ── Page footer ── */
#page-footer {
    text-align: center; padding: 18px 16px;
    font-size: 12px; color: #999;
}

/* ── Error list ── */
#error-box {
    display: none; background: #fff0f0; border: 1px solid #f5c6c6;
    border-radius: 5px; padding: 10px 14px; margin-bottom: 16px; color: #c0392b;
    font-size: 13px; line-height: 1.6;
}

/* ── Secondary button (Save As / Load) ── */
.sec-btn {
    padding: 7px 14px; background: #fff; color: #2980b9;
    border: 1px solid #2980b9; border-radius: 4px; font-size: 13px;
    cursor: pointer; white-space: nowrap;
}
.sec-btn:hover { background: #eaf4fd; }

/* ── Modal ── */
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    z-index: 500;
}
.modal-box {
    background: #fff; border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,.25);
    width: 420px; max-width: calc(100vw - 32px); display: flex; flex-direction: column;
    max-height: calc(100vh - 64px);
}
.modal-title {
    font-size: 15px; font-weight: bold; color: #333;
    padding: 14px 18px 10px; border-bottom: 1px solid #e8e8e8; flex-shrink: 0;
}
.modal-body { padding: 14px 18px; overflow-y: auto; flex: 1; }
.modal-footer {
    padding: 10px 18px; border-top: 1px solid #e8e8e8;
    display: flex; justify-content: flex-end; gap: 8px; flex-shrink: 0;
}
.modal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.modal-field label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .04em; }
.modal-field input { padding: 7px 9px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; font-family: inherit; }
.modal-field input:focus { outline: none; border-color: #2980b9; }
.modal-warn { font-size: 12px; color: #b26a00; background: #fff8e8; border: 1px solid #f0d080; border-radius: 4px; padding: 5px 9px; margin-bottom: 10px; }
.modal-list-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
.modal-list { border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; max-height: 280px; overflow-y: auto; }
.modal-list-item {
    display: flex; align-items: center; padding: 9px 12px;
    font-size: 13px; border-bottom: 1px solid #f0f0f0; cursor: pointer;
}
.modal-list-item:last-child { border-bottom: none; }
.modal-list-item:hover { background: #eaf4fd; }
.modal-list-item .item-name { flex: 1; }
.modal-list-item .item-date { font-size: 11px; color: #999; margin-right: 8px; white-space: nowrap; }
.modal-list-item .item-del {
    background: none; border: none; color: #ccc; font-size: 13px;
    cursor: pointer; padding: 1px 4px; border-radius: 3px; line-height: 1; flex-shrink: 0;
}
.modal-list-item .item-del:hover { color: #e74c3c; background: #fdf0f0; }
.modal-list-live { padding: 9px 12px; font-size: 13px; color: #27ae60; border-bottom: 1px solid #f0f0f0; cursor: pointer; }
.modal-list-live:hover { background: #eafaf1; }
.modal-empty { padding: 12px; font-size: 13px; color: #999; text-align: center; }
.modal-cancel-btn {
    padding: 7px 14px; background: #f4f4f4; color: #555;
    border: 1px solid #ccc; border-radius: 4px; font-size: 13px; cursor: pointer;
}
.modal-cancel-btn:hover { background: #e8e8e8; }
</style>
</head>
<body>
<div id="hdr">
    <div id="hdr-left">
        <h1>MARS APRS Map Admin</h1>
        <span id="current-file">config.yaml</span>
    </div>
    <div id="hdr-right">
        <a href="/">View Map</a>
        <a href="?logout" class="signout">Sign out</a>
        <span id="status"></span>
        <button class="sec-btn" onclick="doLoadModal()">Load…</button>
        <button class="sec-btn" onclick="doSaveAs()">Save As…</button>
        <button class="save-btn" id="save-btn" onclick="doUpdate()">Update</button>
    </div>
</div>

<div id="wrap">
    <div id="error-box"></div>

    <!-- ── Event ── -->
    <div class="section">
        <div class="sec-title">Event</div>
        <div class="sec-body">
            <div class="map-field" style="width:100%;max-width:500px">
                <label for="f-event">Event Name</label>
                <input type="text" id="f-event" style="width:100%" placeholder="e.g. Marin Ultra Challenge 2026" oninput="markDirty()">
            </div>
        </div>
    </div>

    <!-- ── Trackers ── -->
    <div class="section">
        <div class="sec-title">Trackers</div>
        <div class="sec-body">
            <div id="trackers-list"></div>
            <button class="add-btn" onclick="addTracker()">+ Add Tracker</button>
        </div>
    </div>

    <!-- ── Map ── -->
    <div class="section">
        <div class="sec-title">Map Default View</div>
        <div class="sec-body">
            <div class="map-fields">
                <div class="map-field">
                    <label for="map-lat">Latitude</label>
                    <input type="number" id="map-lat" step="any" min="-90" max="90" style="width:130px" oninput="markDirty()">
                </div>
                <div class="map-field">
                    <label for="map-lon">Longitude</label>
                    <input type="number" id="map-lon" step="any" min="-180" max="180" style="width:140px" oninput="markDirty()">
                </div>
                <div class="map-field">
                    <label for="map-zoom">Zoom</label>
                    <input type="number" id="map-zoom" min="0" max="19" step="1" style="width:70px" oninput="markDirty()">
                </div>
                <div class="map-field" style="align-self:flex-end;margin-bottom:2px">
                    <button class="add-btn" onclick="useCurrentMap()" title="Populate fields from the currently displayed map">Use Current Map</button>
                </div>
            </div>
            <div class="map-hint">Zoom: 0=world &nbsp;5=country &nbsp;10=city &nbsp;13=neighbourhood &nbsp;15=street &nbsp;19=max</div>
        </div>
    </div>

    <!-- ── Backgrounds ── -->
    <div class="section">
        <div class="sec-title">Map Backgrounds</div>
        <div class="sec-body">
            <div id="backgrounds-list"></div>
            <button class="add-btn" onclick="addBackground()">+ Add Background</button>
        </div>
    </div>

    <!-- ── Aid Stations ── -->
    <div class="section">
        <div class="sec-title">Aid Stations</div>
        <div class="sec-body">
            <div id="aidstations-list"></div>
            <button class="add-btn" onclick="addAid()">+ Add Aid Station</button>
        </div>
    </div>

    <!-- ── iGates ── -->
    <div class="section">
        <div class="sec-title">iGates</div>
        <div class="sec-body">
            <div id="igates-list"></div>
            <button class="add-btn" onclick="addIgate()">+ Add iGate</button>
        </div>
    </div>

    <!-- ── Courses ── -->
    <div class="section">
        <div class="sec-title">Courses</div>
        <div class="sec-body">
            <div id="courses-list"></div>
            <button class="add-btn" onclick="addCourse()">+ Add Course</button>
            <button class="add-btn" onclick="doManageLocationFiles()" style="margin-left:6px">Manage Location Files…</button>
        </div>
    </div>

    <div id="footer">
        <button class="sec-btn" onclick="doSaveAs()">Save As…</button>
        <button class="save-btn" onclick="doUpdate()">Update</button>
    </div>
</div>

<div id="page-footer">MARS APRS Map Admin v1.1 beta &copy; 2026 Doug Kaye (K6DRK)</div>

<script>
'use strict';

// ── Dirty tracking ────────────────────────────────────────────────────────────

let isDirty = false;

function markDirty() {
    isDirty = true;
    setStatus('Unsaved changes', 'dirty');
}

function setStatus(msg, type, clearMs) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.className   = type ? 'st-' + type : '';
    if (clearMs) setTimeout(() => { if (!isDirty) { el.textContent = ''; el.className = ''; } }, clearMs);
}

window.addEventListener('beforeunload', e => {
    if (isDirty) { e.preventDefault(); e.returnValue = ''; }
});

// ── Drag-to-reorder ───────────────────────────────────────────────────────────

function initDrag(containerId) {
    const container = document.getElementById(containerId);
    let dragSrc = null;

    function attachRow(row) {
        row.addEventListener('dragstart', function(e) {
            dragSrc = this;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
            requestAnimationFrame(() => this.classList.add('dragging'));
        });
        row.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            container.querySelectorAll('.list-row').forEach(r => r.classList.remove('drag-over'));
            this.classList.add('drag-over');
        });
        row.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });
        row.addEventListener('drop', function(e) {
            e.stopPropagation();
            if (dragSrc && dragSrc !== this) {
                const rows = [...container.querySelectorAll(':scope > .list-row')];
                const si   = rows.indexOf(dragSrc);
                const ti   = rows.indexOf(this);
                if (si >= 0 && ti >= 0) {
                    if (si < ti) this.after(dragSrc); else this.before(dragSrc);
                    markDirty();
                }
            }
            container.querySelectorAll('.list-row').forEach(r => r.classList.remove('drag-over'));
        });
        row.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            container.querySelectorAll('.list-row').forEach(r => r.classList.remove('drag-over'));
            dragSrc = null;
        });
    }

    container.querySelectorAll(':scope > .list-row').forEach(attachRow);
    return attachRow;
}

const dragAdder = {};

// ── DOM helpers ───────────────────────────────────────────────────────────────

function makeDeleteBtn() {
    const btn     = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'del-btn';
    btn.title     = 'Remove';
    btn.textContent = '✕';
    btn.onclick   = function() { this.closest('.list-row').remove(); markDirty(); };
    return btn;
}

function makeDragHandle() {
    const s       = document.createElement('span');
    s.className   = 'drag-handle';
    s.textContent = '⠿';
    s.title       = 'Drag to reorder';
    return s;
}

function fieldLabel(labelText, cls, value, width, extra) {
    const wrap    = document.createElement('label');
    wrap.className = 'field-label';
    const span    = document.createElement('span');
    span.textContent = labelText;
    const inp     = document.createElement('input');
    inp.type      = extra?.type || 'text';
    inp.className = cls;
    inp.value     = value ?? '';
    inp.style.width = width;
    if (extra?.placeholder) inp.placeholder = extra.placeholder;
    if (extra?.step)        inp.step        = extra.step;
    inp.addEventListener('input', markDirty);
    wrap.appendChild(span);
    wrap.appendChild(inp);
    return wrap;
}

// ── Tracker rows ──────────────────────────────────────────────────────────────

function buildTrackerRow(t) {
    const row     = document.createElement('div');
    row.className = 'list-row';
    row.draggable = true;
    row.appendChild(makeDragHandle());
    const fields  = document.createElement('div');
    fields.className = 'row-fields';
    fields.appendChild(fieldLabel('Callsign', 'f-cs',   t.callsign, '110px', { placeholder: 'W6SG-4' }));
    fields.appendChild(fieldLabel('ID',       'f-id',   t.id,        '45px', { placeholder: 'S4'     }));
    fields.appendChild(fieldLabel('Name',     'f-name', t.name,     '130px', { placeholder: 'Alice'  }));
    row.appendChild(fields);
    row.appendChild(makeDeleteBtn());
    return row;
}

function appendTracker(t, attach) {
    const row = buildTrackerRow(t);
    document.getElementById('trackers-list').appendChild(row);
    if (attach) attach(row);
}

function addTracker() { appendTracker({}, dragAdder['trackers-list']); markDirty(); }

// ── Background rows ───────────────────────────────────────────────────────────

function buildBgRow(b) {
    const row     = document.createElement('div');
    row.className = 'list-row bg-row';
    row.draggable = true;
    row.appendChild(makeDragHandle());
    const fields  = document.createElement('div');
    fields.className = 'row-fields bg-fields';

    const l1 = document.createElement('div'); l1.className = 'bg-line';
    l1.appendChild(fieldLabel('Name', 'f-bg-name', b.name, '180px', { placeholder: 'OpenStreetMap' }));

    const l2 = document.createElement('div'); l2.className = 'bg-line';
    l2.appendChild(fieldLabel('URL', 'f-url', b.url, '500px',
        { placeholder: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png' }));

    const l3 = document.createElement('div'); l3.className = 'bg-line';
    l3.appendChild(fieldLabel('Attribution', 'f-attr', b.attribution, '500px',
        { placeholder: '&copy; <a href="...">...</a>' }));

    fields.appendChild(l1);
    fields.appendChild(l2);
    fields.appendChild(l3);
    row.appendChild(fields);
    row.appendChild(makeDeleteBtn());
    return row;
}

function appendBg(b, attach) {
    const row = buildBgRow(b);
    document.getElementById('backgrounds-list').appendChild(row);
    if (attach) attach(row);
}

function addBackground() { appendBg({}, dragAdder['backgrounds-list']); markDirty(); }

// ── Location files ────────────────────────────────────────────────────────────

let locationFiles = [];  // [{path, mtime}, …]

async function refreshLocationFiles() {
    try {
        const r = await fetch('?locationfiles');
        if (r.ok) locationFiles = await r.json();
    } catch {}
}

function lfPaths()              { return locationFiles.map(f => f.path); }
function lfByPath(p)            { return locationFiles.find(f => f.path === p); }

function fmtLocalTime(unixSec) {
    return new Date(unixSec * 1000).toLocaleString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
}

function makeFileSelect(currentValue) {
    const wrap = document.createElement('label');
    wrap.className = 'field-label';
    const span = document.createElement('span');
    span.textContent = 'File';
    const sel = document.createElement('select');
    sel.className = 'f-file f-file-select';

    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = '— select a file —';
    sel.appendChild(emptyOpt);

    const paths = lfPaths();
    if (currentValue && !paths.includes(currentValue)) paths.unshift(currentValue);
    paths.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p.replace(/^configs\//, '');
        if (p === currentValue) opt.selected = true;
        sel.appendChild(opt);
    });

    sel.addEventListener('change', () => { updateCourseFileStatus(sel); markDirty(); });
    wrap.appendChild(span);
    wrap.appendChild(sel);
    return { wrap, sel };
}

function updateCourseFileStatus(sel) {
    const dot = sel.closest('.list-row')?.querySelector('.file-status');
    if (!dot) return;
    const v = sel.value;
    if (!v) {
        dot.className = 'file-status'; dot.textContent = ''; dot.title = '';
    } else {
        const entry = lfByPath(v);
        if (entry) {
            dot.className = 'file-status';
            dot.textContent = fmtLocalTime(entry.mtime);
            dot.title = 'Uploaded ' + fmtLocalTime(entry.mtime);
        } else {
            dot.className = 'file-status missing'; dot.textContent = '✗'; dot.title = 'File not found on server';
        }
    }
}

function refreshAllFileSelects() {
    document.querySelectorAll('#courses-list .f-file-select').forEach(sel => {
        const cur   = sel.value;
        const paths = lfPaths();
        while (sel.options.length > 1) sel.remove(1);
        if (cur && !paths.includes(cur)) paths.unshift(cur);
        paths.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p; opt.textContent = p.replace(/^configs\//, '');
            if (p === cur) opt.selected = true;
            sel.appendChild(opt);
        });
        updateCourseFileStatus(sel);
    });
}

// ── Course rows ───────────────────────────────────────────────────────────────

function buildCourseRow(c) {
    const row     = document.createElement('div');
    row.className = 'list-row';
    row.draggable = true;
    row.appendChild(makeDragHandle());
    const fields  = document.createElement('div');
    fields.className = 'row-fields';
    fields.appendChild(fieldLabel('Name', 'f-cname', c.name, '130px'));

    const { wrap: fileWrap, sel: fileSel } = makeFileSelect(c.file || '');
    fields.appendChild(fileWrap);

    const colorWrap  = document.createElement('label');
    colorWrap.className = 'field-wrap';
    colorWrap.style.cssText = 'display:flex;align-items:center;gap:4px;cursor:pointer';
    const colorLbl   = document.createElement('span');
    colorLbl.className = 'field-label';
    colorLbl.textContent = 'Color';
    const colorInput = document.createElement('input');
    colorInput.type  = 'color';
    colorInput.className = 'f-ccolor';
    colorInput.value = c.color || '#2196f3';
    colorInput.style.cssText = 'width:36px;height:24px;padding:1px;border:1px solid #555;border-radius:3px;cursor:pointer;background:none';
    colorInput.addEventListener('input', () => markDirty());
    colorWrap.appendChild(colorLbl);
    colorWrap.appendChild(colorInput);
    fields.appendChild(colorWrap);

    const dot = document.createElement('span');
    dot.className = 'file-status';
    fields.appendChild(dot);
    row.appendChild(fields);
    row.appendChild(makeDeleteBtn());
    updateCourseFileStatus(fileSel);
    return row;
}

function appendCourse(c, attach) {
    const row = buildCourseRow(c);
    document.getElementById('courses-list').appendChild(row);
    if (attach) attach(row);
}

function addCourse() { appendCourse({}, dragAdder['courses-list']); markDirty(); }

// ── Manage Location Files modal ───────────────────────────────────────────────

async function doManageLocationFiles() {
    await refreshLocationFiles();

    const body = document.createElement('div');

    // Upload zone
    const zone = document.createElement('div');
    zone.className = 'lf-upload-zone';
    zone.innerHTML = 'Click to upload &nbsp;·&nbsp; or drag files here<br><span style="font-size:11px;color:#aaa">.gpx &nbsp; .kml &nbsp; .geojson &nbsp; .json</span>';
    const fileInput = document.createElement('input');
    fileInput.type = 'file'; fileInput.accept = '.gpx,.kml,.geojson,.json'; fileInput.multiple = true;
    fileInput.style.display = 'none';
    body.appendChild(fileInput);
    body.appendChild(zone);

    // Feedback line
    const msg = document.createElement('div');
    msg.className = 'lf-msg';
    body.appendChild(msg);

    // File list
    const listLabel = document.createElement('div');
    listLabel.className = 'lf-section-label';
    listLabel.textContent = 'Files in configs/';
    body.appendChild(listLabel);

    const listWrap = document.createElement('div');
    listWrap.className = 'lf-list';
    body.appendChild(listWrap);

    function renderList() {
        listWrap.innerHTML = '';
        if (!locationFiles.length) {
            const empty = document.createElement('div');
            empty.className = 'modal-empty';
            empty.textContent = 'No location files yet.';
            listWrap.appendChild(empty);
            return;
        }
        locationFiles.forEach(f => buildLfRow(listWrap, f.path, f.mtime, msg, renderList));
    }
    renderList();

    // Wire upload zone
    zone.addEventListener('click', () => fileInput.click());
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) uploadLocationFiles(e.dataTransfer.files, msg, renderList);
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadLocationFiles(fileInput.files, msg, renderList);
        fileInput.value = '';
    });

    const closeBtn = document.createElement('button');
    closeBtn.className = 'modal-cancel-btn';
    closeBtn.textContent = 'Close';
    const close = openModal('Location Files', body, [closeBtn]);
    closeBtn.addEventListener('click', close);
}

function buildLfRow(listWrap, filePath, mtime, msg, renderList) {
    const name = filePath.replace(/^configs\//, '');
    const row  = document.createElement('div');
    row.className = 'lf-row';

    const nameEl = document.createElement('span');
    nameEl.className = 'lf-name';
    nameEl.textContent = name;
    row.appendChild(nameEl);

    if (mtime) {
        const tsEl = document.createElement('span');
        tsEl.className = 'item-date';
        tsEl.textContent = fmtLocalTime(mtime);
        tsEl.title = 'Last saved';
        row.appendChild(tsEl);
    }

    // Download
    const dlBtn = document.createElement('a');
    dlBtn.className = 'lf-icon-btn'; dlBtn.title = 'Download';
    dlBtn.href = filePath; dlBtn.download = name; dlBtn.textContent = '⬇';
    row.appendChild(dlBtn);

    // Rename
    const renBtn = document.createElement('button');
    renBtn.type = 'button'; renBtn.className = 'lf-icon-btn save'; renBtn.title = 'Rename'; renBtn.textContent = '✏';
    row.appendChild(renBtn);

    // Delete
    const delBtn = document.createElement('button');
    delBtn.type = 'button'; delBtn.className = 'lf-icon-btn del'; delBtn.title = 'Delete'; delBtn.textContent = '✕';
    row.appendChild(delBtn);

    renBtn.addEventListener('click', () => {
        // Swap to edit mode in-place
        nameEl.style.display = dlBtn.style.display = renBtn.style.display = delBtn.style.display = 'none';

        const inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'lf-input'; inp.value = name;
        const saveBtn2   = document.createElement('button');
        saveBtn2.type    = 'button'; saveBtn2.className = 'lf-small-btn ok'; saveBtn2.textContent = 'Save';
        const cancelBtn2 = document.createElement('button');
        cancelBtn2.type  = 'button'; cancelBtn2.className = 'lf-small-btn cancel'; cancelBtn2.textContent = 'Cancel';

        row.insertBefore(inp, dlBtn);
        row.insertBefore(saveBtn2, dlBtn);
        row.insertBefore(cancelBtn2, dlBtn);
        inp.focus(); inp.select();

        const cancelRename = () => {
            inp.remove(); saveBtn2.remove(); cancelBtn2.remove();
            nameEl.style.display = dlBtn.style.display = renBtn.style.display = delBtn.style.display = '';
            msg.textContent = '';
        };

        const doRename = async () => {
            const newName = inp.value.trim();
            if (!newName || newName === name) { cancelRename(); return; }
            saveBtn2.disabled = true;
            try {
                const r = await fetch('?renamefile', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ oldName: name, newName })
                });
                const res = await r.json();
                if (r.ok && res.ok) {
                    const idx = locationFiles.findIndex(f => f.path === filePath);
                    if (idx >= 0) locationFiles[idx] = { path: res.newFile, mtime: locationFiles[idx].mtime };
                    else await refreshLocationFiles();
                    refreshAllFileSelects();
                    renderList();
                    setMsg(msg, `Renamed to "${newName}"`, true);
                } else {
                    setMsg(msg, res.error || 'Rename failed', false);
                    saveBtn2.disabled = false;
                }
            } catch { setMsg(msg, 'Network error', false); saveBtn2.disabled = false; }
        };

        saveBtn2.addEventListener('click', doRename);
        cancelBtn2.addEventListener('click', cancelRename);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  doRename();
            if (e.key === 'Escape') cancelRename();
        });
    });

    delBtn.addEventListener('click', async () => {
        if (!confirm(`Delete "${name}"?\nThis cannot be undone.`)) return;
        delBtn.disabled = true;
        try {
            const r = await fetch('?deletefile', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name })
            });
            const res = await r.json();
            if (r.ok && res.ok) {
                locationFiles = locationFiles.filter(f => f.path !== filePath);
                refreshAllFileSelects();
                renderList();
                setMsg(msg, `Deleted "${name}"`, true);
            } else {
                setMsg(msg, res.error || 'Delete failed', false);
                delBtn.disabled = false;
            }
        } catch { setMsg(msg, 'Network error', false); delBtn.disabled = false; }
    });

    listWrap.appendChild(row);
}

async function uploadLocationFiles(files, msg, afterUpload) {
    setMsg(msg, 'Uploading…', null);
    const errors = [];
    for (const file of Array.from(files)) {
        const fd = new FormData();
        fd.append('file', file);
        try {
            const r   = await fetch('?upload', { method: 'POST', body: fd });
            const res = await r.json();
            if (!r.ok || !res.ok) errors.push(res.error || `Failed: ${file.name}`);
        } catch { errors.push(`Network error: ${file.name}`); }
    }
    await refreshLocationFiles();
    refreshAllFileSelects();
    if (afterUpload) afterUpload();
    if (!errors.length) {
        const n = files.length;
        setMsg(msg, `Uploaded ${n} file${n > 1 ? 's' : ''}`, true);
    } else {
        setMsg(msg, errors[0], false);
    }
}

function setMsg(el, text, ok) {
    el.textContent = text;
    el.style.color = ok === true ? '#27ae60' : ok === false ? '#e74c3c' : '#888';
}

// ── iGate rows ────────────────────────────────────────────────────────────────

function buildIgateRow(g) {
    const row     = document.createElement('div');
    row.className = 'list-row';
    row.draggable = true;
    row.appendChild(makeDragHandle());
    const fields  = document.createElement('div');
    fields.className = 'row-fields';
    fields.appendChild(fieldLabel('Name', 'f-iname', g.name, '160px', { placeholder: 'K6ABC-10' }));
    fields.appendChild(fieldLabel('Lat',  'f-ilat',  g.lat,  '120px', { type: 'number', step: 'any', placeholder: '37.8725'   }));
    fields.appendChild(fieldLabel('Lon',  'f-ilon',  g.lon,  '130px', { type: 'number', step: 'any', placeholder: '-122.5441' }));
    row.appendChild(fields);
    row.appendChild(makeDeleteBtn());
    return row;
}

function appendIgate(g, attach) {
    const row = buildIgateRow(g);
    document.getElementById('igates-list').appendChild(row);
    if (attach) attach(row);
}

function addIgate() { appendIgate({}, dragAdder['igates-list']); markDirty(); }

// ── Aid Station rows ──────────────────────────────────────────────────────────

function buildAidRow(g) {
    const row     = document.createElement('div');
    row.className = 'list-row';
    row.draggable = true;
    row.appendChild(makeDragHandle());
    const fields  = document.createElement('div');
    fields.className = 'row-fields';
    fields.appendChild(fieldLabel('Name', 'f-aname', g.name, '160px', { placeholder: 'Aid Station 1' }));
    fields.appendChild(fieldLabel('Lat',  'f-alat',  g.lat,  '120px', { type: 'number', step: 'any', placeholder: '37.8725'   }));
    fields.appendChild(fieldLabel('Lon',  'f-alon',  g.lon,  '130px', { type: 'number', step: 'any', placeholder: '-122.5441' }));
    row.appendChild(fields);
    row.appendChild(makeDeleteBtn());
    return row;
}

function appendAid(g, attach) {
    const row = buildAidRow(g);
    document.getElementById('aidstations-list').appendChild(row);
    if (attach) attach(row);
}

function addAid() { appendAid({}, dragAdder['aidstations-list']); markDirty(); }

// ── Collect form → config object ──────────────────────────────────────────────

function collectConfig() {
    const trackers = [];
    document.querySelectorAll('#trackers-list > .list-row').forEach(row => {
        trackers.push({
            callsign: row.querySelector('.f-cs').value.trim(),
            id:       row.querySelector('.f-id').value.trim(),
            name:     row.querySelector('.f-name').value.trim()
        });
    });

    const map = {
        lat:  parseFloat(document.getElementById('map-lat').value),
        lon:  parseFloat(document.getElementById('map-lon').value),
        zoom: parseInt(document.getElementById('map-zoom').value, 10)
    };

    const backgrounds = [];
    document.querySelectorAll('#backgrounds-list > .list-row').forEach(row => {
        backgrounds.push({
            name:        row.querySelector('.f-bg-name').value.trim(),
            url:         row.querySelector('.f-url').value.trim(),
            attribution: row.querySelector('.f-attr').value.trim()
        });
    });

    const courses = [];
    document.querySelectorAll('#courses-list > .list-row').forEach(row => {
        courses.push({
            name:  row.querySelector('.f-cname').value.trim(),
            file:  row.querySelector('.f-file').value.trim(),
            color: row.querySelector('.f-ccolor').value
        });
    });

    const aidstations = [];
    document.querySelectorAll('#aidstations-list > .list-row').forEach(row => {
        aidstations.push({
            name: row.querySelector('.f-aname').value.trim(),
            lat:  parseFloat(row.querySelector('.f-alat').value),
            lon:  parseFloat(row.querySelector('.f-alon').value)
        });
    });

    const igates = [];
    document.querySelectorAll('#igates-list > .list-row').forEach(row => {
        igates.push({
            name: row.querySelector('.f-iname').value.trim(),
            lat:  parseFloat(row.querySelector('.f-ilat').value),
            lon:  parseFloat(row.querySelector('.f-ilon').value)
        });
    });

    return { event: document.getElementById('f-event').value.trim(), trackers, map, backgrounds, courses, aidstations, igates };
}

// ── Populate form from a config object ───────────────────────────────────────

function populateForm(cfg) {
    document.getElementById('f-event').value = cfg.event || '';

    document.getElementById('trackers-list').innerHTML = '';
    (cfg.trackers || []).forEach(t => appendTracker(t));
    dragAdder['trackers-list'] = initDrag('trackers-list');

    document.getElementById('map-lat').value  = cfg.map?.lat  ?? '';
    document.getElementById('map-lon').value  = cfg.map?.lon  ?? '';
    document.getElementById('map-zoom').value = cfg.map?.zoom ?? '';

    document.getElementById('backgrounds-list').innerHTML = '';
    (cfg.backgrounds || []).forEach(b => appendBg(b));
    dragAdder['backgrounds-list'] = initDrag('backgrounds-list');

    document.getElementById('aidstations-list').innerHTML = '';
    (cfg.aidstations || []).forEach(g => appendAid(g));
    dragAdder['aidstations-list'] = initDrag('aidstations-list');

    document.getElementById('igates-list').innerHTML = '';
    (cfg.igates || []).forEach(g => appendIgate(g));
    dragAdder['igates-list'] = initDrag('igates-list');

    document.getElementById('courses-list').innerHTML = '';
    (cfg.courses || []).forEach(c => appendCourse(c));
    dragAdder['courses-list'] = initDrag('courses-list');
}

// ── Use Current Map ───────────────────────────────────────────────────────────

function useCurrentMap() {
    const raw = localStorage.getItem('aprs_map_view');
    if (!raw) {
        alert('No map position found.\nOpen the main map page and pan/zoom to the desired view, then try again.');
        return;
    }
    const v = JSON.parse(raw);
    document.getElementById('map-lat').value  = v.lat;
    document.getElementById('map-lon').value  = v.lon;
    document.getElementById('map-zoom').value = v.zoom;
    markDirty();
}

// ── Load live config.yaml from server ────────────────────────────────────────

async function doLoad() {
    try {
        const [filesResp, cfgResp] = await Promise.all([fetch('?locationfiles'), fetch('?load')]);
        if (cfgResp.status === 401) { location.reload(); return; }
        if (filesResp.ok) locationFiles = await filesResp.json();
        const cfg = await cfgResp.json();
        populateForm(cfg);
        isDirty = false;
        setStatus('');
        document.getElementById('current-file').textContent = 'config.yaml';
    } catch (err) {
        setStatus('Failed to load config', 'error');
        console.error(err);
    }
}

// ── Update live config.yaml (was "Save") ─────────────────────────────────────

function validateConfig(cfg) {
    const errors = [];
    const { lat, lon, zoom } = cfg.map;
    if (isNaN(lat)  || lat  < -90  || lat  > 90)  errors.push('Map: latitude must be −90 to 90');
    if (isNaN(lon)  || lon  < -180 || lon  > 180) errors.push('Map: longitude must be −180 to 180');
    if (isNaN(zoom) || zoom < 0    || zoom > 19)  errors.push('Map: zoom must be 0 to 19');
    (cfg.aidstations || []).forEach((g, i) => {
        if (isNaN(g.lat) || g.lat < -90  || g.lat > 90)   errors.push(`Aid Station ${i+1}: latitude must be −90 to 90`);
        if (isNaN(g.lon) || g.lon < -180 || g.lon > 180)  errors.push(`Aid Station ${i+1}: longitude must be −180 to 180`);
    });
    (cfg.igates || []).forEach((g, i) => {
        if (isNaN(g.lat) || g.lat < -90  || g.lat > 90)   errors.push(`iGate ${i+1}: latitude must be −90 to 90`);
        if (isNaN(g.lon) || g.lon < -180 || g.lon > 180)  errors.push(`iGate ${i+1}: longitude must be −180 to 180`);
    });
    return errors;
}

async function doUpdate() {
    hideErrors();
    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    setStatus('Saving…', '');

    const cfg = collectConfig();
    const clientErrors = validateConfig(cfg);
    if (clientErrors.length) {
        showErrors(clientErrors);
        setStatus('Validation errors', 'error');
        btn.disabled = false;
        return;
    }

    try {
        const r = await fetch('?save', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(cfg)
        });
        if (r.status === 401) {
            showErrors(['Session expired — reload and log in again']);
            setStatus('Session expired', 'error');
            return;
        }
        const result = await r.json();
        if (r.ok && result.ok) {
            isDirty = false;
            setStatus('Updated ✓', 'ok', 4000);
        } else if (result.errors) {
            showErrors(result.errors);
            setStatus('Validation errors', 'error');
        } else {
            showErrors([result.error || 'Save failed']);
            setStatus('Save failed', 'error');
        }
    } catch (err) {
        showErrors(['Network error — check connection']);
        setStatus('Error', 'error');
        console.error(err);
    } finally {
        btn.disabled = false;
    }
}

// ── Version management ────────────────────────────────────────────────────────

async function fetchVersions() {
    const r = await fetch('?versions');
    if (!r.ok) return [];
    return r.json();
}

function fmtDate(mtime) {
    return new Date(mtime * 1000).toLocaleString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
}

function openModal(title, bodyEl, footerEls) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML =
        `<div class="modal-box">` +
            `<div class="modal-title"></div>` +
            `<div class="modal-body"></div>` +
            `<div class="modal-footer"></div>` +
        `</div>`;
    backdrop.querySelector('.modal-title').textContent = title;
    backdrop.querySelector('.modal-body').appendChild(bodyEl);
    footerEls.forEach(b => backdrop.querySelector('.modal-footer').appendChild(b));
    backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
    document.body.appendChild(backdrop);
    const close = () => backdrop.remove();
    return close;
}

async function doSaveAs() {
    let versions = [];
    try { versions = await fetchVersions(); } catch {}

    const body = document.createElement('div');

    // Name input
    const field = document.createElement('div');
    field.className = 'modal-field';
    const lbl = document.createElement('label');
    lbl.textContent = 'Version name';
    lbl.htmlFor = 'modal-vname';
    const inp = document.createElement('input');
    inp.type = 'text'; inp.id = 'modal-vname';
    inp.placeholder = 'e.g. Race Day 2026';
    inp.style.width = '100%';
    inp.value = document.getElementById('f-event').value.trim();
    field.appendChild(lbl); field.appendChild(inp);
    body.appendChild(field);

    const warn = document.createElement('div');
    warn.className = 'modal-warn';
    warn.style.display = 'none';
    body.appendChild(warn);

    // Existing versions list
    if (versions.length) {
        const listLbl = document.createElement('div');
        listLbl.className = 'modal-list-label';
        listLbl.textContent = 'Existing versions:';
        body.appendChild(listLbl);

        const list = document.createElement('div');
        list.className = 'modal-list';
        versions.forEach(v => {
            const row = document.createElement('div');
            row.className = 'modal-list-item';
            row.innerHTML = `<span class="item-name">${v.name}</span><span class="item-date">${fmtDate(v.mtime)}</span>`;
            row.addEventListener('click', () => { inp.value = v.name; inp.dispatchEvent(new Event('input')); });
            list.appendChild(row);
        });
        body.appendChild(list);
    }

    const saveBtn = document.createElement('button');
    saveBtn.className = 'save-btn';
    saveBtn.textContent = 'Save';
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'modal-cancel-btn';
    cancelBtn.textContent = 'Cancel';

    const close = openModal('Save As…', body, [cancelBtn, saveBtn]);
    cancelBtn.addEventListener('click', close);

    const existingNames = new Set(versions.map(v => v.name));
    inp.addEventListener('input', () => {
        const name = inp.value.trim();
        if (existingNames.has(name)) {
            warn.textContent = `"${name}" already exists — saving will overwrite it.`;
            warn.style.display = '';
            saveBtn.textContent = 'Overwrite';
        } else {
            warn.style.display = 'none';
            saveBtn.textContent = 'Save';
        }
    });

    saveBtn.addEventListener('click', async () => {
        const name = inp.value.trim();
        if (!name) { inp.focus(); return; }
        saveBtn.disabled = true;
        try {
            const cfg = collectConfig();
            const errors = validateConfig(cfg);
            if (errors.length) { warn.textContent = errors[0]; warn.style.display = ''; saveBtn.disabled = false; return; }
            const [rLive, rVer] = await Promise.all([
                fetch('?save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(cfg)
                }),
                fetch('?saveversion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, cfg })
                })
            ]);
            const result = await rVer.json();
            if (rVer.ok && result.ok && rLive.ok) {
                isDirty = false;
                close();
                setStatus(`Saved "${name}" ✓`, 'ok', 4000);
                document.getElementById('current-file').textContent = name + '.yaml';
            } else {
                const liveResult = rLive.ok ? null : await rLive.json();
                warn.textContent = result.error || liveResult?.error || 'Save failed';
                warn.style.display = '';
            }
        } catch {
            warn.textContent = 'Network error';
            warn.style.display = '';
        } finally { saveBtn.disabled = false; }
    });

    requestAnimationFrame(() => { inp.focus(); inp.select(); inp.dispatchEvent(new Event('input')); });
}

async function doLoadModal() {
    let versions = [];
    try { versions = await fetchVersions(); } catch {}

    const body = document.createElement('div');
    const list = document.createElement('div');
    list.className = 'modal-list';

    // Live config.yaml at top
    const liveRow = document.createElement('div');
    liveRow.className = 'modal-list-live';
    liveRow.textContent = 'config.yaml  (live / current)';
    liveRow.addEventListener('click', async () => {
        if (isDirty && !confirm('Discard unsaved changes and reload live config.yaml?')) return;
        close();
        await doLoad();
        setStatus('Loaded live config ✓', 'ok', 3000);
        document.getElementById('current-file').textContent = 'config.yaml';
    });
    list.appendChild(liveRow);

    if (!versions.length) {
        const empty = document.createElement('div');
        empty.className = 'modal-empty';
        empty.textContent = 'No saved versions yet.';
        list.appendChild(empty);
    }

    versions.forEach(v => {
        const row = document.createElement('div');
        row.className = 'modal-list-item';
        const nameSpan = document.createElement('span'); nameSpan.className = 'item-name'; nameSpan.textContent = v.name;
        const dateSpan = document.createElement('span'); dateSpan.className = 'item-date'; dateSpan.textContent = fmtDate(v.mtime);
        const delBtn = document.createElement('button'); delBtn.className = 'item-del'; delBtn.textContent = '✕'; delBtn.title = 'Delete this version';

        delBtn.addEventListener('click', async e => {
            e.stopPropagation();
            if (!confirm(`Delete version "${v.name}"?`)) return;
            try {
                const r = await fetch('?deleteversion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: v.name })
                });
                if ((await r.json()).ok) row.remove();
            } catch {}
        });

        row.appendChild(nameSpan); row.appendChild(dateSpan); row.appendChild(delBtn);
        row.addEventListener('click', async () => {
            if (isDirty && !confirm(`Discard unsaved changes and load "${v.name}"?`)) return;
            close();
            try {
                const [filesResp, r] = await Promise.all([
                    fetch('?locationfiles'),
                    fetch('?loadversion&name=' + encodeURIComponent(v.name))
                ]);
                if (r.status === 401) { location.reload(); return; }
                if (!r.ok) { setStatus('Load failed', 'error'); return; }
                if (filesResp.ok) locationFiles = await filesResp.json();
                const cfg = await r.json();
                populateForm(cfg);
                isDirty = false;
                setStatus(`Loaded "${v.name}" ✓`, 'ok', 4000);
                document.getElementById('current-file').textContent = v.name + '.yaml';
            } catch (err) {
                setStatus('Failed to load version', 'error');
                console.error(err);
            }
        });
        list.appendChild(row);
    });

    body.appendChild(list);

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'modal-cancel-btn';
    cancelBtn.textContent = 'Cancel';

    const close = openModal('Load Version', body, [cancelBtn]);
    cancelBtn.addEventListener('click', close);
}

function showErrors(errs) {
    const box = document.getElementById('error-box');
    box.style.display = 'block';
    box.innerHTML = errs.map(e => `<div>⚠ ${e}</div>`).join('');
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideErrors() {
    const box = document.getElementById('error-box');
    box.style.display = 'none';
    box.innerHTML = '';
}

// ── Init ──────────────────────────────────────────────────────────────────────

doLoad();
</script>
</body>
</html>
