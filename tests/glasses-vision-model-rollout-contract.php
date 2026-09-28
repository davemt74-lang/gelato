<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-models.php';
require_once __DIR__.'/../includes/admin-control-core.php';

$pdo=app_pdo();

function gvm_assert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function gvm_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}

gvm_assert(glasses_vision_models_ready($pdo),'Vision model rollout migration must be installed.');
gvm_assert(glasses_vision_model_version_at_least('v1.4.2','1.4.0'),'Version compatibility must accept newer SDK versions.');
gvm_assert(!glasses_vision_model_version_at_least('1.3.9','1.4.0'),'Version compatibility must reject older SDK versions.');
gvm_assert(!glasses_vision_model_version_at_least(null,'1.4.0'),'Missing runtime version must fail a declared minimum.');
$browserMeta=glasses_vision_browser_inference_metadata([
    'browserInference'=>[
        'schema'=>'gelato.browser_onnx_detector.v1','decoder'=>'yolo_v8',
        'input'=>['name'=>'images','width'=>640,'height'=>640,'layout'=>'nchw'],
        'output'=>['name'=>'output0','layout'=>'channels_first','boxScale'=>'pixels'],
        'labels'=>['turkey_slice','bacon_strip'],'nmsIou'=>0.45,'maxDetections'=>25,
    ],
],'onnx');
gvm_assert(is_array($browserMeta)&&$browserMeta['input']['width']===640,'Browser ONNX metadata must normalize supported detector configuration.');
gvm_assert($browserMeta['labels']===['turkey_slice','bacon_strip'],'Browser ONNX metadata must preserve ordered detector labels.');
gvm_assert(glasses_vision_browser_inference_metadata(['browserInference'=>['schema'=>'ignored']],'tflite')===null,'Non-ONNX runtimes must ignore browser ONNX metadata.');

$badBrowserMeta=false;
try{
    glasses_vision_browser_inference_metadata([
        'browserInference'=>[
            'schema'=>'gelato.browser_onnx_detector.v1','decoder'=>'yolo_v8',
            'input'=>['name'=>'images','width'=>0,'height'=>640,'layout'=>'nchw'],
            'output'=>['name'=>'output0','layout'=>'channels_first','boxScale'=>'pixels'],
            'labels'=>['turkey_slice'],
        ],
    ],'onnx');
}catch(InvalidArgumentException){$badBrowserMeta=true;}
gvm_assert($badBrowserMeta,'Invalid browser ONNX tensor metadata must fail closed.');


$slug='gvm-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vision Model CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Main Kitchen','Phoenix','AZ','active',1,10)")
    ->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Model','Manager','Model Manager']);$user=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")
    ->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Rollout Sandwich',?,1)")
    ->execute([$org,$section,'rollout-sandwich-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',13.00,'USD',1)")
    ->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Turkey',?,'food','verified')")
    ->execute([$org,'turkey-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Turkey',0,1,1)")
    ->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 20','guestCount'=>1],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$user);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');
$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-MODEL-'.$slug,
    'displayName'=>'Model AIR3',
    'platform'=>'inmo_air3',
    'sdkVersion'=>'1.5.0',
    'appVersion'=>'2.0.0',
    'capabilities'=>['visionModelRuntimes'=>['onnx','tflite']],
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

$badUrl=false;
try{
    glasses_vision_model_package_create($pdo,$org,[
        'detectorName'=>'food-model-v3','modelName'=>'bad','modelVersion'=>'1.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
        'artifactUrl'=>'http://models.example.test/bad.onnx','artifactSha256'=>str_repeat('a',64),
    ],$user);
}catch(InvalidArgumentException){$badUrl=true;}
gvm_assert($badUrl,'Model packages must reject non-HTTPS artifact URLs.');

$badSha=false;
try{
    glasses_vision_model_package_create($pdo,$org,[
        'detectorName'=>'food-model-v3','modelName'=>'bad-sha','modelVersion'=>'1.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
        'artifactUrl'=>'https://models.example.test/bad.onnx','artifactSha256'=>'bad',
    ],$user);
}catch(InvalidArgumentException){$badSha=true;}
gvm_assert($badSha,'Model packages must reject invalid SHA-256 values.');

$baseline=glasses_vision_model_package_create($pdo,$org,[
    'detectorName'=>'Food Model V3',
    'modelName'=>'sandwich-detector','modelVersion'=>'1.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
    'artifactUrl'=>'https://models.example.test/sandwich-detector-1.onnx','artifactSha256'=>str_repeat('1',64),
    'artifactBytes'=>1024,'minimumSdkVersion'=>'1.0.0','minimumAppVersion'=>'1.0.0','notes'=>'Known-good baseline.',
],$user);
$target=glasses_vision_model_package_create($pdo,$org,[
    'detectorName'=>'food-model-v3',
    'modelName'=>'sandwich-detector','modelVersion'=>'2.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
    'artifactUrl'=>'https://models.example.test/sandwich-detector-2.onnx','artifactSha256'=>str_repeat('2',64),
    'artifactBytes'=>2048,'minimumSdkVersion'=>'1.4.0','minimumAppVersion'=>'1.5.0',
    'metadata'=>['browserInference'=>[
        'schema'=>'gelato.browser_onnx_detector.v1','decoder'=>'yolo_v8',
        'input'=>['name'=>'images','width'=>640,'height'=>640,'layout'=>'nchw'],
        'output'=>['name'=>'output0','layout'=>'channels_first','boxScale'=>'pixels'],
        'labels'=>['turkey_slice'],'nmsIou'=>0.45,'maxDetections'=>25,
    ]],
],$user);

gvm_assert((string)$baseline['detectorName']==='food-model-v3','Detector identity must normalize at package registration.');
gvm_assert($target['hasArtifactBytes']===true&&(int)$target['artifactBytes']===2048,'Package manifest must preserve artifact byte size.');
gvm_assert(($target['metadata']['browserInference']['schema']??'')==='gelato.browser_onnx_detector.v1','Registered ONNX package must preserve validated browser inference metadata.');
gvm_assert(($target['metadata']['browserInference']['input']['name']??'')==='images','Registered ONNX package must preserve validated input tensor identity.');
$comparisonMeta=[
    'modelComparison'=>[
        'schema'=>'gelato.vision_model_comparison.v1',
        'eligible'=>true,
        'override'=>false,
        'goldenTestHash'=>str_repeat('a',64),
        'regressions'=>[],
        'summary'=>['map50Delta'=>0.02,'recallDelta'=>0.01,'demonstratedGain'=>true],
    ],
];
$normalizedComparison=glasses_vision_model_comparison_metadata($comparisonMeta);
gvm_assert($normalizedComparison['eligible']===true&&$normalizedComparison['summary']['map50Delta']===0.02,'Champion/challenger metadata must normalize deterministic eligibility.');
$badComparison=false;
try{glasses_vision_model_comparison_metadata(['modelComparison'=>['schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>true,'goldenTestHash'=>str_repeat('b',64)]]);}catch(InvalidArgumentException){$badComparison=true;}
gvm_assert($badComparison,'Comparison overrides must require a documented tradeoff reason.');



$duplicate=false;
try{
    glasses_vision_model_package_create($pdo,$org,[
        'detectorName'=>'food-model-v3','modelName'=>'sandwich-detector','modelVersion'=>'2.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
        'artifactUrl'=>'https://models.example.test/duplicate.onnx','artifactSha256'=>str_repeat('3',64),
    ],$user);
}catch(InvalidArgumentException){$duplicate=true;}
gvm_assert($duplicate,'Registered model identity must be immutable/unique.');


$ineligible=glasses_vision_model_package_create($pdo,$org,[
    'detectorName'=>'food-model-v3','modelName'=>'sandwich-detector','modelVersion'=>'1.9.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
    'artifactUrl'=>'https://models.example.test/sandwich-detector-ineligible.onnx','artifactSha256'=>str_repeat('9',64),
    'metadata'=>['modelComparison'=>[
        'schema'=>'gelato.vision_model_comparison.v1','eligible'=>false,'override'=>false,
        'goldenTestHash'=>str_repeat('c',64),'regressions'=>['overall precision regression -0.0400'],
        'summary'=>['map50Delta'=>0.01,'recallDelta'=>0.01,'demonstratedGain'=>true],
    ]],
],$user);
$ineligibleDraft=glasses_vision_model_rollout_create($pdo,$org,[
    'targetPackagePublicId'=>$ineligible['publicId'],'baselinePackagePublicId'=>$baseline['publicId'],
    'locationId'=>$location,'stationPublicId'=>(string)$station['public_id'],'canaryPercent'=>10,
],$user);
$comparisonBlocked=false;
try{glasses_vision_model_rollout_activate($pdo,$org,(string)$ineligibleDraft['publicId'],$user);}catch(InvalidArgumentException){$comparisonBlocked=true;}
gvm_assert($comparisonBlocked,'Comparison-aware package marked ineligible must never activate for canary rollout.');

$draft=glasses_vision_model_rollout_create($pdo,$org,[
    'targetPackagePublicId'=>$target['publicId'],'baselinePackagePublicId'=>$baseline['publicId'],
    'locationId'=>$location,'stationPublicId'=>(string)$station['public_id'],'canaryPercent'=>0,
    'notes'=>'Start all devices on baseline.',
],$user);
gvm_assert((string)$draft['status']==='draft'&&(float)$draft['canaryPercent']===0.0,'New rollout must remain draft.');

$before=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$before['action']==='hold'&&(string)$before['reason']==='no_rollout','Draft rollout must never be assigned.');

$active=glasses_vision_model_rollout_activate($pdo,$org,(string)$draft['publicId'],$user);
gvm_assert((string)$active['status']==='active','Activation must be explicit.');
$baselineAssignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$baselineAssignment['action']==='apply','Compatible rollout must produce an apply assignment.');
gvm_assert((string)$baselineAssignment['selection']==='baseline','Zero-percent canary must select baseline.');
gvm_assert((string)$baselineAssignment['package']['publicId']===(string)$baseline['publicId'],'Baseline cohort must receive baseline package.');
gvm_assert($baselineAssignment['compatibility']['compatible']===true,'Compatible runtime must pass server policy.');
gvm_assert(strlen((string)$baselineAssignment['assignmentKey'])===64,'Assignment must have deterministic SHA-256 identity.');
$bucket=glasses_vision_model_canary_bucket((string)$draft['publicId'],(string)$device['public_id']);
gvm_assert(abs((float)$baselineAssignment['canaryBucket']-$bucket)<0.0001,'Assignment must expose deterministic canary bucket.');

$conflictDraft=glasses_vision_model_rollout_create($pdo,$org,[
    'targetPackagePublicId'=>$target['publicId'],'baselinePackagePublicId'=>$baseline['publicId'],
    'locationId'=>$location,'stationPublicId'=>(string)$station['public_id'],'canaryPercent'=>10,
],$user);
$conflict=false;
try{glasses_vision_model_rollout_activate($pdo,$org,(string)$conflictDraft['publicId'],$user);}catch(InvalidArgumentException){$conflict=true;}
gvm_assert($conflict,'Overlapping rollout cannot take the same detector and exact scope.');

$retireBlocked=false;
try{glasses_vision_model_package_retire($pdo,$org,(string)$baseline['publicId'],$user);}catch(InvalidArgumentException){$retireBlocked=true;}
gvm_assert($retireBlocked,'Active rollout baseline cannot be retired.');

$advanced=glasses_vision_model_rollout_advance($pdo,$org,(string)$draft['publicId'],100,$user);
gvm_assert((float)$advanced['canaryPercent']===100.0,'Canary advancement must be explicit.');
$targetAssignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$targetAssignment['selection']==='target','100% rollout must select target.');
gvm_assert((string)$targetAssignment['package']['publicId']===(string)$target['publicId'],'Target cohort must receive target package.');
gvm_assert((string)$targetAssignment['assignmentKey']!==(string)$baselineAssignment['assignmentKey'],'Assignment identity must change when rollout selection changes.');

$decrease=false;
try{glasses_vision_model_rollout_advance($pdo,$org,(string)$draft['publicId'],50,$user);}catch(InvalidArgumentException){$decrease=true;}
gvm_assert($decrease,'Advance action cannot reduce canary percentage.');

$paused=glasses_vision_model_rollout_pause($pdo,$org,(string)$draft['publicId'],$user);
gvm_assert((string)$paused['status']==='paused','Active rollout must support explicit pause.');
$pausedAssignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$pausedAssignment['action']==='hold'&&(string)$pausedAssignment['reason']==='rollout_paused','Paused rollout must not create a new package action.');

$resumed=glasses_vision_model_rollout_activate($pdo,$org,(string)$draft['publicId'],$user);
gvm_assert((string)$resumed['status']==='active','Paused rollout must resume only explicitly.');
$rolled=glasses_vision_model_rollout_rollback($pdo,$org,(string)$draft['publicId'],$user,'Correction rate spike during lunch.');
gvm_assert((string)$rolled['status']==='rolled_back','Rollback must be explicit and durable.');
$rollbackAssignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$rollbackAssignment['selection']==='rollback','Rolled-back rollout must expose rollback selection.');
gvm_assert((string)$rollbackAssignment['package']['publicId']===(string)$baseline['publicId'],'Rollback must restore declared baseline.');

$retireRollbackBaseline=false;
try{glasses_vision_model_package_retire($pdo,$org,(string)$baseline['publicId'],$user);}catch(InvalidArgumentException){$retireRollbackBaseline=true;}
gvm_assert($retireRollbackBaseline,'Rollback baseline must remain protected from retirement.');

$incompatible=glasses_vision_model_package_create($pdo,$org,[
    'detectorName'=>'food-model-v3','modelName'=>'sandwich-detector','modelVersion'=>'3.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
    'artifactUrl'=>'https://models.example.test/sandwich-detector-3.onnx','artifactSha256'=>str_repeat('3',64),
    'minimumSdkVersion'=>'9.0.0','minimumAppVersion'=>'9.0.0',
],$user);
$incompatibleDraft=glasses_vision_model_rollout_create($pdo,$org,[
    'targetPackagePublicId'=>$incompatible['publicId'],'baselinePackagePublicId'=>$baseline['publicId'],
    'locationId'=>$location,'stationPublicId'=>(string)$station['public_id'],'canaryPercent'=>100,
],$user);
glasses_vision_model_rollout_activate($pdo,$org,(string)$incompatibleDraft['publicId'],$user);
$incompatibleAssignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert((string)$incompatibleAssignment['action']==='hold'&&(string)$incompatibleAssignment['reason']==='incompatible_package','Incompatible target must fail closed instead of auto-falling back.');
gvm_assert(in_array('sdk_version_too_old_or_unknown',$incompatibleAssignment['compatibility']['reasons'],true),'Incompatible assignment must explain SDK floor failure.');
glasses_vision_model_rollout_rollback($pdo,$org,(string)$incompatibleDraft['publicId'],$user,'Compatibility gate test complete.');

$report=glasses_vision_model_report($pdo,$device,[
    'assignmentKey'=>(string)$targetAssignment['assignmentKey'],
    'reportKey'=>(string)$targetAssignment['assignmentKey'].':seen','reportType'=>'assignment_seen',
    'rolloutPublicId'=>(string)$draft['publicId'],'packagePublicId'=>(string)$target['publicId'],
    'runtimeState'=>'assignment_received',
]);
gvm_assert($report['idempotent']===false,'First model report must append to rollout ledger.');
$reportAgain=glasses_vision_model_report($pdo,$device,[
    'assignmentKey'=>(string)$targetAssignment['assignmentKey'],
    'reportKey'=>(string)$targetAssignment['assignmentKey'].':seen','reportType'=>'assignment_seen',
]);
gvm_assert($reportAgain['idempotent']===true,'Report keys must be idempotent per device.');

$wrongVerified=false;
try{
    glasses_vision_model_report($pdo,$device,[
        'assignmentKey'=>(string)$targetAssignment['assignmentKey'],
        'reportKey'=>(string)$targetAssignment['assignmentKey'].':verified','reportType'=>'verified','rolloutPublicId'=>(string)$draft['publicId'],
        'packagePublicId'=>(string)$target['publicId'],'artifactSha256'=>str_repeat('f',64),'runtimeState'=>'verified',
    ]);
}catch(InvalidArgumentException){$wrongVerified=true;}
gvm_assert($wrongVerified,'Verified/activated reports must reject checksum mismatch.');

$verified=glasses_vision_model_report($pdo,$device,[
    'assignmentKey'=>(string)$targetAssignment['assignmentKey'],
    'reportKey'=>(string)$targetAssignment['assignmentKey'].':verified','reportType'=>'verified','rolloutPublicId'=>(string)$draft['publicId'],
    'packagePublicId'=>(string)$target['publicId'],'artifactSha256'=>(string)$target['artifactSha256'],'runtimeState'=>'verified',
]);
gvm_assert($verified['idempotent']===false,'Correct checksum verification must be recordable.');

$forgedTarget=false;
try{
    glasses_vision_model_report($pdo,$device,[
        'assignmentKey'=>(string)$baselineAssignment['assignmentKey'],
        'reportKey'=>(string)$baselineAssignment['assignmentKey'].':activated',
        'reportType'=>'activated',
        'rolloutPublicId'=>(string)$draft['publicId'],
        'packagePublicId'=>(string)$target['publicId'],
        'artifactSha256'=>(string)$target['artifactSha256'],
        'runtimeState'=>'active',
    ]);
}catch(InvalidArgumentException){$forgedTarget=true;}
gvm_assert($forgedTarget,'A baseline-issued assignment must never be able to claim target-package activation.');

$assignmentLedger=(int)gvm_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_assignments WHERE organization_id=? AND device_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)",[$org,(int)$device['id'],$org,$sessionPublic]);
gvm_assert($assignmentLedger>=5,'Every materially different device/build model assignment must be recorded in the immutable assignment ledger.');

$metrics=glasses_vision_model_rollout_metrics($pdo,$org,(string)$draft['publicId']);
gvm_assert((int)($metrics['byType']['assignment_seen']['devices']??0)===1,'Rollout metrics must count assignment-seen devices.');
gvm_assert((int)($metrics['byType']['verified']['devices']??0)===1,'Rollout metrics must count verified devices.');

$eventCount=(int)gvm_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollout_events WHERE organization_id=? AND rollout_id=(SELECT id FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?)",[$org,$org,$draft['publicId']]);
gvm_assert($eventCount===6,'Rollout must append create, activate, advance, pause, resume, and rollback audit events.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-MODEL-2-'.$slug,'displayName'=>'Other Model AIR3','platform'=>'inmo_air3',
    'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0'
]);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$crossDevice=false;
try{glasses_vision_model_assignment($pdo,$device2,$sessionPublic,'food-model-v3');}catch(InvalidArgumentException){$crossDevice=true;}
gvm_assert($crossDevice,'Model assignment must preserve build-session device isolation.');

$compat=glasses_vision_model_package_compatibility(glasses_vision_model_package_row($pdo,$org,(string)$baseline['publicId']),$device2);
gvm_assert($compat['compatible']===false&&in_array('vision_runtime_capability_unreported',$compat['reasons'],true),'Unreported model-runtime capability must fail closed.');


$shadowTarget=glasses_vision_model_package_create($pdo,$org,[
    'detectorName'=>'food-model-v3','modelName'=>'sandwich-detector','modelVersion'=>'4.0.0','runtimeType'=>'onnx','platform'=>'inmo_air3',
    'artifactUrl'=>'https://models.example.test/sandwich-detector-shadow.onnx','artifactSha256'=>str_repeat('4',64),
    'metadata'=>['modelComparison'=>[
        'schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>false,
        'goldenTestHash'=>str_repeat('d',64),'regressions'=>[],
        'summary'=>['map50Delta'=>0.03,'recallDelta'=>0.02,'demonstratedGain'=>true],
    ]],
],$user);
$shadowDraft=glasses_vision_model_rollout_create($pdo,$org,[
    'targetPackagePublicId'=>$shadowTarget['publicId'],'baselinePackagePublicId'=>$baseline['publicId'],
    'locationId'=>$location,'stationPublicId'=>(string)$station['public_id'],'canaryPercent'=>10,
    'notes'=>'Shadow challenger before canary.',
],$user);
$shadowBlocked=false;
try{glasses_vision_model_rollout_activate($pdo,$org,(string)$shadowDraft['publicId'],$user);}catch(InvalidArgumentException){$shadowBlocked=true;}
gvm_assert($shadowBlocked,'Comparison-aware challenger must not activate before a passing live shadow run.');

$shadow=glasses_vision_shadow_assignment($pdo,$device,$sessionPublic,'food-model-v3');
gvm_assert(($shadow['action']??'')==='shadow','Eligible draft challenger must be available in non-authoritative shadow mode.');
gvm_assert((string)$shadow['champion']['publicId']===(string)$baseline['publicId'],'Shadow assignment champion must be the rollout baseline.');
gvm_assert((string)$shadow['challenger']['publicId']===(string)$shadowTarget['publicId'],'Shadow assignment challenger must be the rollout target.');
for($i=1;$i<=30;$i++){
    $reported=glasses_vision_shadow_report($pdo,$device,$sessionPublic,[
        'shadowRunPublicId'=>$shadow['runPublicId'],'frameKey'=>'frame-'.$i,
        'championDetectionCount'=>1,'challengerDetectionCount'=>1,'matchedCount'=>1,
        'championOnlyCount'=>0,'challengerOnlyCount'=>0,'meanIou'=>0.82,
        'championMeanConfidence'=>0.91,'challengerMeanConfidence'=>0.93,
        'correctionAlignment'=>$i===30?'challenger':'unknown','criticalMismatch'=>false,
        'metadata'=>['source'=>'ci_shadow','authoritative'=>false],
    ]);
}
$duplicateShadow=glasses_vision_shadow_report($pdo,$device,$sessionPublic,[
    'shadowRunPublicId'=>$shadow['runPublicId'],'frameKey'=>'frame-30',
    'championDetectionCount'=>1,'challengerDetectionCount'=>1,'matchedCount'=>1,
]);
gvm_assert($duplicateShadow['idempotent']===true,'Shadow frame keys must be idempotent per run.');
$shadowComplete=glasses_vision_shadow_complete($pdo,$device,$sessionPublic,(string)$shadow['runPublicId']);
gvm_assert($shadowComplete['frameCount']===30&&$shadowComplete['eligibleForCanary']===true,'Thirty clean same-frame evaluations must satisfy the default shadow canary gate.');
gvm_assert($shadowComplete['correctionChallengerWins']===1&&$shadowComplete['correctionChampionWins']===0,'Shadow summary must preserve correction alignment evidence.');
$shadowActive=glasses_vision_model_rollout_activate($pdo,$org,(string)$shadowDraft['publicId'],$user);
gvm_assert((string)$shadowActive['status']==='active','Passing shadow evidence must unlock explicit canary activation.');
glasses_vision_model_rollout_rollback($pdo,$org,(string)$shadowDraft['publicId'],$user,'Shadow gate contract complete.');

$catalog=glasses_vision_model_catalog($pdo,[
    'organization_id'=>$org,'permissions'=>['*'],'is_owner_role'=>1,
]);
gvm_assert($catalog['ready']===true&&$catalog['canManage']===true,'Owner model catalog must be ready/manageable.');
gvm_assert(count($catalog['packages'])===5,'Catalog must expose registered model packages.');
gvm_assert(count($catalog['rollouts'])===5,'Catalog must expose all rollout plans.');
gvm_assert(isset($catalog['metricsByRollout'][(string)$draft['publicId']]),'Catalog must expose rollout telemetry.');

$modules=admin_modules(['permissions'=>['glasses.view'],'is_owner_role'=>0]);
$modelModules=array_values(array_filter($modules,static fn(array $row):bool=>($row['href']??'')==='glasses-vision-models.php'));
gvm_assert(count($modelModules)===1,'Admin must expose AR Vision Models with glasses permission.');

gvm_assert((string)gvm_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Model rollout governance must never mutate the KDS lifecycle.');

echo "glasses-vision-model-rollout-ok\n";
