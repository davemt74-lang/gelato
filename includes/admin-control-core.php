<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/online-order-lifecycle.php';

function admin_has_any(array $user,array $permissions): bool
{
    foreach($permissions as $permission){
        if(app_has_permission($permission,$user)) return true;
    }
    return false;
}

function admin_control_allowed(array $user): bool
{
    if((int)($user['is_owner_role']??0)===1 || in_array('*',$user['permissions']??[],true)) return true;
    return admin_has_any($user,[
        'settings.organization_edit','public_pages.edit','locations.manage','crm.manage','customer_promotions.manage',
        'pos.manage','kds.configure','staff.manage','inventory.manage','purchasing.manage','tasks.manage','schedule.manage',
        'sales.integrations.manage','roles.edit','users.edit','audit.view'
    ]);
}

function admin_online_orders_allowed(array $user): bool
{
    return admin_has_any($user,['pos.use','pos.manage','kds.view','kds.configure','crm.view','crm.manage']);
}

function admin_modules(array $user): array
{
    $owner=(int)($user['is_owner_role']??0)===1 || in_array('*',$user['permissions']??[],true);
    $definitions=[
        ['category'=>'Commerce','name'=>'Online Orders','description'=>'Monitor customer pickup orders, fulfillment status, totals and requested ready times.','href'=>'online-orders-admin.php','permissions'=>['pos.use','pos.manage','kds.view','crm.view'],'icon'=>'↗'],
        ['category'=>'Commerce','name'=>'POS','description'=>'Open checks, pickup and delivery tickets, payments and service activity.','href'=>'pos.php','permissions'=>['pos.use'],'icon'=>'▦'],
        ['category'=>'Commerce','name'=>'Kitchen Display','description'=>'Kitchen queue, routed stations, preparation timing and ready orders.','href'=>'kds.php','permissions'=>['kds.view'],'icon'=>'⌁'],
        ['category'=>'Customers','name'=>'Customer CRM','description'=>'Customer profiles, relationship history, consent, tags and purchase activity.','href'=>'customer-crm.php','permissions'=>['crm.view'],'icon'=>'◎'],
        ['category'=>'Customers','name'=>'Promotions','description'=>'Send account Inbox promotions to eligible customer segments and locations.','href'=>'customer-promotions.php','permissions'=>['customer_promotions.manage'],'icon'=>'✦'],
        ['category'=>'Restaurant','name'=>'Locations','description'=>'Addresses, hours, online ordering, pickup, delivery and location configuration.','href'=>'locations-admin.php','permissions'=>['locations.manage'],'icon'=>'⌖'],
        ['category'=>'Restaurant','name'=>'Public Website','description'=>'Public contact details, hours, social links and website settings.','href'=>'public-site-settings.php','permissions'=>['public_pages.edit','settings.organization_edit'],'icon'=>'◫'],
        ['category'=>'Restaurant','name'=>'Menu Import','description'=>'Owner menu import and canonical menu synchronization tools.','href'=>'admin-menu-import.php','permissions'=>[],'ownerOnly'=>true,'icon'=>'≡'],
        ['category'=>'Operations','name'=>'Operations','description'=>'Tasks, prep lists, inventory and restaurant work management.','href'=>'operations.php','permissions'=>['tasks.view','tasks.manage','inventory.view','inventory.manage'],'icon'=>'✓'],
        ['category'=>'Operations','name'=>'Scheduling','description'=>'Staff availability, schedules, shifts, requests and coverage.','href'=>'scheduling.php','permissions'=>['schedule.view','schedule.manage','staff.view','staff.manage'],'icon'=>'□'],
        ['category'=>'Operations','name'=>'Purchasing','description'=>'Vendors, purchase orders, receiving and cost intelligence.','href'=>'purchasing.php','permissions'=>['purchasing.view','purchasing.manage'],'icon'=>'↓'],
        ['category'=>'Operations','name'=>'Equipment','description'=>'Restaurant equipment records, service history and operational assets.','href'=>'equipment.php','permissions'=>['equipment.view','equipment.manage'],'icon'=>'◇'],
        ['category'=>'Intelligence','name'=>'Sales Intelligence','description'=>'Sales performance, demand trends, location rollups and source health.','href'=>'sales-intelligence.php','permissions'=>['sales.view'],'icon'=>'↗'],
        ['category'=>'Intelligence','name'=>'Cost Intelligence','description'=>'Food-cost and sales-cost analysis tied to the restaurant data model.','href'=>'sales-cost-intelligence.php','permissions'=>['sales.view','inventory.view'],'icon'=>'$'],
        ['category'=>'Workspace','name'=>'Workspace','description'=>'Return to the broader Gelato restaurant workspace and training system.','href'=>'workspace.php','permissions'=>[],'always'=>true,'icon'=>'G'],
    ];
    $modules=[];
    foreach($definitions as $module){
        if(!empty($module['ownerOnly'])&&!$owner) continue;
        if(empty($module['always'])&&!$owner&&!admin_has_any($user,$module['permissions']??[])) continue;
        unset($module['permissions'],$module['ownerOnly'],$module['always']);
        $modules[]=$module;
    }
    return $modules;
}

function admin_table_exists(PDO $pdo,string $table): bool
{
    static $cache=[];
    $key=spl_object_id($pdo).':'.$table;
    if(array_key_exists($key,$cache)) return $cache[$key];
    try{
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return $cache[$key]=(int)$q->fetchColumn()===1;
    }catch(Throwable){
        return $cache[$key]=false;
    }
}

function admin_scalar(PDO $pdo,string $sql,array $args=[],mixed $fallback=0): mixed
{
    try{$q=$pdo->prepare($sql);$q->execute($args);$value=$q->fetchColumn();return $value===false?$fallback:$value;}catch(Throwable){return $fallback;}
}

function admin_dashboard_metrics(PDO $pdo,int $organizationId): array
{
    $metrics=[
        'onlineOrdersToday'=>0,'onlineOrderValueToday'=>0.0,'openOnlineOrders'=>0,'readyOnlineOrders'=>0,
        'activeCustomers'=>0,'promotionReach'=>0,'activeLocations'=>0,'onlineLocations'=>0,
    ];
    if(admin_table_exists($pdo,'locations')){
        $metrics['activeLocations']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM locations WHERE organization_id=? AND status='active'",[$organizationId]);
        $metrics['onlineLocations']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM locations WHERE organization_id=? AND status='active' AND online_ordering_enabled=1",[$organizationId]);
    }
    if(admin_table_exists($pdo,'crm_customers')){
        $metrics['activeCustomers']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM crm_customers WHERE organization_id=? AND status='active'",[$organizationId]);
        if(admin_table_exists($pdo,'customer_inbox_preferences')){
            $metrics['promotionReach']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM crm_customers c LEFT JOIN customer_inbox_preferences p ON p.organization_id=c.organization_id AND p.customer_id=c.id WHERE c.organization_id=? AND c.status='active' AND c.user_id IS NOT NULL AND COALESCE(p.promotions_enabled,1)=1",[$organizationId]);
        }
    }
    if(admin_table_exists($pdo,'online_orders')&&admin_table_exists($pdo,'pos_checks')){
        $q=$pdo->prepare("SELECT COUNT(*) order_count,COALESCE(SUM(c.total_amount),0) order_value FROM online_orders oo JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id WHERE oo.organization_id=? AND c.business_date=CURDATE() AND c.status<>'cancelled'");
        try{$q->execute([$organizationId]);$row=$q->fetch()?:[];$metrics['onlineOrdersToday']=(int)($row['order_count']??0);$metrics['onlineOrderValueToday']=round((float)($row['order_value']??0),2);}catch(Throwable){}
        $metrics['openOnlineOrders']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM online_orders oo JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id WHERE oo.organization_id=? AND c.status='open'",[$organizationId]);
        $metrics['readyOnlineOrders']=(int)admin_scalar($pdo,"SELECT COUNT(*) FROM online_orders oo JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id WHERE oo.organization_id=? AND c.status='open' AND oo.status='ready'",[$organizationId]);
    }
    return $metrics;
}

function admin_online_order_status(array $row): string
{
    return online_order_lifecycle_display_status(online_order_lifecycle_derive([
        'check_status'=>$row['check_status']??'',
        'kds_count'=>$row['kds_count']??0,
        'kds_queued_count'=>$row['kds_queued_count']??0,
        'kds_held_count'=>$row['kds_held_count']??0,
        'kds_progress_count'=>$row['kds_progress_count']??0,
        'kds_ready_count'=>$row['kds_ready_count']??0,
        'kds_completed_count'=>$row['kds_completed_count']??0,
        'kds_cancelled_count'=>$row['kds_cancelled_count']??0,
    ]));
}

function admin_online_orders(PDO $pdo,int $organizationId,?int $locationId=null,int $limit=100): array
{
    if(!admin_table_exists($pdo,'online_orders')||!admin_table_exists($pdo,'pos_checks')) return [];
    $limit=max(1,min(200,$limit));
    $hasKds=admin_table_exists($pdo,'kds_order_items');
    $kdsJoin=$hasKds?"LEFT JOIN (SELECT organization_id,check_id,COUNT(*) kds_count,SUM(status='queued') kds_queued_count,SUM(status='held') kds_held_count,SUM(status='in_progress') kds_progress_count,SUM(status='ready') kds_ready_count,SUM(status='completed') kds_completed_count,SUM(status='cancelled') kds_cancelled_count FROM kds_order_items GROUP BY organization_id,check_id) ks ON ks.organization_id=oo.organization_id AND ks.check_id=oo.pos_check_id":"";
    $kdsSelect=$hasKds?"COALESCE(ks.kds_count,0) kds_count,COALESCE(ks.kds_queued_count,0) kds_queued_count,COALESCE(ks.kds_held_count,0) kds_held_count,COALESCE(ks.kds_progress_count,0) kds_progress_count,COALESCE(ks.kds_ready_count,0) kds_ready_count,COALESCE(ks.kds_completed_count,0) kds_completed_count,COALESCE(ks.kds_cancelled_count,0) kds_cancelled_count":"0 kds_count,0 kds_queued_count,0 kds_held_count,0 kds_progress_count,0 kds_ready_count,0 kds_completed_count,0 kds_cancelled_count";
    $sql="SELECT oo.public_id order_public_id,oo.service_mode,oo.payment_mode,oo.status order_status,oo.requested_ready_at,oo.customer_note,oo.submitted_at,c.public_id check_public_id,c.check_number,c.business_date,c.status check_status,c.subtotal,c.tax_amount,c.service_charge_amount,c.total_amount,c.amount_paid,l.id location_id,l.name location_name,cc.public_id customer_public_id,cc.display_name customer_name,cc.email customer_email,{$kdsSelect} FROM online_orders oo JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id JOIN locations l ON l.organization_id=oo.organization_id AND l.id=oo.location_id JOIN crm_customers cc ON cc.organization_id=oo.organization_id AND cc.id=oo.customer_id {$kdsJoin} WHERE oo.organization_id=?";
    $args=[$organizationId];
    if($locationId!==null&&$locationId>0){$sql.=' AND oo.location_id=?';$args[]=$locationId;}
    $sql.=' ORDER BY oo.submitted_at DESC,oo.id DESC LIMIT '.$limit;
    try{$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();}catch(Throwable){return [];}
    foreach($rows as &$row)$row['displayStatus']=admin_online_order_status($row);
    unset($row);
    return $rows;
}

function admin_location_options(PDO $pdo,int $organizationId): array
{
    if(!admin_table_exists($pdo,'locations')) return [];
    try{$q=$pdo->prepare("SELECT id,name,online_ordering_enabled,pickup_enabled,delivery_enabled,status FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");$q->execute([$organizationId]);return $q->fetchAll();}catch(Throwable){return [];}
}
