<?php
/**
 * ssh_resize.php — MARS APRS NetBird
 *
 * Receives terminal resize dimensions from the browser and writes them to the
 * relay resize file for ssh_relay.py to consume.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye, K6DRK <doug@rds.com>
 */
require_once '/var/www/html/auth/auth.php';
// netbird.admin, checked here and not only on the page that links here: this
// endpoint drives a live SSH session, and a token is not an access control —
// ssh_term.php will mint one for whoever asks. 403 rather than
// require_permission's redirect, because the caller is fetch()/EventSource and
// a 302 to the login page is not something either can act on.
if (!has_permission('netbird.admin')) { http_response_code(403); exit; }

$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(400); exit; }

$cols = (int)($_GET['cols'] ?? 0);
$rows = (int)($_GET['rows'] ?? 0);
if ($cols < 1 || $rows < 1) { http_response_code(400); exit; }

file_put_contents("/tmp/aprs_ssh_{$token}.resize", "{$cols},{$rows}");
http_response_code(204);
