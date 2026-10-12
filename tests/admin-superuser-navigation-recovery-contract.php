<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/admin-shell-core.php';
function assert_recovery(bool $ok,string $label): void { if (!$ok) {fwrite(STDERR,"FAIL: {$label}\n");exit(1);} }
$owner=['id'=>1,'organization_id'=>1,'role_slug'=>'super_admin','is_system_role'=>1,'is_owner_role'=>1,'permissions'=>['*']];
$manager=['id'=>2,'organization_id'=>1,'role_slug'=>'manager','is_system_role'=>1,'is_owner_role'=>0,'permissions'=>['floorplans.view','equipment.view','glasses.view']];
$outsider=['id'=>3,'organization_id'=>1,'role_slug'=>'manager','is_system_role'=>1,'is_owner_role'=>0,'permissions'=>[]];
assert_recovery(app_role_has_owner_authority(['role_slug'=>'super_admin','is_system_role'=>1,'is_owner_role'=>0]),'Canonical Super Admin recovery');
assert_recovery(!app_role_has_owner_authority(['role_slug'=>'super_admin','is_system_role'=>0,'is_owner_role'=>0]),'Custom role spoof rejected');
assert_recovery(!app_role_has_owner_authority(['role_slug'=>'manager','is_system_role'=>1,'is_owner_role'=>0]),'Manager not escalated');
assert_recovery(app_role_has_owner_authority(['role_slug'=>'manager','is_owner_role'=>1]),'Real owner access retained');
$urls=static function(array $user): array {
    $out=[];foreach(admin_shell_filtered_navigation($user) as $group){foreach($group['items'] as $item){$out[]=$item['href'];}}return $out;
};
$ownerUrls=$urls($owner);
foreach(['floor-planner-v2.php','pos.php','kds.php','glasses-vision-lab.php','glasses-vision-operations.php','equipment.php'] as $route){
    assert_recovery(in_array($route,$ownerUrls,true),'Owner nav missing '.$route);
    assert_recovery(is_file(__DIR__.'/../'.$route),'Missing linked page '.$route);
}
assert_recovery(in_array('floor-planner-v2.php',$urls($manager),true),'Floor viewer nav');
assert_recovery(in_array('glasses-vision-lab.php',$urls($manager),true),'Vision viewer nav');
assert_recovery(!in_array('pos.php',$urls($manager),true),'Non-POS manager restricted');
assert_recovery(!in_array('floor-planner-v2.php',$urls($outsider),true),'Unprivileged floor nav restricted');
assert_recovery(!in_array('glasses-vision-lab.php',$urls(array_merge($manager,['permissions'=>['glasses.manage']])),true),'Manage-only glasses role restricted');
assert_recovery(!admin_route_access_allowed($manager,'pos.php'),'POS route permission enforced');
assert_recovery(!admin_route_access_allowed($outsider,'glasses-vision-lab.php'),'Vision route permission enforced');
assert_recovery(in_array('Floor Planner 2.0',array_column(admin_modules($owner),'name'),true),'Owner Admin card missing floor planner');
assert_recovery(!in_array('Floor Planner 2.0',array_column(admin_modules($outsider),'name'),true),'Unprivileged Admin card restricted');
$admin=file_get_contents(__DIR__.'/../admin.php');
assert_recovery(str_contains($admin,'$allNavigation=admin_shell_filtered_navigation($user);'),'Server navigation missing');
assert_recovery(str_contains($admin,'All available tools'),'No-JS link fallback missing');
$bootstrap=file_get_contents(__DIR__.'/../includes/bootstrap.php');
assert_recovery(str_contains($bootstrap,"om.status = 'active'"),'Active membership required');
assert_recovery(str_contains($bootstrap,"r.is_system_role = 1"),'System Super Admin check required');
echo "admin-superuser-navigation-recovery=ok\n";
