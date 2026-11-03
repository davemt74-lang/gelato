<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/agent-confirmation-core.php';
require_once __DIR__.'/purchasing-agent-core.php';
require_once __DIR__.'/food-monster-g9g-purchase-execution.php';
require_once __DIR__.'/food-monster-g9h-role-execution.php';
require_once __DIR__.'/food-monster-g9i-task-archive.php';

/** Explicit chat grammar only; never accept arbitrary model-generated tool calls. */
function fm9f_approval_chat_intent(string $message):?array {
    $message=trim($message);
    if(preg_match('/^request approval\s*:\s*(major_purchase|permission_change|record_deletion|large_refund)\s*:\s*(.{5,300})$/iu',$message,$m))
        return ['action'=>'propose','kind'=>strtolower($m[1]),'summary'=>trim($m[2])];
    if(preg_match('/^(approve|reject)(?:\s+(?:this|that|the)\s+(?:request|proposal|approval)|\s+approval)?[.!]?$/iu',$message,$m))
        return ['action'=>'decide','decision'=>strtolower($m[1])];
    return null;
}
function fm9f_high_impact_kind(string $kind):string {
    $allowed=['major_purchase','permission_change','record_deletion','large_refund'];
    if(!in_array($kind,$allowed,true))throw new InvalidArgumentException('Unsupported high-impact action.');
    return $kind;
}
function fm9f_approval_propose(array $operator,string $kind,string $summary,string $threadId,?PDO $pdo=null):array {
    if(fm9a_agent_action_decision($operator,'high_impact')!=='approval_required')
        throw new DomainException('Super Admin approval authority required.');
    $kind=fm9f_high_impact_kind($kind);
    $summary=trim($summary);
    if($summary===''||mb_strlen($summary,'UTF-8')>300
        ||!preg_match('/^agent-[a-f0-9]{20}$/D',$threadId))
        throw new InvalidArgumentException('Valid summary and conversation required.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    if(gac_pending_get('food_monster_high_impact',$org,$uid))
        throw new GacConfirmationConflict('Resolve the existing high-impact approval before proposing another.');
    // One executable grant at a time: a generic Confirm must never choose
    // between two unrelated high-impact operations.
    $key=$org.':'.$uid;
    foreach(['fm9g_purchase_grants','fm9h_role_grants','fm9i_task_archive_grants'] as $bucket){
        $grant=$_SESSION[$bucket][$key]??null;
        if(is_array($grant)&&($grant['consumed']??true)===false
            &&(int)($grant['expires']??0)>time())
            throw new DomainException('Finish the previously approved high-impact action before proposing another.');
    }
    $binding=null;
    if($kind==='major_purchase'){
        if(!$pdo)throw new DomainException('A verified purchase-order proposal is required.');
        $binding=fm9g_bind_purchase($pdo,$operator);
        $purchase=pac_pending_get($org,$uid);
        if(!$purchase||($purchase['threadId']??'')!==$threadId||($purchase['hash']??'')==='')
            throw new GacConfirmationConflict('Review the exact purchase proposal in this conversation before requesting approval.');
        $binding['reviewedProposal']=gac_public_proposal($purchase);
        $summary='Submit purchase order '.$binding['orderNumber'].' to '.$binding['vendor']
            .' for $'.number_format($binding['amount'],2).'. Owner note: '.$summary;
    }
    $roleBinding=null;
    if($kind==='permission_change'){
        if(!$pdo)throw new DomainException('Canonical role state is required.');
        $roleBinding=fm9h_prepare_role($pdo,$operator,$summary);
        $summary=$roleBinding['summary'];
    }
    $taskBinding=null;
    if($kind==='record_deletion'){
        if(!$pdo)throw new DomainException('Canonical task state is required.');
        $taskBinding=fm9i_prepare($pdo,$operator,$summary);
        $summary=$taskBinding['summary'];
    }
    $proposal=gac_pending_store('food_monster_high_impact',$org,$uid,$kind,
        ['threadId'=>$threadId,'purchaseBinding'=>$binding,
            'roleBinding'=>$roleBinding,'taskBinding'=>$taskBinding],$summary,600);
    return ['requiresApproval'=>true,'executed'=>false,'proposalId'=>$proposal['id'],
        'kind'=>$kind,'summary'=>$summary,'purchaseOrderId'=>$binding['purchaseOrderId']??null,
        'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires']),'proposal'=>gac_public_proposal($proposal)];
}
function fm9f_approval_decide(array $operator,string $proposalId,string $decision,array $evidence=[]):array {
    if(fm9a_agent_action_decision($operator,'high_impact')!=='approval_required')
        throw new DomainException('Super Admin approval authority required.');
    if(!in_array($decision,['approve','reject'],true))
        throw new InvalidArgumentException('Invalid approval decision.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $pending=gac_pending_reviewed('food_monster_high_impact',$org,$uid,$evidence);
    if(!hash_equals((string)$pending['id'],$proposalId))
        throw new GacConfirmationConflict('The approval ID does not match the reviewed proposal.');
    $pending=gac_pending_claim('food_monster_high_impact',$org,$uid,$evidence);
    $handoff=null;
    try{
    if($decision==='approve'&&($pending['type']??'')==='major_purchase'){
        $binding=(array)($pending['payload']['purchaseBinding']??[]);
        if(!$binding)throw new DomainException('Missing bound purchase proposal; submit a new approval request.');
        $handoff=fm9g_issue_grant($operator,$binding,(string)$pending['payload']['threadId'],$proposalId);
        $handoff['reviewedProposal']=$binding['reviewedProposal']??null;
    }
    if($decision==='approve'&&($pending['type']??'')==='permission_change'){
        $roleBinding=(array)($pending['payload']['roleBinding']??[]);
        if(!$roleBinding)throw new DomainException('Missing verified role change; create a new approval.');
        $handoff=fm9h_issue_grant($operator,$roleBinding,
            (string)$pending['payload']['threadId'],$proposalId);
    }
    if($decision==='approve'&&($pending['type']??'')==='record_deletion'){
        $taskBinding=(array)($pending['payload']['taskBinding']??[]);
        if(!$taskBinding)throw new DomainException('Missing bound task. Create a new approval.');
        $handoff=fm9i_issue_grant($operator,$taskBinding,
            (string)$pending['payload']['threadId'],$proposalId);
    }
    if($handoff&&in_array($pending['type'],['permission_change','record_deletion'],true)){
        $grantBucket=$pending['type']==='permission_change'?'fm9h_role_grants':'fm9i_task_archive_grants';
        $_SESSION[$grantBucket][$org.':'.$uid]['confirmationProposal']=$pending;
        $handoff['reviewedProposal']=gac_public_proposal($pending);
    }
    }finally{gac_pending_clear('food_monster_high_impact',$org,$uid,(string)$pending['id']);}
    // Approval alone never executes a purchase, deletion, refund, or permission change.
    return ['proposalId'=>$proposalId,'decision'=>$decision,'executed'=>false,
        'kind'=>$pending['type'],'summary'=>$pending['summary'],
        'threadId'=>$pending['payload']['threadId'],'handoff'=>$handoff];
}

/** A later typed Confirm must still name the exact approval the owner reviewed. */
function fm9f_verify_grant_review(array $operator,array $grant,array $input): void
{
    gac_reviewed_row(is_array($grant['confirmationProposal']??null)?$grant['confirmationProposal']:null,
        'food_monster_high_impact',(int)$operator['organization_id'],(int)$operator['id'],$input);
}
