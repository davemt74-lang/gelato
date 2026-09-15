<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];

if(!app_has_permission('pos.use',$user))app_json_response(['ok'=>false,'message'=>'Native POS permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='GET'){
    header('Allow: GET');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

try{
    $locationId=max(0,(int)($_GET['locationId']??0));
    if($locationId<1)throw new InvalidArgumentException('Choose a POS location.');
    pos_location($pdo,$org,$locationId);
    if(!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS access is not assigned at that restaurant location.');

    if(!kds_ready($pdo))app_json_response(['ok'=>true,'locationId'=>$locationId,'readyTickets'=>[]]);

    $board=kds_production_board($pdo,$org,$locationId,null,false);
    $ready=[];
    foreach($board['tickets']??[] as $ticket){
        if(empty($ticket['readyToBump'])||(string)($ticket['checkStatus']??'')!=='open')continue;
        $items=[];$readyAt=null;
        foreach($ticket['items']??[] as $item){
            if((string)($item['status']??'')!=='ready')continue;
            $at=$item['ready_at']??null;
            if($at!==null&&($readyAt===null||strcmp((string)$at,(string)$readyAt)>0))$readyAt=(string)$at;
            $items[]=[
                'publicId'=>(string)($item['public_id']??''),
                'name'=>(string)($item['item_name_snapshot']??''),
                'option'=>(string)($item['option_name_snapshot']??''),
                'quantity'=>(float)($item['quantity']??0),
                'station'=>$item['station_name']!==null?(string)$item['station_name']:null,
            ];
        }
        $ready[]=[
            'checkPublicId'=>(string)$ticket['checkPublicId'],
            'checkNumber'=>(string)$ticket['checkNumber'],
            'tableName'=>$ticket['tableName']!==null?(string)$ticket['tableName']:null,
            'serviceMode'=>(string)$ticket['serviceMode'],
            'guestCount'=>(int)$ticket['guestCount'],
            'serverName'=>$ticket['serverName']!==null?(string)$ticket['serverName']:null,
            'readyAt'=>$readyAt,
            'readyCount'=>(int)$ticket['ready'],
            'items'=>$items,
        ];
    }

    app_json_response(['ok'=>true,'locationId'=>$locationId,'readyTickets'=>$ready]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'POS ready tickets could not be loaded.')],500);
}
