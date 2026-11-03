<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-floor-plan.php';

$pdo=app_pdo();
function pfp(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function pfp_retired(callable $operation): bool
{
    try{$operation();}catch(InvalidArgumentException $error){return str_contains($error->getMessage(),'retired');}
    return false;
}

pfp(pos_floor_plan_ready($pdo),'POS floor-plan integration migration must be installed.');
$synthetic=pos_floor_plan_service_points([
    ['id'=>'bar-only','type'=>'bar','label'=>'BAR','seats'=>3,'seatZone'=>'bar','rotation'=>0,'xPercent'=>10.0,'yPercent'=>10.0,'widthPercent'=>30.0,'heightPercent'=>8.0],
]);
pfp(count($synthetic)===3,'A bar with a seat count and no explicit bar chairs must synthesize one stable POS service point per seat.');
pfp(array_column($synthetic,'id')===['bar-only:seat:1','bar-only:seat:2','bar-only:seat:3'],'Synthetic bar-seat component identities must be deterministic.');
pfp(array_column($synthetic,'label')===['Bar Seat 1','Bar Seat 2','Bar Seat 3'],'Synthetic bar seats must receive usable POS ticket names.');
pfp(count(array_filter($synthetic,static fn(array $i):bool=>($i['servicePointKind']??'')==='bar_seat'&&($i['synthetic']??false)===true))===3,'Synthetic bar seats must be typed as bar service points.');

$slug='pfp-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Floor '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Second','Phoenix','AZ','active')")->execute([$org]);$location2=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Third','Phoenix','AZ','active')")->execute([$org]);$location3=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'POS','Lead','POS Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);

$planId='floorplan-'.$slug;
$planData=['version'=>6,'planWft'=>50,'planHft'=>34,'scale'=>24,'items'=>[
    ['id'=>'table-a','type'=>'table','label'=>'4-TOP','xPx'=>120,'yPx'=>96,'wPx'=>96,'hPx'=>72,'r'=>0,'seats'=>4,'seatZone'=>'dining'],
    ['id'=>'table-b','type'=>'table','label'=>'Patio 2','xPx'=>600,'yPx'=>240,'wPx'=>72,'hPx'=>72,'r'=>0,'seats'=>2,'seatZone'=>'dining'],
    ['id'=>'bar-main','type'=>'bar','label'=>'BAR','xPx'=>360,'yPx'=>48,'wPx'=>360,'hPx'=>96,'r'=>0,'seats'=>12,'seatZone'=>'bar'],
    ['id'=>'bar-seat-a','type'=>'chair','label'=>'','xPx'=>384,'yPx'=>152,'wPx'=>40,'hPx'=>40,'r'=>0,'seats'=>1,'seatZone'=>'bar'],
    ['id'=>'dining-chair-a','type'=>'chair','label'=>'','xPx'=>220,'yPx'=>96,'wPx'=>40,'hPx'=>40,'r'=>0,'seats'=>1,'seatZone'=>'dining'],
    ['id'=>'wall-a','type'=>'wall','label'=>'','xPx'=>0,'yPx'=>0,'wPx'=>1200,'hPx'=>6,'r'=>0,'seats'=>0,'seatZone'=>'none'],
]];
$pdo->prepare('INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,?,50,34,24,1,1,?,?)')->execute([$org,$planId,'Main Floor',json_encode($planData,JSON_THROW_ON_ERROR),$user,$user]);

$before=pos_floor_plan_bootstrap($pdo,$org,$location);
pfp($before['selectedPlan']['publicId']===$planId&&$before['selectedBy']==='default','Default floor plan must be discoverable before an explicit location assignment.');
pfp($before['componentTableCount']===2&&$before['componentServicePointCount']===3&&$before['mappedCount']===0&&!$before['configured'],'Unsynced floor plan must report two tables plus one explicit bar-seat service point and zero canonical mappings.');
pos_floor_plan_select($pdo,$org,$location,null,$user);
$none=pos_floor_plan_bootstrap($pdo,$org,$location);pfp($none['selectedPlan']===null&&$none['selectedBy']==='none','An explicit No assigned plan selection must not silently fall back to the organization default.');
$noPlanSyncBlocked=false;try{pos_floor_plan_sync_tables($pdo,$org,$location,$user);}catch(InvalidArgumentException){$noPlanSyncBlocked=true;}pfp($noPlanSyncBlocked,'Floor sync must be blocked when the location explicitly has no assigned plan.');
pos_floor_plan_select($pdo,$org,$location,$planId,$user);
$sync=pos_floor_plan_sync_tables($pdo,$org,$location,$user);
pfp($sync['created']===3&&$sync['updated']===0&&$sync['orphaned']===0&&$sync['componentServicePoints']===3,'First sync must create two tables plus exactly one explicit bar-seat service point.');
$after=$sync['bootstrap'];pfp($after['configured']&&$after['mappedCount']===3&&count($after['tables'])===3&&$after['selectedBy']==='location_setting','Synced floor plan must expose tables and the bar seat as linked canonical service points.');
$linked=array_column($after['tables'],'floorPlanComponentId');sort($linked);pfp($linked===['bar-seat-a','table-a','table-b'],'Only tables and explicit bar-zone chairs may become canonical POS service points; ordinary dining chairs and the bar counter stay layout-only.');
$capacities=array_column($after['tables'],'capacity','floorPlanComponentId');pfp($capacities['table-a']===4&&$capacities['table-b']===2&&$capacities['bar-seat-a']===1,'Floor-plan seat counts must become canonical service-point capacities.');
$shapes=array_column($after['tables'],'shape','floorPlanComponentId');pfp($shapes['bar-seat-a']==='round','Bar seats must use the round service-point shape.');
$names=array_column($after['tables'],'name','floorPlanComponentId');pfp($names['bar-seat-a']==='Bar Seat 1','An unlabeled explicit bar chair must receive a deterministic Bar Seat name.');
pfp(!in_array('dining-chair-a',$linked,true)&&!in_array('bar-main',$linked,true),'Dining chairs and the physical bar counter must not create duplicate POS tickets.');
pfp(count(array_filter($after['structures'],static fn(array $i):bool=>($i['id']??'')==='bar-seat-a'))===0,'A mapped bar chair must be replaced by its live POS service point instead of rendering twice.');

$repeat=pos_floor_plan_sync_tables($pdo,$org,$location,$user);pfp($repeat['created']===0&&$repeat['updated']===3&&count($repeat['bootstrap']['tables'])===3,'Floor sync must be idempotent across tables and bar seats.');
$mapped=array_column($repeat['bootstrap']['tables'],null,'floorPlanComponentId');
$tableA=$mapped['table-a']??null;$tableB=$mapped['table-b']??null;$barSeat=$mapped['bar-seat-a']??null;
pfp(is_array($tableA)&&is_array($tableB)&&is_array($barSeat),'Both mapped tables and the bar seat must exist.');
$barCheck=table_service_seat($pdo,$org,$location,(string)$barSeat['publicId'],1,null,'Bar seat POS mapping contract.',$user);pfp(($barCheck['serviceContext']['tablePublicId']??null)===$barSeat['publicId']&&($barCheck['serviceContext']['partySize']??null)===1,'Tapping a mapped bar seat must create its own canonical dine-in Table Service check.');
$barDetails=pos_check_details($pdo,$org,(string)$barCheck['publicId']);pfp($barDetails['tableName']==='Bar Seat 1','A bar-seat ticket must carry the bar-seat name into the POS check.');
$barLive=pos_floor_plan_bootstrap($pdo,$org,$location);$barLiveRow=null;foreach($barLive['tables'] as $table)if($table['publicId']===$barSeat['publicId'])$barLiveRow=$table;pfp($barLiveRow['state']==='seated'&&$barLiveRow['checkPublicId']===$barCheck['publicId'],'POS floor map must expose the live bar-seat/check state.');

$seated=table_service_seat($pdo,$org,$location,(string)$tableA['publicId'],3,null,'Floor-plan seating contract.',$user);pfp(($seated['serviceContext']['tablePublicId']??null)===$tableA['publicId']&&($seated['serviceContext']['partySize']??null)===3,'Tapping a mapped table must create a canonical dine-in Table Service check.');
$live=pos_floor_plan_bootstrap($pdo,$org,$location);$liveA=null;foreach($live['tables'] as $table)if($table['publicId']===$tableA['publicId'])$liveA=$table;pfp($liveA['state']==='seated'&&$liveA['checkPublicId']===$seated['publicId'],'POS floor map must expose the live table/check state.');

$planData['items'][0]['xPx']=360;$planData['items'][0]['label']='Dining 1';
$planData['items'][3]['label']='Bar 1';
$pdo->prepare('UPDATE floor_plans SET plan_json=?,version=version+1,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([json_encode($planData,JSON_THROW_ON_ERROR),$user,$org,$planId]);
$moved=pos_floor_plan_sync_tables($pdo,$org,$location,$user);$movedA=null;$renamedBar=null;foreach($moved['bootstrap']['tables'] as $table){if($table['publicId']===$tableA['publicId'])$movedA=$table;if($table['publicId']===$barSeat['publicId'])$renamedBar=$table;}
pfp($movedA['state']==='seated'&&$movedA['name']==='Dining 1'&&(float)$movedA['xPercent']>20,'Sync must update floor geometry/name without disturbing an active seated check.');
pfp($renamedBar['state']==='seated'&&$renamedBar['name']==='Bar 1','Sync must rename an occupied bar seat without disturbing its active check.');
$checkAfterRename=pos_check_details($pdo,$org,(string)$seated['publicId']);pfp($checkAfterRename['tableName']==='Dining 1','An occupied table rename must keep the live POS check table name synchronized.');
$barAfterRename=pos_check_details($pdo,$org,(string)$barCheck['publicId']);pfp($barAfterRename['tableName']==='Bar 1','An occupied bar-seat rename must keep the live POS check table name synchronized.');

array_splice($planData['items'],1,1);$pdo->prepare('UPDATE floor_plans SET plan_json=?,version=version+1,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([json_encode($planData,JSON_THROW_ON_ERROR),$user,$org,$planId]);
pfp(pfp_retired(fn()=>table_service_seat($pdo,$org,$location,$tableB['publicId'],1,null,'',$user)), 'A legacy stale active mapping must reject seating even before sync.');
pfp(pfp_retired(fn()=>table_service_transfer($pdo,$org,$seated['publicId'],$tableB['publicId'],$user)), 'A legacy stale mapping must reject an open-check transfer.');
$removed=pos_floor_plan_sync_tables($pdo,$org,$location,$user);
pfp($removed['orphaned']===1&&$removed['retired']===1&&count($removed['bootstrap']['tables'])===2,'Sync must retire and omit an empty removed point.');
$status=$pdo->prepare('SELECT status FROM service_tables WHERE organization_id=? AND id=?');
$status->execute([$org,$tableB['id']]);pfp($status->fetchColumn()==='retired','Sync must preserve the removed canonical row and its historical identity.');

$manual=table_service_table_save($pdo,$org,$location,['name'=>'Manual patio'], $user);
$nextPlan=$planId.'-new';
$sparePlan=$planId.'-spare';
$nextData=['version'=>6,'planWft'=>50,'planHft'=>34,'scale'=>24,'items'=>[
    ['id'=>'table-c','type'=>'table','label'=>'Replacement 3','xPx'=>240,'yPx'=>96,'wPx'=>96,'hPx'=>72,'seats'=>4],
]];
$newPlan=$pdo->prepare('INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,?,50,34,24,1,0,?,?)');
$newPlan->execute([$org,$nextPlan,'Replacement Floor',json_encode($nextData,JSON_THROW_ON_ERROR),$user,$user]);
$newPlan->execute([$org,$sparePlan,'Spare Floor',json_encode($nextData,JSON_THROW_ON_ERROR),$user,$user]);
pos_floor_plan_select($pdo,$org,$location3,$sparePlan,$user);
$unrelatedPoint=pos_floor_plan_sync_tables($pdo,$org,$location3,$user)['bootstrap']['tables'][0];
$pdo->prepare('UPDATE pos_floor_plan_settings SET floor_plan_public_id=NULL WHERE organization_id=? AND location_id=?')->execute([$org,$location3]);
table_service_transaction($pdo,static function()use($pdo,$org,$planId,$user):void{
    pos_floor_plan_lock_publication($pdo,$org);
    pos_floor_plan_after_save($pdo,$org,$planId,$user);
});
$status->execute([$org,$unrelatedPoint['id']]);
pfp($status->fetchColumn()==='active','Saving a layout must not modify an unrelated location with a legacy stale mapping.');
pfp(pfp_retired(fn()=>table_service_seat($pdo,$org,$location3,$unrelatedPoint['publicId'],1,null,'',$user)), 'The unrelated location still rejects stale seating without an implicit write.');

$planData['items'][]=['id'=>'free-before-switch','type'=>'table','label'=>'Empty before switch','xPx'=>360,'yPx'=>120,'wPx'=>60,'hPx'=>60,'seats'=>2];
$pdo->prepare('UPDATE floor_plans SET plan_json=? WHERE organization_id=? AND public_id=?')->execute([json_encode($planData,JSON_THROW_ON_ERROR),$org,$planId]);
$freeOld=array_column(pos_floor_plan_sync_tables($pdo,$org,$location,$user)['bootstrap']['tables'],null,'floorPlanComponentId')['free-before-switch'];
pfp($freeOld['seatAllowed'],'The old point must be available before the concurrent publish.');
$pdo->beginTransaction();
try{
    pos_floor_plan_select($pdo,$org,$location,$nextPlan,$user);
    $db=app_config()['database'];
    $dsn=trim((string)($db['dsn']??''))?:sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$db['host']??'127.0.0.1',$db['port']??3306,$db['name'],$db['charset']??'utf8mb4');
    $racing=new PDO($dsn,(string)$db['username'],(string)$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $racing->exec('SET SESSION innodb_lock_wait_timeout=1');
    $blocked=false;
    try{table_service_seat($racing,$org,$location,$freeOld['publicId'],1,null,'',$user);}
    catch(PDOException $error){$blocked=(int)($error->errorInfo[1]??0)===1205;}
    pfp($blocked,'A racing seat must wait for the publishing transaction to release the old service point.');
    $pdo->commit();
}finally{if($pdo->inTransaction())$pdo->rollBack();}
pfp(pfp_retired(fn()=>table_service_seat($racing,$org,$location,$freeOld['publicId'],1,null,'',$user)),'The same request after publication must reject the old point.');
$replacement=pos_floor_plan_sync_tables($pdo,$org,$location,$user);
$newRows=array_column($replacement['bootstrap']['tables'],null,'publicId');
pfp($newRows[$tableA['publicId']]['retired']&&!$newRows[$tableA['publicId']]['seatAllowed']&&$newRows[$tableA['publicId']]['checkPublicId']===$seated['publicId'],'A previous-plan occupied point must preserve its active check with seating disabled.');
pfp(!$newRows[$manual['publicId']]['retired']&&$newRows[$manual['publicId']]['seatAllowed'],'Manual service points are independent of the plan lifecycle.');
$newTable=array_column($replacement['bootstrap']['tables'],null,'floorPlanComponentId')['table-c'];
$newCheck=table_service_seat($pdo,$org,$location,$newTable['publicId'],1,null,'',$user);

$archive=static function(string $publicId)use($pdo,$org,$user):void{
    table_service_transaction($pdo,static function()use($pdo,$org,$publicId,$user):void{
        pos_floor_plan_lock_publication($pdo,$org);
        $pdo->prepare('UPDATE floor_plans SET archived_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([$org,$publicId]);
        pos_floor_plan_after_archive($pdo,$org,$publicId,$user);
    });
};
$defaultPoint=pos_floor_plan_sync_tables($pdo,$org,$location2,$user)['bootstrap']['tables'][0];
$archive($planId);
pfp(pos_floor_plan_selected_id($pdo,$org,$location2)===$nextPlan,'When the default is archived the oldest active plan is the implicit fallback.');
pfp(pfp_retired(fn()=>table_service_seat($pdo,$org,$location2,$defaultPoint['publicId'],1,null,'',$user)), 'Archiving an implicit default also retires that location’s obsolete mapped points.');
$pdo->prepare('UPDATE floor_plans SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND public_id=?')->execute([$org,$sparePlan]);
pfp(pos_floor_plan_selected_id($pdo,$org,$location2)===$nextPlan,'Editing a different plan cannot republish it at an implicit location.');
$archive($nextPlan);
$archived=pos_floor_plan_bootstrap($pdo,$org,$location);$archivedRows=array_column($archived['tables'],null,'publicId');
pfp($archived['selectedPlan']===null&&$archived['selectedBy']==='none','Archiving an explicit assignment must clear it without falling through to another plan.');
pfp($archivedRows[$newTable['publicId']]['retired']&&$archivedRows[$newTable['publicId']]['checkPublicId']===$newCheck['publicId'],'Archiving an occupied layout must retain its canonical open check.');
pfp(pos_check_details($pdo,$org,$newCheck['publicId'])['status']==='open','Retirement must never close an existing check.');
pos_cancel_check($pdo,$org,$newCheck['publicId'],'Retirement fixture closed',$user);
table_service_release_closed_check($pdo,$org,$newCheck['publicId'],$user);
$afterClose=pos_floor_plan_bootstrap($pdo,$org,$location);
pfp(!in_array($newTable['publicId'],array_column($afterClose['tables'],'publicId'),true)&&pfp_retired(fn()=>table_service_seat($pdo,$org,$location,$newTable['publicId'],1,null,'',$user)), 'After closing the final check, the retired point must disappear without reopening for seating.');

$otherLocation=pos_floor_plan_bootstrap($pdo,$org,$location2);pfp(count($otherLocation['tables'])===0,'POS floor service points must remain location-scoped.');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Floor Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{pos_floor_plan_select($pdo,$otherOrg,$location,$sparePlan,$user);}catch(InvalidArgumentException){$isolated=true;}pfp($isolated,'Floor-plan selection must remain organization/location isolated.');

echo "pos-floor-plan-integration-ok\n";
