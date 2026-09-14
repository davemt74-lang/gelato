<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-floor-plan.php';

$pdo=app_pdo();
function pfp(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}

pfp(pos_floor_plan_ready($pdo),'POS floor-plan integration migration must be installed.');
$slug='pfp-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Floor '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Second','Phoenix','AZ','active')")->execute([$org]);$location2=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'POS','Lead','POS Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);

$planId='floorplan-'.$slug;
$planData=['version'=>5,'planWft'=>50,'planHft'=>34,'scale'=>24,'items'=>[
    ['id'=>'table-a','type'=>'table','label'=>'4-TOP','xPx'=>120,'yPx'=>96,'wPx'=>96,'hPx'=>72,'r'=>0,'seats'=>4,'seatZone'=>'dining'],
    ['id'=>'table-b','type'=>'table','label'=>'Patio 2','xPx'=>600,'yPx'=>240,'wPx'=>72,'hPx'=>72,'r'=>0,'seats'=>2,'seatZone'=>'dining'],
    ['id'=>'wall-a','type'=>'wall','label'=>'','xPx'=>0,'yPx'=>0,'wPx'=>1200,'hPx'=>6,'r'=>0,'seats'=>0,'seatZone'=>'none'],
]];
$pdo->prepare('INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,?,50,34,24,1,1,?,?)')->execute([$org,$planId,'Main Floor',json_encode($planData,JSON_THROW_ON_ERROR),$user,$user]);

$before=pos_floor_plan_bootstrap($pdo,$org,$location);
pfp($before['selectedPlan']['publicId']===$planId,'Default floor plan must be discoverable before an explicit location assignment.');
pfp($before['componentTableCount']===2&&$before['mappedCount']===0&&!$before['configured'],'Unsynced floor plan must report two plan tables and zero canonical mappings.');
pos_floor_plan_select($pdo,$org,$location,$planId,$user);
$sync=pos_floor_plan_sync_tables($pdo,$org,$location,$user);
pfp($sync['created']===2&&$sync['updated']===0&&$sync['orphaned']===0,'First sync must create exactly two canonical service tables.');
$after=$sync['bootstrap'];pfp($after['configured']&&$after['mappedCount']===2&&count($after['tables'])===2,'Synced floor plan must expose two linked canonical service tables.');
$linked=array_column($after['tables'],'floorPlanComponentId');sort($linked);pfp($linked===['table-a','table-b'],'Canonical tables must retain stable floor-plan component identities.');
$capacities=array_column($after['tables'],'capacity','floorPlanComponentId');pfp($capacities['table-a']===4&&$capacities['table-b']===2,'Floor-plan seat counts must become canonical table capacities.');

$repeat=pos_floor_plan_sync_tables($pdo,$org,$location,$user);pfp($repeat['created']===0&&$repeat['updated']===2&&count($repeat['bootstrap']['tables'])===2,'Floor sync must be idempotent.');
$tableA=null;foreach($repeat['bootstrap']['tables'] as $table)if($table['floorPlanComponentId']==='table-a')$tableA=$table;pfp(is_array($tableA),'Mapped table A must exist.');
$seated=table_service_seat($pdo,$org,$location,(string)$tableA['publicId'],3,null,'Floor-plan seating contract.',$user);pfp($seated['service']['tablePublicId']===$tableA['publicId']&&$seated['service']['partySize']===3,'Tapping a mapped table must create a canonical dine-in Table Service check.');
$live=pos_floor_plan_bootstrap($pdo,$org,$location);$liveA=null;foreach($live['tables'] as $table)if($table['publicId']===$tableA['publicId'])$liveA=$table;pfp($liveA['state']==='seated'&&$liveA['checkPublicId']===$seated['publicId'],'POS floor map must expose the live table/check state.');

$planData['items'][0]['xPx']=360;$planData['items'][0]['label']='Dining 1';
$pdo->prepare('UPDATE floor_plans SET plan_json=?,version=version+1,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([json_encode($planData,JSON_THROW_ON_ERROR),$user,$org,$planId]);
$moved=pos_floor_plan_sync_tables($pdo,$org,$location,$user);$movedA=null;foreach($moved['bootstrap']['tables'] as $table)if($table['publicId']===$tableA['publicId'])$movedA=$table;pfp($movedA['state']==='seated'&&$movedA['name']==='Dining 1'&&(float)$movedA['xPercent']>20,'Sync must update floor geometry/name without disturbing an active seated check.');

array_splice($planData['items'],1,1);$pdo->prepare('UPDATE floor_plans SET plan_json=?,version=version+1,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([json_encode($planData,JSON_THROW_ON_ERROR),$user,$org,$planId]);
$removed=pos_floor_plan_sync_tables($pdo,$org,$location,$user);pfp($removed['orphaned']===1&&count($removed['bootstrap']['tables'])===2,'Removing a plan component must not destructively delete an existing canonical service table.');

$otherLocation=pos_floor_plan_bootstrap($pdo,$org,$location2);pfp(count($otherLocation['tables'])===0,'POS floor tables must remain location-scoped.');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Floor Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{pos_floor_plan_select($pdo,$otherOrg,$location,$planId,$user);}catch(InvalidArgumentException){$isolated=true;}pfp($isolated,'Floor-plan selection must remain organization/location isolated.');

echo "pos-floor-plan-integration-ok\n";
