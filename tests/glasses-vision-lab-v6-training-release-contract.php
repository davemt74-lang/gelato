<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';

function v65_assert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }

$pdo=app_pdo();
v65_assert(glasses_vision_training_release_ready($pdo),'Vision Lab V6 training-release migration must be installed.');
v65_assert(class_exists('ZipArchive'),'V6 release CI requires the PHP Zip extension.');

$slug='vl6r-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Release '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'V6','Release','V6 Release Curator']);$actor=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;
$datasetHash=hash('sha256','dataset|'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v7','frozen',?,3,1,?,?,NOW(6))")
    ->execute([$org,$datasetPublic,'Release Corpus',$datasetHash,$actor,$actor]);
$datasetId=(int)$pdo->lastInsertId();

$planPublic='vision-split-'.$slug;$planHash=hash('sha256','plan|'.$slug);
$policy=['captureGroup'=>true,'buildSession'=>true,'operator'=>false,'location'=>false,'station'=>false,'menuItem'=>false];
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.70,.15,.15,?,?,?,?,?,NOW(6))")
    ->execute([$org,$planPublic,$datasetId,json_encode($policy),json_encode(['schema'=>'gelato.vision_group_split.v1']),$planHash,$actor,$actor]);
$planId=(int)$pdo->lastInsertId();

$root=glasses_vision_training_media_storage_root();
$mediaDir=$root.'/'.$org.'/v65';
if(!is_dir($mediaDir)&&!mkdir($mediaDir,0770,true)&&!is_dir($mediaDir))throw new RuntimeException('Could not create V6 release test media dir.');
$annotation=[['label'=>'pepperoni','bbox'=>['x'=>.10,'y'=>.20,'width'=>.40,'height'=>.30]]];
$sourcePaths=[];
foreach(['train','val','test'] as $i=>$split){
    $samplePublic='vision-sample-'.$slug.'-'.$split;
    $pdo->prepare("INSERT INTO glasses_vision_training_samples (organization_id,public_id,source_type,source_reference,review_status,review_outcome,canonical_label,annotation_json,reviewer_user_id,created_by,reviewed_at) VALUES (?,?, 'governed_training_media',?,'approved','approve','pepperoni',?,?,?,NOW(6))")
        ->execute([$org,$samplePublic,'fixture-'.$split,json_encode($annotation),$actor,$actor]);
    $sampleId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO glasses_vision_dataset_items (organization_id,dataset_id,sample_id,split_name,class_label,source_hash) VALUES (?,?,?,?,?,?)")
        ->execute([$org,$datasetId,$sampleId,$split,'pepperoni',hash('sha256',$samplePublic)]);
    $pdo->prepare("INSERT INTO glasses_vision_dataset_split_assignments (organization_id,split_plan_id,sample_id,group_key,group_label,split_name,grouping_json) VALUES (?,?,?,?,?,?,?)")
        ->execute([$org,$planId,$sampleId,hash('sha256','group-'.$split),'capture:'.$split,$split,json_encode(['captureGroup'=>'group-'.$split])]);
    $pdo->prepare("INSERT INTO glasses_vision_dataset_curation_events (organization_id,dataset_id,sample_id,decision,reason,source,actor_user_id) VALUES (?,?,?,'include',?,'manual',?)")
        ->execute([$org,$datasetId,$sampleId,'approved for release '.$split,$actor]);

    $bytes="PNG-fixture-".$slug."-".$split."\n";
    $relative=$org.'/v65/'.$split.'.png';$path=$root.'/'.$relative;
    file_put_contents($path,$bytes,LOCK_EX);$sourcePaths[]=$path;
    $pdo->prepare("INSERT INTO glasses_vision_training_media (organization_id,public_id,sample_id,capture_group,source_type,storage_relative_path,mime_type,byte_size,width,height,sha256,quality_state,quality_flags_json,annotation_json,consent_basis,status,created_by) VALUES (?,?,?,?, 'browser_training_capture',?,'image/png',?,320,240,?,'good','[]',?,'training_media_opt_in','active',?)")
        ->execute([$org,'vision-media-'.$slug.'-'.$split,$sampleId,'group-'.$split,$relative,strlen($bytes),hash('sha256',$bytes),json_encode($annotation),$actor]);
}

$release=glasses_vision_training_release_build($pdo,$org,$datasetPublic,'yolo11n_640',$actor);
v65_assert(strlen((string)$release['releaseHash'])===64&&strlen((string)$release['artifactSha256'])===64,'Release and artifact hashes must be SHA-256.');
v65_assert(($release['manifest']['schema']??'')===GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'Release must use the governed V6 schema.');
v65_assert(($release['manifest']['dataset']['datasetHash']??'')===$datasetHash,'Release must bind to the frozen dataset hash.');
v65_assert(($release['manifest']['splitPlan']['planHash']??'')===$planHash,'Release must bind to the applied split-plan hash.');
v65_assert(($release['manifest']['splitCounts']??[])===['train'=>1,'val'=>1,'test'=>1],'Release must preserve governed split membership.');
v65_assert(($release['manifest']['classes']??[])===['pepperoni'],'Class indexes must be deterministic.');
v65_assert(($release['manifest']['privacy']['absolutePathsIncluded']??true)===false&&($release['manifest']['privacy']['privateStoragePathsIncluded']??true)===false,'Release manifest must explicitly exclude private paths.');
v65_assert(!str_contains(json_encode($release['manifest']),glasses_vision_training_media_storage_root()),'Release manifest must not leak the private training-media root.');

$artifact=glasses_vision_training_release_artifact($pdo,$org,(string)$release['publicId']);
v65_assert(hash_file('sha256',$artifact['path'])===$release['artifactSha256'],'Download artifact must pass SHA-256 integrity verification.');
$zip=new ZipArchive();v65_assert($zip->open($artifact['path'])===true,'Generated YOLO ZIP must open.');
foreach(['dataset.yaml','provenance-manifest.json','images/train/image-000001.png','images/val/image-000002.png','images/test/image-000003.png','labels/train/image-000001.txt','labels/val/image-000002.txt','labels/test/image-000003.txt'] as $entry)v65_assert($zip->locateName($entry)!==false,'Training ZIP missing '.$entry);
$yaml=(string)$zip->getFromName('dataset.yaml');$manifestBody=(string)$zip->getFromName('provenance-manifest.json');
v65_assert(str_contains($yaml,'path: .')&&!str_contains($yaml,'storage/vision-training-media'),'Dataset YAML must be relative and path-safe.');
v65_assert(!str_contains($manifestBody,'storage_relative_path')&&!str_contains($manifestBody,glasses_vision_training_media_storage_root()),'Provenance manifest must not leak private storage paths.');
$inside=json_decode($manifestBody,true,512,JSON_THROW_ON_ERROR);
v65_assert(glasses_vision_training_release_hash($inside)===$release['releaseHash'],'Canonical manifest must reproduce the release hash.');
foreach($inside['files'] as $file){
    $body=$zip->getFromName((string)$file['path']);
    v65_assert($body!==false&&hash('sha256',$body)===$file['sha256'],'Every packaged image/label/config hash must verify.');
}
$label=(string)$zip->getFromName('labels/train/image-000001.txt');
v65_assert(str_starts_with($label,'0 0.30000000 0.35000000 0.40000000 0.30000000'),'YOLO box conversion must be deterministic.');
$zip->close();

$repeat=glasses_vision_training_release_build($pdo,$org,$datasetPublic,'yolo11n_640',$actor);
v65_assert($repeat['publicId']===$release['publicId']&&$repeat['releaseHash']===$release['releaseHash'],'Rebuilding identical corpus/profile must deduplicate to the same release record.');

$draftPublic='vision-dataset-draft-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,created_by) VALUES (?,?,?,'draft','draft',?)")->execute([$org,$draftPublic,'Draft Corpus',$actor]);
$blocked=false;try{glasses_vision_training_release_build($pdo,$org,$draftPublic,'yolo11n_640',$actor);}catch(InvalidArgumentException){$blocked=true;}
v65_assert($blocked,'Training release must reject non-frozen datasets.');

$ungoverned='vision-dataset-ungoverned-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,created_by,frozen_by,frozen_at) VALUES (?,?,?,'legacy','frozen',?,?,?,NOW(6))")->execute([$org,$ungoverned,'Legacy Frozen',hash('sha256','legacy'),$actor,$actor]);
$blocked=false;try{glasses_vision_training_release_build($pdo,$org,$ungoverned,'yolo11n_640',$actor);}catch(InvalidArgumentException){$blocked=true;}
v65_assert($blocked,'Training release must reject frozen datasets without an applied V6 split plan.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$download=file_get_contents(__DIR__.'/../api/glasses-vision-training-release.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v65_assert(str_contains($api,'training_release.build')&&!str_contains($api,'kds_transition'),'Release builder API must not mutate production KDS truth.');
v65_assert(str_contains($download,'Cache-Control: private, no-store')&&str_contains($download,'X-Gelato-SHA256'),'Training release downloads must be authenticated, private, and checksum-addressable.');
v65_assert(str_contains($page,'Training Release Builder'),'Vision Lab must expose the V6 release-builder workspace.');

@unlink($artifact['path']);
foreach($sourcePaths as $path)@unlink($path);
@rmdir($mediaDir);

echo "vision-lab-v6-training-release-ok\n";
