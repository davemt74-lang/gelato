<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/hardening-agent-confirmation-test-support.php';
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,string $encoding='UTF-8'):int{return strlen($s);}}
require __DIR__.'/../includes/food-monster-high-impact-approval.php';
function check_it(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
session_start();
$owner=['id'=>1,'organization_id'=>7,'role_slug'=>'super_admin'];
$staff=['id'=>2,'organization_id'=>7,'role_slug'=>'employee'];
$pdo=new PDO('sqlite::memory:');
hac_test_context($pdo,$owner,['threadId'=>'agent-'.str_repeat('e',20),
    'message'=>'Request approval: large_refund: Review refund proposal']);
try{fm9f_approval_propose($staff,'major_purchase','Buy new oven','agent-'.str_repeat('e',20));check_it(false,'staff rejected');}
catch(DomainException){check_it(true,'staff rejected');}
try{fm9f_approval_propose($owner,'arbitrary_tool','Do anything','agent-'.str_repeat('e',20));check_it(false,'allowlist');}
catch(InvalidArgumentException){check_it(true,'allowlist');}
$p=fm9f_approval_propose($owner,'large_refund','Review refund proposal','agent-'.str_repeat('e',20));
check_it($p['requiresApproval']&&!$p['executed'],'proposal never executes');
$review=hac_test_review($pdo,$owner,'food_monster_high_impact');
try{fm9f_approval_propose($owner,'large_refund','Try replacing prior approval','agent-'.str_repeat('e',20));check_it(false,'pending proposal protected');}
catch(GacConfirmationConflict){check_it(true,'pending proposal protected');}
try{fm9f_approval_decide($staff,$p['proposalId'],'approve');check_it(false,'staff decision denied');}
catch(DomainException){check_it(true,'staff decision denied');}
// Reproduce the evidence-only fixture error without weakening the real guard.
gac_request_context($review,$pdo,$owner);
try{fm9f_approval_decide($owner,$p['proposalId'],'approve',$review);check_it(false,'review evidence alone cannot approve');}
catch(GacConfirmationConflict){check_it(true,'review evidence alone cannot approve');}
check_it((gac_pending_get('food_monster_high_impact',7,1)['id']??null)===$p['proposalId'],
    'missing current decision leaves the approval pending');
$approvalInput=hac_test_context($pdo,$owner,['message'=>'Approve']+$review);
check_it($approvalInput['sourceMessageId']!==$p['proposal']['sourceMessageId'],
    'owner approval uses a new canonical decision turn');
try{fm9f_approval_decide($owner,'gap-incorrect','approve',$approvalInput);check_it(false,'wrong token denied');}
catch(GacConfirmationConflict){check_it(true,'wrong token denied');}
$d=fm9f_approval_decide($owner,$p['proposalId'],'approve',$approvalInput);
check_it($d['decision']==='approve'&&!$d['executed'],'owner approval does not execute tool');
try{fm9f_approval_decide($owner,$p['proposalId'],'approve',$approvalInput);check_it(false,'replay blocked');}
catch(GacConfirmationConflict){check_it(true,'replay blocked');}


// Audit regression: explicit chat parser cannot infer an arbitrary action.
$intent=fm9f_approval_chat_intent('Request approval: major_purchase: Replace the broken oven');
check_it(is_array($intent)&&$intent['action']==='propose'&&$intent['kind']==='major_purchase','owner chat proposal parsed safely');
check_it(fm9f_approval_chat_intent('Request approval: run_php: Execute script')===null,'no generic tool-execution grammar');
check_it(fm9f_approval_chat_intent('What if we approve a refund?')===null,'hypothetical conversation never becomes approval');
check_it(fm9f_approval_chat_intent('Approve')['decision']==='approve','owner decision grammar');
check_it(fm9f_approval_chat_intent('Reject')['decision']==='reject','owner rejection grammar');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-approvals.php');
$workspace=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
check_it(str_contains($api,"(\$last['channel']??'')!=='text'")&&str_contains($api,"voice_event_public_id"),'shared room voice cannot approve high-impact requests');
check_it(str_contains($api,'gaw_messages($pdo,$org,$uid,$thread,1)')
    &&str_contains($api,'app_verify_request_csrf'),'only canonical authenticated chat messages stage approval');
check_it(str_contains($api,"'fm9f_high_impact_decision'")
    &&str_contains($api,"'executed'=>false"),'approval decisions are auditable and nonexecuting');
$domainRouter=(string)file_get_contents(__DIR__.'/../includes/food-monster-b8d11-domain-routing.php');
check_it(str_contains($workspace,'fm9f_approval_chat_intent')
    &&str_contains($domainRouter,"'route'=>'api/food-monster-approvals.php'")
    &&str_contains($domainRouter,"'high_impact_approval'")
    &&str_contains($client,'const delegation=result?.data?.delegation;'),
    'Model-first chat retains the owner-only legacy approval gate');
hac_test_context($pdo,$owner,['threadId'=>'agent-'.str_repeat('e',20),
    'message'=>'Request approval: large_refund: Review a refund proposal']);
$p2=fm9f_approval_propose($owner,'large_refund','Review a refund proposal','agent-'.str_repeat('e',20));
$review=hac_test_review($pdo,$owner,'food_monster_high_impact');
$rejectionInput=hac_test_context($pdo,$owner,['message'=>'Reject']+$review);
$d2=fm9f_approval_decide($owner,$p2['proposalId'],'reject',$rejectionInput);
check_it($d2['decision']==='reject'&&!$d2['executed'],'owner rejection is nonexecuting');
