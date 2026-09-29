<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-handoff-confirmation.php';

const GLASSES_VISION_REWORK_SCHEMA='gelato.vision_rework.v1';

function glasses_vision_rework_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_rework_cases','glasses_vision_rework_attempts'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_handoff_confirmation_ready($pdo);
}

function glasses_vision_rework_case_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT c.*,s.public_id build_session_public_id,d.public_id device_public_id,
      sv.public_id source_validation_public_id,sv.validation_hash source_hash,
      lv.public_id latest_validation_public_id,lv.validation_hash latest_hash
      FROM glasses_vision_rework_cases c
      JOIN glasses_build_sessions s ON s.id=c.build_session_id AND s.organization_id=c.organization_id
      JOIN glasses_devices d ON d.id=c.device_id AND d.organization_id=c.organization_id
      JOIN glasses_vision_final_validations sv ON sv.id=c.source_final_validation_id AND sv.organization_id=c.organization_id
      LEFT JOIN glasses_vision_final_validations lv ON lv.id=c.latest_final_validation_id AND lv.organization_id=c.organization_id
      WHERE c.organization_id=? AND c.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Vision rework case was not found.');
    return $r;
}

function glasses_vision_rework_case_public(array $r): array
{
    return [
      'publicId'=>(string)$r['public_id'],'caseKey'=>(string)$r['case_key'],
      'buildSessionPublicId'=>(string)$r['build_session_public_id'],'devicePublicId'=>(string)$r['device_public_id'],
      'sourceFinalValidationPublicId'=>(string)$r['source_validation_public_id'],
      'sourceValidationHash'=>(string)$r['source_validation_hash'],
      'latestFinalValidationPublicId'=>$r['latest_validation_public_id'],
      'latestValidationHash'=>$r['latest_hash'],'actorUserId'=>$r['actor_user_id']!==null?(int)$r['actor_user_id']:null,
      'status'=>(string)$r['status'],'reason'=>json_decode((string)$r['reason_json'],true)?:[],
      'resolution'=>json_decode((string)($r['resolution_json']??'null'),true),
      'openedAt'=>$r['opened_at'],'resolvedAt'=>$r['resolved_at'],'updatedAt'=>$r['updated_at'],
    ];
}

function glasses_vision_rework_attempt_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT a.*,c.public_id case_public_id,s.public_id scene_public_id,v.public_id final_validation_public_id,v.validation_hash
      FROM glasses_vision_rework_attempts a
      JOIN glasses_vision_rework_cases c ON c.id=a.rework_case_id AND c.organization_id=a.organization_id
      JOIN glasses_vision_scene_snapshots s ON s.id=a.scene_snapshot_id AND s.organization_id=a.organization_id
      JOIN glasses_vision_final_validations v ON v.id=a.final_validation_id AND v.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Vision rework attempt was not found.');
    return [
      'publicId'=>$r['public_id'],'reworkCasePublicId'=>$r['case_public_id'],'attemptKey'=>$r['attempt_key'],
      'scenePublicId'=>$r['scene_public_id'],'finalValidationPublicId'=>$r['final_validation_public_id'],
      'finalValidationHash'=>$r['validation_hash'],'state'=>$r['state'],
      'attempt'=>json_decode((string)$r['attempt_json'],true)?:[],'attemptHash'=>$r['attempt_hash'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_rework_open(PDO $pdo,array $device,string $finalValidationPublicId,string $caseKey,?int $actorUserId=null): array
{
    if(!glasses_vision_rework_ready($pdo))throw new RuntimeException('V9 rework migration is not installed.');
    $org=(int)$device['organization_id'];$caseKey=mb_substr(trim($caseKey),0,190,'UTF-8');
    if($caseKey==='')throw new InvalidArgumentException('Rework case key is required for idempotency.');
    if($actorUserId===null)$actorUserId=(int)($device['paired_by']??0)?:null;

    return glasses_transaction($pdo,function()use($pdo,$device,$org,$finalValidationPublicId,$caseKey,$actorUserId):array{
      $q=$pdo->prepare("SELECT public_id FROM glasses_vision_rework_cases WHERE organization_id=? AND case_key=? LIMIT 1 FOR UPDATE");
      $q->execute([$org,$caseKey]);if($existing=$q->fetchColumn())
        return glasses_vision_rework_case_public(glasses_vision_rework_case_row($pdo,$org,(string)$existing,false));

      $final=glasses_vision_final_row($pdo,$org,trim($finalValidationPublicId));
      if((string)$final['state']!=='needs_correction')
        throw new InvalidArgumentException('Only a failed final validation can open a rework case.');
      if(!glasses_vision_final_verify($pdo,$org,$final['publicId'])['passed'])
        throw new InvalidArgumentException('Failed final validation integrity check did not pass.');

      $scene=glasses_vision_scene_row($pdo,$org,(string)$final['scenePublicId']);
      if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))
        throw new InvalidArgumentException('Failed final validation belongs to another glasses device.');

      $session=glasses_build_session_row($pdo,$org,(string)$final['buildSessionPublicId'],true);
      glasses_build_assert_device_session($device,$session);

      $vq=$pdo->prepare("SELECT id,validation_hash FROM glasses_vision_final_validations WHERE organization_id=? AND public_id=? LIMIT 1");
      $vq->execute([$org,$final['publicId']]);$vr=$vq->fetch();
      $reason=['state'=>$final['state'],'presentationScore'=>$final['presentationScore'],'result'=>$final['result']];
      $public=glasses_public_id('vision-rework');
      $pdo->prepare("INSERT INTO glasses_vision_rework_cases
        (organization_id,public_id,case_key,build_session_id,device_id,source_final_validation_id,actor_user_id,status,source_validation_hash,reason_json)
        VALUES (?,?,?,?,?,?,?,'open',?,?)")
        ->execute([$org,$public,$caseKey,(int)$session['id'],(int)$device['id'],(int)$vr['id'],$actorUserId,(string)$vr['validation_hash'],glasses_vision_training_release_json($reason)]);

      glasses_build_event($pdo,$org,(int)$session['id'],'vision_rework_opened',null,[
        'reworkCasePublicId'=>$public,'sourceFinalValidationPublicId'=>$final['publicId'],'sourceValidationHash'=>$vr['validation_hash']
      ],(int)$device['id']);

      glasses_vision_lineage_edge($pdo,$org,'final_product_validation',$final['publicId'],$final['validationHash'],
        'opened_rework_case','vision_rework_case',$public,null,['state'=>$final['state']],$actorUserId);

      return glasses_vision_rework_case_public(glasses_vision_rework_case_row($pdo,$org,$public,false));
    });
}

function glasses_vision_rework_revalidate(PDO $pdo,array $device,string $casePublicId,string $scenePublicId,string $attemptKey): array
{
    if(!glasses_vision_rework_ready($pdo))throw new RuntimeException('V9 rework migration is not installed.');
    $org=(int)$device['organization_id'];$attemptKey=mb_substr(trim($attemptKey),0,190,'UTF-8');
    if($attemptKey==='')throw new InvalidArgumentException('Revalidation attempt key is required for idempotency.');

    return glasses_transaction($pdo,function()use($pdo,$device,$org,$casePublicId,$scenePublicId,$attemptKey):array{
      $q=$pdo->prepare("SELECT public_id FROM glasses_vision_rework_attempts WHERE organization_id=? AND attempt_key=? LIMIT 1 FOR UPDATE");
      $q->execute([$org,$attemptKey]);if($existing=$q->fetchColumn()){
        $attempt=glasses_vision_rework_attempt_row($pdo,$org,(string)$existing);
        return ['reworkCase'=>glasses_vision_rework_case_public(glasses_vision_rework_case_row($pdo,$org,$attempt['reworkCasePublicId'],false)),'attempt'=>$attempt];
      }

      $case=glasses_vision_rework_case_row($pdo,$org,trim($casePublicId),true);
      if((string)$case['status']!=='open')throw new InvalidArgumentException('Rework case is already resolved.');
      if((int)$case['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Rework case belongs to another glasses device.');

      $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
      if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))
        throw new InvalidArgumentException('Revalidation scene belongs to another glasses device.');
      if(!hash_equals((string)$scene['buildSessionPublicId'],(string)$case['build_session_public_id']))
        throw new InvalidArgumentException('Revalidation scene belongs to another build session.');

      $final=glasses_vision_final_validate_scene($pdo,$org,$scene['publicId']);
      if(!in_array((string)$final['state'],['ready_candidate','needs_correction','insufficient'],true))
        throw new InvalidArgumentException('Revalidation scene is not eligible for the rework loop.');

      $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=? LIMIT 1");$sq->execute([$org,$scene['publicId']]);$sceneId=(int)$sq->fetchColumn();
      $vq=$pdo->prepare("SELECT id FROM glasses_vision_final_validations WHERE organization_id=? AND public_id=? LIMIT 1");$vq->execute([$org,$final['publicId']]);$validationId=(int)$vq->fetchColumn();

      $attempt=[
        'schema'=>GLASSES_VISION_REWORK_SCHEMA,'reworkCasePublicId'=>$case['public_id'],'attemptKey'=>$attemptKey,
        'sourceFinalValidationPublicId'=>$case['source_validation_public_id'],'newFinalValidationPublicId'=>$final['publicId'],
        'newFinalValidationHash'=>$final['validationHash'],'state'=>$final['state'],'readyCandidate'=>$final['readyCandidate'],
      ];
      $attemptHash=hash('sha256',glasses_vision_training_release_json($attempt));$attemptPublic=glasses_public_id('vision-rework-attempt');
      $pdo->prepare("INSERT INTO glasses_vision_rework_attempts
        (organization_id,public_id,rework_case_id,attempt_key,scene_snapshot_id,final_validation_id,state,attempt_json,attempt_hash)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$org,$attemptPublic,(int)$case['id'],$attemptKey,$sceneId,$validationId,$final['state'],glasses_vision_training_release_json($attempt),$attemptHash]);

      $actor=$case['actor_user_id']!==null?(int)$case['actor_user_id']:null;
      glasses_vision_lineage_edge($pdo,$org,'final_product_validation',(string)$case['source_validation_public_id'],(string)$case['source_validation_hash'],
        'revalidated_as','final_product_validation',$final['publicId'],$final['validationHash'],
        ['reworkCasePublicId'=>$case['public_id'],'attemptPublicId'=>$attemptPublic,'state'=>$final['state']],$actor);

      if((string)$final['state']==='ready_candidate'&&!empty($final['readyCandidate'])){
        $resolution=['resolvedByFinalValidationPublicId'=>$final['publicId'],'resolvedByValidationHash'=>$final['validationHash'],'attemptPublicId'=>$attemptPublic];
        $pdo->prepare("UPDATE glasses_vision_rework_cases SET latest_final_validation_id=?,status='resolved',resolution_json=?,resolved_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$validationId,glasses_vision_training_release_json($resolution),$org,(int)$case['id']]);
        glasses_build_event($pdo,$org,(int)$case['build_session_id'],'vision_rework_resolved',null,[
          'reworkCasePublicId'=>$case['public_id'],'finalValidationPublicId'=>$final['publicId'],'attemptPublicId'=>$attemptPublic
        ],(int)$device['id']);
      }else{
        $pdo->prepare("UPDATE glasses_vision_rework_cases SET latest_final_validation_id=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$validationId,$org,(int)$case['id']]);
        glasses_build_event($pdo,$org,(int)$case['build_session_id'],'vision_rework_revalidation_failed',null,[
          'reworkCasePublicId'=>$case['public_id'],'finalValidationPublicId'=>$final['publicId'],'state'=>$final['state'],'attemptPublicId'=>$attemptPublic
        ],(int)$device['id']);
      }

      return [
        'reworkCase'=>glasses_vision_rework_case_public(glasses_vision_rework_case_row($pdo,$org,(string)$case['public_id'],false)),
        'attempt'=>glasses_vision_rework_attempt_row($pdo,$org,$attemptPublic),
        'finalValidation'=>$final,
      ];
    });
}

function glasses_vision_rework_recent(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_rework_cases WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];foreach($q->fetchAll() as $r)$out[]=glasses_vision_rework_case_public(glasses_vision_rework_case_row($pdo,$org,(string)$r['public_id'],false));return $out;
}
