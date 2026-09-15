<?php
declare(strict_types=1);

function admin_add_is_owner(array $user): bool
{
    return (int)($user['is_owner_role']??0)===1 || in_array('*',$user['permissions']??[],true);
}

function admin_add_has_any(array $user,array $permissions): bool
{
    if(admin_add_is_owner($user)) return true;
    foreach($permissions as $permission){
        if(app_has_permission((string)$permission,$user)) return true;
    }
    return false;
}

function admin_add_action_catalog(): array
{
    return [
        ['id'=>'user','group'=>'People','icon'=>'＋','label'=>'Add User','description'=>'Create an employee or staff account and assign access.','href'=>'workspace.php#users','permissions'=>['users.edit']],
        ['id'=>'schedule','group'=>'People','icon'=>'◫','label'=>'Add Schedule','description'=>'Build or manage staff shifts, availability and coverage.','href'=>'scheduling.php?action=add','permissions'=>['schedule.manage','staff.manage']],
        ['id'=>'job','group'=>'People','icon'=>'▣','label'=>'Add Job','description'=>'Create a hiring position for the public careers workflow.','href'=>'workspace.php#jobs','permissions'=>['jobs.edit']],

        ['id'=>'recipe','group'=>'Menu & Products','icon'=>'▤','label'=>'Add Recipe','description'=>'Create a recipe, costing record and production instructions.','href'=>'recipes.php?action=add','permissions'=>['recipes.edit','recipes.ai_map']],
        ['id'=>'food','group'=>'Menu & Products','icon'=>'◉','label'=>'Add Food','description'=>'Create a food item through the upcoming guided food builder.','href'=>'','ownerOnly'=>true,'status'=>'planned','badge'=>'Next phase'],
        ['id'=>'drink','group'=>'Menu & Products','icon'=>'◌','label'=>'Add Drink','description'=>'Create a drink item through the upcoming guided drink builder.','href'=>'','ownerOnly'=>true,'status'=>'planned','badge'=>'Next phase'],
        ['id'=>'package','group'=>'Menu & Products','icon'=>'◇','label'=>'Add Package','description'=>'Build a pickup-only bundled package from live menu items with tracked package pricing.','href'=>'packages-admin.php?action=add','permissions'=>['packages.manage']],

        ['id'=>'location','group'=>'Operations','icon'=>'⌖','label'=>'Add Location','description'=>'Create a restaurant location with service and ordering settings.','href'=>'locations-admin.php?action=add','permissions'=>['locations.manage']],
        ['id'=>'task','group'=>'Operations','icon'=>'✓','label'=>'Add Task','description'=>'Create operational work for the restaurant team.','href'=>'operations.php?action=task','permissions'=>['tasks.manage']],
        ['id'=>'catering','group'=>'Operations','icon'=>'◈','label'=>'Add Catering','description'=>'Create a catering lead, quote or event workflow.','href'=>'catering-pipeline.php?action=add','permissions'=>['catering.manage']],
        ['id'=>'wholesale','group'=>'Operations','icon'=>'◇','label'=>'Add Wholesale','description'=>'Create a wholesale order or start a wholesale account workflow.','href'=>'wholesale-order-entry.php?action=add','permissions'=>['wholesale.manage']],

        ['id'=>'customer','group'=>'Sales & Customers','icon'=>'◎','label'=>'Add Customer','description'=>'Open the CRM to create or manage a customer relationship.','href'=>'customer-crm.php?action=add','permissions'=>['crm.manage']],
        ['id'=>'promotion','group'=>'Sales & Customers','icon'=>'✦','label'=>'Add Promotion','description'=>'Create a customer promotion or account Inbox campaign.','href'=>'customer-promotions.php?action=add','permissions'=>['customer_promotions.manage']],
        ['id'=>'invoice','group'=>'Sales & Customers','icon'=>'$','label'=>'Add Invoice','description'=>'Create or manage a wholesale invoice and receivable.','href'=>'wholesale-receivables.php?action=add','permissions'=>['wholesale.receivables','wholesale.manage']],

        ['id'=>'equipment','group'=>'Purchasing & Assets','icon'=>'⚙','label'=>'Add Equipment','description'=>'Add a restaurant asset, appliance or serviceable equipment record.','href'=>'equipment.php?action=add','permissions'=>['equipment.edit','equipment.service']],
        ['id'=>'service','group'=>'Purchasing & Assets','icon'=>'⌁','label'=>'Add Service','description'=>'Record equipment service, maintenance or a repair event.','href'=>'equipment.php?action=service','permissions'=>['equipment.service','equipment.edit']],
        ['id'=>'receipt','group'=>'Purchasing & Assets','icon'=>'▧','label'=>'Add Receipt','description'=>'Record a purchasing or receiving receipt in the purchasing workflow.','href'=>'purchasing.php?action=receipt','permissions'=>['purchasing.manage','receiving.manage']],
        ['id'=>'purchase-order','group'=>'Purchasing & Assets','icon'=>'▣','label'=>'Add Purchase Order','description'=>'Create a purchase order for a restaurant vendor.','href'=>'purchasing.php?action=purchase-order','permissions'=>['purchasing.manage']],
        ['id'=>'vendor','group'=>'Purchasing & Assets','icon'=>'◫','label'=>'Add Vendor','description'=>'Create or manage a restaurant vendor relationship.','href'=>'purchasing.php?action=vendor','permissions'=>['vendors.manage','purchasing.manage']],
    ];
}

function admin_add_action_allowed(array $user,array $action): bool
{
    if(!empty($action['ownerOnly'])) return admin_add_is_owner($user);
    $permissions=$action['permissions']??[];
    return $permissions!==[] && admin_add_has_any($user,$permissions);
}

function admin_add_filtered_actions(array $user): array
{
    $out=[];
    foreach(admin_add_action_catalog() as $action){
        if(!admin_add_action_allowed($user,$action)) continue;
        unset($action['permissions'],$action['ownerOnly']);
        $out[]=$action;
    }
    return $out;
}
