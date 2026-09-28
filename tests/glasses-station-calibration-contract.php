<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-calibration.php';

$pdo=app_pdo();

function gscci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function gscci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}

gscci_assert(glasses_station_calibration_ready($pdo),'Station calibration migration must be installed.');

$slug='gscci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Station Calibration '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")
    ->execute([$org]);
$location=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Calibration','Lead','Calibration Lead']);
$user=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10
],$user);

$otherStation=kds_station_save($pdo,$org,$location,[
    'name'=>'Fry','slug'=>'fry','targetSeconds'=>240,'sortOrder'=>20
],$user);

$ids=[];
foreach(['Turkey','Bacon','Lettuce'] as $name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower($name).'-'.$slug]);
    $ids[$name]=(int)$pdo->lastInsertId();
}

$profile1=glasses_station_calibration_save(
    $pdo,$org,$location,(string)$station['public_id'],
    [
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'notes'=>'Primary sandwich-line camera view.',
        'zones'=>[
            [
                'zoneKey'=>'turkey-primary',
                'ingredientId'=>$ids['Turkey'],
                'displayName'=>'Turkey Pan',
                'x'=>0.08,'y'=>0.18,'width'=>0.18,'height'=>0.22,'priority'=>20,
                'metadata'=>['bin'=>'cold-1'],
            ],
            [
                'zoneKey'=>'turkey-backup',
                'ingredientId'=>$ids['Turkey'],
                'displayName'=>'Turkey Backup',
                'x'=>0.28,'y'=>0.18,'width'=>0.16,'height'=>0.22,'priority'=>5,
            ],
            [
                'zoneKey'=>'bacon-primary',
                'ingredientId'=>$ids['Bacon'],
                'displayName'=>'Bacon Pan',
                'x'=>0.48,'y'=>0.18,'width'=>0.16,'height'=>0.22,'priority'=>10,
            ],
        ],
    ],
    $user
);

gscci_assert((int)$profile1['version']===1&&(string)$profile1['status']==='active','First station calibration must be active version 1.');
gscci_assert(count($profile1['zones'])===3,'Calibration must persist every ingredient zone.');
gscci_assert((string)$profile1['zones'][0]['zoneKey']==='turkey-primary','Zones must be returned by priority.');
gscci_assert(strlen((string)$profile1['sourceHash'])===64,'Calibration source hash must be SHA-256.');

$exact=glasses_station_calibration_active($pdo,$org,$location,(int)$station['id'],[
    'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8'
]);
gscci_assert(is_array($exact)&&$exact['compatibility']['compatible']===true,'Matching runtime camera signature must be compatible.');

$widthMismatch=glasses_station_calibration_active($pdo,$org,$location,(int)$station['id'],[
    'platform'=>'inmo_air3','frameWidth'=>480,'frameHeight'=>480,'pixelFormat'=>'grayscale8'
]);
gscci_assert($widthMismatch['compatibility']['compatible']===false,'Frame-width mismatch must disable spatial calibration.');
gscci_assert(in_array('frame_width_mismatch',$widthMismatch['compatibility']['reasons'],true),'Compatibility must explain width mismatch.');

$pixelMismatch=glasses_station_calibration_active($pdo,$org,$location,(int)$station['id'],[
    'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'rgb24'
]);
gscci_assert($pixelMismatch['compatibility']['compatible']===false,'Pixel-format mismatch must disable spatial calibration.');
gscci_assert(in_array('pixel_format_mismatch',$pixelMismatch['compatibility']['reasons'],true),'Compatibility must explain pixel format mismatch.');

$invalid=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'ingredientId'=>$ids['Lettuce'],'x'=>0.90,'y'=>0.10,'width'=>0.20,'height'=>0.20,
        ]],
    ],$user);
}catch(InvalidArgumentException){$invalid=true;}
gscci_assert($invalid,'Out-of-frame normalized zone must be rejected.');
gscci_assert((int)gscci_one($pdo,'SELECT COUNT(*) FROM glasses_station_calibrations WHERE organization_id=? AND station_id=?',[$org,$station['id']])===1,'Rejected calibration must not create a partial version.');

$profile2=glasses_station_calibration_save(
    $pdo,$org,$location,(string)$station['public_id'],
    [
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'notes'=>'Adjusted after moving cold rail.',
        'zones'=>[
            [
                'zoneKey'=>'turkey-primary',
                'ingredientId'=>$ids['Turkey'],
                'displayName'=>'Turkey Pan',
                'x'=>0.10,'y'=>0.20,'width'=>0.18,'height'=>0.22,'priority'=>20,
            ],
            [
                'zoneKey'=>'bacon-primary',
                'ingredientId'=>$ids['Bacon'],
                'displayName'=>'Bacon Pan',
                'x'=>0.50,'y'=>0.20,'width'=>0.16,'height'=>0.22,'priority'=>10,
            ],
            [
                'zoneKey'=>'lettuce-primary',
                'ingredientId'=>$ids['Lettuce'],
                'displayName'=>'Lettuce Pan',
                'x'=>0.68,'y'=>0.20,'width'=>0.14,'height'=>0.22,'priority'=>10,
            ],
        ],
    ],
    $user
);

gscci_assert((int)$profile2['version']===2&&(string)$profile2['status']==='active','Second calibration must become active version 2.');
gscci_assert((string)$profile2['sourceHash']!==(string)$profile1['sourceHash'],'Changed station geometry must change source hash.');
gscci_assert((string)gscci_one($pdo,'SELECT status FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?',[$org,$profile1['publicId']])==='superseded','Activating v2 must supersede v1.');
gscci_assert((int)gscci_one($pdo,"SELECT COUNT(*) FROM glasses_station_calibrations WHERE organization_id=? AND station_id=? AND status='active'",[$org,$station['id']])===1,'Station must have exactly one active calibration.');

$versions=glasses_station_calibration_versions($pdo,$org,$location,(string)$station['public_id']);
gscci_assert(count($versions)===2&&(int)$versions[0]['version']===2&&(int)$versions[1]['version']===1,'Calibration history must retain version order.');

$none=glasses_station_calibration_active($pdo,$org,$location,(int)$otherStation['id'],[
    'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8'
]);
gscci_assert($none===null,'A station without a calibration must return no spatial profile.');

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-CAL-'.$slug,
    'displayName'=>'Sandwich AIR3',
    'platform'=>'inmo_air3'
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$deviceCalibration=glasses_station_calibration_active(
    $pdo,
    (int)$device['organization_id'],
    (int)$device['location_id'],
    $device['station_id']!==null?(int)$device['station_id']:null,
    [
        'platform'=>(string)$device['platform'],
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
    ]
);
gscci_assert(is_array($deviceCalibration)&&(string)$deviceCalibration['publicId']===(string)$profile2['publicId'],'Paired station must receive its active calibration.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Org '.$slug]);
$otherOrg=(int)$pdo->lastInsertId();
$isolation=false;
try{glasses_station_calibration_payload($pdo,$otherOrg,(string)$profile2['publicId'],null);}catch(InvalidArgumentException){$isolation=true;}
gscci_assert($isolation,'Calibration profiles must be organization isolated.');

echo "glasses-station-calibration-ok\n";
