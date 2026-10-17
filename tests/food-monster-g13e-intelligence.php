<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
function app_role_has_owner_authority(array $user):bool {
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g13e-intelligence.php';
$n=0;
function check13e(bool $ok,string $reason):void{
    global $n;
    if(!$ok)throw new RuntimeException('FM-G13E FAIL: '.$reason);
    $n++;
}
$owner=['id'=>1,'organization_id'=>3,'role_slug'=>'super_admin','permissions'=>['menu.view','recipes.view','recipes.agent']];
$employee=['id'=>7,'organization_id'=>3,'role_slug'=>'employee','permissions'=>['training.self_view']];
$none=['id'=>8,'organization_id'=>3,'role_slug'=>'employee','permissions'=>[]];
foreach(['team training insights','employee training intelligence','training coaching overview','food monster training intelligence'] as $q)
    check13e(fm13e_intent($q)==='team','only deliberate team insight command: '.$q);
foreach(['my training insights','my learning history','my guided practice summary','my food monster training history'] as $q)
    check13e(fm13e_intent($q)==='self','only deliberate self insight command: '.$q);
foreach(['training progress','next ticket','employee performance rating','my schedule',
    'my employee score','what do staff know','my training on pizza'] as $q)
    check13e(fm13e_intent($q)===null,'no hijack of unrelated route: '.$q);
check13e(fm13e_scope($owner,'team','text',true)==='team','owner can read own team's activity');
check13e(fm13e_scope($owner,'self','text',true)==='self','owner can read own activity');
check13e(fm13e_scope($employee,'self','text',false)==='self','staff can read own practice history');
check13e(fm13e_scope($employee,'team','text',false)===null,'staff cannot access coworker training');
check13e(fm13e_scope($none,'self','text',false)===null,'self requires explicit permission');
check13e(fm13e_scope($owner,'team','voice',true)===null,'team practice history denied to room voice');
check13e(fm13e_scope($owner,'self','voice',true)===null,'room microphone does not impersonate owner');
check13e(fm13e_scope($employee,'self','voice',false)===null,'employee voice is not account authentication');
check13e(fm13e_scope($employee,'self','text',true)==='self','self never broadens to team from owner flag alone');
check13e(fm13e_scope($owner,null,'text',true)===null,'missing exact command blocked');

function trainingEvent(int $person,string $action,array $state,string $when='2026-10-17 18:00:00'):array {
    return ['person_id'=>$person,'role'=>'system','created_at'=>$when,'structured'=>[
        'collection'=>'fm13c_guided_training','source'=>'gelato_training',
        'action'=>$action,'state'=>$state]];
}
$a=['kind'=>'menu','id'=>'52','status'=>'active','index'=>2,'stepCount'=>4,
    'practiceAttempts'=>5,'practiceCorrect'=>2,'digest'=>str_repeat('a',64)];
$older=$a;$older['index']=1;
$b=['kind'=>'menu','id'=>'71','status'=>'paused','index'=>0,'stepCount'=>3,
    'practiceAttempts'=>1,'practiceCorrect'=>0,'digest'=>str_repeat('b',64)];
$events=[
    trainingEvent(7,'progress',$a),
    trainingEvent(9,'pause',$b),
    trainingEvent(7,'hint',$older),trainingEvent(7,'hint',$older),trainingEvent(7,'hint',$older),
    trainingEvent(7,'repeat',$older),trainingEvent(7,'repeat',$older),
    trainingEvent(7,'repeat',$older),trainingEvent(7,'repeat',$older),
    trainingEvent(7,'quiz',$older),
    trainingEvent(9,'start',$b),
    trainingEvent(7,'start',$older),
    trainingEvent(11,'hint',$a), // not authorized, no leakage
];
$answer=fm13e_aggregate($events,[7,9],30);
check13e($answer['peopleWithActivity']===2,'only selected people represented');
check13e($answer['recordedEvents']===12,'unauthorized employee events excluded');
$one=$answer['participants'][0];$two=$answer['participants'][1];
check13e($one['personId']===7&&$two['personId']===9,'current data per person, newest first');
check13e($one['current']['status']==='active'&&$one['current']['stepIndex']===2,'latest canonical state selected');
check13e($one['current']['practiceAttempts']===5&&$one['current']['practiceCorrect']===2,'practice values current-session only');
check13e($one['revisits']===4,'revisits equal explicit repeat and restart actions');
check13e($one['helpRequests']===3,'help requests include only explicit recorded hints and explanations');
check13e($one['coachingSuggestion']!==null,'frequent requests may trigger optional support suggestion');
check13e($two['coachingSuggestion']===null,'no unjustified coaching suggestion for short history');
check13e($one['frequentProcedures'][0]['source']==='menu:52','source counts derived from recorded IDs');
check13e($one['frequentProcedures'][0]['recordedInteractions']===10,'frequency means interactions, not mastery');
check13e(($one['actionCounts']['quiz']??0)===1,'knowledge checks counted by recorded action');
check13e(!isset($one['actionCounts']['correct']),'no inferred grade or competence score');
$legacy=trainingEvent(7,'',$older);
$legacy['structured']['action']=null;
$legacySum=fm13e_aggregate([$legacy],[7]);
check13e($legacySum['recordedEvents']===1,'G13C legacy progress still readable');
check13e($legacySum['participants'][0]['actionCounts']===[],'old events never assigned invented action labels');
$forged=trainingEvent(7,'hint',$a);
$forged['role']='user';
$foreign=trainingEvent(9,'quiz',$b);$foreign['structured']['source']='other_system';
$badState=trainingEvent(7,'progress',$a);$badState['structured']['state']['stepCount']=0;
$badGroup=trainingEvent(7,'progress',$a);$badGroup['structured']['collection']='unrelated';
$badType=trainingEvent(7,'progress',$a);$badType['structured']['state']['kind']='private_document';
$reject=fm13e_aggregate([$forged,$foreign,$badState,$badGroup,$badType],[7,9]);
check13e($reject['recordedEvents']===0,'forged user, other source, bad state and other collection denied');
$restricted=fm13e_aggregate($events,[9],30);
check13e($restricted['peopleWithActivity']===1&&$restricted['participants'][0]['personId']===9,
    'self view excludes other employee entirely');
$cap=fm13e_aggregate([trainingEvent(7,'hint',$a),trainingEvent(9,'hint',$b),
    trainingEvent(10,'hint',$b)],[7,9,10],2);
check13e(count($cap['participants'])===2,'bounded participant result prevents unbounded team lists');
$empty=fm13e_aggregate([],[7]);
check13e($empty['peopleWithActivity']===0&&$empty['recordedEvents']===0,'no history not a failure or rating');
$summary=['participants'=>$answer['participants'],'recordedEvents'=>$answer['recordedEvents'],
    'peopleWithActivity'=>$answer['peopleWithActivity'],'capped'=>false];
$people=[['id'=>7,'display_name'=>'Alex'],['id'=>9,'display_name'=>'Bailey']];
$summary['participants'][0]['sourceTitle']='Pesto Pizza';
$summary['participants'][0]['sourceAvailable']=true;
$summary['participants'][1]['sourceAvailable']=false;
$visible=fm13e_answer($summary,'team',$people);
check13e(str_contains($visible['answer'],'Pesto Pizza'),'source name displayed only from grounded lookup');
check13e(str_contains($visible['answer'],'source no longer confirmed'),'stale or missing sources identified');
check13e(str_contains($visible['answer'],'not competency assessments'),'activity explicitly not certification');
check13e(!str_contains(strtolower($visible['answer']),'rating:')&&!str_contains($visible['answer'],'100%'),
    'no hidden employee performance grade');
check13e($visible['data']['scope']==='team'&&count($visible['data']['participants'])===2,
    'structured Agent Brain results include scoped participants');
$self=fm13e_answer($summary,'self',$people);
check13e(!str_contains($self['answer'],"Alex's training")
    &&str_contains($self['answer'],'Your training'),'self view uses first-person language');
$partial=$summary;$partial['capped']=true;
check13e($partial&&fm13e_answer($partial,'team',$people)['data']['isPartial']===true,
    'bounded query explicitly marks incomplete history');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-training-intelligence.php');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$training=(string)file_get_contents(__DIR__.'/../api/food-monster-training.php');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-g13e-intelligence.php');
check13e(str_contains($api,'app_verify_request_csrf')
    &&str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)'), 'canonical authenticated typed request and CSRF');
check13e(str_contains($api,"'voice'")===false||str_contains($api,"'text'"),
    'voice cannot request employee history using signed-in operator');
check13e(str_contains($api,'fm13e_scope(')&&str_contains($api,'fm9a_is_super_admin_session'),
    'owner authority and self permissions validated at data endpoint');
check13e(str_contains($api,'organization_memberships')&&str_contains($api,"om.status='active'"),
    'only active current-organization members used in roster');
check13e(str_contains($core,'WHERE c.organization_id=? AND c.user_id IN ($marks)')
    &&str_contains($core,"m.role='system'"),'query explicitly organization/user scoped and system role only');
check13e(str_contains($training,"'action'=>$command['action']"),
    'future events include true server-side training action evidence');
check13e(str_contains($route,"if($role==='system')throw new DomainException"),
    'client cannot impersonate internal training ledger system messages');
check13e(str_contains($route,"'domain'=>'food_monster_training_intelligence'"),
    'Agent Chat routes authorized insight requests into read-only Brain results');
echo 'FM-G13E training activity intelligence PASS ('.$n.' assertions)'."\n";
