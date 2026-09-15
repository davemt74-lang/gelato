<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root): string{
    $full=$root.'/'.$path;
    if(!is_file($full))throw new RuntimeException('Missing contract file: '.$path);
    return (string)file_get_contents($full);
};

$core=$read('includes/online-order-core.php');
$endpoint=$read('api/online-order-customizations.php');
$orderJs=$read('assets/js/online-order.js');
$orderPage=$read('online-order.php');
$posJs=$read('js/pos.js');
$kdsJs=$read('js/kds.js');
$shell=$read('js/universal-admin-page-shell.js');

$checks=[
    'customization context uses canonical menu ingredient mapping'=>str_contains($core,'menu_item_ingredients')&&str_contains($core,'can_remove'),
    'customization context validates active menu price'=>str_contains($core,'online_order_price_item')&&str_contains($core,"s.status='active'")&&str_contains($core,'i.is_active=1'),
    'cart accepts structured customizations'=>str_contains($core,"'customizations'=>\$customizations"),
    'removals are server rendered into POS instructions'=>str_contains($core,"'REMOVE: '")&&str_contains($core,'online_order_line_instructions'),
    'substitutions are server rendered into POS instructions'=>str_contains($core,"'SUBSTITUTE: '")&&str_contains($core,' → '),
    'item note is server rendered into POS instructions'=>str_contains($core,"'ITEM NOTE: '"),
    'order note remains visible to fulfillment'=>str_contains($core,"'ORDER NOTE: '")&&str_contains($core,"'notes'=>\$note!==''?'Online pickup: '.\$note:'Online pickup order'"),
    'validated line instructions enter canonical POS'=>str_contains($core,'pos_add_item($pdo,$organizationId')&&str_contains($core,'$validated[$index]'),
    'customization endpoint returns canonical context'=>str_contains($endpoint,'online_order_customization_context')&&str_contains($endpoint,'public_site_context($pdo)'),
    'customer cart exposes customize action'=>str_contains($orderJs,"data-cart-action=\"customize\"")&&str_contains($orderJs,'Customize item'),
    'customer cart fetches server catalog'=>str_contains($orderJs,'api/online-order-customizations.php?priceId='),
    'customer cart submits removals and substitutions'=>str_contains($orderJs,'removals')&&str_contains($orderJs,'substitutions')&&str_contains($orderJs,'fromIngredientId')&&str_contains($orderJs,'toIngredientId'),
    'customer customization labels are display only'=>str_contains($orderJs,'customizationLabels')&&str_contains($orderJs,'submitPayload'),
    'online order JavaScript cache key was advanced'=>str_contains($orderPage,'assets/js/online-order.js?v=20260915-2'),
    'native POS renders line special instructions'=>str_contains($posJs,'special_instructions'),
    'KDS renders line special instructions'=>str_contains($kdsJs,'special_instructions'),
    'universal shell has fallback page title'=>str_contains($shell,'const fallbackTitle')&&str_contains($shell,'pageTitles[path] || [fallbackTitle'),
    'universal shell no longer rejects unlisted admin pages'=>!str_contains($shell,'if (!pageTitles[path]) return;')&&!str_contains($shell,'if(!pageTitles[path])return;'),
    'shell hides legacy admin header'=>str_contains($shell,'header.admin-top{display:none!important}'),
    'shell migrates legacy admin header actions'=>str_contains($shell,'.admin-top-actions')&&str_contains($shell,'header.admin-top'),
    'shell includes time clock navigation'=>str_contains($shell,'Time Clock + Attendance')&&str_contains($shell,'timeclock.php'),
    'shell includes purchasing navigation'=>str_contains($shell,'Purchasing + Receiving')&&str_contains($shell,'purchasing.php'),
    'shell includes equipment navigation'=>str_contains($shell,'Equipment Catalog')&&str_contains($shell,'equipment.php'),
];

foreach([
    'admin.php','admin-menu-import.php','operations.php','timeclock.php','scheduling.php','purchasing.php','equipment.php',
    'recipes.php','prep-intelligence.php','sales-intelligence.php','customer-crm.php','online-orders-admin.php',
] as $page){
    $checks[$page.' loads the universal admin shell']=str_contains($read($page),'js/universal-admin-page-shell.js');
}

foreach(['customer-account.php','online-order.php'] as $customerPage){
    $checks[$customerPage.' keeps customer/public shell']=!str_contains($read($customerPage),'js/universal-admin-page-shell.js');
}

$failed=[];
foreach($checks as $label=>$ok)if(!$ok)$failed[]=$label;
if($failed){
    fwrite(STDERR,"Online order customization/admin shell contract failed:\n - ".implode("\n - ",$failed)."\n");
    exit(1);
}

echo 'PASS: '.count($checks)." customization, POS/KDS propagation, customer UI separation, and universal admin shell checks.\n";
