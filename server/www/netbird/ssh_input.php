<?php
/**
 * ssh_input.php — MARS APRS NetBird
 *
 * Receives raw keystroke data (POST body) from xterm.js and appends it to the
 * relay queue file for ssh_relay.py to consume.
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

$input = file_get_contents('php://input');
if ($input === false || $input === '') { http_response_code(204); exit; }

file_put_contents("/tmp/aprs_ssh_{$token}.q", $input, FILE_APPEND | LOCK_EX);
http_response_code(204);
