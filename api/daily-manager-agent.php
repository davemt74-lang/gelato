<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/daily-manager-core.php';
require_once __DIR__.'/../includes/equipment-brain.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!app_has_permission('manager.brief.view',$user)||!app_has_permission('manager.brief.agent',$user))app_json_response(['ok'=>false,'message'=>'Daily Manager Agent permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}if(!manager_brief_ready($pdo))app_json_response(['ok'=>false,'message'=>'Daily Manager Brief migration is not installed. Run upgrade.php.'],503);
$in=app_json_input();app_verify_request_csrf($in);$message=trim((string)($in['message']??''));if($message===''||mb_strlen($message,'UTF-8')>2000)app_json_response(['ok'=>false,'message'=>'Enter a manager brief request no longer than 2,000 characters.'],422);$text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');

$date=(new DateTimeImmutable('today'))->format('Y-m-d');if(preg_match('/\btomorrow\b/u',$text))$date=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');elseif(preg_match('/\byesterday\b/u',$text))$date=(new DateTimeImmutable('yesterday'))->format('Y-m-d');elseif(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/',$text,$m))$date=$m[1];
$phase=preg_match('/\b(opening|open brief|morning brief)\b/u',$text)?'opening':(preg_match('/\b(closing|close brief|end of day|recap)\b/u',$text)?'closing':'live');
$locationId=isset($in['locationId'])&&(int)$in['locationId']>0?(int)$in['locationId']:null;
try{
    $brief=manager_brief_snapshot($pdo,$org,$date,$locationId,$phase,$uid);$unacked=array_values(array_filter($brief['exceptions'],static fn(array $e):bool=>!$e['acknowledged']));$high=count(array_filter($unacked,static fn(array $e):bool=>$e['severity']==='high'));$medium=count(array_filter($unacked,static fn(array $e):bool=>$e['severity']==='medium'));
    $answer=$brief['summary'];if($unacked){$top=array_slice($unacked,0,5);$answer.=' Priority items: '.implode('; ',array_map(static fn(array $e):string=>$e['title'].' — '.$e['detail'],$top)).'.';}else{$answer.=' There are no unacknowledged high/medium exceptions in the current brief.';}
    $equipment=null;
    if(app_has_permission('equipment.view',$user)&&equipment_brain_table_ready($pdo,'equipment_assets')){
        $equipment=equipment_brain_summary($pdo,$org);$brief['equipment']=$equipment;
        if($equipment['outOfService']>0||$equipment['overdueMaintenance']>0){
            $answer.=" Equipment: {$equipment['outOfService']} out of service and {$equipment['overdueMaintenance']} overdue for maintenance.";
        }elseif($equipment['dueWithin30Days']>0){
            $answer.=" Equipment: {$equipment['dueWithin30Days']} asset".($equipment['dueWithin30Days']===1?' is':'s are')." due for maintenance within 30 days.";
        }
    }
    $answer.=' This is an operational manager summary based on recorded restaurant data; it does not rank employees or make promotion, discipline, compensation, scheduling, or termination decisions.';
    app_audit($pdo,$org,$uid,'agent.manager_daily_brief_used','manager_daily_brief',$date.'|'.$brief['location']['key'],null,['date'=>$date,'phase'=>$phase,'highExceptions'=>$high,'mediumExceptions'=>$medium,'equipmentIntegrated'=>$equipment!==null]);
    app_json_response(['ok'=>true,'skill'=>'manager.daily_brief','answer'=>$answer,'data'=>$brief,'sources'=>array_values(array_filter(['daily-manager.php',$equipment!==null?'equipment.php':null]))]);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
