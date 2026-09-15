<?php
declare(strict_types=1);

require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/kds-core.php';
require_once __DIR__.'/kds-production.php';
require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/customer-inbox-core.php';
require_once __DIR__.'/catering-operations.php';
require_once __DIR__.'/wholesale-portal.php';
require_once __DIR__.'/wholesale-acquisition.php';
require_once __DIR__.'/wholesale-receivables.php';
require_once __DIR__.'/admin-control-core.php';

function admin_dashboard_table_ready(PDO $pdo,string $table): bool
{
    try{
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}

function admin_dashboard_allowed(array $user): bool
{
    return admin_control_allowed($user);
}

function admin_dashboard_can(array $user,string ...$permissions): bool
{
    foreach($permissions as $permission)if(app_has_permission($permission,$user))return true;
    return false;
}

function admin_dashboard_timezone(PDO $pdo,int $organizationId): DateTimeZone
{
    try{
        $q=$pdo->prepare('SELECT timezone FROM organizations WHERE id=? LIMIT 1');
        $q->execute([$organizationId]);
        $name=trim((string)$q->fetchColumn());
        return new DateTimeZone($name!==''?$name:'America/Phoenix');
    }catch(Throwable){return new DateTimeZone('America/Phoenix');}
}

function admin_dashboard_periods(PDO $pdo,int $organizationId): array
{
    $now=new DateTimeImmutable('now',admin_dashboard_timezone($pdo,$organizationId));
    return [
        'today'=>$now->format('Y-m-d'),
        'weekStart'=>$now->modify('monday this week')->format('Y-m-d'),
        'monthStart'=>$now->modify('first day of this month')->format('Y-m-d'),
        'now'=>$now->format(DATE_ATOM),
    ];
}

function admin_dashboard_locations(PDO $pdo,int $organizationId): array
{
    try{return pos_locations($pdo,$organizationId);}catch(Throwable){return [];}
}

function admin_dashboard_location(PDO $pdo,int $organizationId,int $locationId): ?array
{
    if($locationId<1)return null;
    foreach(admin_dashboard_locations($pdo,$organizationId) as $location){
        if((int)$location['id']===$locationId)return $location;
    }
    return null;
}

function admin_dashboard_pos_rollup(PDO $pdo,int $organizationId,array $periods,?int $locationId=null): array
{
    $zero=['salesToday'=>0.0,'salesWeek'=>0.0,'salesMonth'=>0.0,'ticketsToday'=>0,'coversToday'=>0,'avgCheckToday'=>0.0,'activeTickets'=>0,'openValue'=>0.0];
    if(!admin_dashboard_table_ready($pdo,'pos_checks'))return $zero;
    $locationSql=$locationId?' AND location_id=?':'';
    $sql="SELECT
      COALESCE(SUM(CASE WHEN status='paid' AND business_date=? THEN subtotal-discount_amount+tax_amount+service_charge_amount ELSE 0 END),0) sales_today,
      COALESCE(SUM(CASE WHEN status='paid' AND business_date>=? THEN subtotal-discount_amount+tax_amount+service_charge_amount ELSE 0 END),0) sales_week,
      COALESCE(SUM(CASE WHEN status='paid' AND business_date>=? THEN subtotal-discount_amount+tax_amount+service_charge_amount ELSE 0 END),0) sales_month,
      SUM(status='paid' AND business_date=?) tickets_today,
      COALESCE(SUM(CASE WHEN status='paid' AND business_date=? THEN guest_count ELSE 0 END),0) covers_today,
      SUM(status='open') active_tickets,
      COALESCE(SUM(CASE WHEN status='open' THEN subtotal-discount_amount+tax_amount+service_charge_amount ELSE 0 END),0) open_value
      FROM pos_checks WHERE organization_id=?{$locationSql}";
    $bind=[$periods['today'],$periods['weekStart'],$periods['monthStart'],$periods['today'],$periods['today'],$organizationId];
    if($locationId)$bind[]=$locationId;
    try{
        $q=$pdo->prepare($sql);$q->execute($bind);$r=$q->fetch()?:[];
        $tickets=(int)($r['tickets_today']??0);$sales=(float)($r['sales_today']??0);
        return [
            'salesToday'=>round($sales,2),'salesWeek'=>round((float)($r['sales_week']??0),2),'salesMonth'=>round((float)($r['sales_month']??0),2),
            'ticketsToday'=>$tickets,'coversToday'=>(int)($r['covers_today']??0),'avgCheckToday'=>$tickets?round($sales/$tickets,2):0.0,
            'activeTickets'=>(int)($r['active_tickets']??0),'openValue'=>round((float)($r['open_value']??0),2),
        ];
    }catch(Throwable){return $zero;}
}

function admin_dashboard_active_checks(PDO $pdo,int $organizationId,?int $locationId=null,int $limit=12): array
{
    if(!admin_dashboard_table_ready($pdo,'pos_checks'))return [];
    $limit=max(1,min(30,$limit));
    $sql="SELECT c.public_id,c.check_number,c.location_id,l.name location_name,c.service_mode,c.table_name,c.guest_count,c.subtotal,c.discount_amount,c.tax_amount,c.service_charge_amount,c.opened_at,
                 TIMESTAMPDIFF(MINUTE,c.opened_at,NOW()) age_minutes
          FROM pos_checks c JOIN locations l ON l.id=c.location_id AND l.organization_id=c.organization_id
          WHERE c.organization_id=? AND c.status='open'";
    $args=[$organizationId];if($locationId){$sql.=' AND c.location_id=?';$args[]=$locationId;}
    $sql.=" ORDER BY c.opened_at ASC,c.id ASC LIMIT {$limit}";
    try{
        $q=$pdo->prepare($sql);$q->execute($args);
        return array_map(static fn(array $r):array=>[
            'id'=>(string)$r['public_id'],'number'=>(string)$r['check_number'],'locationId'=>(int)$r['location_id'],'locationName'=>(string)$r['location_name'],
            'serviceMode'=>(string)$r['service_mode'],'tableName'=>$r['table_name'],'guestCount'=>(int)$r['guest_count'],
            'value'=>round((float)$r['subtotal']-(float)$r['discount_amount']+(float)$r['tax_amount']+(float)$r['service_charge_amount'],2),
            'openedAt'=>(string)$r['opened_at'],'ageMinutes'=>max(0,(int)$r['age_minutes']),
        ],$q->fetchAll());
    }catch(Throwable){return [];}
}

function admin_dashboard_active_tables(PDO $pdo,int $organizationId,?int $locationId=null): int
{
    if(!admin_dashboard_table_ready($pdo,'service_tables'))return 0;
    $sql="SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND status='active' AND (active_check_id IS NOT NULL OR state IN ('seated','ordering','ordered','check_presented'))";
    $args=[$organizationId];if($locationId){$sql.=' AND location_id=?';$args[]=$locationId;}
    try{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}catch(Throwable){return 0;}
}

function admin_dashboard_ready_tickets(PDO $pdo,int $organizationId,array $locations,?int $locationId=null): int
{
    if(!kds_ready($pdo))return 0;
    $count=0;
    foreach($locations as $location){
        $id=(int)$location['id'];if($locationId&&$id!==$locationId)continue;
        try{
            $board=kds_production_board($pdo,$organizationId,$id,null,false);
            foreach($board['tickets']??[] as $ticket){
                if(!empty($ticket['readyToBump'])&&(string)($ticket['checkStatus']??'')==='open')$count++;
            }
        }catch(Throwable){}
    }
    return $count;
}

function admin_dashboard_online_orders(PDO $pdo,int $organizationId,array $periods,?int $locationId=null): array
{
    $out=['open'=>0,'today'=>0];
    if(!admin_dashboard_table_ready($pdo,'online_orders'))return $out;
    $sql="SELECT SUM(status NOT IN ('completed','cancelled','picked_up','fulfilled')) open_count,SUM(DATE(submitted_at)=?) today_count FROM online_orders WHERE organization_id=?";
    $args=[$periods['today'],$organizationId];if($locationId){$sql.=' AND location_id=?';$args[]=$locationId;}
    try{$q=$pdo->prepare($sql);$q->execute($args);$r=$q->fetch()?:[];return ['open'=>(int)($r['open_count']??0),'today'=>(int)($r['today_count']??0)];}catch(Throwable){return $out;}
}

function admin_dashboard_wholesale(PDO $pdo,int $organizationId,array $periods): array
{
    $out=['available'=>false,'activeAccounts'=>0,'pipelineLeads'=>0,'openOrders'=>0,'openOrderValue'=>0.0,'monthDelivered'=>0.0,'outstandingReceivables'=>0.0,'overdueInvoices'=>0];
    if(!admin_dashboard_table_ready($pdo,'wholesale_accounts'))return $out;
    $out['available']=true;
    try{$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_accounts WHERE organization_id=? AND archived_at IS NULL AND account_status IN ('active','on_hold')");$q->execute([$organizationId]);$out['activeAccounts']=(int)$q->fetchColumn();}catch(Throwable){}
    if(admin_dashboard_table_ready($pdo,'wholesale_leads')){
        try{$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_leads WHERE organization_id=? AND archived_at IS NULL AND pipeline_stage NOT IN ('won','lost')");$q->execute([$organizationId]);$out['pipelineLeads']=(int)$q->fetchColumn();}catch(Throwable){}
    }
    if(admin_dashboard_table_ready($pdo,'wholesale_orders')){
        try{
            $q=$pdo->prepare("SELECT SUM(status NOT IN ('delivered','cancelled')) open_orders,COALESCE(SUM(CASE WHEN status NOT IN ('delivered','cancelled') THEN total ELSE 0 END),0) open_value,COALESCE(SUM(CASE WHEN status='delivered' AND DATE(delivered_at)>=? THEN total ELSE 0 END),0) delivered_month FROM wholesale_orders WHERE organization_id=?");
            $q->execute([$periods['monthStart'],$organizationId]);$r=$q->fetch()?:[];$out['openOrders']=(int)($r['open_orders']??0);$out['openOrderValue']=round((float)($r['open_value']??0),2);$out['monthDelivered']=round((float)($r['delivered_month']??0),2);
        }catch(Throwable){}
    }
    if(admin_dashboard_table_ready($pdo,'wholesale_invoices')&&admin_dashboard_table_ready($pdo,'wholesale_receivable_entries')){
        try{
            $q=$pdo->prepare("SELECT COALESCE(SUM(x.balance),0) outstanding,SUM(x.balance>0.005 AND i.due_date IS NOT NULL AND i.due_date<?) overdue
                FROM wholesale_invoices i
                LEFT JOIN (SELECT wholesale_invoice_id,SUM(amount_delta) balance FROM wholesale_receivable_entries WHERE organization_id=? GROUP BY wholesale_invoice_id) x ON x.wholesale_invoice_id=i.id
                WHERE i.organization_id=? AND i.status NOT IN ('draft','void')");
            $q->execute([$periods['today'],$organizationId,$organizationId]);$r=$q->fetch()?:[];$out['outstandingReceivables']=round(max(0,(float)($r['outstanding']??0)),2);$out['overdueInvoices']=(int)($r['overdue']??0);
        }catch(Throwable){}
    }
    return $out;
}

function admin_dashboard_catering(PDO $pdo,int $organizationId): array
{
    $out=['available'=>false,'activeEvents'=>0,'next7Days'=>0,'atRisk'=>0,'averageReadiness'=>0];
    if(!admin_dashboard_table_ready($pdo,'restaurant_operations'))return $out;
    $out['available']=true;
    try{
        $q=$pdo->prepare("SELECT COUNT(*) active_events,SUM(service_start_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)) next7, SUM(COALESCE(readiness_percent,0)<80 AND service_start_at IS NOT NULL AND service_start_at<=DATE_ADD(NOW(),INTERVAL 7 DAY)) at_risk, COALESCE(AVG(COALESCE(readiness_percent,0)),0) avg_ready FROM restaurant_operations WHERE organization_id=? AND source_type='catering' AND archived_at IS NULL AND status NOT IN ('completed','cancelled')");
        $q->execute([$organizationId]);$r=$q->fetch()?:[];
        return ['available'=>true,'activeEvents'=>(int)($r['active_events']??0),'next7Days'=>(int)($r['next7']??0),'atRisk'=>(int)($r['at_risk']??0),'averageReadiness'=>(int)round((float)($r['avg_ready']??0))];
    }catch(Throwable){return $out;}
}

function admin_dashboard_customers(PDO $pdo,int $organizationId): array
{
    $out=['available'=>false,'activeCustomers'=>0,'promotionsSent30d'=>0,'promotionRecipients30d'=>0];
    if(admin_dashboard_table_ready($pdo,'crm_customers')){
        $out['available']=true;
        try{$q=$pdo->prepare("SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND status='active'");$q->execute([$organizationId]);$out['activeCustomers']=(int)$q->fetchColumn();}catch(Throwable){}
    }
    if(admin_dashboard_table_ready($pdo,'customer_inbox_messages')&&admin_dashboard_table_ready($pdo,'customer_inbox_recipients')){
        try{
            $q=$pdo->prepare("SELECT COUNT(DISTINCT m.id) campaigns,COUNT(r.id) recipients FROM customer_inbox_messages m LEFT JOIN customer_inbox_recipients r ON r.organization_id=m.organization_id AND r.message_id=m.id WHERE m.organization_id=? AND m.message_type='promotion' AND m.sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
            $q->execute([$organizationId]);$r=$q->fetch()?:[];$out['promotionsSent30d']=(int)($r['campaigns']??0);$out['promotionRecipients30d']=(int)($r['recipients']??0);
        }catch(Throwable){}
    }
    return $out;
}

function admin_dashboard_priorities(array $snapshot): array
{
    $items=[];$totals=$snapshot['totals'];$wholesale=$snapshot['wholesale'];$catering=$snapshot['catering'];
    if(($totals['readyTickets']??0)>0)$items[]=['severity'=>'high','title'=>($totals['readyTickets']).' ticket'.(($totals['readyTickets'])===1?' is':'s are').' READY','detail'=>'Expo-ready kitchen tickets are waiting for service.','href'=>'pos.php'];
    if(($totals['activeTickets']??0)>0)$items[]=['severity'=>'normal','title'=>($totals['activeTickets']).' active POS ticket'.(($totals['activeTickets'])===1?'':'s'),'detail'=>'Open checks currently represent $'.number_format((float)($totals['openValue']??0),2).' before closeout.','href'=>'pos.php'];
    if(($snapshot['onlineOrders']['open']??0)>0)$items[]=['severity'=>'normal','title'=>($snapshot['onlineOrders']['open']).' open online order'.(($snapshot['onlineOrders']['open'])===1?'':'s'),'detail'=>'Review pickup flow and kitchen progression.','href'=>'online-orders-admin.php'];
    if(($wholesale['overdueInvoices']??0)>0)$items[]=['severity'=>'high','title'=>($wholesale['overdueInvoices']).' overdue wholesale invoice'.(($wholesale['overdueInvoices'])===1?'':'s'),'detail'=>'Outstanding Wholesale A/R is $'.number_format((float)($wholesale['outstandingReceivables']??0),2).'.','href'=>'wholesale-accounts.php'];
    if(($wholesale['openOrders']??0)>0)$items[]=['severity'=>'normal','title'=>($wholesale['openOrders']).' wholesale order'.(($wholesale['openOrders'])===1?'':'s').' in progress','detail'=>'Open Wholesale order value is $'.number_format((float)($wholesale['openOrderValue']??0),2).'.','href'=>'wholesale-accounts.php'];
    if(($catering['atRisk']??0)>0)$items[]=['severity'=>'high','title'=>($catering['atRisk']).' catering event'.(($catering['atRisk'])===1?' needs':'s need').' attention','detail'=>'Upcoming events below 80% readiness should be reviewed.','href'=>'catering-operations.php'];
    if(!$items)$items[]=['severity'=>'good','title'=>'No urgent command-center exceptions','detail'=>'Live systems are reporting no high-priority dashboard exceptions.','href'=>'operations.php'];
    return array_slice($items,0,8);
}

function admin_dashboard_snapshot(PDO $pdo,array $user,?int $locationId=null): array
{
    $org=(int)$user['organization_id'];$periods=admin_dashboard_periods($pdo,$org);$locations=admin_dashboard_locations($pdo,$org);
    if($locationId&&!admin_dashboard_location($pdo,$org,$locationId))throw new InvalidArgumentException('That dashboard location is not active.');
    $canSales=admin_dashboard_can($user,'sales.view','pos.manage');
    $canTables=admin_dashboard_can($user,'table_service.view','pos.manage');
    $canKds=admin_dashboard_can($user,'kds.view','kds.configure','pos.manage');
    $canWholesale=admin_dashboard_can($user,'wholesale.view');
    $canCatering=admin_dashboard_can($user,'catering.view');
    $canCrm=admin_dashboard_can($user,'crm.view','customer_promotions.manage');
    $emptyPos=['salesToday'=>0.0,'salesWeek'=>0.0,'salesMonth'=>0.0,'ticketsToday'=>0,'coversToday'=>0,'avgCheckToday'=>0.0,'activeTickets'=>0,'openValue'=>0.0];
    $pos=$canSales?admin_dashboard_pos_rollup($pdo,$org,$periods,$locationId):$emptyPos;
    $pos['activeTables']=$canTables?admin_dashboard_active_tables($pdo,$org,$locationId):0;
    $pos['readyTickets']=$canKds?admin_dashboard_ready_tickets($pdo,$org,$locations,$locationId):0;
    $online=$canSales?admin_dashboard_online_orders($pdo,$org,$periods,$locationId):['open'=>0,'today'=>0];
    $locationRows=[];
    foreach($locations as $location){
        $id=(int)$location['id'];$row=$canSales?admin_dashboard_pos_rollup($pdo,$org,$periods,$id):$emptyPos;
        $row['id']=$id;$row['name']=(string)$location['name'];$row['city']=$location['city']??null;$row['state']=$location['state']??null;
        $row['activeTables']=$canTables?admin_dashboard_active_tables($pdo,$org,$id):0;
        $row['readyTickets']=$canKds?admin_dashboard_ready_tickets($pdo,$org,[$location],$id):0;
        $row['onlineOrdersOpen']=$canSales?admin_dashboard_online_orders($pdo,$org,$periods,$id)['open']:0;
        $locationRows[]=$row;
    }
    $snapshot=[
        'generatedAt'=>$periods['now'],'scope'=>['locationId'=>$locationId,'locationName'=>$locationId?(admin_dashboard_location($pdo,$org,$locationId)['name']??null):'All locations'],
        'periods'=>$periods,'capabilities'=>['sales'=>$canSales,'tables'=>$canTables,'kds'=>$canKds,'wholesale'=>$canWholesale,'catering'=>$canCatering,'crm'=>$canCrm],
        'totals'=>$pos,'onlineOrders'=>$online,'locations'=>$locationRows,
        'activeTickets'=>$canSales?admin_dashboard_active_checks($pdo,$org,$locationId):[],
        'wholesale'=>$canWholesale?admin_dashboard_wholesale($pdo,$org,$periods):['available'=>false,'activeAccounts'=>0,'pipelineLeads'=>0,'openOrders'=>0,'openOrderValue'=>0.0,'monthDelivered'=>0.0,'outstandingReceivables'=>0.0,'overdueInvoices'=>0],
        'catering'=>$canCatering?admin_dashboard_catering($pdo,$org):['available'=>false,'activeEvents'=>0,'next7Days'=>0,'atRisk'=>0,'averageReadiness'=>0],
        'customers'=>$canCrm?admin_dashboard_customers($pdo,$org):['available'=>false,'activeCustomers'=>0,'promotionsSent30d'=>0,'promotionRecipients30d'=>0],
    ];
    $snapshot['priorities']=admin_dashboard_priorities($snapshot);
    return $snapshot;
}
