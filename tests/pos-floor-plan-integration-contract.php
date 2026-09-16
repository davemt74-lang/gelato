<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-floor-plan.php';

$pdo=app_pdo();
function pfp(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}

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
$tableA=null;$barSeat=null;foreach($repeat['bootstrap']['tables'] as $table){if($table['floorPlanComponentId']==='table-a')$tableA=$table;if($table['floorPlanComponentId']==='bar-seat-a')$barSeat=$table;}
pfp(is_array($tableA),'Mapped table A must exist.');pfp(is_array($barSeat),'Mapped bar seat must exist.');
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
$removed=pos_floor_plan_sync_tables($pdo,$org,$location,$user);pfp($removed['orphaned']===1&&count($removed['bootstrap']['tables'])===3,'Removing a plan component must not destructively delete an existing canonical service point.');

$otherLocation=pos_floor_plan_bootstrap($pdo,$org,$location2);pfp(count($otherLocation['tables'])===0,'POS floor service points must remain location-scoped.');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Floor Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{pos_floor_plan_select($pdo,$otherOrg,$location,$planId,$user);}catch(InvalidArgumentException){$isolated=true;}pfp($isolated,'Floor-plan selection must remain organization/location isolated.');

echo "pos-floor-plan-integration-ok\n";
