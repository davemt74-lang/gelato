<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-calibration-studio.php';
require_once __DIR__.'/../includes/admin-control-core.php';

$pdo=app_pdo();

function gcsi_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function gcsi_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}

gcsi_assert(glasses_calibration_studio_ready($pdo),'Calibration Studio must require all calibration/region migrations.');

$slug='gcsi-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Calibration Studio '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Main Kitchen','Phoenix','AZ','active',1,10)")
    ->execute([$org]);
$mainLocation=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Second Kitchen','Phoenix','AZ','active',0,20)")
    ->execute([$org]);
$secondLocation=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Calibration','Manager','Calibration Manager']);
$userId=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$mainLocation,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10
],$userId);
$station2=kds_station_save($pdo,$org,$secondLocation,[
    'name'=>'Fry','slug'=>'fry','targetSeconds'=>240,'sortOrder'=>20
],$userId);

foreach(['Turkey','Bacon','Lettuce'] as $name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower($name).'-'.$slug]);
}

$user=[
    'organization_id'=>$org,
    'membership_id'=>0,
    'permissions'=>['*'],
    'is_owner_role'=>1,
];

$catalog=glasses_calibration_studio_catalog($pdo,$user);
gcsi_assert($catalog['schemaReady']===true,'Catalog must report full schema ready.');
gcsi_assert($catalog['canManage']===true,'Wildcard/owner user must be able to manage glasses.');
gcsi_assert(count($catalog['locations'])===2,'Owner catalog must include both active locations.');
gcsi_assert((int)$catalog['locations'][0]['id']===$mainLocation&&$catalog['locations'][0]['primary']===true,'Primary location must sort first.');
gcsi_assert(count($catalog['stationsByLocation'][(string)$mainLocation]??[])===1,'Main location must expose its KDS station.');
gcsi_assert((string)$catalog['stationsByLocation'][(string)$mainLocation][0]['publicId']===(string)$station['public_id'],'Catalog station must preserve KDS public ID.');
gcsi_assert(count($catalog['stationsByLocation'][(string)$secondLocation]??[])===1,'Second location must expose its own station.');
gcsi_assert((string)$catalog['stationsByLocation'][(string)$secondLocation][0]['publicId']===(string)$station2['public_id'],'Station catalog must remain location scoped.');
gcsi_assert(count($catalog['ingredients'])===3,'Studio must expose canonical organization ingredients.');
gcsi_assert((string)$catalog['ingredients'][0]['name']==='Bacon','Ingredients must be alphabetically ordered for operator selection.');

$modules=admin_modules([
    'permissions'=>['glasses.view'],
    'is_owner_role'=>0,
]);
$glassesModules=array_values(array_filter($modules,static fn(array $m):bool=>($m['href']??'')==='glasses-calibration-studio.php'));
gcsi_assert(count($glassesModules)===1,'Admin dashboard must expose the calibration studio when glasses.view is granted.');

$modulesNoAccess=admin_modules([
    'permissions'=>['kds.view'],
    'is_owner_role'=>0,
]);
$hidden=array_values(array_filter($modulesNoAccess,static fn(array $m):bool=>($m['href']??'')==='glasses-calibration-studio.php'));
gcsi_assert(count($hidden)===0,'Calibration Studio must remain hidden without glasses permission.');

echo "glasses-calibration-studio-ok\n";
