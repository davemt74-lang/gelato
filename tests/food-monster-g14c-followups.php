<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
require_once __DIR__.'/../includes/food-monster-g14a-pilot.php';
$n=0;
function ok14c(bool $yes,string $why):void{
    global $n;
    if(!$yes)throw new RuntimeException('FM-G14C FAIL: '.$why);
    $n++;
}
$now=time();
$id='pilot-'.str_repeat('a',20);
$turn=static fn(int $n):string=>'msg-'.str_pad(dechex($n),20,'0',STR_PAD_LEFT);
$base=static function(string $action,int $n)use($id,$turn,$now):array{
    return ['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
        'ownerId'=>9,'runId'=>$id,'action'=>$action,'sourceTurnId'=>$turn($n),
        'recordedAt'=>$now];
};
$events=[$base('start',1)];
$failed=$base('record',2);$failed['gate']='microphone';$failed['outcome']='fail';
$failed['evidence']='USB headset stopped reporting audio during the staged test.';
$plan=$base('plan_fix',3);$plan['gate']='microphone';
$plan['note']='Replace the faulty USB connection and retest with controlled speech.';
$passed=$base('record',4);$passed['gate']='microphone';$passed['outcome']='pass';
$passed['evidence']='Repeated microphone start stop and reconnect completed on physical workstation.';
$resolved=$base('resolve_fix',5);$resolved['gate']='microphone';
$resolved['note']='Re-tested three explicit restarts and observed reliable microphone recovery.';
$validPlan=fm14c_intent('plan kitchen pilot fix microphone: '.$plan['note']);
$validResolve=fm14c_intent('resolve kitchen pilot fix microphone: '.$resolved['note']);
ok14c($validPlan['action']==='plan_fix'&&$validPlan['gate']==='microphone','plan grammar');
ok14c($validResolve['action']==='resolve_fix','explicit resolve grammar');
foreach(['kitchen pilot fixes','show kitchen pilot failures','kitchen pilot followups'] as $cmd)
    ok14c((fm14c_intent($cmd)['action']??'')==='status','read-only status alias');
foreach(['plan kitchen pilot fix customer: an unintended update',
    'fix everything','resolve kitchen pilot fix microphone: short',
    'resolve kitchen pilot fix microphone: '.str_repeat('x',501),
    'record kitchen pilot microphone pass: valid evidence note',
    'next ticket','approve procedure prp-'.str_repeat('a',20)] as $cmd)
    ok14c(fm14c_intent($cmd)===null,'nonconforming commands not intercepted');
$initial=fm14a_state($events,$now);
ok14c($initial['followUps']['unresolved']===0,'pilot starts without invented issues');
ok14c(fm14a_validate_action($validPlan,$initial,$turn(6),$events)!==null,
    'cannot plan before recording a field failure');
$events[]=$passed; // A previous pass cannot resolve a later failure.
$events[]=$failed;
$state=fm14a_state($events,$now);
ok14c(count($state['followUps']['issues'])===1,'failed field check opens one tracked issue');
ok14c($state['followUps']['unresolved']===1,'failure counts as unresolved');
ok14c($state['followUps']['issues'][0]['status']==='open','new failure must start open');
ok14c(fm14a_validate_action($validResolve,$state,$turn(7),$events)!==null,
    'cannot resolve after a pass that predates failure');
ok14c(fm14a_validate_action($validPlan,$state,$turn(8),$events)===null,
    'failure may receive a governed repair plan');
$events[]=$plan;$state=fm14a_state($events,$now);
ok14c($state['followUps']['issues'][0]['status']==='planned','planned repair persists');
ok14c($state['followUps']['issues'][0]['plan']===$plan['note'],'exact bounded plan retained');
ok14c(fm14a_validate_action($validResolve,$state,$turn(9),$events)!==null,
    'a plan alone is not successful retest');
$events[]=$passed;$state=fm14a_state($events,$now);
ok14c($state['followUps']['issues'][0]['status']==='ready_for_review',
    'only subsequent pass after repair plan allows resolution');
ok14c(fm14a_validate_action($validResolve,$state,$turn(10),$events)===null,
    'explicit owner resolution accepted after retest');
$events[]=$resolved;$state=fm14a_state($events,$now);
ok14c($state['followUps']['unresolved']===0&&$state['followUps']['resolved']===1,
    'resolution closes the issue but not the whole pilot');
ok14c($state['followUps']['issues'][0]['resolution']===$resolved['note'],
    'resolution is separately reported, not inferred as device certification');
ok14c($state['fieldCertified']===false,'resolved issue never certifies hardware');
ok14c(fm14a_validate_action($validResolve,$state,$turn(11),$events)!==null,
    'duplicate explicit resolve without a new regression is rejected');
$regression=$failed;$regression['sourceTurnId']=$turn(12);
$events[]=$regression;$state=fm14a_state($events,$now);
ok14c($state['followUps']['issues'][0]['status']==='open'
    &&$state['followUps']['unresolved']===1,'later failure reopens a formerly resolved device issue');
ok14c($state['followUps']['issues'][0]['plan']===null,
    'regression requires a new repair plan rather than retaining stale plan');
ok14c(fm14a_validate_action($validPlan,$state,$turn(12),$events)!==null,
    'reusing an already saved canonical turn is denied');
$closed=$base('close',13);$events[]=$closed;
$state=fm14a_state($events,$now);
ok14c(!$state['active']&&$state['followUps']['closed'],
    'pilot closure preserves unresolved issue history');
ok14c(fm14a_validate_action($validPlan,$state,$turn(14),$events)!==null,
    'closed pilot issue is immutable');
$new=$base('start',15);$new['runId']='pilot-'.str_repeat('b',20);$events[]=$new;
$state=fm14a_state($events,$now);
ok14c($state['followUps']['unresolved']===0&&$state['followUps']['issues']===[],
    'new pilot never inherits old unresolved issues');
ok14c($state['followUps']['runId']===$new['runId'],
    'issues are strictly scoped to selected pilot run');
$source=(array)fm14a_events(array_map(static fn($e)=>['role'=>'system','structured'=>$e],$events),9);
ok14c(count($source)===count($events),'server-owned canonical events preserved');
$forged=$failed;$forged['source']='browser';
ok14c(fm14a_events([['role'=>'system','structured'=>$forged]],9)===[],
    'forged browser events never become actionable repair work');
$foreign=$failed;$foreign['ownerId']=27;
ok14c(fm14a_events([['role'=>'system','structured'=>$foreign]],9)===[],
    'another owner cannot inject fixes into pilot');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-pilot.php');
$ui=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
ok14c(str_contains($api,'app_verify_request_csrf')
    &&str_contains($api,'gaw_messages($pdo,$org,$uid,$threadId,1)'),
    'canonical authenticated typed turn and CSRF retained');
ok14c(str_contains($api,'FOR UPDATE')
    &&str_contains($api,'fm14a_validate_action('),'serialized owner membership and replay verification');
ok14c(str_contains($api,'$event['."'note'".']=$intent['."'note'".']'),
    'repair note persists as a server-owned event, not a client-side task');
ok14c(str_contains($route,'fm14a_intent($message)')
    &&str_contains($route,'fm14a_authorized($user,'),
    'existing governed owner router reused, no new authority path');
ok14c(str_contains($ui,'pilotDeviceSummary()')
    &&str_contains($ui,'data.followUps?.issues'),'local device summary and repair plan shown only in pilot panel');
ok14c(!str_contains($api,'pilotDeviceSummary')
    &&!str_contains($api,'voiceDiagnosticsSnapshot'),'local browser diagnostic snapshot never submitted');
echo 'FM-G14C pilot repairs, retests and owner isolation PASS ('.$n.' assertions)'."\n";
