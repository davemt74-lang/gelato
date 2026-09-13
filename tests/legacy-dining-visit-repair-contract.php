<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/UpgradeService.php';
require_once __DIR__.'/../includes/service-visit-live.php';

$pdo=app_pdo();

function ldv(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}
function ldv_one(PDO $pdo,string $sql,array $args=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}
function ldv_check(PDO $pdo,int $org,int $location,int $user,string $tableName,int $guestCount): array
{
    return pos_create_check($pdo,$org,$location,[
        'serviceMode'=>'dine_in',
        'tableName'=>$tableName,
        'guestCount'=>$guestCount,
        'notes'=>'Legacy dining visit repair CI',
    ],$user);
}
function ldv_context(PDO $pdo,int $org,int $location,int $checkId,int $tableId,int $user,string $visitId,string $status='active'): int
{
    $pdo->prepare("INSERT INTO service_check_contexts (organization_id,location_id,check_id,visit_group_id,table_id,server_user_id,party_size,current_course_key,status,seated_at,created_by,updated_by) VALUES (?,?,?,?,?,?,4,'mains',?,NOW(6),?,?)")
        ->execute([$org,$location,$checkId,$visitId,$tableId,$user,$status,$user,$user]);
    return (int)$pdo->lastInsertId();
}
function ldv_split_event(PDO $pdo,int $org,int $location,int $tableId,int $childCheckId,string $sourcePublic,int $user): void
{
    $pdo->prepare("INSERT INTO service_events (organization_id,location_id,table_id,check_id,event_type,note,metadata_json,actor_user_id) VALUES (?,?,?,?,'check_split_created','Legacy split fixture.',?,?)")
        ->execute([$org,$location,$tableId,$childCheckId,json_encode(['sourceCheckPublicId'=>$sourcePublic],JSON_THROW_ON_ERROR),$user]);
}

ldv(service_visit_ready($pdo),'Dining visit schema must be installed before the repair contract runs.');

$slug='legacy-visit-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Legacy Visit CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);
$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Legacy','Manager','Legacy Manager']);
$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")
    ->execute([$org,$user,$location]);

$customerA=crm_customer_save($pdo,$org,[
    'firstName'=>'Alex','lastName'=>'Legacy','displayName'=>'Alex Legacy',
    'email'=>'alex-'.$slug.'@example.test','phone'=>'6025550201',
],$user,'manual');
$customerB=crm_customer_save($pdo,$org,[
    'firstName'=>'Blair','lastName'=>'Legacy','displayName'=>'Blair Legacy',
    'email'=>'blair-'.$slug.'@example.test','phone'=>'6025550202',
],$user,'manual');

$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,public_id,name,capacity,shape,state,status,created_by,updated_by) VALUES (?,?,'legacy-t1','Legacy T1',6,'rectangle','seated','active',?,?)")
    ->execute([$org,$location,$user,$user]);
$tableId=(int)$pdo->lastInsertId();

// Simulate a pre-20260922 split chain A -> B -> C. The 20260922 migration
// would have assigned each existing context a different visit-legacy-* id.
$a=ldv_check($pdo,$org,$location,$user,'Legacy T1',4);
$b=ldv_check($pdo,$org,$location,$user,'Legacy T1',4);
$c=ldv_check($pdo,$org,$location,$user,'Legacy T1',4);
ldv_context($pdo,$org,$location,(int)$a['id'],$tableId,$user,'visit-legacy-A');
ldv_context($pdo,$org,$location,(int)$b['id'],$tableId,$user,'visit-legacy-B');
ldv_context($pdo,$org,$location,(int)$c['id'],$tableId,$user,'visit-legacy-C');
$pdo->prepare("UPDATE service_tables SET active_check_id=? WHERE organization_id=? AND id=?")->execute([(int)$a['id'],$org,$tableId]);
ldv_split_event($pdo,$org,$location,$tableId,(int)$b['id'],(string)$a['publicId'],$user);
ldv_split_event($pdo,$org,$location,$tableId,(int)$c['id'],(string)$b['publicId'],$user);
$pdo->prepare('UPDATE pos_checks SET customer_id=? WHERE organization_id=? AND id=?')->execute([(int)$customerA['id'],$org,(int)$a['id']]);

// Same-table history without an explicit split edge must never be guessed into
// the visit. This protects independent historical checks from over-grouping.
$standalone=ldv_check($pdo,$org,$location,$user,'Legacy T1',1);
ldv_context($pdo,$org,$location,(int)$standalone['id'],$tableId,$user,'visit-legacy-STANDALONE','closed');
$pdo->prepare("UPDATE pos_checks SET status='cancelled',cancelled_at=NOW(6),cancel_reason='Historical standalone fixture' WHERE organization_id=? AND id=?")
    ->execute([$org,(int)$standalone['id']]);

// A separate legacy split with conflicting explicit customers must preserve
// those identities instead of silently overwriting either one.
$e=ldv_check($pdo,$org,$location,$user,'Legacy Conflict',2);
$f=ldv_check($pdo,$org,$location,$user,'Legacy Conflict',2);
ldv_context($pdo,$org,$location,(int)$e['id'],$tableId,$user,'visit-legacy-E','closed');
ldv_context($pdo,$org,$location,(int)$f['id'],$tableId,$user,'visit-legacy-F','closed');
ldv_split_event($pdo,$org,$location,$tableId,(int)$f['id'],(string)$e['publicId'],$user);
$pdo->prepare('UPDATE pos_checks SET customer_id=?,status=\'cancelled\',cancelled_at=NOW(6) WHERE organization_id=? AND id=?')->execute([(int)$customerA['id'],$org,(int)$e['id']]);
$pdo->prepare('UPDATE pos_checks SET customer_id=?,status=\'cancelled\',cancelled_at=NOW(6) WHERE organization_id=? AND id=?')->execute([(int)$customerB['id'],$org,(int)$f['id']]);

ldv((int)ldv_one($pdo,'SELECT COUNT(DISTINCT visit_group_id) FROM service_check_contexts WHERE organization_id=? AND check_id IN (?,?,?)',[$org,(int)$a['id'],(int)$b['id'],(int)$c['id']])===3,'Fixture must begin as three separate legacy visit ids.');
ldv(ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$b['id']])===null,'Legacy split child B must begin without CRM attribution.');
ldv(ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$c['id']])===null,'Legacy split child C must begin without CRM attribution.');

$sql=file_get_contents(__DIR__.'/../database/20260923_legacy_dining_visit_repair.sql');
if(!is_string($sql))throw new RuntimeException('Could not read legacy dining visit repair migration.');
UpgradeService::executeSqlScript($pdo,$sql,true);

$rootVisit=(string)ldv_one($pdo,'SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,(int)$a['id']]);
ldv($rootVisit==='visit-legacy-A','Root legacy visit id must remain stable.');
ldv((int)ldv_one($pdo,'SELECT COUNT(DISTINCT visit_group_id) FROM service_check_contexts WHERE organization_id=? AND check_id IN (?,?,?)',[$org,(int)$a['id'],(int)$b['id'],(int)$c['id']])===1,'Multi-level legacy split chain must collapse to one dining visit.');
ldv((string)ldv_one($pdo,'SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,(int)$standalone['id']])==='visit-legacy-STANDALONE','Unrelated same-table history must not be grouped without an explicit split event.');
ldv((int)ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$b['id']])===(int)$customerA['id'],'Null CRM attribution must backfill to legacy split child B.');
ldv((int)ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$c['id']])===(int)$customerA['id'],'Null CRM attribution must backfill through a multi-level legacy split chain.');
ldv((int)ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$e['id']])===(int)$customerA['id'],'Existing legacy customer A must remain unchanged.');
ldv((int)ldv_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,(int)$f['id']])===(int)$customerB['id'],'Conflicting legacy customer B must remain unchanged.');

// The repair is intentionally re-runnable for deterministic CI verification.
UpgradeService::executeSqlScript($pdo,$sql,true);
ldv((int)ldv_one($pdo,'SELECT COUNT(DISTINCT visit_group_id) FROM service_check_contexts WHERE organization_id=? AND check_id IN (?,?,?)',[$org,(int)$a['id'],(int)$b['id'],(int)$c['id']])===1,'Legacy repair must be idempotent when re-executed.');

// Prove the operational consequence: settling the legacy primary check must
// keep the physical table occupied by an open sibling until the whole visit is terminal.
$pdo->prepare("UPDATE pos_checks SET status='paid',closed_at=NOW(6),closed_hour=12 WHERE organization_id=? AND id=?")->execute([$org,(int)$a['id']]);
service_visit_reconcile_live($pdo,$org,$location,$user);
ldv((int)ldv_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND id=?',[$org,$tableId])===(int)$b['id'],'Legacy repaired table must repoint from paid root to first open split sibling.');
ldv((string)ldv_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND id=?',[$org,$tableId])==='seated','Legacy repaired table must remain seated while split siblings are open.');

$pdo->prepare("UPDATE pos_checks SET status='cancelled',cancelled_at=NOW(6),cancel_reason='CI sibling close' WHERE organization_id=? AND id=?")->execute([$org,(int)$b['id']]);
service_visit_reconcile_live($pdo,$org,$location,$user);
ldv((int)ldv_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND id=?',[$org,$tableId])===(int)$c['id'],'Legacy repaired table must continue to the final open split sibling.');

$pdo->prepare("UPDATE pos_checks SET status='paid',closed_at=NOW(6),closed_hour=12 WHERE organization_id=? AND id=?")->execute([$org,(int)$c['id']]);
service_visit_reconcile_live($pdo,$org,$location,$user);
ldv(ldv_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND id=?',[$org,$tableId])===null,'Legacy repaired table must release only after the final sibling is terminal.');
ldv((string)ldv_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND id=?',[$org,$tableId])==='dirty','Final legacy visit settlement must leave the table dirty.');

$profile=service_visit_crm_profile($pdo,$org,(string)$customerA['public_id']);
ldv((int)$profile['metrics']['visits']===1,'Repaired paid split siblings must count as one CRM dining visit.');

echo "legacy-dining-visit-repair-ok\n";
