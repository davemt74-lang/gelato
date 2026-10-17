<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,(array)($user['permissions']??[]),true);
}
require_once __DIR__.'/../includes/food-monster-g13c-training.php';
$assertions=0;
function check13d(bool $condition,string $label):void {
    global $assertions;
    if(!$condition)throw new RuntimeException('FM-G13D FAIL: '.$label);
    $assertions++;
}
$hex=static fn(int $n):string=>'msg-'.str_repeat(dechex($n),20);
$menu=['id'=>105,'name'=>'Pesto Pizza','sectionNote'=>'Follow the posted station standards.',
    'preparationNotes'=>"Stretch the dough.\nAdd the pesto.\nBake until fully cooked."];
$material=fm13c_material($menu,'menu');
check13d($material!==null&&count($material['steps'])===3,'canonical menu is source of all step text');
$legacy=hash('sha256',json_encode(['menu','105',$material['steps']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
check13d($material['digest']===$legacy,'G13C training sessions remain resumable after G13D update');
check13d(str_contains($material['notes'],'station standards'),'recorded section notes are accessible without inference');
foreach(['quiz me'=>'quiz','check my knowledge'=>'quiz','practice question'=>'quiz',
    'training hint'=>'hint','hint for this step'=>'hint',
    'training progress'=>'progress','my training progress'=>'progress',
    'training answer 1'=>'answer','training answer 2'=>'answer','answer is 3'=>'answer',
    'explain the current step'=>'question'] as $utterance=>$expected){
    $result=fm13c_command($utterance);
    check13d(($result['action']??'')===$expected,'command recognized: '.$utterance);
}
foreach(['answer my payroll question','answer the phone','make 3 pizzas',
    'quiz staff from another store','show me attendance','next ticket',
    'set ingredient price','approve order','check my schedule'] as $notTraining)
    check13d(fm13c_command($notTraining)===null,'no hijack: '.$notTraining);
$start=fm13c_transition(null,['action'=>'start'],$material,$hex(1),10001);
check13d($start['changed']&&$start['state']['index']===0,'guided step one starts');
check13d((int)$start['state']['practiceAttempts']===0,'zero practice attempts initially');
$quiz=fm13c_transition($start['state'],['action'=>'quiz'],$material,$hex(2),10002);
check13d($quiz['changed']&&is_array($quiz['state']['quiz']),'quiz is saved in same canonical state');
check13d(str_contains($quiz['answer'],'Which of these recorded instructions'),'question based only on source steps');
check13d(str_contains($quiz['answer'],'Stretch the dough'),'current recorded step offered');
check13d(str_contains($quiz['answer'],'Add the pesto'),'distractor from another recorded step');
check13d(str_contains($quiz['answer'],'not certification'),'practice explicitly not certified');
$options=fm13d_options($material,0);
check13d(count($options['options'])===3,'quiz with three distinct options');
check13d($options['correct']===2,'quiz correct option is not always choice one');
$retry=fm13c_transition($quiz['state'],['action'=>'quiz'],$material,$hex(2),10003);
check13d(!$retry['changed']&&$retry['state']===$quiz['state'],'same canonical event cannot reopen quiz');
$wrong=fm13c_transition($quiz['state'],['action'=>'answer','choice'=>1],$material,$hex(3),10003);
check13d((int)$wrong['state']['practiceAttempts']===1,'wrong choice increments attempts');
check13d((int)$wrong['state']['practiceCorrect']===0,'wrong choice never increments correct');
check13d(is_array($wrong['state']['quiz']),'first wrong choice leaves check open');
$correct=fm13c_transition($wrong['state'],['action'=>'answer','choice'=>2],$material,$hex(4),10004);
check13d((int)$correct['state']['practiceAttempts']===2,'correct choice records second attempt');
check13d((int)$correct['state']['practiceCorrect']===1,'one correct answer credited as practice');
check13d($correct['state']['quiz']===null,'quiz closes on correct answer');
check13d(str_contains($correct['answer'],'not an assessment or certification'),'correct selection is not certification');
$replay=fm13c_transition($correct['state'],['action'=>'answer','choice'=>2],$material,$hex(4),10005);
check13d(!$replay['changed']&&(int)$replay['state']['practiceCorrect']===1,'replayed answer cannot inflate score');
$summary=fm13c_transition($correct['state'],['action'=>'progress'],$material,$hex(5),10005);
check13d(str_contains($summary['answer'],'Knowledge-check attempts: 2'),'progress cites recorded counts');
check13d(str_contains($summary['answer'],'correct selections: 1'),'progress cites observed in-chat selections');
check13d(str_contains($summary['answer'],'do not establish competency'),'progress does not claim mastery');
check13d((int)$summary['state']['index']===0,'progress does not advance training');
$why=fm13c_transition($summary['state'],['action'=>'question'],$material,$hex(6),10006);
check13d(str_contains($why['answer'],'Stretch the dough'),'follow-up explains current stored instruction');
check13d(str_contains($why['answer'],'station standards'),'follow-up quotes matching source notes');
check13d(str_contains($why['answer'],'does not establish additional reasons'),'missing reasons not invented');
$next=fm13c_transition($why['state'],['action'=>'next'],$material,$hex(7),10007);
check13d($next['state']['index']===1,'next advances after knowledge check');
check13d($next['state']['quiz']===null,'moving to next step clears old quiz');
$quiz2=fm13c_transition($next['state'],['action'=>'quiz'],$material,$hex(8),10008);
check13d($quiz2['state']['quiz']!==null,'quiz available for next step');
$pause=fm13c_transition($quiz2['state'],['action'=>'pause'],$material,$hex(9),10009);
check13d($pause['state']['status']==='paused'&&$pause['state']['quiz']===null,'pause cancels pending quiz');
$resume=fm13c_transition($pause['state'],['action'=>'resume'],$material,$hex(10),10010);
check13d($resume['state']['index']===1&&$resume['state']['status']==='active','resume retains step and attempts');
check13d($resume['state']['practiceAttempts']===2,'resume retains per-person practice progress');
$twoSteps=['kind'=>'menu','id'=>'200','name'=>'Two Step','steps'=>['First instruction','Second instruction'],'digest'=>'two'];
$twoOptions=fm13d_options($twoSteps,0);
check13d(count($twoOptions['options'])===2,'two distinct steps permit two-choice quiz');
$oneStep=['kind'=>'menu','id'=>'201','name'=>'One Step','steps'=>['Only instruction'],'digest'=>'one'];
check13d(fm13d_options($oneStep,0)===null,'one-step procedure never fabricates distractors');
$duplicateSteps=['kind'=>'menu','id'=>'202','name'=>'Duplicate','steps'=>['Do it','do IT'],'digest'=>'dup'];
check13d(fm13d_options($duplicateSteps,0)===null,'identical choices cannot make invalid practice quiz');
$brief=fm13c_transition(null,['action'=>'start'],$oneStep,$hex(11),10011);
$blocked=fm13c_transition($brief['state'],['action'=>'quiz'],$oneStep,$hex(12),10012);
check13d($blocked['state']['quiz']===null&&str_contains($blocked['answer'],'two distinct recorded steps'),
    'insufficient content reported without invented options');
$quiz3=fm13c_transition($resume['state'],['action'=>'quiz'],$material,$hex(13),10013);
$wrongChoice=$quiz3['state']['quiz']['correct']===1?2:1;
$wrong1=fm13c_transition($quiz3['state'],['action'=>'answer','choice'=>$wrongChoice],$material,$hex(14),10014);
$wrong2=fm13c_transition($wrong1['state'],['action'=>'answer','choice'=>$wrongChoice],$material,$hex(15),10015);
check13d($wrong2['state']['quiz']===null,'two wrong tries close check');
check13d(str_contains($wrong2['answer'],'recorded instruction'),'reveal source-only instruction');
check13d($wrong2['state']['practiceCorrect']===1,'wrong attempts cannot increase correct count');
$changed=$material;$changed['steps'][0]='Use a freshly updated, approved instruction.';$changed['digest']='different';
$stale=fm13c_transition($wrong2['state'],['action'=>'quiz'],$changed,$hex(16),10016);
check13d($stale['state']['status']==='paused','editing an existing procedure pauses practice');
$disabled=fm13c_transition($wrong2['state'],['action'=>'progress'],null,$hex(17),10017);
check13d($disabled['state']['status']==='paused','unavailable source does not yield stale progress');
$api=file_get_contents(__DIR__.'/../api/food-monster-training.php');
$router=file_get_contents(__DIR__.'/../api/agent-workspace.php');
check13d(str_contains($api,'app_verify_request_csrf')&&str_contains($api,'gaw_messages($pdo,$org,$uid,$room,1)'),
    'all practice follows existing CSRF and canonical message safeguards');
check13d(str_contains($api,'fm9d_person_collections($pdo,$user,$personId)')
    &&str_contains($api,'FOR UPDATE'),'employee isolation and transactional lock remain intact');
check13d(str_contains($router,"'domain'=>'guided_employee_training'"),'practice uses governed training route');
echo 'FM-G13D adaptive practice dialogue PASS ('.$assertions.' assertions)'."\n";
