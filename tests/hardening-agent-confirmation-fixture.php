<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-confirmation-core.php';
$mode=$argv[1]??'seed';
$pdo=app_pdo();
if($mode==='snapshot'){
    $org=(int)($argv[2]??0);$out=[];
    foreach(['inventory_items','inventory_transactions','restaurant_tasks','purchase_orders','schedule_shifts'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');
        $q->execute([$org]);$out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if(in_array($mode,['expire-pending','pending','seed-model','continue-model'],true)){
    session_id((string)($argv[2]??''));app_boot_session();
    $actor=app_current_user(true);
    if(!$actor)throw new RuntimeException('An authenticated fixture session is required.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if($mode==='continue-model'){
        require_once __DIR__.'/../includes/food-monster-b8d11-domain-routing.php';
        require_once __DIR__.'/../includes/food-monster-b8d12-pending-actions.php';
        $context=json_decode((string)($argv[3]??''),true,512,JSON_THROW_ON_ERROR);
        $catalogue=fm8d12_pending_catalogue($actor,$context);
        $tool=$catalogue['continue_pending_restaurant_action']??null;
        $result=$tool?$tool['execute'](['decision'=>(string)($argv[4]??'confirm')],$actor):['denied'=>true];
        session_write_close();echo json_encode($result,JSON_THROW_ON_ERROR);exit;
    }
    if($mode==='pending'){
        echo json_encode(gac_pending_raw((string)($argv[3]??'operations'),$org,$uid),JSON_THROW_ON_ERROR);exit;
    }
    if($mode==='expire-pending'){
        $node=(string)($argv[3]??'operations');$bucket=&gac_pending_bucket($node);
        if(isset($bucket[gac_key($org,$uid)]))$bucket[gac_key($org,$uid)]['expires']=time()-1;
        session_write_close();echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);exit;
    }
    require_once __DIR__.'/../includes/food-monster-b8d18-operations-proposals.php';
    gac_request_context(['threadId'=>(string)($argv[3]??''),'sourceMessageId'=>(string)($argv[4]??'')],$pdo,$actor);
    $tool=fm8d18_catalogue($actor)['propose_inventory_stock_change']??null;
    if(!$tool)throw new RuntimeException('Fixture actor cannot propose stock changes.');
    $result=$tool['execute'](['item_name'=>(string)($argv[5]??''),'action'=>'receive',
        'quantity'=>(float)($argv[6]??1),'unit'=>'kg'],$actor);
    session_write_close();echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
if($mode!=='seed')throw new InvalidArgumentException('Unknown confirmation fixture command.');
$suffix='hc-confirm-'.bin2hex(random_bytes(5));$password='Confirm-HTTP-Test!42';$users=[];$orgs=[];
foreach(['owner','coworker','foreign'] as $kind){
    if($kind!=='coworker'){
        $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
            ->execute([$suffix.'-'.$kind]);$org=(int)$pdo->lastInsertId();
    }
    $orgs[$kind]=$org;$email=$suffix.'-'.$kind.'@example.test';
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at) VALUES (?,?,?,'Confirm',?,'active',NOW(6),NOW(6))")
        ->execute([$email,app_password_hash($password),$kind,$kind.' Confirm']);$uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_owner_role) VALUES (?,?,?,1)')->execute([$org,$kind,$kind]);$role=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
    $users[$kind]=['id'=>$uid,'email'=>$email,'organizationId'=>$org];
}
$org=$orgs['owner'];$uid=$users['owner']['id'];$items=[];
foreach(['A','B'] as $label){
    $public=$suffix.'-'.strtolower($label);$name='Confirmation stock '.$label.' '.$suffix;
    $pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by) VALUES (?,?,?,?, 'kg',10,'active',?)")
        ->execute([$org,$public,$public,$name,$uid]);
    $items[$label]=['id'=>$public,'name'=>$name,'initial'=>10];
}
echo json_encode(['organizationId'=>$org,'users'=>$users,'password'=>$password,'items'=>$items,
    'sessionName'=>app_config()['security']['session_name']??'restaurant_workspace_session'],JSON_THROW_ON_ERROR);
