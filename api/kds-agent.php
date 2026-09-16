<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/kds-production.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$membership=(int)$user['membership_id'];
if(!app_has_permission('kds.view',$user))app_json_response(['ok'=>false,'message'=>'Kitchen Display permission required.'],403);
if(!kds_ready($pdo))app_json_response(['ok'=>false,'message'=>'Kitchen Display migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
$message=trim((string)($input['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>2400)app_json_response(['ok'=>false,'message'=>'Ask Gelato a kitchen question no longer than 2,400 characters.'],422);
$context=is_array($input['pageContext']??null)?$input['pageContext']:[];

function kda_allowed_locations(PDO $pdo,int $org,array $user): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");
    $q->execute([$org]);
    return operational_filter_locations($pdo,$user,'kds.view',$q->fetchAll());
}

function kda_location_id(PDO $pdo,int $org,int $membership,array $user,array $context): int
{
    $requested=max(0,(int)($context['locationId']??0));
    if($requested>0){
        kds_location($pdo,$org,$requested);
        if(!operational_location_allowed($pdo,$user,'kds.view',$requested))throw new DomainException('Kitchen Display access is not assigned at that restaurant location.');
        return $requested;
    }
    $q=$pdo->prepare('SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND id=? LIMIT 1');
    $q->execute([$org,$membership]);
    $primary=(int)($q->fetchColumn()?:0);
    if($primary>0&&operational_location_allowed($pdo,$user,'kds.view',$primary))return $primary;
    foreach(kda_allowed_locations($pdo,$org,$user) as $location)return (int)$location['id'];
    throw new DomainException('No active restaurant location is assigned for Kitchen Display access.');
}

function kda_public_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function kda_format_age(int $seconds): string
{
    $seconds=max(0,$seconds);$minutes=intdiv($seconds,60);$remaining=$seconds%60;
    return $minutes>0?$minutes.'m '.$remaining.'s':$remaining.'s';
}

function kda_ticket_label(array $ticket): string
{
    $parts=[(string)$ticket['checkNumber']];
    if(!empty($ticket['tableName']))$parts[]=(string)$ticket['tableName'];
    if(!empty($ticket['serverName']))$parts[]=(string)$ticket['serverName'];
    $parts[]='age '.kda_format_age((int)($ticket['oldestAgeSeconds']??0));
    return implode(' · ',$parts);
}

try{
    $locationId=kda_location_id($pdo,$org,$membership,$user,$context);
    $station=kda_public_id($context['stationPublicId']??'');
    if($station!==''){
        $exists=false;foreach(kds_stations($pdo,$org,$locationId) as $row)if((string)$row['public_id']===$station){$exists=true;break;}
        if(!$exists&&$station!=='unrouted')throw new InvalidArgumentException('The selected kitchen station is no longer active at this location.');
    }
    $board=kds_production_board($pdo,$org,$locationId,$station!==''?$station:null,false);
    $metrics=(array)$board['metrics'];
    $normalized=mb_strtolower($message,'UTF-8');
    $selectedId=kda_public_id($context['kitchenItemPublicId']??'');

    if($selectedId!==''){
        $item=kds_item($pdo,$org,$selectedId,false);
        if((int)$item['location_id']!==$locationId)throw new DomainException('That kitchen item belongs to a different restaurant location.');
        if(preg_match('/\b(?:this|selected|current)\s+(?:item|ticket item)|what(?:\x27s| is)\s+(?:this|selected)|status\s+of\s+(?:this|selected)\b/u',$normalized)){
            $answer=(string)$item['item_name_snapshot'].' · '.str_replace('_',' ',(string)$item['status']).' · check '.(string)$item['check_number'];
            if(!empty($item['station_name']))$answer.=' · '.(string)$item['station_name'];
            if(!empty($item['special_instructions']))$answer.=' · instructions: '.(string)$item['special_instructions'];
            return app_json_response(['ok'=>true,'skill'=>'kds.selected_item','answer'=>$answer.'.','data'=>['itemPublicId'=>$selectedId,'status'=>$item['status'],'station'=>$item['station_name']],'sources'=>['Kitchen Display → Live Item']]);
        }
    }

    if(preg_match('/\b(?:late|behind|overdue|slow|sla)\b/u',$normalized)){
        $tickets=array_values(array_filter((array)$board['tickets'],static fn($ticket)=>!empty($ticket['late'])));
        $parts=array_map('kda_ticket_label',array_slice($tickets,0,8));
        $answer=$tickets?count($tickets).' late kitchen ticket'.(count($tickets)===1?'':'s').': '.implode('; ',$parts).'.':'No kitchen tickets are currently beyond their station target.';
        app_json_response(['ok'=>true,'skill'=>'kds.late','answer'=>$answer,'data'=>['count'=>count($tickets),'tickets'=>array_slice($tickets,0,12)],'sources'=>['Kitchen Display → Live Board']]);
    }

    if(preg_match('/\b(?:ready|ready to bump|pickup|expo)\b/u',$normalized)){
        $tickets=array_values(array_filter((array)$board['tickets'],static fn($ticket)=>!empty($ticket['readyToBump'])||((int)($ticket['ready']??0)>0)));
        $parts=array_map('kda_ticket_label',array_slice($tickets,0,8));
        $answer=$tickets?count($tickets).' ticket'.(count($tickets)===1?'':'s').' have ready items: '.implode('; ',$parts).'.':'No active kitchen tickets currently have ready items.';
        app_json_response(['ok'=>true,'skill'=>'kds.ready','answer'=>$answer,'data'=>['count'=>count($tickets),'tickets'=>array_slice($tickets,0,12)],'sources'=>['Kitchen Display → Expo']]);
    }

    if(preg_match('/\b(?:unrouted|no station|missing route|routing)\b/u',$normalized)){
        $items=array_values(array_filter((array)$board['items'],static fn($item)=>$item['station_id']===null&&!in_array((string)$item['status'],['completed','cancelled'],true)));
        $parts=[];foreach(array_slice($items,0,10) as $item)$parts[]=(string)$item['check_number'].' '.(string)$item['item_name_snapshot'];
        $answer=$items?count($items).' active kitchen item'.(count($items)===1?' is':'s are').' unrouted: '.implode('; ',$parts).'.':'No active kitchen items are unrouted.';
        app_json_response(['ok'=>true,'skill'=>'kds.unrouted','answer'=>$answer,'data'=>['count'=>count($items),'items'=>array_slice($items,0,15)],'sources'=>['Kitchen Display → Routing']]);
    }

    if(preg_match('/\b(?:all day|all-day|production totals?|item counts?|how many)\b/u',$normalized)){
        $rows=(array)$board['allDay'];$parts=[];
        foreach(array_slice($rows,0,12) as $row)$parts[]=(string)$row['name'].' '.rtrim(rtrim(number_format((float)$row['quantity'],2),'0'),'.').' active';
        $answer=$rows?'All Day active fire: '.implode('; ',$parts).'.':'There are no active fired items in the kitchen.';
        app_json_response(['ok'=>true,'skill'=>'kds.all_day','answer'=>$answer,'data'=>array_slice($rows,0,20),'sources'=>['Kitchen Display → All Day']]);
    }

    if(preg_match('/\b(?:station|stations|load|busy|busiest)\b/u',$normalized)){
        $parts=[];
        foreach((array)$board['stations'] as $row){$count=(int)($board['stationCounts'][(string)$row['public_id']]??0);$parts[]=(string)$row['name'].' '.$count.' active';}
        if((int)($board['stationCounts']['unrouted']??0)>0)$parts[]='Unrouted '.(int)$board['stationCounts']['unrouted'];
        app_json_response(['ok'=>true,'skill'=>'kds.stations','answer'=>$parts?'Station load: '.implode('; ',$parts).'.':'No active kitchen stations are configured.','data'=>['stationCounts'=>$board['stationCounts']],'sources'=>['Kitchen Display → Stations']]);
    }

    $location=(array)$board['location'];
    $answer=(string)$location['name'].' kitchen: '.(int)$metrics['tickets'].' active ticket'.((int)$metrics['tickets']===1?'':'s').', '.(int)$metrics['queued'].' queued, '.(int)$metrics['inProgress'].' in progress, '.(int)$metrics['ready'].' ready, '.(int)$metrics['held'].' held, '.(int)$metrics['late'].' late, '.(int)$metrics['unrouted'].' unrouted, and '.(int)$metrics['readyTickets'].' ready-to-bump ticket'.((int)$metrics['readyTickets']===1?'':'s').'.';
    app_json_response(['ok'=>true,'skill'=>'kds.snapshot','answer'=>$answer,'data'=>['location'=>$location,'metrics'=>$metrics,'stationCounts'=>$board['stationCounts']],'sources'=>['Kitchen Display → Live Board']]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
