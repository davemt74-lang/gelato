<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,$user['permissions']??[],true);
}
function app_role_has_owner_authority(array $user):bool {
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g13i-training-impact.php';
$n=0;
function check13i(bool $ok,string $message):void {
    global $n;if(!$ok)throw new RuntimeException('FM-G13I FAIL: '.$message);$n++;
}
$owner=['role_slug'=>'super_admin','permissions'=>['menu.view','menu.manage']];
$employee=['role_slug'=>'employee','permissions'=>['employee.self','training.self_view']];
$unprivileged=['role_slug'=>'employee','permissions'=>[]];
foreach(['my training updates','what changed in my training','review my procedure changes'] as $phrase)
    check13i(fm13i_intent($phrase)==='self','exact self command: '.$phrase);
foreach(['team training updates','team procedure change impact','review changed training procedures'] as $phrase)
    check13i(fm13i_intent($phrase)==='team','exact team command: '.$phrase);
foreach(['next ticket','next step','approve procedure prp-'.str_repeat('a',20),
    'restart training','my employee rating','show the menu','change menu recipe'] as $phrase)
    check13i(fm13i_intent($phrase)===null,'no unrelated tool hijack: '.$phrase);
check13i(fm13i_allowed($owner,'team','text',true),'owner can read authorized team impact');
check13i(fm13i_allowed($owner,'self','text',true),'owner can read own impact');
check13i(!fm13i_allowed($owner,'team','voice',true),'voice cannot read owner team history');
check13i(!fm13i_allowed($employee,'team','text',false),'staff cannot read team changes');
check13i(fm13i_allowed($employee,'self','text',false),'staff can read own changed training');
check13i(!fm13i_allowed($unprivileged,'self','text',false),'missing self rights denied');
check13i(!fm13i_allowed($employee,'self','voice',false),'voice is not a logged-in account');
check13i(!fm13i_allowed($owner,'team','text',false),'without owner authority team changes denied');
$steps=['Stretch dough','Apply approved sauce'];
$material=['kind'=>'menu','id'=>'42','name'=>'Pesto Pizza','steps'=>$steps,'digest'=>'abc123'];
$state=['kind'=>'menu','id'=>'42','index'=>1,'stepCount'=>2,'status'=>'active','digest'=>'abc123'];
$rev=['itemId'=>42,'publishedAt'=>1800000000,'beforeHash'=>'old','afterHash'=>'new'];
$ok=fm13i_assess($state,$material,$rev);
check13i($ok['impact']==='current','equal material digest marks training source current');
check13i($ok['sourceName']==='Pesto Pizza'&&$ok['step']===2,'source and saved current step preserved');
check13i($ok['recordedChange']===true,'recorded edit is attributed only to matching source');
$material['digest']='changed';
$bad=fm13i_assess($state,$material,$rev);
check13i($bad['impact']==='needs_review','updated saved procedure fingerprint triggers review');
check13i(str_contains($bad['recommendation'],'explicitly restart training'),'change does not auto-reset progress');
check13i($bad['trainingStatus']==='active','no student status or skill grade fabricated');
$missing=fm13i_assess($state,null,null);
check13i($missing['impact']==='source_missing','removed/inactive procedure is not presumed current');
check13i($missing['recordedChange']===false,'no evidence is not invented as audit history');
check13i(str_contains($missing['recommendation'],'manager'),'missing source prompts human validation');
$payload=fm13i_summary([$bad,$ok],'self',true);
check13i($payload['data']['affected']===1&&$payload['data']['partial']===true,'affected count and partial notice');
check13i($payload['data']['scope']==='self'&&$payload['data']['readOnly']===true,'self view read-only');
check13i(str_contains($payload['answer'],'No progress was reset'),'answer does not claim automatic learning changes');
$zero=fm13i_summary([], 'team',false);
check13i($zero['data']['affected']===0,'no training means no fabricated affected employees');
$e=['public_id'=>'msg-'.str_repeat('a',20),'role'=>'system','person_id'=>7,
  'created_at'=>'2026-10-17 12:00:00','structured'=>[
   'collection'=>'fm13h_procedure_revision','source'=>'gelato_server',
   'orgId'=>3,'actorId'=>7,'itemId'=>42,'name'=>'Pesto Pizza','origin'=>'menu_manager',
   'before'=>'old complete recipe notes','after'=>'new complete recipe notes',
   'beforeHash'=>hash('sha256','old complete recipe notes'),
   'afterHash'=>hash('sha256','new complete recipe notes'),
   'beforeVersion'=>2,'afterVersion'=>3]];
$e2=$e;$e2['public_id']='msg-'.str_repeat('b',20);
$e2['structured']['itemId']=52;$e2['structured']['beforeVersion']=1;$e2['structured']['afterVersion']=2;
$r=fm13i_revisions([$e,$e2],3,1);
check13i(count($r)===2&&$r[42]['itemId']===42,'audit revisions scoped by menu source');
check13i(count(fm13i_revisions([$e],4,1))===0,'other-tenant history discarded');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-training-impact.php');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
check13i(str_contains($api,'app_verify_request_csrf')
    &&str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)'), 'authenticated canonical chat and CSRF');
check13i(str_contains($api,"'text'")&&str_contains($api,'fm13i_allowed('),
    'only authorized typed requests can read progress');
check13i(str_contains($api,'organization_memberships')
    &&str_contains($api,"om.status='active'"),'SQL scopes to currently active organization members');
check13i(str_contains($api,'($state['."'kind'".']??\'\')!==\'menu\''),'private recipe training excluded from impact view');
check13i(str_contains($api,'fm13c_read_state')&&str_contains($api,'fm13c_fetch_material'),
    'canonical saved state compared to current source');
check13i(str_contains($route,"'domain'=>'food_monster_training_change_impact'"),
    'Agent Chat routes to read-only training impact');
check13i(str_contains($client,'food_monster.training_change_impact')
    &&str_contains($client,'esc(p.recommendation'),'escaped minimal sidebar impact cards');
echo 'FM-G13I training change impact PASS ('.$n.' assertions)'."\n";
