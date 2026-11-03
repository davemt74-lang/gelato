<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||getenv('GELATO_VISION_BOUNDARY_FIXTURE')!=='1'){
    http_response_code(404);
    exit;
}
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/glasses-simulator.php';
require_once __DIR__.'/../includes/glasses-vision-lab.php';

$pdo=app_pdo();

function fixture_insert(PDO $pdo,string $sql,array $args): int
{
    $pdo->prepare($sql)->execute($args);
    return (int)$pdo->lastInsertId();
}

if(($argv[1]??'')==='state'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $state=[];
    foreach(['glasses_product_validations','glasses_validation_events','glasses_vision_model_assignments',
        'glasses_vision_shadow_runs','glasses_vision_training_missions'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE organization_id=?");
        $q->execute([$input['org']]);
        $state[$table]=(int)$q->fetchColumn();
    }
    $q=$pdo->prepare('SELECT last_seen_at FROM glasses_devices WHERE organization_id=? AND public_id=?');
    $q->execute([$input['org'],$input['device']]);
    $state['lastSeen']=$q->fetchColumn();
    echo json_encode($state,JSON_THROW_ON_ERROR);
    exit;
}
if(($argv[1]??'')!=='seed')throw new InvalidArgumentException('Choose seed or state.');

$tag='vision-'.bin2hex(random_bytes(5));
$org=fixture_insert($pdo,"INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')",[$tag]);
$otherOrg=fixture_insert($pdo,"INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')",[$tag.' other']);
$addLocation=fn(int $owner,string $name):int=>fixture_insert($pdo,
    "INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')",[$owner,$name]);
$east=$addLocation($org,'Fixture East');
$west=$addLocation($org,'Fixture West');
$foreignName='Private other restaurant '.$tag;
$foreign=$addLocation($otherOrg,$foreignName);
$password=bin2hex(random_bytes(18));
$hash=app_password_hash($password);
$roles=[];
foreach(['glasses.view','glasses.manage','kds.view','kds.update'] as $permission){
    $role=fixture_insert($pdo,'INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,0,0)',
        [$org,$permission,str_replace('.','-',$permission)]);
    $q=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
    $q->execute([$role,$permission]);
    if($q->rowCount()!==1)throw new RuntimeException('Missing fixture permission '.$permission);
    $roles[$permission]=$role;
}
$users=[];
$actor=null;
foreach([
    'manager'=>['glasses.view'=>null,'glasses.manage'=>null,'kds.view'=>null,'kds.update'=>null],
    'glasses-only'=>['glasses.view'=>$east],
    'split-view'=>['glasses.view'=>$east,'kds.view'=>$west],
    'viewer'=>['glasses.view'=>$east,'kds.view'=>$east],
    'local-manager'=>['glasses.view'=>$east,'glasses.manage'=>$east,'kds.view'=>$east,'kds.update'=>$east],
    'lab-viewer'=>['glasses.view'=>null],
] as $kind=>$grants){
    $email=$tag.'-'.$kind.'@example.test';
    $user=fixture_insert($pdo,"INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
        VALUES (?,?,'Fixture',?,?,'active')",[$email,$hash,$kind,'Vision Fixture '.$kind]);
    $actor??=$user;
    $membership=fixture_insert($pdo,"INSERT INTO organization_memberships (organization_id,user_id,status,primary_location_id)
        VALUES (?,?,'active',?)",[$org,$user,$east]);
    foreach($grants as $permission=>$location){
        fixture_insert($pdo,'INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,?,?)',
            [$membership,$roles[$permission],$location,$actor]);
    }
    $users[$kind]=$email;
}
$owner=fixture_insert($pdo,"INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,'Fixture','Owner','Fixture Owner','active')",[$tag.'-owner@example.test',$hash]);
$ownerRole=fixture_insert($pdo,"INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role)
    VALUES (?,'Fixture Owner','fixture-owner',1,1)",[$org]);
$ownerMembership=fixture_insert($pdo,"INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')",[$org,$owner]);
fixture_insert($pdo,'INSERT INTO user_roles (membership_id,role_id,assigned_by) VALUES (?,?,?)',
    [$ownerMembership,$ownerRole,$actor]);

$station=kds_station_save($pdo,$org,$east,['name'=>'Fixture Pizza','targetSeconds'=>300],$actor)['public_id'];
$westStation=kds_station_save($pdo,$org,$west,['name'=>'Fixture West Prep','targetSeconds'=>300],$actor)['public_id'];
$section=fixture_insert($pdo,"INSERT INTO menu_sections (organization_id,name,slug,status,sort_order)
    VALUES (?,'Fixture Pizza','pizza','active',1)",[$org]);
$item=fixture_insert($pdo,"INSERT INTO menu_items (organization_id,section_id,name,slug,is_active)
    VALUES (?,?,'Fixture Pepperoni Pizza','pepperoni',1)",[$org,$section]);
$price=fixture_insert($pdo,"INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order)
    VALUES (?,'Large','LRG',16.00,'USD',1)",[$item]);
$ingredient=fixture_insert($pdo,"INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status)
    VALUES (?,'Pepperoni','pepperoni','food','verified')",[$org]);
fixture_insert($pdo,"INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order)
    VALUES (?,?,'Pepperoni',0,1,1)",[$item,$ingredient]);
kds_route_save($pdo,$org,$east,$item,$station,$actor);
$grant=glasses_create_pairing_grant($pdo,$org,$east,$station,$actor,10);
$pairing=glasses_pair_device($pdo,$grant['pairingCode'],[
    'hardwareIdentifier'=>$tag,'displayName'=>'Fixture glasses','capabilities'=>['visionModelRuntimes'=>['onnx']],
]);
$token=$pairing['deviceToken'];
$paired=glasses_authenticate_token($pdo,$token);
$device=$paired['public_id'];
$westGrant=glasses_create_pairing_grant($pdo,$org,$west,$westStation,$actor,10);
$westDevice=glasses_pair_device($pdo,$westGrant['pairingCode'],['hardwareIdentifier'=>$tag.'-west','displayName'=>'West fixture glasses'])['device']['publicId'];

$ticket=pos_create_check($pdo,$org,$east,['serviceMode'=>'dine_in','tableName'=>'Fixture Table 8','guestCount'=>3],$actor);
$ticket=pos_add_item($pdo,$org,$ticket['publicId'],$price,1,'Private fixture preparation note',$actor);
$check=$ticket['publicId'];
kds_send_check($pdo,$org,$check,$actor);
$q=$pdo->prepare('SELECT id,public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');
$q->execute([$org,$ticket['items'][0]['id']]);
$routed=$q->fetch();
$kds=$routed['public_id'];
kds_event($pdo,$org,(int)$routed['id'],'glasses_item_note','queued','queued','Private fixture cook note',$actor);
$session=glasses_build_start($pdo,$paired,$kds,null)['publicId'];

$modelPackages=[];
foreach(['champion'=>'1.0.0','challenger'=>'2.0.0'] as $kind=>$version){
    $modelPackages[$kind]=glasses_vision_model_package_create($pdo,$org,[
        'detectorName'=>'boundary-model','modelName'=>'Fixture ingredient detector','modelVersion'=>$version,
        'runtimeType'=>'onnx','platform'=>'inmo_air3','artifactUrl'=>'https://models.example.test/'.$kind.'.onnx',
        'artifactSha256'=>hash('sha256',$tag.'-'.$kind),
        'metadata'=>$kind==='challenger'?['modelComparison'=>[
            'schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'goldenTestHash'=>hash('sha256',$tag),
        ]]:[],
    ],$actor)['publicId'];
}
$modelRollouts=[];
foreach(['east'=>$east,'west'=>$west,'global'=>null] as $kind=>$location){
    $modelRollouts[$kind]=glasses_vision_model_rollout_create($pdo,$org,[
        'targetPackagePublicId'=>$modelPackages['challenger'],'baselinePackagePublicId'=>$modelPackages['champion'],
        'locationId'=>$location,'canaryPercent'=>5,
    ],$actor)['publicId'];
}
$modelShadow=glasses_vision_shadow_assignment($pdo,$paired,$session,'boundary-model');
if(($modelShadow['action']??'')!=='shadow')throw new RuntimeException('Fixture shadow assignment must be available.');
for($frame=1;$frame<=30;$frame++)glasses_vision_shadow_report($pdo,$paired,$session,[
    'shadowRunPublicId'=>$modelShadow['runPublicId'],'frameKey'=>'fixture-'.$frame,
    'championDetectionCount'=>1,'challengerDetectionCount'=>1,'matchedCount'=>1,'meanIou'=>0.87,
]);
glasses_vision_shadow_complete($pdo,$paired,$session,$modelShadow['runPublicId']);

$dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>'Fixture dataset','versionLabel'=>'v1'],$actor)['publicId'];
foreach(['train','val','test'] as $split){
    $sample=glasses_public_id('vision-sample');
    $pdo->prepare("INSERT INTO glasses_vision_training_samples (organization_id,public_id,source_type,source_reference,
        review_status,review_outcome,canonical_label,annotation_json,reviewer_user_id,created_by,reviewed_at)
        VALUES (?,?,'governed_training_media',?,'approved','approve','pepperoni','[]',?,?,NOW(6))")
        ->execute([$org,$sample,'fixture-'.$split,$actor,$actor]);
    glasses_vision_lab_add_dataset_sample($pdo,$org,$dataset,$sample,$split);
}
$pdo->prepare("UPDATE glasses_devices SET last_seen_at='2000-01-01 00:00:00' WHERE organization_id=? AND public_id=?")
    ->execute([$org,$device]);
echo json_encode(compact('org','east','west','foreign','foreignName','station','westStation',
    'password','users','device','westDevice','token','check','kds','session','dataset','modelPackages','modelRollouts'),JSON_THROW_ON_ERROR);
