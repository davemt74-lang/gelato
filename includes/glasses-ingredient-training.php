<?php
declare(strict_types=1);
/**
 * Section 267: guarded bridge into EXISTING V5 Vision Lab media and V11 sessions.
 * No new sample or model table; training images enter the normal pending-review queue.
 */
require_once __DIR__.'/glasses-employee-runtimes.php';
require_once __DIR__.'/glasses-work.php';
require_once __DIR__.'/glasses-vision-training-media.php';

const GLASSES_INGREDIENT_TRAINING_PHASES=[
    'isolated','empty_base','before_add','after_add','mixed','occluded','negative'
];
const GLASSES_INGREDIENT_TRAINING_BOX_PHASES=[
    'isolated','after_add','mixed','occluded'
];

function glasses_ingredient_training_binding(PDO $pdo,array $user,string $runtimePublic): array
{
    if(!glasses_employee_runtimes_ready($pdo)||!glasses_training_ready($pdo)
        ||!glasses_vision_training_media_ready($pdo)){
        throw new DomainException('Install the existing glasses, V11 training and Vision Lab migrations first.');
    }
    if(!app_has_permission('glasses.manage',$user))throw new DomainException('Glasses training management permission required.');
    $runtime=glasses_employee_runtime_row($pdo,$user,$runtimePublic);
    $device=glasses_device_row($pdo,(int)$user['organization_id'],(string)$runtime['device_public_id']);
    glasses_employee_runtime_assert_binding($pdo,$user,$runtimePublic,$device);
    if(!operational_location_allowed($pdo,$user,'glasses.manage',(int)$runtime['location_id']))
        throw new DomainException('Training is not authorized at this restaurant location.');

    $q=$pdo->prepare("SELECT s.*,a.public_id assignment_public_id,p.public_id program_public_id,
          p.name program_name
        FROM glasses_training_sessions s
        JOIN glasses_training_assignments a ON a.id=s.assignment_id
        JOIN glasses_training_programs p ON p.id=a.program_id
        WHERE s.organization_id=? AND s.user_id=? AND s.device_id=? AND s.status='active'
        ORDER BY s.id DESC LIMIT 1");
    $q->execute([(int)$user['organization_id'],(int)$runtime['employee_user_id'],(int)$device['id']]);
    $session=$q->fetch(PDO::FETCH_ASSOC);
    if(!$session)throw new DomainException('Start an assigned V11 employee training session before capturing ingredient samples.');
    if(($session['location_id']!==null&&(int)$session['location_id']!==(int)$device['location_id'])
        ||($session['station_id']!==null&&(int)$session['station_id']!==(int)$device['station_id']))
        throw new DomainException('Training session is assigned to a different kitchen location or station.');
    if((string)($session['mode']??'')!=='training' && (string)($session['mode']??'')!=='shadow')
        throw new DomainException('Production preflight sessions cannot generate new training labels.');
    $original=glasses_training_session($pdo,(int)$user['organization_id'],(string)$session['public_id']);
    if((string)($original['context']['runtimePublicId']??'')!==$runtimePublic)
        throw new DomainException('Training session belongs to a different employee runtime.');
    return ['runtime'=>$runtime,'device'=>$device,'session'=>$session];
}

function glasses_ingredient_training_menu(PDO $pdo,int $org,int $menuId): array
{
    if($menuId<1)throw new InvalidArgumentException('Choose a configured menu item.');
    $q=$pdo->prepare("SELECT id FROM menu_items WHERE organization_id=? AND id=? AND is_active=1 LIMIT 1");
    $q->execute([$org,$menuId]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Menu item is not active in this organization.');
    $menu=glasses_work_menu_source($pdo,$org,$menuId);
    if(!$menu)return ['id'=>$menuId,'ingredients'=>[],'name'=>'Unavailable'];
    $labels=[];
    foreach((array)($menu['ingredients']??[]) as $index=>$part){
        $label=trim((string)($part['canonicalName']??$part['name']??''));
        if($label==='')continue;
        $labels[]=['key'=>(string)$index,'name'=>(string)($part['name']??$label),
            'canonicalName'=>$label,'category'=>(string)($part['category']??'')];
    }
    return ['id'=>$menuId,'name'=>(string)$menu['name'],'sectionName'=>(string)($menu['sectionName']??''),
        'ingredients'=>$labels];
}

function glasses_ingredient_training_catalog(PDO $pdo,array $user,string $runtimePublic): array
{
    $b=glasses_ingredient_training_binding($pdo,$user,$runtimePublic);
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT id FROM menu_items WHERE organization_id=? AND is_active=1 ORDER BY name,id LIMIT 500");
    $q->execute([$org]);
    $menus=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $menu=glasses_ingredient_training_menu($pdo,$org,(int)$id);
        if($menu['ingredients'])$menus[]=$menu;
    }
    $runtime=$b['runtime'];$session=$b['session'];
    $q=$pdo->prepare("SELECT public_id,title,assigned_user_id,assigned_device_id,location_id,station_id
        FROM glasses_vision_training_missions WHERE organization_id=? AND status='active'
        AND (assigned_user_id IS NULL OR assigned_user_id=?)
        AND (assigned_device_id IS NULL OR assigned_device_id=?)
        AND (location_id IS NULL OR location_id=?)
        AND (station_id IS NULL OR station_id=?)
        ORDER BY id DESC LIMIT 100");
    $q->execute([$org,(int)$runtime['employee_user_id'],(int)$b['device']['id'],
        (int)$runtime['location_id'],(int)$b['device']['station_id']]);
    return [
        'runtimePublicId'=>$runtimePublic,'employeeName'=>(string)$runtime['employee_display_name'],
        'devicePublicId'=>(string)$runtime['device_public_id'],
        'locationId'=>(int)$runtime['location_id'],
        'sessionPublicId'=>(string)$session['public_id'],
        'programName'=>(string)$session['program_name'],
        'menuItems'=>$menus,
        'missions'=>array_map(static fn(array $m):array=>
            ['publicId'=>(string)$m['public_id'],'title'=>(string)$m['title']],$q->fetchAll(PDO::FETCH_ASSOC)),
        'phases'=>GLASSES_INGREDIENT_TRAINING_PHASES
    ];
}

function glasses_ingredient_training_capture(PDO $pdo,array $user,array $input): array
{
    if(($input['consentBasis']??null)!=='training_media_opt_in'
        ||($input['reviewRequired']??null)!==true)
        throw new InvalidArgumentException('Explicit private training consent and human review are required.');
    $runtimePublic=trim((string)($input['runtimePublicId']??''));
    $binding=glasses_ingredient_training_binding($pdo,$user,$runtimePublic);
    $session=$binding['session'];$device=$binding['device'];$runtime=$binding['runtime'];
    if(!hash_equals((string)$session['public_id'],(string)($input['sessionPublicId']??'')))
        throw new DomainException('Training session changed. Reload the capture studio.');
    $org=(int)$user['organization_id'];
    $menu=glasses_ingredient_training_menu($pdo,$org,(int)($input['menuItemId']??0));
    $key=(string)($input['ingredientKey']??'');
    $ingredient=null;
    foreach($menu['ingredients'] as $part){
        if(hash_equals($part['key'],$key)){$ingredient=$part;break;}
    }
    if(!$ingredient)throw new InvalidArgumentException('Ingredient must match the configured menu-item ingredients.');
    $phase=(string)($input['phase']??'');
    if(!in_array($phase,GLASSES_INGREDIENT_TRAINING_PHASES,true))
        throw new InvalidArgumentException('Choose an approved capture phase.');
    $annotations=[];
    if(in_array($phase,GLASSES_INGREDIENT_TRAINING_BOX_PHASES,true)){
        if(!is_array($input['bbox']??null))throw new InvalidArgumentException('Draw a bounding box around the configured ingredient.');
        $annotations=[['label'=>$ingredient['canonicalName'],'bbox'=>$input['bbox']]];
    }elseif(isset($input['bbox'])&&$input['bbox']!==null){
        throw new InvalidArgumentException('Before, empty and negative captures must not contain a positive ingredient bounding box.');
    }
    $mission=null;
    $missionPublic=trim((string)($input['missionPublicId']??''));
    if($missionPublic!==''){
        $q=$pdo->prepare("SELECT * FROM glasses_vision_training_missions
            WHERE organization_id=? AND public_id=? AND status='active' LIMIT 1");
        $q->execute([$org,$missionPublic]);$mission=$q->fetch(PDO::FETCH_ASSOC);
        if(!$mission
            ||($mission['assigned_user_id']!==null&&(int)$mission['assigned_user_id']!==(int)$runtime['employee_user_id'])
            ||($mission['assigned_device_id']!==null&&(int)$mission['assigned_device_id']!==(int)$device['id'])
            ||($mission['location_id']!==null&&(int)$mission['location_id']!==(int)$device['location_id'])
            ||($mission['station_id']!==null&&(int)$mission['station_id']!==(int)$device['station_id']))
            throw new DomainException('Vision Lab training mission is not assigned to this employee and station.');
    }
    $source=(string)($input['source']??'');
    if(!in_array($source,['runtime_camera','file_upload'],true))
        throw new InvalidArgumentException('Select an existing camera capture or uploaded training photo.');
    $slot=(int)($input['cameraSlot']??0);
    if($source==='runtime_camera'){
        if($slot<1||$slot>5)throw new InvalidArgumentException('Select camera slot 1–5.');
        $check=$pdo->prepare("SELECT enabled FROM glasses_camera_locations WHERE organization_id=? AND location_id=? AND slot=? LIMIT 1");
        $check->execute([$org,(int)$device['location_id'],$slot]);
        if(!(bool)$check->fetchColumn())throw new DomainException('Camera slot is not enabled for this location.');
    }elseif($slot!==0){
        throw new InvalidArgumentException('Uploaded training files must not claim a live camera slot.');
    }
    // Any identity, canonical label, capture timestamp and mission are server-derived.
    $fingerprint=is_array($input['fingerprint']??null)?$input['fingerprint']:[];
    $in=[
        'consentBasis'=>'training_media_opt_in',
        'imageBase64'=>(string)($input['imageBase64']??''),
        'devicePublicId'=>(string)$device['public_id'],
        'missionPublicId'=>$mission?(string)$mission['public_id']:null,
        'annotations'=>$annotations,
        // Use the DB clock with microseconds so an assignment started during the
        // same second is still correctly attributed (assigned_at is DATETIME(6)).
        'capturedAt'=>(string)$pdo->query("SELECT DATE_FORMAT(NOW(6),'%Y-%m-%d %H:%i:%s.%f')")->fetchColumn(),
        'retentionDays'=>365,
        'perceptualHash'=>$fingerprint['hash']??null,
        'brightnessMean'=>$fingerprint['brightnessMean']??null,
        'contrastMean'=>$fingerprint['contrastMean']??null,
        'blurScore'=>$fingerprint['blurScore']??null,
        'captureGroup'=>'ingredient:'.$session['public_id'].':'.$menu['id'],
        'distanceBucket'=>in_array(($input['distanceBucket']??null),['close','medium','far'],true)?$input['distanceBucket']:null,
        'occlusionBucket'=>in_array(($input['occlusionBucket']??null),['none','partial','heavy'],true)?$input['occlusionBucket']:null,
        'metadata'=>[
            'schema'=>'gelato.ingredient_training_capture.v1',
            'source'=>'guided_ingredient_training',
            'runtimePublicId'=>$runtimePublic,
            'trainingSessionPublicId'=>$session['public_id'],
            'trainingAssignmentPublicId'=>$session['assignment_public_id'],
            'employeeUserId'=>(int)$runtime['employee_user_id'],
            'devicePublicId'=>$device['public_id'],
            'locationId'=>(int)$device['location_id'],
            'stationId'=>$device['station_id']!==null?(int)$device['station_id']:null,
            'cameraSlot'=>$slot,
            'imageSource'=>$source,
            'menuItemId'=>(int)$menu['id'],
            'ingredientKey'=>$ingredient['key'],
            'ingredientName'=>$ingredient['name'],
            'canonicalIngredient'=>$ingredient['canonicalName'],
            'phase'=>$phase,
            'reviewRequired'=>true,
            'autoTrain'=>false,
        ],
    ];
    $result=glasses_vision_training_media_store($pdo,$org,$in,(int)$user['id']);
    // Media store already created a pending Vision Lab sample; never auto-approve.
    $q=$pdo->prepare("SELECT s.review_status,s.operator_user_id,s.id,s.wearer_assignment_id
        FROM glasses_vision_training_samples s
        WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
    $q->execute([$org,(string)$result['samplePublicId']]);$sample=$q->fetch(PDO::FETCH_ASSOC);
    if(!$sample||$sample['review_status']!=='pending'
        ||(int)$sample['operator_user_id']!==(int)$runtime['employee_user_id']){
        throw new DomainException('Vision Lab sample attribution could not be confirmed. Do not use this sample for training.');
    }
    $pdo->prepare("UPDATE glasses_vision_training_samples
       SET wearer_assignment_id=?,provenance_json=?,updated_at=NOW(6)
       WHERE organization_id=? AND id=? AND review_status='pending'")
      ->execute([(int)$runtime['wearer_assignment_id'],
          json_encode(['schema'=>'gelato.ingredient_training_capture.v1','mediaPublicId'=>$result['publicId'],
            'runtimePublicId'=>$runtimePublic,'trainingSessionPublicId'=>$session['public_id'],
            'menuItemId'=>$menu['id'],'ingredientKey'=>$ingredient['key'],'phase'=>$phase],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          $org,(int)$sample['id']]);
    app_audit($pdo,$org,(int)$user['id'],'glasses.ingredient_training_captured','vision_training_sample',
       (string)$result['samplePublicId'],null,
       ['runtimePublicId'=>$runtimePublic,'trainingSessionPublicId'=>$session['public_id'],
        'menuItemId'=>$menu['id'],'ingredientKey'=>$ingredient['key'],'phase'=>$phase,'status'=>'pending']);
    return ['mediaPublicId'=>$result['publicId'],'samplePublicId'=>$result['samplePublicId'],
        'reviewStatus'=>'pending','qualityState'=>$result['qualityState'],
        'qualityFlags'=>$result['qualityFlags'],'exactDuplicates'=>$result['exactDuplicates'],
        'nearDuplicates'=>$result['nearDuplicates']];
}
