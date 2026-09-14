<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/table-service-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';
require_once __DIR__.'/../includes/service-ops-hardening.php';
require_once __DIR__.'/../includes/service-ops-atomic.php';
require_once __DIR__.'/../includes/service-ops-map.php';
require_once __DIR__.'/../includes/service-visit-core.php';
require_once __DIR__.'/../includes/service-visit-extensions.php';
require_once __DIR__.'/../includes/service-visit-live.php';
require_once __DIR__.'/../includes/service-seatability.php';
require_once __DIR__.'/../includes/service-reservation-protection.php';
require_once __DIR__.'/../includes/table-cleaning-lifecycle.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$membership=(int)$user['membership_id'];
$canView=app_has_permission('table_service.view',$user);

if(!$canView)app_json_response(['ok'=>false,'message'=>'Table Service permission required.'],403);
if(!table_service_ready($pdo)||!service_visit_ready($pdo)||!table_cleaning_ready($pdo))app_json_response(['ok'=>false,'message'=>'Table Service migrations are not installed. Run upgrade.php.'],503);

function table_service_api_locations(PDO $pdo,int $org,array $user): array
{
    return operational_filter_locations($pdo,$user,'table_service.view',pos_locations($pdo,$org));
}

function table_service_api_location(PDO $pdo,int $org,int $membership,array $user,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);
    if($id>0){
        table_service_location($pdo,$org,$id);
        if(!operational_location_allowed($pdo,$user,'table_service.view',$id))throw new DomainException('Table Service access is not assigned at that restaurant location.');
        return $id;
    }
    $primary=pos_primary_location_id($pdo,$org,$membership);
    if($primary&&operational_location_allowed($pdo,$user,'table_service.view',$primary)){
        table_service_location($pdo,$org,$primary);
        return $primary;
    }
    foreach(table_service_api_locations($pdo,$org,$user) as $location)return (int)$location['id'];
    throw new DomainException('No active restaurant location is assigned for Table Service access.');
}

function table_service_api_permissions(PDO $pdo,array $user,int $locationId): array
{
    return [
        'use'=>app_has_permission('table_service.use',$user)&&operational_location_allowed($pdo,$user,'table_service.use',$locationId),
        'manage'=>app_has_permission('table_service.manage',$user)&&operational_location_allowed($pdo,$user,'table_service.manage',$locationId),
    ];
}

function table_service_api_assert_check_location(PDO $pdo,int $org,string $checkPublicId,int $locationId): void
{
    $row=table_service_check_row($pdo,$org,$checkPublicId,false);
    if((int)$row['location_id']!==$locationId)throw new DomainException('That table-service check belongs to a different restaurant location.');
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=table_service_api_location($pdo,$org,$membership,$user);
        $permissions=table_service_api_permissions($pdo,$user,$locationId);
        service_visit_reconcile_live($pdo,$org,$locationId,$uid);
        $checkPublic=trim((string)($_GET['check']??''));
        if($checkPublic!=='')table_service_api_assert_check_location($pdo,$org,$checkPublic,$locationId);
        $payload=[
            'ok'=>true,
            'locationId'=>$locationId,
            'locations'=>table_service_api_locations($pdo,$org,$user),
            'businessDate'=>service_ops_business_date($pdo,$org,$locationId),
            'map'=>service_ops_canonical_map($pdo,$org,$locationId),
            'menu'=>pos_menu($pdo,$org),
            'openChecks'=>service_visit_open_checks($pdo,$org,$locationId),
            'permissions'=>$permissions,
        ];
        if($checkPublic!=='')$payload['check']=table_service_detail($pdo,$org,$checkPublic);
        app_json_response($payload);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $input=app_json_input();
    app_verify_request_csrf($input);
    $action=(string)($input['action']??'');
    $locationId=table_service_api_location($pdo,$org,$membership,$user,$input);
    $permissions=table_service_api_permissions($pdo,$user,$locationId);

    if(str_starts_with($action,'section.')||$action==='table.save'){
        if(!$permissions['manage'])app_json_response(['ok'=>false,'message'=>'Table Service management permission required at this location.'],403);

        if($action==='section.save'){
            $section=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$input,$uid):array{
                $section=table_service_section_save($pdo,$org,$locationId,$input,$uid);
                app_audit($pdo,$org,$uid,'table_service.section_saved','service_section',(string)$section['publicId'],null,['locationId'=>$locationId,'name'=>$section['name']]);
                return $section;
            });
            app_json_response(['ok'=>true,'section'=>$section,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
        }

        if($action==='section.assign'){
            $businessDate=(string)($input['businessDate']??service_ops_business_date($pdo,$org,$locationId));
            $assignment=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$input,$uid,$businessDate):array{
                $assignment=table_service_section_assign($pdo,$org,$locationId,(string)($input['sectionPublicId']??''),isset($input['assignedUserId'])&&$input['assignedUserId']!==null?(int)$input['assignedUserId']:null,$businessDate,$uid);
                app_audit($pdo,$org,$uid,'table_service.section_assigned','service_section',(string)($input['sectionPublicId']??''),null,$assignment);
                return $assignment;
            });
            app_json_response(['ok'=>true,'assignment'=>$assignment,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
        }

        if(trim((string)($input['publicId']??''))===''&&host_ready($pdo))throw new InvalidArgumentException('New dining tables must be created as managed physical assets in Host Stand.');
        $table=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$input,$uid):array{
            $table=table_service_table_save($pdo,$org,$locationId,$input,$uid);
            app_audit($pdo,$org,$uid,'table_service.table_saved','service_table',(string)$table['publicId'],null,['locationId'=>$locationId,'name'=>$table['name'],'capacity'=>$table['capacity']]);
            return $table;
        });
        app_json_response(['ok'=>true,'table'=>$table,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
    }

    if(!$permissions['use'])app_json_response(['ok'=>false,'message'=>'Table Service operating permission required at this location.'],403);
    $tablePublic=trim((string)($input['tablePublicId']??''));

    if($action==='table.cleaning_start'){
        if($tablePublic==='')throw new InvalidArgumentException('Choose a table to clean.');
        operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$tablePublic,$uid):void{
            $table=table_cleaning_start($pdo,$org,$locationId,$tablePublic,$uid);
            app_audit($pdo,$org,$uid,'table_service.cleaning_started','service_table',$tablePublic,null,['dirtyAt'=>$table['dirty_at']??null]);
        });
        app_json_response(['ok'=>true,'tablePublicId'=>$tablePublic,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
    }

    if($action==='table.ready'){
        if($tablePublic==='')throw new InvalidArgumentException('Choose a table to mark ready.');
        operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$tablePublic,$uid):void{
            $table=table_cleaning_mark_ready($pdo,$org,$locationId,$tablePublic,$uid);
            app_audit($pdo,$org,$uid,'table_service.table_ready','service_table',$tablePublic,null,['readyAt'=>$table['ready_at']??null]);
        });
        app_json_response(['ok'=>true,'tablePublicId'=>$tablePublic,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
    }

    if($action==='party.seat'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$tablePublic,$input,$uid):array{
            $check=service_reservation_protection_party_seat($pdo,$org,$locationId,$tablePublic,(int)($input['partySize']??1),isset($input['serverUserId'])&&$input['serverUserId']!==null?(int)$input['serverUserId']:null,(string)($input['notes']??''),$uid);
            app_audit($pdo,$org,$uid,'table_service.party_seated','pos_check',(string)$check['publicId'],null,['tablePublicId'=>$tablePublic,'partySize'=>(int)($input['partySize']??1)]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)],201);
    }

    if($action==='table.state'){
        operational_db_wrap($pdo,function()use($pdo,$org,$tablePublic,$input,$uid):void{
            service_ops_table_state($pdo,$org,$tablePublic,(string)($input['state']??''),$uid);
            app_audit($pdo,$org,$uid,'table_service.table_state','service_table',$tablePublic,null,['state'=>(string)($input['state']??'')]);
        });
        app_json_response(['ok'=>true,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
    }

    $checkPublic=trim((string)($input['checkPublicId']??''));
    if($checkPublic==='')throw new InvalidArgumentException('Choose an open table-service check.');
    table_service_api_assert_check_location($pdo,$org,$checkPublic,$locationId);

    if($action==='server.assign'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$input,$uid):array{
            $check=service_ops_assign_server($pdo,$org,$checkPublic,(int)($input['serverUserId']??0),$uid);
            app_audit($pdo,$org,$uid,'table_service.server_assigned','pos_check',$checkPublic,null,['serverUserId'=>(int)($input['serverUserId']??0)]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId)]);
    }

    if($action==='item.course'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$input,$uid):array{
            $check=table_service_item_course($pdo,$org,$checkPublic,(int)($input['itemId']??0),isset($input['seatNumber'])&&$input['seatNumber']!==''?(int)$input['seatNumber']:null,(string)($input['courseKey']??'mains'),$uid);
            app_audit($pdo,$org,$uid,'table_service.item_course_updated','pos_check',$checkPublic,null,['itemId'=>(int)($input['itemId']??0),'seatNumber'=>$input['seatNumber']??null,'courseKey'=>(string)($input['courseKey']??'mains')]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check]);
    }

    if($action==='course.fire'||$action==='course.hold'){
        $held=$action==='course.hold';
        $result=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$input,$uid,$held):array{
            $result=table_service_fire_course($pdo,$org,$checkPublic,(string)($input['courseKey']??'mains'),$uid,$held);
            app_audit($pdo,$org,$uid,$held?'table_service.course_held':'table_service.course_fired','pos_check',$checkPublic,null,['courseKey'=>(string)($input['courseKey']??'mains')]);
            return $result;
        });
        app_json_response(['ok'=>true]+$result);
    }

    if($action==='table.transfer'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$input,$uid):array{
            $check=service_reservation_protection_transfer($pdo,$org,$checkPublic,(string)($input['destinationTablePublicId']??''),$uid);
            app_audit($pdo,$org,$uid,'table_service.table_transfer','pos_check',$checkPublic,null,['destinationTablePublicId'=>(string)($input['destinationTablePublicId']??'')]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)]);
    }

    if($action==='check.split'){
        $movedGuests=isset($input['movedGuestCount'])&&$input['movedGuestCount']!==''?(int)$input['movedGuestCount']:null;
        $result=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$input,$uid,$movedGuests):array{
            $result=service_visit_split($pdo,$org,$checkPublic,is_array($input['itemIds']??null)?$input['itemIds']:[],$uid,$movedGuests);
            app_audit($pdo,$org,$uid,'table_service.check_split','pos_check',$checkPublic,null,['createdCheckPublicId'=>$result['created']['publicId'],'itemIds'=>$input['itemIds']??[],'movedGuestCount'=>$result['created']['guestCount']]);
            return $result;
        });
        app_json_response(['ok'=>true]+$result+['map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)],201);
    }

    if($action==='check.merge'){
        $target=trim((string)($input['targetCheckPublicId']??''));
        table_service_api_assert_check_location($pdo,$org,$target,$locationId);
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$checkPublic,$target,$uid):array{
            $check=service_visit_merge_safe($pdo,$org,$checkPublic,$target,$uid);
            app_audit($pdo,$org,$uid,'table_service.check_merged','pos_check',$target,null,['sourceCheckPublicId'=>$checkPublic]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check,'map'=>service_ops_canonical_map($pdo,$org,$locationId),'openChecks'=>service_visit_open_checks($pdo,$org,$locationId)]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported Table Service action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Table Service could not complete the request.')],500);
}
