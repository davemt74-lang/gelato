<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-commerce.php';

$user=app_require_permission($_SERVER['REQUEST_METHOD']==='GET'?'wholesale.view':'wholesale.manage');
$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!wholesale_commerce_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale commerce migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'catalog');
    if($action==='catalog'){
        $accountPublic=trim((string)($_GET['accountId']??''));$accountId=null;
        if($accountPublic!==''){$q=$pdo->prepare("SELECT id FROM wholesale_accounts WHERE organization_id=? AND public_id=? AND archived_at IS NULL");$q->execute([$org,$accountPublic]);$accountId=(int)($q->fetchColumn()?:0);if(!$accountId)app_json_response(['ok'=>false,'message'=>'Wholesale account not found.'],404);}
        app_json_response(['ok'=>true,'catalog'=>wholesale_commerce_catalog($pdo,$org,$accountId)]);
    }
    if($action==='admin'){
        $products=$pdo->prepare("SELECT p.*,r.public_id recipe_public_id,r.name recipe_name FROM wholesale_products p LEFT JOIN recipes r ON r.id=p.recipe_id AND r.organization_id=p.organization_id WHERE p.organization_id=? AND p.archived_at IS NULL ORDER BY p.name");$products->execute([$org]);
        $skus=$pdo->prepare("SELECT s.*,p.public_id product_public_id,p.name product_name FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id WHERE s.organization_id=? AND s.archived_at IS NULL ORDER BY p.name,s.name");$skus->execute([$org]);
        $lists=$pdo->prepare("SELECT * FROM wholesale_price_lists WHERE organization_id=? AND archived_at IS NULL ORDER BY is_default DESC,name");$lists->execute([$org]);
        $recipes=$pdo->prepare("SELECT public_id,name FROM recipes WHERE organization_id=? AND status='active' AND archived_at IS NULL ORDER BY name");$recipes->execute([$org]);
        app_json_response(['ok'=>true,'products'=>$products->fetchAll(),'skus'=>$skus->fetchAll(),'priceLists'=>$lists->fetchAll(),'recipes'=>$recipes->fetchAll()]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported wholesale commerce action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');
try{
    if($action==='product')app_json_response(['ok'=>true,'product'=>wholesale_commerce_save_product($pdo,$org,$input,(int)$user['id'])]);
    if($action==='sku')app_json_response(['ok'=>true,'sku'=>wholesale_commerce_save_sku($pdo,$org,$input,(int)$user['id'])]);
    if($action==='price_list')app_json_response(['ok'=>true,'priceList'=>wholesale_commerce_save_price_list($pdo,$org,$input,(int)$user['id'])]);
    if($action==='price')app_json_response(['ok'=>true,'price'=>wholesale_commerce_set_price($pdo,$org,$input,(int)$user['id'])]);
    if($action==='assign_price_list'){
        $accountPublic=trim((string)($input['accountId']??''));$q=$pdo->prepare("SELECT id FROM wholesale_accounts WHERE organization_id=? AND public_id=? AND archived_at IS NULL");$q->execute([$org,$accountPublic]);$accountId=(int)($q->fetchColumn()?:0);if(!$accountId)throw new InvalidArgumentException('Wholesale account not found.');
        wholesale_commerce_assign_price_list($pdo,$org,$accountId,trim((string)($input['priceListId']??'')),(int)$user['id']);
        app_audit($pdo,$org,(int)$user['id'],'wholesale.price_list_assigned','wholesale_account',$accountPublic,null,['priceListId'=>$input['priceListId']??null]);
        app_json_response(['ok'=>true,'message'=>'Wholesale price list assigned.']);
    }
}catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
app_json_response(['ok'=>false,'message'=>'Unsupported wholesale commerce action.'],422);
