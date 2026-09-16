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

function kda_qty(float $value): string
{
    return rtrim(rtrim(number_format($value,2,'.',''),'0'),'.');
}

function kda_ticket_age(array $ticket): int
{
    return max(0,(int)($ticket['oldestAgeSeconds']??$ticket['durationSeconds']??0));
}

function kda_ticket_label(array $ticket): string
{
    $parts=['order '.(string)$ticket['checkNumber']];
    if(!empty($ticket['tableName']))$parts[]='table '.(string)$ticket['tableName'];
    elseif(!empty($ticket['serviceMode']))$parts[]=str_replace('_',' ',(string)$ticket['serviceMode']);
    if(!empty($ticket['serverName']))$parts[]='server '.(string)$ticket['serverName'];
    if(isset($ticket['oldestAgeSeconds']))$parts[]='age '.kda_format_age(kda_ticket_age($ticket));
    return implode(', ',$parts);
}

function kda_item_phrase(array $item,bool $includeStatus=true): string
{
    $text=kda_qty((float)($item['quantity']??0)).' '.trim((string)($item['item_name_snapshot']??'item'));
    if(!empty($item['option_name_snapshot']))$text.=' '.trim((string)$item['option_name_snapshot']);
    if($includeStatus&&!empty($item['status']))$text.=', '.str_replace('_',' ',(string)$item['status']);
    if($includeStatus&&!empty($item['station_name']))$text.=' at '.(string)$item['station_name'];
    if(!empty($item['special_instructions']))$text.=', special instruction: '.trim((string)$item['special_instructions']);
    return $text;
}

function kda_ticket_detail(array $ticket,bool $voice=false): string
{
    $parts=[kda_ticket_label($ticket)];
    if(isset($ticket['guestCount'])&&(int)$ticket['guestCount']>0)$parts[]=(int)$ticket['guestCount'].' guest'.((int)$ticket['guestCount']===1?'':'s');
    $items=[];foreach((array)($ticket['items']??[]) as $item)$items[]=kda_item_phrase($item,true);
    $status=[];
    foreach(['queued'=>'queued','inProgress'=>'in progress','ready'=>'ready','held'=>'held','unrouted'=>'unrouted'] as $key=>$label){$count=(int)($ticket[$key]??0);if($count>0)$status[]=$count.' '.$label;}
    $text=implode(', ',$parts).'. ';
    if($items)$text.=implode($voice?'. ':'; ',$items).'. ';
    if($status)$text.='Ticket status: '.implode(', ',$status).'. ';
    if(!empty($ticket['readyToBump']))$text.='Ready to bump. ';
    if(!empty($ticket['late']))$text.='Late against station target. ';
    elseif(!empty($ticket['warning']))$text.='Approaching station target. ';
    return trim($text);
}

function kda_recent_history(PDO $pdo,int $org,int $locationId): array
{
    $sql="SELECT k.public_id,k.status,k.sent_at,k.fired_at,k.started_at,k.ready_at,k.completed_at,k.cancelled_at,
        TIMESTAMPDIFF(SECOND,COALESCE(k.fired_at,k.sent_at),COALESCE(k.completed_at,k.cancelled_at,k.updated_at)) duration_seconds,
        s.name station_name,c.public_id check_public_id,c.check_number,c.service_mode,c.table_name,c.guest_count,
        u.display_name opened_by_name,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions
      FROM kds_order_items k
      JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id
      JOIN users u ON u.id=c.opened_by
      JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id
      LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id
      WHERE k.organization_id=? AND k.location_id=?
        AND k.status IN ('completed','cancelled')
        AND COALESCE(k.completed_at,k.cancelled_at,k.updated_at)>=DATE_SUB(NOW(6),INTERVAL 24 HOUR)
      ORDER BY COALESCE(k.completed_at,k.cancelled_at,k.updated_at) DESC,k.id DESC";
    $q=$pdo->prepare($sql);$q->execute([$org,$locationId]);$tickets=[];
    foreach($q->fetchAll() as $row){
        $key=(string)$row['check_public_id'];
        if(!isset($tickets[$key]))$tickets[$key]=[
            'checkPublicId'=>$key,'checkNumber'=>(string)$row['check_number'],'serviceMode'=>(string)$row['service_mode'],
            'tableName'=>$row['table_name'],'guestCount'=>(int)$row['guest_count'],'serverName'=>(string)$row['opened_by_name'],
            'completedAt'=>$row['completed_at']?:$row['cancelled_at'],'durationSeconds'=>max(0,(int)($row['duration_seconds']??0)),'items'=>[],
        ];
        $tickets[$key]['durationSeconds']=max((int)$tickets[$key]['durationSeconds'],max(0,(int)($row['duration_seconds']??0)));
        $candidate=$row['completed_at']?:$row['cancelled_at'];
        if($candidate&&(!$tickets[$key]['completedAt']||strcmp((string)$candidate,(string)$tickets[$key]['completedAt'])>0))$tickets[$key]['completedAt']=$candidate;
        $tickets[$key]['items'][]=[
            'public_id'=>(string)$row['public_id'],'status'=>(string)$row['status'],'item_name_snapshot'=>(string)$row['item_name_snapshot'],
            'option_name_snapshot'=>$row['option_name_snapshot'],'quantity'=>(float)$row['quantity'],'special_instructions'=>$row['special_instructions'],
            'station_name'=>$row['station_name'],'durationSeconds'=>max(0,(int)($row['duration_seconds']??0)),
        ];
    }
    return array_slice(array_values($tickets),0,100);
}

function kda_ticket_by_public_id(array $tickets,string $publicId): ?array
{
    foreach($tickets as $ticket)if((string)($ticket['checkPublicId']??'')===$publicId)return $ticket;
    return null;
}

function kda_match_ticket(array $tickets,string $message,string $selectedPublicId=''): ?array
{
    $lower=mb_strtolower($message,'UTF-8');
    $useSelected=$selectedPublicId!==''&&preg_match('/\b(?:this|selected|current)\s+(?:order|ticket|check)|what(?:\x27s| is)\s+(?:on|in)\s+(?:this|selected)|read\s+(?:this|selected)\b/u',$lower);
    if($useSelected&&($selected=kda_ticket_by_public_id($tickets,$selectedPublicId)))return $selected;

    if(preg_match('/\b(?:order|ticket|check)(?:\s+(?:number|no\.?))?\s*#?\s*([A-Za-z0-9._:-]+)\b/iu',$message,$m)){
        $needle=mb_strtolower((string)$m[1],'UTF-8');
        foreach($tickets as $ticket)if(mb_strtolower((string)($ticket['checkNumber']??''),'UTF-8')===$needle)return $ticket;
    }
    if(preg_match('/\btable\s+([^,?.!]{1,40})/iu',$message,$m)){
        $needle=mb_strtolower(trim((string)$m[1]),'UTF-8');
        foreach($tickets as $ticket)if(mb_strtolower(trim((string)($ticket['tableName']??'')),'UTF-8')===$needle)return $ticket;
    }
    foreach($tickets as $ticket){
        $number=trim((string)($ticket['checkNumber']??''));
        if($number!==''&&preg_match('/(?<![A-Za-z0-9])'.preg_quote($number,'/').'(?![A-Za-z0-9])/u',$message))return $ticket;
    }
    return null;
}

function kda_newest_ticket(array $tickets): ?array
{
    $best=null;$stamp='';
    foreach($tickets as $ticket){
        $candidate='';foreach((array)($ticket['items']??[]) as $item){$value=(string)($item['sent_at']??$item['fired_at']??'');if($value>$candidate)$candidate=$value;}
        if($best===null||$candidate>$stamp){$best=$ticket;$stamp=$candidate;}
    }
    return $best;
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
    $tickets=(array)$board['tickets'];
    $normalized=mb_strtolower($message,'UTF-8');
    $selectedItemId=kda_public_id($context['kitchenItemPublicId']??'');
    $selectedCheckId=kda_public_id($context['checkPublicId']??'');

    if($selectedItemId!==''){
        $item=kds_item($pdo,$org,$selectedItemId,false);
        if((int)$item['location_id']!==$locationId)throw new DomainException('That kitchen item belongs to a different restaurant location.');
        if(preg_match('/\b(?:this|selected|current)\s+(?:item|ticket item)|what(?:\x27s| is)\s+(?:this|selected)\s+item|status\s+of\s+(?:this|selected)\s+item\b/u',$normalized)){
            $answer=kda_item_phrase($item,true).' on order '.(string)$item['check_number'].'.';
            app_json_response(['ok'=>true,'skill'=>'kds.selected_item','answer'=>$answer,'data'=>['itemPublicId'=>$selectedItemId,'status'=>$item['status'],'station'=>$item['station_name']],'sources'=>['Kitchen Display → Live Item']]);
        }
    }

    $ticket=kda_match_ticket($tickets,$message,$selectedCheckId);
    if($ticket){
        app_json_response(['ok'=>true,'skill'=>'kds.order_detail','answer'=>kda_ticket_detail($ticket,true),'data'=>['ticket'=>$ticket],'sources'=>['Kitchen Display → Live Order']]);
    }

    if(preg_match('/\b(?:order history|recent completed|completed orders?|finished orders?|last completed)\b/u',$normalized)){
        $history=kda_recent_history($pdo,$org,$locationId);
        $historyTicket=kda_match_ticket($history,$message,$selectedCheckId);
        if($historyTicket)app_json_response(['ok'=>true,'skill'=>'kds.order_history_detail','answer'=>kda_ticket_detail($historyTicket,true).' Completed '.(string)($historyTicket['completedAt']??'recently').'.','data'=>['ticket'=>$historyTicket],'sources'=>['Kitchen Display → 24-Hour Order History']]);
        $parts=[];foreach(array_slice($history,0,8) as $row)$parts[]=kda_ticket_label($row).' completed '.(string)($row['completedAt']??'');
        $answer=$history?'Recent completed kitchen orders: '.implode('; ',$parts).'.':'There are no completed or cancelled kitchen orders in the last 24 hours.';
        app_json_response(['ok'=>true,'skill'=>'kds.order_history','answer'=>$answer,'data'=>['count'=>count($history),'tickets'=>array_slice($history,0,20)],'sources'=>['Kitchen Display → 24-Hour Order History']]);
    }

    if(preg_match('/\b(?:read|list|tell me|what are|what\x27s|whats)\s+(?:all\s+)?(?:the\s+)?(?:active\s+|current\s+)?(?:kds\s+)?orders?\b|\borders?\s+(?:in|on)\s+(?:the\s+)?(?:kitchen|kds)\b/u',$normalized)){
        $ordered=$tickets;usort($ordered,static fn($a,$b)=>kda_ticket_age($b)<=>kda_ticket_age($a));
        if(!$ordered)app_json_response(['ok'=>true,'skill'=>'kds.read_orders','answer'=>'There are no active kitchen orders right now.','data'=>['tickets'=>[]],'sources'=>['Kitchen Display → Live Orders']]);
        $parts=[];foreach(array_slice($ordered,0,20) as $row)$parts[]=kda_ticket_detail($row,true);
        app_json_response(['ok'=>true,'skill'=>'kds.read_orders','answer'=>implode(' Next order. ',$parts),'data'=>['tickets'=>array_slice($ordered,0,20)],'sources'=>['Kitchen Display → Live Orders']]);
    }

    if(preg_match('/\b(?:newest|latest|just came in|most recent|last order|new order)\b/u',$normalized)){
        $newest=kda_newest_ticket($tickets);
        $answer=$newest?'Newest active kitchen order: '.kda_ticket_detail($newest,true):'There are no active kitchen orders right now.';
        app_json_response(['ok'=>true,'skill'=>'kds.newest_order','answer'=>$answer,'data'=>['ticket'=>$newest],'sources'=>['Kitchen Display → Live Orders']]);
    }

    if(preg_match('/\b(?:special instructions?|mods?|modifications?|allerg(?:y|ies)|notes?)\b/u',$normalized)){
        $rows=[];
        foreach($tickets as $order)foreach((array)($order['items']??[]) as $item)if(trim((string)($item['special_instructions']??''))!=='')$rows[]='order '.(string)$order['checkNumber'].', '.kda_item_phrase($item,false);
        $answer=$rows?'Current kitchen special instructions: '.implode('; ',array_slice($rows,0,20)).'.':'There are no special instructions on the active KDS orders.';
        app_json_response(['ok'=>true,'skill'=>'kds.special_instructions','answer'=>$answer,'data'=>['count'=>count($rows)],'sources'=>['Kitchen Display → Live Order Instructions']]);
    }

    if(preg_match('/\b(?:late|behind|overdue|slow|sla)\b/u',$normalized)){
        $rows=array_values(array_filter($tickets,static fn($row)=>!empty($row['late'])));
        $parts=array_map('kda_ticket_label',array_slice($rows,0,8));
        $answer=$rows?count($rows).' late kitchen ticket'.(count($rows)===1?'':'s').': '.implode('; ',$parts).'.':'No kitchen tickets are currently beyond their station target.';
        app_json_response(['ok'=>true,'skill'=>'kds.late','answer'=>$answer,'data'=>['count'=>count($rows),'tickets'=>array_slice($rows,0,12)],'sources'=>['Kitchen Display → Live Board']]);
    }

    if(preg_match('/\b(?:ready|ready to bump|pickup|expo)\b/u',$normalized)){
        $rows=array_values(array_filter($tickets,static fn($row)=>!empty($row['readyToBump'])||((int)($row['ready']??0)>0)));
        $parts=array_map('kda_ticket_label',array_slice($rows,0,8));
        $answer=$rows?count($rows).' ticket'.(count($rows)===1?'':'s').' have ready items: '.implode('; ',$parts).'.':'No active kitchen tickets currently have ready items.';
        app_json_response(['ok'=>true,'skill'=>'kds.ready','answer'=>$answer,'data'=>['count'=>count($rows),'tickets'=>array_slice($rows,0,12)],'sources'=>['Kitchen Display → Expo']]);
    }

    if(preg_match('/\b(?:held|on hold|holds?)\b/u',$normalized)){
        $rows=array_values(array_filter($tickets,static fn($row)=>(int)($row['held']??0)>0));
        $parts=[];foreach(array_slice($rows,0,10) as $row)$parts[]=kda_ticket_label($row).' with '.(int)$row['held'].' held item'.((int)$row['held']===1?'':'s');
        $answer=$rows?'Held kitchen work: '.implode('; ',$parts).'.':'No active kitchen ticket has held items.';
        app_json_response(['ok'=>true,'skill'=>'kds.held','answer'=>$answer,'data'=>['count'=>count($rows),'tickets'=>array_slice($rows,0,12)],'sources'=>['Kitchen Display → Live Orders']]);
    }

    if(preg_match('/\b(?:unrouted|no station|missing route|routing)\b/u',$normalized)){
        $items=array_values(array_filter((array)$board['items'],static fn($item)=>$item['station_id']===null&&!in_array((string)$item['status'],['completed','cancelled'],true)));
        $parts=[];foreach(array_slice($items,0,10) as $item)$parts[]='order '.(string)$item['check_number'].', '.kda_item_phrase($item,false);
        $answer=$items?count($items).' active kitchen item'.(count($items)===1?' is':'s are').' unrouted: '.implode('; ',$parts).'.':'No active kitchen items are unrouted.';
        app_json_response(['ok'=>true,'skill'=>'kds.unrouted','answer'=>$answer,'data'=>['count'=>count($items),'items'=>array_slice($items,0,15)],'sources'=>['Kitchen Display → Routing']]);
    }

    if(preg_match('/\b(?:all day|all-day|production totals?|item counts?|how many)\b/u',$normalized)){
        $rows=(array)$board['allDay'];$parts=[];
        foreach(array_slice($rows,0,20) as $row)$parts[]=(string)$row['name'].' '.kda_qty((float)$row['quantity']).' active: '.kda_qty((float)$row['queued']).' queued, '.kda_qty((float)$row['inProgress']).' cooking, '.kda_qty((float)$row['ready']).' ready';
        $answer=$rows?'All Day active fire: '.implode('; ',$parts).'.':'There are no active fired items in the kitchen.';
        app_json_response(['ok'=>true,'skill'=>'kds.all_day','answer'=>$answer,'data'=>array_slice($rows,0,30),'sources'=>['Kitchen Display → All Day']]);
    }

    if(preg_match('/\b(?:station|stations|load|busy|busiest)\b/u',$normalized)){
        $parts=[];
        foreach((array)$board['stations'] as $row){$count=(int)($board['stationCounts'][(string)$row['public_id']]??0);$parts[]=(string)$row['name'].' '.$count.' active';}
        if((int)($board['stationCounts']['unrouted']??0)>0)$parts[]='Unrouted '.(int)$board['stationCounts']['unrouted'];
        app_json_response(['ok'=>true,'skill'=>'kds.stations','answer'=>$parts?'Station load: '.implode('; ',$parts).'.':'No active kitchen stations are configured.','data'=>['stationCounts'=>$board['stationCounts']],'sources'=>['Kitchen Display → Stations']]);
    }

    if(preg_match('/\b(?:next|oldest|what should.*work|priority order)\b/u',$normalized)){
        $ordered=$tickets;usort($ordered,static fn($a,$b)=>kda_ticket_age($b)<=>kda_ticket_age($a));$next=$ordered[0]??null;
        app_json_response(['ok'=>true,'skill'=>'kds.next_order','answer'=>$next?'Oldest active kitchen order: '.kda_ticket_detail($next,true):'There are no active kitchen orders right now.','data'=>['ticket'=>$next],'sources'=>['Kitchen Display → Live Orders']]);
    }

    $location=(array)$board['location'];
    $answer=(string)$location['name'].' kitchen: '.(int)$metrics['tickets'].' active ticket'.((int)$metrics['tickets']===1?'':'s').', '.(int)$metrics['queued'].' queued, '.(int)$metrics['inProgress'].' in progress, '.(int)$metrics['ready'].' ready, '.(int)$metrics['held'].' held, '.(int)$metrics['late'].' late, '.(int)$metrics['unrouted'].' unrouted, and '.(int)$metrics['readyTickets'].' ready-to-bump ticket'.((int)$metrics['readyTickets']===1?'':'s').'. Ask for any order number, table, special instructions, All Day item count, station load, or recent completed order.';
    app_json_response(['ok'=>true,'skill'=>'kds.snapshot','answer'=>$answer,'data'=>['location'=>$location,'metrics'=>$metrics,'stationCounts'=>$board['stationCounts'],'tickets'=>$tickets],'sources'=>['Kitchen Display → Live Board']]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
