<?php
require_once __DIR__ . '/auth.php';
require_permission('users.manage');

const KNOWN_PERMISSIONS = [
    'users.manage'       => 'Manage users &amp; permissions',
    'admin.view'         => 'Map admin — view only',
    'admin.edit'         => 'Map admin — edit (requires admin.view)',
    'admin.set_default'  => 'Save as Default Event',
    'admin.delete_event' => 'Delete events',
    'analyzer.view'      => 'View analyzer',
    'analyzer.admin'     => 'Analyzer admin (erase data, daemon)',
    'netbird.view'       => 'View NetBird status',
    'netbird.admin'      => 'Manage NetBird devices',
    'wifi.admin'         => 'WiFi management',
    'tickets.manage'     => 'Manage tickets',
    'messages.delete_all' => 'Delete All Messages',
];

// Permissions shown in the "Basic access" group box
const BASIC_PERMISSIONS = ['admin.view', 'netbird.view', 'analyzer.view'];

function renderPermissions(array $granted = [], ?int $lockedId = null, ?int $meId = null): void {
    $basic = BASIC_PERMISSIONS;
    echo '<div class="perm-group"><div class="perm-group-label">Basic access</div>';
    foreach ($basic as $key) {
        $label    = KNOWN_PERMISSIONS[$key];
        $checked  = in_array($key, $granted) ? 'checked' : '';
        $disabled = ($key === 'users.manage' && $lockedId !== null && $lockedId === $meId) ? 'disabled' : '';
        echo "<label class=\"perm-row\"><input type=\"checkbox\" name=\"perms[]\" value=\"$key\" $checked $disabled> $label</label>";
    }
    echo '</div>';
    echo '<div class="perm-grid" style="margin-top:8px">';
    foreach (KNOWN_PERMISSIONS as $key => $label) {
        if (in_array($key, $basic)) continue;
        $checked  = in_array($key, $granted) ? 'checked' : '';
        $disabled = ($key === 'users.manage' && $lockedId !== null && $lockedId === $meId) ? 'disabled' : '';
        echo "<label class=\"perm-row\"><input type=\"checkbox\" name=\"perms[]\" value=\"$key\" $checked $disabled> $label</label>";
    }
    echo '</div>';
}

$me    = current_user();
$error = '';
$ok    = '';

function db_users(): array {
    $db   = auth_db();
    $rows = [];
    $res  = $db->query(
        'SELECT u.id, u.username, u.name, u.email, u.active, u.created, u.last_login,
                GROUP_CONCAT(p.permission, ",") AS perms
         FROM users u
         LEFT JOIN permissions p ON p.user_id = u.id
         GROUP BY u.id ORDER BY u.username'
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['perm_list'] = $r['perms'] ? explode(',', $r['perms']) : [];
        $rows[] = $r;
    }
    $db->close();
    return $rows;
}

function save_perms(SQLite3 $db, int $uid, array $perms): void {
    $db->exec("DELETE FROM permissions WHERE user_id = $uid");
    foreach ($perms as $p) {
        if (!array_key_exists($p, KNOWN_PERMISSIONS)) continue;
        $s = $db->prepare('INSERT OR IGNORE INTO permissions (user_id, permission) VALUES (?, ?)');
        $s->bindValue(1, $uid, SQLITE3_INTEGER);
        $s->bindValue(2, $p,   SQLITE3_TEXT);
        $s->execute();
    }
}

// ── POST handlers ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name']     ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $pw1      = $_POST['pw1'] ?? '';
        $pw2      = $_POST['pw2'] ?? '';
        $perms    = $_POST['perms'] ?? [];
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{2,32}$/', $username)) {
            $error = 'Username must be 2–32 chars (letters, digits, _, -, .)';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address';
        } elseif ($pw1 === '') {
            $error = 'Password is required';
        } elseif ($pw1 !== $pw2) {
            $error = 'Passwords do not match';
        } else {
            $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
            $now  = time();
            try {
                $db = auth_db();
                $s  = $db->prepare(
                    'INSERT INTO users (username, name, email, pw_hash, active, created) VALUES (?, ?, ?, ?, 1, ?)'
                );
                $s->bindValue(1, $username, SQLITE3_TEXT);
                $s->bindValue(2, $name,     SQLITE3_TEXT);
                $s->bindValue(3, $email,    SQLITE3_TEXT);
                $s->bindValue(4, $hash,     SQLITE3_TEXT);
                $s->bindValue(5, $now,      SQLITE3_INTEGER);
                $s->execute();
                $uid = $db->lastInsertRowID();
                save_perms($db, (int)$uid, $perms);
                $db->close();
                $ok = "User &ldquo;$username&rdquo; created.";
            } catch (Exception $e) {
                $error = str_contains($e->getMessage(), 'UNIQUE') ? "Username &ldquo;$username&rdquo; already exists." : 'Database error.';
            }
        }
    }

    if ($action === 'edit') {
        $uid   = (int)($_POST['uid'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $perms = $_POST['perms'] ?? [];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address';
            goto done;
        }
        // active: can't deactivate yourself
        $active = ($uid === (int)$me['id']) ? 1 : (int)!empty($_POST['active']);
        try {
            $db = auth_db();
            $s  = $db->prepare('UPDATE users SET name = ?, email = ?, active = ? WHERE id = ?');
            $s->bindValue(1, $name,   SQLITE3_TEXT);
            $s->bindValue(2, $email,  SQLITE3_TEXT);
            $s->bindValue(3, $active, SQLITE3_INTEGER);
            $s->bindValue(4, $uid,    SQLITE3_INTEGER);
            $s->execute();
            // Preserve users.manage for yourself
            if ($uid === (int)$me['id'] && !in_array('users.manage', $perms)) {
                $perms[] = 'users.manage';
            }
            save_perms($db, $uid, $perms);
            $db->close();
            $ok = 'Changes saved.';
        } catch (Exception $e) {
            $error = 'Save failed.';
        }
        done:;
    }

    if ($action === 'reset_pw') {
        $uid = (int)($_POST['uid'] ?? 0);
        $pw1 = $_POST['pw1'] ?? '';
        $pw2 = $_POST['pw2'] ?? '';
        if ($pw1 === '') {
            $error = 'Password is required';
        } elseif ($pw1 !== $pw2) {
            $error = 'Passwords do not match';
        } else {
            $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
            $db   = auth_db();
            $s    = $db->prepare('UPDATE users SET pw_hash = ? WHERE id = ?');
            $s->bindValue(1, $hash, SQLITE3_TEXT);
            $s->bindValue(2, $uid,  SQLITE3_INTEGER);
            $s->execute();
            // Invalidate all sessions for that user except current
            $cur = $_COOKIE[MARSAPRS_SESSION_COOKIE] ?? '';
            $ds  = $db->prepare('DELETE FROM sessions WHERE user_id = ? AND token != ?');
            $ds->bindValue(1, $uid, SQLITE3_INTEGER);
            $ds->bindValue(2, $cur, SQLITE3_TEXT);
            $ds->execute();
            $db->close();
            $ok = 'Password updated.';
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === (int)$me['id']) {
            $error = 'Cannot delete your own account.';
        } else {
            $db = auth_db();
            $s  = $db->prepare('DELETE FROM users WHERE id = ?');
            $s->bindValue(1, $uid, SQLITE3_INTEGER);
            $s->execute();
            $db->close();
            $ok = 'User deleted.';
        }
    }
}

$users  = db_users();
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Users</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;color:#111827;font-size:14px}
#hdr{background:#1e3a5f;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:16px}
#hdr h1{font-size:15px;font-weight:700}
#hdr a{color:#93c5fd;font-size:13px;text-decoration:none}
#hdr a:hover{text-decoration:underline}
.wrap{max-width:860px;margin:28px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:20px;overflow:hidden}
.card-hdr{padding:14px 20px;font-weight:700;font-size:13px;color:#374151;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
.card-body{padding:20px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:8px 12px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e5e7eb}
td{padding:9px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;font-size:11px;padding:1px 7px;border-radius:10px;margin:1px}
.badge-perm{background:#dbeafe;color:#1e40af}
.badge-active{background:#dcfce7;color:#166534}
.badge-pending{background:#fef9c3;color:#854d0e}
.badge-off{background:#f3f4f6;color:#6b7280}
label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;margin-top:12px;text-transform:uppercase;letter-spacing:.05em}
label:first-of-type{margin-top:0}
input[type=text],input[type=password]{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:14px;background:#f9fafb;color:#111827}
input:focus{outline:none;border-color:#2563eb;background:#fff}
.pw-wrap{position:relative;margin-bottom:16px}.pw-wrap input{margin-bottom:0;padding-right:36px}
.eye-btn{position:absolute;right:1px;top:1px;bottom:1px;width:32px;background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;border-radius:0 4px 4px 0}
.eye-btn:hover{color:#6b7280;background:#f0f0f0}.eye-btn svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-top:8px}
.perm-row{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
.perm-row input{width:auto}
.perm-group{border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;margin-top:8px;background:#f9fafb}
.perm-group-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px}
.btns{display:flex;gap:8px;margin-top:16px}
.btn{padding:8px 16px;border-radius:5px;font-size:13px;font-weight:500;border:none;cursor:pointer}
.btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-ghost{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}.btn-ghost:hover{background:#e5e7eb}
.msg-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px}
.msg-err{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px}
.section-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #e5e7eb}
.add-link{font-size:13px;color:#2563eb;text-decoration:none;font-weight:500}
.add-link:hover{text-decoration:underline}
.ts{font-size:12px;color:#9ca3af}
.del-btn{background:none;border:none;cursor:pointer;color:#d1d5db;padding:2px 4px;border-radius:4px;font-size:16px;line-height:1;transition:color .15s}
.del-btn:hover{color:#dc2626}
/* Warning modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:28px 32px;max-width:400px;width:90%}
.modal h2{font-size:16px;font-weight:700;color:#111827;margin-bottom:10px}
.modal p{font-size:14px;color:#374151;line-height:1.55;margin-bottom:20px}
.modal-btns{display:flex;gap:10px;justify-content:flex-end}
</style>
</head>
<body>
<div id="hdr">
  <h1>MARS APRS — User Management</h1>
  <a href="/admin/">Map Admin</a>
  <a href="/auth/logout.php">Sign out</a>
</div>
<div class="wrap">

<?php if ($ok):  ?><div class="msg-ok"><?= $ok ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg-err"><?= $error ?></div><?php endif; ?>

<!-- ── User list ── -->
<div class="card">
  <div class="card-hdr">
    Users (<?= count($users) ?>)
    <a class="add-link" href="?add=1">+ Add User</a>
  </div>
  <table>
    <tr>
      <th>Username</th><th>Display Name</th><th>Email</th><th>Status</th>
      <th>Permissions</th><th>Last Login</th><th></th>
    </tr>
    <?php foreach ($users as $u):
        $has_any_perms = !empty($u['perm_list']);
        $is_pending    = $u['active'] && !$has_any_perms;
        $is_me         = ($u['id'] === $me['id']);
        $has_um        = in_array('users.manage', $u['perm_list']);
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
      <td><?= htmlspecialchars($u['name']) ?></td>
      <td class="ts"><?= htmlspecialchars($u['email']) ?></td>
      <td>
        <?php if ($is_pending): ?>
          <span class="badge badge-pending">Pending</span>
        <?php elseif ($u['active']): ?>
          <span class="badge badge-active">Active</span>
        <?php else: ?>
          <span class="badge badge-off">Disabled</span>
        <?php endif; ?>
        <?php if ($is_me): ?><span class="badge badge-off">you</span><?php endif; ?>
      </td>
      <td>
        <?php foreach ($u['perm_list'] as $p): ?>
          <span class="badge badge-perm"><?= htmlspecialchars($p) ?></span>
        <?php endforeach; ?>
        <?php if (!$has_any_perms): ?><span class="ts">none</span><?php endif; ?>
      </td>
      <td class="ts"><?= $u['last_login'] ? date('Y-m-d H:i', $u['last_login']) : 'Never' ?></td>
      <td style="white-space:nowrap">
        <a class="add-link" href="?edit=<?= $u['id'] ?>">Edit</a>
        <?php if (!$is_me): ?>
        <button class="del-btn" title="Delete <?= htmlspecialchars($u['username']) ?>"
                onclick="confirmDelete(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username'])) ?>, <?= $has_um ? 'true' : 'false' ?>)"
                >&#128465;</button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- ── Add user form ── -->
<?php if (isset($_GET['add'])): ?>
<div class="card">
  <div class="card-hdr">Add User</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="action" value="create">
      <label>Username</label>
      <input type="text" name="username" autocomplete="off" autofocus>
      <label>Display Name</label>
      <input type="text" name="name" autocomplete="off">
      <label>Email <span style="font-weight:400;color:#6b7280">(for password recovery)</span></label>
      <input type="email" name="email" autocomplete="off">
      <label>Password</label>
      <div class="pw-wrap">
        <input type="password" name="pw1" autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <label>Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" name="pw2" autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <label style="margin-top:16px">Permissions</label>
      <?php renderPermissions(); ?>
      <div class="btns">
        <button class="btn btn-primary" type="submit">Create User</button>
        <a class="btn btn-ghost" href="users.php">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Edit user form ── -->
<?php
$editUser = null;
if ($editId) {
    foreach ($users as $u) {
        if ($u['id'] === $editId) { $editUser = $u; break; }
    }
}
if ($editUser): ?>
<div class="card">
  <div class="card-hdr">Edit: <?= htmlspecialchars($editUser['username']) ?></div>
  <div class="card-body">

    <div class="section-title">Profile &amp; Permissions</div>
    <form method="post">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="uid" value="<?= $editUser['id'] ?>">
      <label>Display Name</label>
      <input type="text" name="name" value="<?= htmlspecialchars($editUser['name']) ?>">
      <label>Email <span style="font-weight:400;color:#6b7280">(for password recovery)</span></label>
      <input type="email" name="email" value="<?= htmlspecialchars($editUser['email']) ?>">
      <?php if ($editUser['id'] !== $me['id']): ?>
      <label style="margin-top:12px">
        <input type="checkbox" name="active" value="1" <?= $editUser['active'] ? 'checked' : '' ?>>
        Account active
      </label>
      <?php endif; ?>
      <label style="margin-top:16px">Permissions</label>
      <?php renderPermissions($editUser['perm_list'], $editUser['id'], $me['id']); ?>
      <div class="btns">
        <button class="btn btn-primary" type="submit">Save Changes</button>
        <a class="btn btn-ghost" href="users.php">Cancel</a>
      </div>
    </form>

    <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb">
    <div class="section-title">Reset Password</div>
    <form method="post">
      <input type="hidden" name="action" value="reset_pw">
      <input type="hidden" name="uid" value="<?= $editUser['id'] ?>">
      <label>New Password</label>
      <div class="pw-wrap">
        <input type="password" name="pw1" autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <label>Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" name="pw2" autocomplete="new-password">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <div class="btns">
        <button class="btn btn-primary" type="submit">Set Password</button>
      </div>
    </form>

    <?php if ($editUser['id'] !== $me['id']): ?>
    <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb">
    <div class="section-title">Danger Zone</div>
    <div class="btns">
      <button class="btn btn-danger" type="button"
              onclick="confirmDelete(<?= $editUser['id'] ?>, <?= htmlspecialchars(json_encode($editUser['username'])) ?>, <?= in_array('users.manage', $editUser['perm_list']) ? 'true' : 'false' ?>)">
        Delete User
      </button>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

</div><!-- .wrap -->

<!-- ── Delete form (shared, submitted by JS) ── -->
<form id="delete-form" method="post" style="display:none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="uid" id="delete-uid">
</form>

<!-- ── Warning modal (users.manage) ── -->
<div class="modal-overlay" id="warn-modal">
  <div class="modal">
    <h2>&#9888; Delete admin account?</h2>
    <p id="warn-modal-msg"></p>
    <div class="modal-btns">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" onclick="doDelete()">Delete anyway</button>
    </div>
  </div>
</div>

<!-- ── Simple confirm modal (no users.manage) ── -->
<div class="modal-overlay" id="confirm-modal">
  <div class="modal">
    <h2>Delete user?</h2>
    <p id="confirm-modal-msg"></p>
    <div class="modal-btns">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" onclick="doDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
function togglePw(btn){var i=btn.closest('.pw-wrap').querySelector('input'),s=i.type==='password';i.type=s?'text':'password';btn.querySelector('.eye-show').style.display=s?'none':'';btn.querySelector('.eye-hide').style.display=s?'':'none';}
function confirmDelete(uid, username, hasUsersManage) {
    document.getElementById('delete-uid').value = uid;
    if (hasUsersManage) {
        document.getElementById('warn-modal-msg').textContent =
            username + ' has the "Manage users & permissions" privilege. ' +
            'Deleting this account cannot be undone.';
        document.getElementById('warn-modal').classList.add('open');
    } else {
        document.getElementById('confirm-modal-msg').textContent =
            'Delete ' + username + '? This cannot be undone.';
        document.getElementById('confirm-modal').classList.add('open');
    }
}
function closeModal() {
    document.getElementById('warn-modal').classList.remove('open');
    document.getElementById('confirm-modal').classList.remove('open');
}
function doDelete() {
    document.getElementById('delete-form').submit();
}
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) closeModal();
    });
});
</script>
</body>
</html>
