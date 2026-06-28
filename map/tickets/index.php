<?php
ini_set('display_errors', '0');

$ticketsFile  = __DIR__ . '/tickets.json';
$versionsFile = __DIR__ . '/versions.json';
$configFile   = __DIR__ . '/config.json';

// ── File upload config ────────────────────────────────────────────────────────

define('UPLOAD_DIR',     __DIR__ . '/uploads');
define('MAX_FILES',      5);
define('MAX_FILE_BYTES', 10 * 1024 * 1024);

const ALLOWED_MIME = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
const ALLOWED_EXT  = ['jpg','jpeg','png','gif','webp','pdf'];

// ── Helpers ───────────────────────────────────────────────────────────────────

function loadConfig(string $path): array {
    if (!file_exists($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh || !flock($fh, LOCK_SH)) return [];
    $data = stream_get_contents($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return json_decode($data, true) ?: [];
}

function sendTicketNotification(array $ticket, string $to): void {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $host    = $_SERVER['HTTP_HOST'] ?? 'marsaprs.org';
    $id      = $ticket['id'];
    $from    = 'noreply@' . $host;
    $subject = "New ticket {$id}: " . substr($ticket['summary'], 0, 80);
    $lines   = [
        "A new ticket has been submitted.",
        "",
        "ID:          {$id}",
        "Date:        " . date('Y-m-d H:i T', $ticket['created']),
        "Platform:    " . ($ticket['platform']    ?? ''),
        "Version:     " . ($ticket['version']     ?? ''),
        "Type:        " . ($ticket['type']        ?? ''),
        "Summary:     " . ($ticket['summary']     ?? ''),
        "Submitter:   " . ($ticket['name']        ?? 'Anonymous'),
    ];
    if (($ticket['email'] ?? '') !== '') {
        $lines[] = "Contact:     " . $ticket['email'];
    }
    $lines[] = "";
    $lines[] = "Description:";
    $lines[] = $ticket['description'] ?? '';
    $lines[] = "";
    $lines[] = "View ticket: https://{$host}/tickets/admin?id={$id}";

    $headers = implode("\r\n", [
        "From: MARS APRS Tickets <{$from}>",
        "Reply-To: {$from}",
        "Content-Type: text/plain; charset=UTF-8",
        "X-Mailer: PHP/" . PHP_VERSION,
    ]);
    @mail($to, $subject, implode("\n", $lines), $headers);
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

function loadTickets(string $path): array {
    if (!file_exists($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh || !flock($fh, LOCK_SH)) return [];
    $data = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return json_decode($data, true) ?: [];
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

function nextTicketId(array $tickets): string {
    $max = 0;
    foreach ($tickets as $t) {
        if (preg_match('/^TKT-(\d+)$/', $t['id'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'TKT-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function isImageFile(string $fname): bool {
    return in_array(strtolower(pathinfo($fname, PATHINFO_EXTENSION)),
        ['jpg','jpeg','png','gif','webp'], true);
}

function ticketFileUrl(string $ticketId, string $fname): string {
    return 'uploads/' . rawurlencode($ticketId) . '/' . rawurlencode($fname);
}

function ensureUploadsHtaccess(): void {
    $dir = UPLOAD_DIR;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht,
            "Options -Indexes\n" .
            "<FilesMatch \"\\.php[0-9]?$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

function processUploads(string $ticketId): array {
    if (empty($_FILES['files']['name'][0])) return [];

    ensureUploadsHtaccess();

    $names  = (array)$_FILES['files']['name'];
    $tmps   = (array)$_FILES['files']['tmp_name'];
    $sizes  = (array)$_FILES['files']['size'];
    $errors = (array)$_FILES['files']['error'];
    $count  = min(count($names), MAX_FILES);

    $dir = UPLOAD_DIR . '/' . $ticketId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $saved = [];
    $fi    = finfo_open(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $count; $i++) {
        if ($errors[$i] !== UPLOAD_ERR_OK) continue;
        if ($sizes[$i] > MAX_FILE_BYTES || !is_uploaded_file($tmps[$i])) continue;

        $mime = finfo_file($fi, $tmps[$i]);
        if (!in_array($mime, ALLOWED_MIME, true)) continue;

        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) continue;

        $base  = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_',
                     pathinfo($names[$i], PATHINFO_FILENAME)), 0, 60) ?: 'file';
        $fname = $base . '.' . $ext;
        $dest  = $dir . '/' . $fname;
        $n = 2;
        while (file_exists($dest)) {
            $fname = $base . '_' . $n++ . '.' . $ext;
            $dest  = $dir . '/' . $fname;
        }
        if (move_uploaded_file($tmps[$i], $dest)) $saved[] = $fname;
    }

    finfo_close($fi);
    return $saved;
}

// ── Status check (?check=TKT-xxxx) ───────────────────────────────────────────

$checkId     = '';
$checkTicket = null;
if (isset($_GET['check'])) {
    $checkId = strtoupper(trim($_GET['check']));
    if (preg_match('/^TKT-\d{4}$/', $checkId)) {
        $tickets = loadTickets($ticketsFile);
        foreach ($tickets as $t) {
            if ($t['id'] === $checkId) { $checkTicket = $t; break; }
        }
    }
}

// ── Form submission ───────────────────────────────────────────────────────────

$submitted = null;
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['check'])) {
    $name     = substr(trim($_POST['name']     ?? ''), 0, 80);
    $email    = substr(trim($_POST['email']    ?? ''), 0, 200);
    $platform = trim($_POST['platform'] ?? '');
    $version  = trim($_POST['version'] ?? '') === '__other__'
              ? substr(trim($_POST['version_other'] ?? ''), 0, 60)
              : substr(trim($_POST['version'] ?? ''), 0, 60);
    $type    = trim($_POST['type'] ?? '');
    $summary = substr(trim($_POST['summary']     ?? ''), 0, 120);
    $desc    = substr(trim($_POST['description'] ?? ''), 0, 4000);

    $validPlatforms = ['iOS', 'Android', 'iOS and Android', 'Web'];
    $validTypes     = ['Bug', 'Feature Request', 'Question'];

    if ($name === '') {
        $formError = 'Name is required.';
    } elseif ($summary === '') {
        $formError = 'Summary is required.';
    } elseif ($desc === '') {
        $formError = 'Description is required.';
    } elseif (!in_array($platform, $validPlatforms, true)) {
        $formError = 'Please select a platform.';
    } elseif (!in_array($type, $validTypes, true)) {
        $formError = 'Please select a ticket type.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formError = 'Please enter a valid email address.';
    } else {
        $tickets = loadTickets($ticketsFile);
        $now     = time();
        $id      = nextTicketId($tickets);
        $files   = processUploads($id);
        $ticket  = [
            'id'             => $id,
            'created'        => $now,
            'updated'        => $now,
            'name'           => $name,
            'email'          => $email,
            'platform'       => $platform,
            'version'        => $version,
            'type'           => $type,
            'summary'        => $summary,
            'description'    => $desc,
            'status'           => 'Open',
            'admin_response'   => '',
            'resolved_version' => '',
            'files'            => $files,
        ];
        $tickets[] = $ticket;
        if (saveTickets($ticketsFile, $tickets)) {
            $submitted = $ticket;
            $cfg = loadConfig($configFile);
            if (!empty($cfg['manager_email'])) {
                sendTicketNotification($ticket, $cfg['manager_email']);
            }
        } else {
            $formError = 'Could not save your ticket — please try again.';
        }
    }
}

// ── Status badge helper ───────────────────────────────────────────────────────

function statusBadge(string $status): string {
    $colors = [
        'Open'        => '#c0392b',
        'In Progress' => '#b7770d',
        'Resolved'    => '#1a7a3c',
        'Won\'t Fix'  => '#666',
        'Duplicate'   => '#666',
    ];
    $bg = $colors[$status] ?? '#555';
    $s  = esc($status);
    return "<span style=\"display:inline-block;padding:3px 10px;border-radius:12px;background:{$bg};color:#fff;font-size:13px;font-weight:bold\">{$s}</span>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MARS APRS Tracker — Bug Reports &amp; Feedback</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: arial, helvetica, sans-serif; font-size: 14px; background: #eef0f3; color: #222; }
#hdr {
    background: #2c3e50; color: #fff;
    padding: 12px 24px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
#hdr-text h1 { font-size: 17px; font-weight: bold; }
#hdr-text p { font-size: 12px; color: #8aafc8; margin-top: 2px; }
.hdr-btn {
    padding: 6px 14px; background: #fff; color: #2c3e50;
    border: none; border-radius: 4px; font-size: 13px; font-weight: bold;
    cursor: pointer; text-decoration: none; white-space: nowrap; flex-shrink: 0;
}
.hdr-btn:hover { background: #d0dce8; }
#wrap { max-width: 640px; margin: 32px auto; padding: 0 16px 60px; }
.card {
    background: #fff; border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,.12);
    padding: 28px 28px 32px;
}
.card h2 { font-size: 15px; color: #333; margin-bottom: 22px; }
.field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
.field label { font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase; letter-spacing: .05em; }
.field input, .field select, .field textarea {
    padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
    font-size: 14px; font-family: inherit; color: #222;
}
.field input[type=file] { padding: 6px 8px; }
.field input:focus, .field select:focus, .field textarea:focus {
    outline: none; border-color: #2980b9;
}
.field textarea { resize: vertical; }
.field .hint { font-size: 11px; color: #999; }
.submit-btn {
    width: 100%; padding: 10px; background: #2980b9; color: #fff;
    border: none; border-radius: 4px; font-size: 15px; font-weight: bold;
    cursor: pointer; margin-top: 8px;
}
.submit-btn:hover { background: #1f6da0; }
.error {
    background: #fff0f0; border: 1px solid #f5c6c6; border-radius: 4px;
    padding: 9px 13px; color: #c0392b; font-size: 13px; margin-bottom: 18px;
}
.success-id {
    font-size: 28px; font-weight: bold; color: #2c3e50;
    text-align: center; letter-spacing: .05em; margin: 16px 0 10px;
    font-family: monospace;
}
.success-note {
    text-align: center; font-size: 13px; color: #555; margin-bottom: 22px;
}
.tkt-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.tkt-table tr td { padding: 8px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.tkt-table tr:last-child td { border-bottom: none; }
.tkt-table td:first-child { width: 130px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #888; padding-right: 12px; padding-top: 10px; }
.tkt-desc { white-space: pre-wrap; font-size: 13px; line-height: 1.6; }
.response-box {
    background: #f0f7ff; border: 1px solid #b3d0f0; border-radius: 4px;
    padding: 12px 14px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;
}
.check-link { display: block; text-align: center; margin-top: 20px; font-size: 13px; }
.check-link a { color: #2980b9; text-decoration: none; }
.check-link a:hover { text-decoration: underline; }
.check-form { display: flex; gap: 8px; margin-top: 20px; }
.check-form input {
    flex: 1; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
    font-size: 14px; font-family: inherit;
}
.check-form input:focus { outline: none; border-color: #2980b9; }
.check-form button {
    padding: 8px 16px; background: #2c3e50; color: #fff;
    border: none; border-radius: 4px; font-size: 14px; cursor: pointer;
}
.check-form button:hover { background: #1a252f; }
.file-grid { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; margin-top: 4px; }
.file-thumb {
    max-width: 140px; max-height: 110px; border-radius: 4px;
    border: 1px solid #ddd; display: block; object-fit: cover;
}
.file-thumb:hover { border-color: #2980b9; }
.file-doc {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 13px; color: #2980b9; text-decoration: none;
    padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; background: #fafafa;
}
.file-doc:hover { background: #edf4fb; border-color: #2980b9; }
@media (max-width: 500px) {
    .card { padding: 20px 16px 24px; }
}
</style>
</head>
<body>
<div id="hdr">
    <div id="hdr-text">
        <h1>MARS APRS Tracker — Bug Reports &amp; Feedback</h1>
        <p>Submit a bug report or feature request for the iOS, Android, or Web map</p>
    </div>
    <a href="admin.php" class="hdr-btn">Admin</a>
</div>
<div id="wrap">

<?php if ($submitted): ?>
<!-- ── Confirmation ── -->
<div class="card">
    <h2>Ticket submitted</h2>
    <div class="success-id"><?= esc($submitted['id']) ?></div>
    <p class="success-note">Save this ticket ID — you can use it to check the status of your report.</p>
    <table class="tkt-table">
        <tr><td>Summary</td><td><?= esc($submitted['summary']) ?></td></tr>
        <tr><td>Platform</td><td><?= esc($submitted['platform']) ?></td></tr>
        <tr><td>Type</td><td><?= esc($submitted['type']) ?></td></tr>
        <tr><td>Status</td><td><?= statusBadge($submitted['status']) ?></td></tr>
        <?php if (!empty($submitted['files'])): $fc = count($submitted['files']); ?>
        <tr><td>Files</td><td><?= $fc ?> file<?= $fc === 1 ? '' : 's' ?> attached</td></tr>
        <?php endif; ?>
    </table>
    <div class="check-link">
        <a href="?">Submit another ticket</a>
        &nbsp;&middot;&nbsp;
        <a href="?check=<?= esc($submitted['id']) ?>">Check this ticket's status</a>
    </div>
</div>

<?php elseif ($checkTicket): ?>
<!-- ── Status check result ── -->
<div class="card">
    <h2>Ticket <?= esc($checkTicket['id']) ?></h2>
    <table class="tkt-table">
        <tr><td>Status</td><td><?= statusBadge($checkTicket['status']) ?><?php if (!empty($checkTicket['resolved_version'] ?? '')): ?> <span style="font-size:13px;color:#555;margin-left:6px">in v<?= esc($checkTicket['resolved_version']) ?></span><?php endif; ?></td></tr>
        <tr><td>Submitted</td><td><?= esc(date('M j, Y', $checkTicket['created'])) ?></td></tr>
        <tr><td>Platform</td><td><?= esc($checkTicket['platform']) ?></td></tr>
        <tr><td>Version</td><td><?= esc($checkTicket['version'] ?: '—') ?></td></tr>
        <tr><td>Type</td><td><?= esc($checkTicket['type']) ?></td></tr>
        <tr><td>Summary</td><td><?= esc($checkTicket['summary']) ?></td></tr>
        <tr><td>Description</td><td><div class="tkt-desc"><?= esc($checkTicket['description']) ?></div></td></tr>
        <?php if (!empty($checkTicket['files'])): ?>
        <tr>
            <td>Files</td>
            <td>
                <div class="file-grid">
                <?php foreach ($checkTicket['files'] as $fname):
                    $url = esc(ticketFileUrl($checkTicket['id'], $fname)); ?>
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
        <?php if ($checkTicket['admin_response'] !== ''): ?>
        <tr>
            <td>Response</td>
            <td><div class="response-box"><?= esc($checkTicket['admin_response']) ?></div></td>
        </tr>
        <?php endif; ?>
    </table>
    <div class="check-link"><a href="?">Submit a new ticket</a></div>
    <div class="check-link" style="margin-top:8px"><a href="?">Check another ticket</a></div>
</div>

<?php elseif ($checkId !== '' && $checkTicket === null): ?>
<!-- ── Ticket not found ── -->
<div class="card">
    <h2>Ticket not found</h2>
    <p style="color:#666;margin-bottom:18px">No ticket with ID <strong><?= esc($checkId) ?></strong> was found.</p>
    <form method="GET" action="">
        <div class="check-form">
            <input type="text" name="check" placeholder="TKT-0001" value="" style="text-transform:uppercase">
            <button type="submit">Check</button>
        </div>
    </form>
    <div class="check-link"><a href="?">Submit a new ticket</a></div>
</div>

<?php else: ?>
<!-- ── Submit form ── -->
<div class="card">
    <h2>Submit a Bug Report or Feature Request</h2>
    <?php if ($formError): ?>
        <div class="error"><?= esc($formError) ?></div>
    <?php endif; ?>
    <form method="POST" action="" enctype="multipart/form-data">
        <div class="field">
            <label for="f-name">Your Name <span style="color:#c0392b">*</span></label>
            <input type="text" id="f-name" name="name" required maxlength="80"
                value="<?= esc($_POST['name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="f-email">Email <span style="color:#999;font-weight:normal;text-transform:none">(optional)</span></label>
            <input type="email" id="f-email" name="email" maxlength="200"
                value="<?= esc($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="f-platform">Platform <span style="color:#c0392b">*</span></label>
            <select id="f-platform" name="platform" required>
                <option value="">— select —</option>
                <?php foreach (['iOS', 'Android', 'iOS and Android', 'Web'] as $p): ?>
                    <option value="<?= esc($p) ?>"<?= (($_POST['platform'] ?? '') === $p) ? ' selected' : '' ?>><?= esc($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-version">App Version</label>
            <?php
            $versions   = loadVersions($versionsFile);
            $postVer    = $_POST['version'] ?? '';
            $isOther    = $postVer !== '' && $postVer !== '__other__' && !in_array($postVer, $versions, true);
            $selectVal  = $isOther ? '__other__' : $postVer;
            $otherVal   = $isOther ? $postVer : ($_POST['version_other'] ?? '');
            ?>
            <select id="f-version" name="version"
                onchange="var w=document.getElementById('f-ver-other');w.style.display=this.value==='__other__'?'block':'none'">
                <option value="">— select —</option>
                <?php foreach ($versions as $v): ?>
                    <option value="<?= esc($v) ?>"<?= ($selectVal === $v) ? ' selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
                <option value="__other__"<?= ($selectVal === '__other__') ? ' selected' : '' ?>>Other / not listed</option>
            </select>
            <input type="text" id="f-ver-other" name="version_other" maxlength="60"
                placeholder="e.g. 1.16.1 — find it in the app's About section"
                value="<?= esc($otherVal) ?>"
                style="margin-top:6px;<?= ($selectVal === '__other__') ? '' : 'display:none' ?>">
        </div>
        <div class="field">
            <label for="f-type">Type <span style="color:#c0392b">*</span></label>
            <select id="f-type" name="type" required>
                <option value="">— select —</option>
                <?php foreach (['Bug', 'Feature Request', 'Question'] as $tp): ?>
                    <option value="<?= esc($tp) ?>"<?= (($_POST['type'] ?? '') === $tp) ? ' selected' : '' ?>><?= esc($tp) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-summary">Summary <span style="color:#c0392b">*</span></label>
            <input type="text" id="f-summary" name="summary" required maxlength="120"
                placeholder="One-line description of the issue or request"
                value="<?= esc($_POST['summary'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="f-desc">Description <span style="color:#c0392b">*</span></label>
            <textarea id="f-desc" name="description" rows="6" required maxlength="4000"
                placeholder="Please describe the issue in detail: what you did, what you expected, and what actually happened."><?= esc($_POST['description'] ?? '') ?></textarea>
            <span class="hint">Maximum 4000 characters.</span>
        </div>
        <div class="field">
            <label for="f-files">Screenshots / Files <span style="color:#999;font-weight:normal;text-transform:none">(optional)</span></label>
            <input type="file" id="f-files" name="files[]" multiple
                accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,image/jpeg,image/png,image/gif,image/webp,application/pdf">
            <span class="hint">Up to <?= MAX_FILES ?> files · <?= MAX_FILE_BYTES / 1024 / 1024 ?> MB each · JPEG, PNG, GIF, WebP, or PDF</span>
        </div>
        <button type="submit" class="submit-btn">Submit Ticket</button>
    </form>
    <div style="margin-top:24px;padding-top:18px;border-top:1px solid #eee">
        <form method="GET" action="">
            <p style="font-size:13px;color:#666;margin-bottom:8px">Already have a ticket ID? Check its status:</p>
            <div class="check-form">
                <input type="text" name="check" placeholder="TKT-0001" style="text-transform:uppercase">
                <button type="submit">Check</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
