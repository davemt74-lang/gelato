<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-learning.php';
require_once __DIR__.'/../includes/admin-control-core.php';

$pdo=app_pdo();

function glci_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function glci_component_row(array $rows,string $key): array {
    foreach($rows as $row)if((string)$row['componentKey']===$key)return $row;
    throw new RuntimeException('Analytics component not found: '.$key);
}
function glci_band(array $rows,string $band): array {
    foreach($rows as $row)if((string)$row['band']===$band)return $row;
    throw new RuntimeException('Confidence band not found: '.$band);
}

glci_assert(glasses_learning_ready($pdo),'Vision learning dependencies must be installed.');

$slug='glci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Learning CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Main Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Learning','Lead','Learning Lead']);$userId=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10],$userId);
$otherStation=kds_station_save($pdo,$org,$location,['name'=>'Fry','slug'=>'fry','targetSeconds'=>240,'sortOrder'=>20],$userId);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Learning Sandwich',?,1)")->execute([$org,$section,'learning-sandwich-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',14.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$keys=[];
foreach(['Bread','Turkey','Bacon','Lettuce','Tomato'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower($name).'-'.$slug]);
    $ingredientId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')->execute([$item,$ingredientId,$name,$i+1]);
    $keys[$name]=glasses_build_component_key($name,$ingredientId);
}

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$userId);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 18','guestCount'=>1],$userId);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$userId);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$userId,false);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$userId,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-LEARN-'.$slug,
    'displayName'=>'Learning AIR3',
    'platform'=>'inmo_air3',
    'sdkVersion'=>'0.7.3-test',
    'appVersion'=>'0.16.0-test',
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

$observations=[
    ['key'=>'learn-bread','component'=>$keys['Bread'],'name'=>'Bread','confidence'=>0.48,'metadata'=>['evidenceKind'=>'WorkSurfaceUnprimed','sourceZoneKey'=>'bread-pan','destinationRegionKey'=>'build-main','sequenceSupported'=>false]],
    ['key'=>'learn-turkey','component'=>$keys['Turkey'],'name'=>'Turkey','confidence'=>0.60,'metadata'=>['evidenceKind'=>'TransferConfirmed','sourceZoneKey'=>'turkey-pan','destinationRegionKey'=>'build-main','sequenceSupported'=>true]],
    ['key'=>'learn-bacon','component'=>$keys['Bacon'],'name'=>'Bacon','confidence'=>0.80,'metadata'=>['evidenceKind'=>'TransferConfirmed','sourceZoneKey'=>'bacon-pan','destinationRegionKey'=>'build-main','sequenceSupported'=>false]],
    ['key'=>'learn-lettuce','component'=>$keys['Lettuce'],'name'=>'Lettuce','confidence'=>0.90,'metadata'=>['evidenceKind'=>'TransferConfirmed','sourceZoneKey'=>'lettuce-pan','destinationRegionKey'=>'build-main','sequenceSupported'=>true]],
    ['key'=>'learn-tomato','component'=>$keys['Tomato'],'name'=>'Tomato','confidence'=>0.97,'metadata'=>['evidenceKind'=>'TransferConfirmed','sourceZoneKey'=>'tomato-pan','destinationRegionKey'=>'build-main','sequenceSupported'=>true]],
];
foreach($observations as $obs){
    glasses_build_observe($pdo,$device,$sessionPublic,[
        'observationKey'=>$obs['key'],
        'componentKey'=>$obs['component'],
        'displayName'=>$obs['name'],
        'observationAction'=>'added',
        'quantity'=>1,
        'confidence'=>$obs['confidence'],
        'trackingId'=>$obs['key'].'-track',
        'metadata'=>$obs['metadata'],
    ]);
}

glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'reject-turkey',
    'observationKey'=>'learn-turkey',
    'resolution'=>'reject',
    'reason'=>'Turkey never left the pan.',
    'metadata'=>['input'=>'voice'],
]);
glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'bacon-was-turkey',
    'observationKey'=>'learn-bacon',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Turkey'],
    'correctedQuantity'=>1,
    'hasCorrectedQuantity'=>true,
    'reason'=>'Detector confused bacon with turkey.',
]);
glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'lettuce-half',
    'observationKey'=>'learn-lettuce',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Lettuce'],
    'correctedQuantity'=>0.5,
    'hasCorrectedQuantity'=>true,
    'reason'=>'Half portion.',
]);

$analytics=glasses_learning_analytics($pdo,$org,$location,(string)$station['public_id'],30);
$t=$analytics['totals'];
glci_assert((int)$t['observations']===5,'Analytics must count all scoped observations.');
glci_assert((int)$t['humanCorrected']===3,'Analytics must count latest human corrections.');
glci_assert(abs((float)$t['humanCorrectionRate']-0.6)<0.0001,'Correction rate must be corrected / observations.');
glci_assert((int)$t['uncorrected']===2,'Uncorrected observations must remain a separate non-verified category.');
glci_assert((int)$t['rejected']===1,'Reject outcome must be counted.');
glci_assert((int)$t['reclassified']===1,'Cross-component replacement must count as reclassified.');
glci_assert((int)$t['quantityCorrected']===1,'Same-component quantity replacement must count as quantity corrected.');
glci_assert((int)$t['reviewedNoChange']===0,'No-change review count must remain separate.');
glci_assert(abs((float)$t['meanModelConfidence']-0.75)<0.0001,'Mean model confidence must reflect source confidence, not human outcome.');
glci_assert($analytics['interpretation']['uncorrectedDoesNotMeanVerifiedCorrect']===true,'Analytics must explicitly prevent accuracy overclaiming.');

$low=glci_band($analytics['confidenceBands'],'0.00–0.49');
$mid=glci_band($analytics['confidenceBands'],'0.50–0.74');
$high=glci_band($analytics['confidenceBands'],'0.75–0.84');
glci_assert((int)$low['observations']===1&&(int)$low['corrected']===0,'Low confidence uncorrected signal must remain visible.');
glci_assert((int)$mid['observations']===1&&(int)$mid['rejected']===1&&(float)$mid['correctionRate']===1.0,'Rejected 0.60 observation must land in the correct confidence band.');
glci_assert((int)$high['observations']===1&&(int)$high['reclassified']===1,'0.80 reclassification must land in 0.75–0.84.');

$turkeyStats=glci_component_row($analytics['components'],$keys['Turkey']);
$baconStats=glci_component_row($analytics['components'],$keys['Bacon']);
glci_assert((int)$turkeyStats['rejected']===1,'Original Turkey component must report its rejected source observation.');
glci_assert((int)$baconStats['reclassified']===1,'Original Bacon component must report its reclassification.');

$transferKinds=array_values(array_filter($analytics['evidenceKinds'],static fn(array $row):bool=>$row['evidenceKind']==='TransferConfirmed'));
glci_assert(count($transferKinds)===1&&(int)$transferKinds[0]['observations']===4&&(int)$transferKinds[0]['corrected']===3,'Evidence-type analytics must measure correction rate on transfer evidence.');

$correctedDataset=glasses_learning_dataset($pdo,$org,$location,(string)$station['public_id'],30,true,100);
glci_assert((string)$correctedDataset['schema']==='gelato.ar_learning_dataset.v1','Learning export schema must be versioned.');
glci_assert((int)$correctedDataset['rowCount']===3,'Default governed dataset must include only human-reviewed observations.');
glci_assert(strlen((string)$correctedDataset['datasetHash'])===64,'Learning dataset must carry a deterministic SHA-256 content hash.');

$allDataset=glasses_learning_dataset($pdo,$org,$location,(string)$station['public_id'],30,false,100);
glci_assert((int)$allDataset['rowCount']===5,'Explicit full dataset may include uncorrected observations.');
$breadRows=array_values(array_filter($allDataset['rows'],static fn(array $row):bool=>$row['observationKey']==='learn-bread'));
glci_assert(count($breadRows)===1,'Full dataset must include Bread.');
glci_assert((string)$breadRows[0]['humanReview']['outcome']==='uncorrected','Untouched evidence must be labeled uncorrected, never correct.');
glci_assert($breadRows[0]['humanReview']['reviewed']===false,'Untouched evidence must not imply human verification.');
glci_assert((string)$breadRows[0]['source']['evidenceKind']==='WorkSurfaceUnprimed','Learning export must preserve Section 13 evidence metadata.');
glci_assert(!array_key_exists('bbox',$breadRows[0]['source']),'Governed learning export must not include bounding-box image geometry by default.');

$baconRows=array_values(array_filter($allDataset['rows'],static fn(array $row):bool=>$row['observationKey']==='learn-bacon'));
glci_assert((string)$baconRows[0]['humanReview']['outcome']==='reclassified','Dataset must identify human reclassification.');
glci_assert((string)$baconRows[0]['source']['componentKey']===$keys['Bacon'],'Dataset must preserve the original model classification.');
glci_assert((string)$baconRows[0]['effective']['componentKey']===$keys['Turkey'],'Dataset must expose the effective human-corrected component separately.');
glci_assert((string)$baconRows[0]['device']['sdkVersion']==='0.7.3-test','Dataset must retain model-runtime context available from the paired device.');

$wrongStation=glasses_learning_analytics($pdo,$org,$location,(string)$otherStation['public_id'],30);
glci_assert((int)$wrongStation['totals']['observations']===0,'Analytics must remain station scoped.');

$catalog=glasses_learning_catalog($pdo,[
    'organization_id'=>$org,
    'membership_id'=>0,
    'permissions'=>['*'],
    'is_owner_role'=>1,
]);
glci_assert($catalog['ready']===true&&$catalog['canExport']===true,'Owner learning catalog must be ready and export-capable.');
glci_assert(count($catalog['locations'])===1,'Learning catalog must expose accessible active locations.');
glci_assert(count($catalog['stationsByLocation'][(string)$location])===2,'Learning catalog must expose active KDS stations for the selected location.');

$modules=admin_modules(['permissions'=>['glasses.view'],'is_owner_role'=>0]);
$learningModules=array_values(array_filter($modules,static fn(array $module):bool=>($module['href']??'')==='glasses-learning.php'));
glci_assert(count($learningModules)===1,'Admin must expose Vision Learning with glasses.view permission.');

echo "glasses-learning-analytics-ok\n";
