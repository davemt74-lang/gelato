<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-vision-operations.php';

$pdo=app_pdo();
function gvo_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gvo_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

gvo_assert(glasses_vision_ops_ready($pdo),'Vision Operations requires the complete glasses/vision schema.');
$slug='gvo-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Ops '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Fleet Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Fleet','Admin','Fleet Admin']);$uid=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'pizza-line','targetSeconds'=>300],$uid);
$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$uid,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-FLEET-'.$slug,'displayName'=>'Pizza AIR3','sdkVersion'=>'0.7.3','appVersion'=>'2.0.0']);
$auth=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
glasses_heartbeat($pdo,$auth,['appVersion'=>'2.0.1']);

$user=['organization_id'=>$org,'permissions'=>['glasses.view'],'is_owner_role'=>0];
$catalog=glasses_vision_ops_catalog($pdo,$user);
gvo_assert($catalog['ready']===true,'Fleet catalog must report readiness.');
gvo_assert(count($catalog['devices'])===1,'Fleet catalog must expose organization devices.');
$device=$catalog['devices'][0];
gvo_assert($device['displayName']==='Pizza AIR3'&&$device['health']['state']==='warning','Active station device without calibration must be visible as warning.');
gvo_assert($device['health']['reason']==='Station has no active calibration','Missing calibration must be explained.');
gvo_assert($catalog['summary']['total']===1&&$catalog['summary']['warning']===1,'Summary must count fleet health states.');
gvo_assert(count($catalog['needsAttention'])===1,'Needs-attention queue must include warning devices.');
gvo_assert(count($catalog['timeline'])>=2,'Fleet timeline must combine pairing/heartbeat history.');

$healthy=glasses_vision_ops_device_health(['deviceStatus'=>'active','lastSeenAt'=>date('Y-m-d H:i:s'),'driftState'=>'stable','unresolvedIncident'=>null,'runtimeState'=>'ready','runtimeErrorCode'=>null,'assignmentAction'=>'apply','calibrationPublicId'=>'calibration-1','stationPublicId'=>'station-1']);
gvo_assert($healthy['state']==='healthy','Current heartbeat/model/calibration state must classify healthy.');
$critical=glasses_vision_ops_device_health(['deviceStatus'=>'active','lastSeenAt'=>date('Y-m-d H:i:s'),'driftState'=>'critical','unresolvedIncident'=>null]);
gvo_assert($critical['state']==='critical','Critical drift must outrank other health signals.');
$offline=glasses_vision_ops_device_health(['deviceStatus'=>'active','lastSeenAt'=>date('Y-m-d H:i:s',time()-1800),'driftState'=>'stable','unresolvedIncident'=>null]);
gvo_assert($offline['state']==='offline','Heartbeat older than 15 minutes must classify offline.');

$page=file_get_contents(__DIR__.'/../glasses-vision-operations.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-operations.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-operations.js');
gvo_assert(str_contains($page,'Vision Operations &amp; Fleet Health')&&str_contains($page,'gvoAttention')&&str_contains($page,'gvoTimeline'),'Dashboard must expose summary, attention and timeline surfaces.');
gvo_assert(str_contains($page,'This dashboard aggregates existing ledgers only'),'Dashboard must state its read-only authority boundary.');
gvo_assert(str_contains($api,'glasses_vision_ops_catalog')&&!str_contains($api,'glasses_build_observe'),'Operations API must remain read-only and never mutate build truth.');
gvo_assert(str_contains($js,'All locations')&&str_contains($js,'All health states')&&str_contains($js,'renderAttention'),'Dashboard must support fleet filtering and needs-attention rendering.');

echo "glasses-vision-operations-ok\n";
