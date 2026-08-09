<?php
/** PHPUnit bootstrap: defines constants and requires shared source files for test isolation. */
define('APRS_DAEMON_INCLUDE_ONLY', true);

$repoRoot = dirname(__DIR__, 3);
// MessagingDb resolves its path from this constant when none is passed; tests always
// pass an explicit temp file, but the constant must exist for the class to load.
if (!defined('MARSAPRS_MESSAGES_DB')) define('MARSAPRS_MESSAGES_DB', sys_get_temp_dir() . '/marsaprs_test_messages.db');
require_once $repoRoot . '/map/messaging_db.php';
require_once $repoRoot . '/map/config_parse.php';
require_once $repoRoot . '/map/aprsDaemon.php';
require_once $repoRoot . '/map/admin/config_yaml.php';
require_once $repoRoot . '/server/www/netbird/yaml_lib.php';
