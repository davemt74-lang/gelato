<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';
require_once __DIR__.'/../includes/glasses-vision-training-qualification.php';
require_once __DIR__.'/../includes/glasses-vision-retraining.php';

function hdm_png(int $value): string {
    $row="\x00".str_repeat(chr($value).chr(100).chr(80),320);
    $chunk=static fn(string $type,string $bytes):string=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',320,240,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat($row,240),9)).$chunk('IEND','');
}
function hdm_seed(PDO $pdo,?int $org=null,?int $actor=null): array {
    $suffix='hd-media-'.bin2hex(random_bytes(5));
    if($org===null){
        $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix]);$org=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,'Retention','Test','Retention Test','active')")->execute([$suffix.'@example.test',app_password_hash('Media-Retention-Test!42')]);$actor=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$actor]);
    }
    if(!$actor)throw new RuntimeException('Fixture actor is required.');
    $dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>$suffix,'versionLabel'=>'v1'],$actor);
    $media=[];
    foreach(['train','val','test','train'] as $i=>$split){
        $m=glasses_vision_training_media_store($pdo,$org,[
            'imageBase64'=>base64_encode(hdm_png(110+$i)),'consentBasis'=>'training_media_opt_in','retentionDays'=>1,
            'annotations'=>[['label'=>'ingredient','bbox'=>['x'=>.1,'y'=>.1,'width'=>.4,'height'=>.4]]],
            'brightnessMean'=>.5,'contrastMean'=>.3,'blurScore'=>120,'captureGroup'=>$suffix.'-'.$i,
        ],$actor);
        glasses_vision_lab_review_sample($pdo,$org,$m['samplePublicId'],['decision'=>'approve','notes'=>'Reviewed retention fixture'],$actor);
        glasses_vision_lab_add_dataset_sample($pdo,$org,$dataset['publicId'],$m['samplePublicId'],$split);
        $media[]=$m;
    }
    // Persist fixture state required by the real release builder; no fake release bytes.
    $dq=$pdo->prepare('SELECT id FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=?');$dq->execute([$org,$dataset['publicId']]);$datasetId=(int)$dq->fetchColumn();
    $pdo->prepare("UPDATE glasses_vision_dataset_versions SET status='frozen',dataset_hash=?,frozen_at=UTC_TIMESTAMP(6),frozen_by=? WHERE organization_id=? AND id=?")->execute([hash('sha256',$suffix),$actor,$org,$datasetId]);
    $pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans(organization_id,public_id,dataset_id,status,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied','{}','{}',?,?,?,UTC_TIMESTAMP(6))")
        ->execute([$org,$suffix.'-plan',$datasetId,hash('sha256',$suffix.'plan'),$actor,$actor]);
    $release=glasses_vision_training_release_build($pdo,$org,$dataset['publicId'],'yolo11n_640',$actor);
    $artifact=glasses_vision_training_release_artifact($pdo,$org,$release['publicId']);
    $pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND public_id=?')->execute([$org,$media[3]['publicId']]);
    $error=$suffix.'-error';
    $context=json_encode(['context'=>['samplePublicId'=>$media[3]['samplePublicId'],'mediaPublicId'=>$media[3]['publicId']]],JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO glasses_vision_production_errors(organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,expected_component_key,context_json,actor_user_id,occurred_at) VALUES (?,?,?,?,'manual','false_negative','reviewed','ingredient',?,?,UTC_TIMESTAMP(6))")
        ->execute([$org,$error,$error,hash('sha256',$error),$context,$actor]);$errorId=(int)$pdo->lastInsertId();
    return ['org'=>$org,'actor'=>$actor,'dataset'=>$dataset['publicId'],'datasetId'=>$datasetId,'media'=>$media,'release'=>$release,'artifact'=>$artifact,'errorId'=>$errorId];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $pdo=app_pdo();$mode=$argv[1]??'seed';
    if($mode==='seed')$out=hdm_seed($pdo,isset($argv[2])?(int)$argv[2]:null,isset($argv[3])?(int)$argv[3]:null);
    elseif($mode==='fresh-release')$out=glasses_vision_training_release_build($pdo,(int)$argv[2],(string)$argv[4],'yolo11n_640',(int)$argv[3]);
    elseif($mode==='expire'){
        $pdo->prepare('UPDATE glasses_vision_training_media SET retention_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND public_id=?')->execute([(int)$argv[2],$argv[3]]);$out=['ok'=>true];
    }elseif($mode==='viewer'){
        $uid=(int)$argv[2];$org=(int)$argv[3];
        $q=$pdo->prepare('SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=?');$q->execute([$org,$uid]);$mid=(int)$q->fetchColumn();
        $pdo->prepare('DELETE FROM user_roles WHERE membership_id=?')->execute([$mid]);
        $pdo->prepare("INSERT INTO roles(organization_id,name,slug,is_owner_role) VALUES (?,'Retention viewer',?,0)")->execute([$org,'hd-viewer-'.$uid]);$rid=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='glasses.view'")->execute([$rid]);
        $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$mid,$rid,$uid]);$out=['ok'=>true];
    }else throw new InvalidArgumentException('Unknown retention fixture command.');
    echo json_encode($out,JSON_THROW_ON_ERROR);
}
