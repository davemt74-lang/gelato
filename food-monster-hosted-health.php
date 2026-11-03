<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/food-monster-installation.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $probe = (string)($_SERVER['HTTP_X_FOOD_MONSTER_HEALTH'] ?? '');
    $hosted = app_has_config() ? (app_config()['food_monster_hosted'] ?? []) : [];
    if ($method !== 'GET' || (($https === '' || $https === 'off') && (int)($_SERVER['SERVER_PORT'] ?? 0) !== 443)
        || !preg_match('/^[a-f0-9]{64}$/D', $probe)
        || !hash_equals((string)($hosted['health_hash'] ?? ''), hash('sha256', $probe))
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($hosted['installation_id'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    $pdo = app_pdo();
    $connection = fmi_read_connection('connection.json');
    $version = fmi_version();
    if (($connection['installation_id'] ?? null) !== $hosted['installation_id'] || $version === null || !app_owner_exists($pdo)) {
        throw new RuntimeException('Managed setup is incomplete.');
    }
    echo $hosted['installation_id'], "\n", $version;
} catch (Throwable) {
    http_response_code(503);
}
