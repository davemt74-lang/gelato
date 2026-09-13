<?php
declare(strict_types=1);

require_once __DIR__.'/sales-intelligence-core.php';
require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/prep-intelligence-core.php';
require_once __DIR__.'/purchasing-core.php';
require_once __DIR__.'/employee-shift-communications.php';

function manager_brief_ready(PDO $pdo): bool
{
    foreach(['manager_daily_logs','manager_daily_exception_actions'] as $table){
        if(!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}

function manager_brief_date(string $date): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($date));
    if(!$d||$d->format('Y-m-d')!==trim($date)) throw new InvalidArgumentException('Business date is invalid.');
    return $d->format('Y-m-d');
}

function manager_brief_phase(string $phase): string
{
    $phase=mb_strtolower(trim($phase),'UTF-8');
    return in_array($phase,['opening','live','closing'],true)?$phase:'live';
}

function manager_brief_location(PDO $pdo,int $org,?int $locationId): array
{
    if(!$locationId) return ['id'=>null,'key'=>'all','name'=>'All locations'];
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
    $q->execute([$org,$locationId]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Restaurant location was not found.');
    return ['id'=>(int)$row['id'],'key'=>'id:'.(int)$row['id'],'name'=>(string)$row['name']];
}

function manager_brief_log(PDO $pdo,int $org,string $locationKey,string $date): ?array
{
    $q=$pdo->prepare("SELECT l.*,ou.display_name opening_acknowledged_by_name,cu.display_name closing_acknowledged_by_name FROM manager_daily_logs l LEFT JOIN users ou ON ou.id=l.opening_acknowledged_by LEFT JOIN users cu ON cu.id=l.closing_acknowledged_by WHERE l.organization_id=? AND l.location_key=? AND l.business_date=? LIMIT 1");
    $q->execute([$org,$locationKey,$date]);$row=$q->fetch();return $row?:null;
}

function manager_brief_log_get_or_create(PDO $pdo,int $org,array $location,string $date,int $userId): array
{
    $pdo->prepare("INSERT INTO manager_daily_logs (organization_id,location_id,location_key,business_date,created_by,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE updated_by=VALUES(updated_by)")
        ->execute([$org,$location['id'],$location['key'],$date,$userId,$userId]);
    return manager_brief_log($pdo,$org,(string)$location['key'],$date)??throw new RuntimeException('Manager daily log could not be created.');
}

function manager_brief_save_phase(PDO $pdo,int $org,array $location,string $date,string $phase,string $note,bool $acknowledge,int $userId): array
{
    if(!in_array($phase,['opening','closing'],true)) throw new InvalidArgumentException('Manager note phase must be opening or closing.');
    $log=manager_brief_log_get_or_create($pdo,$org,$location,$date,$userId);$note=mb_substr(trim($note),0,10000,'UTF-8');
    if($phase==='opening'){
        $pdo->prepare("UPDATE manager_daily_logs SET opening_note=?,opening_acknowledged_at=IF(?,NOW(6),opening_acknowledged_at),opening_acknowledged_by=IF(?, ?, opening_acknowledged_by),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$note?:null,$acknowledge?1:0,$acknowledge?1:0,$userId,$userId,$org,(int)$log['id']]);
    }else{
        $pdo->prepare("UPDATE manager_daily_logs SET closing_note=?,closing_acknowledged_at=IF(?,NOW(6),closing_acknowledged_at),closing_acknowledged_by=IF(?, ?, closing_acknowledged_by),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$note?:null,$acknowledge?1:0,$acknowledge?1:0,$userId,$userId,$org,(int)$log['id']]);
    }
    return manager_brief_log($pdo,$org,(string)$location['key'],$date)??[];
}

function manager_brief_exception_key(string $type,string $subject): string
{
    return $type.'-'.substr(hash('sha256',$type.'|'.$subject),0,40);
}

function manager_brief_ack_exception(PDO $pdo,int $org,array $location,string $date,string $key,string $note,int $userId): array
{
    if(!preg_match('/^[a-z0-9_-]{8,96}$/',$key)) throw new InvalidArgumentException('Operating exception key is invalid.');
    $log=manager_brief_log_get_or_create($pdo,$org,$location,$date,$userId);$note=mb_substr(trim($note),0,1200,'UTF-8');
    $pdo->prepare("INSERT INTO manager_daily_exception_actions (organization_id,manager_daily_log_id,exception_key,note,actor_user_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note),actor_user_id=VALUES(actor_user_id),acknowledged_at=NOW(6)")
        ->execute([$org,(int)$log['id'],$key,$note?:null,$userId]);
    return manager_brief_acknowledgements($pdo,$org,(int)$log['id']);
}

function manager_brief_acknowledgements(PDO $pdo,int $org,int $logId): array
{
    $q=$pdo->prepare("SELECT a.exception_key,a.note,a.acknowledged_at,u.display_name actor_name FROM manager_daily_exception_actions a JOIN users u ON u.id=a.actor_user_id WHERE a.organization_id=? AND a.manager_daily_log_id=? ORDER BY a.acknowledged_at DESC");
    $q->execute([$org,$logId]);$out=[];foreach($q->fetchAll() as $r)$out[(string)$r['exception_key']]=['note'=>$r['note'],'acknowledgedAt'=>$r['acknowledged_at'],'actorName'=>$r['actor_name']];return $out;
}

function manager_brief_sales(PDO $pdo,int $org,string $date,?int $locationId): array
{
    if(!sales_intelligence_ready($pdo)) return ['status'=>'unavailable','reason'=>'Sales Intelligence is not installed.','actual'=>null,'forecast'=>null];
    $actual=null;$forecast=null;
    try{$d=sales_dashboard($pdo,$org,$date,$date,$locationId);$actual=$d['totals']??null;}catch(Throwable $e){return ['status'=>'unknown','reason'=>$e->getMessage(),'actual'=>null,'forecast'=>null];}
    try{
        $provider=sales_primary_provider($pdo,$org);$sql="SELECT public_id,service_period,projected_net_sales,projected_tickets,projected_covers,projected_labor_hours,scheduled_labor_hours,confidence,sample_count FROM sales_forecasts WHERE organization_id=? AND source_provider=? AND forecast_date=? AND service_period='all'";$args=[$org,$provider,$date];
        if($locationId){$sql.=' AND location_id=?';$args[]=$locationId;}else{$sql.=' AND location_id IS NULL';}
        $sql.=' ORDER BY id DESC LIMIT 1';$q=$pdo->prepare($sql);$q->execute($args);$forecast=$q->fetch()?:null;
    }catch(Throwable){}
    return ['status'=>'ready','reason'=>null,'actual'=>$actual,'forecast'=>$forecast];
}

function manager_brief_pos(PDO $pdo,int $org,string $date,?int $locationId): array
{
    if(!function_exists('pos_ready')||!pos_ready($pdo)) return ['status'=>'unavailable','openChecks'=>0,'paidChecks'=>0,'paidSales'=>0.0];
    $where='organization_id=? AND business_date=?';$args=[$org,$date];if($locationId){$where.=' AND location_id=?';$args[]=$locationId;}
    $q=$pdo->prepare("SELECT SUM(status='open') open_checks,SUM(status='paid') paid_checks,COALESCE(SUM(CASE WHEN status='paid' THEN GREATEST(0,total_amount-tip_amount) ELSE 0 END),0) paid_sales,COALESCE(SUM(CASE WHEN status='paid' THEN guest_count ELSE 0 END),0) paid_covers FROM pos_checks WHERE {$where}");$q->execute($args);$r=$q->fetch()?:[];
    return ['status'=>'ready','openChecks'=>(int)($r['open_checks']??0),'paidChecks'=>(int)($r['paid_checks']??0),'paidSales'=>round((float)($r['paid_sales']??0),2),'paidCovers'=>(int)($r['paid_covers']??0)];
}

function manager_brief_labor(PDO $pdo,int $org,string $date,?int $locationId): array
{
    if(!restaurant_brain_table_ready($pdo,'schedule_shifts')) return ['status'=>'unavailable','shiftCount'=>0,'unassignedShifts'=>0,'scheduledHours'=>0.0,'pendingRequests'=>0];
    $start=$date.' 00:00:00';$end=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    $sql="SELECT COUNT(*) shift_count,SUM(s.user_id IS NULL) unassigned,COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,GREATEST(s.starts_at,?),LEAST(s.ends_at,?))-s.break_minutes))/60,0) scheduled_hours FROM schedule_shifts s JOIN schedule_weeks w ON w.id=s.schedule_week_id AND w.organization_id=s.organization_id AND w.status IN ('published','locked') WHERE s.organization_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.starts_at<? AND s.ends_at>?";$args=[$start,$end,$org,$end,$start];if($locationId){$sql.=' AND s.location_id=?';$args[]=$locationId;}$q=$pdo->prepare($sql);$q->execute($args);$r=$q->fetch()?:[];
    $requestSql="SELECT COUNT(*) FROM schedule_shift_requests r JOIN schedule_shifts s ON s.id=r.shift_id AND s.organization_id=r.organization_id WHERE r.organization_id=? AND r.status='pending' AND s.starts_at<? AND s.ends_at>?";$requestArgs=[$org,$end,$start];if($locationId){$requestSql.=' AND s.location_id=?';$requestArgs[]=$locationId;}$q=$pdo->prepare($requestSql);$q->execute($requestArgs);
    return ['status'=>'ready','shiftCount'=>(int)($r['shift_count']??0),'unassignedShifts'=>(int)($r['unassigned']??0),'scheduledHours'=>round((float)($r['scheduled_hours']??0),2),'pendingRequests'=>(int)$q->fetchColumn()];
}

function manager_brief_tasks(PDO $pdo,int $org,string $date): array
{
    if(!restaurant_brain_table_ready($pdo,'restaurant_tasks')) return ['status'=>'unavailable','open'=>0,'blocked'=>0,'critical'=>0,'overdue'=>0,'items'=>[]];
    $q=$pdo->prepare("SELECT t.public_id,t.title,t.priority,t.status,t.due_at,t.station,c.slug category FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id AND c.organization_id=t.organization_id WHERE t.organization_id=? AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled') AND c.slug IN ('opening','prep','catering','closing','manager','maintenance','inventory') AND (DATE(COALESCE(t.due_at,t.created_at))=? OR (t.due_at<? AND t.status NOT IN ('completed','verified','cancelled'))) ORDER BY (t.status='blocked') DESC,FIELD(t.priority,'critical','high','normal','low'),COALESCE(t.due_at,t.created_at),t.title LIMIT 40");
    $dayEnd=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');$q->execute([$org,$date,$dayEnd]);$items=$q->fetchAll();$now=time();$open=count($items);$blocked=0;$critical=0;$overdue=0;
    foreach($items as &$r){$r['isOverdue']=!empty($r['due_at'])&&strtotime((string)$r['due_at'])<$now&&$date<=date('Y-m-d');if($r['status']==='blocked')$blocked++;if($r['priority']==='critical')$critical++;if($r['isOverdue'])$overdue++;}unset($r);
    return ['status'=>'ready','open'=>$open,'blocked'=>$blocked,'critical'=>$critical,'overdue'=>$overdue,'items'=>$items];
}

function manager_brief_inventory(PDO $pdo,int $org): array
{
    if(!restaurant_brain_table_ready($pdo,'inventory_items')) return ['status'=>'unavailable','lowCount'=>0,'items'=>[]];
    $q=$pdo->prepare("SELECT public_id,name,category,base_unit,on_hand_quantity,reorder_point,par_level,storage_location,last_counted_at FROM inventory_items WHERE organization_id=? AND status='active' AND archived_at IS NULL AND reorder_point IS NOT NULL AND on_hand_quantity<=reorder_point ORDER BY (on_hand_quantity<=0) DESC,(reorder_point-on_hand_quantity) DESC,name LIMIT 30");$q->execute([$org]);$items=$q->fetchAll();
    return ['status'=>'ready','lowCount'=>count($items),'items'=>$items];
}

function manager_brief_purchasing(PDO $pdo,int $org,string $date): array
{
    if(!purchasing_ready($pdo)) return ['status'=>'unavailable','dueToday'=>0,'overdue'=>0,'orders'=>[]];
    $q=$pdo->prepare("SELECT po.public_id,po.order_number,po.status,po.expected_delivery_date,po.total_amount,v.name vendor_name FROM purchase_orders po JOIN vendors v ON v.id=po.vendor_id AND v.organization_id=po.organization_id WHERE po.organization_id=? AND po.archived_at IS NULL AND po.status NOT IN ('received','cancelled','closed') AND po.expected_delivery_date IS NOT NULL AND po.expected_delivery_date<=? ORDER BY po.expected_delivery_date,po.id LIMIT 30");$q->execute([$org,$date]);$orders=$q->fetchAll();$due=0;$overdue=0;foreach($orders as &$r){$r['isOverdue']=(string)$r['expected_delivery_date']<$date;if($r['isOverdue'])$overdue++;else$due++;}unset($r);
    return ['status'=>'ready','dueToday'=>$due,'overdue'=>$overdue,'orders'=>$orders];
}

function manager_brief_handoffs(PDO $pdo,int $org,string $date,?int $locationId): array
{
    if(!employee_shift_comms_ready($pdo)) return ['status'=>'unavailable','open'=>0,'highPriority'=>0,'items'=>[]];
    $start=$date.' 00:00:00';$end=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');$sql="SELECT m.public_id,m.message_type,m.title,m.body,m.priority,m.station,m.location_id,m.effective_from,m.effective_until,m.created_at,u.display_name created_by_name,l.name location_name FROM employee_shift_messages m LEFT JOIN users u ON u.id=m.created_by LEFT JOIN locations l ON l.id=m.location_id WHERE m.organization_id=? AND m.archived_at IS NULL AND m.status='open' AND m.effective_from<? AND (m.effective_until IS NULL OR m.effective_until>=?)";$args=[$org,$end,$start];if($locationId){$sql.=' AND (m.location_id IS NULL OR m.location_id=?)';$args[]=$locationId;}$sql.=" ORDER BY FIELD(m.priority,'urgent','high','normal','low'),m.created_at DESC LIMIT 30";$q=$pdo->prepare($sql);$q->execute($args);$items=$q->fetchAll();$high=count(array_filter($items,static fn(array $r):bool=>in_array((string)$r['priority'],['urgent','high'],true)));
    return ['status'=>'ready','open'=>count($items),'highPriority'=>$high,'items'=>$items];
}

function manager_brief_crm(PDO $pdo,int $org,string $date,?int $locationId): array
{
    if(!restaurant_brain_table_ready($pdo,'crm_customers')||!restaurant_brain_table_ready($pdo,'pos_checks')) return ['status'=>'unavailable','linkedCustomers'=>0,'newCustomers'=>0,'returningCustomers'=>0];
    $loc=$locationId?' AND c.location_id=?':'';$args=[$date,$org,$date];if($locationId)$args[]=$locationId;
    $sql="SELECT COUNT(DISTINCT c.customer_id) linked_customers,COUNT(DISTINCT CASE WHEN firsts.first_date=? THEN c.customer_id END) new_customers FROM pos_checks c JOIN (SELECT organization_id,customer_id,MIN(business_date) first_date FROM pos_checks WHERE status='paid' AND customer_id IS NOT NULL GROUP BY organization_id,customer_id) firsts ON firsts.organization_id=c.organization_id AND firsts.customer_id=c.customer_id WHERE c.organization_id=? AND c.business_date=? AND c.status='paid' AND c.customer_id IS NOT NULL{$loc}";$q=$pdo->prepare($sql);$q->execute($args);$r=$q->fetch()?:[];$linked=(int)($r['linked_customers']??0);$new=(int)($r['new_customers']??0);
    return ['status'=>'ready','linkedCustomers'=>$linked,'newCustomers'=>$new,'returningCustomers'=>max(0,$linked-$new)];
}

function manager_brief_exception(string $type,string $subject,string $severity,string $title,string $detail,string $source,?string $href=null): array
{
    return ['key'=>manager_brief_exception_key($type,$subject),'type'=>$type,'severity'=>$severity,'title'=>$title,'detail'=>$detail,'source'=>$source,'href'=>$href,'acknowledged'=>false,'acknowledgement'=>null];
}

function manager_brief_build_exceptions(array $snapshot): array
{
    $e=[];$date=(string)$snapshot['businessDate'];$phase=(string)$snapshot['phase'];$loc=(string)$snapshot['location']['key'];
    foreach($snapshot['tasks']['items']??[] as $task){if(($task['status']??'')==='blocked')$e[]=manager_brief_exception('task-blocked',(string)$task['public_id'],'high','Blocked task: '.(string)$task['title'],'This operating task is blocked and still open.','tasks','operations.php');elseif(!empty($task['isOverdue']))$e[]=manager_brief_exception('task-overdue',(string)$task['public_id'],($task['priority']??'')==='critical'?'high':'medium','Overdue task: '.(string)$task['title'],'The task due time has passed and the task remains open.','tasks','operations.php');elseif(($task['priority']??'')==='critical')$e[]=manager_brief_exception('task-critical',(string)$task['public_id'],'high','Critical task: '.(string)$task['title'],'A critical operating task remains open.','tasks','operations.php');}
    foreach($snapshot['inventory']['items']??[] as $item){$severity=(float)$item['on_hand_quantity']<=0?'high':'medium';$e[]=manager_brief_exception('inventory-low',(string)$item['public_id'],$severity,'Low inventory: '.(string)$item['name'],'On hand '.rtrim(rtrim(number_format((float)$item['on_hand_quantity'],2,'.',''),'0'),'.').' '.(string)($item['base_unit']??'').' at/below reorder point.','inventory','operations.php');}
    foreach($snapshot['purchasing']['orders']??[] as $po){$e[]=manager_brief_exception(!empty($po['isOverdue'])?'po-overdue':'po-due',(string)$po['public_id'],!empty($po['isOverdue'])?'high':'medium',(!empty($po['isOverdue'])?'Overdue delivery: ':'Delivery due today: ').(string)$po['order_number'],(string)$po['vendor_name'].' · status '.(string)$po['status'].'.','purchasing','purchasing.php');}
    foreach($snapshot['handoffs']['items']??[] as $m){if(in_array((string)$m['priority'],['urgent','high'],true))$e[]=manager_brief_exception('handoff',(string)$m['public_id'],'high','Shift update: '.(string)$m['title'],mb_substr((string)$m['body'],0,260,'UTF-8'),'handoffs','employee-handoffs.php');}
    if(($snapshot['labor']['unassignedShifts']??0)>0)$e[]=manager_brief_exception('labor-unassigned',$date.'|'.$loc,'high','Unassigned shifts remain',(int)$snapshot['labor']['unassignedShifts'].' published shift(s) have no assigned employee.','labor','schedule.php');
    if(($snapshot['labor']['pendingRequests']??0)>0)$e[]=manager_brief_exception('labor-requests',$date.'|'.$loc,'medium','Pending shift requests',(int)$snapshot['labor']['pendingRequests'].' shift request(s) still need manager review.','labor','schedule.php');
    $f=$snapshot['sales']['forecast']??null;if($f&&$f['projected_labor_hours']!==null&&$f['scheduled_labor_hours']!==null){$gap=(float)$f['projected_labor_hours']-(float)$f['scheduled_labor_hours'];if($gap>=1)$e[]=manager_brief_exception('labor-forecast-gap',(string)$f['public_id'],$gap>=3?'high':'medium','Forecast labor coverage gap',number_format($gap,1).' fewer scheduled labor hour(s) than the current aggregate demand forecast.','sales','sales-intelligence.php');}
    if($phase==='closing'&&($snapshot['pos']['openChecks']??0)>0)$e[]=manager_brief_exception('pos-open-closing',$date.'|'.$loc,'high','Open POS checks at closing',(int)$snapshot['pos']['openChecks'].' check(s) are still open for this business date.','pos','pos.php');
    return $e;
}

function manager_brief_narrative(array $s): string
{
    $phase=ucfirst((string)$s['phase']);$parts=[];$actual=$s['sales']['actual']??null;$forecast=$s['sales']['forecast']??null;
    if($actual&&((float)($actual['netSales']??0)>0||(int)($actual['tickets']??0)>0))$parts[]='Recorded net sales are $'.number_format((float)$actual['netSales'],2).' across '.(int)$actual['tickets'].' ticket(s) and '.(int)$actual['covers'].' cover(s).';elseif($forecast)$parts[]='Current demand forecast is $'.number_format((float)$forecast['projected_net_sales'],2).' net sales and '.(int)round((float)$forecast['projected_covers']).' covers.';else$parts[]='Sales or forecast data is not available for this date yet.';
    if(($s['labor']['status']??'')==='ready')$parts[]='Published schedule contains '.(int)$s['labor']['shiftCount'].' shift(s), '.number_format((float)$s['labor']['scheduledHours'],1).' scheduled hour(s), and '.(int)$s['labor']['unassignedShifts'].' unassigned shift(s).';
    $high=count(array_filter($s['exceptions']??[],static fn(array $e):bool=>$e['severity']==='high'&&!$e['acknowledged']));$medium=count(array_filter($s['exceptions']??[],static fn(array $e):bool=>$e['severity']==='medium'&&!$e['acknowledged']));$parts[]=$high||$medium?"{$high} high and {$medium} medium unacknowledged operating exception(s) need review.":'No unacknowledged high or medium operating exceptions are currently recorded.';
    return $phase.' brief: '.implode(' ',$parts);
}

function manager_brief_snapshot(PDO $pdo,int $org,string $date,?int $locationId,string $phase='live',?int $userId=null): array
{
    if(!manager_brief_ready($pdo))throw new RuntimeException('Daily Manager Brief migration is not installed. Run upgrade.php.');$date=manager_brief_date($date);$phase=manager_brief_phase($phase);$location=manager_brief_location($pdo,$org,$locationId);
    $snapshot=['businessDate'=>$date,'phase'=>$phase,'location'=>$location,'generatedAt'=>(new DateTimeImmutable())->format('Y-m-d H:i:s'),'sales'=>manager_brief_sales($pdo,$org,$date,$location['id']),'pos'=>manager_brief_pos($pdo,$org,$date,$location['id']),'labor'=>manager_brief_labor($pdo,$org,$date,$location['id']),'tasks'=>manager_brief_tasks($pdo,$org,$date),'inventory'=>manager_brief_inventory($pdo,$org),'purchasing'=>manager_brief_purchasing($pdo,$org,$date),'handoffs'=>manager_brief_handoffs($pdo,$org,$date,$location['id']),'crm'=>manager_brief_crm($pdo,$org,$date,$location['id'])];
    $log=manager_brief_log($pdo,$org,(string)$location['key'],$date);$acks=$log?manager_brief_acknowledgements($pdo,$org,(int)$log['id']):[];$snapshot['exceptions']=manager_brief_build_exceptions($snapshot);foreach($snapshot['exceptions'] as &$e){if(isset($acks[$e['key']])){$e['acknowledged']=true;$e['acknowledgement']=$acks[$e['key']];}}unset($e);$snapshot['log']=$log;$snapshot['summary']=manager_brief_narrative($snapshot);$snapshot['sourceStatus']=['sales'=>$snapshot['sales']['status'],'pos'=>$snapshot['pos']['status'],'labor'=>$snapshot['labor']['status'],'tasks'=>$snapshot['tasks']['status'],'inventory'=>$snapshot['inventory']['status'],'purchasing'=>$snapshot['purchasing']['status'],'handoffs'=>$snapshot['handoffs']['status'],'crm'=>$snapshot['crm']['status']];
    return $snapshot;
}
