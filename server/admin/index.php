<?php
ini_set('display_errors', '0');
ini_set('session.gc_maxlifetime', 43200);
session_start();

// ── Helpers ───────────────────────────────────────────────────────────────────

function respondJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

// ── Paths ─────────────────────────────────────────────────────────────────────

$configPath   = __DIR__ . '/../config.json';
$coursesDir   = __DIR__ . '/../courses';
$passwordFile = '/var/www/html/admin/password.txt';   // shared with marsaprs

// ── Auth ──────────────────────────────────────────────────────────────────────

$storedPass = file_exists($passwordFile) ? trim(file_get_contents($passwordFile)) : '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$loginError = '';
if (!isset($_SESSION['static_map_authed'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pw'])) {
    if ($storedPass !== '' && $_POST['pw'] === $storedPass) {
        $_SESSION['static_map_authed'] = true;
    } else {
        $loginError = 'Incorrect password';
    }
}

$authed = !empty($_SESSION['static_map_authed']);

if (!$authed) {
    if (isset($_GET['load']) || isset($_GET['save']) || isset($_GET['upload']) || isset($_GET['deletecourse'])) {
        respondJson(['error' => 'Not authenticated'], 401);
    }
    renderLogin($loginError);
    exit;
}

// ── API endpoints (authenticated) ─────────────────────────────────────────────

// GET ?load — return current config
if (isset($_GET['load'])) {
    if (!file_exists($configPath)) {
        respondJson(['attribution' => '', 'copyright' => '', 'help' => '', 'courses' => []]);
    }
    header('Content-Type: application/json');
    echo file_get_contents($configPath);
    exit;
}

// POST ?save — write config.json
if (isset($_GET['save']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) respondJson(['error' => 'Invalid JSON'], 400);

    // Sanitise: only keep known keys
    $clean = [
        'attribution' => trim($data['attribution'] ?? ''),
        'copyright'   => trim($data['copyright'] ?? ''),
        'help'        => $data['help'] ?? '',
        'courses'     => [],
    ];
    foreach (($data['courses'] ?? []) as $c) {
        if (!isset($c['file'])) continue;
        $clean['courses'][] = [
            'name'    => trim($c['name'] ?? ''),
            'file'    => preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $c['file'] ?? ''),
            'color'   => preg_match('/^#[0-9a-fA-F]{6}$/', $c['color'] ?? '') ? $c['color'] : '#2196f3',
            'visible' => (bool)($c['visible'] ?? true),
        ];
    }

    if (file_put_contents($configPath, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        respondJson(['error' => 'Failed to write config'], 500);
    }
    respondJson(['ok' => true]);
}

// POST ?upload — upload a course file
if (isset($_GET['upload']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) respondJson(['error' => 'No file'], 400);
    $f    = $_FILES['file'];
    $name = basename($f['name']);
    if (!preg_match('/\.(gpx|geojson|json)$/i', $name)) {
        respondJson(['error' => 'Only .gpx, .geojson, .json files allowed'], 400);
    }
    if (!is_dir($coursesDir)) mkdir($coursesDir, 0755, true);
    $dest = $coursesDir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        respondJson(['error' => 'Upload failed'], 500);
    }
    respondJson(['ok' => true, 'file' => 'courses/' . $name]);
}

// POST ?deletecourse — delete a course file
if (isset($_GET['deletecourse']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? '');
    if (!$file) respondJson(['error' => 'No file specified'], 400);
    $path = $coursesDir . '/' . $file;
    if (file_exists($path)) unlink($path);
    respondJson(['ok' => true]);
}

// GET ?listcourses — list uploaded course files
if (isset($_GET['listcourses'])) {
    $files = [];
    if (is_dir($coursesDir)) {
        foreach (glob($coursesDir . '/*.{gpx,geojson,json}', GLOB_BRACE) as $f) {
            $files[] = basename($f);
        }
    }
    respondJson($files);
}

renderAdmin();

// ── Render functions ──────────────────────────────────────────────────────────

function renderLogin($error = '') { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Static Map Admin</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:arial,helvetica,sans-serif; font-size:14px; background:#eef0f3; min-height:100vh; display:flex; flex-direction:column; }
#hdr { background:#2c3e50; color:#fff; padding:10px 20px; }
#hdr h1 { font-size:16px; font-weight:bold; }
#content { flex:1; display:flex; align-items:center; justify-content:center; padding:40px 20px; }
.card { background:#fff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.12); padding:32px 36px; width:100%; max-width:300px; }
.card h2 { font-size:15px; color:#333; margin-bottom:20px; }
.field { display:flex; flex-direction:column; gap:4px; margin-bottom:16px; }
.field label { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.04em; }
.field input { padding:8px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; font-family:inherit; }
.field input:focus { outline:none; border-color:#2980b9; }
.submit-btn { width:100%; padding:9px; background:#2980b9; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:bold; cursor:pointer; }
.submit-btn:hover { background:#1f6da0; }
.error { background:#fff0f0; border:1px solid #f5c6c6; border-radius:4px; padding:8px 12px; color:#c0392b; font-size:13px; margin-bottom:14px; }
</style>
</head>
<body>
<div id="hdr"><h1>Static Map Admin</h1></div>
<div id="content">
  <div class="card">
    <h2>Sign in</h2>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="field">
        <label for="pw">Password</label>
        <input type="password" id="pw" name="pw" autocomplete="current-password" autofocus>
      </div>
      <button type="submit" class="submit-btn">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
<?php exit; }

function renderAdmin() { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Static Map Admin</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:arial,helvetica,sans-serif; font-size:14px; background:#eef0f3; min-height:100vh; }
#hdr { background:#2c3e50; color:#fff; padding:10px 20px; display:flex; align-items:center; justify-content:space-between; }
#hdr h1 { font-size:16px; font-weight:bold; }
#hdr a { color:#aec6cf; font-size:13px; text-decoration:none; }
#hdr a:hover { color:#fff; }
#main { max-width:800px; margin:24px auto; padding:0 16px 40px; }
.card { background:#fff; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,.1); padding:24px; margin-bottom:20px; }
.card h2 { font-size:15px; font-weight:bold; color:#2c3e50; margin-bottom:16px; padding-bottom:10px; border-bottom:1px solid #eee; }
.field { margin-bottom:14px; }
.field label { display:block; font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.field input[type=text], .field textarea {
    width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:4px;
    font-size:14px; font-family:inherit;
}
.field textarea { min-height:120px; resize:vertical; }
.field input:focus, .field textarea:focus { outline:none; border-color:#2980b9; }
.save-btn { padding:10px 28px; background:#27ae60; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:bold; cursor:pointer; }
.save-btn:hover { background:#1e8449; }
.save-btn:disabled { background:#aaa; cursor:default; }
.upload-btn { padding:7px 16px; background:#2980b9; color:#fff; border:none; border-radius:4px; font-size:13px; cursor:pointer; }
.upload-btn:hover { background:#1f6da0; }
table { width:100%; border-collapse:collapse; margin-top:12px; }
th { text-align:left; font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.04em; padding:6px 8px; border-bottom:1px solid #eee; }
td { padding:8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
.color-swatch { width:28px; height:28px; border-radius:4px; border:1px solid #ccc; cursor:pointer; }
.del-btn { background:none; border:none; color:#c0392b; cursor:pointer; font-size:18px; padding:2px 6px; }
.del-btn:hover { color:#96281b; }
.drag-handle { cursor:grab; color:#bbb; font-size:18px; user-select:none; }
.msg { display:none; padding:8px 14px; border-radius:4px; font-size:13px; margin-top:12px; }
.msg.ok { background:#eafaf1; border:1px solid #a9dfbf; color:#1e8449; }
.msg.err { background:#fdedec; border:1px solid #f5b7b1; color:#c0392b; }
.no-courses { color:#999; font-size:13px; padding:12px 0; }
</style>
</head>
<body>
<div id="hdr">
  <h1>Static Map Admin</h1>
  <a href="?logout">Logout</a>
</div>
<div id="main">

  <div class="card">
    <h2>App Settings</h2>
    <div class="field">
      <label>Map Attribution</label>
      <input type="text" id="attribution" placeholder="© OpenStreetMap contributors">
    </div>
    <div class="field">
      <label>Copyright Notice</label>
      <input type="text" id="copyright" placeholder="© 2026 Your Name">
    </div>
    <div class="field">
      <label>Help Text (HTML)</label>
      <textarea id="help" placeholder="<b>Help</b><br>Tap the location button to center on your position."></textarea>
    </div>
  </div>

  <div class="card">
    <h2>Courses</h2>
    <div id="course-list"><div class="no-courses">No courses yet.</div></div>
    <div style="margin-top:14px;">
      <input type="file" id="file-input" accept=".gpx,.geojson,.json" style="display:none">
      <button class="upload-btn" onclick="document.getElementById('file-input').click()">Upload Course File (.gpx / .geojson)</button>
      <span id="upload-status" style="margin-left:10px;font-size:13px;color:#888;"></span>
    </div>
  </div>

  <button class="save-btn" id="save-btn" onclick="saveConfig()">Save</button>
  <div class="msg" id="msg"></div>

</div>

<script>
let courses = [];
let dragSrc = null;

async function loadConfig() {
  try {
    const r = await fetch('?load');
    const d = await r.json();
    document.getElementById('attribution').value = d.attribution || '';
    document.getElementById('copyright').value   = d.copyright   || '';
    document.getElementById('help').value        = d.help        || '';
    courses = d.courses || [];
    renderCourses();
  } catch(e) { showMsg('Failed to load config: ' + e, false); }
}

function renderCourses() {
  const el = document.getElementById('course-list');
  if (!courses.length) { el.innerHTML = '<div class="no-courses">No courses yet.</div>'; return; }
  let html = '<table><thead><tr><th></th><th>Name</th><th>Color</th><th>Visible</th><th>File</th><th></th></tr></thead><tbody>';
  courses.forEach((c, i) => {
    html += `<tr draggable="true" data-i="${i}"
      ondragstart="dragStart(event,${i})" ondragover="dragOver(event)" ondrop="dragDrop(event,${i})">
      <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
      <td><input type="text" value="${esc(c.name)}" style="width:120px;padding:4px 6px;border:1px solid #ccc;border-radius:3px;"
          onchange="courses[${i}].name=this.value"></td>
      <td><input type="color" value="${esc(c.color)}" class="color-swatch"
          oninput="courses[${i}].color=this.value"></td>
      <td style="text-align:center"><input type="checkbox" ${c.visible ? 'checked' : ''}
          onchange="courses[${i}].visible=this.checked"></td>
      <td style="color:#888;font-size:12px">${esc(c.file)}</td>
      <td><button class="del-btn" onclick="deleteCourse(${i})" title="Delete">×</button></td>
    </tr>`;
  });
  el.innerHTML = html + '</tbody></table>';
}

function dragStart(e, i) { dragSrc = i; e.dataTransfer.effectAllowed = 'move'; }
function dragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
function dragDrop(e, i) {
  e.preventDefault();
  if (dragSrc === null || dragSrc === i) return;
  const moved = courses.splice(dragSrc, 1)[0];
  courses.splice(i, 0, moved);
  dragSrc = null;
  renderCourses();
}

async function deleteCourse(i) {
  const c = courses[i];
  if (!confirm('Delete course "' + c.name + '" and its file?')) return;
  const filename = c.file.replace('courses/', '');
  courses.splice(i, 1);
  renderCourses();
  try {
    await fetch('?deletecourse', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({file: filename}) });
  } catch(e) {}
}

async function saveConfig() {
  const btn = document.getElementById('save-btn');
  btn.disabled = true;
  btn.textContent = 'Saving…';
  try {
    const payload = {
      attribution: document.getElementById('attribution').value,
      copyright:   document.getElementById('copyright').value,
      help:        document.getElementById('help').value,
      courses:     courses,
    };
    const r = await fetch('?save', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
    const d = await r.json();
    if (d.ok) showMsg('Saved.', true); else showMsg('Error: ' + (d.error || '?'), false);
  } catch(e) { showMsg('Save failed: ' + e, false); }
  btn.disabled = false;
  btn.textContent = 'Save';
}

document.getElementById('file-input').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const status = document.getElementById('upload-status');
  status.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('file', file);
  try {
    const r = await fetch('?upload', { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) {
      const name = file.name.replace(/\.[^.]+$/, '');
      courses.push({ name: name, file: d.file, color: '#2196f3', visible: true });
      renderCourses();
      status.textContent = 'Uploaded: ' + file.name;
    } else {
      status.textContent = 'Error: ' + (d.error || '?');
    }
  } catch(e) { status.textContent = 'Upload failed: ' + e; }
  this.value = '';
});

function showMsg(text, ok) {
  const el = document.getElementById('msg');
  el.className = 'msg ' + (ok ? 'ok' : 'err');
  el.textContent = text;
  el.style.display = 'block';
  setTimeout(() => el.style.display = 'none', 4000);
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadConfig();
</script>
</body>
</html>
<?php }
