<?php
declare(strict_types=1);

/**
 * FM-B8D10 — durable high-impact Vision Lab review proposals.
 * The model may ONLY create a proposal. Execution requires a separate
 * authenticated, CSRF-checked human click, fresh server permissions, and
 * unchanged canonical sample state.
 */
require_once __DIR__.'/food-monster-b8d6-training.php';
require_once __DIR__.'/food-monster-b8d8-vision-actions.php';
require_once __DIR__.'/food-monster-b8d2-execution-loop.php';
require_once __DIR__.'/glasses-vision-lab.php';

function fm8d10_ready(PDO $pdo):bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='food_monster_agent_approval_requests'");
    return (int)$q->fetchColumn()===1;
}
function fm8d10_can_review(array $actor):bool
{
    return app_has_permission('glasses.view',$actor)
        &&app_has_permission('glasses.manage',$actor);
}
function fm8d10_sample_row(PDO $pdo,int $org,string $ref,bool $lock=false):?array
{
    if(!preg_match('/^vision-sample-[a-z0-9-]{8,72}$/D',$ref))return null;
    $q=$pdo->prepare("SELECT public_id,review_status,canonical_label,updated_at
        FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1".
        ($lock?' FOR UPDATE':''));
    $q->execute([$org,$ref]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}
function fm8d10_fingerprint(int $org,array $sample):string
{
    return hash('sha256',json_encode([
        $org,(string)$sample['public_id'],(string)$sample['review_status'],
        (string)($sample['canonical_label']??''),(string)$sample['updated_at'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function fm8d10_public(array $row):array
{
    $input=json_decode((string)($row['request_json']??'{}'),true)?:[];
    return ['id'=>(string)$row['public_id'],
        'type'=>'vision_sample_review','state'=>(string)$row['state'],
        'resource'=>(string)$row['resource_public_id'],
        'summary'=>(string)($input['summary']??'Review a Vision Lab training sample'),
        'expiresAt'=>(string)($row['expires_at']??''),'decision'=>(string)($input['review_decision']??'')];
}
function fm8d10_observation(string $answer,?array $approval=null):array
{
    $data=['answer'=>mb_strcut($answer,0,2200,'UTF-8'),
        'source'=>'Gelato Vision Lab governed approval ledger'];
    if($approval!==null)$data['approval']=$approval;
    return ['ok'=>true,'data'=>$data];
}
function fm8d10_propose(PDO $pdo,array $actor,array $context,array $args):array
{
    if(!fm8d10_can_review($actor))
        throw new DomainException('Vision review management is not authorized.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $ref=trim((string)($args['sample_public_id']??''));
    $action=(string)($args['review_decision']??'');
    $label=trim((string)($args['canonical_label']??''));
    $notes=trim((string)($args['notes']??''));
    if(!in_array($action,['approve','reject','relabel','needs_adjudication'],true)
       ||mb_strlen($label,'UTF-8')>190||mb_strlen($notes,'UTF-8')>1000
       ||($action==='relabel'&&$label===''))
        throw new InvalidArgumentException('Specify a valid review decision and label.');
    if(!glasses_vision_lab_ready($pdo)||!fm8d10_ready($pdo))
        return fm8d10_observation('Vision Lab approval migration is not installed. No change was made.');
    $pdo->beginTransaction();
    try{
        fm8d8_lock_actor($pdo,$org,$uid);
        try{$turn=fm8d6_canonical_turn($pdo,$actor,$context);}
        catch(InvalidArgumentException){$turn=null;}
        if($turn===null)throw new DomainException('Latest authenticated chat message changed.');
        $thread=(string)$context['threadId'];
        $q=$pdo->prepare("SELECT * FROM food_monster_agent_approval_requests
            WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?
                AND action='vision_sample_review' LIMIT 1 FOR UPDATE");
        $q->execute([$org,$uid,$turn]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            if($prior['state']==='pending'&&strtotime((string)$prior['expires_at'].' UTC')<=time()){
                $pdo->prepare("UPDATE food_monster_agent_approval_requests
                    SET state='expired',resolved_at=UTC_TIMESTAMP(6) WHERE id=?")
                    ->execute([$prior['id']]);
                $prior['state']='expired';
            }
            $row=$prior;$isReplay=true;
        }
        else{
            $sample=fm8d10_sample_row($pdo,$org,$ref,true);
            if(!$sample)throw new DomainException('No matching Vision Lab sample.');
            if(!in_array($sample['review_status'],['pending','needs_adjudication'],true))
                throw new DomainException('Sample is no longer awaiting review.');
            $public='fmapproval-'.bin2hex(random_bytes(12));
            $summary='Review sample '.$ref.' as '.$action.
                ($action==='relabel'?' with label '.$label:'').
                '. Current sample status: '.$sample['review_status'].'.';
            $payload=['sample_public_id'=>$ref,'review_decision'=>$action,
                'canonical_label'=>$label,'notes'=>$notes,'summary'=>$summary];
            $q=$pdo->prepare("INSERT INTO food_monster_agent_approval_requests
                (organization_id,public_id,actor_user_id,thread_public_id,turn_public_id,
                 action,resource_public_id,request_json,resource_fingerprint,state,expires_at)
                VALUES (?,?,?,?,?,'vision_sample_review',?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
            $q->execute([$org,$public,$uid,$thread,$turn,$ref,
                json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                fm8d10_fingerprint($org,$sample)]);
            $q=$pdo->prepare("SELECT * FROM food_monster_agent_approval_requests
                WHERE organization_id=? AND public_id=?");
            $q->execute([$org,$public]);$row=$q->fetch(PDO::FETCH_ASSOC);$isReplay=false;
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $approval=fm8d10_public($row);
    return fm8d10_observation(($isReplay?'This approval request already exists. ':'I prepared an approval request. ').
        $approval['summary'].' No training sample has been changed. Use the Approve or Dismiss button in Agent Chat.',
        $approval);
}
function fm8d10_pending(PDO $pdo,array $actor,string $thread):?array
{
    if(!fm8d10_can_review($actor)||!fm8d10_ready($pdo)
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread))return null;
    $q=$pdo->prepare("SELECT * FROM food_monster_agent_approval_requests
        WHERE organization_id=? AND actor_user_id=? AND thread_public_id=?
          AND state='pending' AND expires_at>UTC_TIMESTAMP(6)
        ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$actor['organization_id'],(int)$actor['id'],$thread]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?fm8d10_public($row):null;
}
function fm8d10_decide(PDO $pdo,array $principal,string $public,string $decision,string $thread):array
{
    if(!in_array($decision,['approve','dismiss'],true)
       ||!preg_match('/^fmapproval-[a-f0-9]{24}$/D',$public)
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread))
        throw new InvalidArgumentException('Invalid approval selection.');
    if(!fm8d10_ready($pdo))throw new DomainException('Approval schema is not installed.');
    $actor=fm8d2_refresh_operator($pdo,$principal);
    if(!$actor||!fm8d10_can_review($actor)
       ||(int)$actor['id']!==(int)$principal['id']
       ||(int)$actor['organization_id']!==(int)$principal['organization_id'])
        throw new DomainException('Approval authority was revoked or cannot be verified.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $pdo->beginTransaction();
    try{
        fm8d8_lock_actor($pdo,$org,$uid);
        $fresh=fm8d2_refresh_operator($pdo,$principal);
        if(!$fresh||!fm8d10_can_review($fresh))
            throw new DomainException('Current review permission is no longer valid.');
        $q=$pdo->prepare("SELECT * FROM food_monster_agent_approval_requests
            WHERE organization_id=? AND actor_user_id=? AND public_id=?
              AND thread_public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$uid,$public,$thread]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('This approval does not belong to the signed-in user and chat.');
        if($row['state']!=='pending'){
            $out=['state'=>(string)$row['state'],'executed'=>false,
                'answer'=>'This approval was already resolved as '.$row['state'].'. No action was repeated.'];
        }elseif(strtotime((string)$row['expires_at'].' UTC')<=time()){
            $pdo->prepare("UPDATE food_monster_agent_approval_requests
                SET state='expired',resolved_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
            $out=['state'=>'expired','executed'=>false,
                'answer'=>'This approval expired. No sample was changed.'];
        }elseif($decision==='dismiss'){
            $pdo->prepare("UPDATE food_monster_agent_approval_requests
                SET state='dismissed',resolved_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
            $out=['state'=>'dismissed','executed'=>false,
                'answer'=>'Approval dismissed. No Vision Lab sample was changed.'];
        }else{
            $sample=fm8d10_sample_row($pdo,$org,(string)$row['resource_public_id'],true);
            if(!$sample||!hash_equals((string)$row['resource_fingerprint'],
                fm8d10_fingerprint($org,$sample))
               ||!in_array($sample['review_status'],['pending','needs_adjudication'],true)){
                $pdo->prepare("UPDATE food_monster_agent_approval_requests
                    SET state='stale',resolved_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
                $out=['state'=>'stale','executed'=>false,
                    'answer'=>'The sample changed since the proposal. No decision was applied. Review it again.'];
            }else{
                $payload=json_decode((string)$row['request_json'],true)?:[];
                $result=glasses_vision_lab_review_sample($pdo,$org,(string)$sample['public_id'],[
                    'decision'=>(string)$payload['review_decision'],
                    'canonicalLabel'=>(string)($payload['canonical_label']??''),
                    'notes'=>(string)($payload['notes']??'')],$uid);
                $pdo->prepare("UPDATE food_monster_agent_approval_requests
                    SET state='approved',resolved_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
                app_audit($pdo,$org,$uid,'food_monster.vision_sample_review_approved',
                    'vision_sample',(string)$sample['public_id'],null,
                    ['approvalId'=>$public,'decision'=>$payload['review_decision'],
                     'resultStatus'=>$result['reviewStatus'],'source'=>'signed_in_human_approval']);
                $out=['state'=>'approved','executed'=>true,
                    'answer'=>'Approved and applied review '.$payload['review_decision'].
                        ' to Vision Lab sample '.$sample['public_id'].
                        '. Recorded status: '.$result['reviewStatus'].'.'];
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['ok'=>true,'approvalId'=>$public,'state'=>$out['state'],
        'executed'=>$out['executed'],'answer'=>$out['answer']];
}
function fm8d10_catalogue(array $principal,array $context=[]):array
{
    if(!fm8d10_can_review($principal)
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',(string)($context['threadId']??''))
       ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($context['messageId']??'')))
        return [];
    return ['request_vision_sample_review'=>[
        'description'=>'Prepare an approval request for a specific recorded Vision Lab sample: approve, reject, relabel, or flag for adjudication. Never changes the sample until the signed-in authorized human clicks Approve in Agent Chat; do not say the review is complete after proposing. Requires exact sample ID and clear user instruction.',
        'parameters'=>['type'=>'object','properties'=>[
            'sample_public_id'=>['type'=>'string','description'=>'Exact recorded vision sample ID'],
            'review_decision'=>['type'=>'string','enum'=>['approve','reject','relabel','needs_adjudication']],
            'canonical_label'=>['type'=>'string','description'=>'Required for relabel'],
            'notes'=>['type'=>'string','description'=>'Optional review reason'],
        ],'required'=>['sample_public_id','review_decision'],'additionalProperties'=>false],
        'allowedArguments'=>['sample_public_id','review_decision','canonical_label','notes'],
        'impact'=>'routine',
        'authorize'=>static fn(array $actor,array $args):bool=>fm8d10_can_review($actor),
        'validate'=>static function(array $args,array $actor):array{
            $miss=[];foreach(['sample_public_id','review_decision'] as $f)
                if(trim((string)($args[$f]??''))==='')$miss[]=$f;
            if(($args['review_decision']??'')==='relabel'
               &&trim((string)($args['canonical_label']??''))==='')$miss[]='canonical_label';
            return $miss;
        },
        'execute'=>static fn(array $args,array $actor):array=>
            fm8d10_propose(app_pdo(),$actor,$context,$args),
    ]];
}
