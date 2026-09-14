<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/online-order.php') ?: '';

foreach ([
    'online_order_ready($pdo)',
    'customer_account_ready($pdo)',
    'Online ordering is temporarily unavailable',
    "error_log('Online ordering bootstrap failed:",
    'http_response_code(503)',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Online-order runtime gate missing: ' . $needle);
    }
}

$readyPosition = strpos($source, 'online_order_ready($pdo)');
$locationsPosition = strpos($source, 'online_order_locations($pdo');
if ($readyPosition === false || $locationsPosition === false || $readyPosition > $locationsPosition) {
    throw new RuntimeException('Online-order readiness must be checked before location queries.');
}

if (!str_contains($source, 'public_site_context($pdo)')) {
    throw new RuntimeException('Public-site context must be protected by a runtime guard.');
}
if (!str_contains($source, 'public_site_fallback_context()')) {
    throw new RuntimeException('Online-order page must retain a safe public-site fallback context.');
}
if (!str_contains($source, 'Run Gelato Upgrade.')) {
    throw new RuntimeException('Production logs must identify a missing schema upgrade.');
}

echo "PASS: online-order runtime readiness gate prevents raw production 500s.\n";
