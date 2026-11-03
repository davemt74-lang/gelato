<?php
declare(strict_types=1);

/** A stale/missing/mismatched review is always a conflict, never a new command. */
final class GacConfirmationConflict extends RuntimeException {}

function gac_key(int $organizationId,int $userId): string
{
    return $organizationId.':'.$userId;
}

/** Request-local context, never model arguments and never a session-wide thread. */
function gac_request_context(array $input,PDO $pdo,array $actor): void
{
    $GLOBALS['gac_request_context']=['input'=>$input,'pdo'=>$pdo,
        'organizationId'=>(int)($actor['organization_id']??0),
        'userId'=>(int)($actor['id']??0),'actor'=>$actor];
}

function gac_session_lock(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())
        throw new GacConfirmationConflict('The confirmation session is unavailable. Reload the conversation.');
}

/** Purchasing and Scheduling retain their existing buckets, with one shared contract. */
function &gac_pending_bucket(string $node): array
{
    if($node==='purchasing'){
        if(!isset($_SESSION['purchasing_agent_pending'])||!is_array($_SESSION['purchasing_agent_pending']))
            $_SESSION['purchasing_agent_pending']=[];
        return $_SESSION['purchasing_agent_pending'];
    }
    if($node==='scheduling'){
        if(!isset($_SESSION['schedule_agent_pending'])||!is_array($_SESSION['schedule_agent_pending']))
            $_SESSION['schedule_agent_pending']=[];
        return $_SESSION['schedule_agent_pending'];
    }
    if(!isset($_SESSION['agent_node_pending'][$node])||!is_array($_SESSION['agent_node_pending'][$node]))
        $_SESSION['agent_node_pending'][$node]=[];
    return $_SESSION['agent_node_pending'][$node];
}

/** Read only: invalid evidence must not consume or erase somebody else's proposal. */
function gac_pending_raw(string $node,int $organizationId,int $userId): ?array
{
    $key=gac_key($organizationId,$userId);
    $bucket=$node==='purchasing'?($_SESSION['purchasing_agent_pending']??[]):
        ($node==='scheduling'?($_SESSION['schedule_agent_pending']??[]):
            ($_SESSION['agent_node_pending'][$node]??[]));
    $row=is_array($bucket)?($bucket[$key]??null):null;
    return is_array($row)?$row:null;
}

function gac_pending_get(string $node,int $organizationId,int $userId): ?array
{
    $row=gac_pending_raw($node,$organizationId,$userId);
    if(!$row||(int)($row['expires']??0)<=time()
        ||(int)($row['organizationId']??0)!==$organizationId
        ||(int)($row['userId']??0)!==$userId
        ||($row['status']??'pending')!=='pending')return null;
    return $row;
}

function gac_canonical_value(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=gac_canonical_value($item);
    return $value;
}

function gac_proposal_hash(array $row): string
{
    $immutable=[];
    foreach(['version','id','node','organizationId','userId','threadId','sourceMessageId',
        'proposalMessageId','messageHash','type','payload','summary','created','expires'] as $key)
        $immutable[$key]=$row[$key]??null;
    return hash('sha256',json_encode(gac_canonical_value($immutable),
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
}

/** Verify an actual saved owner-scoped message, rather than accepting a public-ID shape. */
function gac_canonical_message(PDO $pdo,int $org,int $uid,string $threadId,string $messageId,string $role): array
{
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',$threadId)
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',$messageId))
        throw new GacConfirmationConflict('Use the reviewed proposal in its original conversation.');
    $q=$pdo->prepare('SELECT m.id,m.conversation_id,m.public_id,m.role,m.content,m.structured_json,m.channel,m.voice_event_public_id
        FROM agent_messages m INNER JOIN agent_conversations c ON c.id=m.conversation_id
        WHERE c.organization_id=? AND c.user_id=? AND c.public_id=? AND c.archived_at IS NULL
          AND m.organization_id=? AND m.user_id=? AND m.public_id=? AND m.role=? LIMIT 1');
    $q->execute([$org,$uid,$threadId,$org,$uid,$messageId,$role]);
    $message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message)throw new GacConfirmationConflict('The reviewed proposal is missing or belongs to another conversation.');
    return $message;
}

function gac_creation_binding(int $org,int $uid): array
{
    $context=$GLOBALS['gac_request_context']??[];
    $input=(array)($context['input']??[]);
    if((int)($context['organizationId']??0)!==$org||(int)($context['userId']??0)!==$uid
        ||!(($context['pdo']??null) instanceof PDO))
        throw new GacConfirmationConflict('Create this proposal through your authenticated Agent conversation.');
    $threadId=is_string($input['threadId']??null)?$input['threadId']:'';
    $sourceMessageId=is_string($input['sourceMessageId']??null)?$input['sourceMessageId']:'';
    gac_canonical_message($context['pdo'],$org,$uid,$threadId,$sourceMessageId,'user');
    return ['threadId'=>$threadId,'sourceMessageId'=>$sourceMessageId];
}

/**
 * Resolve the current command from its saved, owner-scoped user turn. Browser
 * voice flags and cached recognition events can never turn room speech into a
 * typed command. Acoustic attribution remains an additional channel check,
 * not authentication: account permissions and explicit private-turn consent
 * still apply independently.
 */
function gac_protected_turn(int $org,int $uid): array
{
    $binding=gac_creation_binding($org,$uid);
    $context=$GLOBALS['gac_request_context'];$input=$context['input'];$pdo=$context['pdo'];
    $message=gac_canonical_message($pdo,$org,$uid,$binding['threadId'],$binding['sourceMessageId'],'user');
    $latest=$pdo->prepare("SELECT id FROM agent_messages WHERE conversation_id=? AND organization_id=? AND user_id=? AND role='user' ORDER BY id DESC LIMIT 1");
    $latest->execute([(int)$message['conversation_id'],$org,$uid]);
    if((int)$latest->fetchColumn()!==(int)$message['id'])
        throw new GacConfirmationConflict('This command is no longer the current conversation turn. Ask again in the original conversation.');
    $saved=trim((string)$message['content']);
    $requested=array_key_exists('message',$input)?trim((string)$input['message']):$saved;
    $voice=($message['channel']??'')==='voice';
    // The model may forward a canonical "yes" as Confirm (or "never mind" as
    // Cancel); it may not substitute a different command or opposite decision.
    if($voice&&!hash_equals($saved,$requested)
        &&!(gac_is_confirm($saved)&&gac_is_confirm($requested))
        &&!(gac_is_cancel($saved)&&gac_is_cancel($requested)))
        throw new GacConfirmationConflict('The command does not match its saved conversation turn.');
    if(!$voice&&!empty($input['voice']))
        throw new GacConfirmationConflict('Record this microphone command as a new spoken conversation turn.');
    $turn=$binding+['channel'=>$voice?'voice':'text',
        'voiceEventId'=>$voice?(string)($message['voice_event_public_id']??''):''];
    if(!$voice)return $turn;
    require_once __DIR__.'/agent-voice-conversation.php';
    require_once __DIR__.'/agent-private-voice.php';
    $event=$turn['voiceEventId'];
    if($event===''||(array_key_exists('voiceEventId',$input)
        &&!hash_equals($event,(string)$input['voiceEventId'])))
        throw new GacConfirmationConflict('This spoken action needs a fresh voice match for this exact command.');
    $speaker=avc_verified_speaker($pdo,$org,(array)($context['actor']??[]),$event);
    if(!$speaker||(int)$speaker['userId']!==$uid)
        throw new GacConfirmationConflict('The current speaker could not be matched to the signed-in employee. Use the typed controls or speak again.');
    $receipt=$pdo->prepare("SELECT COUNT(*) FROM voice_identity_events e
        WHERE e.organization_id=? AND e.session_user_id=? AND e.matched_user_id=? AND e.public_id=?
          AND JSON_EXTRACT(COALESCE(e.metadata_json,'{}'),'$.agentTurnConsumedAt') IS NOT NULL
          AND (SELECT COUNT(*) FROM agent_messages m WHERE m.organization_id=e.organization_id
            AND m.voice_event_public_id=e.public_id AND m.role='user')=1");
    $receipt->execute([$org,$uid,$uid,$event]);
    if((int)$receipt->fetchColumn()!==1
        ||!gavp_was_approved($org,$uid,$turn['threadId'],$turn['sourceMessageId']))
        throw new GacConfirmationConflict('Approve this exact spoken request in the signed-in Agent before changing restaurant data.');
    return $turn;
}

/** One canonical spoken command may stage OR confirm one protected action. */
function gac_claim_protected_turn(int $org,int $uid): array
{
    $turn=gac_protected_turn($org,$uid);
    if($turn['channel']!=='voice')return $turn;
    $pdo=$GLOBALS['gac_request_context']['pdo'];
    $claim=$pdo->prepare("UPDATE voice_identity_events
        SET metadata_json=JSON_SET(COALESCE(metadata_json,'{}'),'$.protectedAgentTurnConsumedAt',NOW(6))
        WHERE organization_id=? AND session_user_id=? AND matched_user_id=? AND public_id=?
          AND event_type='match' AND created_at>=DATE_SUB(NOW(6),INTERVAL 30 SECOND)
          AND JSON_EXTRACT(COALESCE(metadata_json,'{}'),'$.protectedAgentTurnConsumedAt') IS NULL");
    $claim->execute([$org,$uid,$uid,$turn['voiceEventId']]);
    if($claim->rowCount()!==1)
        throw new GacConfirmationConflict('This spoken command is stale or already used. Speak a new command or use the typed controls.');
    return $turn;
}

function gac_pending_store(string $node,int $organizationId,int $userId,string $type,array $payload,string $summary,int $ttl=600): array
{
    if($organizationId<1||$userId<1||!preg_match('/^[a-z][a-z0-9_]{1,80}$/D',$node))
        throw new GacConfirmationConflict('A valid signed-in proposal owner is required.');
    gac_session_lock();
    $existing=gac_pending_raw($node,$organizationId,$userId);
    // Do not replace an unresolved or in-flight proposal, including legacy rows.
    if($existing&&max((int)($existing['expires']??0),(int)($existing['claimExpires']??0))>time())
        throw new GacConfirmationConflict('There is already a pending action for this Agent. Review and Confirm or Cancel it before requesting another.');
    $binding=gac_claim_protected_turn($organizationId,$userId);
    $prefix=$node==='purchasing'?'pap':($node==='scheduling'?'sap':'gap');
    $row=[
        'version'=>2,'id'=>$prefix.'-'.bin2hex(random_bytes(8)),
        'node'=>$node,'organizationId'=>$organizationId,'userId'=>$userId,
        'threadId'=>$binding['threadId'],'sourceMessageId'=>$binding['sourceMessageId'],
        'proposalMessageId'=>'','messageHash'=>'','hash'=>'','status'=>'pending',
        'type'=>$type,'payload'=>$payload,'summary'=>$summary,
        'created'=>time(),'expires'=>time()+max(60,min(1800,$ttl)),
    ];
    $bucket=&gac_pending_bucket($node);
    $bucket[gac_key($organizationId,$userId)]=$row;
    return $row;
}

/** Server-only cancellation/completion. Client decisions must first use claim(). */
function gac_pending_clear(string $node,int $organizationId,int $userId,?string $expectedId=null): void
{
    $row=gac_pending_raw($node,$organizationId,$userId);
    if(!$row||($expectedId!==null&&!hash_equals((string)($row['id']??''),$expectedId)))return;
    $bucket=&gac_pending_bucket($node);
    unset($bucket[gac_key($organizationId,$userId)]);
}

function gac_proposal_answer(array $proposal): string
{
    $suffix=($proposal['node']??'')==='food_monster_high_impact'
        ?'Reply Approve or Reject in this conversation. Approval never executes the underlying action; a separate authorized confirmation is still required.'
        :'Reply Confirm to execute this change, or Cancel to discard it.';
    return (string)$proposal['summary']."\n\n".$suffix;
}

function gac_public_proposal(array $proposal): array
{
    return ['id'=>(string)$proposal['id'],'hash'=>(string)($proposal['hash']??''),
        'node'=>(string)$proposal['node'],'threadId'=>(string)($proposal['threadId']??''),
        'sourceMessageId'=>(string)($proposal['sourceMessageId']??''),
        'messageId'=>(string)($proposal['proposalMessageId']??''),
        'type'=>(string)$proposal['type'],'summary'=>(string)$proposal['summary'],
        'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])];
}

/** Called by canonical message recording before trusting client-supplied display text. */
function gac_pending_for_binding(string $node,int $org,int $uid,string $proposalId,string $threadId): array
{
    gac_session_lock();
    $row=gac_pending_get($node,$org,$uid);
    if(!$row||(int)($row['version']??0)!==2||($row['node']??'')!==$node
        ||!hash_equals((string)$row['id'],$proposalId)
        ||!hash_equals((string)($row['threadId']??''),$threadId))
        throw new GacConfirmationConflict('That proposal is stale, expired, or belongs to another conversation.');
    return $row;
}

/** Bind once, only to the server-canonical proposal text in the same owner's thread. */
function gac_pending_bind_message(PDO $pdo,string $node,int $org,int $uid,string $proposalId,string $threadId,string $messageId): array
{
    $row=gac_pending_for_binding($node,$org,$uid,$proposalId,$threadId);
    if(($row['proposalMessageId']??'')!==''&&!hash_equals((string)$row['proposalMessageId'],$messageId))
        throw new GacConfirmationConflict('This proposal is already bound to a different reviewed message.');
    $message=gac_canonical_message($pdo,$org,$uid,$threadId,$messageId,'agent');
    $structured=json_decode((string)($message['structured_json']??''),true);
    $identity=$structured['proposal']??$structured['data']['proposal']??null;
    if(!is_array($identity)||($identity['id']??'')!==$row['id']
        ||($identity['node']??'')!==$node
        ||!hash_equals(gac_proposal_answer($row),trim((string)$message['content'])))
        throw new GacConfirmationConflict('The saved proposal message does not match the server-reviewed action.');
    $row['proposalMessageId']=$messageId;
    $row['messageHash']=hash('sha256',trim((string)$message['content']));
    $row['hash']=gac_proposal_hash($row);
    $bucket=&gac_pending_bucket($node);
    $bucket[gac_key($org,$uid)]=$row;
    return gac_public_proposal($row);
}

function gac_confirmation_fields(array $input): array
{
    $fields=[];
    foreach(['threadId','proposalId','proposalHash','proposalMessageId'] as $key){
        if(!is_string($input[$key]??null)||$input[$key]==='')
            throw new GacConfirmationConflict('Review the current proposal in this conversation before confirming or cancelling it.');
        $fields[$key]=$input[$key];
    }
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',$fields['threadId'])
        ||!preg_match('/^(?:gap|pap|sap)-[a-f0-9]{16,32}$/D',$fields['proposalId'])
        ||!preg_match('/^[a-f0-9]{64}$/D',$fields['proposalHash'])
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',$fields['proposalMessageId']))
        throw new GacConfirmationConflict('The reviewed proposal identity is invalid. Reload the conversation.');
    return $fields;
}

/** Exact non-consuming lookup for routing and model continuation. */
function gac_pending_reviewed(string $node,int $org,int $uid,array $input,bool $verifyCanonical=true): array
{
    $row=gac_pending_get($node,$org,$uid);
    return gac_reviewed_row($row,$node,$org,$uid,$input,$verifyCanonical);
}

/** Also validates a previously reviewed proposal retained by an owner-approval grant. */
function gac_reviewed_row(?array $row,string $node,int $org,int $uid,array $input,bool $verifyCanonical=true): array
{
    $evidence=gac_confirmation_fields($input);
    if(!$row||(int)($row['version']??0)!==2||($row['node']??'')!==$node
        ||(int)($row['organizationId']??0)!==$org||(int)($row['userId']??0)!==$uid
        ||(int)($row['expires']??0)<=time()||($row['status']??'')!=='pending'
        ||!hash_equals((string)$row['id'],$evidence['proposalId'])
        ||!hash_equals((string)($row['threadId']??''),$evidence['threadId'])
        ||!hash_equals((string)($row['proposalMessageId']??''),$evidence['proposalMessageId'])
        ||!hash_equals((string)($row['hash']??''),$evidence['proposalHash'])
        ||!hash_equals(gac_proposal_hash($row),$evidence['proposalHash']))
        throw new GacConfirmationConflict('That reviewed proposal is stale, expired, already used, or belongs to another conversation.');
    if($verifyCanonical){
        $context=$GLOBALS['gac_request_context']??[];
        if((int)($context['organizationId']??0)!==$org||(int)($context['userId']??0)!==$uid
            ||!(($context['pdo']??null) instanceof PDO))
            throw new GacConfirmationConflict('Verify this proposal through your authenticated Agent conversation.');
        $message=gac_canonical_message($context['pdo'],$org,$uid,$evidence['threadId'],$evidence['proposalMessageId'],'agent');
        $structured=json_decode((string)($message['structured_json']??''),true);
        $identity=$structured['proposal']??$structured['data']['proposal']??null;
        $metadataMatches=is_array($identity);
        if($metadataMatches)foreach(gac_public_proposal($row) as $key=>$value){
            if(($identity[$key]??null)!==$value){$metadataMatches=false;break;}
        }
        if(!$metadataMatches
            ||!hash_equals((string)$row['messageHash'],hash('sha256',trim((string)$message['content']))))
            throw new GacConfirmationConflict('The saved proposal changed. Request and review a new proposal.');
    }
    return $row;
}

/**
 * Persist the one-use claim before side effects, then reacquire the session lock.
 * The claimed row blocks replacement until the domain completes or TTL expires.
 * A lost response, crash, or simultaneous second Confirm can never execute it again.
 */
function gac_pending_claim(string $node,int $org,int $uid,array $input): array
{
    gac_session_lock();
    $row=gac_pending_reviewed($node,$org,$uid,$input);
    $turn=gac_claim_protected_turn($org,$uid);
    if($turn['threadId']!==$row['threadId'])
        throw new GacConfirmationConflict('Confirm the proposal in its original conversation.');
    $bucket=&gac_pending_bucket($node);
    $bucket[gac_key($org,$uid)]['status']='claimed';
    $bucket[gac_key($org,$uid)]['claimExpires']=time()+1800;
    if(!session_write_close())
        throw new GacConfirmationConflict('Could not persist this one-use confirmation. No action was executed.');
    gac_session_lock();
    return $row+['decisionChannel'=>$turn['channel'],'decisionVoiceEventId'=>$turn['voiceEventId'],
        'decisionMessageId'=>$turn['sourceMessageId']];
}

function gac_proposal_result(array $proposal,string $skill,array $sources=[]): array
{
    return ['ok'=>true,'skill'=>$skill,'answer'=>gac_proposal_answer($proposal),
        'data'=>['requiresConfirmation'=>true,'proposal'=>gac_public_proposal($proposal)],
        'sources'=>$sources];
}

function gac_is_confirm(string $message): bool
{
    return preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply)(?:\s+(?:it|that|change|action))?[.!]?$/i',trim($message))===1;
}

function gac_is_cancel(string $message): bool
{
    return preg_match('/^(?:cancel|cancel it|discard|never mind|nevermind|stop)(?:\s+(?:it|that|change|action))?[.!]?$/i',trim($message))===1;
}
