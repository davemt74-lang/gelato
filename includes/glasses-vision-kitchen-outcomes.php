<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-rework.php';
require_once __DIR__.'/glasses-vision-feedback.php';

const GLASSES_VISION_KITCHEN_OUTCOME_SCHEMA='gelato.vision_kitchen_outcome.v1';

function glasses_vision_kitchen_outcome_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_kitchen_outcomes'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_rework_ready($pdo) && glasses_vision_feedback_ready($pdo);
}

function glasses_vision_kitchen_outcome_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT o.*,s.public_id build_session_public_id,d.public_id device_public_id
      FROM glasses_vision_kitchen_outcomes o
      JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
      JOIN glasses_devices d ON d.id=o.device_id AND d.organization_id=o.organization_id
      WHERE o.organization_id=? AND o.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Kitchen outcome was not found.');
    return $r;
}

function glasses_vision_kitchen_outcome_public(array $r): array
{
    return [
      'schema'=>GLASSES_VISION_KITCHEN_OUTCOME_SCHEMA,'publicId'=>$r['public_id'],
      'eventKey'=>$r['event_key'],'eventHash'=>$r['event_hash'],
      'sourceKind'=>$r['source_kind'],'sourcePublicId'=>$r['source_public_id'],'sourceHash'=>$r['source_hash'],
      'buildSessionPublicId'=>$r['build_session_public_id'],'devicePublicId'=>$r['device_public_id'],
      'category'=>$r['category'],'disposition'=>$r['disposition'],'reviewStatus'=>$r['review_status'],
      'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'actorUserId'=>$r['actor_user_id']!==null?(int)$r['actor_user_id']:null,
      'occurredAt'=>$r['occurred_at'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_kitchen_outcome_record(PDO $pdo,int $org,array $input,?int $actorUserId): array
{
    if(!glasses_vision_kitchen_outcome_ready($pdo))throw new RuntimeException('V9 kitchen-outcome migration is not installed.');
    $eventKey=mb_substr(trim((string)($input['eventKey']??'')),0,190,'UTF-8');
    $sourceKind=trim((string)($input['sourceKind']??''));$sourcePublic=trim((string)($input['sourcePublicId']??''));
    $sourceHash=strtolower(trim((string)($input['sourceHash']??'')));
    $category=trim((string)($input['category']??''));$disposition=trim((string)($input['disposition']??''));
    if($eventKey===''||$sourcePublic===''||!preg_match('/^[a-f0-9]{64}$/',$sourceHash))throw new InvalidArgumentException('Kitchen outcome source identity is invalid.');
    foreach([$sourceKind,$category,$disposition] as $v)if(!preg_match('/^[a-z0-9_.-]{2,64}$/',$v))throw new InvalidArgumentException('Kitchen outcome classification is invalid.');
    $buildSessionPublic=trim((string)($input['buildSessionPublicId']??''));$devicePublic=trim((string)($input['devicePublicId']??''));
    $sq=$pdo->prepare("SELECT id,device_id FROM glasses_build_sessions WHERE organization_id=? AND public_id=? LIMIT 1");$sq->execute([$org,$buildSessionPublic]);$session=$sq->fetch();
    if(!$session)throw new InvalidArgumentException('Kitchen outcome build session was not found.');
    $dq=$pdo->prepare("SELECT id FROM glasses_devices WHERE organization_id=? AND public_id=? LIMIT 1");$dq->execute([$org,$devicePublic]);$deviceId=(int)$dq->fetchColumn();
    if($deviceId<1||(int)$session['device_id']!==$deviceId)throw new InvalidArgumentException('Kitchen outcome device/build identity mismatch.');
    $evidence=is_array($input['evidence']??null)?$input['evidence']:[];
    $occurredAt=(string)($input['occurredAt']??gmdate('Y-m-d H:i:s.u'));
    $snapshot=[
      'schema'=>GLASSES_VISION_KITCHEN_OUTCOME_SCHEMA,'eventKey'=>$eventKey,'sourceKind'=>$sourceKind,
      'sourcePublicId'=>$sourcePublic,'sourceHash'=>$sourceHash,'buildSessionPublicId'=>$buildSessionPublic,
      'devicePublicId'=>$devicePublic,'category'=>$category,'disposition'=>$disposition,'reviewStatus'=>'pending',
      'evidence'=>$evidence,'occurredAt'=>$occurredAt
    ];
    $eventHash=hash('sha256',glasses_vision_training_release_json($snapshot));

    return glasses_transaction($pdo,function()use($pdo,$org,$actorUserId,$eventKey,$eventHash,$sourceKind,$sourcePublic,$sourceHash,$session,$deviceId,$category,$disposition,$snapshot,$occurredAt):array{
      $q=$pdo->prepare("SELECT public_id,event_hash FROM glasses_vision_kitchen_outcomes WHERE organization_id=? AND event_key=? LIMIT 1 FOR UPDATE");
      $q->execute([$org,$eventKey]);$existing=$q->fetch();
      if($existing){
        if(!hash_equals((string)$existing['event_hash'],$eventHash))throw new InvalidArgumentException('Kitchen outcome event key already exists with different immutable evidence.');
        return glasses_vision_kitchen_outcome_public(glasses_vision_kitchen_outcome_row($pdo,$org,(string)$existing['public_id']));
      }
      $public=glasses_public_id('vision-kitchen-outcome');
      $pdo->prepare("INSERT INTO glasses_vision_kitchen_outcomes
        (organization_id,public_id,event_key,event_hash,source_kind,source_public_id,source_hash,build_session_id,device_id,category,disposition,review_status,evidence_json,actor_user_id,occurred_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,?)")
        ->execute([$org,$public,$eventKey,$eventHash,$sourceKind,$sourcePublic,$sourceHash,(int)$session['id'],$deviceId,$category,$disposition,glasses_vision_training_release_json($snapshot),$actorUserId,$occurredAt]);
      glasses_vision_lineage_edge($pdo,$org,$sourceKind,$sourcePublic,$sourceHash,'observed_as_kitchen_outcome','kitchen_outcome',$public,$eventHash,[
        'category'=>$category,'disposition'=>$disposition,'reviewStatus'=>'pending'
      ],$actorUserId);
      return glasses_vision_kitchen_outcome_public(glasses_vision_kitchen_outcome_row($pdo,$org,$public));
    });
}

function glasses_vision_kitchen_outcome_sync(PDO $pdo,int $org,int $actorUserId,int $limit=500): array
{
    if(!glasses_vision_kitchen_outcome_ready($pdo))throw new RuntimeException('V9 kitchen-outcome migration is not installed.');
    $limit=max(1,min(2000,$limit));$created=[];

    $q=$pdo->prepare("SELECT v.public_id,v.validation_hash,v.state,v.presentation_score,v.confidence,v.result_json,v.created_at,
      s.public_id build_public_id,d.public_id device_public_id
      FROM glasses_vision_final_validations v
      JOIN glasses_build_sessions s ON s.id=v.build_session_id AND s.organization_id=v.organization_id
      JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
      LEFT JOIN glasses_vision_kitchen_outcomes o ON o.organization_id=v.organization_id AND o.event_key=CONCAT('final:',v.public_id)
      WHERE v.organization_id=? AND o.id IS NULL AND v.state IN ('ready_candidate','needs_correction')
      ORDER BY v.id LIMIT ".$limit);
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
      $pass=(string)$r['state']==='ready_candidate';
      $created[]=glasses_vision_kitchen_outcome_record($pdo,$org,[
        'eventKey'=>'final:'.$r['public_id'],'sourceKind'=>'final_product_validation','sourcePublicId'=>$r['public_id'],'sourceHash'=>$r['validation_hash'],
        'buildSessionPublicId'=>$r['build_public_id'],'devicePublicId'=>$r['device_public_id'],
        'category'=>$pass?'confirmed_pass':'final_failure','disposition'=>$pass?'positive_reference':'defect_review',
        'evidence'=>['state'=>$r['state'],'presentationScore'=>$r['presentation_score']!==null?(float)$r['presentation_score']:null,'confidence'=>(float)$r['confidence'],'result'=>json_decode((string)$r['result_json'],true)?:[]],
        'occurredAt'=>$r['created_at']
      ],$actorUserId);
    }

    $q=$pdo->prepare("SELECT a.public_id,a.attempt_hash,a.state,a.attempt_json,a.created_at,c.public_id case_public_id,
      s.public_id build_public_id,d.public_id device_public_id
      FROM glasses_vision_rework_attempts a
      JOIN glasses_vision_rework_cases c ON c.id=a.rework_case_id AND c.organization_id=a.organization_id
      JOIN glasses_build_sessions s ON s.id=c.build_session_id AND s.organization_id=c.organization_id
      JOIN glasses_devices d ON d.id=c.device_id AND d.organization_id=c.organization_id
      LEFT JOIN glasses_vision_kitchen_outcomes o ON o.organization_id=a.organization_id AND o.event_key=CONCAT('rework:',a.public_id)
      WHERE a.organization_id=? AND o.id IS NULL ORDER BY a.id LIMIT ".$limit);
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
      $state=(string)$r['state'];$success=$state==='ready_candidate';
      $created[]=glasses_vision_kitchen_outcome_record($pdo,$org,[
        'eventKey'=>'rework:'.$r['public_id'],'sourceKind'=>'vision_rework_attempt','sourcePublicId'=>$r['public_id'],'sourceHash'=>$r['attempt_hash'],
        'buildSessionPublicId'=>$r['build_public_id'],'devicePublicId'=>$r['device_public_id'],
        'category'=>$success?'rework_success':'rework_failure','disposition'=>$success?'correction_pair':'defect_review',
        'evidence'=>['reworkCasePublicId'=>$r['case_public_id'],'state'=>$state,'attempt'=>json_decode((string)$r['attempt_json'],true)?:[]],
        'occurredAt'=>$r['created_at']
      ],$actorUserId);
    }

    $q=$pdo->prepare("SELECT c.public_id,c.confirmation_json,c.result_json,c.confirmed_at,s.public_id build_public_id,d.public_id device_public_id
      FROM glasses_vision_handoff_confirmations c
      JOIN glasses_build_sessions s ON s.id=c.build_session_id AND s.organization_id=c.organization_id
      JOIN glasses_devices d ON d.id=c.device_id AND d.organization_id=c.organization_id
      LEFT JOIN glasses_vision_kitchen_outcomes o ON o.organization_id=c.organization_id AND o.event_key=CONCAT('handoff:',c.public_id)
      WHERE c.organization_id=? AND c.status='completed' AND o.id IS NULL ORDER BY c.id LIMIT ".$limit);
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
      $sourceMaterial=['confirmation'=>json_decode((string)$r['confirmation_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[]];
      $sourceHash=hash('sha256',glasses_vision_training_release_json($sourceMaterial));
      $created[]=glasses_vision_kitchen_outcome_record($pdo,$org,[
        'eventKey'=>'handoff:'.$r['public_id'],'sourceKind'=>'vision_handoff_confirmation','sourcePublicId'=>$r['public_id'],'sourceHash'=>$sourceHash,
        'buildSessionPublicId'=>$r['build_public_id'],'devicePublicId'=>$r['device_public_id'],
        'category'=>'confirmed_handoff','disposition'=>'accepted_output','evidence'=>$sourceMaterial,'occurredAt'=>$r['confirmed_at']
      ],$actorUserId);
    }

    return ['created'=>count($created),'events'=>$created];
}

function glasses_vision_kitchen_outcome_list(PDO $pdo,int $org,int $limit=100): array
{
    if(!glasses_vision_kitchen_outcome_ready($pdo))return [];
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_kitchen_outcomes WHERE organization_id=? ORDER BY occurred_at DESC,id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_kitchen_outcome_public(glasses_vision_kitchen_outcome_row($pdo,$org,(string)$p));return $out;
}
function glasses_vision_kitchen_outcome_summary(PDO $pdo,int $org): array
{
    if(!glasses_vision_kitchen_outcome_ready($pdo))return ['total'=>0,'pendingReview'=>0,'byCategory'=>[]];
    $q=$pdo->prepare("SELECT category,review_status,COUNT(*) c FROM glasses_vision_kitchen_outcomes WHERE organization_id=? GROUP BY category,review_status");$q->execute([$org]);
    $out=['total'=>0,'pendingReview'=>0,'byCategory'=>[]];foreach($q->fetchAll() as $r){$n=(int)$r['c'];$out['total']+=$n;if($r['review_status']==='pending')$out['pendingReview']+=$n;$out['byCategory'][$r['category']]=($out['byCategory'][$r['category']]??0)+$n;}ksort($out['byCategory']);return $out;
}
function glasses_vision_kitchen_outcome_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_kitchen_outcome_ready($pdo),'schema'=>GLASSES_VISION_KITCHEN_OUTCOME_SCHEMA,'summary'=>glasses_vision_kitchen_outcome_summary($pdo,$org),'events'=>glasses_vision_kitchen_outcome_list($pdo,$org,100)];
}
