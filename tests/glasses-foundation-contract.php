<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';

$pdo=app_pdo();
function glassesci_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function glassesci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

glassesci_assert(glasses_ready($pdo),'Glasses foundation migration must be installed.');
$slug='glasses-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Glasses CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Second Kitchen','Phoenix','AZ','active')")->execute([$org]);$location2=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'AR','Manager','AR Manager']);$user=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300],$user);
$station2=kds_station_save($pdo,$org,$location2,['name'=>'Expo','slug'=>'expo','targetSeconds'=>180],$user);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
glassesci_assert((bool)preg_match('/^[A-F0-9]{12}$/',(string)$grant['pairingCode']),'Pairing code format must be deterministic and compact.');
glassesci_assert((int)glassesci_one($pdo,'SELECT COUNT(*) FROM glasses_pairing_grants WHERE organization_id=?',[$org])===1,'Pairing grant must persist.');

$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-CI-'.$slug,
    'displayName'=>'Prep Glasses',
    'sdkVersion'=>'0.7.3',
    'appVersion'=>'0.1.0',
    'systemVersion'=>'3.4.test',
    'capabilities'=>['camera'=>'grayscale','dof'=>6,'display'=>'binocular'],
]);
glassesci_assert(strlen((string)$paired['deviceToken'])===64,'Pairing must return a 256-bit bearer token.');
glassesci_assert((string)$paired['device']['stationPublicId']===(string)$station['public_id'],'Pairing must preserve station assignment.');
glassesci_assert((string)$paired['device']['sdkVersion']==='0.7.3','Pairing must record SDK version.');
glassesci_assert((int)glassesci_one($pdo,'SELECT COUNT(*) FROM glasses_pairing_grants WHERE organization_id=? AND consumed_at IS NOT NULL',[$org])===1,'Pairing grant must be single-use.');
glassesci_assert((int)glassesci_one($pdo,'SELECT COUNT(*) FROM glasses_devices WHERE organization_id=? AND token_hash=?',[$org,(string)$paired['deviceToken']])===0,'Raw bearer token must never be stored.');

$reuseBlocked=false;try{glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'reuse']);}catch(InvalidArgumentException){$reuseBlocked=true;}
glassesci_assert($reuseBlocked,'Consumed pairing code must not be reusable.');

$auth=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
glassesci_assert((string)$auth['public_id']===(string)$paired['device']['publicId'],'Bearer token must authenticate the paired device.');

$heartbeat=glasses_heartbeat($pdo,$auth,['appVersion'=>'0.1.1','capabilities'=>['camera'=>'grayscale','dof'=>6]]);
glassesci_assert((string)$heartbeat['appVersion']==='0.1.1'&&!empty($heartbeat['lastSeenAt']),'Heartbeat must refresh version and last-seen state.');
glassesci_assert((int)glassesci_one($pdo,"SELECT COUNT(*) FROM glasses_device_events WHERE organization_id=? AND event_type='heartbeat'",[$org])===1,'Heartbeat must be auditable.');

$assigned=glasses_device_assign($pdo,$org,(string)$paired['device']['publicId'],$location2,(string)$station2['public_id'],$user);
glassesci_assert((int)$assigned['locationId']===$location2&&(string)$assigned['stationPublicId']===(string)$station2['public_id'],'Device assignment must move location + station atomically.');

$wrongStationBlocked=false;try{glasses_device_assign($pdo,$org,(string)$paired['device']['publicId'],$location,(string)$station2['public_id'],$user);}catch(InvalidArgumentException){$wrongStationBlocked=true;}
glassesci_assert($wrongStationBlocked,'Device assignment must reject a station from another location.');

$renamed=glasses_device_rename($pdo,$org,(string)$paired['device']['publicId'],'Line 1 AIR3',$user);
glassesci_assert((string)$renamed['displayName']==='Line 1 AIR3','Device rename must persist.');

$revoked=glasses_device_revoke($pdo,$org,(string)$paired['device']['publicId'],$user);
glassesci_assert((string)$revoked['status']==='revoked'&&!empty($revoked['revokedAt']),'Device revoke must be terminal until re-pair.');
$oldTokenBlocked=false;try{glasses_authenticate_token($pdo,(string)$paired['deviceToken']);}catch(InvalidArgumentException){$oldTokenBlocked=true;}
glassesci_assert($oldTokenBlocked,'Revocation must immediately invalidate the old bearer token.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$repaired=glasses_pair_device($pdo,(string)$grant2['pairingCode'],['hardwareIdentifier'=>'AIR3-CI-'.$slug,'displayName'=>'Prep Glasses']);
glassesci_assert((string)$repaired['device']['status']==='active','Revoked hardware must be re-pairable.');
glassesci_assert((string)$repaired['device']['publicId']===(string)$paired['device']['publicId'],'Re-pair must retain stable device identity.');
glassesci_assert((string)$repaired['deviceToken']!==(string)$paired['deviceToken'],'Re-pair must issue a fresh bearer token.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Other Org '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$isolation=false;try{glasses_device_row($pdo,$otherOrg,(string)$repaired['device']['publicId']);}catch(InvalidArgumentException){$isolation=true;}
glassesci_assert($isolation,'Device inventory must be organization-isolated.');

glassesci_assert((int)glassesci_one($pdo,"SELECT COUNT(*) FROM permissions WHERE permission_key IN ('glasses.view','glasses.manage')")===2,'Glasses permissions must be installed.');
glassesci_assert((int)glassesci_one($pdo,"SELECT COUNT(*) FROM glasses_device_events WHERE organization_id=? AND event_type IN ('paired','repaired','assignment_changed','renamed','revoked')",[$org])>=5,'Lifecycle events must be append-only and complete.');

echo "glasses-foundation-ok\n";
