<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-profiles.php';
require_once __DIR__.'/../includes/admin-control-core.php';

$pdo=app_pdo();

function gvlp_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gvlp_mapping(array $profile,string $normalized): array {
    foreach($profile['mappings'] as $mapping)if((string)$mapping['normalizedLabel']===$normalized)return $mapping;
    throw new RuntimeException('Vision mapping not found: '.$normalized);
}

gvlp_assert(glasses_vision_profiles_ready($pdo),'Vision label profile migration must be installed.');
gvlp_assert(glasses_vision_normalize_label(' Turkey__Slice ')==='turkey slice','Model-label normalization must match client normalization.');
gvlp_assert(glasses_vision_normalize_detector(' Food Model V3 ')==='food-model-v3','Detector names must normalize deterministically.');

$slug='gvlp-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Label CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,'Main Kitchen','Phoenix','AZ','active',1,10)")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Vision','Manager','Vision Manager']);$user=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Club Sandwich',?,1)")->execute([$org,$section,'club-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',15.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$ingredients=[];
foreach(['Turkey','Bacon','Swiss Cheese'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower(str_replace(' ','-',$name)).'-'.$slug]);
    $ingredients[$name]=(int)$pdo->lastInsertId();
    if($name!=='Swiss Cheese'){
        $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')->execute([$item,$ingredients[$name],$name,$i+1]);
    }
}
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 19','guestCount'=>1],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$user);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-LABEL-'.$slug,'displayName'=>'Label AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

$genericTurkey=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Turkey'],
    'detectorName'=>'*',
    'modelLabel'=>'turkey_slice',
    'minimumConfidence'=>0.70,
    'notes'=>'Generic turkey alias.',
],$user);
$specificTurkey=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Turkey'],
    'detectorName'=>'Food Model V3',
    'modelLabel'=>'Turkey Slice',
    'minimumConfidence'=>0.82,
    'notes'=>'Detector-specific confidence floor.',
],$user);
$bacon=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Bacon'],
    'detectorName'=>'*',
    'modelLabel'=>'bacon_strip',
],$user);
$cheese=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Swiss Cheese'],
    'detectorName'=>'*',
    'modelLabel'=>'cheese_slice',
    'minimumConfidence'=>0.91,
],$user);
$genericMystery=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Turkey'],
    'detectorName'=>'*',
    'modelLabel'=>'mystery_slice',
    'minimumConfidence'=>0.72,
],$user);
$specificMystery=glasses_vision_profile_save($pdo,$org,[
    'ingredientId'=>$ingredients['Swiss Cheese'],
    'detectorName'=>'Food Model V3',
    'modelLabel'=>'mystery slice',
    'minimumConfidence'=>0.93,
],$user);

gvlp_assert((string)$specificTurkey['detectorName']==='food-model-v3','Saved detector name must use normalized runtime identity.');
gvlp_assert((string)$specificTurkey['normalizedLabel']==='turkey slice','Saved model label must use stable normalized label.');

$duplicate=false;
try{
    glasses_vision_profile_save($pdo,$org,[
        'ingredientId'=>$ingredients['Bacon'],
        'detectorName'=>'*',
        'modelLabel'=>'Turkey Slice',
    ],$user);
}catch(InvalidArgumentException){$duplicate=true;}
gvlp_assert($duplicate,'Same detector cannot map one normalized model label to multiple ingredients.');

$weak=false;
try{
    glasses_vision_profile_save($pdo,$org,[
        'ingredientId'=>$ingredients['Turkey'],
        'detectorName'=>'another-model',
        'modelLabel'=>'weak-turkey',
        'minimumConfidence'=>0.49,
    ],$user);
}catch(InvalidArgumentException){$weak=true;}
gvlp_assert($weak,'Per-label profile cannot weaken the 0.50 global safety floor.');

$profile=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,'Food Model V3');
gvlp_assert((string)$profile['schema']==='gelato.vision_label_profile.v1','Runtime vision profile schema must be versioned.');
gvlp_assert((string)$profile['detectorName']==='food-model-v3','Runtime detector identity must be normalized.');
gvlp_assert(count($profile['mappings'])===2,'Runtime profile must include only recipe ingredients, excluding unrelated Cheese and detector-specific non-recipe overrides.');
gvlp_assert(count(array_filter($profile['mappings'],static fn(array $row):bool=>$row['normalizedLabel']==='mystery slice'))===0,'Detector-specific non-recipe override must suppress the generic recipe mapping and fail closed.');
gvlp_assert(strlen((string)$profile['profileHash'])===64,'Runtime profile must carry a deterministic SHA-256 hash.');

$turkey=gvlp_mapping($profile,'turkey slice');
$baconMap=gvlp_mapping($profile,'bacon strip');
gvlp_assert((string)$turkey['componentKey']==='ingredient:'.$ingredients['Turkey'],'Profile must map detector label to active build component key.');
gvlp_assert((string)$turkey['sourceDetector']==='food-model-v3','Detector-specific mapping must override generic mapping with the same label.');
gvlp_assert(abs((float)$turkey['minimumConfidence']-0.82)<0.0001&&$turkey['hasMinimumConfidence']===true,'Specific threshold must override generic threshold.');
gvlp_assert((string)$baconMap['sourceDetector']==='*','Generic mapping must remain available when no detector-specific override exists.');
gvlp_assert($baconMap['minimumConfidence']===null&&$baconMap['hasMinimumConfidence']===false,'Missing per-label threshold must preserve the client global floor.');

$profileAgain=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,'food-model-v3');
gvlp_assert((string)$profileAgain['profileHash']===(string)$profile['profileHash'],'Identical build/profile input must produce the same profile hash.');

$otherDetector=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,'other-model');
$generic=gvlp_mapping($otherDetector,'turkey slice');
gvlp_assert((string)$generic['sourceDetector']==='*'&&abs((float)$generic['minimumConfidence']-0.70)<0.0001,'Other detectors must fall back to generic mapping.');

glasses_vision_profile_set_status($pdo,$org,(string)$bacon['publicId'],'inactive',$user);
$withoutBacon=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,'food-model-v3');
gvlp_assert(count($withoutBacon['mappings'])===1,'Inactive mappings must not be delivered to the glasses runtime.');
glasses_vision_profile_set_status($pdo,$org,(string)$bacon['publicId'],'active',$user);

$catalog=glasses_vision_profile_catalog($pdo,[
    'organization_id'=>$org,
    'permissions'=>['*'],
    'is_owner_role'=>1,
]);
gvlp_assert($catalog['ready']===true&&$catalog['canManage']===true,'Owner vision registry catalog must be manageable.');
gvlp_assert(count($catalog['profiles'])===6&&count($catalog['ingredients'])===3,'Registry catalog must expose organization-scoped mappings and ingredients.');

$modules=admin_modules(['permissions'=>['glasses.view'],'is_owner_role'=>0]);
$module=array_values(array_filter($modules,static fn(array $row):bool=>($row['href']??'')==='glasses-vision-profiles.php'));
gvlp_assert(count($module)===1,'Admin must expose AR Vision Labels to users with glasses permission.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],['hardwareIdentifier'=>'AIR3-LABEL-2-'.$slug,'displayName'=>'Other AIR3']);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$isolated=false;
try{glasses_vision_profile_for_build($pdo,$device2,$sessionPublic,'food-model-v3');}catch(InvalidArgumentException){$isolated=true;}
gvlp_assert($isolated,'Vision profile delivery must preserve build-session device isolation.');

echo "glasses-vision-label-profiles-ok\n";
