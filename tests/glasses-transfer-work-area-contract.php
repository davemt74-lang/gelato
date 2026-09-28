<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-calibration.php';

$pdo=app_pdo();

function gtaci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function gtaci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}

gtaci_assert(glasses_station_calibration_ready($pdo),'Transfer work-area migration must be installed.');

$slug='gtaci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Transfer Calibration '.$slug]);
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

$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Turkey',?,'food','verified')")
    ->execute([$org,'turkey-'.$slug]);
$turkey=(int)$pdo->lastInsertId();

$profile=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
    'platform'=>'inmo_air3',
    'frameWidth'=>640,
    'frameHeight'=>480,
    'pixelFormat'=>'grayscale8',
    'zones'=>[
        [
            'zoneKey'=>'turkey-pan',
            'ingredientId'=>$turkey,
            'displayName'=>'Turkey Pan',
            'x'=>0.06,'y'=>0.18,'width'=>0.18,'height'=>0.20,'priority'=>20,
        ],
    ],
    'workAreas'=>[
        [
            'areaKey'=>'sandwich-board',
            'role'=>'assembly',
            'displayName'=>'Sandwich Assembly Board',
            'x'=>0.46,'y'=>0.42,'width'=>0.30,'height'=>0.34,'priority'=>20,
            'metadata'=>['surface'=>'cutting-board'],
        ],
        [
            'areaKey'=>'plating-zone',
            'role'=>'plating',
            'displayName'=>'Plating Zone',
            'x'=>0.78,'y'=>0.42,'width'=>0.18,'height'=>0.34,'priority'=>10,
        ],
    ],
],$user);

gtaci_assert(count($profile['workAreas'])===2,'Calibration payload must include persisted work areas.');
gtaci_assert((string)$profile['workAreas'][0]['areaKey']==='sandwich-board','Work areas must be ordered by priority.');
gtaci_assert((string)$profile['workAreas'][0]['role']==='assembly','Assembly role must be retained.');
gtaci_assert((string)$profile['workAreas'][0]['metadata']['surface']==='cutting-board','Work-area metadata must round-trip.');
gtaci_assert((int)gtaci_one($pdo,'SELECT COUNT(*) FROM glasses_station_calibration_work_areas WHERE organization_id=?',[$org])===2,'Work areas must persist in the calibration-owned table.');

$hash1=(string)$profile['sourceHash'];
$profile2=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
    'platform'=>'inmo_air3',
    'frameWidth'=>640,
    'frameHeight'=>480,
    'pixelFormat'=>'grayscale8',
    'zones'=>[
        [
            'zoneKey'=>'turkey-pan',
            'ingredientId'=>$turkey,
            'displayName'=>'Turkey Pan',
            'x'=>0.06,'y'=>0.18,'width'=>0.18,'height'=>0.20,'priority'=>20,
        ],
    ],
    'workAreas'=>[
        [
            'areaKey'=>'sandwich-board',
            'role'=>'assembly',
            'displayName'=>'Sandwich Assembly Board',
            'x'=>0.50,'y'=>0.44,'width'=>0.28,'height'=>0.32,'priority'=>20,
        ],
    ],
],$user);

gtaci_assert((int)$profile2['version']===2,'Changing work-area geometry must create a new calibration version.');
gtaci_assert((string)$profile2['sourceHash']!==$hash1,'Work-area geometry must participate in calibration source hash.');
gtaci_assert((string)gtaci_one($pdo,'SELECT status FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?',[$org,$profile['publicId']])==='superseded','New work-area calibration must supersede previous active version.');

$invalid=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'zoneKey'=>'turkey-pan','ingredientId'=>$turkey,'x'=>0.06,'y'=>0.18,'width'=>0.18,'height'=>0.20,
        ]],
        'workAreas'=>[[
            'areaKey'=>'bad-board','role'=>'assembly','x'=>0.90,'y'=>0.90,'width'=>0.20,'height'=>0.20,
        ]],
    ],$user);
}catch(InvalidArgumentException){$invalid=true;}
gtaci_assert($invalid,'Out-of-frame work area must be rejected.');
gtaci_assert((int)gtaci_one($pdo,'SELECT COUNT(*) FROM glasses_station_calibrations WHERE organization_id=? AND station_id=?',[$org,$station['id']])===2,'Rejected work-area profile must not create a partial version.');

$duplicate=false;
try{
    glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
        'platform'=>'inmo_air3',
        'frameWidth'=>640,
        'frameHeight'=>480,
        'pixelFormat'=>'grayscale8',
        'zones'=>[[
            'zoneKey'=>'turkey-pan','ingredientId'=>$turkey,'x'=>0.06,'y'=>0.18,'width'=>0.18,'height'=>0.20,
        ]],
        'workAreas'=>[
            ['areaKey'=>'same','role'=>'assembly','x'=>0.40,'y'=>0.40,'width'=>0.10,'height'=>0.10],
            ['areaKey'=>'same','role'=>'assembly','x'=>0.55,'y'=>0.40,'width'=>0.10,'height'=>0.10],
        ],
    ],$user);
}catch(InvalidArgumentException){$duplicate=true;}
gtaci_assert($duplicate,'Work-area keys must be unique inside a calibration.');

$legacy=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
    'platform'=>'inmo_air3',
    'frameWidth'=>640,
    'frameHeight'=>480,
    'pixelFormat'=>'grayscale8',
    'zones'=>[[
        'zoneKey'=>'turkey-pan','ingredientId'=>$turkey,'x'=>0.06,'y'=>0.18,'width'=>0.18,'height'=>0.20,
    ]],
],$user);
gtaci_assert($legacy['workAreas']===[],'Existing calibration callers may omit work areas without breaking.');

echo "glasses-transfer-work-area-ok\n";
