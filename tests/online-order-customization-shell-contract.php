<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root): string{
    $full=$root.'/'.$path;
    if(!is_file($full))throw new RuntimeException('Missing contract file: '.$path);
    return (string)file_get_contents($full);
};

$core=$read('includes/online-order-core.php');
$integration=$read('includes/menu-order-integration.php');
$endpoint=$read('api/online-order-customizations.php');
$orderPage=$read('online-order.php');
$foodCartActive=str_contains($orderPage,'assets/js/online-order-food.js?v=20260915-1');
$orderJs=$read($foodCartActive?'assets/js/online-order-food.js':'assets/js/online-order.js');
$posEntry=$read('js/pos.js');
$posJs=$read(str_contains($posEntry,'js/pos-runtime.js')?'js/pos-runtime.js':'js/pos.js');
$kdsJs=$read('js/kds.js');
$shell=$read('js/universal-admin-page-shell.js');
$shellCore=$read('includes/admin-shell-core.php');
$bootstrap=$read('includes/bootstrap.php');

$checks=[
    'customization context uses canonical menu ingredient mapping'=>str_contains($core,'menu_item_ingredients')&&str_contains($core,'can_remove'),
    'customization context validates active menu price'=>str_contains($core,'online_order_price_item')&&str_contains($core,"s.status='active'")&&str_contains($core,'i.is_active=1'),
    'cart accepts structured customizations'=>str_contains($core,"'customizations'=>\$customizations"),
    'removals are server rendered into POS instructions'=>str_contains($core,"'REMOVE: '")&&str_contains($core,'online_order_line_instructions'),
    'substitutions are server rendered into POS instructions'=>str_contains($core,"'SUBSTITUTE: '")&&str_contains($core,' → '),
    'item note is server rendered into POS instructions'=>str_contains($core,"'ITEM NOTE: '"),
    'order note remains visible to fulfillment'=>str_contains($core,"'ORDER NOTE: '")&&str_contains($core,"'notes'=>\$note!==''?'Online pickup: '.\$note:'Online pickup order'"),
    'validated line instructions enter canonical POS'=>str_contains($integration,'pos_add_item($pdo,$organizationId')&&str_contains($integration,"\$validated[\$index]['instructions']"),
    'customization endpoint returns canonical context'=>str_contains($endpoint,'online_order_customization_context')&&str_contains($endpoint,'public_site_context($pdo)'),
    'customization endpoint augments context with paid add-ons'=>str_contains($endpoint,"'addOnGroups'")&&str_contains($endpoint,'menu_manager_customization_addons'),
    'customer cart exposes customize action'=>(str_contains($orderJs,'data-customize=')||str_contains($orderJs,"data-cart-action=\"customize\""))&&str_contains($orderJs,'Customize item'),
    'customer cart fetches server catalog'=>str_contains($orderJs,'api/online-order-customizations.php?priceId='),
    'customer cart submits removals and substitutions'=>str_contains($orderJs,'removals')&&str_contains($orderJs,'substitutions')&&str_contains($orderJs,'fromIngredientId')&&str_contains($orderJs,'toIngredientId'),
    'customer cart submits paid add-ons by server identity'=>str_contains($orderJs,'addOns')&&str_contains($orderJs,'optionId')&&str_contains($orderJs,'quantity'),
    'customer customization labels stay display-only'=>str_contains($orderJs,'submitPayload')&&(str_contains($orderJs,'customizationLabels')||str_contains($orderJs,'row.labels')),
    'paid add-ons are server validated before POS pricing'=>str_contains($integration,'menu_manager_validate_addons')&&str_contains($integration,'menu_manager_apply_pos_addons'),
    'online order page loads current Food cart implementation'=>$foodCartActive,
    'online order page keeps universal public shell'=>str_contains($orderPage,'assets/js/public-shell.js?v=20260915-1'),
    'native POS renders line special instructions'=>str_contains($posJs,'special_instructions'),
    'native POS entrypoint preserves specialized runtime'=>str_contains($posEntry,'js/pos-runtime.js'),
    'KDS renders line special instructions'=>str_contains($kdsJs,'special_instructions'),

    // The shell model is server-owned. Browser code consumes one canonical role-aware model.
    'universal shell consumes canonical server model'=>str_contains($shell,'api/admin-shell.php?page=')&&str_contains($shell,"shell.type !== 'standard'"),
    'canonical shell provides fallback page metadata'=>str_contains($shellCore,"??[ucwords(str_replace")&&str_contains($shellCore,"'Gelato restaurant administration','standard'"),
    'universal shell no longer rejects unlisted admin pages'=>!str_contains($shell,'if (!pageTitles[path]) return;')&&!str_contains($shell,'if(!pageTitles[path])return;'),
    'shell hides legacy admin header'=>str_contains($shell,'header.admin-top{display:none!important}'),
    'shell migrates legacy admin header actions'=>str_contains($shell,'.admin-top-actions')&&str_contains($shell,'header.admin-top'),
    'canonical shell includes time clock navigation'=>str_contains($shellCore,'Time Clock + Attendance')&&str_contains($shellCore,'timeclock.php'),
    'canonical shell includes purchasing navigation'=>str_contains($shellCore,'Purchasing + Receiving')&&str_contains($shellCore,'purchasing.php'),
    'canonical shell includes equipment navigation'=>str_contains($shellCore,'Equipment Catalog')&&str_contains($shellCore,'equipment.php'),
    'standard admin shell is centrally auto-bound'=>str_contains($bootstrap,'function app_standard_admin_shell_pages()')&&str_contains($bootstrap,'function app_boot_admin_shell_injection()'),
];

foreach([
    'admin.php','admin-menu-import.php','operations.php','timeclock.php','scheduling.php','purchasing.php','equipment.php',
    'recipes.php','prep-intelligence.php','sales-intelligence.php','customer-crm.php','online-orders-admin.php',
] as $page){
    $checks[$page.' is registered for the canonical admin shell']=str_contains($bootstrap,"'{$page}'")&&str_contains($shellCore,"'{$page}'");
}

foreach(['customer-account.php','online-order.php'] as $customerPage){
    $checks[$customerPage.' keeps customer/public shell']=!str_contains($bootstrap,"'{$customerPage}'")&&str_contains($shellCore,"'{$customerPage}'")&&str_contains($shellCore,"'customer'");
}

$failed=[];
foreach($checks as $label=>$ok)if(!$ok)$failed[]=$label;
if($failed){
    fwrite(STDERR,"Online order customization/admin shell contract failed:\n - ".implode("\n - ",$failed)."\n");
    exit(1);
}

echo 'PASS: '.count($checks)." customization, paid add-on, POS/KDS propagation, customer UI separation, and canonical role-aware admin shell checks.\n";
