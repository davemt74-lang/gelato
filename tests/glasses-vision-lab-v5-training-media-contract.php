<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-lab.php';
require_once __DIR__.'/../includes/glasses-vision-active-learning.php';
require_once __DIR__.'/../includes/glasses-vision-dataset-intelligence.php';
require_once __DIR__.'/../includes/glasses-vision-training-media.php';

function v5_assert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }

function v5_new_session(PDO $pdo,int $org,int $location,array $station,int $price,int $actor,array $device,string $suffix): string {
    $check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'V5-'.$suffix,'guestCount'=>1],$actor);
    $check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);
    $line=(int)$check['items'][0]['id'];
    kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
    $q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");
    $q->execute([$org,$line]);
    $session=glasses_build_start($pdo,$device,(string)$q->fetchColumn(),null);
    return (string)$session['publicId'];
}

function v5_sample(PDO $pdo,int $org,array $device,string $session,string $key,string $component,int $actor,float $confidence): array {
    glasses_build_observe($pdo,$device,$session,[
        'observationKey'=>$key,'componentKey'=>$component,'displayName'=>'Bacon','observationAction'=>'added',
        'quantity'=>1,'confidence'=>$confidence,'trackingId'=>'v5-'.$key,
        'metadata'=>['detectorLabel'=>'bacon'],
    ]);
    $sample=glasses_vision_lab_queue_observation($pdo,$org,$key,null,$actor);
    return glasses_vision_lab_review_sample($pdo,$org,(string)$sample['publicId'],['decision'=>'approve','notes'=>'V5 test ground truth.'],$actor);
}

$imgA='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCABAAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDxqiiivkj7sKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA//Z';
$imgB='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCABAAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDxyiiivkj7sKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA//Z';
$imgC='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCABAAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD836KKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAP/2Q==';

$pdo=app_pdo();
v5_assert(glasses_vision_training_media_ready($pdo),'Vision Lab V5 migration must be installed.');
v5_assert(glasses_vision_training_media_hamming('0000000000000000','0000000000000001')===1,'64-bit dHash Hamming distance must be deterministic.');

$slug='vl5-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Lab V5 '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'V5 Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'V5','Trainer','V5 Trainer']);$admin=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$admin,$location]);
$station=kds_station_save($pdo,$org,$location,['name'=>'V5 Line','slug'=>'v5-line','targetSeconds'=>300],$admin);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'V5',?,'active',1)")->execute([$org,'v5-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'V5 Sandwich',?,1)")->execute([$org,$section,'v5-sandwich-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Bacon',?,'food','verified')")->execute([$org,'bacon-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Bacon',0,1,1)")->execute([$item,$ingredient]);
$component=glasses_build_component_key('Bacon',$ingredient);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$admin);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$admin,10);
$pair=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'V5-'.$slug,'displayName'=>'V5 AIR3','platform'=>'inmo_air3']);
$device=glasses_authenticate_token($pdo,(string)$pair['deviceToken']);
glasses_vision_lab_assign_device($pdo,$org,(string)$pair['device']['publicId'],$admin,'operator','manual',$admin);

$sessions=[];
foreach(['A','B','C','D'] as $suffix)$sessions[$suffix]=v5_new_session($pdo,$org,$location,$station,$price,$admin,$device,$suffix);
$samples=[];
foreach([
    'A'=>['v5-a',$sessions['A'],.995],
    'B'=>['v5-b',$sessions['B'],.95],
    'C'=>['v5-c',$sessions['C'],.93],
    'D'=>['v5-d',$sessions['D'],.80],
] as $name=>$def)$samples[$name]=v5_sample($pdo,$org,$device,$def[1],$def[0],$component,$admin,$def[2]);

$dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>'V5 Visual Dataset','versionLabel'=>'v1'],$admin);
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$samples['A']['publicId'],'train');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$samples['B']['publicId'],'val');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$samples['C']['publicId'],'test');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$samples['D']['publicId'],'train');

$base=[
    'consentBasis'=>'training_media_opt_in','retentionDays'=>365,'devicePublicId'=>(string)$pair['device']['publicId'],
    'annotations'=>[['label'=>$component,'bbox'=>['x'=>.1,'y'=>.1,'width'=>.4,'height'=>.4]]],
    'brightnessMean'=>.5,'contrastMean'=>.3,'blurScore'=>120,'distanceBucket'=>'normal','occlusionBucket'=>'none',
    'camera'=>['facing'=>'environment','deviceKey'=>'camera-v5','width'=>64,'height'=>64,'fps'=>30],
    'pose'=>['pitch'=>0,'yaw'=>15,'roll'=>0],
];

$consentBlocked=false;
try{glasses_vision_training_media_store($pdo,$org,array_merge($base,['consentBasis'=>'','imageBase64'=>$imgA]),$admin);}catch(InvalidArgumentException){$consentBlocked=true;}
v5_assert($consentBlocked,'Server-side training media retention must require explicit opt-in.');

$bboxBlocked=false;
try{glasses_vision_training_media_store($pdo,$org,array_merge($base,['imageBase64'=>$imgA,'annotations'=>[['label'=>$component,'bbox'=>['x'=>.9,'y'=>.9,'width'=>.4,'height'=>.4]]]]),$admin);}catch(InvalidArgumentException){$bboxBlocked=true;}
v5_assert($bboxBlocked,'Out-of-image annotation geometry must be rejected.');

$m1=glasses_vision_training_media_store($pdo,$org,array_merge($base,[
    'imageBase64'=>$imgA,'samplePublicId'=>$samples['A']['publicId'],'buildSessionPublicId'=>$sessions['A'],
    'captureGroup'=>'burst-v5','burstIndex'=>0,'perceptualHash'=>'0000000000000000','detectorConfidence'=>.995,
]),$admin);
$m2=glasses_vision_training_media_store($pdo,$org,array_merge($base,[
    'imageBase64'=>$imgA,'samplePublicId'=>$samples['B']['publicId'],'buildSessionPublicId'=>$sessions['B'],
    'captureGroup'=>'burst-v5','burstIndex'=>1,'perceptualHash'=>'0000000000000000','detectorConfidence'=>.95,
]),$admin);
$m3=glasses_vision_training_media_store($pdo,$org,array_merge($base,[
    'imageBase64'=>$imgB,'samplePublicId'=>$samples['C']['publicId'],'buildSessionPublicId'=>$sessions['C'],
    'captureGroup'=>'burst-v5-c','burstIndex'=>0,'perceptualHash'=>'0000000000000001','detectorConfidence'=>.93,
]),$admin);
$m4=glasses_vision_training_media_store($pdo,$org,array_merge($base,[
    'imageBase64'=>$imgC,'samplePublicId'=>$samples['D']['publicId'],'buildSessionPublicId'=>$sessions['D'],
    'captureGroup'=>'burst-v5-d','burstIndex'=>0,'perceptualHash'=>'ffffffffffffffff','brightnessMean'=>.05,'contrastMean'=>.02,'blurScore'=>2,
]),$admin);

v5_assert($m1['sha256']===$m2['sha256']&&count($m2['exactDuplicates'])>=1,'Server SHA-256 must identify exact duplicate training media.');
v5_assert(in_array('too_easy',$m1['qualityFlags'],true),'Very-high-confidence reviewed capture must be flagged as too easy/deprioritizable.');
v5_assert($m4['qualityState']==='poor','Low-light blurred media must be classified poor.');

$quality=glasses_vision_training_media_dataset_quality($pdo,$org,(string)$dataset['publicId']);
v5_assert(count($quality['exactDuplicateClusters'])===1,'Dataset quality must expose exact duplicate clusters.');
v5_assert(count($quality['nearDuplicatePairs'])>=1,'Dataset quality must expose perceptual near-duplicate pairs.');
v5_assert(count($quality['captureGroupLeakage'])===1,'Capture-group leakage across train/val must be detected.');
v5_assert($quality['poorCount']===1&&$quality['tooEasyCount']>=1,'Dataset quality must expose poor and too-easy media.');
v5_assert($quality['poseInstrumentedCount']===4&&$quality['cameraDeviceDiversity']===1,'Camera pose/device diversity must be measurable.');
v5_assert(count($quality['exclusionRecommendations'])>=3,'V5 must generate exclusion/recapture/deprioritization recommendations.');

$analysis=glasses_vision_dataset_intelligence_analyze($pdo,$org,(string)$dataset['publicId'],$admin,1);
v5_assert($analysis['readiness']['visualMediaClear']===false&&$analysis['readiness']['datasetReleaseReady']===false,'V4 readiness must incorporate V5 visual-media blockers.');
v5_assert($analysis['limitations']['perceptualDuplicateDetection']===true,'V5 must remove the prior perceptual-duplicate limitation when media fingerprints exist.');

$freezeBlocked=false;
try{glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$admin);}catch(InvalidArgumentException){$freezeBlocked=true;}
v5_assert($freezeBlocked,'Dataset freeze must be blocked by visual-media quality failures.');

$m2row=glasses_vision_training_media_row($pdo,$org,(string)$m2['publicId'],false);
$m2path=glasses_vision_training_media_storage_root().'/'.$m2row['storage_relative_path'];
v5_assert(is_file($m2path),'Governed media bytes must exist in private storage before deletion.');
glasses_vision_training_media_delete($pdo,$org,(string)$m2['publicId'],'remove exact duplicate',$admin);
v5_assert(!is_file($m2path),'Governed deletion must remove private media bytes.');

$pdo->prepare("UPDATE glasses_vision_training_media SET retention_until=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,$m4['publicId']]);
$expired=glasses_vision_training_media_expire($pdo,$org,$admin,50);
v5_assert($expired['expired']===1,'Retention cleanup must delete expired training media.');

$quality2=glasses_vision_training_media_dataset_quality($pdo,$org,(string)$dataset['publicId']);
v5_assert(($quality2['releaseBlockers']['exactDuplicates']??-1)===0&&($quality2['releaseBlockers']['captureGroupLeakage']??-1)===0&&($quality2['releaseBlockers']['poorMedia']??-1)===0,'Deleting duplicate/expired poor media must clear V5 release blockers.');
v5_assert(count($quality2['nearDuplicatePairs'])>=1,'Near-duplicate information remains advisory after hard blockers are cleared.');

$analysis2=glasses_vision_dataset_intelligence_analyze($pdo,$org,(string)$dataset['publicId'],$admin,1);
v5_assert($analysis2['readiness']['visualMediaClear']===true&&$analysis2['readiness']['datasetReleaseReady']===true,'Remediated visual dataset must become release-ready.');

$manifest=glasses_vision_training_media_export_manifest($pdo,$org,(string)$dataset['publicId']);
v5_assert($manifest['privateBytesExcluded']===true&&$manifest['mediaCount']===2,'Governed export manifest must expose fingerprints without private bytes.');
v5_assert(!str_contains(json_encode($manifest),'storage_relative_path'),'Export manifest must never expose private storage paths.');

$frozen=glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$admin);
v5_assert($frozen['status']==='frozen'&&strlen((string)$frozen['datasetHash'])===64,'Clean visual dataset must freeze with an immutable hash.');
$q=$pdo->prepare("SELECT manifest_json FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=?");$q->execute([$org,$dataset['publicId']]);
$frozenManifest=json_decode((string)$q->fetchColumn(),true);
v5_assert(($frozenManifest['schema']??'')==='gelato.vision_dataset.v3'&&($frozenManifest['trainingMedia']['mediaCount']??0)===2,'Frozen dataset hash manifest must include governed V5 media fingerprints.');

$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$sim=file_get_contents(__DIR__.'/../glasses-simulator.php');
$simJs=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-media.php');
v5_assert(str_contains($page,'Vision Lab V5')&&str_contains($page,'Training Media'),'Vision Lab V5 workspace must expose governed media.');
v5_assert(str_contains($sim,'datasetServerOptIn')&&str_contains($sim,'Explicit opt-in required'),'Simulator must require explicit media-retention opt-in.');
v5_assert(str_contains($simJs,'datasetVisualFeatures')&&str_contains($simJs,'perceptualHash')&&str_contains($simJs,"action:'upload'"),'Simulator must compute visual features and upload only through the governed media API.');
v5_assert(str_contains($api,'Cache-Control: private, no-store')&&str_contains($api,"app_has_permission('glasses.manage'" )&&str_contains($api,"action==='delete'"),'Media retrieval must be authenticated/private and deletion governed.');
v5_assert(!str_contains($api,'glasses_build_observe')&&!str_contains($api,'kds_transition'),'Training media API must not mutate production build or KDS truth.');

echo "vision-lab-v5-training-media-ok\n";
