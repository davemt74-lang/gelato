<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/table-service-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';

$pdo=app_pdo();
function tsr_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function tsr_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

tsr_assert(table_service_ready($pdo),'Table Service must be installed.');
$slug='table-reconcile-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Table Reconcile CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,'Dining Room','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Floor','Manager','Floor Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO service_sections (organization_id,location_id,public_id,name,status,created_by,updated_by) VALUES (?,?,?,?, 'active',?,?)")->execute([$org,$location,table_service_public_id('section'),'Dining',$user,$user]);$section=(int)$pdo->lastInsertId();

$now=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d H:i:s.u');$date=substr($now,0,10);
$pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,public_id,check_number,business_date,service_mode,table_name,guest_count,status,currency,opened_by,opened_at) VALUES (?,?,?,?,?,'dine_in','T1',2,'open','USD',?,?)")->execute([$org,$location,table_service_public_id('check'),'PRIMARY-'.$slug,$date,$user,$now]);$primary=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,public_id,check_number,business_date,service_mode,table_name,guest_count,status,currency,opened_by,opened_at,cancelled_at,cancel_reason,cancelled_by) VALUES (?,?,?,?,?,'dine_in','T1',2,'cancelled','USD',?,?,NOW(6),'Split closed in CI',?)")->execute([$org,$location,table_service_public_id('check'),'SPLIT-'.$slug,$date,$user,$now,$user]);$secondary=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,section_id,public_id,name,capacity,state,active_check_id,assigned_user_id,seated_at,status,created_by,updated_by) VALUES (?,?,?,?,?,4,'seated',?,?,NOW(6),'active',?,?)")->execute([$org,$location,$section,table_service_public_id('table'),'T1',$primary,$user,$user,$user]);$table=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO service_check_contexts (organization_id,location_id,check_id,table_id,server_user_id,party_size,current_course_key,status,seated_at,created_by,updated_by) VALUES (?,?,?,?,?,2,'mains','active',NOW(6),?,?)")->execute([$org,$location,$primary,$table,$user,$user,$user]);
$pdo->prepare("INSERT INTO service_check_contexts (organization_id,location_id,check_id,table_id,server_user_id,party_size,current_course_key,status,seated_at,created_by,updated_by) VALUES (?,?,?,?,?,2,'mains','active',NOW(6),?,?)")->execute([$org,$location,$secondary,$table,$user,$user,$user]);

$count=table_service_reconcile_closed_checks($pdo,$org,$location,$user);tsr_assert($count===1,'Only the closed secondary split should reconcile first.');
tsr_assert((string)tsr_one($pdo,'SELECT status FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,$secondary])==='closed','Closed secondary split context must close.');
tsr_assert((string)tsr_one($pdo,'SELECT status FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,$primary])==='active','Primary open context must remain active.');
tsr_assert((int)tsr_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND id=?',[$org,$table])===$primary,'Secondary split closure must not clear the primary table check.');
tsr_assert((string)tsr_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND id=?',[$org,$table])==='seated','Secondary split closure must not dirty the occupied table.');
$meta=(string)tsr_one($pdo,"SELECT metadata_json FROM service_events WHERE organization_id=? AND check_id=? AND event_type='check_reconciled' ORDER BY id DESC LIMIT 1",[$org,$secondary]);tsr_assert(str_contains($meta,'"releasedTable":false'),'Secondary reconcile event must record releasedTable=false.');

$pdo->prepare("UPDATE pos_checks SET status='paid',closed_at=NOW(6),closed_by=? WHERE organization_id=? AND id=?")->execute([$user,$org,$primary]);
$count=table_service_reconcile_closed_checks($pdo,$org,$location,$user);tsr_assert($count===1,'Primary closed check should reconcile second.');
tsr_assert(tsr_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND id=?',[$org,$table])===null,'Primary closure must release the table pointer.');
tsr_assert((string)tsr_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND id=?',[$org,$table])==='dirty','Primary closure must move the physical table to Dirty.');
$meta=(string)tsr_one($pdo,"SELECT metadata_json FROM service_events WHERE organization_id=? AND check_id=? AND event_type='check_reconciled' ORDER BY id DESC LIMIT 1",[$org,$primary]);tsr_assert(str_contains($meta,'"releasedTable":true'),'Primary reconcile event must record releasedTable=true.');

echo "table-service reconcile contract passed\n";
