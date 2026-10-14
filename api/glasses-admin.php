<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/glasses-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$canView=app_has_permission('glasses.view',$user);
$canManage=app_has_permission('glasses.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_ready($pdo))app_json_response(['ok'=>false,'message'=>'Glasses plugin migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=(int)($_GET['locationId']??0);
        if($locationId>0&&!operational_location_allowed($pdo,$user,'glasses.view',$locationId))
            throw new DomainException('AR glasses access is not assigned at that restaurant location.');
        if(($_GET['action']??'')==='pairing.status'){
            if(!$canManage)throw new DomainException('Glasses management permission required.');
            $id=trim((string)($_GET['pairingPublicId']??''));
            if($id===''||strlen($id)>80)throw new InvalidArgumentException('Choose a pairing request.');
            $q=$pdo->prepare("SELECT g.id,g.location_id,g.created_by,g.expires_at,g.consumed_at,
                d.public_id device_public_id,d.display_name device_name
              FROM glasses_pairing_grants g
              LEFT JOIN glasses_devices d ON d.id=g.paired_device_id AND d.organization_id=g.organization_id
              WHERE g.organization_id=? AND g.public_id=? LIMIT 1");
            $q->execute([$org,$id]);$grant=$q->fetch(PDO::FETCH_ASSOC);
            if(!$grant||(int)$grant['created_by']!==$uid||
                !operational_location_allowed($pdo,$user,'glasses.manage',(int)$grant['location_id']))
                throw new DomainException('Pairing request not available.');
            $state=$grant['device_public_id']!==null?'paired':
                ($grant['consumed_at']!==null?'consumed':
                 (strtotime((string)$grant['expires_at'])<=time()?'expired':'pending'));
            app_json_response(['ok'=>true,'pairing'=>[
                'state'=>$state,'devicePublicId'=>$grant['device_public_id'],
                'deviceName'=>$grant['device_name']
            ]]);
        }
        // Device discovery must be filtered by the operator's location rights.
        $devices=array_values(array_filter(glasses_devices($pdo,$org,$locationId>0?$locationId:null),
          static fn(array $d):bool=>operational_location_allowed($pdo,$user,'glasses.view',(int)$d['locationId'])));
        app_json_response([
            'ok'=>true,'devices'=>$devices,
            'permissions'=>['view'=>true,'manage'=>$canManage],
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    if(!$canManage)app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');

    if($action==='pairing.create'){
        $locationId=(int)($in['locationId']??0);
        if($locationId<1)throw new InvalidArgumentException('Choose a restaurant location.');
        if(!operational_location_allowed($pdo,$user,'glasses.manage',$locationId))
            throw new DomainException('AR glasses management is not assigned at this restaurant location.');
        $grant=glasses_create_pairing_grant($pdo,$org,$locationId,isset($in['stationPublicId'])?(string)$in['stationPublicId']:null,$uid,(int)($in['ttlMinutes']??10));
        app_audit($pdo,$org,$uid,'glasses.pairing_created','glasses_pairing_grant',(string)$grant['publicId'],null,[
            'locationId'=>$locationId,'stationPublicId'=>$grant['station']['publicId']??null
        ]);
        app_json_response(['ok'=>true,'pairing'=>$grant],201);
    }

    $public=trim((string)($in['devicePublicId']??''));
    if($public==='')throw new InvalidArgumentException('Choose a glasses device.');
    $current=glasses_device_row($pdo,$org,$public,false);
    if(!operational_location_allowed($pdo,$user,'glasses.manage',(int)$current['location_id']))
        throw new DomainException('AR glasses management is not assigned at this restaurant location.');

    if($action==='device.assign'){
        $locationId=(int)($in['locationId']??0);
        if($locationId<1)throw new InvalidArgumentException('Choose a restaurant location.');
        if(!operational_location_allowed($pdo,$user,'glasses.manage',$locationId))
            throw new DomainException('AR glasses management is not assigned at the destination restaurant location.');
        $device=glasses_device_assign($pdo,$org,$public,$locationId,isset($in['stationPublicId'])?(string)$in['stationPublicId']:null,$uid);
        app_audit($pdo,$org,$uid,'glasses.device_assigned','glasses_device',$public,null,['locationId'=>$locationId,'stationPublicId'=>$device['stationPublicId']]);
        app_json_response(['ok'=>true,'device'=>$device]);
    }

    if($action==='device.rename'){
        $device=glasses_device_rename($pdo,$org,$public,(string)($in['displayName']??''),$uid);
        app_audit($pdo,$org,$uid,'glasses.device_renamed','glasses_device',$public,null,['displayName'=>$device['displayName']]);
        app_json_response(['ok'=>true,'device'=>$device]);
    }

    if($action==='device.revoke'){
        $device=glasses_device_revoke($pdo,$org,$public,$uid);
        app_audit($pdo,$org,$uid,'glasses.device_revoked','glasses_device',$public,null,['locationId'=>$device['locationId']]);
        app_json_response(['ok'=>true,'device'=>$device]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported AR glasses action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'AR glasses management could not complete the request.')],500);
}
