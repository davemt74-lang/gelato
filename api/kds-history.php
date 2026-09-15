<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$membership=(int)$user['membership_id'];
if(!app_has_permission('kds.view',$user))app_json_response(['ok'=>false,'message'=>'Kitchen Display permission required.'],403);
if(!kds_ready($pdo))app_json_response(['ok'=>false,'message'=>'Kitchen Display migration is not installed. Run upgrade.php.'],503);

function kds_history_locations(PDO $pdo,int $org,array $user): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");
    $q->execute([$org]);
    return operational_filter_locations($pdo,$user,'kds.view',$q->fetchAll());
}

function kds_history_location(PDO $pdo,int $org,int $membership,array $user): int
{
    $id=(int)($_GET['locationId']??0);
    if($id>0){
        pos_location($pdo,$org,$id);
        if(!operational_location_allowed($pdo,$user,'kds.view',$id))throw new DomainException('Kitchen Display access is not assigned at that restaurant location.');
        return $id;
    }
    $q=$pdo->prepare('SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND id=? LIMIT 1');
    $q->execute([$org,$membership]);
    $id=(int)($q->fetchColumn()?:0);
    if($id>0&&operational_location_allowed($pdo,$user,'kds.view',$id))return $id;
    foreach(kds_history_locations($pdo,$org,$user) as $location)return (int)$location['id'];
    throw new DomainException('No active restaurant location is assigned for Kitchen Display access.');
}

try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){
        header('Allow: GET');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $locationId=kds_history_location($pdo,$org,$membership,$user);
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
    $q=$pdo->prepare($sql);$q->execute([$org,$locationId]);
    $tickets=[];
    foreach($q->fetchAll() as $row){
        $key=(string)$row['check_public_id'];
        if(!isset($tickets[$key])){
            $tickets[$key]=[
                'checkPublicId'=>$key,
                'checkNumber'=>(string)$row['check_number'],
                'serviceMode'=>(string)$row['service_mode'],
                'tableName'=>$row['table_name'],
                'guestCount'=>(int)$row['guest_count'],
                'serverName'=>(string)$row['opened_by_name'],
                'completedAt'=>$row['completed_at']?:$row['cancelled_at'],
                'durationSeconds'=>max(0,(int)($row['duration_seconds']??0)),
                'items'=>[],
            ];
        }
        $tickets[$key]['durationSeconds']=max((int)$tickets[$key]['durationSeconds'],max(0,(int)($row['duration_seconds']??0)));
        $candidate=$row['completed_at']?:$row['cancelled_at'];
        if($candidate&&(!$tickets[$key]['completedAt']||strcmp((string)$candidate,(string)$tickets[$key]['completedAt'])>0))$tickets[$key]['completedAt']=$candidate;
        $tickets[$key]['items'][]=[
            'public_id'=>(string)$row['public_id'],
            'status'=>(string)$row['status'],
            'item_name_snapshot'=>(string)$row['item_name_snapshot'],
            'option_name_snapshot'=>$row['option_name_snapshot'],
            'quantity'=>(float)$row['quantity'],
            'special_instructions'=>$row['special_instructions'],
            'station_name'=>$row['station_name'],
            'durationSeconds'=>max(0,(int)($row['duration_seconds']??0)),
        ];
    }
    app_json_response([
        'ok'=>true,
        'locationId'=>$locationId,
        'locations'=>kds_history_locations($pdo,$org,$user),
        'hours'=>24,
        'tickets'=>array_slice(array_values($tickets),0,100),
    ]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Kitchen order history could not be loaded.')],500);
}
