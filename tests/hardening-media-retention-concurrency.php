<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/hardening-media-retention-fixture.php';
if(($argv[1]??'')==='worker'){
    $in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    echo json_encode(['connection'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
    try{glasses_vision_training_qualification_run($pdo,(int)$in['org'],(string)$in['release'],[],(int)$in['actor']);echo json_encode(['qualified'=>true],JSON_THROW_ON_ERROR);}
    catch(GlassesVisionMediaUnavailable $e){echo json_encode(['qualified'=>false,'reason'=>'media_unavailable'],JSON_THROW_ON_ERROR);}
    exit;
}
$checks=0;
function hdmc_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$pdo=app_pdo();$f=hdm_seed($pdo);$org=$f['org'];
$fresh=glasses_vision_training_release_build($pdo,$org,$f['dataset'],'yolo11n_640',$f['actor']);
$release=glasses_vision_training_release_row($pdo,$org,$fresh['publicId']);
$artifact=glasses_vision_training_release_artifact($pdo,$org,$fresh['publicId']);
$process=null;$pipes=[];
try{
    $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT id FROM glasses_vision_training_releases WHERE organization_id=? AND id=? FOR UPDATE');$lock->execute([$org,(int)$release['id']]);
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not launch qualification worker.');
    fwrite($pipes[0],json_encode(['org'=>$org,'release'=>$fresh['publicId'],'actor'=>$f['actor']],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
    $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$connection=(int)$ready['connection'];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id=?");
    $deadline=microtime(true)+5;$waiting=false;
    do{$q->execute([$connection]);$waiting=(int)$q->fetchColumn()===1;if($waiting)break;usleep(200000);}while(microtime(true)<$deadline);
    hdmc_check($waiting,'Qualification is observed waiting on the locked release before cleanup.');
    $pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND public_id=?')->execute([$org,$f['media'][0]['publicId']]);
    $cleaned=glasses_vision_training_release_expire_batch($pdo,1,(int)$release['id']-1);
    hdmc_check($cleaned['expired']===1,'Competing cleanup expires the exact held release.');
    $pdo->commit();
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;
    hdmc_check($exit===0,'Qualification worker must not hide a database error: '.$error);
    $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    hdmc_check($result===['qualified'=>false,'reason'=>'media_unavailable'],'Qualification rechecks expiry after the lock wait.');
    $row=glasses_vision_training_release_row($pdo,$org,$fresh['publicId']);
    hdmc_check($row['status']==='expired','Late qualification cannot restore an expired status.');
    hdmc_check(!file_exists($artifact['path']),'Late qualification cannot recreate the cleaned ZIP.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM glasses_vision_training_qualifications WHERE organization_id=? AND training_release_id=?');$q->execute([$org,(int)$release['id']]);
    hdmc_check((int)$q->fetchColumn()===0,'Denied qualification adds no misleading attestation.');
    $copies=glob(dirname($artifact['path']).'/'.$fresh['publicId'].'-qualified-*.zip')?:[];
    hdmc_check($copies===[],'No qualified image copy is published after cleanup.');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if(is_resource($process))proc_terminate($process);
    foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
    if(is_resource($process))proc_close($process);
}
echo "media-retention concurrency: {$checks} checks passed\n";
