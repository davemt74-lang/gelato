<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-calibration.php';

$pdo=app_pdo();

function gtci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function gtci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}

$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_station_calibration_regions'");
$q->execute();
gtci_assert((int)$q->fetchColumn()===1,'Station work-region migration must be installed.');

$slug='gtci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Transfer CI '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")
    ->execute([$org]);
$location=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Transfer','Lead','Transfer Lead']);
$user=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10
],$user);

$ids=[];
foreach(['Turkey','Bacon'] as $name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower($name).'-'.$slug]);
    $ids[$name]=(int)$pdo->lastInsertId();
}

$profile=glasses_station_calibration_save(
    $pdo,$org,$location,(string)$station['public_id'],
    [
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'zones'=>[
            [
                'zoneKey'=>'turkey-pan',
                'ingredientId'=>$ids['Turkey'],
                'displayName'=>'Turkey Pan',
                'x'=>0.05,'y'=>0.15,'width'=>0.20,'height'=>0.22,'priority'=>20,
            ],
            [
                'zoneKey'=>'bacon-pan',
                'ingredientId'=>$ids['Bacon'],
                'displayName'=>'Bacon Pan',
                'x'=>0.27,'y'=>0.15,'width'=>0.18,'height'=>0.22,'priority'=>20,
            ],
        ],
        'regions'=>[
            [
                'regionKey'=>'build-main',
                'regionType'=>'build_surface',
                'displayName'=>'Main Build Surface',
                'x'=>0.40,'y'=>0.45,'width'=>0.38,'height'=>0.35,'priority'=>20,
                'metadata'=>['surface'=>'cutting-board'],
            ],
            [
                'regionKey'=>'expo-edge',
                'regionType'=>'handoff_surface',
                'displayName'=>'Expo Edge',
                'x'=>0.80,'y'=>0.20,'width'=>0.15,'height'=>0.20,'priority'=>10,
            ],
        ],
    ],
    $user
);

gtci_assert(count($profile['regions'])===2,'Calibration payload must include station work regions.');
gtci_assert((string)$profile['regions'][0]['regionKey']==='build-main','Higher-priority build region must be first.');
gtci_assert((string)$profile['regions'][0]['regionType']==='build_surface','Build region type must persist.');
gtci_assert((float)$profile['regions'][0]['x']===0.40&&(float)$profile['regions'][0]['width']===0.38,'Normalized build region geometry must persist.');
gtci_assert((string)($profile['regions'][0]['metadata']['surface']??'')==='cutting-board','Region metadata must round-trip.');

$regionCount=(int)gtci_one(
    $pdo,
    'SELECT COUNT(*) FROM glasses_station_calibration_regions WHERE organization_id=? AND calibration_id=(SELECT id FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?)',
    [$org,$org,$profile['publicId']]
);
gtci_assert($regionCount===2,'Both work regions must be stored durably.');

$duplicate=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'ingredientId'=>$ids['Turkey'],'x'=>0.05,'y'=>0.15,'width'=>0.20,'height'=>0.22
        ]],
        'regions'=>[
            ['regionKey'=>'build-main','regionType'=>'build_surface','x'=>0.40,'y'=>0.45,'width'=>0.30,'height'=>0.30],
            ['regionKey'=>'build-main','regionType'=>'build_surface','x'=>0.50,'y'=>0.50,'width'=>0.30,'height'=>0.30],
        ],
    ],$user);
}catch(InvalidArgumentException){$duplicate=true;}
gtci_assert($duplicate,'Duplicate region keys must be rejected.');

$invalidType=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'ingredientId'=>$ids['Turkey'],'x'=>0.05,'y'=>0.15,'width'=>0.20,'height'=>0.22
        ]],
        'regions'=>[[
            'regionKey'=>'mystery','regionType'=>'unknown_surface','x'=>0.40,'y'=>0.45,'width'=>0.30,'height'=>0.30
        ]],
    ],$user);
}catch(InvalidArgumentException){$invalidType=true;}
gtci_assert($invalidType,'Unsupported station region types must fail closed.');

$invalidGeometry=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'ingredientId'=>$ids['Turkey'],'x'=>0.05,'y'=>0.15,'width'=>0.20,'height'=>0.22
        ]],
        'regions'=>[[
            'regionKey'=>'bad-build','regionType'=>'build_surface','x'=>0.90,'y'=>0.90,'width'=>0.20,'height'=>0.20
        ]],
    ],$user);
}catch(InvalidArgumentException){$invalidGeometry=true;}
gtci_assert($invalidGeometry,'Out-of-frame work region must be rejected.');

$profile2=glasses_station_calibration_save(
    $pdo,$org,$location,(string)$station['public_id'],
    [
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'zones'=>[
            ['zoneKey'=>'turkey-pan','ingredientId'=>$ids['Turkey'],'x'=>0.05,'y'=>0.15,'width'=>0.20,'height'=>0.22,'priority'=>20],
        ],
        'regions'=>[
            ['regionKey'=>'build-main','regionType'=>'build_surface','x'=>0.42,'y'=>0.45,'width'=>0.36,'height'=>0.35,'priority'=>20],
        ],
    ],
    $user
);

gtci_assert((int)$profile2['version']===(int)$profile['version']+1,'Changing work-region geometry must create a new calibration version.');
gtci_assert((string)$profile2['sourceHash']!==(string)$profile['sourceHash'],'Work-region geometry must participate in the calibration source hash.');
gtci_assert((string)gtci_one($pdo,'SELECT status FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?',[$org,$profile['publicId']])==='superseded','New region-aware profile must supersede the old active profile.');

echo "glasses-transfer-sequence-backend-ok\n";
