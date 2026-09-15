<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/admin-control-core.php';

/**
 * Canonical direct-route access for staff/admin pages.
 *
 * This catalog mirrors the server-side guard at each page entry point. It is
 * intentionally narrower than action-level API permissions: a permission that
 * can mutate one API resource does not automatically grant access to the full
 * management page.
 */
function admin_route_access_catalog(): array
{
    return [
        'admin.php'=>['callback'=>'admin_control_allowed'],
        'admin-menu-import.php'=>['ownerOnly'=>true],
        'locations-admin.php'=>['any'=>['locations.manage']],
        'operations.php'=>['any'=>['tasks.view','inventory.view']],
        'timeclock.php'=>['any'=>['timeclock.self','timeclock.view','attendance.view','voice.self']],
        'scheduling.php'=>['any'=>['schedule.view','schedule.manage','schedule.self','staff.view','staff.manage']],
        'purchasing.php'=>['any'=>['purchasing.view']],
        'equipment.php'=>['any'=>['equipment.view']],
        'equipment-detail.php'=>['any'=>['equipment.view']],
        'recipes.php'=>['any'=>['recipes.view']],
        'prep-intelligence.php'=>['any'=>['prep.intelligence.view']],
        'catering-operations.php'=>['any'=>['catering.view']],
        'catering-pipeline.php'=>['any'=>['catering.view']],
        'sales-intelligence.php'=>['any'=>['sales.view']],
        'sales-import-center.php'=>['any'=>['sales.import']],
        'sales-cost-intelligence.php'=>['any'=>['sales.costs.view','sales.view']],
        'customer-promotions.php'=>['any'=>['customer_promotions.manage']],
        'customer-crm.php'=>['any'=>['crm.view']],
        'online-orders-admin.php'=>['callback'=>'admin_online_orders_allowed'],
        'public-site-settings.php'=>['any'=>['public_pages.edit','settings.organization_edit']],
        'wholesale-pipeline.php'=>['any'=>['wholesale.view']],
        'wholesale-accounts.php'=>['any'=>['wholesale.view']],
        'wholesale-acquisition.php'=>['any'=>['wholesale.manage']],
        'wholesale-commerce.php'=>['any'=>['wholesale.manage']],
        'wholesale-customer-360.php'=>['any'=>['wholesale.view']],
        'wholesale-demand.php'=>['any'=>['wholesale.view']],
        'wholesale-fulfillment.php'=>['any'=>['wholesale.view']],
        'wholesale-order-entry.php'=>['any'=>['wholesale.manage']],
        'wholesale-purchasing.php'=>['any'=>['wholesale.manage']],
        'wholesale-receivables.php'=>['any'=>['wholesale.receivables.view']],
    ];
}

function admin_route_access_page(string $href): string
{
    $path=(string)(parse_url($href,PHP_URL_PATH)??'');
    return basename(strtolower(trim($path)));
}

function admin_route_access_rule(string $page): ?array
{
    $page=admin_route_access_page($page);
    if($page==='') return null;
    $catalog=admin_route_access_catalog();
    return $catalog[$page]??null;
}

function admin_route_access_permissions(string $page): array
{
    $rule=admin_route_access_rule($page);
    return array_values(array_unique(array_map('strval',(array)($rule['any']??[]))));
}

function admin_route_access_allowed(array $user,string $page): bool
{
    $rule=admin_route_access_rule($page);
    if($rule===null) return true;

    // Keep Menu Import aligned with its strict page guard: actual owner role,
    // not merely a wildcard permission on a non-owner account.
    if(!empty($rule['ownerOnly'])) return (int)($user['is_owner_role']??0)===1;

    $callback=(string)($rule['callback']??'');
    if($callback!==''){
        if(!is_callable($callback)) return false;
        return (bool)$callback($user);
    }

    foreach((array)($rule['any']??[]) as $permission){
        if(app_has_permission((string)$permission,$user)) return true;
    }
    return false;
}
