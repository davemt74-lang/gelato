<?php
declare(strict_types=1);

function asc_test(bool $ok, string $message, int $code): void
{
    if ($ok) {
        return;
    }
    fwrite(STDERR, "FAIL {$code}: {$message}\n");
    exit($code);
}

$root = dirname(__DIR__);
$shell = file_get_contents($root . '/js/admin-shell-consolidation.js');
$workspaceSync = file_get_contents($root . '/js/workspace-shell-sync.js');
$workspacePhp = file_get_contents($root . '/workspace.php');
$loader = file_get_contents($root . '/js/floor-planner-module.js');
$employeeHome = file_get_contents($root . '/js/employee-home-module.js');
$template = file_get_contents($root . '/index.html');

asc_test(is_string($shell) && $shell !== '', 'admin shell consolidation script is missing', 2);
asc_test(is_string($workspaceSync) && $workspaceSync !== '', 'workspace canonical shell synchronizer is missing', 3);
asc_test(is_string($workspacePhp) && $workspacePhp !== '', 'workspace PHP loader is missing', 4);
asc_test(is_string($loader) && $loader !== '', 'floor planner module loader is missing', 5);
asc_test(is_string($employeeHome) && $employeeHome !== '', 'employee home navigation module is missing', 6);
asc_test(is_string($template) && $template !== '', 'workspace template is missing', 7);

asc_test(str_contains($shell, '.sidebar .role-card{display:none!important}'), 'Active position card is not hidden', 8);
asc_test(str_contains($shell, '#page-owner .owner-agent-dialogue'), 'Owner private conversation is not hidden', 9);
asc_test(str_contains($shell, '#page-owner .owner-agent-composer'), 'Owner private composer is not hidden', 10);
asc_test(str_contains($shell, '#page-owner>.page-head{display:none!important}'), 'Owner promotional page header is not hidden', 11);
asc_test(str_contains($shell, 'body.gelato-global-agent-ready #page-workspace>.workspace>.composer'), 'Local composer is not conditionally replaced by the global Agent bar', 12);
asc_test(str_contains($shell, 'gelato-agent-ready'), 'Global Agent readiness integration is missing', 13);
asc_test(str_contains($shell, 'gelato-agent-response'), 'Global Agent responses are not mirrored into the main canvas', 14);
asc_test(str_contains($shell, 'sendToGuidedTraining'), 'Guided training forwarding is missing', 15);
asc_test(str_contains($shell, "</span>Wholesale'"), 'Wholesale navigation normalization is missing', 16);
asc_test(str_contains($shell, "</span>Catering Pipeline'"), 'Catering Pipeline navigation normalization is missing', 17);

asc_test(str_contains($loader, "addAdminNav('locations.manage', 'locations-nav'"), 'Locations is not added to the main admin navigation with the correct permission', 18);
asc_test(str_contains($loader, "'Locations', 'locations-admin.php'"), 'Locations navigation does not target the location manager', 19);
asc_test(str_contains($loader, "admin-shell-consolidation.js?v=20260915-1"), 'Admin shell cache version was not advanced', 20);

asc_test(str_contains($shell, "gelato-admin-nav-accordion-v1"), 'Accordion state does not have a durable localStorage key', 21);
foreach (['Team & Hiring', 'Website & Locations', 'Operations', 'Sales & Events', 'AI & Knowledge'] as $category) {
    asc_test(str_contains($shell, $category), 'Admin navigation category missing: ' . $category, 22);
}
asc_test(str_contains($shell, "localStorage.setItem(ADMIN_NAV_STATE_KEY"), 'Accordion open state is not persisted', 23);
asc_test(str_contains($shell, "localStorage.getItem(ADMIN_NAV_STATE_KEY"), 'Accordion open state is not restored on load', 24);
asc_test(str_contains($shell, "aria-expanded"), 'Accordion toggles do not expose expanded state', 25);
asc_test(str_contains($shell, "MutationObserver"), 'Late-added admin navigation links are not regrouped', 26);
asc_test(str_contains($shell, "header [data-employee-home-link]"), 'Shared shell does not remove legacy Employee Home header injection', 27);
asc_test(!str_contains($employeeHome, "header .actions"), 'Employee Home module can still inject into the top header', 28);
asc_test(str_contains($employeeHome, "[data-nav-group=\"admin\"]"), 'Employee Home module is not restricted to the admin sidebar', 29);

foreach ([
    'online-orders-admin.php',
    'operations.php',
    'sales-intelligence.php',
    'customer-crm.php',
    'customer-promotions.php',
    'catering-operations.php',
    'catering-pipeline.php',
    'locations-admin.php',
    'recipes.php',
] as $route) {
    asc_test(str_contains($loader, $route), 'Main admin sidebar is missing route: ' . $route, 30);
}

// The legacy workspace script can still add shortcuts before the DB-backed model arrives;
// the canonical sync owns the final visible state and removes duplicates.
asc_test(str_contains($workspaceSync, "fetch('api/admin-shell.php?page=workspace.php'"), 'Workspace header is not synchronized from the canonical shell API', 31);
asc_test(str_contains($workspaceSync, "#gelatoHeaderPos,#gelatoHeaderKds,[data-canonical-shell-shortcut]"), 'Workspace sync does not remove legacy/duplicate shortcuts before rendering canonical ones', 32);
asc_test(str_contains($workspaceSync, "a.header-link[href=\"landing.html\"]"), 'Legacy Public site header shortcut is not removed from the workspace topbar', 33);
asc_test(str_contains($workspaceSync, 'shell.headerLinks || []'), 'Workspace shortcut visibility is not driven by server-filtered shell links', 34);
asc_test(str_contains($workspacePhp, 'js/workspace-shell-sync.js?v=20260915-shell1'), 'Workspace does not load canonical shell synchronization', 35);
asc_test(str_contains($shell, "cleanProfileMenu"), 'Profile menu deduplication is missing', 36);
asc_test(str_contains($shell, "a[href=\"landing.html\"],a[href=\"apply.html\"]"), 'Legacy duplicate profile links are not removed', 37);

// Legacy hooks stay in the DOM because existing training/admin code still updates them.
asc_test(str_contains($template, 'id="sidebarRole"'), 'sidebarRole compatibility hook was removed', 38);
asc_test(str_contains($template, 'id="ownerAgentMessages"'), 'ownerAgentMessages compatibility hook was removed', 39);
asc_test(str_contains($template, 'id="ownerAgentInput"'), 'ownerAgentInput compatibility hook was removed', 40);

echo "admin-shell-consolidation=ok\n";
