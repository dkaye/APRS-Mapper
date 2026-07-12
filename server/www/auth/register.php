<?php
require_once __DIR__ . '/auth.php';

// Already logged in
if (current_user()) {
    header('Location: /admin/');
    exit;
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name']     ?? '');
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $pw1      = $_POST['pw1'] ?? '';
    $pw2      = $_POST['pw2'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_\-\.]{2,32}$/', $username)) {
        $error = 'Username must be 2–32 characters (letters, digits, _, -, .)';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } elseif ($pw1 === '') {
        $error = 'Password is required';
    } elseif (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters';
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
            $db->close();
            $done = true;
            $ticketsCfg = @json_decode(@file_get_contents('/var/www/html/tickets/config.json'), true) ?: [];
            $managerEmail = $ticketsCfg['manager_email'] ?? '';
            if ($managerEmail !== '' && filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
                $subj = 'MARS APRS: New account request — ' . $username;
                $body = "A new account has been created and is awaiting permissions.\n\n"
                      . "Username: $username\n"
                      . ($name  !== '' ? "Name:     $name\n"  : '')
                      . ($email !== '' ? "Email:    $email\n" : '')
                      . "\nGrant access at: https://marsaprs.org/auth/users.php";
                @mail($managerEmail, $subj, $body, "From: noreply@marsaprs.org\r\nContent-Type: text/plain; charset=utf-8");
            }
        } catch (Exception $e) {
            $error = str_contains($e->getMessage(), 'UNIQUE')
                ? "Username &ldquo;$username&rdquo; is already taken."
                : 'Could not create account — please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Create Account</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:360px}
.logo{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}
h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:24px;line-height:1.5}
label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em}
input[type=text],input[type=email],input[type=password]{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:15px;color:#111827;background:#f9fafb;margin-bottom:16px}
input:focus{outline:none;border-color:#2563eb;background:#fff}
button{width:100%;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;margin-top:4px}
button:hover{background:#1d4ed8}
.pw-wrap{position:relative;margin-bottom:16px}.pw-wrap input{margin-bottom:0;padding-right:40px}
.eye-btn{position:absolute;right:1px;top:1px;bottom:1px;width:36px;background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;border-radius:0 5px 5px 0}
.eye-btn:hover{color:#6b7280;background:#f0f0f0}.eye-btn svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.err{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.ok{background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;padding:14px;font-size:13px;line-height:1.6}
.back{display:block;text-align:center;margin-top:16px;font-size:13px;color:#2563eb;text-decoration:none}
.back:hover{text-decoration:underline}
.hint{font-size:11px;color:#9ca3af;margin-top:-12px;margin-bottom:16px}
</style>
</head>
<body>
<div class="box">
  <div class="logo">MARS APRS</div>
  <h1>Create Account</h1>
  <?php if ($done): ?>
    <div class="ok">
      Account created. An administrator will grant you access — you'll be able to sign in once permissions are assigned.
    </div>
    <a class="back" href="/">Back to Map</a>
  <?php else: ?>
    <p class="sub">New accounts have no permissions until an administrator grants access.</p>
    <?php if ($error): ?><div class="err"><?= $error ?></div><?php endif; ?>
    <form method="post">
      <label for="u">Username</label>
      <input type="text" id="u" name="username" autocomplete="username" autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      <label for="n">Display Name <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
      <input type="text" id="n" name="name" autocomplete="name"
             value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      <label for="e">Email <span style="font-weight:400;color:#9ca3af">(optional, for password recovery)</span></label>
      <input type="email" id="e" name="email" autocomplete="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <label for="p1">Password</label>
      <div class="pw-wrap">
        <input type="password" id="p1" name="pw1" autocomplete="new-password" minlength="8">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <p class="hint">At least 8 characters</p>
      <label for="p2">Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" id="p2" name="pw2" autocomplete="new-password" minlength="8">
        <button type="button" class="eye-btn" onclick="togglePw(this)" tabindex="-1" aria-label="Show password">
          <svg class="eye-show" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-hide" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <button type="submit">Create Account</button>
    </form>
    <a class="back" href="/auth/login.php">Already have an account? Sign in</a>
    <a class="back" href="/" style="color:#6b7280;margin-top:6px">Cancel</a>
  <?php endif; ?>
</div>
<script>
function togglePw(btn){var i=btn.closest('.pw-wrap').querySelector('input'),s=i.type==='password';i.type=s?'text':'password';btn.querySelector('.eye-show').style.display=s?'none':'';btn.querySelector('.eye-hide').style.display=s?'':'none';}
</script>
</body>
</html>
