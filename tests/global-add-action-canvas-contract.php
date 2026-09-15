<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user): bool{return in_array('*',$user['permissions']??[],true)||in_array($permission,$user['permissions']??[],true);}
require __DIR__.'/../includes/admin-add-actions.php';
function gac_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
function gac_labels(array $actions): array{return array_values(array_map(static fn(array $action):string=>(string)$action['label'],$actions));}
function gac_action(array $actions,string $id): array{return array_values(array_filter($actions,static fn(array $action):bool=>(string)$action['id']===$id))[0]??[];}

$owner=['is_owner_role'=>1,'permissions'=>[]];$ownerActions=admin_add_filtered_actions($owner);$ownerLabels=gac_labels($ownerActions);
foreach(['Add User','Add Recipe','Add Location','Add Schedule','Add Wholesale','Add Catering','Add Invoice','Add Service','Add Receipt','Add Package','Add Discount','Add Food','Add Drink','Add Equipment'] as $label)gac_assert(in_array($label,$ownerLabels,true),$label.' must be available in the owner add canvas.');
foreach([
    ['package','packages-admin.php?action=add'],['discount','discounts-admin.php?action=add'],['food','menu-manager.php?action=add-food'],['drink','menu-manager.php?action=add-drink'],
] as [$id,$href]){$action=gac_action($ownerActions,$id);gac_assert($action!==[],$id.' must exist as one live Add action.');gac_assert(($action['href']??'')===$href,$id.' must navigate to its live builder.');gac_assert(($action['status']??'')!=='planned',$id.' must not be marked planned.');}

$operator=['is_owner_role'=>0,'permissions'=>['users.edit','schedule.manage','recipes.edit','locations.manage','tasks.manage','catering.manage','wholesale.manage','crm.manage','customer_promotions.manage','equipment.edit','equipment.service','purchasing.manage','receiving.manage','vendors.manage']];$operatorLabels=gac_labels(admin_add_filtered_actions($operator));
gac_assert(in_array('Add Equipment',$operatorLabels,true),'Equipment editors must receive Add Equipment.');gac_assert(in_array('Add Recipe',$operatorLabels,true),'Recipe editors must receive Add Recipe.');gac_assert(in_array('Add Receipt',$operatorLabels,true),'Purchasing managers must receive Add Receipt.');foreach(['Add Package','Add Discount','Add Food','Add Drink'] as $label)gac_assert(!in_array($label,$operatorLabels,true),'Accounts without the required permission must not receive '.$label.'.');

$packageManager=['is_owner_role'=>0,'permissions'=>['packages.view','packages.manage']];$packageLabels=gac_labels(admin_add_filtered_actions($packageManager));gac_assert(in_array('Add Package',$packageLabels,true),'Package managers must receive Add Package.');gac_assert(!in_array('Add Food',$packageLabels,true)&&!in_array('Add Drink',$packageLabels,true),'Package-only managers must not receive menu creation actions.');
$discountManager=['is_owner_role'=>0,'permissions'=>['discounts.view','discounts.manage']];$discountLabels=gac_labels(admin_add_filtered_actions($discountManager));gac_assert(in_array('Add Discount',$discountLabels,true),'Discount managers must receive Add Discount.');

$menuManager=['is_owner_role'=>0,'permissions'=>['menu.view','menu.manage']];$menuActions=admin_add_filtered_actions($menuManager);$menuLabels=gac_labels($menuActions);gac_assert(in_array('Add Food',$menuLabels,true),'Menu managers must receive Add Food.');gac_assert(in_array('Add Drink',$menuLabels,true),'Menu managers must receive the live Add Drink action.');gac_assert((gac_action($menuActions,'food')['href']??'')==='menu-manager.php?action=add-food','Menu managers must navigate to Food builder.');gac_assert((gac_action($menuActions,'drink')['href']??'')==='menu-manager.php?action=add-drink','Menu managers must navigate to Drink builder.');

$viewer=['is_owner_role'=>0,'permissions'=>['equipment.view','recipes.view','crm.view','packages.view','discounts.view','menu.view']];$viewerLabels=gac_labels(admin_add_filtered_actions($viewer));foreach(['Add Equipment','Add Recipe','Add Customer','Add Package','Add Discount','Add Food','Add Drink'] as $label)gac_assert(!in_array($label,$viewerLabels,true),'View-only access must not expose '.$label.'.');

echo "global-add-action-canvas=ok\n";
