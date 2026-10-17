<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-g13i-training-impact.php';
$n=0;
function check13j(bool $yes,string $label):void {
    global $n;
    if(!$yes)throw new RuntimeException('FM-G13J FAIL: '.$label);
    $n++;
}
$turn=static fn(int $i):string=>'msg-'.str_pad(dechex($i),20,'0',STR_PAD_LEFT);
$oldItem=['id'=>42,'name'=>'Pesto Pizza',
    'preparationNotes'=>"Stretch the dough.\nSpread pesto."];
$newItem=['id'=>42,'name'=>'Pesto Pizza',
    'preparationNotes'=>"Stretch the dough evenly.\nSpread approved pesto.\nBake according to the station procedure."];
$old=fm13c_material($oldItem,'menu');
$new=fm13c_material($newItem,'menu');
check13j($old!==null&&$new!==null,'two recorded source versions available');
check13j($old['digest']!==$new['digest'],'procedure revision changes source fingerprint');
foreach([
    'review updated training'=>'review_update',
    'review revised training'=>'review_update',
    'acknowledge updated training'=>'acknowledge_update',
    'acknowledge revised training'=>'acknowledge_update',
    'start updated training'=>'start_refresh',
    'start revised training'=>'start_refresh'
] as $spoken=>$action)
    check13j((fm13c_command($spoken)['action']??null)===$action,'explicit revised training command: '.$spoken);
foreach(['next ticket','complete ticket','approve procedure prp-'.str_repeat('a',20),
    'acknowledge employee rating','reset all staff training',
    'change recipe','show my schedule'] as $spoken)
    check13j(fm13c_command($spoken)===null,'does not hijack operational request: '.$spoken);
$prior=['status'=>'completed','kind'=>'menu','id'=>'42','digest'=>$old['digest'],
    'index'=>1,'stepCount'=>2,'quiz'=>null,'practiceAttempts'=>4,'practiceCorrect'=>2,
    'updatedAt'=>8000,'lastTurnId'=>$turn(1)];
check13j(fm13j_source_changed($prior,$new),'changed digest for same menu item detected');
check13j(!fm13j_source_changed($prior,$old),'unchanged digest not treated as revision');
check13j(!fm13j_source_changed($prior,null),'missing source not considered safe revision');
$other=$new;$other['id']='99';
check13j(!fm13j_source_changed($prior,$other),'cannot attach another menu item');
$recipe=$new;$recipe['kind']='recipe';
check13j(!fm13j_source_changed($prior,$recipe),'private recipe out of this scope');
$none=fm13c_transition($prior,['action'=>'review_update'],$old,$turn(2),9000);
check13j(!$none['changed']&&$none['state']===$prior,'review cannot create change without actual source drift');
$noAck=fm13c_transition($prior,['action'=>'acknowledge_update'],$new,$turn(3),9001);
check13j(!$noAck['changed']&&$noAck['state']===$prior,'acknowledgement requires prior review');
$noStart=fm13c_transition($prior,['action'=>'start_refresh'],$new,$turn(4),9002);
check13j(!$noStart['changed'],'cannot skip review and acknowledgement');
$review=fm13c_transition($prior,['action'=>'review_update'],$new,$turn(5),9003);
$state=$review['state'];
check13j($review['changed']&&$state['status']==='paused','review saves paused non-certified state');
check13j($state['index']===1&&$state['practiceAttempts']===4,
    'revision preview preserves previous step and old in-chat attempts');
check13j($state['refresh']['priorDigest']===$old['digest']
    &&$state['refresh']['priorStatus']==='completed','previous recorded source and status retained');
check13j($state['refresh']['reviewDigest']===$new['digest'],'preview bound to exact new source hash');
check13j(str_contains($review['answer'],'Stretch the dough evenly.')
    &&str_contains($review['answer'],'3 recorded steps'),'preview is current recorded content');
check13j(str_contains($review['answer'],'not training completion'),'preview does not certify employee');
$wrongAck=fm13c_transition($state,['action'=>'acknowledge_update'],$old,$turn(6),9004);
check13j(!$wrongAck['changed'],'cannot acknowledge an older or mismatched source');
$repeat=fm13c_transition($state,['action'=>'review_update'],$new,$turn(5),9005);
check13j(!$repeat['changed']&&$repeat['state']===$state,'same saved message is idempotent');
$ack=fm13c_transition($state,['action'=>'acknowledge_update'],$new,$turn(7),9006);
check13j($ack['changed']&&$ack['state']['status']==='paused','acknowledging does not start training');
check13j($ack['state']['digest']===$old['digest'],
    'acknowledgement does not replace old training digest or completion history');
check13j($ack['state']['practiceAttempts']===4
    &&$ack['state']['refresh']['ackDigest']===$new['digest'],'old practice remains and exact revision acknowledged');
check13j(str_contains($ack['answer'],'does not confirm you have read every step'),
    'acknowledgement explicitly not evidence of skills');
check13j(fm13j_status($ack['state'],$new)['acknowledgedUpdate'],
    'impact view exposes acknowledged but not started revision');
$ackAgain=fm13c_transition($ack['state'],['action'=>'acknowledge_update'],$new,$turn(8),9007);
check13j(!$ackAgain['changed'],'cannot acknowledge same unchanged revision twice');
$thirdItem=$newItem;$thirdItem['preparationNotes'].="\nConfirm plating visually.";
$third=fm13c_material($thirdItem,'menu');
$stale=fm13c_transition($ack['state'],['action'=>'start_refresh'],$third,$turn(9),9008);
check13j(!$stale['changed'],'a second source edit invalidates pending acknowledgement');
$refresh=fm13c_transition($ack['state'],['action'=>'start_refresh'],$new,$turn(10),9009);
$r=$refresh['state'];
check13j($refresh['changed']&&$r['status']==='active'&&$r['index']===0,
    'acknowledged revised source begins at first updated step');
check13j($r['digest']===$new['digest']&&$r['stepCount']===3,
    'new session locks to current source digest and step count');
check13j($r['practiceAttempts']===0&&$r['practiceCorrect']===0
    &&$r['quiz']===null,'new in-chat practice counters start fresh');
check13j($r['refresh']['priorStepIndex']===1&&$r['refresh']['priorStatus']==='completed',
    'historical progress remains in refresh metadata and append-only records');
check13j(fm13j_status($r,$new)['refreshInProgress'],
    'read-only impact reports active guided revision review');
check13j(!fm13j_status($r,$new)['refreshCompleted'],
    'start is not completion');
$step2=fm13c_transition($r,['action'=>'next'],$new,$turn(11),9010);
check13j($step2['state']['index']===1&&$step2['state']['status']==='active',
    'first next advances along current approved steps');
$step3=fm13c_transition($step2['state'],['action'=>'next'],$new,$turn(12),9011);
check13j($step3['state']['index']===2,'second next shows the last recorded step');
$finish=fm13c_transition($step3['state'],['action'=>'next'],$new,$turn(13),9012);
check13j($finish['state']['status']==='completed'
    &&$finish['state']['refresh']['completedAt']===9012,'guided updated walkthrough completion recorded');
check13j(fm13j_status($finish['state'],$new)['refreshCompleted'],
    'impact view reports finished walkthrough for acknowledged digest only');
check13j(str_contains($finish['answer'],'not a skills assessment or certification'),
    'finished walkthrough does not claim demonstrated competence');
$staleCompleted=fm13j_status($finish['state'],$third);
check13j(!$staleCompleted['refreshCompleted'],'newer unreviewed procedure invalidates completion status');
$replay=fm13c_transition($finish['state'],['action'=>'next'],$new,$turn(13),9013);
check13j(!$replay['changed'],'replayed completion cannot be written twice');
$sourceGone=fm13c_transition($ack['state'],['action'=>'start_refresh'],null,$turn(14),9014);
check13j(!$sourceGone['changed'],'inactive/missing source cannot be acknowledged and restarted');
$noMenu=$prior;$noMenu['kind']='recipe';
check13j(!fm13c_transition($noMenu,['action'=>'review_update'],$recipe,$turn(15),9015)['changed'],
    'private recipe acknowledgements excluded until separate approval model');
$impact=fm13i_assess($ack['state'],$new,null);
check13j($impact['acknowledgedUpdate']&&$impact['impact']==='needs_review',
    'ack status remains needs-review until guided refresh starts');
$doneImpact=fm13i_assess($finish['state'],$new,null);
check13j($doneImpact['impact']==='current'&&$doneImpact['refreshCompleted'],
    'completed refresh becomes current only for exact source');
check13j(str_contains($doneImpact['recommendation'],'not competency certification'),
    'impact summary never upgrades practice to personnel qualification');
$router=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-training.php');
$card=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
check13j(str_contains($router,'fm13c_command($message)')&&str_contains($router,'guided_employee_training'),
    'G13J commands use the existing authenticated guided-training route');
check13j(str_contains($api,'fm9d_person_collections($pdo,$user,$personId)')
    &&str_contains($api,'FOR UPDATE'),'existing speaker/participant isolation and transaction lock preserved');
check13j(str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)')
    &&str_contains($api,'app_verify_request_csrf'),'fresh canonical chat and CSRF required');
check13j(str_contains($api,"'fm13c_guided_training'")&&str_contains($api,"'action'=>".'$command['."'action'".']'),
    'G13J changes appear as canonical person-scoped training events');
check13j(str_contains($card,'p.acknowledgedUpdate')&&str_contains($card,'p.refreshCompleted'),
    'Agent Canvas shows acknowledgement distinct from guided completion');
echo 'FM-G13J source-bound acknowledgement and guided refresh PASS ('.$n.' assertions)'."\n";
