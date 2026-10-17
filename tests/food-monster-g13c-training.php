<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-g13c-training.php';
$n=0;
function check13c(bool $ok,string $desc):void {
    global $n;
    if(!$ok)throw new RuntimeException('FM-G13C FAIL: '.$desc);
    $n++;
}
$commands=[
    'start training on the Pesto Pizza'=>'start',
    'train me on the dough recipe'=>'start',
    'walk me through how to make Pesto Pizza'=>'start',
    'next step'=>'next','training next step'=>'next',
    'repeat step'=>'repeat','say that step again'=>'repeat',
    'previous step'=>'previous','go back one step'=>'previous',
    'pause training'=>'pause','stop training'=>'pause','end training'=>'pause',
    'resume training'=>'resume','where were we in training'=>'resume',
    'restart training'=>'restart','why this step'=>'question',
    'explain the current step'=>'question','what temperature for this step'=>'question'
];
foreach($commands as $spoken=>$action){
    $cmd=fm13c_command($spoken);
    check13c(($cmd['action']??'')===$action,'recognized intentional training command: '.$spoken);
}
foreach(['next ticket','next item','finish order','confirm','approve it','stop listening',
    'add step to the recipe','update training record','start timer',
    'show me sales','show me my schedule','walk me through my CRM pipeline'] as $ordinary){
    check13c(fm13c_command($ordinary)===null,'never intercept operational or privileged command: '.$ordinary);
}
$menu=['id'=>4,'name'=>'Pesto Pizza','preparationNotes'=>
    "Step 1: Stretch dough.\n2. Add pesto.\n3) Bake until fully cooked."];
$material=fm13c_material($menu,'menu');
check13c($material!==null&&count($material['steps'])===3,'menu notes become three recorded steps');
check13c($material['steps'][0]==='Stretch dough.','number markers removed, instructions retained');
check13c($material['steps'][2]==='Bake until fully cooked.','last original step retained');
check13c(fm13c_material(['id'=>9,'name'=>'Empty','preparationNotes'=>''],'menu')===null,'missing prep notes do not fabricate steps');
$recipe=['public_id'=>'recipe-'.str_repeat('d',20),'name'=>'Dough','status'=>'active',
    'instructions_json'=>json_encode([['text'=>'Measure flour'],['text'=>'Add water'],['text'=>'Knead thoroughly']])];
$rMaterial=fm13c_material($recipe,'recipe');
check13c(count($rMaterial['steps'])===3,'recipe uses only stored instruction records');
$recipe['status']='inactive';
check13c(fm13c_material($recipe,'recipe')===null,'inactive recipe cannot be started');
$recipe['status']='active';
$turn=static fn(int $i):string=>'msg-'.str_repeat(dechex($i),20);
$start=fm13c_transition(null,['action'=>'start'],$material,$turn(1),1000);
check13c($start['changed']&&$start['state']['index']===0,'start creates step one');
check13c(str_contains($start['answer'],'Pesto Pizza, step 1 of 3'),'step answer sourced and enumerated');
$state=$start['state'];
$again=fm13c_transition($state,['action'=>'next'],$material,$turn(1),1001);
check13c(!$again['changed']&&$again['state']['index']===0,'replay of saved canonical message cannot advance');
$next=fm13c_transition($state,['action'=>'next'],$material,$turn(2),1002);
check13c($next['changed']&&$next['state']['index']===1,'next advances exactly once');
$repeat=fm13c_transition($next['state'],['action'=>'repeat'],$material,$turn(3),1003);
check13c($repeat['state']['index']===1&&str_contains($repeat['answer'],'Add pesto'),'repeat does not advance');
$pause=fm13c_transition($repeat['state'],['action'=>'pause'],$material,$turn(4),1004);
check13c($pause['state']['status']==='paused','stop training pauses without erasing recorded progress');
$pausedNext=fm13c_transition($pause['state'],['action'=>'next'],$material,$turn(5),1005);
check13c(!$pausedNext['changed']&&$pausedNext['state']['index']===1,'paused training cannot advance');
$resume=fm13c_transition($pause['state'],['action'=>'resume'],$material,$turn(6),1006);
check13c($resume['state']['index']===1&&$resume['state']['status']==='active',
    'resume reopens exactly the saved step');
$question=fm13c_transition($resume['state'],['action'=>'question'],$material,$turn(7),1007);
check13c($question['state']['index']===1&&str_contains($question['answer'],'does not establish additional reasons'),
    'follow-up answer repeats evidence, never invents a reason');
$finish1=fm13c_transition($question['state'],['action'=>'next'],$material,$turn(8),1008);
check13c($finish1['state']['index']===2,'advance to final step');
$finish2=fm13c_transition($finish1['state'],['action'=>'next'],$material,$turn(9),1009);
check13c($finish2['state']['status']==='completed','finishing steps records completed guidance only');
check13c(str_contains($finish2['answer'],'not a skills assessment'),'completion never asserts trained mastery');
$after=fm13c_transition($finish2['state'],['action'=>'next'],$material,$turn(10),1010);
check13c(!$after['changed'],'completed training cannot advance');
$restart=fm13c_transition($finish2['state'],['action'=>'restart'],$material,$turn(11),1011);
check13c($restart['state']['status']==='active'&&$restart['state']['index']===0,
    'completed training can restart only on explicit request');
$edited=$menu;$edited['preparationNotes']="Stretch dough. Add pesto. Bake at 999 degrees.";
$changed=fm13c_material($edited,'menu');
check13c($changed['digest']!==$material['digest'],'changed procedure fingerprint differs');
$stale=fm13c_transition($next['state'],['action'=>'resume'],$changed,$turn(12),1012);
check13c($stale['state']['status']==='paused'&&str_contains($stale['answer'],'changed'),
    'source edits pause training without emitting stale steps');
$gone=fm13c_transition($next['state'],['action'=>'repeat'],null,$turn(13),1013);
check13c($gone['state']['status']==='paused','inactive or deleted source fails closed');
$invalid=fm13c_transition($state,['action'=>'next'],$material,'not-a-saved-message',1015);
check13c(!$invalid['changed'],'invalid canonical turn ID cannot mutate progress');
$staff=['permissions'=>['menu.view']];
$owner=['permissions'=>['menu.view','recipes.view','recipes.agent']];
check13c(fm13b_allowed($staff,'training',false,true),'menu guidance permitted for staff with menu permission');
check13c(!fm13b_allowed($staff,'recipe',false,true),'staff cannot inherit recipe records');
check13c(!fm13b_allowed($owner,'recipe',false,true),'unidentified owner-room voice cannot read private recipe');
check13c(fm13b_allowed($owner,'recipe',true,true),'verified owner may access permitted private recipe');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-training.php');
$route=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
check13c(str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)')&&str_contains($api,'app_verify_request_csrf'),
    'endpoint trusts signed-in canonical chat plus CSRF');
check13c(str_contains($api,'fm9d_person_collections($pdo,$user,$personId)')
    &&str_contains($api,"$"."pending['personMessageId']"),'employee speaker identity verified from canonical mirrored event');
check13c(str_contains($api,'FOR UPDATE')&&str_contains($api,'beginTransaction()'),
    'progress ledger uses serialized transactional writes');
check13c(str_contains($api,"'system','Training progress updated'")
    &&str_contains($api,"'fm13c_guided_training'"),'progress stored in canonical per-person Agent Brain thread');
check13c(str_contains($route,"'route'=>'api/food-monster-training.php?threadId='")
    &&str_contains($route,"'domain'=>'guided_employee_training'"),'explicit route wired into Agent Chat');
echo 'FM-G13C guided employee training PASS ('.$n.' assertions)'."\n";
