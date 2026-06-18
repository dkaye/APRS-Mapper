<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

$configPath = __DIR__ . '/config.json';

if (!file_exists($configPath)) {
    echo json_encode([
        'attribution' => '© OpenStreetMap contributors',
        'copyright'   => '',
        'help'        => '',
        'courses'     => [],
    ]);
    exit;
}

echo file_get_contents($configPath);
