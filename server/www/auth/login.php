<?php
require_once __DIR__ . '/auth.php';

$next = $_GET['next'] ?? $_POST['next'] ?? '/admin/';
if (!preg_match('/^\/[a-zA-Z0-9\/_\-\.?=&%]*$/', $next) || str_contains($next, '//')) {
    $next = '/admin/';
}

// Already logged in
if (current_user()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username !== '' && $password !== '') {
        try {
            $db  = auth_db();
            $s   = $db->prepare('SELECT id, pw_hash, active FROM users WHERE username = ?');
            $s->bindValue(1, $username, SQLITE3_TEXT);
            $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
            $db->close();
            if ($row && $row['active'] && password_verify($password, $row['pw_hash'])) {
                purge_expired_sessions();
                $token = create_session((int)$row['id']);
                set_session_cookie($token);
                header('Location: ' . $next);
                exit;
            }
        } catch (Exception $e) {}
    }
    $error = 'Incorrect username or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Sign In</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:340px}
.logo{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}
h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:24px}
label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em}
input[type=text],input[type=password]{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:15px;color:#111827;background:#f9fafb;margin-bottom:16px}
input:focus{outline:none;border-color:#2563eb;background:#fff}
button{width:100%;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;margin-top:4px}
button:hover{background:#1d4ed8}
.pw-wrap{position:relative;margin-bottom:16px}.pw-wrap input{margin-bottom:0;padding-right:40px}
.eye-btn{position:absolute;right:1px;top:1px;bottom:1px;width:36px;background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;border-radius:0 5px 5px 0}
.eye-btn:hover{color:#6b7280;background:#f0f0f0}.eye-btn svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.err{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.forgot{display:block;text-align:right;font-size:12px;color:#6b7280;text-decoration:none;margin-top:-10px;margin-bottom:12px}
.forgot:hover{color:#2563eb}
.register{display:block;text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280}
.register a{color:#2563eb;text-decoration:none}.register a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="box">
  <div class="logo">MARS APRS</div>
  <h1>Sign In</h1>
  <p class="sub">Enter your credentials to continue.</p>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <label for="u">Username</label>
    <input type="text" id="u" name="username" autocomplete="username" autofocus>
    <label for="p">Password</label>
    <div class="pw-wrap">
      <input type="password" id="p" name="password" autocomplete="current-password">
      <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
        <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
      </button>
    </div>
    <a class="forgot" href="/auth/forgot_password.php">Forgot password?</a>
    <button type="submit">Sign In</button>
  </form>
  <p class="register">New here? <a href="/auth/register.php">Create an account</a> &nbsp;&middot;&nbsp; <a href="/">Cancel</a></p>
</div>
<script>
function togglePw(btn){var i=btn.closest('.pw-wrap').querySelector('input'),s=i.type==='password';i.type=s?'text':'password';btn.querySelector('.eye-show').style.display=s?'none':'';btn.querySelector('.eye-hide').style.display=s?'':'none';}
</script>
</body>
</html>
