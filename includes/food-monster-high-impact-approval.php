<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/agent-confirmation-core.php';
require_once __DIR__.'/purchasing-agent-core.php';
require_once __DIR__.'/food-monster-g9g-purchase-execution.php';

/**
 * FM-G9F approval staging. This service NEVER executes a high-impact tool.
 * The canonical module still owns its permission checks and actual execution.
 */
/**
 * Explicit, deterministic Agent Chat grammar. Do not let a model invent a
 * proposal kind, permission, target, amount or execution endpoint.
 */
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
    if($summary===''||mb_strlen($summary,'UTF-8')>300||!preg_match('/^thr-[a-zA-Z0-9-]{8,90}$/D',$threadId))
        throw new InvalidArgumentException('Valid summary and conversation required.');
    if(gac_pending_get('food_monster_high_impact',(int)$operator['organization_id'],(int)$operator['id']))
        throw new DomainException('Resolve the existing high-impact approval before proposing another.');
    $binding=null;
    if($kind==='major_purchase'){
        if(!$pdo)throw new DomainException('A verified purchase-order proposal is required.');
        $binding=fm9g_bind_purchase($pdo,$operator);
        $summary='Submit purchase order '.$binding['orderNumber'].' to '.$binding['vendor'].
            ' for 
    return ['requiresApproval'=>true,'executed'=>false,'proposalId'=>$proposal['id'],
        'kind'=>$kind,'summary'=>$summary,'purchaseOrderId'=>$binding['purchaseOrderId']??null,
        'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])];
}
function fm9f_approval_decide(array $operator,string $proposalId,string $decision):array {
    if(fm9a_agent_action_decision($operator,'high_impact')!=='approval_required')
        throw new DomainException('Super Admin approval authority required.');
    if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Invalid approval decision.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $pending=gac_pending_get('food_monster_high_impact',$org,$uid);
    if(!$pending||!hash_equals((string)$pending['id'],$proposalId))
        throw new InvalidArgumentException('Pending approval was not found or has expired.');
    $handoff=null;
    if($decision==='approve'&&($pending['type']??'')==='major_purchase'){
        $binding=(array)($pending['payload']['purchaseBinding']??[]);
        if(!$binding)throw new DomainException('Missing bound purchase proposal; submit a new approval request.');
        $handoff=fm9g_issue_grant($operator,$binding,(string)$pending['payload']['threadId'],$proposalId);
    }
    gac_pending_clear('food_monster_high_impact',$org,$uid);
    // Approval permits the owner to enter the domain-specific confirmation flow;
    // it is not a substitute for that flow and no write action runs here.
    return ['proposalId'=>$proposalId,'decision'=>$decision,'executed'=>false,
        'kind'=>$pending['type'],'summary'=>$pending['summary'],'threadId'=>$pending['payload']['threadId'],
        'handoff'=>$handoff];
}
.number_format($binding['amount'],2).'. Owner note: '.$summary;
    }
    // Stored only under the authenticated Super Admin session; no model or employee grants.
    $proposal=gac_pending_store('food_monster_high_impact',(int)$operator['organization_id'],
        (int)$operator['id'],$kind,['threadId'=>$threadId,'purchaseBinding'=>$binding],$summary,600);
    return ['requiresApproval'=>true,'executed'=>false,'proposalId'=>$proposal['id'],
        'kind'=>$kind,'summary'=>$summary,'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])];
}
function fm9f_approval_decide(array $operator,string $proposalId,string $decision):array {
    if(fm9a_agent_action_decision($operator,'high_impact')!=='approval_required')
        throw new DomainException('Super Admin approval authority required.');
    if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Invalid approval decision.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $pending=gac_pending_get('food_monster_high_impact',$org,$uid);
    if(!$pending||!hash_equals((string)$pending['id'],$proposalId))
        throw new InvalidArgumentException('Pending approval was not found or has expired.');
    gac_pending_clear('food_monster_high_impact',$org,$uid);
    // Approval permits the owner to enter the domain-specific confirmation flow;
    // it is not a substitute for that flow and no write action runs here.
    return ['proposalId'=>$proposalId,'decision'=>$decision,'executed'=>false,
        'kind'=>$pending['type'],'summary'=>$pending['summary'],'threadId'=>$pending['payload']['threadId']];
}
