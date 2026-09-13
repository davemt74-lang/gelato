<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/daily-manager-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canView=app_has_permission('manager.brief.view',$user);$canManage=app_has_permission('manager.brief.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Daily Manager Brief permission required.'],403);
if(!manager_brief_ready($pdo))app_json_response(['ok'=>false,'message'=>'Daily Manager Brief migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $date=manager_brief_date((string)($_GET['date']??date('Y-m-d')));$phase=manager_brief_phase((string)($_GET['phase']??'live'));$locationId=(int)($_GET['locationId']??0);$locationId=$locationId>0?$locationId:null;
        $locations=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name");$locations->execute([$org]);
        app_json_response(['ok'=>true,'brief'=>manager_brief_snapshot($pdo,$org,$date,$locationId,$phase,$uid),'locations'=>$locations->fetchAll(),'permissions'=>['view'=>$canView,'manage'=>$canManage]]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    if(!$canManage)app_json_response(['ok'=>false,'message'=>'Daily Manager Brief management permission required.'],403);
    $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');$date=manager_brief_date((string)($in['date']??date('Y-m-d')));$locationId=(int)($in['locationId']??0);$location=manager_brief_location($pdo,$org,$locationId>0?$locationId:null);
    if($action==='phase.save'){
        $phase=(string)($in['phase']??'');$log=manager_brief_save_phase($pdo,$org,$location,$date,$phase,(string)($in['note']??''),!empty($in['acknowledge']),$uid);app_audit($pdo,$org,$uid,'manager.brief_phase_saved','manager_daily_log',(string)$log['id'],null,['date'=>$date,'locationKey'=>$location['key'],'phase'=>$phase,'acknowledged'=>!empty($in['acknowledge'])]);app_json_response(['ok'=>true,'log'=>$log,'brief'=>manager_brief_snapshot($pdo,$org,$date,$location['id'],manager_brief_phase($phase),$uid)]);
    }
    if($action==='exception.ack'){
        $key=trim((string)($in['exceptionKey']??''));$acks=manager_brief_ack_exception($pdo,$org,$location,$date,$key,(string)($in['note']??''),$uid);app_audit($pdo,$org,$uid,'manager.brief_exception_acknowledged','manager_daily_log',$date.'|'.$location['key'],null,['exceptionKey'=>$key]);app_json_response(['ok'=>true,'acknowledgements'=>$acks,'brief'=>manager_brief_snapshot($pdo,$org,$date,$location['id'],manager_brief_phase((string)($in['phase']??'live')),$uid)]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Daily Manager Brief action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
