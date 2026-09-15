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
foreach(['Add User','Add Recipe','Add Location','Add Schedule','Add Wholesale','Add Catering','Add Invoice','Add Service','Add Receipt','Add Package','Add Food','Add Drink','Add Equipment'] as $label){
    gac_assert(in_array($label,$ownerLabels,true),$label.' must be available in the owner add canvas.');
}

$planned=array_values(array_filter($ownerActions,static fn(array $action): bool=>in_array((string)$action['id'],['package','food','drink'],true)));
gac_assert(count($planned)===3,'Package, Food and Drink must exist as planned builders.');
foreach($planned as $action){
    gac_assert(($action['status']??'')==='planned','Future builder actions must be marked planned.');
    gac_assert(($action['href']??'')==='','Future builder actions must not navigate to unfinished routes.');
}

$operator=['is_owner_role'=>0,'permissions'=>[
    'users.edit','schedule.manage','recipes.edit','locations.manage','tasks.manage','catering.manage','wholesale.manage',
    'crm.manage','customer_promotions.manage','equipment.edit','equipment.service','purchasing.manage','receiving.manage','vendors.manage',
]];
$operatorActions=admin_add_filtered_actions($operator);
$operatorLabels=gac_labels($operatorActions);
gac_assert(in_array('Add Equipment',$operatorLabels,true),'Equipment editors must receive Add Equipment.');
gac_assert(in_array('Add Recipe',$operatorLabels,true),'Recipe editors must receive Add Recipe.');
gac_assert(in_array('Add Receipt',$operatorLabels,true),'Purchasing managers must receive Add Receipt.');
gac_assert(!in_array('Add Package',$operatorLabels,true),'Owner-only future Package builder must not leak to non-owner accounts.');
gac_assert(!in_array('Add Food',$operatorLabels,true),'Owner-only future Food builder must not leak to non-owner accounts.');
gac_assert(!in_array('Add Drink',$operatorLabels,true),'Owner-only future Drink builder must not leak to non-owner accounts.');

$viewer=['is_owner_role'=>0,'permissions'=>['equipment.view','recipes.view','crm.view']];
$viewerLabels=gac_labels(admin_add_filtered_actions($viewer));
gac_assert(!in_array('Add Equipment',$viewerLabels,true),'View-only equipment access must not expose Add Equipment.');
gac_assert(!in_array('Add Recipe',$viewerLabels,true),'View-only recipe access must not expose Add Recipe.');
gac_assert(!in_array('Add Customer',$viewerLabels,true),'View-only CRM access must not expose Add Customer.');

echo "global-add-action-canvas=ok\n";
