<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/online-order.php') ?: '';
$core = file_get_contents($root . '/includes/online-order-core.php') ?: '';
$orderJs = file_get_contents($root . '/assets/js/online-order.js') ?: '';
$signup = file_get_contents($root . '/customer-signup.php') ?: '';
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

foreach ([
    'customer_account_current($pdo,$organizationId)',
    'Guest order',
    'Account at checkout',
    'Continue to checkout',
    'Pay at pickup <em>Active</em>',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Guest-before-account ordering flow missing: ' . $needle);
    }
}
if (str_contains($source, 'customer_account_require($pdo,$organizationId)')) {
    throw new RuntimeException('Public online ordering must not require customer login before the menu and cart are shown.');
}
foreach ([
    'sessionStorage.getItem(key)',
    'sessionStorage.setItem(key',
    'authenticated',
    'window.location.assign(signupUrl)',
] as $needle) {
    if (!str_contains($orderJs, $needle)) {
        throw new RuntimeException('Guest cart handoff contract missing: ' . $needle);
    }
}
foreach ([
    '$return=customer_account_safe_return',
    'name="return"',
    'app_redirect($return)',
    'customer-login.php?return=',
] as $needle) {
    if (!str_contains($signup, $needle)) {
        throw new RuntimeException('Signup checkout-return contract missing: ' . $needle);
    }
}

foreach ([
    'online_order_missing_requirements',
    "'online_orders'",
    "'pos_settings'",
    "'pos_checks'",
    "'pos_check_items'",
    "'pos_tenders'",
    "'sales_integrations'",
    "'public_slug'",
    "'pickup_enabled'",
    "'online_ordering_enabled'",
    "'pickup_lead_minutes'",
] as $needle) {
    if (!str_contains($core, $needle)) {
        throw new RuntimeException('Online-order direct readiness contract missing: ' . $needle);
    }
}
if (str_contains($core, 'pos_ready($pdo)')) {
    throw new RuntimeException('Online ordering must not be blocked by unrelated sales-intelligence readiness through pos_ready().');
}

foreach ([
    'cp includes/*.php deploy-patch/includes/',
    'customer-login.php customer-signup.php customer-promotions.php upgrade.php',
    'database/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql',
    'deploy-patch/database/20260914_zzzzzzz_sales_demand_intelligence.sql',
    'deploy-patch/database/20260916_native_pos.sql',
    'deploy-patch/database/20260917_customer_crm.sql',
    'deploy-patch/database/20261001_system_user_types_pos_order_types.sql',
    'deploy-patch/database/20261002_customer_accounts_online_ordering_foundation.sql',
    'deploy-patch/database/20261003_location_foundation.sql',
    'deploy-patch/database/20261004_online_ordering_customer_inbox.sql',
    'stonefellows-online-ordering-recovery-deploy.zip',
] as $needle) {
    if (!str_contains($workflow, $needle)) {
        throw new RuntimeException('Dependency-complete recovery artifact contract missing: ' . $needle);
    }
}

echo "PASS: online ordering supports guest cart building, account-at-checkout handoff, direct readiness, and dependency-complete recovery deployment.\n";
