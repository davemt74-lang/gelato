<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/admin-route-access.php';
require_once $root.'/includes/admin-shell-core.php';

function rpe_assert(bool $ok,string $message,int $code): void
{
    if($ok) return;
    fwrite(STDERR,"FAIL {$code}: {$message}\n");
    exit($code);
}

function rpe_user(array $permissions=[],bool $owner=false): array
{
    return [
        'id'=>901,
        'organization_id'=>77,
        'membership_id'=>88,
        'is_owner_role'=>$owner?1:0,
        'permissions'=>$permissions,
        'first_name'=>'Contract',
        'last_name'=>'User',
        'display_name'=>'Contract User',
        'role_name'=>'Contract',
        'role_slug'=>'contract',
    ];
}

function rpe_nav_has(array $user,string $href): bool
{
    foreach(admin_shell_filtered_navigation($user) as $group){
        foreach($group['items']??[] as $item){
            if((string)($item['href']??'')===$href) return true;
        }
    }
    return false;
}

$catalog=admin_route_access_catalog();
$standard=app_standard_admin_shell_pages();
$missing=array_values(array_diff($standard,array_keys($catalog)));
rpe_assert($missing===[],'Canonical route policy is missing standard admin pages: '.implode(', ',$missing),2);

// Direct route visibility must match the route guard, not a broader action permission.
rpe_assert(admin_route_access_allowed(rpe_user(['purchasing.view']),'purchasing.php'),'Purchasing view permission should open Purchasing',3);
rpe_assert(!admin_route_access_allowed(rpe_user(['purchasing.manage']),'purchasing.php'),'Purchasing manage-only must not imply direct page access',4);
rpe_assert(!rpe_nav_has(rpe_user(['purchasing.manage']),'purchasing.php'),'Shell exposes Purchasing to a manage-only account',5);

rpe_assert(admin_route_access_allowed(rpe_user(['equipment.view']),'equipment.php'),'Equipment view permission should open Equipment',6);
rpe_assert(!admin_route_access_allowed(rpe_user(['equipment.edit']),'equipment.php'),'Equipment edit-only must not imply direct page access',7);
rpe_assert(!rpe_nav_has(rpe_user(['equipment.edit']),'equipment.php'),'Shell exposes Equipment to an edit-only account',8);

rpe_assert(admin_route_access_allowed(rpe_user(['recipes.view']),'recipes.php'),'Recipe view permission should open Recipes',9);
rpe_assert(!admin_route_access_allowed(rpe_user(['recipes.edit']),'recipes.php'),'Recipe edit-only must not imply direct page access',10);
rpe_assert(!rpe_nav_has(rpe_user(['recipes.edit']),'recipes.php'),'Shell exposes Recipes to an edit-only account',11);

rpe_assert(admin_route_access_allowed(rpe_user(['prep.intelligence.view']),'prep-intelligence.php'),'Prep view permission should open Prep Intelligence',12);
rpe_assert(!admin_route_access_allowed(rpe_user(['prep.intelligence.manage']),'prep-intelligence.php'),'Prep manage-only must not imply direct page access',13);

rpe_assert(admin_route_access_allowed(rpe_user(['sales.import']),'sales-import-center.php'),'Sales import permission should open Sales Import Center',14);
rpe_assert(!admin_route_access_allowed(rpe_user(['sales.import_profiles.manage']),'sales-import-center.php'),'Import-profile management alone must not open Sales Import Center',15);

rpe_assert(admin_route_access_allowed(rpe_user(['sales.costs.view']),'sales-cost-intelligence.php'),'Dedicated sales-cost view permission should open Cost Intelligence',16);
rpe_assert(rpe_nav_has(rpe_user(['sales.costs.view']),'sales-cost-intelligence.php'),'Shell hides Cost Intelligence from a dedicated sales-cost viewer',61);
rpe_assert(admin_route_access_allowed(rpe_user(['sales.view']),'sales-cost-intelligence.php'),'Sales view permission should open Cost Intelligence',17);
rpe_assert(!admin_route_access_allowed(rpe_user(['inventory.view']),'sales-cost-intelligence.php'),'Inventory view alone must not open Cost Intelligence',18);
rpe_assert(!rpe_nav_has(rpe_user(['inventory.view']),'sales-cost-intelligence.php'),'Shell exposes Cost Intelligence to inventory-only account',19);

rpe_assert(admin_route_access_allowed(rpe_user(['crm.view']),'customer-crm.php'),'CRM view permission should open Customer CRM',20);
rpe_assert(!admin_route_access_allowed(rpe_user(['crm.manage']),'customer-crm.php'),'CRM manage-only must not imply full CRM page access',21);
rpe_assert(!rpe_nav_has(rpe_user(['crm.manage']),'customer-crm.php'),'Shell exposes CRM page to manage-only account',22);

rpe_assert(admin_route_access_allowed(rpe_user(['catering.view']),'catering-operations.php'),'Catering view permission should open Catering',23);
rpe_assert(!admin_route_access_allowed(rpe_user(['catering.manage']),'catering-operations.php'),'Catering manage-only must not imply direct page access',24);

rpe_assert(admin_route_access_allowed(rpe_user(['timeclock.self']),'timeclock.php'),'Timeclock self should open Time Clock',25);
rpe_assert(admin_route_access_allowed(rpe_user(['attendance.view']),'timeclock.php'),'Attendance view should open Time Clock',26);
rpe_assert(!admin_route_access_allowed(rpe_user(['timeclock.manage']),'timeclock.php'),'Timeclock manage-only must not imply direct page access',27);
rpe_assert(!admin_route_access_allowed(rpe_user(['voice.manage']),'timeclock.php'),'Voice manage-only must not imply direct Time Clock access',28);

rpe_assert(admin_route_access_allowed(rpe_user(['wholesale.view']),'wholesale-pipeline.php'),'Wholesale view should open pipeline',29);
rpe_assert(!admin_route_access_allowed(rpe_user(['wholesale.manage']),'wholesale-pipeline.php'),'Wholesale manage-only must not imply pipeline view',30);
rpe_assert(admin_route_access_allowed(rpe_user(['wholesale.manage']),'wholesale-acquisition.php'),'Wholesale manage should open acquisition worksheet',31);
rpe_assert(!admin_route_access_allowed(rpe_user(['wholesale.view']),'wholesale-acquisition.php'),'Wholesale view must not open acquisition worksheet',32);
rpe_assert(admin_route_access_allowed(rpe_user(['wholesale.receivables.view']),'wholesale-receivables.php'),'Receivables view should open receivables',33);
rpe_assert(!admin_route_access_allowed(rpe_user(['wholesale.view']),'wholesale-receivables.php'),'Generic wholesale view must not imply receivables access',34);

rpe_assert(admin_route_access_allowed(rpe_user(['pos.use']),'online-orders-admin.php'),'POS use should satisfy Online Orders callback policy',35);
rpe_assert(!admin_route_access_allowed(rpe_user([]),'online-orders-admin.php'),'Unpermissioned account must not open Online Orders admin',36);
rpe_assert(admin_route_access_allowed(rpe_user([],true),'admin-menu-import.php'),'Owner role should open Menu Import',37);
rpe_assert(!admin_route_access_allowed(rpe_user(['*'],false),'admin-menu-import.php'),'Wildcard non-owner must not bypass strict Menu Import owner guard',38);

// Preserve legitimate composite/self-service route rules.
rpe_assert(admin_route_access_allowed(rpe_user(['tasks.view']),'operations.php'),'Tasks view should open Operations',39);
rpe_assert(admin_route_access_allowed(rpe_user(['inventory.view']),'operations.php'),'Inventory view should open Operations',40);
rpe_assert(!admin_route_access_allowed(rpe_user(['tasks.manage']),'operations.php'),'Tasks manage-only must not imply Operations page access',41);
rpe_assert(admin_route_access_allowed(rpe_user(['schedule.self']),'scheduling.php'),'Schedule self should open Scheduling',42);
rpe_assert(admin_route_access_allowed(rpe_user(['staff.manage']),'scheduling.php'),'Staff manager should open Scheduling',43);

// Specialized workstations linked from the canonical shell must also match their own route guards.
rpe_assert(admin_route_access_allowed(rpe_user(['floorplans.view']),'floor-planner-v2.php'),'Floor Planner view should open the workstation',44);
rpe_assert(rpe_nav_has(rpe_user(['floorplans.view']),'floor-planner-v2.php'),'Shell hides Floor Planner from a workstation viewer',62);
rpe_assert(!admin_route_access_allowed(rpe_user(['floorplans.edit']),'floor-planner-v2.php'),'Floor Planner edit-only must not imply workstation access',45);
rpe_assert(!rpe_nav_has(rpe_user(['floorplans.edit']),'floor-planner-v2.php'),'Shell exposes Floor Planner to an edit-only account',46);

$guards=[
    'locations-admin.php'=>['locations.manage'],
    'operations.php'=>['tasks.view','inventory.view'],
    'timeclock.php'=>['timeclock.self','timeclock.view','attendance.view','voice.self'],
    'scheduling.php'=>['schedule.view','schedule.manage','schedule.self','staff.view','staff.manage'],
    'purchasing.php'=>['purchasing.view'],
    'equipment.php'=>['equipment.view'],
    'recipes.php'=>['recipes.view'],
    'prep-intelligence.php'=>['prep.intelligence.view'],
    'catering-operations.php'=>['catering.view'],
    'catering-pipeline.php'=>['catering.view'],
    'sales-intelligence.php'=>['sales.view'],
    'sales-import-center.php'=>['sales.import'],
    'sales-cost-intelligence.php'=>['sales.costs.view','sales.view'],
    'customer-promotions.php'=>['customer_promotions.manage'],
    'customer-crm.php'=>['crm.view'],
    'public-site-settings.php'=>['public_pages.edit','settings.organization_edit'],
    'wholesale-pipeline.php'=>['wholesale.view'],
    'wholesale-accounts.php'=>['wholesale.view'],
    'wholesale-acquisition.php'=>['wholesale.manage'],
    'wholesale-commerce.php'=>['wholesale.manage'],
    'wholesale-customer-360.php'=>['wholesale.view'],
    'wholesale-demand.php'=>['wholesale.view'],
    'wholesale-fulfillment.php'=>['wholesale.view'],
    'wholesale-order-entry.php'=>['wholesale.manage'],
    'wholesale-purchasing.php'=>['wholesale.manage'],
    'wholesale-receivables.php'=>['wholesale.receivables.view'],
    'floor-planner-ops-legacy.php'=>['floorplans.view'],
];
foreach($guards as $page=>$permissions){
    $content=file_get_contents($root.'/'.$page);
    rpe_assert(is_string($content)&&$content!=='',$page.' is missing',50);
    foreach($permissions as $permission){
        rpe_assert(str_contains($content,$permission),$page.' no longer contains its expected server guard permission: '.$permission,51);
    }
}

$admin=file_get_contents($root.'/admin.php');
$online=file_get_contents($root.'/online-orders-admin.php');
$menuImport=file_get_contents($root.'/admin-menu-import.php');
rpe_assert(str_contains((string)$admin,'admin_control_allowed($user)'),'Admin control page lost its server access callback',52);
rpe_assert(str_contains((string)$online,'admin_online_orders_allowed($user)'),'Online Orders page lost its server access callback',53);
rpe_assert(str_contains((string)$menuImport,"is_owner_role")&&str_contains((string)$menuImport,'Owner access required.'),'Menu Import lost strict owner enforcement',54);

$shell=file_get_contents($root.'/includes/admin-shell-core.php');
$shellApi=file_get_contents($root.'/api/admin-shell.php');
rpe_assert(str_contains((string)$shell,"admin_route_access_rule(\$route)")&&str_contains((string)$shell,'admin_route_access_allowed($user,$route)'),'Canonical shell is not filtered by direct-route access',55);
rpe_assert(str_contains((string)$shellApi,'admin_route_access_allowed($user,$page)'),'Admin shell API does not enforce direct-route access',56);

$pos=file_get_contents($root.'/api/pos.php');
$kds=file_get_contents($root.'/api/kds.php');
rpe_assert(str_contains((string)$pos,"app_has_permission('pos.use'"),'POS API lost pos.use enforcement',57);
rpe_assert(str_contains((string)$pos,"operational_location_allowed(\$pdo,\$user,'pos.use'"),'POS API lost location-scoped permission enforcement',58);
rpe_assert(str_contains((string)$kds,"app_has_permission('kds.view'"),'KDS API lost kds.view enforcement',59);
rpe_assert(str_contains((string)$kds,"operational_location_allowed(\$pdo,\$user,'kds.view'"),'KDS API lost location-scoped permission enforcement',60);

echo "role-permission-enforcement=ok\n";
