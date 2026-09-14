<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/online-order.php') ?: '';
$workflow = file_get_contents($root . '/.github/workflows/online-ordering-inbox.yml') ?: '';

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

$outerTryPosition = strpos($source, "try{\n    require_once __DIR__.'/includes/public-site.php';");
$sessionPosition = strpos($source, 'app_boot_session();');
$readyPosition = strpos($source, 'online_order_ready($pdo)');
$locationsPosition = strpos($source, 'online_order_locations($pdo');
if ($outerTryPosition === false || $sessionPosition === false || $outerTryPosition > $sessionPosition) {
    throw new RuntimeException('Online-order bootstrap dependencies and session setup must be inside the top-level runtime guard.');
}
if ($readyPosition === false || $locationsPosition === false || $readyPosition > $locationsPosition) {
    throw new RuntimeException('Online-order readiness must be checked before location queries.');
}

foreach ([
    'public_site_context($pdo)',
    'public_site_fallback_context',
    'Run Gelato Upgrade.',
    'Online ordering bootstrap failed before runtime readiness:',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Online-order partial-deploy protection missing: ' . $needle);
    }
}

if (!str_contains($workflow, 'cp includes/public-site.php includes/online-order-core.php includes/customer-inbox-core.php deploy-patch/includes/')) {
    throw new RuntimeException('Deploy artifact must include includes/public-site.php with online-order.php.');
}
if (!str_contains($workflow, "test -f deploy-patch/includes/public-site.php")) {
    throw new RuntimeException('Deploy artifact must verify the public-site dependency is packaged.');
}

echo "PASS: online-order runtime readiness gate prevents raw production 500s and deploys its public-site dependency.\n";
