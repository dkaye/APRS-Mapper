<?php
/**
 * MARS APRS Shared Authentication Library
 *
 * Include at the top of every protected PHP page.
 * Validates marsaprs_session cookies against /var/lib/marsaprs/users.db.
 */

define('MARSAPRS_USERS_DB',    '/var/lib/marsaprs/users.db');
define('MARSAPRS_SESSION_TTL', 86400);
define('MARSAPRS_SESSION_COOKIE', 'marsaprs_session');

// ── Database ──────────────────────────────────────────────────────────────────

function auth_db(): SQLite3 {
    $db = new SQLite3(MARSAPRS_USERS_DB, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(2000);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

// ── Session ───────────────────────────────────────────────────────────────────

function current_user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $token = $_COOKIE[MARSAPRS_SESSION_COOKIE] ?? '';
    if (!$token || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return $cached = null;
    }
    try {
        $db  = auth_db();
        $s   = $db->prepare(
            'SELECT u.id, u.username, u.name, u.email, u.active
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token = ? AND s.expires > ? AND u.active = 1'
        );
        $s->bindValue(1, $token, SQLITE3_TEXT);
        $s->bindValue(2, time(), SQLITE3_INTEGER);
        $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return $cached = ($row ?: null);
    } catch (Exception $e) {
        return $cached = null;
    }
}

function get_user_by_email(string $email): ?array {
    try {
        $db  = auth_db();
        $s   = $db->prepare('SELECT id, username FROM users WHERE LOWER(email) = LOWER(?) AND active = 1');
        $s->bindValue(1, $email, SQLITE3_TEXT);
        $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function create_session(int $userId): string {
    $token = bin2hex(random_bytes(32));
    $ip    = $_SERVER['HTTP_CF_CONNECTING_IP']
          ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : null)
          ?? $_SERVER['REMOTE_ADDR']
          ?? '-';
    $now = time();
    $db  = auth_db();
    $s   = $db->prepare(
        'INSERT INTO sessions (token, user_id, created, expires, ip) VALUES (?, ?, ?, ?, ?)'
    );
    $s->bindValue(1, $token,                           SQLITE3_TEXT);
    $s->bindValue(2, $userId,                          SQLITE3_INTEGER);
    $s->bindValue(3, $now,                             SQLITE3_INTEGER);
    $s->bindValue(4, $now + MARSAPRS_SESSION_TTL,      SQLITE3_INTEGER);
    $s->bindValue(5, $ip,                              SQLITE3_TEXT);
    $s->execute();
    $up = $db->prepare('UPDATE users SET last_login = ? WHERE id = ?');
    $up->bindValue(1, $now,    SQLITE3_INTEGER);
    $up->bindValue(2, $userId, SQLITE3_INTEGER);
    $up->execute();
    $db->close();
    return $token;
}

function destroy_session(): void {
    $token = $_COOKIE[MARSAPRS_SESSION_COOKIE] ?? '';
    if ($token && preg_match('/^[0-9a-f]{64}$/', $token)) {
        try {
            $db = auth_db();
            $s  = $db->prepare('DELETE FROM sessions WHERE token = ?');
            $s->bindValue(1, $token, SQLITE3_TEXT);
            $s->execute();
            $db->close();
        } catch (Exception $e) {}
    }
    setcookie(MARSAPRS_SESSION_COOKIE, '', [
        'expires'  => 1,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
        'secure'   => true,
    ]);
}

function set_session_cookie(string $token): void {
    setcookie(MARSAPRS_SESSION_COOKIE, $token, [
        'expires'  => time() + MARSAPRS_SESSION_TTL,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
        'secure'   => true,
    ]);
}

function purge_expired_sessions(): void {
    try {
        $db = auth_db();
        $db->exec('DELETE FROM sessions WHERE expires < ' . time());
        $db->close();
    } catch (Exception $e) {}
}

// ── Permissions ───────────────────────────────────────────────────────────────

function has_permission(string $perm): bool {
    $user = current_user();
    if (!$user) return false;
    try {
        $db = auth_db();
        $s  = $db->prepare(
            'SELECT 1 FROM permissions WHERE user_id = ? AND permission = ?'
        );
        $s->bindValue(1, $user['id'], SQLITE3_INTEGER);
        $s->bindValue(2, $perm,       SQLITE3_TEXT);
        $ok = $s->execute()->fetchArray();
        $db->close();
        return (bool)$ok;
    } catch (Exception $e) {
        return false;
    }
}

function require_permission(string $perm): void {
    $user = current_user();
    if (!$user) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /auth/login.php?next=' . $next);
        exit;
    }
    if (!has_permission($perm)) {
        http_response_code(403);
        // JSON callers
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
            str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Missing permission: $perm"]);
            exit;
        }
        $p = htmlspecialchars($perm);
        // Build list of pages this user can actually reach (top-level only)
        $nav_pages = [
            'admin.view'    => ['Map Admin',       '/admin/'],
            'analyzer.view' => ['Analyzer',        '/analyzer/'],
            'netbird.view'  => ['NetBird Status',  '/netbird/'],
            'wifi.admin'    => ['WiFi Management', '/wifi/'],
            'users.manage'  => ['User Management', '/auth/users.php'],
            'tickets.manage'=> ['Tickets',         '/tickets/'],
        ];
        $nav_links = '';
        foreach ($nav_pages as $np => [$label, $url]) {
            if ($np !== $perm && has_permission($np)) {
                $nav_links .= '<a class="nav-link" href="' . $url . '">' . $label . ' &rsaquo;</a>';
            }
        }
        // When user has accessible pages, lead with those; otherwise lead with the denial
        if ($nav_links) {
            $heading  = 'Where would you like to go?';
            $subtext  = 'Your account doesn&rsquo;t have access to this page (<code>' . $p . '</code>), but you can reach the following:';
            $nav_html = '<div class="nav-links">' . $nav_links . '</div>';
            $footer   = '<div class="footer"><a href="/auth/logout.php">Sign in with a different account</a></div>';
        } else {
            $heading  = 'Access Denied';
            $subtext  = 'Your account doesn&rsquo;t have the <code>' . $p . '</code> permission.';
            $nav_html = '';
            $footer   = '<div class="actions"><a class="btn btn-primary" href="/auth/logout.php">Sign in with a different account</a><a class="btn btn-ghost" href="javascript:history.back()">Go back</a></div>';
        }
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>MARS APRS — Access Denied</title>
        <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
        .box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:400px}
        .logo{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}
        h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:10px}
        p{font-size:14px;color:#6b7280;line-height:1.55;margin-bottom:20px}
        code{background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:13px;color:#374151;font-family:monospace}
        .nav-links{display:flex;flex-direction:column;gap:8px}
        .nav-link{display:block;padding:11px 16px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:7px;font-size:15px;color:#1d4ed8;text-decoration:none;font-weight:600;transition:background .12s}
        .nav-link:hover{background:#dbeafe;border-color:#93c5fd}
        .actions{display:flex;flex-direction:column;gap:10px;margin-top:20px}
        .btn{display:block;text-align:center;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:500;text-decoration:none}
        .btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
        .btn-ghost{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}.btn-ghost:hover{background:#e5e7eb}
        .footer{margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;color:#9ca3af;text-align:center}
        .footer a{color:#6b7280;text-decoration:none}.footer a:hover{text-decoration:underline}
        </style>
        </head>
        <body>
        <div class="box">
          <div class="logo">MARS APRS</div>
          <h1>$heading</h1>
          <p>$subtext</p>
          $nav_html
          $footer
        </div>
        </body>
        </html>
        HTML;
        exit;
    }
}
