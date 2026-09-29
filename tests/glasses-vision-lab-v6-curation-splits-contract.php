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
require_once __DIR__.'/../includes/glasses-vision-curation.php';

function v6_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v6_session(PDO $pdo,int $org,int $loc,array $station,int $price,int $actor,array $device,string $suffix): string {
    $check=pos_create_check($pdo,$org,$loc,['serviceMode'=>'dine_in','tableName'=>'V6-'.$suffix,'guestCount'=>1],$actor);
    $check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);
    $line=(int)$check['items'][0]['id'];kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
    $q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);
    return (string)glasses_build_start($pdo,$device,(string)$q->fetchColumn(),null)['publicId'];
}
function v6_sample(PDO $pdo,int $org,array $device,string $session,string $key,string $component,int $actor): array {
    glasses_build_observe($pdo,$device,$session,['observationKey'=>$key,'componentKey'=>$component,'displayName'=>'Bacon','observationAction'=>'added','quantity'=>1,'confidence'=>.91,'trackingId'=>'v6-'.$key,'metadata'=>['detectorLabel'=>'bacon']]);
    $sample=glasses_vision_lab_queue_observation($pdo,$org,$key,null,$actor);
    return glasses_vision_lab_review_sample($pdo,$org,(string)$sample['publicId'],['decision'=>'approve','notes'=>'V6 approved evidence.'],$actor);
}

$pdo=app_pdo();v6_assert(glasses_vision_curation_ready($pdo),'Vision Lab V6 migration must be installed.');
$slug='vl6-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Lab V6 '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'V6 Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);$loc=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'V6','Curator','V6 Curator']);$actor=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$actor,$loc]);
$station=kds_station_save($pdo,$org,$loc,['name'=>'V6 Line','slug'=>'v6-line','targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'V6',?,'active',1)")->execute([$org,'v6-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'V6 Sandwich',?,1)")->execute([$org,$section,'v6-sandwich-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Bacon',?,'food','verified')")->execute([$org,'bacon-'.$slug]);$ing=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Bacon',0,1,1)")->execute([$item,$ing]);$component=glasses_build_component_key('Bacon',$ing);
kds_route_save($pdo,$org,$loc,$item,(string)$station['public_id'],$actor);
$grant=glasses_create_pairing_grant($pdo,$org,$loc,(string)$station['public_id'],$actor,10);
$pair=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'V6-'.$slug,'displayName'=>'V6 AIR3','platform'=>'inmo_air3']);$device=glasses_authenticate_token($pdo,(string)$pair['deviceToken']);
glasses_vision_lab_assign_device($pdo,$org,(string)$pair['device']['publicId'],$actor,'operator','manual',$actor);

$sa=v6_session($pdo,$org,$loc,$station,$price,$actor,$device,'A');
$sb=v6_session($pdo,$org,$loc,$station,$price,$actor,$device,'B');
$sc=v6_session($pdo,$org,$loc,$station,$price,$actor,$device,'C');
$sd=v6_session($pdo,$org,$loc,$station,$price,$actor,$device,'D');
$samples=[
 'a1'=>v6_sample($pdo,$org,$device,$sa,'v6-a1',$component,$actor),
 'a2'=>v6_sample($pdo,$org,$device,$sa,'v6-a2',$component,$actor),
 'b1'=>v6_sample($pdo,$org,$device,$sb,'v6-b1',$component,$actor),
 'c1'=>v6_sample($pdo,$org,$device,$sc,'v6-c1',$component,$actor),
 'd1'=>v6_sample($pdo,$org,$device,$sd,'v6-d1',$component,$actor),
];

$dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>'V6 Curated Dataset','versionLabel'=>'v1'],$actor);
foreach($samples as $s)glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$s['publicId'],'train');

glasses_vision_curation_decide($pdo,$org,(string)$dataset['publicId'],(string)$samples['c1']['publicId'],'exclude','Temporary holdout decision.',$actor);
glasses_vision_curation_decide($pdo,$org,(string)$dataset['publicId'],(string)$samples['c1']['publicId'],'include','Reviewed and restored.',$actor);
glasses_vision_curation_decide($pdo,$org,(string)$dataset['publicId'],(string)$samples['d1']['publicId'],'exclude','Redundant evidence excluded from release.',$actor);
$rows=glasses_vision_curation_rows($pdo,$org,(string)$dataset['publicId']);
$state=[];foreach($rows as $r)$state[$r['samplePublicId']]=$r;
v6_assert($state[$samples['c1']['publicId']]['curationDecision']==='include','Latest append-only curation event must determine current inclusion.');
v6_assert($state[$samples['d1']['publicId']]['curationDecision']==='exclude','Explicit exclusion must remain visible before plan apply.');
$count=(int)$pdo->query("SELECT COUNT(*) FROM glasses_vision_dataset_curation_events WHERE organization_id={$org}")->fetchColumn();
v6_assert($count===3,'Curation ledger must preserve all historical decisions.');

$plan1=glasses_vision_curation_create_split_plan($pdo,$org,(string)$dataset['publicId'],['seed'=>74],$actor);
$plan2=glasses_vision_curation_create_split_plan($pdo,$org,(string)$dataset['publicId'],['seed'=>74],$actor);
v6_assert($plan1['planHash']===$plan2['planHash'],'Same dataset, curation state, policy and seed must generate the same deterministic split hash.');
v6_assert(($plan1['manifest']['groupCount']??0)===3,'Two samples from one build plus two independent builds must form three lineage groups.');
$bySample=[];foreach($plan1['manifest']['assignments'] as $a)$bySample[$a['samplePublicId']]=$a;
v6_assert($bySample[$samples['a1']['publicId']]['groupKey']===$bySample[$samples['a2']['publicId']]['groupKey'],'Samples from the same build must share a protected group.');
v6_assert($bySample[$samples['a1']['publicId']]['split']===$bySample[$samples['a2']['publicId']]['split'],'Samples from the same protected group must never cross splits.');
v6_assert(!isset($bySample[$samples['d1']['publicId']]),'Excluded evidence must not enter the split plan.');

$blocked=false;try{glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$actor);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'group-aware split plan');}
v6_assert($blocked,'Dataset freeze must require an applied governed group-aware split plan.');

$applied=glasses_vision_curation_apply_split_plan($pdo,$org,(string)$plan1['publicId'],$actor);
v6_assert($applied['status']==='applied','Draft group-aware split plan must apply explicitly.');
$rows2=glasses_vision_curation_rows($pdo,$org,(string)$dataset['publicId']);
v6_assert(count($rows2)===4,'Applying curation must remove explicitly excluded samples from the draft dataset.');
$splits=array_count_values(array_column($rows2,'split'));
v6_assert(($splits['train']??0)>0&&($splits['val']??0)>0&&($splits['test']??0)>0,'Applied group split must produce non-empty train/val/test sets.');
$state2=[];foreach($rows2 as $r)$state2[$r['samplePublicId']]=$r;
v6_assert($state2[$samples['a1']['publicId']]['split']===$state2[$samples['a2']['publicId']]['split'],'Applied dataset must retain protected group co-location.');

$frozen=glasses_vision_lab_freeze_dataset($pdo,$org,(string)$dataset['publicId'],$actor);
v6_assert($frozen['status']==='frozen'&&strlen((string)$frozen['datasetHash'])===64,'Curated group-split dataset must freeze successfully.');

$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-lab.js');
v6_assert((str_contains($page,'Vision Lab V6')||str_contains($page,'Vision Lab V7'))&&str_contains($page,'Curated Dataset Builder'),'V6 workspace must expose curated dataset controls.');
v6_assert(str_contains($api,'curation.plan_split')&&str_contains($api,'curation.apply_split'),'V6 API must expose governed split planning and apply.');
v6_assert(str_contains($js,'data-curate')&&str_contains($js,'data-split-apply'),'V6 UI must expose curation and explicit split apply.');
v6_assert(!str_contains($api,'glasses_build_observe')&&!str_contains($api,'kds_transition'),'Curation API must not mutate production build or KDS truth.');
echo "vision-lab-v6-curation-splits-ok\n";
