<?php
declare(strict_types=1);

require_once __DIR__.'/food-monster-vp3-link.php';
require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/pickup-fulfillment-core.php';

function fm_vp3_open_orders(PDO $pdo,int $org,int $location,int $limit,DateTimeImmutable $now): array
{
    if(!pickup_fulfillment_ready($pdo))throw new RuntimeException('Pickup fulfillment is unavailable.');
    // Online pickup stores its promised time in the pickup location's zone.
    // Native POS uses the organization's business timezone for its own checks.
    $q=$pdo->prepare('SELECT timezone FROM locations WHERE organization_id=? AND id=?');
    $q->execute([$org,$location]);
    $configured=trim((string)$q->fetchColumn());
    try{$pickupZone=$configured!==''?new DateTimeZone($configured):$now->getTimezone();}
    catch(Exception){$pickupZone=new DateTimeZone('America/Phoenix');}
    $rows=pickup_fulfillment_queue($pdo,$org,$location,'active',$limit+1);
    $orders=[];
    foreach(array_slice($rows,0,$limit) as $row){
        $requested=$row['requested_ready_at']!==null
            ? new DateTimeImmutable((string)$row['requested_ready_at'],$pickupZone)
            : null;
        $orders[]=[
            'orderId'=>(string)$row['order_public_id'],
            'checkId'=>(string)$row['check_public_id'],
            'checkNumber'=>(string)$row['check_number'],
            'status'=>(string)$row['fulfillmentState'],
            'paymentStatus'=>$row['paymentComplete']?'paid':'due',
            'requestedReadyAt'=>$requested?->format(DATE_ATOM),
            'pastPromise'=>$requested!==null&&$requested<$now,
        ];
    }
    return ['orders'=>$orders,'truncated'=>count($rows)>$limit];
}

function fm_vp3_kitchen_summary(PDO $pdo,int $org,int $location,array $checkIds): array
{
    $marks=implode(',',array_fill(0,count($checkIds),'?'));
    $q=$pdo->prepare("SELECT k.check_id,
        SUM(k.status='queued') queued,SUM(k.status='in_progress') in_progress,
        SUM(k.status='ready') ready,SUM(k.status='held') held,
        SUM(k.status='completed') completed,SUM(k.status='cancelled') cancelled,
        SUM(k.status NOT IN ('completed','cancelled') AND s.id IS NULL) unrouted,
        MAX(k.status IN ('queued','in_progress','ready') AND COALESCE(s.target_seconds,0)>0
            AND TIMESTAMPDIFF(SECOND,COALESCE(k.fired_at,k.sent_at),NOW(6))>s.target_seconds) late
        FROM kds_order_items k
        LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id
            AND s.location_id=k.location_id
        WHERE k.organization_id=? AND k.location_id=? AND k.check_id IN ({$marks}) GROUP BY k.check_id");
    $q->execute(array_merge([$org,$location],$checkIds));
    $counts=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$counts[(int)$row['check_id']]=$row;

    $q=$pdo->prepare("SELECT i.check_id,COUNT(*) unsent FROM pos_check_items i
        LEFT JOIN kds_order_items k ON k.pos_check_item_id=i.id AND k.organization_id=i.organization_id
            AND k.location_id=?
        WHERE i.organization_id=? AND i.check_id IN ({$marks}) AND i.status='active' AND k.id IS NULL
        GROUP BY i.check_id");
    $q->execute(array_merge([$location,$org],$checkIds));
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$counts[(int)$row['check_id']]['unsent']=$row['unsent'];
    return $counts;
}

function fm_vp3_open_tickets(PDO $pdo,int $org,int $location,int $limit,DateTimeImmutable $now): array
{
    $fetchLimit=$limit+1;
    $q=$pdo->prepare("SELECT c.id,c.public_id,c.check_number,c.service_mode,c.opened_at,
        (SELECT t.name FROM service_tables t WHERE t.organization_id=c.organization_id
            AND t.location_id=c.location_id AND t.active_check_id=c.id ORDER BY t.id LIMIT 1) table_name
        FROM pos_checks c WHERE c.organization_id=? AND c.location_id=? AND c.status='open'
        ORDER BY c.opened_at,c.id LIMIT {$fetchLimit}");
    $q->execute([$org,$location]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $checks=array_slice($rows,0,$limit);
    if(!$checks)return ['tickets'=>[],'truncated'=>false];
    $counts=fm_vp3_kitchen_summary($pdo,$org,$location,array_map('intval',array_column($checks,'id')));
    $tickets=[];
    foreach($checks as $check){
        $opened=new DateTimeImmutable((string)$check['opened_at'],$now->getTimezone());
        $kds=$counts[(int)$check['id']]??[];
        $kitchen=[
            'queued'=>(int)($kds['queued']??0),'inProgress'=>(int)($kds['in_progress']??0),
            'ready'=>(int)($kds['ready']??0),'held'=>(int)($kds['held']??0),
            'completed'=>(int)($kds['completed']??0),'cancelled'=>(int)($kds['cancelled']??0),
            'unrouted'=>(int)($kds['unrouted']??0),'unsent'=>(int)($kds['unsent']??0),
            'late'=>(bool)($kds['late']??false),
        ];
        $kitchen['status']=match(true){
            $kitchen['inProgress']>0=>'in_progress',
            $kitchen['queued']>0=>'queued',
            $kitchen['held']>0=>'held',
            $kitchen['unsent']>0&&($kitchen['ready']>0||$kitchen['completed']>0)=>'partially_sent',
            $kitchen['ready']>0=>'ready',
            $kitchen['completed']>0=>'completed',
            $kitchen['cancelled']>0&&$kitchen['unsent']===0=>'cancelled',
            default=>'not_sent',
        };
        $serviceMode=pos_order_type((string)$check['service_mode'])?:'dine_in';
        $tickets[]=[
            'checkId'=>(string)$check['public_id'],'checkNumber'=>(string)$check['check_number'],
            'serviceMode'=>$serviceMode,'tableName'=>$serviceMode==='dine_in'?$check['table_name']:null,
            'openedAt'=>$opened->format(DATE_ATOM),'ageSeconds'=>max(0,$now->getTimestamp()-$opened->getTimestamp()),
            'kitchen'=>$kitchen,
        ];
    }
    return ['tickets'=>$tickets,'truncated'=>count($rows)>$limit];
}

function fm_vp3_sales_currency(PDO $pdo,int $org,int $location,string $day): array
{
    $q=$pdo->prepare("SELECT COUNT(DISTINCT currency) variants,MIN(currency) currency FROM pos_checks
        WHERE organization_id=? AND location_id=? AND business_date=? AND status='paid'");
    $q->execute([$org,$location,$day]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return [
        'mixed'=>(int)($row['variants']??0)>1,
        'currency'=>preg_match('/^[A-Z]{3}$/D',(string)($row['currency']??''))===1?$row['currency']:null,
    ];
}

function fm_vp3_daily_sales(PDO $pdo,int $org,int $location,string $day): array
{
    $provider=sales_primary_provider($pdo,$org);
    $q=$pdo->prepare("SELECT tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,
        comps_amount,voids_amount,refunds_amount,service_charges_amount,UNIX_TIMESTAMP(updated_at) updated_epoch
        FROM sales_periods WHERE organization_id=? AND location_id=? AND location_key=?
            AND source_provider=? AND granularity='daily' AND service_period='all' AND period_start=? AND period_end=?
        LIMIT 1");
    $q->execute([$org,$location,'id:'.$location,$provider,$day,$day]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    $data=['businessDate'=>$day,'available'=>false,'provider'=>$provider,'currency'=>null,
        'dataUpdatedAt'=>null,'totals'=>null];
    if(!$row)return $data;
    if($provider==='gelato_pos'){
        $currency=fm_vp3_sales_currency($pdo,$org,$location,$day);
        if($currency['mixed'])return $data;
        $data['currency']=$currency['currency'];
    }
    $data['available']=true;
    $data['dataUpdatedAt']=$row['updated_epoch']!==null?gmdate('Y-m-d\TH:i:s\Z',(int)$row['updated_epoch']):null;
    $data['totals']=[
        'tickets'=>(int)$row['tickets'],'covers'=>(int)$row['covers'],
        'grossSales'=>(string)$row['gross_sales'],'netSales'=>(string)$row['net_sales'],
        'discounts'=>(string)$row['discounts_amount'],'comps'=>(string)$row['comps_amount'],
        'voids'=>(string)$row['voids_amount'],'refunds'=>(string)$row['refunds_amount'],
        'tax'=>(string)$row['tax_amount'],'tips'=>(string)$row['tips_amount'],
        'serviceCharges'=>(string)$row['service_charges_amount'],
    ];
    return $data;
}

function fm_vp3_operation(PDO $pdo,array $grant,string $action,array $input): array
{
    if(!in_array($action,FM_VP3_OPERATION_SCOPES,true)||!in_array($action,$grant['scopes'],true))
        throw new DomainException('The connection lacks this read permission.');
    $locationId=$input['locationId']??null;
    if(!is_int($locationId)||$locationId<1)throw new InvalidArgumentException('Specify one approved location.');
    if($locationId!==$grant['locationId'])throw new DomainException('The location is outside this connection.');
    $org=(int)$grant['user']['organization_id'];
    $location=fm_vp3_location($pdo,$org,$locationId);
    $now=new DateTimeImmutable('now',new DateTimeZone($location['timezone']));
    if($action==='sales.daily.read'){
        $day=$input['businessDate']??$now->format('Y-m-d');
        $parsed=is_string($day)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$day)
            ? DateTimeImmutable::createFromFormat('!Y-m-d',$day) : false;
        if(!$parsed||$parsed->format('Y-m-d')!==$day)
            throw new InvalidArgumentException('Use a valid business date in YYYY-MM-DD format.');
        $data=fm_vp3_daily_sales($pdo,$org,$locationId,$day);
        $source=['label'=>'Sales Intelligence','path'=>'/sales-intelligence.php'];
    }else{
        $limit=$input['limit']??20;
        if(!is_int($limit)||$limit<1||$limit>50)throw new InvalidArgumentException('Limit must be between 1 and 50.');
        if($action==='orders.read'){
            $data=fm_vp3_open_orders($pdo,$org,$locationId,$limit,$now);
            $source=['label'=>'Pickup Fulfillment','path'=>'/pickup-fulfillment.php'];
        }else{
            $data=fm_vp3_open_tickets($pdo,$org,$locationId,$limit,$now);
            $source=['label'=>'Point of Sale','path'=>'/pos.php'];
        }
    }
    return [
        'ok'=>true,'schema'=>'food-monster-vp3-operations-v1','instanceId'=>fm_kb2_instance($pdo),
        'connectionId'=>$grant['connectionId'],'agentName'=>food_monster_agent_name(),'capability'=>$action,
        'location'=>$location,'asOf'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>$source,'data'=>$data,
    ];
}
