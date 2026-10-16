<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $user):bool{return ($user['is_owner_role']??0)===1;}
function app_has_permission(string $permission,array $user):bool{
    return in_array('*',$user['permissions']??[],true)||in_array($permission,$user['permissions']??[],true);
}
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$n=0;
function g9g_check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException("FAIL ".$name);$n++;}
$owner=['id'=>1,'organization_id'=>7,'is_owner_role'=>1,'permissions'=>['*']];
$staff=['id'=>2,'organization_id'=>7,'is_owner_role'=>0,'permissions'=>['*']];
$other=$owner;$other['organization_id']=9;
$proposal=['id'=>'pap-'.str_repeat('a',16),'organizationId'=>7,'userId'=>1,'type'=>'submit_po',
    'payload'=>['purchaseOrderId'=>'po-'.str_repeat('b',20),
        'expected'=>['status'=>'draft','updatedAt'=>'2026-10-16 11:00:00.000000']],
    'expires'=>time()+600];
$signature=fm9g_purchase_signature($proposal);
g9g_check(strlen($signature)===64,'deterministic proposal digest');
$binding=['proposalId'=>$proposal['id'],'signature'=>$signature,
    'purchaseOrderId'=>$proposal['payload']['purchaseOrderId'],'expires'=>$proposal['expires']];
$grant=fm9g_issue_grant($owner,$binding,'thr-1234567890','gap-'.str_repeat('c',16));
g9g_check(!$grant['executed']&&$grant['status']==='approved_for_domain_confirmation','approval never executes');
g9g_check(fm9g_authorize_purchase($owner,$proposal)['approvalId']===$grant['approvalId'],'owner matches exact proposal');
try{fm9g_authorize_purchase($staff,$proposal);g9g_check(false,'employee denied');}
catch(DomainException){g9g_check(true,'employee denied');}
try{fm9g_authorize_purchase($other,$proposal);g9g_check(false,'tenant denied');}
catch(DomainException){g9g_check(true,'tenant denied');}
$changed=$proposal;$changed['id']='pap-'.str_repeat('d',16);
try{fm9g_authorize_purchase($owner,$changed);g9g_check(false,'swapped proposal denied');}
catch(DomainException){g9g_check(true,'swapped proposal denied');}
$changed=$proposal;$changed['payload']['expected']['updatedAt']='2026-10-16 12:00:00';
try{fm9g_authorize_purchase($owner,$changed);g9g_check(false,'stale version denied');}
catch(DomainException){g9g_check(true,'stale version denied');}
$changed=$proposal;$changed['payload']['purchaseOrderId']='po-'.str_repeat('e',20);
try{fm9g_authorize_purchase($owner,$changed);g9g_check(false,'different PO denied');}
catch(DomainException){g9g_check(true,'different PO denied');}
$changed=$proposal;$changed['type']='cancel_po';
try{fm9g_purchase_signature($changed);g9g_check(false,'unsupported execution denied');}
catch(InvalidArgumentException){g9g_check(true,'unsupported execution denied');}
$key=fm9g_grant_key(7,1);
$_SESSION['fm9g_purchase_grants'][$key]['consumed']=true;
try{fm9g_authorize_purchase($owner,$proposal);g9g_check(false,'used token denied');}
catch(DomainException){g9g_check(true,'used token denied');}
$_SESSION['fm9g_purchase_grants'][$key]['consumed']=false;
$_SESSION['fm9g_purchase_grants'][$key]['expires']=time()-1;
try{fm9g_authorize_purchase($owner,$proposal);g9g_check(false,'expired token denied');}
catch(DomainException){g9g_check(true,'expired token denied');}
echo "FM-G9G scoped purchase grant PASS ($n assertions)\n";
