<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-vision-lineage.php';

const GLASSES_VISION_PRODUCTION_ERROR_SCHEMA='gelato.vision_production_error.v1';

function glasses_vision_feedback_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_production_errors'");
    $q->execute();
    return (int)$q->fetchColumn()===1&&glasses_vision_lineage_ready($pdo);
}

function glasses_vision_feedback_error_type(string $value): string
{
    $value=strtolower(trim($value));
    $allowed=['false_positive','false_negative','misclassification','low_confidence','correction','validation_disagreement','unexpected_detection'];
    if(!in_array($value,$allowed,true))throw new InvalidArgumentException('Production vision error type is invalid.');
    return $value;
}

function glasses_vision_feedback_outcome(string $value): string
{
    $value=strtolower(trim($value));
    $allowed=['rejected','reclassified','confirmed','missed','needs_review','corrected'];
    if(!in_array($value,$allowed,true))throw new InvalidArgumentException('Production vision outcome is invalid.');
    return $value;
}

function glasses_vision_feedback_canonical(array $value): string
{
    return glasses_vision_training_release_json($value);
}

function glasses_vision_feedback_context(PDO $pdo,int $org,string $sessionPublic,?string $observationKey=null): array
{
    $q=$pdo->prepare("SELECT s.*,d.public_id device_public_id,k.location_id,k.station_id,ks.public_id station_public_id,
      mi.name menu_item_name
      FROM glasses_build_sessions s
      JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
      JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
      LEFT JOIN kds_stations ks ON ks.id=k.station_id AND ks.organization_id=k.organization_id
      JOIN menu_items mi ON mi.id=s.menu_item_id
      WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
    $q->execute([$org,$sessionPublic]);$session=$q->fetch();
    if(!$session)throw new InvalidArgumentException('Build session was not found.');

    $observation=null;
    if($observationKey!==null&&trim($observationKey)!==''){
        $oq=$pdo->prepare("SELECT * FROM glasses_build_observations WHERE organization_id=? AND build_session_id=? AND observation_key=? LIMIT 1");
        $oq->execute([$org,(int)$session['id'],trim($observationKey)]);$observation=$oq->fetch();
        if(!$observation)throw new InvalidArgumentException('Production observation was not found.');
    }

    $assignment=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,r.public_id rollout_public_id
      FROM glasses_vision_model_assignments a
      LEFT JOIN glasses_vision_model_packages p ON p.id=a.package_id AND p.organization_id=a.organization_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=a.rollout_id AND r.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.device_id=? AND a.issued_at<=?
      ORDER BY a.issued_at DESC,a.id DESC LIMIT 1");
    $occurred=$observation['created_at']??$session['updated_at']??$session['started_at'];
    $assignment->execute([$org,(int)$session['device_id'],$occurred]);$model=$assignment->fetch()?:null;

    return ['session'=>$session,'observation'=>$observation,'assignment'=>$model,'occurredAt'=>$occurred];
}

function glasses_vision_feedback_public(array $r): array
{
    return [
      'schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA,
      'publicId'=>$r['public_id'],'eventKey'=>$r['event_key'],'eventHash'=>$r['event_hash'],
      'sourceType'=>$r['source_type'],'errorType'=>$r['error_type'],'outcome'=>$r['outcome'],
      'buildSessionPublicId'=>$r['build_session_public_id']??null,
      'observationKey'=>$r['observation_key']??null,'correctionKey'=>$r['correction_key']??null,
      'devicePublicId'=>$r['device_public_id']??null,'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,
      'stationPublicId'=>$r['station_public_id']??null,'menuItemId'=>$r['menu_item_id']!==null?(int)$r['menu_item_id']:null,
      'modelPackagePublicId'=>$r['model_package_public_id']??null,'rolloutPublicId'=>$r['rollout_public_id']??null,
      'predictedComponentKey'=>$r['predicted_component_key'],'expectedComponentKey'=>$r['expected_component_key'],
      'confidence'=>$r['confidence']!==null?(float)$r['confidence']:null,
      'context'=>json_decode((string)$r['context_json'],true)?:[],
      'occurredAt'=>$r['occurred_at'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_feedback_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT e.*,s.public_id build_session_public_id,o.observation_key,c.correction_key,d.public_id device_public_id,
      ks.public_id station_public_id,p.public_id model_package_public_id,r.public_id rollout_public_id
      FROM glasses_vision_production_errors e
      LEFT JOIN glasses_build_sessions s ON s.id=e.build_session_id
      LEFT JOIN glasses_build_observations o ON o.id=e.observation_id
      LEFT JOIN glasses_observation_corrections c ON c.id=e.correction_id
      LEFT JOIN glasses_devices d ON d.id=e.device_id
      LEFT JOIN kds_stations ks ON ks.id=e.station_id
      LEFT JOIN glasses_vision_model_packages p ON p.id=e.model_package_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=e.rollout_id
      WHERE e.organization_id=? AND e.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Production vision error was not found.');
    return $row;
}

function glasses_vision_feedback_record(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_feedback_ready($pdo))throw new RuntimeException('Vision Lab V7 production-error migration is not installed.');
    $eventKey=mb_substr(trim((string)($input['eventKey']??'')),0,190,'UTF-8');
    if($eventKey==='')throw new InvalidArgumentException('Production error event key is required.');
    $source=mb_substr(strtolower(trim((string)($input['sourceType']??'manual'))),0,40,'UTF-8');
    if(!preg_match('/^[a-z0-9_.-]{2,40}$/',$source))throw new InvalidArgumentException('Production error source type is invalid.');
    $errorType=glasses_vision_feedback_error_type((string)($input['errorType']??''));
    $outcome=glasses_vision_feedback_outcome((string)($input['outcome']??''));
    $sessionPublic=trim((string)($input['buildSessionPublicId']??''));
    if($sessionPublic==='')throw new InvalidArgumentException('Build session is required.');
    $observationKey=trim((string)($input['observationKey']??''));
    $ctx=glasses_vision_feedback_context($pdo,$org,$sessionPublic,$observationKey!==''?$observationKey:null);
    $s=$ctx['session'];$o=$ctx['observation'];$a=$ctx['assignment'];

    $predicted=mb_substr(trim((string)($input['predictedComponentKey']??($o['component_key']??''))),0,160,'UTF-8')?:null;
    $expected=mb_substr(trim((string)($input['expectedComponentKey']??'')),0,160,'UTF-8')?:null;
    $confidence=array_key_exists('confidence',$input)?max(0,min(1,(float)$input['confidence'])):($o&&$o['confidence']!==null?(float)$o['confidence']:null);
    $correctionId=isset($input['correctionId'])?(int)$input['correctionId']:null;
    $extra=is_array($input['context']??null)?$input['context']:[];

    $snapshot=[
      'schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA,
      'eventKey'=>$eventKey,'sourceType'=>$source,'errorType'=>$errorType,'outcome'=>$outcome,
      'buildSessionPublicId'=>$s['public_id'],'observationKey'=>$o['observation_key']??null,
      'devicePublicId'=>$s['device_public_id'],'locationId'=>(int)$s['location_id'],'stationPublicId'=>$s['station_public_id'],
      'menuItemId'=>(int)$s['menu_item_id'],'menuItemName'=>$s['menu_item_name'],
      'modelPackagePublicId'=>$a['package_public_id']??null,'modelArtifactSha256'=>$a['package_sha256']??null,
      'rolloutPublicId'=>$a['rollout_public_id']??null,'assignmentKey'=>$a['assignment_key']??null,
      'predictedComponentKey'=>$predicted,'expectedComponentKey'=>$expected,'confidence'=>$confidence,
      'observationAction'=>$o['action']??null,'observationQuantity'=>$o!==null?(float)$o['quantity']:null,
      'context'=>$extra,'occurredAt'=>$ctx['occurredAt'],
    ];
    $hash=hash('sha256',glasses_vision_feedback_canonical($snapshot));

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$eventKey,$hash,$source,$errorType,$outcome,$s,$o,$a,$predicted,$expected,$confidence,$correctionId,$snapshot,$ctx):array{
        $q=$pdo->prepare("SELECT public_id,event_hash FROM glasses_vision_production_errors WHERE organization_id=? AND event_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$eventKey]);$existing=$q->fetch();
        if($existing){
            if(!hash_equals((string)$existing['event_hash'],$hash))throw new InvalidArgumentException('Production error event key already exists with different immutable evidence.');
            return glasses_vision_feedback_public(glasses_vision_feedback_row($pdo,$org,(string)$existing['public_id']));
        }
        $public=glasses_public_id('vision-prod-error');
        $pdo->prepare("INSERT INTO glasses_vision_production_errors
          (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,build_session_id,observation_id,correction_id,device_id,location_id,station_id,menu_item_id,model_package_id,rollout_id,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$eventKey,$hash,$source,$errorType,$outcome,(int)$s['id'],$o?(int)$o['id']:null,$correctionId,(int)$s['device_id'],(int)$s['location_id'],$s['station_id']!==null?(int)$s['station_id']:null,(int)$s['menu_item_id'],$a&&$a['package_id']!==null?(int)$a['package_id']:null,$a&&$a['rollout_id']!==null?(int)$a['rollout_id']:null,$predicted,$expected,$confidence,json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$ctx['occurredAt']]);
        if(!empty($a['package_public_id'])){
            glasses_vision_lineage_edge($pdo,$org,'model_package',(string)$a['package_public_id'],(string)($a['package_sha256']??null),'produced_error','production_error',$public,$hash,[
              'errorType'=>$errorType,'outcome'=>$outcome,'buildSessionPublicId'=>$s['public_id'],'observationKey'=>$o['observation_key']??null
            ],$actor);
        }
        return glasses_vision_feedback_public(glasses_vision_feedback_row($pdo,$org,$public));
    });
}

function glasses_vision_feedback_sync_corrections(PDO $pdo,int $org,int $actor,int $limit=500): array
{
    if(!glasses_vision_feedback_ready($pdo))throw new RuntimeException('Vision Lab V7 production-error migration is not installed.');
    $limit=max(1,min(2000,$limit));
    $q=$pdo->prepare("SELECT c.id,c.correction_key,c.resolution,c.target_component_key,c.corrected_quantity,c.reason,c.created_at,
      o.observation_key,o.component_key predicted_component_key,o.confidence,s.public_id build_session_public_id
      FROM glasses_observation_corrections c
      JOIN glasses_build_observations o ON o.id=c.observation_id AND o.organization_id=c.organization_id
      JOIN glasses_build_sessions s ON s.id=c.build_session_id AND s.organization_id=c.organization_id
      LEFT JOIN glasses_vision_production_errors e ON e.organization_id=c.organization_id AND e.event_key=CONCAT('correction:',s.public_id,':',c.correction_key)
      WHERE c.organization_id=? AND e.id IS NULL ORDER BY c.id LIMIT ".$limit);
    $q->execute([$org]);$created=[];
    foreach($q->fetchAll() as $r){
        $pred=(string)$r['predicted_component_key'];$target=trim((string)($r['target_component_key']??''));
        if((string)$r['resolution']==='reject'){$type='false_positive';$outcome='rejected';$expected=null;}
        elseif($target!==''&&$target!==$pred){$type='misclassification';$outcome='reclassified';$expected=$target;}
        else{$type='correction';$outcome='corrected';$expected=$target?:$pred;}
        $created[]=glasses_vision_feedback_record($pdo,$org,[
          'eventKey'=>'correction:'.$r['build_session_public_id'].':'.$r['correction_key'],
          'sourceType'=>'human_correction','errorType'=>$type,'outcome'=>$outcome,
          'buildSessionPublicId'=>$r['build_session_public_id'],'observationKey'=>$r['observation_key'],
          'predictedComponentKey'=>$pred,'expectedComponentKey'=>$expected,'confidence'=>$r['confidence'],
          'correctionId'=>(int)$r['id'],
          'context'=>['correctionKey'=>$r['correction_key'],'resolution'=>$r['resolution'],'correctedQuantity'=>$r['corrected_quantity']!==null?(float)$r['corrected_quantity']:null,'reason'=>$r['reason']]
        ],$actor);
    }
    return ['created'=>count($created),'events'=>$created];
}

function glasses_vision_feedback_list(PDO $pdo,int $org,int $limit=100): array
{
    if(!glasses_vision_feedback_ready($pdo))return [];
    $limit=max(1,min(500,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_production_errors WHERE organization_id=? ORDER BY occurred_at DESC,id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_feedback_public(glasses_vision_feedback_row($pdo,$org,(string)$p));
    return $out;
}

function glasses_vision_feedback_summary(PDO $pdo,int $org): array
{
    if(!glasses_vision_feedback_ready($pdo))return ['total'=>0,'byType'=>[],'byOutcome'=>[]];
    $q=$pdo->prepare("SELECT error_type,outcome,COUNT(*) c FROM glasses_vision_production_errors WHERE organization_id=? GROUP BY error_type,outcome");
    $q->execute([$org]);$byType=[];$byOutcome=[];$total=0;
    foreach($q->fetchAll() as $r){$n=(int)$r['c'];$total+=$n;$byType[$r['error_type']]=($byType[$r['error_type']]??0)+$n;$byOutcome[$r['outcome']]=($byOutcome[$r['outcome']]??0)+$n;}
    ksort($byType);ksort($byOutcome);return ['total'=>$total,'byType'=>$byType,'byOutcome'=>$byOutcome];
}

function glasses_vision_feedback_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_feedback_ready($pdo),'schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA,'summary'=>glasses_vision_feedback_summary($pdo,$org),'events'=>glasses_vision_feedback_list($pdo,$org,100)];
}
