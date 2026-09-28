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

function v4_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function v4_session(PDO $pdo,int $org,int $location,array $station,int $menuItemId,int $priceId,int $actor,array $device,string $suffix): string {
    $check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'V4-'.$suffix,'guestCount'=>1],$actor);
    $check=pos_add_item($pdo,$org,(string)$check['publicId'],$priceId,1,'',$actor);
    $line=(int)$check['items'][0]['id'];
    kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
    $q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");
    $q->execute([$org,$line]);$kds=(string)$q->fetchColumn();
    $session=glasses_build_start($pdo,$device,$kds,null);
    return (string)$session['publicId'];
}

$pdo=app_pdo();
v4_assert(glasses_vision_dataset_intelligence_ready($pdo),'Vision Lab V4 migration must be installed.');
$slug='vl4-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Lab V4 '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'V4 Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);
$location=(int)$pdo->lastInsertId();

$users=[];
foreach([['Admin','Trainer'],['Peer','Reviewer']] as $person){
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
      ->execute([strtolower($person[0]).$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),$person[0],$person[1],$person[0].' '.$person[1]]);
    $users[]=(int)$pdo->lastInsertId();
}
[$admin,$peer]=$users;
foreach($users as $uid)$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$uid,$location]);

$station=kds_station_save($pdo,$org,$location,['name'=>'V4 Line','slug'=>'v4-line','targetSeconds'=>300],$admin);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'V4',?,'active',1)")->execute([$org,'v4-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'V4 Sandwich',?,1)")->execute([$org,$section,'v4-sandwich-'.$slug]);
$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10,'USD',1)")->execute([$item]);
$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Bacon',?,'food','verified')")->execute([$org,'bacon-'.$slug]);
$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Bacon',0,1,1)")->execute([$item,$ingredient]);
$component=glasses_build_component_key('Bacon',$ingredient);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$admin);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$admin,10);
$pair=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'V4-'.$slug,'displayName'=>'V4 AIR3','platform'=>'inmo_air3']);
$device=glasses_authenticate_token($pdo,(string)$pair['deviceToken']);
glasses_vision_lab_assign_device($pdo,$org,(string)$pair['device']['publicId'],$peer,'operator','manual',$admin);

$sessionA=v4_session($pdo,$org,$location,$station,$item,$price,$admin,$device,'A');
$sessionB=v4_session($pdo,$org,$location,$station,$item,$price,$admin,$device,'B');
$sessionC=v4_session($pdo,$org,$location,$station,$item,$price,$admin,$device,'C');

$observations=[
    ['session'=>$sessionA,'key'=>'v4-a1','confidence'=>0.92],
    ['session'=>$sessionA,'key'=>'v4-a2','confidence'=>0.91],
    ['session'=>$sessionB,'key'=>'v4-b1','confidence'=>0.90],
    ['session'=>$sessionC,'key'=>'v4-c1','confidence'=>0.89],
];
foreach($observations as $index=>$obs){
    glasses_build_observe($pdo,$device,$obs['session'],[
        'observationKey'=>$obs['key'],'componentKey'=>$component,'displayName'=>'Bacon','observationAction'=>'added',
        'quantity'=>1,'confidence'=>$obs['confidence'],'trackingId'=>'v4-track-'.$index,
        'metadata'=>['detectorLabel'=>'bacon','lighting'=>$index%2===0?'bright':'normal'],
    ]);
    $sample=glasses_vision_lab_queue_observation($pdo,$org,$obs['key'],null,$admin);
    glasses_vision_lab_review_sample($pdo,$org,(string)$sample['publicId'],['decision'=>'approve','notes'=>'V4 seed ground truth.'],$admin);
    glasses_vision_dataset_intelligence_record_review($pdo,$org,(string)$sample['publicId'],$admin,'approve',$component,'Primary review.');
}

$samples=glasses_vision_lab_samples($pdo,$org);
v4_assert(count($samples)===4,'Four production samples must be available for dataset intelligence.');
$bySource=[];foreach($samples as $sample)$bySource[$sample['sourceReference']]=$sample;

$dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>'V4 Bacon Dataset','versionLabel'=>'v1'],$admin);
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$bySource['v4-a1']['publicId'],'train');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$bySource['v4-a2']['publicId'],'test');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$bySource['v4-b1']['publicId'],'val');
glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$bySource['v4-c1']['publicId'],'test');

$peerReview=glasses_vision_dataset_intelligence_record_review($pdo,$org,(string)$bySource['v4-a1']['publicId'],$peer,'relabel','ingredient:999999','Peer sees a different class.');
v4_assert($peerReview['hasDisagreement']===true,'Conflicting peer review must produce adjudication disagreement.');

$analysis=glasses_vision_dataset_intelligence_analyze($pdo,$org,(string)$dataset['publicId'],$admin,5);
v4_assert(count($analysis['splitLeakage'])===1,'Same build session across train/test must be detected as leakage.');
v4_assert(count($analysis['reviewDisagreements'])===1,'Multi-reviewer disagreement must be surfaced.');
v4_assert($analysis['readiness']['datasetReleaseReady']===false,'Dataset with leakage/disagreement must not be release-ready.');
v4_assert($analysis['environment']['lightingInstrumented']===true,'Environment coverage must detect instrumented lighting metadata.');
v4_assert($analysis['limitations']['perceptualDuplicateDetection']===false,'V4 must explicitly disclose unavailable perceptual duplicate detection.');

$freezeBlocked=false;
try{glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$admin);}catch(InvalidArgumentException){$freezeBlocked=true;}
v4_assert($freezeBlocked,'Freeze must be blocked while split leakage or reviewer disagreement exists.');

$buildA=(string)$analysis['splitLeakage'][0]['buildPublicId'];
$fixed=glasses_vision_dataset_intelligence_consolidate_build_split($pdo,$org,(string)$dataset['publicId'],$buildA,'train');
v4_assert($fixed['matchedItems']===2&&$fixed['changedItems']===1,'Leakage remediation must move all samples from the build into one split.');

$consensus=glasses_vision_dataset_intelligence_record_review($pdo,$org,(string)$bySource['v4-a1']['publicId'],$peer,'approve',$component,'Peer agrees after adjudication.');
v4_assert($consensus['hasDisagreement']===false&&$consensus['reviewCount']===2,'Matching peer reviews must clear disagreement.');
$resolvedSample=glasses_vision_lab_sample($pdo,$org,(string)$bySource['v4-a1']['publicId']);
v4_assert($resolvedSample['reviewStatus']==='approved','Resolved reviewer consensus must restore approved sample status.');

$analysis2=glasses_vision_dataset_intelligence_analyze($pdo,$org,(string)$dataset['publicId'],$admin,5);
v4_assert(count($analysis2['splitLeakage'])===0&&count($analysis2['reviewDisagreements'])===0,'Remediated dataset must clear leakage and disagreement.');
v4_assert($analysis2['readiness']['hasValidationSplit']===true&&$analysis2['readiness']['hasTestSplit']===true,'Dataset must retain validation and test splits after remediation.');
v4_assert($analysis2['readiness']['datasetReleaseReady']===true,'Clear draft dataset with val/test must become release-ready.');

$plan=glasses_vision_dataset_intelligence_collection_plan($pdo,$org,(string)$analysis2['publicId'],'V4 coverage plan',$admin);
v4_assert($plan['status']==='draft'&&count($plan['tasks'])>0,'Analysis gaps must generate a draft collection plan.');
$accepted=glasses_vision_dataset_intelligence_accept_plan($pdo,$org,(string)$plan['publicId'],$admin);
v4_assert($accepted['status']==='accepted'&&$accepted['missionsCreated']>=1,'Accepted collection plan must create governed training missions.');

$frozen=glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$admin);
v4_assert($frozen['status']==='frozen'&&strlen((string)$frozen['datasetHash'])===64,'Remediated dataset must freeze with immutable hash.');
$refrozen=glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$admin);
v4_assert($refrozen['status']==='frozen'&&$refrozen['datasetHash']===$frozen['datasetHash'],'Repeated freeze must remain idempotent for immutable historical datasets.');

$moveBlocked=false;
try{glasses_vision_dataset_intelligence_consolidate_build_split($pdo,$org,(string)$dataset['publicId'],$buildA,'test');}catch(InvalidArgumentException){$moveBlocked=true;}
v4_assert($moveBlocked,'Frozen datasets must reject split remediation.');

$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-lab.js');
v4_assert((str_contains($page,'Vision Lab V4')||str_contains($page,'Vision Lab V5')||str_contains($page,'Vision Lab V6'))&&str_contains($page,'Dataset Intelligence'),'V4 workspace must expose dataset intelligence.');
v4_assert(str_contains($api,'dataset_intelligence.analyze')&&str_contains($api,'dataset_intelligence.accept_plan'),'V4 API must expose analysis and collection plan actions.');
v4_assert(str_contains($js,'data-fix-leak')&&str_contains($js,'data-plan-accept')&&str_contains($js,'data-peer-review'),'V4 UI must expose leakage remediation, plan acceptance and peer review.');
v4_assert(!str_contains($api,'glasses_build_observe')&&!str_contains($api,'kds_transition'),'Dataset intelligence API must not mutate production build or KDS truth.');

echo "vision-lab-v4-dataset-intelligence-ok\n";
