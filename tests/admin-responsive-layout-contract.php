<?php
declare(strict_types=1);

// Shared admin UI responsive regression: independent of a running database.
$root = dirname(__DIR__);
function assertAdminResponsive(bool $condition, string $reason): void {
    if (!$condition) {
        fwrite(STDERR, "Admin responsive layout: {$reason}\n");
        exit(1);
    }
}
$base = file_get_contents($root . '/assets/css/admin-control.css');
$layout = file_get_contents($root . '/assets/css/admin-responsive-layout.css');
$script = file_get_contents($root . '/js/universal-admin-page-shell.js');
$bootstrap = file_get_contents($root . '/includes/bootstrap.php');
assertAdminResponsive(is_string($base) && is_string($layout) && is_string($script) && is_string($bootstrap), 'assets exist');
assertAdminResponsive(str_contains($base, 'width:min(1460px,calc(100% - 40px))'), 'admin width must respect sidebar-adjusted content box');
assertAdminResponsive(!str_contains($base, 'calc(100vw - 36px)'), 'viewport-width overshoot regression');
assertAdminResponsive(str_contains($base, '.admin-control .admin-top-actions{display:flex;flex:1 1 100%;flex-wrap:wrap'), 'narrow fallback headers must wrap links');
assertAdminResponsive(str_contains($base, 'width:min(1460px,calc(100% - 24px))'), 'mobile side gutters valid');
assertAdminResponsive(str_contains($layout, '.admin-control .admin-shell') && str_contains($layout, 'margin-inline:auto'), 'shared shell gutters');
assertAdminResponsive(str_contains($layout, '.vl-grid>') && str_contains($layout, 'min-width:0'), 'Vision Lab grid children can shrink');
assertAdminResponsive(str_contains($layout, '.vl-table table') && str_contains($layout, 'table-layout:fixed'), 'Vision Lab desktop tables wrap inside column');
assertAdminResponsive(str_contains($layout, '(.gvo-table-wrap,.gvm-table-wrap,.gvp-table-wrap) table{width:100%;min-width:0!important;table-layout:fixed}'), 'other Vision tables do not overflow desktop panels');
assertAdminResponsive(str_contains($layout, '@media(max-width:1260px)') && str_contains($layout, '.vl-hero,.vl-grid'), 'sidebar-aware Vision Lab breakpoint');
assertAdminResponsive(str_contains($layout, '@media(max-width:780px)') && str_contains($layout, 'content:attr(data-label)'), 'mobile cards have visible field names');
foreach (['.gvo-table-wrap','.gvm-table-wrap','.gvp-table-wrap'] as $table) {
    assertAdminResponsive(str_contains($layout, $table), "{$table} is responsive");
}
assertAdminResponsive(str_contains($script, 'admin-responsive-layout.css?v=20261012-ui1'), 'canonical shell loads the shared stylesheet');
assertAdminResponsive(str_contains($script, 'installResponsiveTableLabels()'), 'dynamic table labels installed');
assertAdminResponsive(str_contains($script, 'new MutationObserver('), 'API-driven rows receive labels');
assertAdminResponsive(str_contains($bootstrap, 'js/universal-admin-page-shell.js?v=20261023-manual-chat1'), 'core pages get a cache-busted shell');
foreach (['glasses-vision-lab.php','glasses-vision-models.php','glasses-vision-operations.php','glasses-vision-profiles.php'] as $page) {
    $source = file_get_contents($root . '/' . $page);
    assertAdminResponsive(str_contains($source, 'js/universal-admin-page-shell.js?v=20261012-ui1'), "{$page} loads corrected shell");
    assertAdminResponsive(str_contains($source, 'assets/css/admin-control.css?v=20261012-ui1'), "{$page} refreshes mobile-safe header CSS");
}
echo "admin-responsive-layout=ok\n";
