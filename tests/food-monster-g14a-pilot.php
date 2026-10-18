<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $user):bool{
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g14a-pilot.php';
$n=0;function pass14a(bool $ok,string $label):void{
    global $n;if(!$ok)throw new RuntimeException('FM-G14A FAIL: '.$label);$n++;
}
$owner=['role_slug'=>'super_admin','id'=>7,'organization_id'=>3,'permissions'=>[]];
$employee=['role_slug'=>'employee','id'=>8,'organization_id'=>3,'permissions'=>['agent.manage']];
pass14a(fm14a_authorized($owner,'text'),'owner typed field gate');
pass14a(!fm14a_authorized($owner,'voice'),'shared voice cannot report pilot outcomes');
pass14a(!fm14a_authorized($employee,'text'),'non-owner cannot borrow agent rights');
pass14a(count(fm14a_gates())===7,'seven declared independent pilot field checks');
foreach(['kitchen pilot readiness','kitchen pilot status','show kitchen pilot checks'] as $q)
    pass14a(fm14a_intent($q)['action']==='status','status alias '.$q);
pass14a(fm14a_intent('start kitchen pilot')['action']==='start','explicit start');
pass14a(fm14a_intent('close kitchen pilot')['action']==='close','explicit close');
foreach(array_keys(fm14a_gates()) as $gate){
    $intent=fm14a_intent('record kitchen pilot '.$gate.' pass: Tested in a physical kitchen with observed result.');
    pass14a($intent!==null&&$intent['gate']===$gate&&$intent['outcome']==='pass','known gate '.$gate);
}
foreach(['next ticket','start training','approve kitchen safety','record kitchen pilot camera pass: short',
    'record kitchen pilot health pass: Can hear speech clearly during shift!',
    'all kitchen checks passed','auto certify the kitchen','my shift training refresh',
    'record kitchen pilot camera pass: '.str_repeat('x',501)] as $q)
    pass14a(fm14a_intent($q)===null,'non-pilot or invalid command ignored');
pass14a(!fm14a_evidence_ok('worked fine'),'short non-evidence blocked');
pass14a(!fm14a_evidence_ok("Camera worked correctly.\x00 Secret"),'binary evidence blocked');
pass14a(fm14a_evidence_ok('Observed start and stop in a normal staffed kitchen shift.'),
    'safe descriptive evidence accepted');
$now=1800000000;$id='pilot-'.str_repeat('a',20);
$turn=static fn(int $i):string=>'msg-'.str_pad(dechex($i),20,'0',STR_PAD_LEFT);
$start=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server','ownerId'=>7,
    'action'=>'start','runId'=>$id,'sourceTurnId'=>$turn(1),'recordedAt'=>$now-240];
$events=[$start];
$state=fm14a_state($events,$now);
pass14a($state['active']&&!$state['closed']&&$state['passed']===0,'new pilot starts without fabricated passes');
pass14a(!$state['fieldCertified']&&!$state['allOperatorReportedPass'],'operator reports never certify equipment');
pass14a(fm14a_validate_action(['action'=>'start'],$state,$turn(2),$events)!==null,
    'duplicate active run rejected');
pass14a(fm14a_validate_action(['action'=>'record','gate'=>'camera','evidence'=>'Test evidence long enough.'],$state,$turn(1),$events)!==null,
    'replayed canonical chat source turn rejected');
foreach(array_keys(fm14a_gates()) as $i=>$gate){
    $events[]=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
        'ownerId'=>7,'action'=>'record','runId'=>$id,'sourceTurnId'=>$turn($i+2),
        'gate'=>$gate,'outcome'=>'pass','evidence'=>'Physical test reported by owner for '.$gate.' with real hardware.',
        'recordedAt'=>$now-90];
}
$state=fm14a_state($events,$now);
pass14a($state['passed']===7&&$state['allOperatorReportedPass'],'all recent manual pass reports recognized');
pass14a($state['fieldCertified']===false,'all manual passes never claim field certification');
pass14a($state['items'][0]['evidence']!==null,'operator evidence attached per gate');
$stale=fm14a_state($events,$now+604801);
pass14a($stale['passed']===0&&$stale['stale']===7,
    'old self-reported passes expire after seven days');
$failure=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
    'ownerId'=>7,'action'=>'record','runId'=>$id,'sourceTurnId'=>$turn(20),
    'gate'=>'handoff','outcome'=>'fail',
    'evidence'=>'Speaker handoff was not reliably detected during test.',
    'recordedAt'=>$now-10];
$events[]=$failure;$state=fm14a_state($events,$now);
pass14a($state['passed']===6&&!$state['allOperatorReportedPass'],
    'latest failed handoff overrides an earlier pass');
$closed=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server','ownerId'=>7,
    'action'=>'close','runId'=>$id,'sourceTurnId'=>$turn(21),'recordedAt'=>$now];
$events[]=$closed;$state=fm14a_state($events,$now);
pass14a($state['closed']&&!$state['active'],'closed run locked');
pass14a(fm14a_validate_action(['action'=>'record','gate'=>'camera','evidence'=>'Physical test evidence reported here.'],$state,$turn(22),$events)!==null,
    'closed pilot cannot be modified');
$newId='pilot-'.str_repeat('b',20);
$events[]=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server','ownerId'=>7,
    'action'=>'start','runId'=>$newId,'sourceTurnId'=>$turn(23),'recordedAt'=>$now];
$state=fm14a_state($events,$now);
pass14a($state['active']&&$state['runId']===$newId&&$state['passed']===0,
    'new run does not inherit old hardware passes');
$fixture=static fn(array $meta,string $role='system'):array=>['role'=>$role,'structured'=>$meta];
pass14a(count(fm14a_events(array_map($fixture,$events),7))===count($events),
    'server-owned owner events load from historical audit');
pass14a(fm14a_events([$fixture($start,'user')],7)===[],
    'user-authored structured event cannot forge pilot result');
$foreign=$start;$foreign['ownerId']=99;
pass14a(fm14a_events([$fixture($foreign)],7)===[],'cross-owner event excluded');
$browser=$start;$browser['source']='client';
pass14a(fm14a_events([$fixture($browser)],7)===[],'browser-made claims excluded');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-pilot.php');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
pass14a(str_contains($api,'app_verify_request_csrf')
    &&str_contains($api,'gaw_messages($pdo,$org,$uid,$threadId,1)'),
    'canonical authenticated typed turn required for writing');
pass14a(str_contains($api,'fm14a_authorized($user,')
    &&str_contains($api,'FOR UPDATE'),'owner and row locking protect record writes');
pass14a(str_contains($api,"'fieldCertified']=false"),'API never advertises hardware certification');
pass14a(str_contains($route,'fm14a_intent($message)')
    &&str_contains($route,'fm14a_authorized($user,'),
    'governed typed-only Agent Chat routing');
pass14a(str_contains($client,'food_monster.kitchen_pilot')
    &&str_contains($client,'pilotLocal'),'pilot checklist and transient diagnostics only in sidebar');
pass14a(!str_contains($client,'pilotLocal.personId')
    &&!str_contains($client,'pilotLocal.transcript'),'no speaker identity or transcript stored in diagnostic counters');
echo 'FM-G14A owner pilot policy and operational evidence PASS ('.$n.' assertions)'."\n";
