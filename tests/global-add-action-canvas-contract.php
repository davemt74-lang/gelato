<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $user): bool
{
    return in_array('*',$user['permissions']??[],true) || in_array($permission,$user['permissions']??[],true);
}

require __DIR__.'/../includes/admin-add-actions.php';

function gac_assert(bool $ok,string $message): void
{
    if(!$ok) throw new RuntimeException($message);
}

function gac_labels(array $actions): array
{
    return array_values(array_map(static fn(array $action): string=>(string)$action['label'],$actions));
}

$owner=['is_owner_role'=>1,'permissions'=>[]];
$ownerActions=admin_add_filtered_actions($owner);
$ownerLabels=gac_labels($ownerActions);
foreach(['Add User','Add Recipe','Add Location','Add Schedule','Add Wholesale','Add Catering','Add Invoice','Add Service','Add Receipt','Add Package','Add Discount','Add Food','Add Drink','Add Equipment'] as $label){
    gac_assert(in_array($label,$ownerLabels,true),$label.' must be available in the owner add canvas.');
}

$package=array_values(array_filter($ownerActions,static fn(array $action): bool=>(string)$action['id']==='package'));
gac_assert(count($package)===1,'Package must exist as one live builder action.');
gac_assert(($package[0]['href']??'')==='packages-admin.php?action=add','Add Package must open the live package builder.');
gac_assert(($package[0]['status']??'')!=='planned','Add Package must no longer be marked planned.');

$discount=array_values(array_filter($ownerActions,static fn(array $action): bool=>(string)$action['id']==='discount'));
gac_assert(count($discount)===1,'Discount must exist as one live Add action.');
gac_assert(($discount[0]['href']??'')==='discounts-admin.php?action=add','Add Discount must open the typed discount manager.');
gac_assert(($discount[0]['status']??'')!=='planned','Add Discount must be a live action.');

$food=array_values(array_filter($ownerActions,static fn(array $action): bool=>(string)$action['id']==='food'));
gac_assert(count($food)===1,'Food must exist as one live builder action.');
gac_assert(($food[0]['href']??'')==='menu-manager.php?action=add-food','Add Food must open the Menu Manager Food builder.');
gac_assert(($food[0]['status']??'')!=='planned','Add Food must be live.');

$drink=array_values(array_filter($ownerActions,static fn(array $action): bool=>(string)$action['id']==='drink'));
gac_assert(count($drink)===1,'Drink must remain visible for the next builder phase.');
gac_assert(($drink[0]['status']??'')==='planned','Add Drink must remain marked planned.');
gac_assert(($drink[0]['href']??'')==='','Add Drink must not navigate to an unfinished route.');

$operator=['is_owner_role'=>0,'permissions'=>[
    'users.edit','schedule.manage','recipes.edit','locations.manage','tasks.manage','catering.manage','wholesale.manage',
    'crm.manage','customer_promotions.manage','equipment.edit','equipment.service','purchasing.manage','receiving.manage','vendors.manage',
]];
$operatorActions=admin_add_filtered_actions($operator);
$operatorLabels=gac_labels($operatorActions);
gac_assert(in_array('Add Equipment',$operatorLabels,true),'Equipment editors must receive Add Equipment.');
gac_assert(in_array('Add Recipe',$operatorLabels,true),'Recipe editors must receive Add Recipe.');
gac_assert(in_array('Add Receipt',$operatorLabels,true),'Purchasing managers must receive Add Receipt.');
gac_assert(!in_array('Add Package',$operatorLabels,true),'Accounts without packages.manage must not receive Add Package.');
gac_assert(!in_array('Add Discount',$operatorLabels,true),'Accounts without discounts.manage must not receive Add Discount.');
gac_assert(!in_array('Add Food',$operatorLabels,true),'Accounts without menu.manage must not receive Add Food.');
gac_assert(!in_array('Add Drink',$operatorLabels,true),'Owner-only future Drink builder must not leak to non-owner accounts.');

$packageManager=['is_owner_role'=>0,'permissions'=>['packages.view','packages.manage']];
$packageManagerActions=admin_add_filtered_actions($packageManager);
$packageManagerLabels=gac_labels($packageManagerActions);
gac_assert(in_array('Add Package',$packageManagerLabels,true),'Package managers must receive the live Add Package action.');
$packageAction=array_values(array_filter($packageManagerActions,static fn(array $action):bool=>(string)$action['id']==='package'))[0]??[];
gac_assert(($packageAction['href']??'')==='packages-admin.php?action=add','Package managers must navigate to the package builder.');
gac_assert(!in_array('Add Discount',$packageManagerLabels,true),'Package managers without discounts.manage must not receive Add Discount.');
gac_assert(!in_array('Add Food',$packageManagerLabels,true),'Package managers without menu.manage must not receive Add Food.');

$discountManager=['is_owner_role'=>0,'permissions'=>['discounts.view','discounts.manage']];
$discountManagerActions=admin_add_filtered_actions($discountManager);
$discountManagerLabels=gac_labels($discountManagerActions);
gac_assert(in_array('Add Discount',$discountManagerLabels,true),'Discount managers must receive Add Discount.');
$discountAction=array_values(array_filter($discountManagerActions,static fn(array $action):bool=>(string)$action['id']==='discount'))[0]??[];
gac_assert(($discountAction['href']??'')==='discounts-admin.php?action=add','Discount managers must navigate to the typed discount manager.');
gac_assert(!in_array('Add Package',$discountManagerLabels,true),'Discount managers without packages.manage must not receive Add Package.');

$menuManager=['is_owner_role'=>0,'permissions'=>['menu.view','menu.manage']];
$menuManagerActions=admin_add_filtered_actions($menuManager);
$menuManagerLabels=gac_labels($menuManagerActions);
gac_assert(in_array('Add Food',$menuManagerLabels,true),'Menu managers must receive Add Food.');
$foodAction=array_values(array_filter($menuManagerActions,static fn(array $action):bool=>(string)$action['id']==='food'))[0]??[];
gac_assert(($foodAction['href']??'')==='menu-manager.php?action=add-food','Menu managers must navigate to the Food builder.');
gac_assert(!in_array('Add Drink',$menuManagerLabels,true),'Future Add Drink remains owner-visible until built.');

$viewer=['is_owner_role'=>0,'permissions'=>['equipment.view','recipes.view','crm.view','packages.view','discounts.view','menu.view']];
$viewerLabels=gac_labels(admin_add_filtered_actions($viewer));
gac_assert(!in_array('Add Equipment',$viewerLabels,true),'View-only equipment access must not expose Add Equipment.');
gac_assert(!in_array('Add Recipe',$viewerLabels,true),'View-only recipe access must not expose Add Recipe.');
gac_assert(!in_array('Add Customer',$viewerLabels,true),'View-only CRM access must not expose Add Customer.');
gac_assert(!in_array('Add Package',$viewerLabels,true),'View-only package access must not expose Add Package.');
gac_assert(!in_array('Add Discount',$viewerLabels,true),'View-only discount access must not expose Add Discount.');
gac_assert(!in_array('Add Food',$viewerLabels,true),'View-only menu access must not expose Add Food.');

echo "global-add-action-canvas=ok\n";
