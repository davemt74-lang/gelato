<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-lab.php';
require_once __DIR__.'/../includes/glasses-vision-active-learning.php';
function al_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$pdo=app_pdo();al_assert(glasses_vision_active_learning_ready($pdo),'Vision Lab V3 migration must be installed.');
$slug='al3-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Active Learning '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Learning Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);$loc=(int)$pdo->lastInsertId();
foreach([['Admin','Trainer'],['Cook','Operator']] as $x){$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([strtolower($x[0]).$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),$x[0],$x[1],$x[0].' '.$x[1]]);$ids[]=(int)$pdo->lastInsertId();}
[$admin,$cook]=$ids;foreach([$admin,$cook] as $uid)$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$uid,$loc]);
$station=kds_station_save($pdo,$org,$loc,['name'=>'Learning Line','slug'=>'learning-line','targetSeconds'=>300],$admin);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lab',?,'active',1)")->execute([$org,'lab-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Learning Sandwich',?,1)")->execute([$org,$section,'learning-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Bacon',?,'food','verified')")->execute([$org,'bacon-'.$slug]);$ing=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Bacon',0,1,1)")->execute([$item,$ing]);$component=glasses_build_component_key('Bacon',$ing);
kds_route_save($pdo,$org,$loc,$item,(string)$station['public_id'],$admin);
$check=pos_create_check($pdo,$org,$loc,['serviceMode'=>'dine_in','tableName'=>'Lab','guestCount'=>1],$admin);$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$admin);$line=(int)$check['items'][0]['id'];kds_send_check($pdo,$org,(string)$check['publicId'],$admin,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kds=(string)$q->fetchColumn();
$grant=glasses_create_pairing_grant($pdo,$org,$loc,(string)$station['public_id'],$admin,10);$pair=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AL-'.$slug,'displayName'=>'Learning AIR3','platform'=>'inmo_air3']);$device=glasses_authenticate_token($pdo,(string)$pair['deviceToken']);
glasses_vision_lab_assign_device($pdo,$org,(string)$pair['device']['publicId'],$cook,'operator','manual',$admin);
$session=glasses_build_start($pdo,$device,$kds,null);$sessionPublic=(string)$session['publicId'];
glasses_build_observe($pdo,$device,$sessionPublic,['observationKey'=>'al-low-confidence','componentKey'=>$component,'displayName'=>'Bacon','observationAction'=>'added','quantity'=>1,'confidence'=>0.42,'trackingId'=>'al-track','metadata'=>['detectorLabel'=>'bacon']]);
glasses_build_correct_observation($pdo,$device,$sessionPublic,['correctionKey'=>'al-correction','observationKey'=>'al-low-confidence','resolution'=>'replace','targetComponentKey'=>$component,'correctedQuantity'=>1,'hasCorrectedQuantity'=>true,'reason'=>'Corrected during production.']);

$scan=glasses_vision_active_learning_scan($pdo,$org,$admin,'manual');
al_assert($scan['createdCandidates']>=2,'Scan must discover corrected and low-confidence production signals.');
al_assert($scan['queuedSamples']>=1,'Observation-based active learning must queue evidence for human review.');
$candidates=glasses_vision_active_learning_candidates($pdo,$org,'open',20);
$types=array_column($candidates,'candidateType');al_assert(in_array('correction',$types,true)&&in_array('low_confidence',$types,true),'Candidate queue must include correction and low-confidence types.');
al_assert($candidates[0]['priorityScore']>=$candidates[count($candidates)-1]['priorityScore'],'Candidates must be priority ordered.');
$samples=glasses_vision_lab_samples($pdo,$org);al_assert(count($samples)===1&&$samples[0]['reviewStatus']==='pending','Automatic intake may queue evidence but must never auto-approve ground truth.');
al_assert($samples[0]['operatorUserId']===$cook,'Automatically queued evidence must preserve wearer attribution.');

$correction=null;foreach($candidates as $candidate)if($candidate['candidateType']==='correction'){$correction=$candidate;break;}al_assert(is_array($correction),'Correction candidate is required.');
$mission=glasses_vision_active_learning_create_mission($pdo,$org,(string)$correction['publicId'],$admin);
al_assert($mission['sourceType']==='active_learning'&&$mission['assignedUserId']===$cook,'Explicit candidate action must create an attributed active-learning mission.');
$updated=glasses_vision_active_learning_candidates($pdo,$org,'all',20);$assigned=array_values(array_filter($updated,fn($x)=>$x['publicId']===$correction['publicId']))[0]??null;al_assert(($assigned['status']??'')==='assigned'&&($assigned['missionPublicId']??null)===$mission['publicId'],'Candidate must retain mission lineage.');
$samples=glasses_vision_lab_samples($pdo,$org);al_assert($samples[0]['missionPublicId']===$mission['publicId'],'Queued sample must attach to the accepted mission.');

$scan2=glasses_vision_active_learning_scan($pdo,$org,$admin,'manual');al_assert($scan2['createdCandidates']===0,'Repeated scans must be idempotent for unchanged source signals.');
$open=glasses_vision_active_learning_candidates($pdo,$org,'open',20);if($open){$dismiss=glasses_vision_active_learning_dismiss($pdo,$org,(string)$open[0]['publicId'],'Not useful for this training cycle.',$admin);al_assert($dismiss['status']==='dismissed','Open candidates must support explicit dismissal with reason.');}
$summary=glasses_vision_active_learning_summary($pdo,$org);al_assert(($summary['states']['assigned']??0)>=1,'Active-learning summary must count accepted mission candidates.');

$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-lab.js');
al_assert((str_contains($page,'Vision Lab V3')||str_contains($page,'Vision Lab V4')||str_contains($page,'Vision Lab V5')||str_contains($page,'Vision Lab V6')||str_contains($page,'Vision Lab V7')||str_contains($page,'Vision Lab V8')||str_contains($page,'Vision Lab V9'))&&str_contains($page,'Scan production signals'),'Vision Lab V3 must expose the active-learning workspace.');
al_assert(str_contains($api,"active_learning.scan")&&str_contains($api,"active_learning.create_mission")&&str_contains($api,'app_verify_request_csrf'),'Active-learning mutations must be explicit and CSRF protected.');
al_assert(str_contains($js,'data-al-mission')&&str_contains($js,'data-al-dismiss'),'UI must expose candidate acceptance and dismissal.');
al_assert(!str_contains($api,'glasses_build_observe')&&!str_contains($api,'kds_transition'),'Active learning API must not mutate production build or KDS truth.');
$approved=(int)$pdo->query("SELECT COUNT(*) FROM glasses_vision_training_samples WHERE organization_id={$org} AND review_status='approved'")->fetchColumn();al_assert($approved===0,'Active learning automation must never silently promote evidence to ground truth.');
echo "vision-lab-v3-active-learning-ok\n";
