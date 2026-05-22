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
$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(400); exit; }

$input = file_get_contents('php://input');
if ($input === false || $input === '') { http_response_code(204); exit; }

file_put_contents("/tmp/aprs_ssh_{$token}.q", $input, FILE_APPEND | LOCK_EX);
http_response_code(204);
