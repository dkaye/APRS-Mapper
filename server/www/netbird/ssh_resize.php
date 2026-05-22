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
$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(400); exit; }

$cols = (int)($_GET['cols'] ?? 0);
$rows = (int)($_GET['rows'] ?? 0);
if ($cols < 1 || $rows < 1) { http_response_code(400); exit; }

file_put_contents("/tmp/aprs_ssh_{$token}.resize", "{$cols},{$rows}");
http_response_code(204);
