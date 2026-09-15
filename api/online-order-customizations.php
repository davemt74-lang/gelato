<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/public-site.php';
require_once __DIR__.'/../includes/menu-order-integration.php';

if($_SERVER['REQUEST_METHOD']!=='GET'){
    header('Allow: GET');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

try{
    $pdo=app_pdo();
    $context=public_site_context($pdo);
    $organizationId=(int)($context['organizationId']??0);
    if($organizationId<1)throw new RuntimeException('Restaurant ordering context is unavailable.');
    if(!online_order_ready($pdo))app_json_response(['ok'=>false,'message'=>'Online ordering is temporarily unavailable.'],503);
    $priceId=max(0,(int)($_GET['priceId']??0));
    $locationId=max(0,(int)($_GET['locationId']??0));
    if($priceId<1)throw new InvalidArgumentException('Choose a menu option to customize.');

    // Keep the established canonical ingredient/removal/substitution context as the base.
    $customization=online_order_customization_context($pdo,$organizationId,$priceId);
    $customization['addOnGroups']=menu_manager_ready($pdo)
        ? menu_manager_customization_addons($pdo,$organizationId,$priceId,'online_order',$locationId?:null)
        : [];

    app_json_response(['ok'=>true,'customization'=>$customization]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Online order customization catalog failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Item customization is temporarily unavailable.'],500);
}
