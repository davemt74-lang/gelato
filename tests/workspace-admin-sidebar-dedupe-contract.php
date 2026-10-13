<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-shell-core.php';
function sidebarCheck(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$root = dirname(__DIR__);
$owner = ['id'=>1,'organization_id'=>1,'role_slug'=>'super_admin','is_system_role'=>1,'is_owner_role'=>1,'permissions'=>['*']];
$manager = ['id'=>2,'organization_id'=>1,'role_slug'=>'manager','is_system_role'=>1,'is_owner_role'=>0,'permissions'=>['glasses.view']];
foreach ([$owner,$manager] as $person) {
    $seen = [];
    $nav = admin_shell_filtered_navigation($person);
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $href = strtolower((string)$item['href']);
            sidebarCheck(!isset($seen[$href]), 'duplicate authorized sidebar destination '.$href);
            $seen[$href] = true;
        }
    }
    sidebarCheck(isset($seen['glasses-vision-lab.php']), 'allowed Vision Lab not present');
    if ($person['id'] === 2) {
        sidebarCheck(!isset($seen['customer-crm.php']), 'non-CRM user received CRM sidebar link');
        sidebarCheck(!isset($seen['catering-operations.php']), 'non-catering user received catering link');
    }
}
$workspace = (string)file_get_contents($root.'/workspace.php');
$sync = (string)file_get_contents($root.'/js/workspace-shell-sync.js');
$accordion = (string)file_get_contents($root.'/js/admin-shell-consolidation.js');
$theme = (string)file_get_contents($root.'/css/admin-tech-theme.css');
$loader = (string)file_get_contents($root.'/js/floor-planner-module.js');
sidebarCheck(!str_contains($workspace, '$sidebarRoutes'), 'workspace injects duplicate nav routes into native sidebar');
sidebarCheck(!str_contains($workspace, 'gelato-sidebar-route-heading{'), 'old disabled-looking sidebar CSS still injected');
sidebarCheck(str_contains($workspace, 'gelato-server-navigation'), 'server-rendered fallback must remain available');
sidebarCheck(str_contains($sync, 'function syncAdminSidebar(shell)'), 'canonical workspace reconciliation missing');
sidebarCheck(str_contains($sync, 'syncAdminSidebar(shell);'), 'workspace does not run sidebar reconciliation');
sidebarCheck(str_contains($sync, '(shell.navigation || [])'), 'nav must use permission-filtered server catalog');
sidebarCheck(str_contains($sync, 'destinations.has(destination)'), 'nav reconciliation is not deduplicated');
sidebarCheck(str_contains($sync, "spaButtons.has(destination)"), 'native SPA navigation is not reused');
sidebarCheck(str_contains($sync, "'.nav-btn:not([data-nav])'"), 'late-added legacy module buttons not cleaned');
sidebarCheck(str_contains($sync, 'new MutationObserver('), 'late module injections not reconciled');
sidebarCheck(str_contains($accordion, "{id: 'vision', label: 'Vision & AR Glasses'}"), 'Vision sidebar category absent');
sidebarCheck(str_contains($theme, 'gelato-canonical-nav-link') && str_contains($theme, 'opacity:1!important'), 'canonical links remain faded or inaccessible');
sidebarCheck(str_contains($workspace, 'js/workspace-shell-sync.js?v=20261013-nav1'), 'new sidebar script cache version missing');
sidebarCheck(str_contains($workspace, 'css/admin-tech-theme.css?v=20261013-nav1'), 'new admin theme cache version missing');
sidebarCheck(str_contains($loader, 'admin-shell-consolidation.js?v=20261013-nav1'), 'new accordion cache version missing');
echo "workspace-admin-sidebar-dedupe=ok\n";
