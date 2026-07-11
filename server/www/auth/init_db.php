#!/usr/bin/env php
<?php
/**
 * init_db.php — Initialize MARS APRS user database (idempotent)
 *
 * Run as: sudo -u www-data php /var/www/html/auth/init_db.php
 * Or non-interactively: sudo -u www-data php /var/www/html/auth/init_db.php --user=doug --pass=secret
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

define('MARSAPRS_USERS_DB', '/var/lib/marsaprs/users.db');

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m)) $opts[$m[1]] = $m[2];
}

$dir = dirname(MARSAPRS_USERS_DB);
if (!is_dir($dir)) mkdir($dir, 0770, true);

$db = new SQLite3(MARSAPRS_USERS_DB);
$db->busyTimeout(2000);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA journal_mode = WAL');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT    UNIQUE NOT NULL,
    name       TEXT    NOT NULL DEFAULT '',
    email      TEXT    NOT NULL DEFAULT '',
    pw_hash    TEXT    NOT NULL,
    active     INTEGER NOT NULL DEFAULT 1,
    created    INTEGER NOT NULL,
    last_login INTEGER
);
CREATE TABLE IF NOT EXISTS permissions (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permission TEXT    NOT NULL,
    PRIMARY KEY (user_id, permission)
);
CREATE TABLE IF NOT EXISTS sessions (
    token    TEXT    PRIMARY KEY,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created  INTEGER NOT NULL,
    expires  INTEGER NOT NULL,
    ip       TEXT
);
CREATE TABLE IF NOT EXISTS password_resets (
    token    TEXT    PRIMARY KEY,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires  INTEGER NOT NULL
);
SQL);

// Migrate existing DB — add email column if it doesn't exist yet
@$db->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");

echo "Database ready: " . MARSAPRS_USERS_DB . "\n";

$count    = $db->querySingle('SELECT COUNT(*) FROM users');
$username = $opts['user'] ?? null;
$password = $opts['pass'] ?? null;

if (!$username && $count === 0) {
    if (!posix_isatty(STDIN)) {
        echo "NOTE: No users exist. Run interactively to create the first admin:\n";
        echo "  sudo -u www-data php /var/www/html/auth/init_db.php\n";
    } else {
        echo "No users exist. Create first superadmin:\n";
        echo "Username: ";
        $username = trim(fgets(STDIN));
        echo "Password: ";
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
}

if ($username && $password) {
    $allPerms = [
        'users.manage',
        'admin.view', 'admin.edit', 'admin.set_default', 'admin.delete_event',
        'analyzer.view', 'analyzer.admin',
        'netbird.view', 'netbird.admin',
        'wifi.admin', 'tickets.manage',
    ];
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $now  = time();
    $s    = $db->prepare('INSERT OR IGNORE INTO users (username, name, pw_hash, created) VALUES (?, ?, ?, ?)');
    $s->bindValue(1, $username, SQLITE3_TEXT);
    $s->bindValue(2, $username, SQLITE3_TEXT);
    $s->bindValue(3, $hash,     SQLITE3_TEXT);
    $s->bindValue(4, $now,      SQLITE3_INTEGER);
    $s->execute();
    if ($db->changes() > 0) {
        $uid = $db->lastInsertRowID();
        foreach ($allPerms as $p) {
            $ps = $db->prepare('INSERT OR IGNORE INTO permissions (user_id, permission) VALUES (?, ?)');
            $ps->bindValue(1, $uid, SQLITE3_INTEGER);
            $ps->bindValue(2, $p,   SQLITE3_TEXT);
            $ps->execute();
        }
        echo "Created superadmin: $username\n";
    } else {
        echo "User '$username' already exists — skipped.\n";
    }
}

$db->close();
echo "Done.\n";
