<?php
require_once __DIR__ . '/auth.php';

// Already logged in → no need for this page
if (current_user()) {
    header('Location: /admin/');
    exit;
}

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = get_user_by_email($email);
        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = time() + 3600;
            try {
                $db = auth_db();
                $d  = $db->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $d->bindValue(1, $user['id'], SQLITE3_INTEGER);
                $d->execute();
                $s  = $db->prepare('INSERT INTO password_resets (token, user_id, expires) VALUES (?, ?, ?)');
                $s->bindValue(1, $token,      SQLITE3_TEXT);
                $s->bindValue(2, $user['id'], SQLITE3_INTEGER);
                $s->bindValue(3, $expires,    SQLITE3_INTEGER);
                $s->execute();
                $db->close();
                $link = 'https://marsaprs.org/auth/reset_password.php?token=' . $token;
                $body = "You requested a password reset for your MARS APRS account.\r\n\r\n"
                      . "Click the link below to set a new password. The link expires in 1 hour.\r\n\r\n"
                      . $link . "\r\n\r\n"
                      . "If you did not request this, ignore this email — your password is unchanged.\r\n";
                mail($email, 'MARS APRS Password Reset', $body,
                     "From: no-reply@marsaprs.org\r\nContent-Type: text/plain; charset=UTF-8");
            } catch (Exception $e) {}
        }
    }
    // Always show "sent" to avoid email enumeration
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Forgot Password</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:340px}
.logo{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}
h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:24px}
label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em}
input[type=email]{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:15px;color:#111827;background:#f9fafb;margin-bottom:16px}
input:focus{outline:none;border-color:#2563eb;background:#fff}
button{width:100%;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;margin-top:4px}
button:hover{background:#1d4ed8}
.ok{background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;padding:12px 14px;font-size:13px;line-height:1.5}
.back{display:block;text-align:center;margin-top:16px;font-size:13px;color:#2563eb;text-decoration:none}
.back:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="box">
  <div class="logo">MARS APRS</div>
  <h1>Forgot Password</h1>
  <?php if ($sent): ?>
    <div class="ok">If that email is registered, a reset link has been sent. Check your inbox (and spam folder).</div>
    <a class="back" href="/auth/login.php">Back to Sign In</a>
  <?php else: ?>
    <p class="sub">Enter your email address and we'll send you a reset link.</p>
    <form method="post">
      <label for="email">Email Address</label>
      <input type="email" id="email" name="email" autocomplete="email" autofocus>
      <button type="submit">Send Reset Link</button>
    </form>
    <a class="back" href="/auth/login.php">Back to Sign In</a>
  <?php endif; ?>
</div>
</body>
</html>
