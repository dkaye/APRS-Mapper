<?php
/** PHPUnit bootstrap: defines constants and requires shared source files for test isolation. */
define('APRS_DAEMON_INCLUDE_ONLY', true);

$repoRoot = dirname(__DIR__, 3);
// MessagingDb resolves its path from this constant when none is passed; tests always
// pass an explicit temp file, but the constant must exist for the class to load.
if (!defined('MARSAPRS_MESSAGES_DB')) define('MARSAPRS_MESSAGES_DB', sys_get_temp_dir() . '/marsaprs_test_messages.db');
// Radio audio lives in the web root in production. Point it at a temp tree here, or a
// test that stores or prunes a clip would reach for /var/www/html — silently doing
// nothing on a dev Mac, and deleting real files on the server.
if (!defined('MARSAPRS_AUDIO_ROOT')) define('MARSAPRS_AUDIO_ROOT', sys_get_temp_dir() . '/marsaprs_test_radio');
// Same reason: the ID-name list lives in /var/lib/marsaprs in production. Every test
// passes an explicit path, but messaging_db.php requires spoken_ids.php at load and the
// constant is evaluated then.
if (!defined('MARSAPRS_SPOKEN_IDS')) define('MARSAPRS_SPOKEN_IDS', sys_get_temp_dir() . '/marsaprs_test_spoken_ids.json');
require_once $repoRoot . '/map/messaging_db.php';
// Only defines functions at load; the ?messaging= dispatch happens in index.php.
require_once $repoRoot . '/map/messaging.php';
require_once $repoRoot . '/map/config_parse.php';
require_once $repoRoot . '/map/aprsDaemon.php';
require_once $repoRoot . '/map/admin/config_yaml.php';
require_once $repoRoot . '/server/www/netbird/yaml_lib.php';
