<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,string $encoding='UTF-8'):int{return strlen($s);}}
require __DIR__.'/../includes/food-monster-high-impact-approval.php';
function check_it(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
session_start();
$owner=['id'=>1,'organization_id'=>7,'role_slug'=>'super_admin'];
$staff=['id'=>2,'organization_id'=>7,'role_slug'=>'employee'];
try{fm9f_approval_propose($staff,'major_purchase','Buy new oven','thr-1234567890');check_it(false,'staff rejected');}
catch(DomainException){check_it(true,'staff rejected');}
try{fm9f_approval_propose($owner,'arbitrary_tool','Do anything','thr-1234567890');check_it(false,'allowlist');}
catch(InvalidArgumentException){check_it(true,'allowlist');}
$p=fm9f_approval_propose($owner,'record_deletion','Review record deletion','thr-1234567890');
check_it($p['requiresApproval']&&!$p['executed'],'proposal never executes');
try{fm9f_approval_propose($owner,'large_refund','Try replacing prior approval','thr-1234567890');check_it(false,'pending proposal protected');}
catch(DomainException){check_it(true,'pending proposal protected');}
try{fm9f_approval_decide($staff,$p['proposalId'],'approve');check_it(false,'staff decision denied');}
catch(DomainException){check_it(true,'staff decision denied');}
try{fm9f_approval_decide($owner,'gap-incorrect','approve');check_it(false,'wrong token denied');}
catch(InvalidArgumentException){check_it(true,'wrong token denied');}
$d=fm9f_approval_decide($owner,$p['proposalId'],'approve');
check_it($d['decision']==='approve'&&!$d['executed'],'owner approval does not execute tool');
try{fm9f_approval_decide($owner,$p['proposalId'],'approve');check_it(false,'replay blocked');}
catch(InvalidArgumentException){check_it(true,'replay blocked');}


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
check_it(str_contains($workspace,'fm9f_approval_chat_intent')
    &&str_contains($client,'api/food-monster-approvals.php'),'Agent Chat routes owner approval');
$p2=fm9f_approval_propose($owner,'record_deletion','Review old order removal','thr-1234567890');
$d2=fm9f_approval_decide($owner,$p2['proposalId'],'reject');
check_it($d2['decision']==='reject'&&!$d2['executed'],'owner rejection is nonexecuting');
