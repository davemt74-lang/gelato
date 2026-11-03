<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/hardening-media-retention-fixture.php';

$checks=0;
function hdmn_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hdmn_value(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function hdmn_row(PDO $pdo,string $sql,array $args=[]): array {$q=$pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Missing retention fixture row.');return $row;}

$pdo=app_pdo();$f=hdm_seed($pdo);$org=$f['org'];$actor=$f['actor'];
$media=glasses_vision_training_media_row($pdo,$org,$f['media'][3]['publicId']);$mediaId=(int)$media['id'];
$mediaPath=glasses_vision_training_media_storage_root().'/'.$media['storage_relative_path'];
$mediaSql='SELECT status,deleted_at,deleted_by,delete_reason FROM glasses_vision_training_media WHERE organization_id=? AND id=?';
$mediaBefore=hdmn_row($pdo,$mediaSql,[$org,$mediaId]);
$mediaTrigger='hdmn_media_'.bin2hex(random_bytes(5));
$sentinel='Nested media cleanup '.bin2hex(random_bytes(4));

// DDL is outside the caller transaction. Only the success event fails; the batch
// can still record its retryable failure after rolling the mutation back.
$pdo->exec("CREATE TRIGGER {$mediaTrigger} BEFORE INSERT ON glasses_vision_training_media_events FOR EACH ROW BEGIN IF NEW.organization_id={$org} AND NEW.media_id={$mediaId} AND NEW.event_type='deleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected nested media event failure'; END IF; END");
try{
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE organizations SET name=? WHERE id=?')->execute([$sentinel,$org]);
    $cleanup=glasses_vision_training_media_expire_batch($pdo,$org,$actor,1);
    hdmn_check($cleanup['expired']===0&&$cleanup['failed']===1&&$cleanup['scanned']===1,'A nested media event failure must be reported as one failed cleanup.');
    hdmn_check($pdo->inTransaction(),'A nested media failure must preserve the caller transaction.');
    hdmn_check(hdmn_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])===$sentinel,'A nested media failure must preserve earlier caller work.');
    hdmn_check(hdmn_row($pdo,$mediaSql,[$org,$mediaId])===$mediaBefore,'Failed nested cleanup must roll back deleted status and deletion metadata.');
    hdmn_check(!file_exists($mediaPath),'The event failure is injected after actual media bytes were removed.');
    hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='deleted'",[$org,$mediaId])===0,'Failed nested cleanup cannot record a successful deletion event.');
    hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='retention_cleanup_failed'",[$org,$mediaId])===1,'A retryable nested media failure must retain its failure event.');
    $pdo->commit();
    hdmn_check(hdmn_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])===$sentinel&&hdmn_row($pdo,$mediaSql,[$org,$mediaId])===$mediaBefore,'Committing caller work must leave failed media cleanup eligible for retry.');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP TRIGGER IF EXISTS '.$mediaTrigger);
}
$retry=glasses_vision_training_media_expire_batch($pdo,$org,$actor,1);
hdmn_check($retry['expired']===1&&$retry['failed']===0,'Media cleanup must succeed on retry when its bytes are already absent.');
hdmn_check(hdmn_value($pdo,'SELECT status FROM glasses_vision_training_media WHERE organization_id=? AND id=?',[$org,$mediaId])==='deleted','Successful retry records deleted media status.');
hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='deleted'",[$org,$mediaId])===1,'Successful retry records exactly one deletion event.');

$release=glasses_vision_training_release_row($pdo,$org,$f['release']['publicId']);$releaseId=(int)$release['id'];
$releaseSql='SELECT status,manifest_json,artifact_relative_path,artifact_sha256,artifact_bytes FROM glasses_vision_training_releases WHERE organization_id=? AND id=?';
$releaseBefore=hdmn_row($pdo,$releaseSql,[$org,$releaseId]);
$releaseTrigger='hdmn_release_'.bin2hex(random_bytes(5));
$sentinel='Nested release cleanup '.bin2hex(random_bytes(4));
$pdo->exec("CREATE TRIGGER {$releaseTrigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id={$org} AND NEW.action='glasses.training_release_expired' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected nested release audit failure'; END IF; END");
try{
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE organizations SET name=? WHERE id=?')->execute([$sentinel,$org]);
    $cleanup=glasses_vision_training_release_expire_batch($pdo,1,$releaseId-1);
    hdmn_check($cleanup['expired']===0&&$cleanup['failed']===1&&$cleanup['scanned']===1,'A nested release audit failure must be reported as one failed cleanup.');
    hdmn_check($pdo->inTransaction(),'A nested release failure must preserve the caller transaction.');
    hdmn_check(hdmn_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])===$sentinel,'A nested release failure must preserve earlier caller work.');
    hdmn_check(hdmn_row($pdo,$releaseSql,[$org,$releaseId])===$releaseBefore,'Failed nested cleanup must roll back expired status and preserve release evidence.');
    hdmn_check(!file_exists($f['artifact']['path']),'The release audit failure is injected after actual ZIP bytes were removed.');
    hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_release_expired'",[$org])===0,'Failed nested release cleanup cannot record a successful expiration audit.');
    hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_release_cleanup_failed'",[$org])===1,'A retryable nested release failure must retain its failure audit.');
    $pdo->commit();
    hdmn_check(hdmn_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])===$sentinel&&hdmn_row($pdo,$releaseSql,[$org,$releaseId])===$releaseBefore,'Committing caller work must leave failed release cleanup eligible for retry.');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP TRIGGER IF EXISTS '.$releaseTrigger);
}
$retry=glasses_vision_training_release_expire_batch($pdo,1,$releaseId-1);
hdmn_check($retry['expired']===1&&$retry['failed']===0,'Release cleanup must succeed on retry when its ZIP bytes are already absent.');
$releaseAfter=hdmn_row($pdo,$releaseSql,[$org,$releaseId]);$expected=$releaseBefore;$expected['status']='expired';
hdmn_check($releaseAfter===$expected,'Successful release retry changes only expiration status and preserves manifest and artifact evidence.');
hdmn_check((int)hdmn_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_release_expired'",[$org])===1,'Successful release retry records exactly one expiration audit.');
echo "media-retention nested rollback: {$checks} checks passed\n";
