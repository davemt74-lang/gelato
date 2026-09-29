<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-final-validation.php';
require_once __DIR__.'/glasses-handoff.php';

const GLASSES_VISION_HANDOFF_CONFIRMATION_SCHEMA='gelato.vision_handoff_confirmation.v1';

function glasses_vision_handoff_confirmation_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_handoff_confirmations'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_final_ready($pdo) && glasses_handoff_ready($pdo);
}

function glasses_vision_handoff_confirmation_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT c.*,v.public_id final_validation_public_id,v.validation_hash,
        s.public_id build_session_public_id,d.public_id device_public_id,
        h.public_id handoff_public_id
      FROM glasses_vision_handoff_confirmations c
      JOIN glasses_vision_final_validations v ON v.id=c.final_validation_id AND v.organization_id=c.organization_id
      JOIN glasses_build_sessions s ON s.id=c.build_session_id AND s.organization_id=c.organization_id
      JOIN glasses_devices d ON d.id=c.device_id AND d.organization_id=c.organization_id
      LEFT JOIN glasses_kds_handoffs h ON h.id=c.handoff_id AND h.organization_id=c.organization_id
      WHERE c.organization_id=? AND c.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Human handoff confirmation was not found.');
    return $r;
}

function glasses_vision_handoff_confirmation_public(array $r): array
{
    return [
      'publicId'=>(string)$r['public_id'],
      'confirmationKey'=>(string)$r['confirmation_key'],
      'finalValidationPublicId'=>(string)$r['final_validation_public_id'],
      'buildSessionPublicId'=>(string)$r['build_session_public_id'],
      'devicePublicId'=>(string)$r['device_public_id'],
      'actorUserId'=>$r['actor_user_id']!==null?(int)$r['actor_user_id']:null,
      'handoffPublicId'=>$r['handoff_public_id'],
      'status'=>(string)$r['status'],
      'finalValidationHash'=>(string)$r['final_validation_hash'],
      'buildContextHash'=>(string)$r['build_context_hash'],
      'confirmation'=>json_decode((string)$r['confirmation_json'],true)?:[],
      'result'=>json_decode((string)($r['result_json']??'null'),true),
      'confirmedAt'=>$r['confirmed_at'],
      'createdAt'=>$r['created_at'],
      'updatedAt'=>$r['updated_at'],
    ];
}

function glasses_vision_handoff_confirm(
    PDO $pdo,
    array $device,
    string $finalValidationPublicId,
    string $confirmationKey,
    ?int $actorUserId=null
): array {
    if(!glasses_vision_handoff_confirmation_ready($pdo))
        throw new RuntimeException('V9 human handoff confirmation migration is not installed.');

    $org=(int)$device['organization_id'];
    $confirmationKey=mb_substr(trim($confirmationKey),0,190,'UTF-8');
    if($confirmationKey==='')throw new InvalidArgumentException('Confirmation key is required for idempotency.');
    if($actorUserId===null)$actorUserId=(int)($device['paired_by']??0)?:null;

    return glasses_transaction($pdo,function()use($pdo,$device,$org,$finalValidationPublicId,$confirmationKey,$actorUserId):array{
        $q=$pdo->prepare("SELECT public_id FROM glasses_vision_handoff_confirmations WHERE organization_id=? AND confirmation_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$confirmationKey]);
        if($existing=$q->fetchColumn()){
            return glasses_vision_handoff_confirmation_public(glasses_vision_handoff_confirmation_row($pdo,$org,(string)$existing,false));
        }

        $final=glasses_vision_final_row($pdo,$org,trim($finalValidationPublicId));
        if((string)$final['state']!=='ready_candidate'||empty($final['readyCandidate']))
            throw new InvalidArgumentException('Final product validation is not a ready candidate.');
        if(!glasses_vision_final_verify($pdo,$org,$final['publicId'])['passed'])
            throw new InvalidArgumentException('Final product validation integrity check failed.');

        $scene=glasses_vision_scene_row($pdo,$org,(string)$final['scenePublicId']);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))
            throw new InvalidArgumentException('Final validation belongs to another glasses device.');

        $session=glasses_build_session_row($pdo,$org,(string)$final['buildSessionPublicId'],true);
        glasses_build_assert_device_session($device,$session);
        $build=glasses_build_payload($pdo,$org,(string)$final['buildSessionPublicId']);
        if(empty($build['summary']['accounted']))
            throw new InvalidArgumentException('Canonical build is no longer fully accounted.');

        $sceneContextHash=(string)($scene['context']['buildContextHash']??'');
        $liveContextHash=glasses_vision_step_build_context_hash($build);
        if($sceneContextHash===''||!hash_equals($sceneContextHash,$liveContextHash))
            throw new InvalidArgumentException('Final validation is stale relative to the current canonical build state.');

        $validationRowQ=$pdo->prepare("SELECT id,validation_hash FROM glasses_vision_final_validations WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $validationRowQ->execute([$org,$final['publicId']]);$validationRow=$validationRowQ->fetch();
        if(!$validationRow)throw new InvalidArgumentException('Final validation was not found.');

        $public=glasses_public_id('vision-handoff-confirmation');
        $confirmation=[
          'schema'=>GLASSES_VISION_HANDOFF_CONFIRMATION_SCHEMA,
          'explicitHumanConfirmation'=>true,
          'finalValidationPublicId'=>$final['publicId'],
          'finalValidationHash'=>$final['validationHash'],
          'buildSessionPublicId'=>$final['buildSessionPublicId'],
          'buildContextHash'=>$liveContextHash,
          'actorUserId'=>$actorUserId,
          'actorDevicePublicId'=>(string)$device['public_id'],
        ];

        $pdo->prepare("INSERT INTO glasses_vision_handoff_confirmations
          (organization_id,public_id,confirmation_key,final_validation_id,build_session_id,device_id,actor_user_id,status,final_validation_hash,build_context_hash,confirmation_json)
          VALUES (?,?,?,?,?,?,?,'confirmed',?,?,?)")
          ->execute([
            $org,$public,$confirmationKey,(int)$validationRow['id'],(int)$session['id'],(int)$device['id'],$actorUserId,
            (string)$final['validationHash'],$liveContextHash,glasses_vision_training_release_json($confirmation)
          ]);
        $confirmationId=(int)$pdo->lastInsertId();

        // Existing canonical product-validation/KDS handoff owns the consequential transition.
        $handoff=glasses_handoff_to_expo($pdo,$device,(string)$final['buildSessionPublicId']);

        $hq=$pdo->prepare("SELECT id FROM glasses_kds_handoffs WHERE organization_id=? AND public_id=? LIMIT 1");
        $hq->execute([$org,(string)$handoff['publicId']]);$handoffId=(int)$hq->fetchColumn();
        if($handoffId<1)throw new RuntimeException('Canonical Expo handoff record was not found after completion.');

        $result=[
          'handoffPublicId'=>$handoff['publicId'],
          'kdsItemPublicId'=>$handoff['kdsItemPublicId'],
          'fromStatus'=>$handoff['fromStatus'],
          'toStatus'=>$handoff['toStatus'],
          'kdsStatus'=>$handoff['kdsStatus'],
          'buildSessionCompleted'=>true,
        ];

        $pdo->prepare("UPDATE glasses_vision_handoff_confirmations
          SET handoff_id=?,status='completed',result_json=?,confirmed_at=NOW(6),updated_at=NOW(6)
          WHERE organization_id=? AND id=?")
          ->execute([$handoffId,glasses_vision_training_release_json($result),$org,$confirmationId]);

        glasses_build_event(
          $pdo,$org,(int)$session['id'],'vision_human_handoff_confirmed',null,
          [
            'confirmationPublicId'=>$public,
            'confirmationKey'=>$confirmationKey,
            'finalValidationPublicId'=>$final['publicId'],
            'finalValidationHash'=>$final['validationHash'],
            'handoffPublicId'=>$handoff['publicId'],
          ],
          (int)$device['id']
        );

        return glasses_vision_handoff_confirmation_public(
          glasses_vision_handoff_confirmation_row($pdo,$org,$public,false)
        );
    });
}

function glasses_vision_handoff_confirmation_recent(PDO $pdo,int $org,int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_handoff_confirmations WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll() as $r)$out[]=glasses_vision_handoff_confirmation_public(glasses_vision_handoff_confirmation_row($pdo,$org,(string)$r['public_id'],false));
    return $out;
}
