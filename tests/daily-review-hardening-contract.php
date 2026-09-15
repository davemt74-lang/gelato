<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.$path;
    if(!is_file($full))throw new RuntimeException('Missing review target: '.$path);
    $content=file_get_contents($full);
    if(!is_string($content))throw new RuntimeException('Could not read review target: '.$path);
    return $content;
};
$needles=static function(string $label,string $source,array $values):void{
    foreach($values as $needle){
        if(!str_contains($source,$needle))throw new RuntimeException($label.' missing: '.$needle);
    }
};

$lifecycle=$read('includes/online-order-lifecycle.php');
$needles('Lifecycle hardening',$lifecycle,[
    "'kds_state_known'",
    "table_name='kds_order_items'",
    'Kitchen lifecycle state could not be read safely.',
    'return in_array($current,online_order_lifecycle_statuses(),true)?$current',
]);
if(str_contains($lifecycle,'catch(Throwable){}'."\n\n    \$row=array_merge(\$row,\$counts)")){
    throw new RuntimeException('KDS aggregate failures must not be swallowed as zero kitchen state.');
}

$route=$read('includes/admin-route-access.php');
$needles('Admin route catalog',$route,[
    "'menu-manager.php'=>['any'=>['menu.view']]",
    "'packages-admin.php'=>['any'=>['packages.view']]",
    "'discounts-admin.php'=>['any'=>['discounts.view']]",
    "'pickup-fulfillment.php'=>['callback'=>'admin_pickup_fulfillment_allowed']",
    "'order-recovery.php'=>['callback'=>'admin_order_recovery_allowed']",
]);

$shell=$read('includes/admin-shell-core.php');
$needles('Admin shell catalog',$shell,[
    "'menu-manager.php'=>['Menu Manager'",
    "'packages-admin.php'=>['Package Deals'",
    "'discounts-admin.php'=>['Discounts'",
    "'pickup-fulfillment.php'=>['Pickup Fulfillment'",
    "'order-recovery.php'=>['Order Recovery'",
    "'href'=>'menu-manager.php'",
    "'href'=>'packages-admin.php'",
    "'href'=>'discounts-admin.php'",
    "'href'=>'pickup-fulfillment.php'",
    "'href'=>'order-recovery.php'",
]);

foreach([
    'menu-manager.php'=>'menu.view',
    'packages-admin.php'=>'packages.view',
    'discounts-admin.php'=>'discounts.view',
] as $path=>$permission){
    $source=$read($path);
    if(!str_contains($source,$permission))throw new RuntimeException($path.' does not retain its server-side permission guard.');
    if(!str_contains($source,'universal-admin-page-shell.js'))throw new RuntimeException($path.' must mount the canonical admin shell.');
}

$order=$read('online-order.php');
$needles('Online ordering media',$order,[
    "require_once __DIR__.'/includes/media-core.php'",
    'media_location_cover_map',
    'media_enrich_channel_menu($pdo,$organizationId,$menu)',
    'class="order-location-cover"',
    'class="order-item-image"',
]);
$css=$read('assets/css/online-order.css');
$needles('Online ordering media styles',$css,['.order-location-cover','.order-item-info.has-image','.order-item-image']);

echo "daily-review-hardening=ok\n";
