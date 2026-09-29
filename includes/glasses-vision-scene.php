<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-calibration.php';
require_once __DIR__.'/glasses-vision-models.php';
require_once __DIR__.'/glasses-vision-profiles.php';
require_once __DIR__.'/glasses-vision-training-release.php';
require_once __DIR__.'/glasses-vision-lineage.php';
require_once __DIR__.'/glasses-vision-lineage.php';

const GLASSES_VISION_SCENE_SCHEMA='gelato.vision_scene.v1';

function glasses_vision_scene_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_scene_snapshots','glasses_vision_scene_entities'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_build_ready($pdo)&&glasses_station_calibration_ready($pdo)&&glasses_vision_models_ready($pdo)&&glasses_vision_profiles_ready($pdo)&&glasses_vision_lineage_ready($pdo);
}

function glasses_vision_scene_time(string $value): string
{
    $value=trim($value);
    if($value==='')throw new InvalidArgumentException('Scene capture time is required.');
    try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new InvalidArgumentException('Scene capture time is invalid.');}
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function glasses_vision_scene_bbox(mixed $value): ?array
{
    if($value===null)return null;
    if(!is_array($value)||count($value)!==4)throw new InvalidArgumentException('Scene entity bounding box must contain x, y, width and height.');
    $out=array_values(array_map(static fn($v)=>(float)$v,$value));
    foreach($out as $n)if(!is_finite($n)||$n<0.0||$n>1.0)throw new InvalidArgumentException('Scene entity bounding box values must be normalized between 0 and 1.');
    if($out[2]<=0.0||$out[3]<=0.0||$out[0]+$out[2]>1.000001||$out[1]+$out[3]>1.000001)
        throw new InvalidArgumentException('Scene entity bounding box is outside the normalized frame.');
    return array_map(static fn(float $v):float=>round($v,6),$out);
}

function glasses_vision_scene_inside(array $box,array $region): bool
{
    $cx=$box[0]+$box[2]/2;$cy=$box[1]+$box[3]/2;
    return $cx>=(float)$region['x']&&$cy>=(float)$region['y']
      &&$cx<=((float)$region['x']+(float)$region['width'])
      &&$cy<=((float)$region['y']+(float)$region['height']);
}

function glasses_vision_scene_spatial(?array $box,?array $calibration): array
{
    $spatial=['ingredientZones'=>[],'regions'=>[]];
    if($box===null||$calibration===null||empty($calibration['compatibility']['compatible']))return $spatial;
    foreach((array)($calibration['zones']??[]) as $zone){
        if(glasses_vision_scene_inside($box,$zone))$spatial['ingredientZones'][]=[
          'zoneKey'=>$zone['zoneKey'],'ingredientId'=>$zone['ingredientId'],'displayName'=>$zone['displayName'],'priority'=>$zone['priority']
        ];
    }
    foreach((array)($calibration['regions']??[]) as $region){
        if(glasses_vision_scene_inside($box,$region))$spatial['regions'][]=[
          'regionKey'=>$region['regionKey'],'regionType'=>$region['regionType'],'displayName'=>$region['displayName'],'priority'=>$region['priority']
        ];
    }
    usort($spatial['ingredientZones'],static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['zoneKey'],$b['zoneKey']));
    usort($spatial['regions'],static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['regionKey'],$b['regionKey']));
    return $spatial;
}

function glasses_vision_scene_entities_normalize(array $input,array $build,?array $calibration,array $profile): array
{
    if(count($input)>120)throw new InvalidArgumentException('Scene contains too many entities.');
    $allowed=['ingredient','tool','container','hand','product','equipment','surface','unknown'];
    $validComponents=[];foreach((array)$build['components'] as $c)$validComponents[(string)$c['componentKey']]=true;
    $mappingByLabel=[];foreach((array)($profile['mappings']??[]) as $mapping)$mappingByLabel[(string)$mapping['normalizedLabel']]=$mapping;
    $seen=[];$trackingSeen=[];$entities=[];
    foreach(array_values($input) as $i=>$raw){
        if(!is_array($raw))throw new InvalidArgumentException('Scene entity payload is invalid.');
        $key=mb_substr(trim((string)($raw['entityKey']??'')),0,190,'UTF-8');
        if($key==='')throw new InvalidArgumentException('Scene entity key is required.');
        if(isset($seen[$key]))throw new InvalidArgumentException('Scene entity keys must be unique within a frame.');
        $seen[$key]=true;
        $kind=mb_strtolower(trim((string)($raw['kind']??'unknown')),'UTF-8');
        if(!in_array($kind,$allowed,true))throw new InvalidArgumentException('Scene entity kind is invalid.');
        $label=mb_substr(trim((string)($raw['label']??'')),0,180,'UTF-8');
        if($label==='')$label=$kind;
        $normalizedLabel=$kind==='ingredient'?glasses_vision_normalize_label($label):null;
        $requestedComponent=mb_substr(trim((string)($raw['componentKey']??'')),0,160,'UTF-8')?:null;
        if($requestedComponent!==null&&$kind!=='ingredient')
            throw new InvalidArgumentException('Only ingredient scene entities may bind to recipe components.');
        $componentKey=null;
        if($kind==='ingredient'&&$normalizedLabel!==''){
            $mapping=$mappingByLabel[$normalizedLabel]??null;
            if($mapping!==null){
                $componentKey=(string)$mapping['componentKey'];
                if($requestedComponent!==null&&!hash_equals($requestedComponent,$componentKey))
                    throw new InvalidArgumentException('Scene ingredient mapping conflicts with the governed Vision Label Profile.');
            }elseif($requestedComponent!==null){
                throw new InvalidArgumentException('Scene ingredient mapping is not authorized by the governed Vision Label Profile.');
            }
        }
        if($componentKey!==null&&!isset($validComponents[$componentKey]))
            throw new InvalidArgumentException('Scene entity component must belong to the active canonical build.');
        $tracking=mb_substr(trim((string)($raw['trackingId']??'')),0,190,'UTF-8')?:null;
        if($tracking!==null&&isset($trackingSeen[$tracking]))throw new InvalidArgumentException('Scene tracking IDs must be unique within a frame.');
        if($tracking!==null)$trackingSeen[$tracking]=true;
        $confidence=array_key_exists('confidence',$raw)?(float)$raw['confidence']:null;
        if($confidence!==null&&(!is_finite($confidence)||$confidence<0||$confidence>1))
            throw new InvalidArgumentException('Scene entity confidence must be between 0 and 1.');
        $bbox=glasses_vision_scene_bbox($raw['bbox']??null);
        $source=mb_strtolower(trim((string)($raw['source']??'vision_model')),'UTF-8');
        if(!in_array($source,['vision_model','device_runtime','sensor_fusion'],true))
            throw new InvalidArgumentException('Scene entity source is invalid.');
        $attributes=is_array($raw['attributes']??null)?$raw['attributes']:[];
        $attributesJson=glasses_vision_training_release_json($attributes);
        if(strlen($attributesJson)>12000)throw new InvalidArgumentException('Scene entity attributes are too large.');
        $spatial=glasses_vision_scene_spatial($bbox,$calibration);
        $entity=[
          'entityKey'=>$key,'kind'=>$kind,'label'=>$label,'normalizedLabel'=>$normalizedLabel,'componentKey'=>$componentKey,'trackingId'=>$tracking,'source'=>$source,
          'confidence'=>$confidence!==null?round($confidence,6):null,'bbox'=>$bbox,'spatial'=>$spatial,'attributes'=>$attributes
        ];
        $entity['entityHash']=hash('sha256',glasses_vision_training_release_json($entity));
        $entities[]=$entity;
    }
    usort($entities,static fn($a,$b)=>strcmp($a['entityKey'],$b['entityKey']));
    return $entities;
}

function glasses_vision_scene_relationships(array $entities,array $input): array
{
    if(count($input)>300)throw new InvalidArgumentException('Scene contains too many relationships.');
    $keys=[];foreach($entities as $e)$keys[$e['entityKey']]=true;
    $allowed=['near','touching','inside','on','held_by','contains','overlaps','approaching'];
    $out=[];$seen=[];
    foreach(array_values($input) as $raw){
        if(!is_array($raw))throw new InvalidArgumentException('Scene relationship payload is invalid.');
        $from=trim((string)($raw['from']??''));$to=trim((string)($raw['to']??''));$type=strtolower(trim((string)($raw['type']??'')));
        if(!isset($keys[$from])||!isset($keys[$to])||$from===$to)throw new InvalidArgumentException('Scene relationship references an unknown entity.');
        if(!in_array($type,$allowed,true))throw new InvalidArgumentException('Scene relationship type is invalid.');
        $confidence=array_key_exists('confidence',$raw)?(float)$raw['confidence']:null;
        if($confidence!==null&&(!is_finite($confidence)||$confidence<0||$confidence>1))throw new InvalidArgumentException('Scene relationship confidence is invalid.');
        $id=$from.'|'.$type.'|'.$to;
        if(isset($seen[$id]))continue;$seen[$id]=true;
        $out[]=['from'=>$from,'type'=>$type,'to'=>$to,'confidence'=>$confidence!==null?round($confidence,6):null];
    }
    usort($out,static fn($a,$b)=>strcmp($a['from'].'|'.$a['type'].'|'.$a['to'],$b['from'].'|'.$b['type'].'|'.$b['to']));
    return $out;
}

function glasses_vision_scene_capture(PDO $pdo,array $device,array $input): array
{
    if(!glasses_vision_scene_ready($pdo))throw new RuntimeException('Vision Lab V9 live-scene migration is not installed.');
    $org=(int)$device['organization_id'];
    $sessionPublic=trim((string)($input['buildSessionPublicId']??''));
    if($sessionPublic==='')throw new InvalidArgumentException('Scene capture requires a build session.');
    $session=glasses_build_session_row($pdo,$org,$sessionPublic,false);glasses_build_assert_device_session($device,$session);
    if((string)$session['status']!=='active')throw new InvalidArgumentException('Live scene capture requires an active build session.');
    $build=glasses_build_payload($pdo,$org,$sessionPublic);

    $frameKey=mb_substr(trim((string)($input['frameKey']??'')),0,190,'UTF-8');
    if($frameKey==='')throw new InvalidArgumentException('Scene frame key is required.');
    $width=(int)($input['frameWidth']??0);$height=(int)($input['frameHeight']??0);
    if($width<16||$width>8192||$height<16||$height>8192)throw new InvalidArgumentException('Scene frame dimensions are invalid.');
    $pixel=mb_substr(strtolower(trim((string)($input['pixelFormat']??'grayscale8'))),0,40,'UTF-8');
    if($pixel==='')throw new InvalidArgumentException('Scene pixel format is required.');
    $capturedAt=glasses_vision_scene_time((string)($input['capturedAt']??''));
    $capturedDt=new DateTimeImmutable($capturedAt,new DateTimeZone('UTC'));$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $startedDt=new DateTimeImmutable((string)$session['started_at'],new DateTimeZone('UTC'));
    if($capturedDt>$now->modify('+5 seconds'))throw new InvalidArgumentException('Scene capture time cannot be in the future.');
    if($capturedDt<$now->modify('-2 minutes'))throw new InvalidArgumentException('Live scene frame is stale.');
    if($capturedDt<$startedDt->modify('-5 seconds'))throw new InvalidArgumentException('Scene capture predates the active build session.');

    $detector=trim((string)($input['detectorName']??'ingredient_detector'));
    $assignment=glasses_vision_model_assignment($pdo,$device,$sessionPublic,$detector);
    if((string)$assignment['action']!=='apply'||!is_array($assignment['package']??null))
        throw new InvalidArgumentException('Live scene capture requires an applicable model assignment.');
    $packagePublic=(string)$assignment['package']['publicId'];
    $package=glasses_vision_model_package_row($pdo,$org,$packagePublic,false);
    if((string)($package['status']??'')!=='ready')throw new InvalidArgumentException('Live scene capture requires a ready model package.');

    $calibration=glasses_station_calibration_active($pdo,$org,(int)$session['location_id'],(int)$session['station_id'],[
      'platform'=>(string)$device['platform'],'frameWidth'=>$width,'frameHeight'=>$height,'pixelFormat'=>$pixel
    ]);
    $calibrationId=null;
    if($calibration!==null&&empty($calibration['compatibility']['compatible']))$calibration=null;
    if($calibration!==null){
        $q=$pdo->prepare("SELECT id FROM glasses_station_calibrations WHERE organization_id=? AND public_id=? LIMIT 1");
        $q->execute([$org,$calibration['publicId']]);$calibrationId=(int)$q->fetchColumn();
    }

    $profile=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,$detector);
    $entities=glasses_vision_scene_entities_normalize(is_array($input['entities']??null)?$input['entities']:[],$build,$calibration,$profile);
    $relationships=glasses_vision_scene_relationships($entities,is_array($input['relationships']??null)?$input['relationships']:[]);

    $counts=[];foreach($entities as $e)$counts[$e['kind']]=($counts[$e['kind']]??0)+1;ksort($counts);
    $mappedIngredients=0;foreach($entities as $e)if($e['kind']==='ingredient'&&$e['componentKey']!==null)$mappedIngredients++;
    $sceneState=$entities?'observed':'empty';
    $summary=[
      'entityCount'=>count($entities),'entityKinds'=>$counts,'relationshipCount'=>count($relationships),
      'mappedIngredientCount'=>$mappedIngredients,'currentExpectedComponentKey'=>$build['summary']['currentComponentKey']??null,
      'buildAccounted'=>(bool)($build['summary']['accounted']??false),
    ];

    $canonicalBuildContext=[
      'publicId'=>$build['publicId'],'status'=>$build['status'],'kdsItemPublicId'=>$build['kdsItemPublicId'],'kdsStatus'=>$build['kdsStatus'],
      'locationId'=>$build['locationId'],'stationPublicId'=>$build['stationPublicId'],'menuItemId'=>$build['menuItemId'],
      'posCheckItemId'=>$build['posCheckItemId'],'buildDefinition'=>$build['buildDefinition'],'summary'=>$build['summary'],'components'=>$build['components'],
      'orderContext'=>$build['context']
    ];
    $buildContextHash=hash('sha256',glasses_vision_training_release_json($canonicalBuildContext));
    $context=[
      'buildContextHash'=>$buildContextHash,
      'buildSession'=>[
        'publicId'=>$build['publicId'],'status'=>$build['status'],'kdsItemPublicId'=>$build['kdsItemPublicId'],'kdsStatus'=>$build['kdsStatus'],
        'locationId'=>$build['locationId'],'stationPublicId'=>$build['stationPublicId'],'menuItemId'=>$build['menuItemId'],
        'posCheckItemId'=>$build['posCheckItemId'],'buildDefinition'=>$build['buildDefinition'],
        'summary'=>$build['summary'],'components'=>$build['components']
      ],
      'orderContext'=>$build['context'],
      'modelAssignment'=>[
        'assignmentKey'=>$assignment['assignmentKey'],'detectorName'=>$assignment['detectorName'],'selection'=>$assignment['selection'],
        'rollout'=>$assignment['rollout'],'packagePublicId'=>$package['public_id'],'artifactSha256'=>$package['artifact_sha256'],
        'profileHash'=>$profile['profileHash']
      ],
      'calibration'=>$calibration?[
        'publicId'=>$calibration['publicId'],'sourceHash'=>$calibration['sourceHash'],'version'=>$calibration['version'],
        'zones'=>$calibration['zones'],'regions'=>$calibration['regions']
      ]:null,
      'recipePlan'=>[
        'currentExpectedComponentKey'=>$build['summary']['currentComponentKey']??null,
        'components'=>array_map(static fn($c)=>[
          'componentKey'=>$c['componentKey'],'displayName'=>$c['displayName'],'status'=>$c['status'],'sortOrder'=>$c['sortOrder'],
          'expectedQuantity'=>$c['expectedQuantity'],'detectedQuantity'=>$c['detectedQuantity'],'unit'=>$c['unit'],'optional'=>$c['optional']
        ],$build['components']),
        'declaredSteps'=>$build['context']['buildDefinition']['steps']??[],
        'recognizedStep'=>null,
        'recognizedStepReason'=>'reserved_for_v9_section_2'
      ],
    ];

    $source=[
      'schema'=>GLASSES_VISION_SCENE_SCHEMA,'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,
      'frame'=>['frameKey'=>$frameKey,'width'=>$width,'height'=>$height,'pixelFormat'=>$pixel,'capturedAt'=>$capturedAt],
      'assignmentKey'=>$assignment['assignmentKey'],'modelArtifactSha256'=>$package['artifact_sha256'],
      'buildContextHash'=>$buildContextHash,'visionProfileHash'=>$profile['profileHash'],'calibrationSourceHash'=>$calibration['sourceHash']??null,
      'entityHashes'=>array_map(static fn($e)=>[$e['entityKey'],$e['entityHash']],$entities),
      'relationships'=>$relationships,
    ];
    $sourceFingerprint=hash('sha256',glasses_vision_training_release_json($source));
    $material=['sourceFingerprint'=>$sourceFingerprint,'sceneState'=>$sceneState,'context'=>$context,'relationships'=>$relationships,'summary'=>$summary];
    $sceneHash=hash('sha256',glasses_vision_training_release_json($material));

    return glasses_transaction($pdo,function()use($pdo,$org,$device,$session,$package,$calibrationId,$frameKey,$width,$height,$pixel,$capturedAt,$assignment,$sourceFingerprint,$sceneState,$context,$relationships,$summary,$sceneHash,$entities):array{
        $q=$pdo->prepare("SELECT public_id,source_fingerprint,scene_hash FROM glasses_vision_scene_snapshots
          WHERE organization_id=? AND device_id=? AND build_session_id=? AND frame_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$device['id'],(int)$session['id'],$frameKey]);$existing=$q->fetch();
        if($existing){
            if(!hash_equals((string)$existing['source_fingerprint'],$sourceFingerprint)||!hash_equals((string)$existing['scene_hash'],$sceneHash))
                throw new InvalidArgumentException('Scene frame key was already used with different immutable evidence.');
            return glasses_vision_scene_row($pdo,$org,(string)$existing['public_id']);
        }
        $public=glasses_public_id('vision-scene');
        $pdo->prepare("INSERT INTO glasses_vision_scene_snapshots
          (organization_id,public_id,device_id,build_session_id,package_id,calibration_id,frame_key,frame_width,frame_height,pixel_format,captured_at,
           assignment_key,source_fingerprint,scene_state,context_json,relationships_json,summary_json,scene_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$device['id'],(int)$session['id'],(int)$package['id'],$calibrationId,$frameKey,$width,$height,$pixel,$capturedAt,
            $assignment['assignmentKey'],$sourceFingerprint,$sceneState,glasses_vision_training_release_json($context),
            glasses_vision_training_release_json($relationships),glasses_vision_training_release_json($summary),$sceneHash]);
        $sceneId=(int)$pdo->lastInsertId();
        $insert=$pdo->prepare("INSERT INTO glasses_vision_scene_entities
          (organization_id,scene_snapshot_id,public_id,entity_key,entity_kind,label,normalized_label,component_key,tracking_id,source_type,confidence,bbox_json,spatial_json,attributes_json,entity_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($entities as $e)$insert->execute([
          $org,$sceneId,glasses_public_id('vision-entity'),$e['entityKey'],$e['kind'],$e['label'],$e['normalizedLabel'],$e['componentKey'],$e['trackingId'],$e['source'],$e['confidence'],
          $e['bbox']!==null?glasses_vision_training_release_json($e['bbox']):null,glasses_vision_training_release_json($e['spatial']),
          glasses_vision_training_release_json($e['attributes']),$e['entityHash']
        ]);
        glasses_vision_lineage_edge($pdo,$org,'model_package',(string)$package['public_id'],(string)$package['artifact_sha256'],'observed_scene_as','vision_scene',$public,$sceneHash,
          ['buildSessionPublicId'=>(string)$session['public_id'],'frameKey'=>$frameKey,'sourceFingerprint'=>$sourceFingerprint],null);
        return glasses_vision_scene_row($pdo,$org,$public);
    });
}

function glasses_vision_scene_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT s.*,d.public_id device_public_id,b.public_id build_public_id,p.public_id package_public_id,p.artifact_sha256,
      c.public_id calibration_public_id,c.source_hash calibration_source_hash
      FROM glasses_vision_scene_snapshots s
      JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
      JOIN glasses_build_sessions b ON b.id=s.build_session_id AND b.organization_id=s.organization_id
      JOIN glasses_vision_model_packages p ON p.id=s.package_id AND p.organization_id=s.organization_id
      LEFT JOIN glasses_station_calibrations c ON c.id=s.calibration_id AND c.organization_id=s.organization_id
      WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Vision scene was not found.');
    $eq=$pdo->prepare("SELECT * FROM glasses_vision_scene_entities WHERE organization_id=? AND scene_snapshot_id=? ORDER BY entity_key");
    $eq->execute([$org,(int)$r['id']]);$entities=[];
    foreach($eq->fetchAll() as $e)$entities[]=[
      'publicId'=>$e['public_id'],'entityKey'=>$e['entity_key'],'kind'=>$e['entity_kind'],'label'=>$e['label'],'normalizedLabel'=>$e['normalized_label'],'componentKey'=>$e['component_key'],
      'trackingId'=>$e['tracking_id'],'source'=>$e['source_type'],'confidence'=>$e['confidence']!==null?(float)$e['confidence']:null,
      'bbox'=>$e['bbox_json']!==null?json_decode((string)$e['bbox_json'],true):null,'spatial'=>json_decode((string)$e['spatial_json'],true)?:[],
      'attributes'=>json_decode((string)$e['attributes_json'],true)?:[],'entityHash'=>$e['entity_hash']
    ];
    return [
      'schema'=>GLASSES_VISION_SCENE_SCHEMA,'publicId'=>$r['public_id'],'devicePublicId'=>$r['device_public_id'],'buildSessionPublicId'=>$r['build_public_id'],
      'modelPackagePublicId'=>$r['package_public_id'],'modelArtifactSha256'=>$r['artifact_sha256'],'calibrationPublicId'=>$r['calibration_public_id'],
      'calibrationSourceHash'=>$r['calibration_source_hash'],'frameKey'=>$r['frame_key'],'frameWidth'=>(int)$r['frame_width'],'frameHeight'=>(int)$r['frame_height'],
      'pixelFormat'=>$r['pixel_format'],'capturedAt'=>$r['captured_at'],'assignmentKey'=>$r['assignment_key'],'sourceFingerprint'=>$r['source_fingerprint'],
      'sceneState'=>$r['scene_state'],'context'=>json_decode((string)$r['context_json'],true)?:[],'relationships'=>json_decode((string)$r['relationships_json'],true)?:[],
      'summary'=>json_decode((string)$r['summary_json'],true)?:[],'entities'=>$entities,'sceneHash'=>$r['scene_hash'],'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_scene_verify(PDO $pdo,int $org,string $publicId): array
{
    $s=glasses_vision_scene_row($pdo,$org,$publicId);
    $entityHashes=array_map(static fn($e)=>[$e['entityKey'],$e['entityHash']],$s['entities']);
    $source=[
      'schema'=>GLASSES_VISION_SCENE_SCHEMA,'devicePublicId'=>$s['devicePublicId'],'buildSessionPublicId'=>$s['buildSessionPublicId'],
      'frame'=>['frameKey'=>$s['frameKey'],'width'=>$s['frameWidth'],'height'=>$s['frameHeight'],'pixelFormat'=>$s['pixelFormat'],'capturedAt'=>$s['capturedAt']],
      'assignmentKey'=>$s['assignmentKey'],'modelArtifactSha256'=>$s['modelArtifactSha256'],
      'buildContextHash'=>$s['context']['buildContextHash']??null,'visionProfileHash'=>$s['context']['modelAssignment']['profileHash']??null,'calibrationSourceHash'=>$s['calibrationSourceHash'],
      'entityHashes'=>$entityHashes,'relationships'=>$s['relationships']
    ];
    $sourceFingerprint=hash('sha256',glasses_vision_training_release_json($source));
    $material=['sourceFingerprint'=>$sourceFingerprint,'sceneState'=>$s['sceneState'],'context'=>$s['context'],'relationships'=>$s['relationships'],'summary'=>$s['summary']];
    $sceneHash=hash('sha256',glasses_vision_training_release_json($material));
    $aq=$pdo->prepare("SELECT a.package_id,p.public_id,p.artifact_sha256
      FROM glasses_vision_model_assignments a
      JOIN glasses_vision_model_packages p ON p.id=a.package_id AND p.organization_id=a.organization_id
      JOIN glasses_build_sessions b ON b.id=a.build_session_id AND b.organization_id=a.organization_id
      JOIN glasses_devices d ON d.id=a.device_id AND d.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.assignment_key=? AND b.public_id=? AND d.public_id=? LIMIT 1");
    $aq->execute([$org,$s['assignmentKey'],$s['buildSessionPublicId'],$s['devicePublicId']]);$assignment=$aq->fetch();
    $assignmentValid=$assignment
      &&hash_equals((string)$assignment['public_id'],(string)$s['modelPackagePublicId'])
      &&hash_equals((string)$assignment['artifact_sha256'],(string)$s['modelArtifactSha256']);
    $entitiesValid=true;
    foreach($s['entities'] as $e){
        $material=[
          'entityKey'=>$e['entityKey'],'kind'=>$e['kind'],'label'=>$e['label'],'normalizedLabel'=>$e['normalizedLabel'],'componentKey'=>$e['componentKey'],'trackingId'=>$e['trackingId'],'source'=>$e['source'],
          'confidence'=>$e['confidence'],'bbox'=>$e['bbox'],'spatial'=>$e['spatial'],'attributes'=>$e['attributes']
        ];
        if(!hash_equals($e['entityHash'],hash('sha256',glasses_vision_training_release_json($material)))){$entitiesValid=false;break;}
    }
    return [
      'passed'=>$assignmentValid&&$entitiesValid&&hash_equals($s['sourceFingerprint'],$sourceFingerprint)&&hash_equals($s['sceneHash'],$sceneHash),
      'assignmentValid'=>(bool)$assignmentValid,'entitiesValid'=>$entitiesValid,'sourceFingerprint'=>$s['sourceFingerprint'],'recomputedSourceFingerprint'=>$sourceFingerprint,
      'sceneHash'=>$s['sceneHash'],'recomputedSceneHash'=>$sceneHash
    ];
}

function glasses_vision_scene_recent(PDO $pdo,int $org,int $limit=50): array
{
    if(!glasses_vision_scene_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_SCENE_SCHEMA,'scenes'=>[]];
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_scene_snapshots WHERE organization_id=? ORDER BY captured_at DESC,id DESC LIMIT {$limit}");
    $q->execute([$org]);$scenes=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public)$scenes[]=glasses_vision_scene_row($pdo,$org,(string)$public);
    return ['ready'=>true,'schema'=>GLASSES_VISION_SCENE_SCHEMA,'scenes'=>$scenes];
}
