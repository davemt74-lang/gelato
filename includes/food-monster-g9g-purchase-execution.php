<?php
declare(strict_types=1);
/**
 * FM-G9G: one-owner/one-proposal/one-PO execution grant for the existing
 * Purchasing Agent. No payment or PO execution occurs in this module.
 */
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/purchasing-agent-core.php';

function fm9g_purchase_signature(array $proposal):string
{
    if(($proposal['type']??'')!=='submit_po')throw new InvalidArgumentException('Only purchase-order submissions can be bound.');
    $payload=(array)($proposal['payload']??[]);
    $id=(string)($payload['purchaseOrderId']??'');
    $expected=(array)($payload['expected']??[]);
    if(!preg_match('/^po-[A-Za-z0-9-]{8,90}$/D',$id)
        ||!isset($expected['status'],$expected['updatedAt'])
        ||!is_string($proposal['id']??null))throw new InvalidArgumentException('Incomplete purchase-order proposal.');
    return hash('sha256',json_encode([
        (int)($proposal['organizationId']??0),(int)($proposal['userId']??0),
        (string)$proposal['id'],$id,(string)$expected['status'],(string)$expected['updatedAt']
    ],JSON_THROW_ON_ERROR));
}

function fm9g_bind_purchase(PDO $pdo,array $operator):array
{
    if(!fm9a_is_super_admin_session($operator)
        ||!app_has_permission('purchasing.view',$operator)
        ||!app_has_permission('purchasing.agent',$operator)
        ||!app_has_permission('purchasing.manage',$operator))
        throw new DomainException('Super Admin purchasing permissions required.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $proposal=pac_pending_get($org,$uid);
    if(!$proposal||($proposal['type']??'')!=='submit_po')
        throw new DomainException('Prepare a specific purchase-order submission in Purchasing Agent first.');
    $signature=fm9g_purchase_signature($proposal);
    $po=purchasing_order($pdo,$org,(string)$proposal['payload']['purchaseOrderId']);
    if(!$po||($po['status']??'')!=='draft')
        throw new InvalidArgumentException('Selected purchase order is no longer a draft.');
    pac_guard_order($po,(array)$proposal['payload']['expected']);
    return [
        'proposalId'=>(string)$proposal['id'],
        'signature'=>$signature,
        'purchaseOrderId'=>(string)$po['public_id'],
        'orderNumber'=>(string)$po['order_number'],
        'vendor'=>(string)$po['vendor_name'],
        'amount'=>(float)$po['total_amount'],
        'expires'=>(int)$proposal['expires'],
    ];
}

function fm9g_grant_key(int $org,int $uid):string{return $org.':'.$uid;}
function fm9g_issue_grant(array $operator,array $binding,string $threadId,string $approvalId):array
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin required.');
    if(!preg_match('/^gap-[a-f0-9]{16}$/D',$approvalId))throw new InvalidArgumentException('Invalid approval reference.');
    $expires=min((int)($binding['expires']??0),time()+600);
    if($expires<=time())throw new InvalidArgumentException('Original purchase proposal expired.');
    $receipt=[
        'approvalId'=>$approvalId,'proposalId'=>(string)$binding['proposalId'],
        'signature'=>(string)$binding['signature'],'purchaseOrderId'=>(string)$binding['purchaseOrderId'],
        'threadId'=>$threadId,'expires'=>$expires,'consumed'=>false,
    ];
    $_SESSION['fm9g_purchase_grants'][fm9g_grant_key((int)$operator['organization_id'],(int)$operator['id'])]=$receipt;
    return ['approvalId'=>$approvalId,'purchaseOrderId'=>$receipt['purchaseOrderId'],
        'expiresAt'=>date(DATE_ATOM,$expires),'status'=>'approved_for_domain_confirmation','executed'=>false];
}
function fm9g_authorize_purchase(array $operator,array $proposal):array
{
    if(!fm9a_is_super_admin_session($operator)
        ||!app_has_permission('purchasing.manage',$operator))throw new DomainException('Super Admin approval required to submit a purchase order.');
    $key=fm9g_grant_key((int)$operator['organization_id'],(int)$operator['id']);
    $grant=$_SESSION['fm9g_purchase_grants'][$key]??null;
    if(!is_array($grant)||($grant['consumed']??true)!==false||(int)($grant['expires']??0)<=time())
        throw new DomainException('This purchase order requires current Super Admin approval in Agent Chat.');
    $expected=fm9g_purchase_signature($proposal);
    if(!hash_equals((string)$grant['signature'],$expected)
        ||!hash_equals((string)$grant['proposalId'],(string)$proposal['id'])
        ||!hash_equals((string)$grant['purchaseOrderId'],(string)$proposal['payload']['purchaseOrderId']))
        throw new DomainException('Approval is for a different or changed purchase-order proposal.');
    return $grant;
}

/** Record only an observed, successful domain response; never claim it ran early. */
function fm9g_record_purchase_result(PDO $pdo,array $operator,array $grant,string $purchaseOrderId):bool
{
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $key=fm9g_grant_key($org,$uid);
    if(!isset($_SESSION['fm9g_purchase_grants'][$key])||
        !hash_equals((string)$grant['purchaseOrderId'],$purchaseOrderId))return false;
    // Consume before receipt writing: a failed receipt write must not allow re-execution.
    $_SESSION['fm9g_purchase_grants'][$key]['consumed']=true;
    try{
        $thread=(string)$grant['threadId'];
        if(!gaw_thread($pdo,$org,$uid,$thread))throw new RuntimeException('Approval conversation unavailable.');
        gaw_append($pdo,$org,$uid,$thread,'system','Purchasing Agent submitted approved purchase order '.$purchaseOrderId.'.',[
            'channel'=>'proactive','structured'=>[
                'collection'=>'fm9g_high_impact_execution',
                'kind'=>'major_purchase','state'=>'executed','executed'=>true,
                'approvalId'=>$grant['approvalId'],'proposalId'=>$grant['proposalId'],
                'purchaseOrderId'=>$purchaseOrderId,'actorUserId'=>$uid,
                'source'=>'verified_purchasing_agent_submit'
            ]]);
        app_audit($pdo,$org,$uid,'food_monster.high_impact_executed','purchase_order',$purchaseOrderId,null,
            ['approvalId'=>$grant['approvalId'],'proposalId'=>$grant['proposalId'],'source'=>'purchasing_agent']);
        return true;
    }catch(Throwable $e){
        error_log('FM-G9G purchase submitted but receipt persistence failed: '.get_class($e));
        return false;
    }
}
