<?php
/**
 * admin.php — MARS APRS Tracker Map tickets system admin panel
 *
 * Password-protected interface for event staff to manage support tickets
 * submitted by participants. Allows viewing, updating status, and deleting
 * tickets stored in tickets.json on the server.
 */
ini_set('display_errors', '0');
ini_set('session.gc_maxlifetime', 43200);
session_start();

$ticketsFile  = __DIR__ . '/tickets.json';
$versionsFile = __DIR__ . '/versions.json';
$configFile   = __DIR__ . '/config.json';
$passwordFile = __DIR__ . '/../admin/password.txt';
$storedPass   = file_exists($passwordFile) ? trim(file_get_contents($passwordFile)) : '';

// ── Helpers ───────────────────────────────────────────────────────────────────

function loadConfig(string $path): array {
    if (!file_exists($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh || !flock($fh, LOCK_SH)) return [];
    $data = stream_get_contents($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return json_decode($data, true) ?: [];
}

function saveConfig(string $path, array $cfg): void {
    $fh = fopen($path, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($cfg, JSON_PRETTY_PRINT) . "\n");
    flock($fh, LOCK_UN); fclose($fh);
}

function loadTickets(string $path): array {
    if (!file_exists($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh || !flock($fh, LOCK_SH)) return [];
    $data = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return json_decode($data, true) ?: [];
}

function loadVersions(string $path): array {
    if (!file_exists($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh || !flock($fh, LOCK_SH)) return [];
    $data = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return json_decode($data, true) ?: [];
}

function saveVersions(string $path, array $versions): bool {
    $fh = fopen($path, 'c+');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array_values($versions), JSON_PRETTY_PRINT) . "\n");
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function saveTickets(string $path, array $tickets): bool {
    $fh = fopen($path, 'c+');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($tickets, JSON_PRETTY_PRINT) . "\n");
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function statusBadge(string $status): string {
    $colors = [
        'Open'       => '#c0392b',
        'Testing'    => '#1a6fa8',
        'Resolved'   => '#1a7a3c',
        "Won't Fix"  => '#666',
        'Duplicate'  => '#666',
    ];
    $bg = $colors[$status] ?? '#555';
    $s  = esc($status);
    return "<span class=\"badge\" style=\"background:{$bg}\">{$s}</span>";
}

const VALID_STATUSES = ['Open', 'Testing', 'Resolved', "Won't Fix", 'Duplicate'];

// ── File helpers ──────────────────────────────────────────────────────────────

function isImageFile(string $fname): bool {
    return in_array(strtolower(pathinfo($fname, PATHINFO_EXTENSION)),
        ['jpg','jpeg','png','gif','webp'], true);
}

function ticketFileUrl(string $ticketId, string $fname): string {
    return 'uploads/' . rawurlencode($ticketId) . '/' . rawurlencode($fname);
}

function ticketUrl(string $id, string $token): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'marsaprs.org';
    return "{$proto}://{$host}/tickets/?id={$id}&token={$token}";
}

function sendCommentNotification(array $ticket, string $commentText): void {
    $email = $ticket['email'] ?? '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $token = $ticket['token'] ?? '';
    if ($token === '') return;
    $host    = $_SERVER['HTTP_HOST'] ?? 'marsaprs.org';
    $id      = $ticket['id'];
    $from    = 'noreply@' . $host;
    $subject = "Update on ticket {$id} — MARS APRS";
    $body    = implode("\n", [
        "There is an update on your ticket {$id}:",
        "  " . ($ticket['summary'] ?? ''),
        "",
        $commentText,
        "",
        "View your ticket and add comments:",
        "  " . ticketUrl($id, $token),
    ]);
    $headers = implode("\r\n", [
        "From: MARS APRS Support <{$from}>",
        "Reply-To: {$from}",
        "Content-Type: text/plain; charset=UTF-8",
        "X-Mailer: PHP/" . PHP_VERSION,
    ]);
    @mail($email, $subject, $body, $headers);
}

function deleteTicketUploads(string $ticketId): void {
    $dir = __DIR__ . '/uploads/' . $ticketId;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

function totalUploadsSize(): int {
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) return 0;
    $total = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $total += $f->getSize();
    return $total;
}

function formatSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// ── Auth ──────────────────────────────────────────────────────────────────────

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$loginError = '';
if (!isset($_SESSION['aprs_admin_authed']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    if ($storedPass !== '' && $_POST['pw'] === $storedPass) {
        $_SESSION['aprs_admin_authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Incorrect password';
}

$authed = !empty($_SESSION['aprs_admin_authed']) || !empty($_SESSION['stats_auth']);

if (!$authed) {
    renderLogin($loginError);
    exit;
}

// ── POST: update ticket ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['update'])) {
    $id       = strtoupper(trim($_POST['id'] ?? ''));
    $status   = trim($_POST['status'] ?? '');
    $response = substr(trim($_POST['admin_response'] ?? ''), 0, 8000);

    if (!preg_match('/^TKT-\d{4}$/', $id) || !in_array($status, VALID_STATUSES, true)) {
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid request');
    }

    $resolvedVersion = ($status === 'Resolved')
        ? substr(trim($_POST['resolved_version'] ?? ''), 0, 60)
        : '';

    $tickets = loadTickets($ticketsFile);
    $found = false;
    foreach ($tickets as &$t) {
        if ($t['id'] === $id) {
            $t['status']           = $status;
            $t['admin_response']   = $response;
            $t['resolved_version'] = $resolvedVersion;
            $t['updated']          = time();
            $found = true;
            break;
        }
    }
    unset($t);

    if ($found) saveTickets($ticketsFile, $tickets);
    header('Location: admin?id=' . urlencode($id) . '&saved=1');
    exit;
}

// ── POST: delete ticket ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['delete'])) {
    $id = strtoupper(trim($_POST['id'] ?? ''));
    if (preg_match('/^TKT-\d{4}$/', $id)) {
        $tickets = loadTickets($ticketsFile);
        $tickets = array_values(array_filter($tickets, fn($t) => $t['id'] !== $id));
        saveTickets($ticketsFile, $tickets);
        deleteTicketUploads($id);
    }
    header('Location: admin');
    exit;
}

// ── POST: admin adds comment ──────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['addcomment'])) {
    $id     = strtoupper(trim($_POST['id']   ?? ''));
    $text   = substr(trim($_POST['text'] ?? ''), 0, 2000);
    $notify = !empty($_POST['notify']);
    if (preg_match('/^TKT-\d{4}$/', $id) && $text !== '') {
        $tickets = loadTickets($ticketsFile);
        foreach ($tickets as &$t) {
            if ($t['id'] === $id) {
                if (!isset($t['comments'])) $t['comments'] = [];
                $t['comments'][] = ['ts' => time(), 'author' => 'admin', 'name' => 'Admin', 'text' => $text];
                $t['updated'] = time();
                saveTickets($ticketsFile, $tickets);
                if ($notify) sendCommentNotification($t, $text);
                break;
            }
        }
        unset($t);
    }
    header('Location: admin?id=' . urlencode($id) . '&saved=1');
    exit;
}

// ── POST: add version ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['addversion'])) {
    $ver = substr(trim($_POST['new_version'] ?? ''), 0, 60);
    if ($ver !== '') {
        $versions = loadVersions($versionsFile);
        if (!in_array($ver, $versions, true)) {
            array_unshift($versions, $ver);
            saveVersions($versionsFile, $versions);
        }
    }
    header('Location: admin#versions');
    exit;
}

// ── POST: delete version ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['deleteversion'])) {
    $ver = trim($_POST['version'] ?? '');
    if ($ver !== '') {
        $versions = loadVersions($versionsFile);
        $versions = array_values(array_filter($versions, fn($v) => $v !== $ver));
        saveVersions($versionsFile, $versions);
    }
    header('Location: admin#versions');
    exit;
}

// ── POST: save settings ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['savesettings'])) {
    $email = trim($_POST['manager_email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';  // silently discard invalid address
    }
    $cfg = loadConfig($configFile);
    $cfg['manager_email'] = $email;
    saveConfig($configFile, $cfg);
    header('Location: admin#settings');
    exit;
}

// ── Detail view (?id=TKT-xxxx) ───────────────────────────────────────────────

$detailTicket = null;
if (isset($_GET['id'])) {
    $reqId = strtoupper(trim($_GET['id']));
    if (preg_match('/^TKT-\d{4}$/', $reqId)) {
        $tickets = loadTickets($ticketsFile);
        foreach ($tickets as $t) {
            if ($t['id'] === $reqId) { $detailTicket = $t; break; }
        }
    }
}

// ── List view ─────────────────────────────────────────────────────────────────

function renderLogin(string $error): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tickets Admin — MARS APRS</title>
<style>
* { margin:0;padding:0;box-sizing:border-box; }
body { font-family:arial,helvetica,sans-serif;font-size:14px;background:#eef0f3;min-height:100vh;display:flex;flex-direction:column; }
#hdr { background:#2c3e50;color:#fff;padding:10px 20px; }
#hdr h1 { font-size:16px;font-weight:bold; }
#content { flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px; }
.card { background:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:32px 36px;width:100%;max-width:300px; }
.card h2 { font-size:15px;color:#333;margin-bottom:20px; }
.field { display:flex;flex-direction:column;gap:4px;margin-bottom:16px; }
.field label { font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.04em; }
.field input { padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:inherit; }
.field input:focus { outline:none;border-color:#2980b9; }
.btn-row { display:flex;gap:10px;margin-top:4px; }
.submit-btn { flex:1;padding:9px;background:#2980b9;color:#fff;border:none;border-radius:4px;font-size:14px;font-weight:bold;cursor:pointer; }
.submit-btn:hover { background:#1f6da0; }
.cancel-btn { flex:1;padding:9px;background:#f0f0f0;color:#555;border:1px solid #ccc;border-radius:4px;font-size:14px;font-weight:bold;cursor:pointer;text-decoration:none;text-align:center; }
.cancel-btn:hover { background:#e0e0e0; }
.error { background:#fff0f0;border:1px solid #f5c6c6;border-radius:4px;padding:8px 12px;color:#c0392b;font-size:13px;margin-bottom:14px; }
</style>
</head>
<body>
<div id="hdr"><h1>MARS APRS — Ticket Admin</h1></div>
<div id="content">
    <div class="card">
        <h2>Sign in</h2>
        <?php if ($error): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
        <form method="POST">
            <div class="field">
                <label for="pw">Password</label>
                <input type="password" id="pw" name="pw" autocomplete="current-password" autofocus>
            </div>
            <div class="btn-row">
                <button type="submit" class="submit-btn">Sign In</button>
                <a href="../" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
<?php }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ticket Admin — MARS APRS</title>
<style>
* { margin:0;padding:0;box-sizing:border-box; }
body { font-family:arial,helvetica,sans-serif;font-size:14px;background:#eef0f3;color:#222; }
#hdr {
    background:#2c3e50;color:#fff;
    padding:10px 24px;
    display:flex;align-items:center;justify-content:space-between;
}
#hdr h1 { font-size:16px;font-weight:bold; }
#hdr-right { display:flex;gap:10px;align-items:center; }
.hdr-btn {
    padding:6px 14px;background:#fff;color:#2c3e50;
    border:none;border-radius:4px;font-size:13px;font-weight:bold;
    cursor:pointer;text-decoration:none;
}
.hdr-btn:hover { background:#d0dce8; }
#wrap { max-width:900px;margin:28px auto;padding:0 16px 60px; }
.card { background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.12);padding:24px 24px 28px;margin-bottom:20px; }
.card h2 { font-size:15px;color:#333;margin-bottom:18px; }
table { width:100%;border-collapse:collapse; }
th {
    text-align:left;padding:7px 10px;
    font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.05em;color:#666;
    background:#f5f6f8;border-bottom:2px solid #e0e0e0;
}
td { padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top;font-size:13px; }
tr:last-child td { border-bottom:none; }
tbody tr { cursor:pointer; }
tbody tr:hover { background:#f0f4f8; }
.badge {
    display:inline-block;padding:2px 9px;border-radius:10px;
    color:#fff;font-size:12px;font-weight:bold;white-space:nowrap;
}
.tkt-table { width:100%;border-collapse:collapse;margin-bottom:20px; }
.tkt-table td { padding:8px 0;border-bottom:1px solid #f0f0f0;vertical-align:top; }
.tkt-table tr:last-child td { border-bottom:none; }
.tkt-table td:first-child {
    width:140px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
    color:#888;padding-right:14px;padding-top:10px;
}
.tkt-desc { white-space:pre-wrap;font-size:13px;line-height:1.6; }
.field { display:flex;flex-direction:column;gap:5px;margin-bottom:14px; }
.field label { font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:.05em;color:#666; }
.field select, .field textarea {
    padding:8px 10px;border:1px solid #ccc;border-radius:4px;
    font-size:14px;font-family:inherit;
}
.field select:focus, .field textarea:focus { outline:none;border-color:#2980b9; }
.save-btn {
    padding:9px 24px;background:#2980b9;color:#fff;
    border:none;border-radius:4px;font-size:14px;font-weight:bold;cursor:pointer;
}
.save-btn:hover { background:#1f6da0; }
.back-link { font-size:13px;margin-bottom:18px;display:inline-block; }
.back-link a { color:#2980b9;text-decoration:none; }
.back-link a:hover { text-decoration:underline; }
.empty { padding:18px;text-align:center;color:#999;font-size:13px; }
.del-ticket-btn {
    padding:9px 18px;background:#fff;color:#c0392b;
    border:1px solid #e0b0b0;border-radius:4px;font-size:14px;font-weight:bold;cursor:pointer;
}
.del-ticket-btn:hover { background:#fdf0f0;border-color:#c0392b; }
.thread { margin:18px 0 0; }
.thread-msg { display:flex;gap:10px;margin-bottom:12px; }
.thread-msg.mine { flex-direction:row-reverse; }
.thread-bubble { max-width:78%;padding:8px 12px;border-radius:10px;font-size:13px;line-height:1.5;white-space:pre-wrap; }
.thread-msg.theirs .thread-bubble { background:#eef2f7; }
.thread-msg.mine   .thread-bubble { background:#dceeff; }
.thread-meta { font-size:11px;color:#999;margin-top:3px; }
.thread-msg.mine .thread-meta { text-align:right; }
.comment-form { margin-top:18px;padding-top:14px;border-top:1px solid #eee; }
.comment-form h3 { font-size:13px;font-weight:bold;color:#555;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px; }
.comment-form textarea { width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:inherit;resize:vertical; }
.comment-form textarea:focus { outline:none;border-color:#2980b9; }
.comment-form-row { display:flex;align-items:center;justify-content:space-between;margin-top:8px;flex-wrap:wrap;gap:8px; }
.copy-btn {
    background:none;border:none;cursor:pointer;color:#bbb;font-size:13px;
    padding:1px 5px;border-radius:3px;vertical-align:middle;margin-left:6px;
    transition:color .15s,background .15s;
}
.copy-btn:hover { color:#2980b9;background:#ddeeff; }
.copy-btn.copied { color:#27ae60; }
.file-grid { display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;margin-top:4px; }
.file-thumb { max-width:150px;max-height:120px;border-radius:4px;border:1px solid #ddd;display:block;object-fit:cover; }
.file-thumb:hover { border-color:#2980b9; }
.file-doc { display:inline-flex;align-items:center;gap:5px;font-size:13px;color:#2980b9;text-decoration:none;padding:6px 10px;border:1px solid #ddd;border-radius:4px;background:#fafafa; }
.file-doc:hover { background:#edf4fb;border-color:#2980b9; }
.file-count { font-size:12px;color:#888;white-space:nowrap; }
th.sortable { cursor:pointer;user-select:none; }
th.sortable:hover { background:#eaebee; }
th.sort-asc::after { content:' ▲';font-size:10px;opacity:.7; }
th.sort-desc::after { content:' ▼';font-size:10px;opacity:.7; }
@media (max-width:600px) {
    #hdr { flex-direction:column;align-items:flex-start;gap:8px; }
    .card { padding:16px 14px 20px; }
}
</style>
</head>
<body>
<div id="hdr">
    <h1>MARS APRS — Ticket Admin</h1>
    <div id="hdr-right">
        <a href="." class="hdr-btn">New Ticket</a>
        <a href="?logout" class="hdr-btn">Sign Out</a>
        <a href="../admin/" class="hdr-btn">Map Admin</a>
    </div>
</div>
<div id="wrap">

<?php if ($detailTicket): ?>
<!-- ── Detail view ── -->
<?php if (isset($_GET['saved'])): ?>
<div id="save-toast" style="background:#1a7a3c;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;font-weight:bold;margin-bottom:14px;display:inline-block">&#10003; Saved</div>
<script>
setTimeout(function() {
    var t = document.getElementById('save-toast');
    if (t) t.style.transition = 'opacity .4s', t.style.opacity = '0', setTimeout(function(){ t.remove(); }, 400);
}, 1800);
history.replaceState({}, '', 'admin?id=<?= esc($detailTicket['id']) ?>');
</script>
<?php endif; ?>
<div class="back-link"><a href="admin">&larr; All Tickets</a></div>
<div class="card">
    <h2>Ticket <?= esc($detailTicket['id']) ?></h2>
    <table class="tkt-table">
        <tr><td>Status</td><td><?= statusBadge($detailTicket['status']) ?><?php if (!empty($detailTicket['resolved_version'])): ?> <span style="font-size:13px;color:#555;margin-left:8px">in v<?= esc($detailTicket['resolved_version']) ?></span><?php endif; ?></td></tr>
        <tr><td>Submitted</td><td><?= esc(date('M j, Y g:i A', $detailTicket['created'])) ?></td></tr>
        <?php if ($detailTicket['updated'] !== $detailTicket['created']): ?>
        <tr><td>Updated</td><td><?= esc(date('M j, Y g:i A', $detailTicket['updated'])) ?></td></tr>
        <?php endif; ?>
        <tr><td>Name</td><td><?= esc($detailTicket['name']) ?></td></tr>
        <tr><td>Email</td><td><?php if ($detailTicket['email'] !== ''): ?><span id="cp-email"><?= esc($detailTicket['email']) ?></span><button class="copy-btn" onclick="copyField('cp-email',this)" title="Copy">&#x2398;</button><?php else: ?><span style="color:#bbb">—</span><?php endif; ?></td></tr>
        <?php
            $_proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $_checkUrl = $_proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/?check=' . urlencode($detailTicket['id']);
        ?>
        <tr><td>Status URL</td><td><span id="cp-status-url" style="font-size:13px;color:#555"><?= esc($_checkUrl) ?></span><button class="copy-btn" onclick="copyField('cp-status-url',this)" title="Copy">&#x2398;</button></td></tr>
        <tr><td>Platform</td><td><?= esc($detailTicket['platform']) ?></td></tr>
        <tr><td>Version</td><td><?= $detailTicket['version'] !== '' ? esc($detailTicket['version']) : '<span style="color:#bbb">—</span>' ?></td></tr>
        <tr><td>Type</td><td><?= esc($detailTicket['type']) ?></td></tr>
        <tr><td>Summary</td><td><span id="cp-summary"><?= esc($detailTicket['summary']) ?></span><button class="copy-btn" onclick="copyField('cp-summary',this)" title="Copy">&#x2398;</button></td></tr>
        <tr><td>Description</td><td><div class="tkt-desc"><span id="cp-desc"><?= esc($detailTicket['description']) ?></span></div><button class="copy-btn" onclick="copyField('cp-desc',this)" title="Copy" style="margin-left:0;margin-top:4px">&#x2398;</button></td></tr>
        <?php if (!empty($detailTicket['files'])): ?>
        <tr>
            <td>Files</td>
            <td>
                <div class="file-grid">
                <?php foreach ($detailTicket['files'] as $fname):
                    $url = esc(ticketFileUrl($detailTicket['id'], $fname)); ?>
                <?php if (isImageFile($fname)): ?>
                    <a href="<?= $url ?>" target="_blank">
                        <img src="<?= $url ?>" class="file-thumb" alt="<?= esc($fname) ?>">
                    </a>
                <?php else: ?>
                    <a href="<?= $url ?>" target="_blank" class="file-doc">📄 <?= esc($fname) ?></a>
                <?php endif; ?>
                <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ($detailTicket['admin_response'] !== ''): ?>
        <tr><td>Response</td><td><div class="tkt-desc"><?= esc($detailTicket['admin_response']) ?></div></td></tr>
        <?php endif; ?>
    </table>

    <?php
    $comments      = $detailTicket['comments'] ?? [];
    $adminResponse = trim($detailTicket['admin_response'] ?? '');
    $hasContent    = !empty($comments) || $adminResponse !== '';
    $hasEmail      = ($detailTicket['email'] ?? '') !== '';
    $hasToken      = ($detailTicket['token'] ?? '') !== '';
    if ($hasContent): ?>
    <div class="thread">
    <?php if ($adminResponse !== '' && empty(array_filter($comments, fn($c) => $c['author'] === 'admin'))): ?>
        <div class="thread-msg mine">
            <div>
                <div class="thread-bubble"><?= esc($adminResponse) ?></div>
                <div class="thread-meta">Admin response</div>
            </div>
        </div>
    <?php endif; ?>
    <?php foreach ($comments as $c):
        $isAdmin = $c['author'] === 'admin';
        $label   = $isAdmin ? 'Admin' : esc($c['name'] ?? 'Submitter');
        $ts      = date('M j g:i A', (int)($c['ts'] ?? 0));
    ?>
        <div class="thread-msg <?= $isAdmin ? 'mine' : 'theirs' ?>">
            <div>
                <div class="thread-bubble"><?= esc($c['text']) ?></div>
                <div class="thread-meta"><?= $label ?> · <?= esc($ts) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="comment-form">
        <h3>Add Comment</h3>
        <form method="POST" action="admin?addcomment">
            <input type="hidden" name="id" value="<?= esc($detailTicket['id']) ?>">
            <textarea name="text" rows="3" maxlength="2000" placeholder="Add a comment visible to the submitter…"></textarea>
            <div class="comment-form-row">
                <?php if ($hasEmail && $hasToken): ?>
                <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="checkbox" name="notify" value="1" checked>
                    Email submitter (<?= esc($detailTicket['email']) ?>)
                </label>
                <?php else: ?>
                <span style="font-size:12px;color:#aaa"><?= $hasEmail ? 'No comment link — ticket predates this feature' : 'No email on file' ?></span>
                <?php endif; ?>
                <button type="submit" class="save-btn" style="padding:7px 18px">Add Comment</button>
            </div>
        </form>
    </div>

    <form method="POST" action="admin?update">
        <input type="hidden" name="id" value="<?= esc($detailTicket['id']) ?>">
        <?php $versions = loadVersions($versionsFile); $curResolved = $detailTicket['resolved_version'] ?? ''; ?>
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status" onchange="toggleResolvedVer(this.value)">
                <?php foreach (VALID_STATUSES as $st): ?>
                    <option value="<?= esc($st) ?>"<?= ($detailTicket['status'] === $st) ? ' selected' : '' ?>><?= esc($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" id="f-resolved-row"<?= $detailTicket['status'] !== 'Resolved' ? ' style="display:none"' : '' ?>>
            <label for="f-resolved-ver">Resolved in Release</label>
            <select id="f-resolved-ver" name="resolved_version">
                <option value="">— select version —</option>
                <?php foreach ($versions as $v): ?>
                <option value="<?= esc($v) ?>"<?= ($curResolved === $v) ? ' selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
                <?php if ($curResolved !== '' && !in_array($curResolved, $versions, true)): ?>
                <option value="<?= esc($curResolved) ?>" selected><?= esc($curResolved) ?></option>
                <?php endif; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-response">Admin Response</label>
            <textarea id="f-response" name="admin_response" rows="5" maxlength="8000"
                placeholder="Optional response visible to the submitter when they check their ticket status"><?= esc($detailTicket['admin_response']) ?></textarea>
        </div>
        <button type="submit" class="save-btn">Save</button>
    </form>
    <form method="POST" action="admin?delete" onsubmit="return confirmDelete(this)" style="margin-top:12px;text-align:right">
        <input type="hidden" name="id" value="<?= esc($detailTicket['id']) ?>">
        <button type="submit" class="del-ticket-btn">Delete Ticket</button>
    </form>
</div>

<?php else: ?>
<!-- ── List view ── -->
<?php
$tickets = loadTickets($ticketsFile);
$tickets = array_reverse($tickets); // newest first
$uploadSize = totalUploadsSize();
?>
<div class="card">
    <h2 style="display:flex;justify-content:space-between;align-items:baseline">
        <span style="display:flex;align-items:center;gap:16px">
            <span id="tickets-title">All Tickets (<?= count($tickets) ?>)</span>
            <label style="font-size:13px;font-weight:normal;color:#555;cursor:pointer;display:flex;align-items:center;gap:5px">
                <input type="checkbox" id="active-filter"> Only Active Tickets
            </label>
        </span>
        <?php if ($uploadSize > 0): ?>
        <span style="font-size:12px;color:#999;font-weight:normal">📎 <?= formatSize($uploadSize) ?> uploads</span>
        <?php endif; ?>
    </h2>
    <?php if (empty($tickets)): ?>
        <div class="empty">No tickets yet.</div>
    <?php else: ?>
    <table id="tickets-table">
        <thead>
            <tr>
                <th class="sortable">ID</th>
                <th class="sortable">Date</th>
                <th class="sortable">Platform</th>
                <th class="sortable">Version</th>
                <th class="sortable">Type</th>
                <th class="sortable">Summary</th>
                <th class="sortable">Status</th>
                <th class="sortable">Files</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
            <?php $fc = count($t['files'] ?? []); ?>
            <tr onclick="location.href='admin.php?id=<?= urlencode($t['id']) ?>'">
                <td style="font-family:monospace;white-space:nowrap"><?= esc($t['id']) ?></td>
                <td style="white-space:nowrap;color:#666" data-sort="<?= $t['created'] ?>"><?= esc(date('M j, Y', $t['created'])) ?></td>
                <td><?= esc($t['platform']) ?></td>
                <td style="color:#666"><?= esc($t['version'] ?: '') ?></td>
                <td><?= esc($t['type']) ?></td>
                <td><?= esc($t['summary']) ?></td>
                <td data-sort="<?= esc($t['status']) ?>"><?= statusBadge($t['status']) ?></td>
                <td class="file-count" data-sort="<?= $fc ?>"><?php if ($fc) echo "📎&nbsp;$fc"; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$detailTicket): ?>
<!-- ── Version list management ── -->
<?php $versions = loadVersions($versionsFile); ?>
<div class="card" id="versions">
    <h2>App Version List</h2>
    <p style="font-size:13px;color:#666;margin-bottom:16px">These versions appear in the dropdown on the submission form, newest first.</p>
    <?php if (empty($versions)): ?>
        <p style="font-size:13px;color:#999;margin-bottom:16px">No versions defined yet.</p>
    <?php else: ?>
    <table style="margin-bottom:18px">
        <thead>
            <tr><th style="width:200px">Version</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
            <tr>
                <td style="font-family:monospace;font-size:14px"><?= esc($v) ?></td>
                <td style="text-align:right;width:60px">
                    <form method="POST" action="admin.php?deleteversion"
                        onsubmit="return confirm('Remove version <?= esc($v) ?> from the list?')">
                        <input type="hidden" name="version" value="<?= esc($v) ?>">
                        <button type="submit" style="background:none;border:none;color:#bbb;font-size:15px;cursor:pointer;padding:2px 6px;border-radius:3px"
                            title="Remove" onmouseover="this.style.color='#c0392b';this.style.background='#fdf0f0'"
                            onmouseout="this.style.color='#bbb';this.style.background='none'">&times;</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <form method="POST" action="admin.php?addversion" style="display:flex;gap:8px;align-items:center">
        <input type="text" name="new_version" placeholder="e.g. 1.17.0" maxlength="60"
            style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:inherit;width:180px">
        <button type="submit" class="save-btn" style="padding:7px 18px">Add Version</button>
    </form>
</div>
<?php endif; ?>

<?php if (!$detailTicket):
$cfg = loadConfig($configFile);
$managerEmail = $cfg['manager_email'] ?? '';
?>
<div class="card" id="settings">
    <h2>Settings</h2>
    <form method="POST" action="admin.php?savesettings">
        <div class="field">
            <label for="f-manager-email">Manager Email</label>
            <input type="email" id="f-manager-email" name="manager_email"
                value="<?= esc($managerEmail) ?>"
                placeholder="e.g. manager@example.com"
                style="width:100%;max-width:360px">
            <p style="font-size:12px;color:#888;margin-top:5px">New ticket notifications are emailed to this address. Leave blank to disable.</p>
        </div>
        <button type="submit" class="save-btn" style="margin-top:4px">Save Settings</button>
    </form>
</div>
<?php endif; ?>

</div>
<script>
(function() {
    var tbl = document.getElementById('tickets-table');
    if (!tbl) return;
    var tbody = tbl.querySelector('tbody');
    var ths   = Array.from(tbl.querySelectorAll('thead th.sortable'));
    var sortCol = 1, sortDir = -1; // default: Date descending (newest first)

    ths.forEach(function(th, i) {
        th.addEventListener('click', function() {
            sortDir = (sortCol === i) ? -sortDir : 1;
            sortCol = i;
            sort();
        });
    });

    function sort() {
        var rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort(function(a, b) {
            var av = (a.children[sortCol].dataset.sort !== undefined
                      ? a.children[sortCol].dataset.sort
                      : a.children[sortCol].textContent).trim().toLowerCase();
            var bv = (b.children[sortCol].dataset.sort !== undefined
                      ? b.children[sortCol].dataset.sort
                      : b.children[sortCol].textContent).trim().toLowerCase();
            var an = parseFloat(av), bn = parseFloat(bv);
            var cmp = (!isNaN(an) && !isNaN(bn)) ? (an - bn) : av.localeCompare(bv);
            return cmp * sortDir;
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
        ths.forEach(function(th, i) {
            th.classList.toggle('sort-asc',  i === sortCol && sortDir === 1);
            th.classList.toggle('sort-desc', i === sortCol && sortDir === -1);
        });
    }

    sort(); // apply default sort on load

    // "Only Active Tickets" filter
    var filterCb = document.getElementById('active-filter');
    var titleEl  = document.getElementById('tickets-title');
    if (filterCb) {
        var ACTIVE = ['open', 'testing'];
        var allRows = Array.from(tbody.querySelectorAll('tr'));
        var total   = allRows.length;

        function countByStatus(statuses) {
            return allRows.filter(function(r) {
                return statuses.indexOf((r.children[6].dataset.sort || '').toLowerCase()) !== -1;
            }).length;
        }

        function buildCounts() {
            var nOpen    = countByStatus(['open']);
            var nTesting = countByStatus(['testing']);
            var parts = [total + ' total', nOpen + ' open'];
            if (nTesting) parts.push(nTesting + ' testing');
            return parts.join(', ');
        }

        function applyFilter() {
            var active = filterCb.checked;
            allRows.forEach(function(r) {
                var status = (r.children[6].dataset.sort || '').toLowerCase();
                r.style.display = (!active || ACTIVE.indexOf(status) !== -1) ? '' : 'none';
            });
            if (titleEl) {
                var label = active ? 'Active Tickets' : 'All Tickets';
                titleEl.textContent = label + ' (' + buildCounts() + ')';
            }
        }
        try { if (localStorage.getItem('tickets_active_filter') === '1') filterCb.checked = true; } catch {}
        filterCb.addEventListener('change', function() {
            try { localStorage.setItem('tickets_active_filter', filterCb.checked ? '1' : '0'); } catch {}
            applyFilter();
        });
        applyFilter(); // set counts on load
    }
})();

function toggleResolvedVer(status) {
    var row = document.getElementById('f-resolved-row');
    if (row) row.style.display = status === 'Resolved' ? '' : 'none';
}
function confirmDelete(form) {
    var id = form.querySelector('input[name=id]').value;
    return confirm('Permanently delete ' + id + '? This cannot be undone.');
}
function copyField(id, btn) {
    var text = document.getElementById(id).innerText;
    navigator.clipboard.writeText(text).then(function() {
        btn.classList.add('copied');
        btn.innerHTML = '&#x2713;';
        setTimeout(function() { btn.classList.remove('copied'); btn.innerHTML = '&#x2398;'; }, 1500);
    });
}
</script>
</body>
</html>
