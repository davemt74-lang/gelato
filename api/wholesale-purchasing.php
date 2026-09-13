<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-purchasing-lifecycle.php';
require_once __DIR__ . '/../includes/operations-wholesale.php';

$user=app_require_permission($_SERVER['REQUEST_METHOD']==='GET' ? 'wholesale.view' : 'wholesale.manage');
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];
if(!wholesale_planning_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Wholesale purchasing/lifecycle migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $id=trim((string)($_GET['id']??''));
    $users=$pdo->prepare("SELECT DISTINCT u.id,u.display_name FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name");
    $users->execute([$org]);
    $leads=$pdo->prepare("SELECT l.public_id,l.business_name,l.contact_name,l.email,l.pipeline_stage,l.estimated_monthly_volume,l.next_followup_at,a.public_id account_public_id,a.account_status FROM wholesale_leads l LEFT JOIN wholesale_accounts a ON a.wholesale_lead_id=l.id AND a.organization_id=l.organization_id AND a.archived_at IS NULL WHERE l.organization_id=? AND l.archived_at IS NULL ORDER BY FIELD(l.pipeline_stage,'negotiation','quoted','sample','qualified','new','won','lost'),l.updated_at DESC LIMIT 250");
    $leads->execute([$org]);
    $payload=['ok'=>true,'settings'=>wholesale_planning_settings($pdo,$org),'users'=>$users->fetchAll(),'leads'=>$leads->fetchAll()];
    if($id!=='') $payload['detail']=wholesale_planning_detail($pdo,$org,$id);
    app_json_response($payload);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: GET, POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);
$action=(string)($input['action']??'');
$leadId=trim((string)($input['leadId']??''));
try{
    if($action==='settings.save'){
        app_json_response(['ok'=>true,'message'=>'Wholesale planning defaults saved.','settings'=>wholesale_planning_save_settings($pdo,$org,$input,$userId)]);
    }
    if($leadId==='') throw new InvalidArgumentException('Select a Wholesale lead first.');
    if($action==='worksheet.calculate'){
        $lead=wholesale_planning_lead($pdo,$org,$leadId);
        $catalog=wholesale_commerce_ready($pdo) ? wholesale_commerce_catalog($pdo,$org,$lead['account_id']?(int)$lead['account_id']:null) : [];
        app_json_response(['ok'=>true,'calculation'=>wholesale_planning_calculate($input,wholesale_planning_settings($pdo,$org),$catalog)]);
    }
    if($action==='worksheet.save'){
        $calculation=wholesale_planning_save_worksheet($pdo,$org,$leadId,$input,$userId);
        app_json_response(['ok'=>true,'message'=>'Purchasing worksheet saved to the Wholesale opportunity.','calculation'=>$calculation,'detail'=>wholesale_planning_detail($pdo,$org,$leadId)]);
    }
    if($action==='lifecycle.save'){
        app_json_response(['ok'=>true,'message'=>'Wholesale lifecycle updated.','lifecycle'=>wholesale_lifecycle_save($pdo,$org,$leadId,$input,$userId),'detail'=>wholesale_planning_detail($pdo,$org,$leadId)]);
    }
    if($action==='tasting.save'){
        app_json_response(['ok'=>true,'message'=>'Tasting record saved.','tasting'=>wholesale_tasting_save($pdo,$org,$leadId,$input,$userId),'detail'=>wholesale_planning_detail($pdo,$org,$leadId)]);
    }
    if($action==='starter_order.create'){
        $order=wholesale_planning_create_starter_order($pdo,$org,$leadId,$userId);
        if(operations_wholesale_ready($pdo)) operations_sync_wholesale_tasks($pdo,$org,$userId);
        app_json_response(['ok'=>true,'message'=>!empty($order['alreadyExists'])?'Existing starter order returned; no duplicate was created.':'Canonical requested starter order created and synced to Operations.','order'=>$order,'detail'=>wholesale_planning_detail($pdo,$org,$leadId)]);
    }
    throw new InvalidArgumentException('Unsupported Wholesale purchasing action.');
}catch(InvalidArgumentException|RuntimeException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}
