<?php
declare(strict_types=1);

function kds_ready(PDO $pdo): bool
{
    foreach(['kds_stations','kds_menu_routes','kds_order_items','kds_order_events','pos_checks','pos_check_items'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function kds_public_id(string $prefix='kds'): string {return $prefix.'-'.bin2hex(random_bytes(12));}
function kds_slug(string $value): string {$s=mb_strtolower(trim($value),'UTF-8');$s=preg_replace('/[^\pL\pN]+/u','-',$s)??'';return trim($s,'-');}
function kds_transaction(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();try{$result=$work();if($owns)$pdo->commit();return $result;}catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function kds_location(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND id=? AND status='active' LIMIT 1");$q->execute([$org,$locationId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Kitchen location was not found.');return ['id'=>(int)$r['id'],'name'=>(string)$r['name']];
}

function kds_stations(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    kds_location($pdo,$org,$locationId);$sql="SELECT id,public_id,name,slug,target_seconds,sort_order,status,created_at,updated_at FROM kds_stations WHERE organization_id=? AND location_id=?";$args=[$org,$locationId];if($activeOnly)$sql.=" AND status='active'";$sql.=' ORDER BY sort_order,name,id';$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}

function kds_station_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    kds_location($pdo,$org,$locationId);$public=trim((string)($input['publicId']??''));$name=mb_substr(trim((string)($input['name']??'')),0,120,'UTF-8');if($name==='')throw new InvalidArgumentException('Kitchen station name is required.');$slug=kds_slug((string)($input['slug']??$name));if($slug==='')throw new InvalidArgumentException('Kitchen station slug is invalid.');$target=max(30,min(7200,(int)($input['targetSeconds']??600)));$sort=(int)($input['sortOrder']??0);$status=in_array((string)($input['status']??'active'),['active','inactive'],true)?(string)$input['status']:'active';
    if($public!==''){$q=$pdo->prepare('SELECT id FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$public]);$id=(int)$q->fetchColumn();if(!$id)throw new InvalidArgumentException('Kitchen station was not found.');$pdo->prepare('UPDATE kds_stations SET name=?,slug=?,target_seconds=?,sort_order=?,status=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$name,$slug,$target,$sort,$status,$userId,$org,$id]);}
    else{$public=kds_public_id('station');$pdo->prepare('INSERT INTO kds_stations (organization_id,location_id,public_id,name,slug,target_seconds,sort_order,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,$locationId,$public,$name,$slug,$target,$sort,$status,$userId,$userId]);}
    $q=$pdo->prepare('SELECT id,public_id,name,slug,target_seconds,sort_order,status FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$public]);return $q->fetch()?:[];
}

function kds_menu_routes(PDO $pdo,int $org,int $locationId): array
{
    kds_location($pdo,$org,$locationId);$q=$pdo->prepare("SELECT r.menu_item_id,i.name menu_item_name,s.id station_id,s.public_id station_public_id,s.name station_name,s.status station_status FROM kds_menu_routes r JOIN menu_items i ON i.id=r.menu_item_id AND i.organization_id=r.organization_id JOIN kds_stations s ON s.id=r.station_id AND s.organization_id=r.organization_id AND s.location_id=r.location_id WHERE r.organization_id=? AND r.location_id=? ORDER BY i.name");$q->execute([$org,$locationId]);return $q->fetchAll();
}

function kds_route_save(PDO $pdo,int $org,int $locationId,int $menuItemId,?string $stationPublicId,int $userId): array
{
    kds_location($pdo,$org,$locationId);$q=$pdo->prepare('SELECT id FROM menu_items WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$menuItemId]);if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Menu item was not found.');
    if($stationPublicId===null||trim($stationPublicId)===''){$pdo->prepare('DELETE FROM kds_menu_routes WHERE organization_id=? AND location_id=? AND menu_item_id=?')->execute([$org,$locationId,$menuItemId]);return ['menuItemId'=>$menuItemId,'station'=>null];}
    $q=$pdo->prepare("SELECT id,public_id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");$q->execute([$org,$locationId,trim($stationPublicId)]);$station=$q->fetch();if(!$station)throw new InvalidArgumentException('Active kitchen station was not found.');
    $pdo->prepare('INSERT INTO kds_menu_routes (organization_id,location_id,menu_item_id,station_id,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE station_id=VALUES(station_id),updated_by=VALUES(updated_by),updated_at=NOW(6)')->execute([$org,$locationId,$menuItemId,(int)$station['id'],$userId]);return ['menuItemId'=>$menuItemId,'station'=>['publicId'=>$station['public_id'],'name'=>$station['name']]];
}

function kds_event(PDO $pdo,int $org,int $itemId,string $eventType,?string $from,?string $to,?string $note,?int $userId): void
{
    $note=mb_substr(trim((string)$note),0,1000,'UTF-8')?:null;$pdo->prepare('INSERT INTO kds_order_events (organization_id,kds_order_item_id,event_type,from_status,to_status,note,actor_user_id) VALUES (?,?,?,?,?,?,?)')->execute([$org,$itemId,$eventType,$from,$to,$note,$userId]);
}

function kds_sent_line(PDO $pdo,int $org,int $posItemId): ?array
{
    if(!kds_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=? LIMIT 1');$q->execute([$org,$posItemId]);$r=$q->fetch();return $r?:null;
}

function kds_assert_pos_line_mutable(PDO $pdo,int $org,int $posItemId): void
{
    if(kds_sent_line($pdo,$org,$posItemId)!==null)throw new InvalidArgumentException('This item has already been sent to the kitchen. Void it and add a corrected item instead.');
}

function kds_send_check(PDO $pdo,int $org,string $checkPublicId,int $userId,bool $hold=false): array
{
    if(!kds_ready($pdo))throw new RuntimeException('Kitchen Display migration is not installed. Run upgrade.php.');
    return kds_transaction($pdo,function()use($pdo,$org,$checkPublicId,$userId,$hold):array{
        $q=$pdo->prepare("SELECT id,location_id,status FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");$q->execute([$org,$checkPublicId]);$check=$q->fetch();if(!$check)throw new InvalidArgumentException('POS check was not found.');if($check['status']!=='open')throw new InvalidArgumentException('Only an open POS check can send new items to the kitchen.');
        $q=$pdo->prepare("SELECT i.id,i.menu_item_id FROM pos_check_items i LEFT JOIN kds_order_items k ON k.organization_id=i.organization_id AND k.pos_check_item_id=i.id WHERE i.organization_id=? AND i.check_id=? AND i.status='active' AND k.id IS NULL ORDER BY i.id FOR UPDATE");$q->execute([$org,(int)$check['id']]);$lines=$q->fetchAll();if(!$lines)return kds_check_summary($pdo,$org,$checkPublicId);
        $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');foreach($lines as $line){$route=$pdo->prepare("SELECT r.station_id FROM kds_menu_routes r JOIN kds_stations s ON s.id=r.station_id AND s.organization_id=r.organization_id AND s.location_id=r.location_id AND s.status='active' WHERE r.organization_id=? AND r.location_id=? AND r.menu_item_id=? LIMIT 1");$route->execute([$org,(int)$check['location_id'],(int)$line['menu_item_id']]);$station=$route->fetchColumn();$status=$hold?'held':'queued';$fired=$hold?null:$now;$public=kds_public_id('kds-item');$pdo->prepare('INSERT INTO kds_order_items (organization_id,location_id,check_id,pos_check_item_id,station_id,public_id,status,sent_at,fired_at,last_action_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,(int)$check['location_id'],(int)$check['id'],(int)$line['id'],$station!==false?(int)$station:null,$public,$status,$now,$fired,$userId]);$id=(int)$pdo->lastInsertId();kds_event($pdo,$org,$id,'sent',null,$status,$station===false?'No active station route was configured.':null,$userId);}
        return kds_check_summary($pdo,$org,$checkPublicId);
    });
}

function kds_item(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT k.*,c.public_id check_public_id,c.check_number,c.status check_status,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions,i.status pos_item_status,s.name station_name,s.target_seconds FROM kds_order_items k JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id WHERE k.organization_id=? AND k.public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Kitchen item was not found.');return $r;
}

function kds_transition(PDO $pdo,int $org,string $publicId,string $to,int $userId,string $note=''): array
{
    $valid=['held'=>['queued','cancelled'],'queued'=>['held','in_progress','cancelled'],'in_progress'=>['ready','cancelled'],'ready'=>['in_progress','completed','cancelled'],'completed'=>[],'cancelled'=>[]];if(!array_key_exists($to,$valid))throw new InvalidArgumentException('Kitchen status is invalid.');
    return kds_transaction($pdo,function()use($pdo,$org,$publicId,$to,$userId,$note,$valid):array{
        $row=kds_item($pdo,$org,$publicId,true);$from=(string)$row['status'];if(!in_array($to,$valid[$from]??[],true))throw new InvalidArgumentException('Kitchen item cannot move from '.$from.' to '.$to.'.');if($row['station_id']===null&&$to!=='cancelled')throw new InvalidArgumentException('Route this item to an active kitchen station before changing its kitchen status.');
        $sets=['status=?','last_action_by=?','revision=revision+1','updated_at=NOW(6)'];$args=[$to,$userId];if($to==='queued'&&empty($row['fired_at']))$sets[]='fired_at=NOW(6)';if($to==='in_progress'&&empty($row['started_at']))$sets[]='started_at=NOW(6)';if($to==='ready'&&empty($row['ready_at']))$sets[]='ready_at=NOW(6)';if($to==='completed'&&empty($row['completed_at']))$sets[]='completed_at=NOW(6)';if($to==='cancelled'&&empty($row['cancelled_at']))$sets[]='cancelled_at=NOW(6)';if($to==='held')$sets[]='fired_at=NULL';$args[]=$org;$args[]=(int)$row['id'];$pdo->prepare('UPDATE kds_order_items SET '.implode(',',$sets).' WHERE organization_id=? AND id=?')->execute($args);kds_event($pdo,$org,(int)$row['id'],'status_changed',$from,$to,$note,$userId);return kds_item($pdo,$org,$publicId,false);
    });
}

function kds_reassign(PDO $pdo,int $org,string $publicId,?string $stationPublicId,int $userId): array
{
    return kds_transaction($pdo,function()use($pdo,$org,$publicId,$stationPublicId,$userId):array{
        $row=kds_item($pdo,$org,$publicId,true);if(in_array((string)$row['status'],['completed','cancelled'],true))throw new InvalidArgumentException('Completed or cancelled kitchen items cannot be reassigned.');$stationId=null;$stationName=null;if($stationPublicId!==null&&trim($stationPublicId)!==''){$q=$pdo->prepare("SELECT id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");$q->execute([$org,(int)$row['location_id'],trim($stationPublicId)]);$s=$q->fetch();if(!$s)throw new InvalidArgumentException('Active kitchen station was not found.');$stationId=(int)$s['id'];$stationName=(string)$s['name'];}$pdo->prepare('UPDATE kds_order_items SET station_id=?,last_action_by=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$stationId,$userId,$org,(int)$row['id']]);kds_event($pdo,$org,(int)$row['id'],'reassigned',(string)$row['status'],(string)$row['status'],$stationName?'Routed to '.$stationName:'Moved to Unrouted',$userId);return kds_item($pdo,$org,$publicId,false);
    });
}

function kds_cancel_pos_line(PDO $pdo,int $org,int $posItemId,string $reason,int $userId): void
{
    if(!kds_ready($pdo))return;kds_transaction($pdo,function()use($pdo,$org,$posItemId,$reason,$userId):void{$q=$pdo->prepare("SELECT id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=? LIMIT 1 FOR UPDATE");$q->execute([$org,$posItemId]);$row=$q->fetch();if(!$row||in_array((string)$row['status'],['completed','cancelled'],true))return;$pdo->prepare("UPDATE kds_order_items SET status='cancelled',cancelled_at=NOW(6),last_action_by=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$row['id']]);kds_event($pdo,$org,(int)$row['id'],'pos_void',(string)$row['status'],'cancelled',$reason,$userId);});
}

function kds_cancel_check(PDO $pdo,int $org,int $checkId,string $reason,int $userId): void
{
    if(!kds_ready($pdo))return;kds_transaction($pdo,function()use($pdo,$org,$checkId,$reason,$userId):void{$q=$pdo->prepare("SELECT id,status FROM kds_order_items WHERE organization_id=? AND check_id=? AND status NOT IN ('completed','cancelled') FOR UPDATE");$q->execute([$org,$checkId]);foreach($q->fetchAll() as $row){$pdo->prepare("UPDATE kds_order_items SET status='cancelled',cancelled_at=NOW(6),last_action_by=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$row['id']]);kds_event($pdo,$org,(int)$row['id'],'check_cancelled',(string)$row['status'],'cancelled',$reason,$userId);}});
}

function kds_check_summary(PDO $pdo,int $org,string $checkPublicId): array
{
    if(!kds_ready($pdo))return ['ready'=>false,'sent'=>0,'unsent'=>0,'unrouted'=>0,'items'=>[]];$q=$pdo->prepare('SELECT id FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$checkPublicId]);$checkId=(int)$q->fetchColumn();if(!$checkId)throw new InvalidArgumentException('POS check was not found.');$q=$pdo->prepare("SELECT k.public_id,k.pos_check_item_id,k.status,k.station_id,s.public_id station_public_id,s.name station_name,k.sent_at,k.fired_at,k.started_at,k.ready_at,k.completed_at,k.cancelled_at FROM kds_order_items k LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id WHERE k.organization_id=? AND k.check_id=? ORDER BY k.id");$q->execute([$org,$checkId]);$items=$q->fetchAll();$q=$pdo->prepare("SELECT COUNT(*) FROM pos_check_items i LEFT JOIN kds_order_items k ON k.organization_id=i.organization_id AND k.pos_check_item_id=i.id WHERE i.organization_id=? AND i.check_id=? AND i.status='active' AND k.id IS NULL");$q->execute([$org,$checkId]);$unsent=(int)$q->fetchColumn();$unrouted=count(array_filter($items,static fn(array $r):bool=>$r['station_id']===null&&!in_array((string)$r['status'],['completed','cancelled'],true)));return ['ready'=>true,'sent'=>count($items),'unsent'=>$unsent,'unrouted'=>$unrouted,'items'=>$items];
}

function kds_board(PDO $pdo,int $org,int $locationId,?string $stationPublicId=null,bool $includeCompleted=false): array
{
    kds_location($pdo,$org,$locationId);$where="k.organization_id=? AND k.location_id=?";$args=[$org,$locationId];if($stationPublicId==='unrouted')$where.=' AND k.station_id IS NULL';elseif($stationPublicId!==null&&$stationPublicId!==''){$where.=' AND s.public_id=?';$args[]=$stationPublicId;}if(!$includeCompleted)$where.=" AND k.status NOT IN ('completed','cancelled')";
    $sql="SELECT k.public_id,k.status,k.sent_at,k.fired_at,k.started_at,k.ready_at,k.completed_at,k.cancelled_at,k.station_id,s.public_id station_public_id,s.name station_name,s.target_seconds,c.public_id check_public_id,c.check_number,c.business_date,c.service_mode,c.table_name,c.guest_count,c.status check_status,i.id pos_check_item_id,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions,i.status pos_item_status FROM kds_order_items k JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id WHERE {$where} ORDER BY CASE k.status WHEN 'ready' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'queued' THEN 2 WHEN 'held' THEN 3 ELSE 4 END,COALESCE(k.fired_at,k.sent_at),k.id";$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();$now=microtime(true);foreach($rows as &$r){$anchor=$r['fired_at']?:$r['sent_at'];$age=max(0,$now-(strtotime((string)$anchor)?:time()));$r['ageSeconds']=(int)round($age);$target=(int)($r['target_seconds']??0);$r['late']=$target>0&&$r['status']!=='held'&&$age>$target;}unset($r);
    return ['location'=>kds_location($pdo,$org,$locationId),'stations'=>kds_stations($pdo,$org,$locationId),'items'=>$rows,'unroutedCount'=>count(array_filter($rows,static fn(array $r):bool=>$r['station_id']===null))];
}

function kds_menu_catalog(PDO $pdo,int $org,int $locationId): array
{
    $routes=[];foreach(kds_menu_routes($pdo,$org,$locationId) as $r)$routes[(int)$r['menu_item_id']]=$r;$q=$pdo->prepare("SELECT i.id,i.name,s.name section_name FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id WHERE i.organization_id=? AND i.is_active=1 AND s.status='active' ORDER BY s.sort_order,s.name,i.name");$q->execute([$org]);$out=[];foreach($q->fetchAll() as $r){$route=$routes[(int)$r['id']]??null;$out[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'sectionName'=>(string)$r['section_name'],'station'=>$route?['publicId'=>$route['station_public_id'],'name'=>$route['station_name']]:null];}return $out;
}
