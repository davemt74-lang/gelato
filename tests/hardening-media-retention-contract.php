<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/hardening-media-retention-fixture.php';
$checks=0;
function hdm_assert(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hdm_denied(callable $work,string $label): void {try{$work();}catch(GlassesVisionMediaUnavailable $e){hdm_assert(true,$label);return;}throw new RuntimeException($label);}
function hdm_value(PDO $pdo,string $sql,array $args=[]):mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
$pdo=app_pdo();$f=hdm_seed($pdo);$org=$f['org'];$actor=$f['actor'];$expired=$f['media'][3]['publicId'];
$manifest=glasses_vision_training_media_export_manifest($pdo,$org,$f['dataset']);
hdm_assert(!in_array($expired,array_column($manifest['items'],'publicId'),true),'Expired active media must be absent from export before cleanup.');
hdm_assert($manifest['mediaCount']===3,'Only retained media enters an export.');
hdm_assert(glasses_vision_training_media_dataset_quality($pdo,$org,$f['dataset'])['mediaCount']===3,'Dataset quality excludes expired active media.');
if(($argv[1]??'')==='availability-only'){echo "media-retention availability: {$checks} checks passed\n";exit;}

$now=new DateTimeImmutable('2030-01-01 00:00:00.123456',new DateTimeZone('UTC'));
$oldZone=date_default_timezone_get();date_default_timezone_set('America/Los_Angeles');
foreach([
    [['status'=>'active','retention_until'=>null],true],
    [['status'=>'active','retention_until'=>'2030-01-01 00:00:00.123457'],true],
    [['status'=>'active','retention_until'=>'2030-01-01 00:00:00.123456'],false],
    [['status'=>'active','retention_until'=>'2030-01-01 00:00:00.123455'],false],
    [['status'=>'active','retention_until'=>'2030-02-30 00:00:00'],false],
    [['status'=>'active','retention_until'=>'invalid'],false],
    [['status'=>'active'],false],
    [['status'=>'deleted','retention_until'=>null],false],
] as [$row,$expected])hdm_assert(glasses_vision_media_retained($row,$now)===$expected,'UTC microsecond deadline and malformed-date policy.');
date_default_timezone_set($oldZone);
$row=glasses_vision_training_media_row($pdo,$org,$expired);
hdm_assert($row['status']==='active'&&is_file(glasses_vision_training_media_storage_root().'/'.$row['storage_relative_path']),'Expiry reproduction uses an active row with bytes still present.');
hdm_denied(fn()=>glasses_vision_media_require_retained($row),'Shared access guard denies expired active media.');
hdm_assert(glasses_vision_training_media_public($row)['status']==='expired','Catalog truthfully reports logical expiry before cleanup.');
hdm_assert(glasses_vision_balance_media($pdo,$org,$f['datasetId'])['mediaCount']===3,'Balance diversity does not use expired images.');
$releaseRows=glasses_vision_training_release_rows($pdo,$org,$f['datasetId']);
hdm_assert(count($releaseRows)===3&&!in_array($expired,array_column($releaseRows,'media_public_id'),true),'Training release selection excludes expired images.');
hdm_denied(fn()=>glasses_vision_training_release_artifact($pdo,$org,$f['release']['publicId']),'An already-built ZIP cannot extend source retention.');
hdm_denied(fn()=>glasses_vision_training_qualification_evaluate($pdo,$org,$f['release']['publicId']),'Expired release cannot qualify for training.');
$source=glasses_vision_retraining_source($pdo,$org,['production_error_id'=>$f['errorId']]);
hdm_assert($source['eligibility']==='ineligible'&&$source['reason']==='training_media_retention_expired','Explicit retraining source rejects an expired image.');
$fresh=glasses_vision_training_release_build($pdo,$org,$f['dataset'],'yolo11n_640',$actor);
hdm_assert($fresh['manifest']['itemCount']===3,'New training ZIP contains retained images only.');
hdm_assert(glasses_vision_training_release_artifact($pdo,$org,$fresh['publicId'])['bytes']>0,'Retained training ZIP remains downloadable.');
$qualification=glasses_vision_training_qualification_evaluate($pdo,$org,$fresh['publicId']);
hdm_assert(($qualification['summary']['classCounts']['ingredient']??0)===3,'Qualification counts exactly the retained release samples, not four historical dataset samples.');
$foreign=hdm_seed($pdo);$foreignExpired=$foreign['media'][3]['publicId'];
hdm_assert(!in_array($foreignExpired,array_column(glasses_vision_training_media_export_manifest($pdo,$org)['items'],'publicId'),true),'Export remains tenant scoped.');
try{glasses_vision_training_media_row($pdo,$foreign['org'],$expired);throw new RuntimeException('Foreign media lookup accepted.');}catch(InvalidArgumentException $e){hdm_assert(true,'Foreign media lookup denies.');}

$cleanup=glasses_vision_training_media_expire($pdo,$org,$actor,1);
hdm_assert($cleanup['expired']===1&&$cleanup['failed']===0,'Bounded cleanup deletes one expired source.');
hdm_assert(!file_exists(glasses_vision_training_media_storage_root().'/'.$row['storage_relative_path']),'Cleanup removed the actual image bytes.');
hdm_assert(glasses_vision_training_media_row($pdo,$foreign['org'],$foreignExpired)['status']==='active','Tenant cleanup leaves foreign expired rows untouched.');
$eventCount=(int)hdm_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='deleted'",[$org,(int)$row['id']]);
hdm_assert(glasses_vision_training_media_expire($pdo,$org,$actor,1)['expired']===0,'Cleanup is idempotent.');
hdm_assert((int)hdm_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='deleted'",[$org,(int)$row['id']])===$eventCount,'Idempotent cleanup does not duplicate deletion events.');

// A directory at the exact file path causes deterministic unlink failure even as root.
$failure=glasses_vision_training_media_row($pdo,$org,$f['media'][0]['publicId']);$failurePath=glasses_vision_training_media_storage_root().'/'.$failure['storage_relative_path'];
unlink($failurePath);mkdir($failurePath,0700);
$missing=glasses_vision_training_media_row($pdo,$org,$f['media'][1]['publicId']);$missingPath=glasses_vision_training_media_storage_root().'/'.$missing['storage_relative_path'];unlink($missingPath);
$pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND id IN (?,?)')->execute([$org,(int)$failure['id'],(int)$missing['id']]);
$cleanup=glasses_vision_training_media_expire($pdo,$org,$actor,10);
hdm_assert($cleanup['failed']===1&&$cleanup['expired']===1,'One filesystem failure does not stop other eligible cleanup.');
hdm_assert(glasses_vision_training_media_row($pdo,$org,$failure['public_id'])['status']==='active','Failed unlink never claims the row was deleted.');
hdm_assert((int)hdm_value($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=? AND event_type='retention_cleanup_failed'",[$org,(int)$failure['id']])===1,'Cleanup failure is audited and retryable.');
hdm_assert(glasses_vision_training_media_row($pdo,$org,$missing['public_id'])['status']==='deleted','Already missing bytes are recorded as cleaned without error.');
rmdir($failurePath);file_put_contents($failurePath,hdm_png(110));
hdm_assert(glasses_vision_training_media_expire($pdo,$org,$actor,10)['expired']===1,'Retry removes a repaired inaccessible file.');

// Cursor pages complete and then wrap; failed records can never starve later IDs.
$last=glasses_vision_training_media_row($pdo,$org,$f['media'][2]['publicId']);
$pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND id=?')->execute([$org,(int)$last['id']]);
$page=glasses_vision_training_media_expire_batch($pdo,$org,$actor,1,0);
hdm_assert($page['expired']===1&&$page['nextAfterId']===(int)$last['id'],'Cleanup advances its bounded cursor.');
hdm_assert(glasses_vision_training_media_expire_batch($pdo,$org,$actor,1,$page['nextAfterId'])['nextAfterId']===0,'End of cleanup scan wraps for future expirations and retries.');
$releaseId=(int)hdm_value($pdo,'SELECT id FROM glasses_vision_training_releases WHERE organization_id=? AND public_id=?',[$org,$f['release']['publicId']]);
$releaseCleanup=glasses_vision_training_release_expire_batch($pdo,1,$releaseId-1);
hdm_assert($releaseCleanup['expired']===1&&!is_file($f['artifact']['path']),'Cleanup removes derived ZIP bytes after source expiry.');
hdm_assert(glasses_vision_training_release_row($pdo,$org,$f['release']['publicId'])['status']==='expired','Derived ZIP retains manifest history with expired status.');
hdm_assert((int)hdm_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_release_expired'",[$org])===1,'Derived artifact deletion is audited.');
hdm_assert(is_file($foreign['artifact']['path']),'Bounded release cleanup does not delete later unselected artifacts.');
echo "media-retention contract: {$checks} checks passed\n";
