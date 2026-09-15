<?php
declare(strict_types=1);

function uas_test(bool $ok,string $message,int $code): void
{
    if($ok)return;
    fwrite(STDERR,"FAIL {$code}: {$message}\n");
    exit($code);
}

$root=dirname(__DIR__);
$shell=file_get_contents($root.'/js/universal-admin-page-shell.js');
$core=file_get_contents($root.'/includes/admin-shell-core.php');
$bootstrap=file_get_contents($root.'/includes/bootstrap.php');
$api=file_get_contents($root.'/api/admin-shell.php');
uas_test(is_string($shell)&&$shell!=='','Universal admin page shell is missing',2);
uas_test(is_string($core)&&$core!=='','Canonical admin shell core is missing',3);
uas_test(is_string($bootstrap)&&$bootstrap!=='','Application bootstrap is missing',4);
uas_test(is_string($api)&&$api!=='','Admin shell API is missing',5);

uas_test(str_contains($shell,"fetch(`api/admin-shell.php?page="),'Universal shell is not driven by the server shell model',6);
uas_test(!str_contains($shell,'const pageTitles ='),'Page titles are still duplicated in the browser shell',7);
uas_test(!str_contains($shell,'const groups ='),'Navigation is still duplicated in the browser shell',8);
uas_test(!str_contains($shell,'function currentAccount'),'Account identity is still being inferred from localStorage',9);
uas_test(str_contains($shell,"shell.type !== 'standard'"),'Universal shell does not respect page shell classification',10);
uas_test(str_contains($shell,'function movePageActions'),'Page-local actions are not preserved inside the canonical header',11);

foreach(['Team & Hiring','Website & Locations','Operations','Sales & Events','AI & Knowledge'] as $label){
    uas_test(str_contains($core,$label),'Canonical shell category missing: '.$label,12);
}
foreach(['pos.use','kds.view','schedule.view','timeclock.self','purchasing.view','equipment.view','recipes.view','prep.intelligence.view','sales.import','catering.view','wholesale.view'] as $permission){
    uas_test(str_contains($core,$permission),'Canonical shell permission mapping missing: '.$permission,13);
}
uas_test(str_contains($core,"'floor-planner-v2.php'=>['Floor Planner 2.0','Floor planning canvas','workstation']"),'Floor Planner 2.0 is not classified as a specialized workstation',14);
uas_test(str_contains($core,"'pos.php'=>['POS','Point of sale','workstation']"),'POS is not classified as a specialized workstation',15);
uas_test(str_contains($core,"'customer-account.php'=>['Customer Account','Customer account','customer']"),'Customer account is not classified as customer UI',16);
uas_test(str_contains($core,'admin_shell_filtered_navigation($user)'),'Canonical shell does not filter navigation by current permissions',17);
uas_test(str_contains($api,'admin_shell_page_context($user,$page)'),'Shell API is not using the canonical shell model',18);

uas_test(str_contains($bootstrap,'function app_standard_admin_shell_pages()'),'Standard admin shell allowlist is not centralized in bootstrap',19);
uas_test(str_contains($bootstrap,'function app_boot_admin_shell_injection()'),'Standard admin pages are not auto-bound to the canonical shell',20);
uas_test(str_contains($bootstrap,"js/universal-admin-page-shell.js?v=20260915-shell2"),'Canonical shell asset is not centrally cache-busted',21);
uas_test(str_contains($bootstrap,"str_contains($html, 'js/universal-admin-page-shell.js')"),'Existing page-level shell includes are not deduplicated',22);

$standardPages=[
    'admin.php','admin-menu-import.php','locations-admin.php','operations.php','catering-operations.php','catering-pipeline.php',
    'sales-intelligence.php','sales-import-center.php','sales-cost-intelligence.php','customer-promotions.php','customer-crm.php',
    'online-orders-admin.php','recipes.php','scheduling.php','timeclock.php','public-site-settings.php','prep-intelligence.php',
    'equipment.php','equipment-detail.php','purchasing.php','wholesale-pipeline.php','wholesale-accounts.php','wholesale-acquisition.php',
    'wholesale-commerce.php','wholesale-customer-360.php','wholesale-demand.php','wholesale-fulfillment.php','wholesale-order-entry.php',
    'wholesale-purchasing.php','wholesale-receivables.php',
];
foreach($standardPages as $index=>$target){
    uas_test(is_file($root.'/'.$target),$target.' is missing',30+$index);
    uas_test(str_contains($bootstrap,"'{$target}'"),$target.' is not registered for canonical shell auto-binding',70+$index);
    uas_test(str_contains($core,"'{$target}'"),$target.' is not classified by the canonical shell model',110+$index);
}

$specialPages=['workspace.php','pos.php','kds.php','kds-dashboard.php','floor-planner.php','floor-planner-v2.php','table-service.php','host-stand.php','agent-canvas.php','customer-account.php','online-order.php','wholesale-portal.php'];
foreach($specialPages as $index=>$target){
    uas_test(!str_contains($bootstrap,"'{$target}'"),$target.' must not be auto-bound to the standard admin shell',160+$index);
}

echo "universal-admin-page-shell=ok\n";
