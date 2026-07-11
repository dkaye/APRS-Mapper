"""
MARS APRS Authentication — validates marsaprs_session cookies against users.db.
"""
import re
import sqlite3
import time

from flask import redirect, request

USERS_DB       = '/var/lib/marsaprs/users.db'
SESSION_COOKIE = 'marsaprs_session'
LOGIN_URL      = '/auth/login.php'
_TOKEN_RE      = re.compile(r'^[0-9a-f]{64}$')


def _conn():
    conn = sqlite3.connect(USERS_DB)
    conn.row_factory = sqlite3.Row
    conn.execute('PRAGMA foreign_keys = ON')
    return conn


def current_user() -> dict | None:
    token = request.cookies.get(SESSION_COOKIE, '')
    if not _TOKEN_RE.match(token):
        return None
    try:
        with _conn() as c:
            row = c.execute(
                'SELECT u.id, u.username, u.name '
                'FROM sessions s JOIN users u ON u.id = s.user_id '
                'WHERE s.token = ? AND s.expires > ? AND u.active = 1',
                (token, int(time.time()))
            ).fetchone()
        return dict(row) if row else None
    except Exception:
        return None


def has_permission(perm: str) -> bool:
    user = current_user()
    if not user:
        return False
    try:
        with _conn() as c:
            row = c.execute(
                'SELECT 1 FROM permissions WHERE user_id = ? AND permission = ?',
                (user['id'], perm)
            ).fetchone()
        return row is not None
    except Exception:
        return False


def require_permission(perm: str):
    """Return a redirect/error response if permission is missing, else None."""
    if not has_permission(perm):
        user = current_user()
        if not user:
            return redirect(f'{LOGIN_URL}?next={request.path}')
        from flask import make_response
        nav_pages = [
            ('admin.view',     'Map Admin',       '/admin/'),
            ('analyzer.view',  'Analyzer',        '/analyzer/'),
            ('netbird.view',   'NetBird Status',  '/netbird/'),
            ('wifi.admin',     'WiFi Management', '/wifi/'),
            ('users.manage',   'User Management', '/auth/users.php'),
            ('tickets.manage', 'Tickets',         '/tickets/'),
        ]
        nav_links = ''.join(
            f'<a class="nav-link" href="{url}">{label} &rsaquo;</a>'
            for np, label, url in nav_pages
            if np != perm and has_permission(np)
        )
        if nav_links:
            heading  = 'Where would you like to go?'
            subtext  = f'Your account doesn&rsquo;t have access to this page (<code>{perm}</code>), but you can reach the following:'
            body     = f'<div class="nav-links">{nav_links}</div><div class="footer"><a href="/auth/logout.php">Sign in with a different account</a></div>'
        else:
            heading  = 'Access Denied'
            subtext  = f'Your account doesn&rsquo;t have the <code>{perm}</code> permission.'
            body     = '<div class="actions"><a class="btn btn-primary" href="/auth/logout.php">Sign in with a different account</a><a class="btn btn-ghost" href="javascript:history.back()">Go back</a></div>'
        html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MARS APRS — Access Denied</title>
<style>
*{{box-sizing:border-box;margin:0;padding:0}}
body{{background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}}
.box{{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px 40px;width:400px}}
.logo{{font-size:12px;font-weight:700;letter-spacing:.1em;color:#6b7280;text-transform:uppercase;margin-bottom:20px}}
h1{{font-size:20px;font-weight:700;color:#111827;margin-bottom:10px}}
p{{font-size:14px;color:#6b7280;line-height:1.55;margin-bottom:20px}}
code{{background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:13px;color:#374151;font-family:monospace}}
.nav-links{{display:flex;flex-direction:column;gap:8px}}
.nav-link{{display:block;padding:11px 16px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:7px;font-size:15px;color:#1d4ed8;text-decoration:none;font-weight:600;transition:background .12s}}
.nav-link:hover{{background:#dbeafe;border-color:#93c5fd}}
.actions{{display:flex;flex-direction:column;gap:10px;margin-top:20px}}
.btn{{display:block;text-align:center;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:500;text-decoration:none}}
.btn-primary{{background:#2563eb;color:#fff}}.btn-primary:hover{{background:#1d4ed8}}
.btn-ghost{{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}}.btn-ghost:hover{{background:#e5e7eb}}
.footer{{margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;color:#9ca3af;text-align:center}}
.footer a{{color:#6b7280;text-decoration:none}}.footer a:hover{{text-decoration:underline}}
</style>
</head>
<body>
<div class="box">
  <div class="logo">MARS APRS</div>
  <h1>{heading}</h1>
  <p>{subtext}</p>
  {body}
</div>
</body>
</html>"""
        return make_response(html, 403)
    return None
