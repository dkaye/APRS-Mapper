<?php
/** PHPUnit bootstrap: defines constants and requires shared source files for test isolation. */
define('APRS_DAEMON_INCLUDE_ONLY', true);

$repoRoot = dirname(__DIR__, 3);
require_once $repoRoot . '/map/config_parse.php';
require_once $repoRoot . '/map/aprsDaemon.php';
require_once $repoRoot . '/map/admin/config_yaml.php';
require_once $repoRoot . '/server/www/netbird/yaml_lib.php';
