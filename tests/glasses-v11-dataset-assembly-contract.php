<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-v11-dataset-assembly.php';
require_once __DIR__.'/../includes/glasses-vision-dataset-intelligence.php';

function v115_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v115_user(PDO $pdo,int $org,int $location,string $email,string $name):int{
  $p=preg_split('/\s+/',trim($name),2);
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash',?,?,?,'active')")
    ->execute([$email,$p[0]?:'Vision',$p[1]??'User',$name]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$id,$location]);
  return $id;
}
function v115_device(PDO $pdo,int $org,int $location,string $stationPublic,int $actor,string $hardware):array{
  $g=glasses_create_pairing_grant($pdo,$org,$location,$stationPublic,$actor,10);
  $p=glasses_pair_device($pdo,(string)$g['pairingCode'],['hardwareIdentifier'=>$hardware,'displayName'=>$hardware,'platform'=>'browser_simulator','capabilities'=>['hardwareRuntimeAdapterId'=>'simulator.v1']]);
  return glasses_authenticate_token($pdo,(string)$p['deviceToken']);
}
function v115_png(int $r,int $g,int $b): string {
  $w=320;$h=240;$raw='';
  $row=chr(0).str_repeat(chr($r).chr($g).chr($b),$w);
  for($y=0;$y<$h;$y++)$raw.=$row;
  $chunk=function(string $type,string $data):string{return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));};
  $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
  return base64_encode($png);
}

$pdo=app_pdo();v115_assert(glasses_v11_dataset_assembly_ready($pdo),'V11 dataset assembly migration must be installed.');
$slug='v115-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Dataset '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Dataset Kitchen','Phoenix','AZ','active')")->execute([$org]);$loc=(int)$pdo->lastInsertId();
$admin=v115_user($pdo,$org,$loc,$slug.'-admin@example.test','Dataset Admin');
$r1=v115_user($pdo,$org,$loc,$slug.'-r1@example.test','Dataset Reviewer');
$station=kds_station_save($pdo,$org,$loc,['name'=>'Dataset Line','slug'=>'dataset-'.$slug,'targetSeconds'=>300],$admin);

$evidence=[];
$colors=[[220,40,40],[40,220,40],[40,40,220]];
foreach($colors as $i=>$rgb){
  $cook=v115_user($pdo,$org,$loc,$slug.'-cook'.$i.'@example.test','Dataset Cook '.$i);
  $device=v115_device($pdo,$org,$loc,(string)$station['public_id'],$admin,'V11-DATASET-'.$i.'-'.$slug);
  $program=glasses_training_program_create($pdo,$org,['name'=>'Dataset Program '.$i,'mode'=>'training','locationId'=>$loc,'stationPublicId'=>$station['public_id']],$admin);
  $assignment=glasses_training_assignment_create($pdo,$org,['programPublicId'=>$program['public_id'],'userId'=>$cook,'devicePublicId'=>$device['public_id'],'supervisorUserId'=>$r1],$admin);
  $session=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device,$admin);
  $ev=glasses_v11_production_evidence_capture($pdo,$device,[
    'trainingSessionPublicId'=>$session['public_id'],'imageBase64'=>v115_png(...$rgb),'capturedAt'=>gmdate('c'),
    'captureGroup'=>'cg-'.$i.'-'.$slug,'recipeStepKey'=>'assemble.step'.$i,
    'validationOutcome'=>$i===0?'final_failure':'ready_candidate','trainingEligibility'=>'eligible',
    'annotations'=>[['label'=>'ingredient_'.$i,'bbox'=>['x'=>.2,'y'=>.2,'width'=>.3,'height'=>.3]]]
  ],$admin);
  v115_assert($ev['trainingEligibility']==='review','Device capture must never self-promote training eligibility.');
  glasses_vision_lab_review_sample($pdo,$org,$ev['samplePublicId'],['decision'=>'approve','canonicalLabel'=>'ingredient_'.$i,'notes'=>'Approved for assembly'],$r1);
  $ev=glasses_v11_production_evidence_set_eligibility($pdo,$org,$ev['publicId'],'eligible','',$admin);
  $evidence[]=$ev;
}

glasses_v11_mining_run($pdo,$org,[],$admin);
$dataset=glasses_v11_dataset_assemble($pdo,$org,['name'=>'V11 Kitchen Dataset','versionLabel'=>'v1','policy'=>['maxSamples'=>20,'maxPerOperator'=>5,'maxPerDevice'=>5,'maxPerLocation'=>20,'maxPerStation'=>20]],$admin);

v115_assert($dataset['status']==='draft','Assembled V11 dataset must remain a governed draft until freeze.');
v115_assert(count($dataset['items'])===3,'Assembly must include the three independent eligible approved samples.');
v115_assert(strlen((string)$dataset['assemblyHash'])===64,'Assembly must have an immutable SHA-256 manifest hash.');
v115_assert(($dataset['policy']['splitPolicy']['device']??false)===true,'V11 split policy must use device-aware leakage grouping.');
v115_assert(($dataset['policy']['splitPolicy']['location']??true)===false,'Single-site datasets must not be forced into one location lineage group.');
v115_assert(($dataset['coverage']['splits']['train']??0)===1&&($dataset['coverage']['splits']['val']??0)===1&&($dataset['coverage']['splits']['test']??0)===1,'Three independent groups must cover train/val/test.');

$deviceSplits=[];
foreach($dataset['items'] as $item){
  v115_assert($item['eligibilitySnapshot']==='eligible','Dataset item must retain eligible snapshot.');
  v115_assert(!empty($item['mediaPublicId']),'Dataset item must retain governed media lineage.');
  $devicePublic=$item['provenance']['devicePublicId']??null;v115_assert(is_string($devicePublic)&&$devicePublic!=='','Dataset item must retain device lineage.');
  $deviceSplits[$devicePublic][$item['split']]=true;
}
foreach($deviceSplits as $splits)v115_assert(count($splits)===1,'One device lineage must never leak across splits.');

glasses_v11_dataset_freeze_guard($pdo,$org,$dataset['publicId']);
$frozen=glasses_vision_lab_freeze_dataset($pdo,$org,$dataset['publicId'],$admin);
v115_assert($frozen['status']==='frozen'&&strlen((string)$frozen['datasetHash'])===64,'Existing V6 freeze must produce immutable dataset hash.');

$mutationBlocked=false;try{glasses_vision_lab_add_dataset_sample($pdo,$org,$dataset['publicId'],$evidence[0]['samplePublicId'],'train');}catch(InvalidArgumentException){$mutationBlocked=true;}
v115_assert($mutationBlocked,'Frozen dataset must remain immutable.');

$manifest=$dataset['manifest'];
v115_assert(($manifest['schema']??'')===GLASSES_V11_DATASET_ASSEMBLY_SCHEMA,'Assembly manifest must be V11 schema.');
v115_assert(count($manifest['items']??[])===3,'Assembly manifest must enumerate selected samples.');
$split=$dataset['splitPlans'][0]['manifest']??[];
foreach(($split['assignments']??[]) as $a)v115_assert(array_key_exists('deviceIds',$a['grouping']??[]),'Split manifest must retain device grouping provenance.');

$migration=file_get_contents(__DIR__.'/../database/20261201_v11_dataset_assembly_versioning.sql');
$source=file_get_contents(__DIR__.'/../includes/glasses-v11-dataset-assembly.php');
$curation=file_get_contents(__DIR__.'/../includes/glasses-vision-curation.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-v11-dataset-assembly.js');
v115_assert(str_contains($migration,'ALTER TABLE glasses_vision_dataset_versions')&&str_contains($migration,'ALTER TABLE glasses_vision_dataset_items')&&!str_contains($migration,'CREATE TABLE'),'V11 must extend canonical V6 datasets rather than create another dataset system.');
v115_assert(str_contains($curation,"'device'=>"),'V6 split governance must support device lineage.');
v115_assert(str_contains($api,'dataset.assemble')&&str_contains($api,'dataset.freeze'),'Vision Lab API must expose governed V11 assembly/freeze.');
v115_assert(str_contains($page,'V11 Dataset Assembly &amp; Versioning'),'Vision Lab must expose V11 dataset builder.');
v115_assert(str_contains($ui,'Freeze immutable version'),'Dataset UI must expose explicit immutable freeze.');
foreach(['glasses_vision_retraining_','glasses_vision_model_rollout_activate(','kds_transition('] as $forbidden)v115_assert(!str_contains($source,$forbidden),'Dataset assembly must not bypass downstream governance: '.$forbidden);

echo "glasses-v11-dataset-assembly-ok\n";
