<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-ingredient-prevention.php';

const GLASSES_VISION_QUANTITY_SCHEMA='gelato.vision_quantity_verification.v1';
const GLASSES_VISION_QUANTITY_POLICY='gelato.vision_quantity_policy.v1';

function glasses_vision_quantity_policy(): array
{
    return [
      'schema'=>GLASSES_VISION_QUANTITY_POLICY,
      'minimumConfidence'=>0.75,
      'relativeTolerance'=>0.15,
      'absoluteTolerance'=>0.10,
      'conflictRelativeDelta'=>0.20,
      'supportedMethods'=>['vision_estimate','count','scale_fusion','sensor_fusion'],
    ];
}

function glasses_vision_quantity_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_quantity_verifications'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_ingredient_guard_ready($pdo);
}

function glasses_vision_quantity_unit(string $unit): string
{
    $u=mb_strtolower(trim($unit),'UTF-8');
    $map=['portions'=>'portion','portion'=>'portion','pieces'=>'piece','piece'=>'piece','pcs'=>'piece','pc'=>'piece','count'=>'piece',
          'ounces'=>'oz','ounce'=>'oz','oz'=>'oz','grams'=>'g','gram'=>'g','g'=>'g','milliliters'=>'ml','milliliter'=>'ml','ml'=>'ml'];
    return $map[$u]??$u;
}

function glasses_vision_quantity_measurements(array $scene,string $componentKey): array
{
    $policy=glasses_vision_quantity_policy();$out=[];
    foreach((array)($scene['entities']??[]) as $entity){
        if((string)($entity['kind']??'')!=='ingredient'||(string)($entity['componentKey']??'')!==$componentKey)continue;
        $m=(array)(($entity['attributes']['quantityEstimate']??null)?:[]);
        if(!$m)continue;
        $value=(float)($m['value']??0);$confidence=(float)($m['confidence']??0);
        $unit=glasses_vision_quantity_unit((string)($m['unit']??''));
        $method=mb_strtolower(trim((string)($m['method']??'vision_estimate')),'UTF-8');
        if(!is_finite($value)||$value<0||$value>100000)continue;
        if(!is_finite($confidence)||$confidence<0||$confidence>1||$confidence<(float)$policy['minimumConfidence'])continue;
        if($unit===''||!in_array($method,$policy['supportedMethods'],true))continue;
        $out[]=[
          'entityKey'=>(string)$entity['entityKey'],'trackingId'=>$entity['trackingId']??null,
          'value'=>round($value,4),'unit'=>$unit,'confidence'=>round($confidence,6),'method'=>$method,
          'entityHash'=>(string)$entity['entityHash'],
        ];
    }
    return $out;
}

function glasses_vision_quantity_assess_scene(array $scene): array
{
    $policy=glasses_vision_quantity_policy();
    $targets=glasses_vision_ingredient_guard_targets($scene);
    $currentKey=(string)($scene['context']['recipePlan']['currentExpectedComponentKey']??'');
    $components=[];foreach((array)($scene['context']['recipePlan']['components']??[]) as $c)$components[(string)($c['componentKey']??'')]=$c;
    $component=$currentKey!==''?($components[$currentKey]??null):null;

    if(!$targets)return [
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>'insufficient','componentKey'=>$currentKey?:null,
      'expectedQuantity'=>$component!==null?(float)($component['expectedQuantity']??0):null,'observedQuantity'=>null,
      'unit'=>$component!==null?glasses_vision_quantity_unit((string)($component['unit']??'')):null,'confidence'=>0.0,
      'deviation'=>null,'tolerance'=>null,'measurements'=>[],'reason'=>'product_not_visible',
      'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
    ];
    if($component===null)return [
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>'insufficient','componentKey'=>null,
      'expectedQuantity'=>null,'observedQuantity'=>null,'unit'=>null,'confidence'=>0.0,'deviation'=>null,'tolerance'=>null,
      'measurements'=>[],'reason'=>'no_current_component','advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,
      'changesPosState'=>false,'changesDetectedQuantity'=>false,
    ];

    $expected=(float)($component['expectedQuantity']??0);
    $expectedUnit=glasses_vision_quantity_unit((string)($component['unit']??''));
    if($expected<=0||$expectedUnit==='')return [
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>'insufficient','componentKey'=>$currentKey,
      'expectedQuantity'=>$expected,'observedQuantity'=>null,'unit'=>$expectedUnit?:null,'confidence'=>0.0,'deviation'=>null,
      'tolerance'=>null,'measurements'=>[],'reason'=>'canonical_quantity_unavailable','advisoryOnly'=>true,
      'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
    ];

    $measurements=glasses_vision_quantity_measurements($scene,$currentKey);
    $compatible=array_values(array_filter($measurements,static fn($m)=>$m['unit']===$expectedUnit));
    if(!$compatible)return [
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>'insufficient','componentKey'=>$currentKey,
      'expectedQuantity'=>$expected,'observedQuantity'=>null,'unit'=>$expectedUnit,'confidence'=>0.0,'deviation'=>null,
      'tolerance'=>max((float)$policy['absoluteTolerance'],$expected*(float)$policy['relativeTolerance']),
      'measurements'=>$measurements,'reason'=>$measurements?'unit_mismatch':'no_quantity_evidence',
      'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
    ];

    usort($compatible,static fn($a,$b)=>$b['confidence']<=>$a['confidence']);
    $best=$compatible[0];
    if(count($compatible)>1){
        foreach(array_slice($compatible,1) as $other){
            $den=max(0.0001,max((float)$best['value'],(float)$other['value']));
            if(abs((float)$best['value']-(float)$other['value'])/$den>(float)$policy['conflictRelativeDelta']){
                return [
                  'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>'ambiguous','componentKey'=>$currentKey,
                  'expectedQuantity'=>$expected,'observedQuantity'=>null,'unit'=>$expectedUnit,'confidence'=>(float)$best['confidence'],
                  'deviation'=>null,'tolerance'=>max((float)$policy['absoluteTolerance'],$expected*(float)$policy['relativeTolerance']),
                  'measurements'=>$compatible,'reason'=>'conflicting_quantity_evidence','advisoryOnly'=>true,
                  'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
                ];
            }
        }
    }

    $observed=(float)$best['value'];$deviation=$observed-$expected;
    $tolerance=max((float)$policy['absoluteTolerance'],$expected*(float)$policy['relativeTolerance']);
    if(abs($deviation)<=$tolerance)$state='within_tolerance';
    elseif($deviation<0)$state='under_portioned';
    else $state='over_portioned';

    return [
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$policy,'state'=>$state,'componentKey'=>$currentKey,
      'expectedQuantity'=>round($expected,4),'observedQuantity'=>round($observed,4),'unit'=>$expectedUnit,
      'confidence'=>(float)$best['confidence'],'deviation'=>round($deviation,4),'tolerance'=>round($tolerance,4),
      'measurements'=>$compatible,'reason'=>$state,'advisoryOnly'=>true,'changesBuildState'=>false,
      'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
    ];
}

function glasses_vision_quantity_verify_scene(PDO $pdo,int $org,string $scenePublicId): array
{
    if(!glasses_vision_quantity_ready($pdo))throw new RuntimeException('Vision Lab V9 quantity-verification migration is not installed.');
    $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
    if(!glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed'])
        throw new InvalidArgumentException('Quantity verification requires an intact V9 scene.');
    $build=glasses_build_payload($pdo,$org,(string)$scene['buildSessionPublicId']);
    if((string)$build['status']!=='active')throw new InvalidArgumentException('Quantity verification requires an active build session.');
    $sceneDefinition=(string)($scene['context']['buildSession']['buildDefinition']['publicId']??'');
    $liveDefinition=(string)($build['buildDefinition']['publicId']??'');
    if($sceneDefinition===''||$liveDefinition===''||!hash_equals($sceneDefinition,$liveDefinition))
        throw new InvalidArgumentException('Quantity verification requires the same canonical build definition captured by the scene.');
    $sceneHash=(string)($scene['context']['buildContextHash']??'');$liveHash=glasses_vision_step_build_context_hash($build);
    if($sceneHash===''||!hash_equals($sceneHash,$liveHash))
        throw new InvalidArgumentException('Quantity verification requires a scene captured from the current canonical build state.');

    $assessment=glasses_vision_quantity_assess_scene($scene);
    $evidence=[
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$assessment['policy'],
      'scene'=>['publicId'=>$scene['publicId'],'sceneHash'=>$scene['sceneHash'],'sourceFingerprint'=>$scene['sourceFingerprint']],
      'buildSessionPublicId'=>$scene['buildSessionPublicId'],'buildContextHash'=>$sceneHash,'liveBuildContextHash'=>$liveHash,
      'buildDefinition'=>$scene['context']['buildSession']['buildDefinition']??null,'recipePlan'=>$scene['context']['recipePlan']??null,
      'measurementEntityHashes'=>array_map(static fn($m)=>[$m['entityKey'],$m['entityHash']],(array)$assessment['measurements']),
    ];
    $result=$assessment;unset($result['policy'],$result['schema']);
    $material=['evidence'=>$evidence,'result'=>$result];
    $verificationHash=hash('sha256',glasses_vision_training_release_json($material));
    $verificationKey=hash('sha256',glasses_vision_training_release_json([
      'schema'=>GLASSES_VISION_QUANTITY_SCHEMA,'policy'=>$assessment['policy'],'sceneHash'=>$scene['sceneHash']
    ]));

    return glasses_transaction($pdo,function()use($pdo,$org,$scene,$assessment,$evidence,$result,$verificationHash,$verificationKey):array{
        $q=$pdo->prepare("SELECT public_id,verification_hash FROM glasses_vision_quantity_verifications WHERE organization_id=? AND verification_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$verificationKey]);$existing=$q->fetch();
        if($existing){
            if(!hash_equals((string)$existing['verification_hash'],$verificationHash))
                throw new InvalidArgumentException('Quantity-verification identity conflicts with different immutable evidence.');
            return glasses_vision_quantity_row($pdo,$org,(string)$existing['public_id']);
        }
        $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=? LIMIT 1");
        $sq->execute([$org,$scene['publicId']]);$sceneId=(int)$sq->fetchColumn();
        $bq=$pdo->prepare("SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=? LIMIT 1");
        $bq->execute([$org,$scene['buildSessionPublicId']]);$buildId=(int)$bq->fetchColumn();
        $public=glasses_public_id('vision-quantity');
        $pdo->prepare("INSERT INTO glasses_vision_quantity_verifications
          (organization_id,public_id,scene_snapshot_id,build_session_id,verification_key,state,component_key,expected_quantity,observed_quantity,unit,confidence,evidence_json,result_json,verification_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$sceneId,$buildId,$verificationKey,$assessment['state'],$assessment['componentKey'],
            $assessment['expectedQuantity'],$assessment['observedQuantity'],$assessment['unit'],$assessment['confidence'],
            glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($result),$verificationHash]);
        glasses_vision_lineage_edge($pdo,$org,'vision_scene',$scene['publicId'],$scene['sceneHash'],'verified_quantity_as',
          'quantity_verification',$public,$verificationHash,
          ['state'=>$assessment['state'],'componentKey'=>$assessment['componentKey'],'expectedQuantity'=>$assessment['expectedQuantity'],
           'observedQuantity'=>$assessment['observedQuantity'],'unit'=>$assessment['unit']],null);
        return glasses_vision_quantity_row($pdo,$org,$public);
    });
}

function glasses_vision_quantity_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT v.*,s.public_id scene_public_id,s.scene_hash,b.public_id build_public_id
      FROM glasses_vision_quantity_verifications v
      JOIN glasses_vision_scene_snapshots s ON s.id=v.scene_snapshot_id AND s.organization_id=v.organization_id
      JOIN glasses_build_sessions b ON b.id=v.build_session_id AND b.organization_id=v.organization_id
      WHERE v.organization_id=? AND v.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Quantity verification was not found.');
    return [
      'publicId'=>(string)$r['public_id'],'scenePublicId'=>(string)$r['scene_public_id'],'sceneHash'=>(string)$r['scene_hash'],
      'buildSessionPublicId'=>(string)$r['build_public_id'],'state'=>(string)$r['state'],'componentKey'=>$r['component_key'],
      'expectedQuantity'=>$r['expected_quantity']!==null?(float)$r['expected_quantity']:null,
      'observedQuantity'=>$r['observed_quantity']!==null?(float)$r['observed_quantity']:null,'unit'=>$r['unit'],
      'confidence'=>(float)$r['confidence'],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'result'=>json_decode((string)$r['result_json'],true)?:[],'verificationHash'=>(string)$r['verification_hash'],
      'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false,'changesDetectedQuantity'=>false,
      'createdAt'=>(string)$r['created_at'],
    ];
}

function glasses_vision_quantity_verify(PDO $pdo,int $org,string $publicId): array
{
    $row=glasses_vision_quantity_row($pdo,$org,$publicId);
    $calculated=hash('sha256',glasses_vision_training_release_json(['evidence'=>$row['evidence'],'result'=>$row['result']]));
    return ['passed'=>hash_equals($row['verificationHash'],$calculated),'publicId'=>$row['publicId'],
      'verificationHash'=>$row['verificationHash'],'calculatedHash'=>$calculated];
}

function glasses_vision_quantity_recent(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_quantity_verifications WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];foreach($q->fetchAll() as $r)$out[]=glasses_vision_quantity_row($pdo,$org,(string)$r['public_id']);return $out;
}
