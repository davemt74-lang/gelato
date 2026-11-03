<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/purchasing-receiving.php';
$pdo=app_pdo();
if(($argv[1]??'seed')==='snapshot'){
    $org=(int)($argv[2]??0);$out=[];
    foreach(['inventory_items','inventory_transactions','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items','purchase_order_events','vendor_price_history','inventory_vendor_items','audit_log'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
$suffix='hbip-http-'.bin2hex(random_bytes(5));$password='Stock-HTTP-Test!42';$users=[];$orgs=[];
foreach(['owner','viewer','foreign'] as $kind){
    if($kind!=='viewer'){
        $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix.'-'.$kind]);$org=(int)$pdo->lastInsertId();
    }
    $orgs[$kind]=$org;$email=$suffix.'-'.$kind.'@example.test';
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at) VALUES (?,?,?,'Stock',?,'active',NOW(6),NOW(6))")
        ->execute([$email,app_password_hash($password),$kind,$kind.' Stock']);$uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_owner_role) VALUES (?,?,?,?)')->execute([$org,$kind,$kind,$kind==='viewer'?0:1]);$role=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
    if($kind==='viewer')$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key IN ('inventory.view','purchasing.view','receiving.view')")->execute([$role]);
    $users[$kind]=['id'=>$uid,'email'=>$email];
}
$org=$orgs['owner'];$uid=$users['owner']['id'];
$stock=$suffix.'-stock';
$pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by) VALUES (?,?,?,?, 'kg',3,'active',?)")
    ->execute([$org,$stock,$stock,'HTTP stock',$uid]);
$vendor=purchasing_save_vendor($pdo,$org,['name'=>'HTTP supplier '.$suffix],$uid);$orders=[];
foreach(['receive','cancel','draft'] as $kind){
    $public=$suffix.'-'.$kind;
    $pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by) VALUES (?,?,?,?, 'kg',0,'active',?)")
        ->execute([$org,$public,$public,$kind.' stock',$uid]);
    purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendor['public_id'],'inventoryId'=>$public,'vendorSku'=>$kind,'packSize'=>1,'packUnit'=>'kg','pricePerPack'=>3,'preferred'=>true],$uid);
    $po=purchasing_create_order($pdo,$org,$vendor['public_id'],[['inventoryId'=>$public,'packs'=>5]],$uid);
    if($kind!=='draft')$po=purchasing_submit($pdo,$org,$po['public_id'],$uid);
    $orders[$kind]=['id'=>$po['public_id'],'line'=>$po['items'][0]['public_id'],'stock'=>$public];
}
echo json_encode(['organizationId'=>$org,'users'=>$users,'password'=>$password,'stock'=>$stock,'orders'=>$orders],JSON_THROW_ON_ERROR);
