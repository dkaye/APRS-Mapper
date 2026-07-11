<?php
require_once __DIR__ . '/auth.php';

$token   = preg_replace('/[^0-9a-f]/', '', $_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$success = false;

function get_reset_user(string $token): ?array {
    if (strlen($token) !== 64) return null;
    try {
        $db  = auth_db();
        $s   = $db->prepare(
            'SELECT u.id, u.username FROM password_resets r JOIN users u ON u.id = r.user_id '
          . 'WHERE r.token = ? AND r.expires > ?'
        );
        $s->bindValue(1, $token, SQLITE3_TEXT);
        $s->bindValue(2, time(), SQLITE3_INTEGER);
        $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

$resetUser = get_reset_user($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['pw1'] ?? '';
    $pw2 = $_POST['pw2'] ?? '';
    if (!$resetUser) {
        $error = 'This reset link is invalid or has expired.';
    } elseif ($pw1 === '') {
        $error = 'Password is required.';
    } elseif (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $db = auth_db();
            $u  = $db->prepare('UPDATE users SET pw_hash = ? WHERE id = ?');
            $u->bindValue(1, $hash,           SQLITE3_TEXT);
            $u->bindValue(2, $resetUser['id'], SQLITE3_INTEGER);
            $u->execute();
            $d1 = $db->prepare('DELETE FROM password_resets WHERE token = ?');
            $d1->bindValue(1, $token, SQLITE3_TEXT);
            $d1->execute();
            $d2 = $db->prepare('DELETE FROM sessions WHERE user_id = ?');
            $d2->bindValue(1, $resetUser['id'], SQLITE3_INTEGER);
            $d2->execute();
            $db->close();
            $success = true;
        } catch (Exception $e) {
            $error = 'Database error — please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Reset Password</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:340px}
.logo{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}
h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:24px}
label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em}
input[type=password]{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:15px;color:#111827;background:#f9fafb;margin-bottom:16px}
input:focus{outline:none;border-color:#2563eb;background:#fff}
button{width:100%;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;margin-top:4px}
button:hover{background:#1d4ed8}
.pw-wrap{position:relative;margin-bottom:16px}.pw-wrap input{margin-bottom:0;padding-right:40px}
.eye-btn{position:absolute;right:1px;top:1px;bottom:1px;width:36px;background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;border-radius:0 5px 5px 0}
.eye-btn:hover{color:#6b7280;background:#f0f0f0}.eye-btn svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.err{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.ok{background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;padding:12px 14px;font-size:13px;line-height:1.5}
.back{display:block;text-align:center;margin-top:16px;font-size:13px;color:#2563eb;text-decoration:none}
.back:hover{text-decoration:underline}
.invalid{color:#6b7280;font-size:14px;margin-bottom:16px}
</style>
</head>
<body>
<div class="box">
  <div class="logo">MARS APRS</div>
  <h1>Reset Password</h1>
  <?php if ($success): ?>
    <div class="ok">Password updated successfully.</div>
    <a class="back" href="/auth/login.php">Sign In</a>
  <?php elseif (!$resetUser && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <p class="invalid">This reset link is invalid or has expired.</p>
    <a class="back" href="/auth/forgot_password.php">Request a new link</a>
  <?php else: ?>
    <p class="sub">Set a new password for your account<?= $resetUser ? ' (<strong>' . htmlspecialchars($resetUser['username']) . '</strong>)' : '' ?>.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <label for="pw1">New Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw1" name="pw1" autocomplete="new-password" autofocus minlength="8">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <label for="pw2">Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw2" name="pw2" autocomplete="new-password" minlength="8">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <button type="submit">Set New Password</button>
    </form>
    <a class="back" href="/auth/login.php">Cancel</a>
  <?php endif; ?>
</div>
<script>
function togglePw(btn){var i=btn.closest('.pw-wrap').querySelector('input'),s=i.type==='password';i.type=s?'text':'password';btn.querySelector('.eye-show').style.display=s?'none':'';btn.querySelector('.eye-hide').style.display=s?'':'none';}
</script>
</body>
</html>
