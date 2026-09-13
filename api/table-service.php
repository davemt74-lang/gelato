<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/table-service-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';
require_once __DIR__.'/../includes/service-ops-hardening.php';
require_once __DIR__.'/../includes/service-ops-atomic.php';
require_once __DIR__.'/../includes/service-ops-map.php';
require_once __DIR__.'/../includes/service-visit-core.php';
require_once __DIR__.'/../includes/service-visit-extensions.php';
require_once __DIR__.'/../includes/service-visit-live.php';
require_once __DIR__.'/../includes/service-seatability.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];$membership=(int)$user['membership_id'];
$canView=app_has_permission('table_service.view',$user);$canUse=app_has_permission('table_service.use',$user);$canManage=app_has_permission('table_service.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Table Service permission required.'],403);
if(!table_service_ready($pdo)||!service_visit_ready($pdo))app_json_response(['ok'=>false,'message'=>'Table Service dining-visit migration is not installed. Run upgrade.php.'],503);

function table_service_api_location(PDO $pdo,int $org,int $membership,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);if($id>0){table_service_location($pdo,$org,$id);return $id;}$primary=pos_primary_location_id($pdo,$org,$membership);if($primary)return $primary;$locations=pos_locations($pdo,$org);if(!$locations)throw new InvalidArgumentException('Create an active restaurant location first.');return (int)$locations[0]['id'];
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=table_service_api_location($pdo,$org,$membership);service_visit_reconcile_live($pdo,$org,$locationId,$uid);$checkPublic=trim((string)($_GET['check']??''));
        $payload=['ok'=>true,'locationId'=>$locationId,'locations'=>pos_locations($pdo,$org),'businessDate'=>service_ops_business_date($pdo,$org,$locationId),'map'=>service_ops_canonical_map($pdo,$org,$locationId),'menu'=>pos_menu($pdo,$org),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId),'permissions'=>['use'=>$canUse,'manage'=>$canManage]];
        if($checkPublic!=='')$payload['check']=table_service_detail($pdo,$org,$checkPublic);
        app_json_response($payload);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');$locationId=table_service_api_location($pdo,$org,$membership,$input);
    if(str_starts_with($action,'section.')||$action==='table.save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Table Service management permission required.'],403);
        if($action==='section.save'){$section=table_service_section_save($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'table_service.section_saved','service_section',(string)$section['publicId'],null,['locationId'=>$locationId,'name'=>$section['name']]);app_json_response(['ok'=>true,'section'=>$section,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);}
        if($action==='section.assign'){$businessDate=(string)($input['businessDate']??service_ops_business_date($pdo,$org,$locationId));$assignment=table_service_section_assign($pdo,$org,$locationId,(string)($input['sectionPublicId']??''),isset($input['assignedUserId'])&&$input['assignedUserId']!==null?(int)$input['assignedUserId']:null,$businessDate,$uid);app_audit($pdo,$org,$uid,'table_service.section_assigned','service_section',(string)($input['sectionPublicId']??''),null,$assignment);app_json_response(['ok'=>true,'assignment'=>$assignment,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);}
        if($action==='table.save'){
            if(trim((string)($input['publicId']??''))===''&&host_ready($pdo))throw new InvalidArgumentException('New dining tables must be created as managed physical assets in Host Stand.');
            $table=table_service_table_save($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'table_service.table_saved','service_table',(string)$table['publicId'],null,['locationId'=>$locationId,'name'=>$table['name'],'capacity'=>$table['capacity']]);app_json_response(['ok'=>true,'table'=>$table,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
        }
    }
    if(!$canUse)app_json_response(['ok'=>false,'message'=>'Table Service operating permission required.'],403);
    $checkPublic=trim((string)($input['checkPublicId']??''));
    if($action==='party.seat'){$check=service_seatability_party_seat($pdo,$org,$locationId,(string)($input['tablePublicId']??''),(int)($input['partySize']??1),isset($input['serverUserId'])&&$input['serverUserId']!==null?(int)$input['serverUserId']:null,(string)($input['notes']??''),$uid);app_audit($pdo,$org,$uid,'table_service.party_seated','pos_check',(string)$check['publicId'],null,['tablePublicId'=>(string)($input['tablePublicId']??''),'partySize'=>(int)($input['partySize']??1)]);app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)],201);}
    if($action==='table.state'){$map=service_ops_table_state($pdo,$org,(string)($input['tablePublicId']??''),(string)($input['state']??''),$uid);app_audit($pdo,$org,$uid,'table_service.table_state','service_table',(string)($input['tablePublicId']??''),null,['state'=>(string)($input['state']??'')]);app_json_response(['ok'=>true,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);}
    if($checkPublic==='')throw new InvalidArgumentException('Choose an open table-service check.');
    if($action==='server.assign'){$check=service_ops_assign_server($pdo,$org,$checkPublic,(int)($input['serverUserId']??0),$uid);app_audit($pdo,$org,$uid,'table_service.server_assigned','pos_check',$checkPublic,null,['serverUserId'=>(int)($input['serverUserId']??0)]);app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);}
    if($action==='item.course'){$check=table_service_item_course($pdo,$org,$checkPublic,(int)($input['itemId']??0),isset($input['seatNumber'])&&$input['seatNumber']!==''?(int)$input['seatNumber']:null,(string)($input['courseKey']??'mains'),$uid);app_audit($pdo,$org,$uid,'table_service.item_course_updated','pos_check',$checkPublic,null,['itemId'=>(int)($input['itemId']??0),'seatNumber'=>$input['seatNumber']??null,'courseKey'=>(string)($input['courseKey']??'mains')]);app_json_response(['ok'=>true,'check'=>$check]);}
    if($action==='course.fire'||$action==='course.hold'){$held=$action==='course.hold';$result=table_service_fire_course($pdo,$org,$checkPublic,(string)($input['courseKey']??'mains'),$uid,$held);app_audit($pdo,$org,$uid,$held?'table_service.course_held':'table_service.course_fired','pos_check',$checkPublic,null,['courseKey'=>(string)($input['courseKey']??'mains')]);app_json_response(['ok'=>true]+$result);}
    if($action==='table.transfer'){$check=service_seatability_transfer($pdo,$org,$checkPublic,(string)($input['destinationTablePublicId']??''),$uid);app_audit($pdo,$org,$uid,'table_service.table_transfer','pos_check',$checkPublic,null,['destinationTablePublicId'=>(string)($input['destinationTablePublicId']??'')]);app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)]);}
    if($action==='check.split'){$movedGuests=isset($input['movedGuestCount'])&&$input['movedGuestCount']!==''?(int)$input['movedGuestCount']:null;$result=service_visit_split($pdo,$org,$checkPublic,is_array($input['itemIds']??null)?$input['itemIds']:[],$uid,$movedGuests);app_audit($pdo,$org,$uid,'table_service.check_split','pos_check',$checkPublic,null,['createdCheckPublicId'=>$result['created']['publicId'],'itemIds'=>$input['itemIds']??[],'movedGuestCount'=>$result['created']['guestCount']]);app_json_response(['ok'=>true]+$result+['map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)],201);}
    if($action==='check.merge'){$target=trim((string)($input['targetCheckPublicId']??''));$check=service_visit_merge_safe($pdo,$org,$checkPublic,$target,$uid);app_audit($pdo,$org,$uid,'table_service.check_merged','pos_check',$target,null,['sourceCheckPublicId'=>$checkPublic]);app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported Table Service action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
