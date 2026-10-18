<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
    return in_array($permission,$user['permissions']??[],true);
}
function app_role_has_owner_authority(array $user):bool{
    return ($user['role_slug']??'')==='super_admin';
}
require_once __DIR__.'/../includes/food-monster-g13k-training-notice.php';
$count=0;
function assert13k(bool $yes,string $name):void {
    global $count;
    if(!$yes)throw new RuntimeException('FM-G13K FAIL: '.$name);
    $count++;
}
$owner=['id'=>1,'role_slug'=>'super_admin','organization_id'=>7,
    'permissions'=>['voice.agent','training.self_view']];
$employee=['id'=>9,'role_slug'=>'employee','organization_id'=>7,
    'permissions'=>['training.self_view']];
$visitor=['id'=>10,'role_slug'=>'employee','organization_id'=>7,'permissions'=>[]];
$speaker=['personId'=>9,'identityAssurance'=>'provisional_voice_match'];
assert13k(fm13k_eligible($owner,null,'user','text')===1,'owner typed account may see own notice');
assert13k(fm13k_eligible($employee,null,'user','text')===9,'employee typed only sees own notice');
assert13k(fm13k_eligible($visitor,null,'user','text')===null,'unpermitted employee gets no notice');
assert13k(fm13k_eligible($owner,$speaker,'user','voice')===9,
    'server-matched voice participant gets own notice');
assert13k(fm13k_eligible($owner,null,'user','voice')===null,'unknown room voice gets no notice');
assert13k(fm13k_eligible($owner,['personId'=>9,'identityAssurance'=>'camera_match'],'user','voice')===null,
    'camera-only inference cannot deliver private training signal');
assert13k(fm13k_eligible($owner,['personId'=>1,'identityAssurance'=>'provisional_voice_match'],'user','voice')===null,
    'operator identity cannot be reused as another room speaker');
assert13k(fm13k_eligible($employee,$speaker,'user','voice')===null,
    'employee login cannot borrow speaker matching capability');
assert13k(fm13k_eligible($owner,$speaker,'agent','voice')===null,
    'agent output never triggers a participant alert');
assert13k(fm13k_eligible($owner,$speaker,'user','proactive')===null,
    'background events never become speaker authentication');
$noVoice=$owner;$noVoice['permissions']=['training.self_view'];
assert13k(fm13k_eligible($noVoice,$speaker,'user','voice')===null,
    'owner without voice.agent is denied room notices');
$old=fm13c_material(['id'=>41,'name'=>'Pesto Pizza',
    'preparationNotes'=>"Stretch dough.\nApply pesto."],'menu');
$new=fm13c_material(['id'=>41,'name'=>'Pesto Pizza',
    'preparationNotes'=>"Stretch dough evenly.\nApply approved pesto."],'menu');
$state=['kind'=>'menu','id'=>'41','digest'=>$old['digest'],
    'status'=>'paused','stepCount'=>2,'index'=>1,'practiceAttempts'=>4,'practiceCorrect'=>2];
$now=1770000000;
assert13k(fm13k_policy($state,$new,null,9,$now),'changed procedure eligible first time');
assert13k(!fm13k_policy($state,$old,null,9,$now),'same source not eligible');
assert13k(!fm13k_policy($state,null,null,9,$now),'removed source not eligible');
$recipe=$new;$recipe['kind']='recipe';
assert13k(!fm13k_policy($state,$recipe,null,9,$now),'private recipes out of scope');
assert13k(!fm13k_policy($state,$new,null,0,$now),'invalid participant denied');
$previous=['personId'=>9,'sourceDigest'=>$new['digest'],'sentAt'=>$now-3600];
assert13k(!fm13k_policy($state,$new,$previous,9,$now),'same revision suppressed for an hour');
assert13k(!fm13k_policy($state,$new,$previous,9,$now+86399),
    'same source suppressed within one full day');
assert13k(fm13k_policy($state,$new,$previous,9,$now+86400),
    'new day may show gentle reminder if still unacknowledged');
assert13k(fm13k_policy($state,$new,$previous,10,$now),
    'person-specific suppression never leaks across employees');
$acked=$state;$acked['refresh']=['ackDigest'=>$new['digest']];
assert13k(!fm13k_policy($acked,$new,null,9,$now),
    'acknowledged procedure does not keep sending alerts');
$reviewed=$state;$reviewed['refresh']=['reviewDigest'=>$new['digest']];
assert13k(fm13k_policy($reviewed,$new,null,9,$now),
    'review alone may be reminded, without claiming it was certified');
$refresh=$state;$refresh['digest']=$new['digest'];$refresh['status']='active';
$refresh['refresh']=['sourceDigest'=>$new['digest'],'startedAt'=>5000];
assert13k(!fm13k_policy($refresh,$new,null,9,$now),'active refresh has current source');
$latestItem=fm13c_material(['id'=>41,'name'=>'Pesto Pizza',
    'preparationNotes'=>"Stretch dough evenly.\nApply approved pesto.\nCheck oven."],'menu');
assert13k(fm13k_policy($state,$latestItem,$previous,9,$now),
    'newer distinct source digest permits immediate new notice');
$server=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$js=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
assert13k(str_contains($server,'fm13k_try_notice($pdo,$user,$notifyPerson)')
  &&str_contains($server,'fm13k_eligible($user,$personSpeaker,$role,$channel)'),
  'only server selected speaker or typed account receives notice');
assert13k(str_contains($server,'fm13c_command(')
  &&str_contains($server,"'review_update','acknowledge_update','start_refresh'"),
  'review/acknowledge turns are not interrupted with duplicate notices');
assert13k(str_contains($js,'showTrainingUpdateNotice(submitted?.trainingUpdateNotice)'),
    'UI displays server-declared notice only');
assert13k(str_contains($js,'showTrainingUpdateNotice(null)'),
    'UI clears prior speaker notice before the next turn');
assert13k(str_contains($js,"field.value='review updated training'"),
    'only explicit click prepares revised training review request');
assert13k(!str_contains($js,"speak(submitted?.trainingUpdateNotice"),
    'update does not auto-speak in kitchen');
echo 'FM-G13K notice privacy and source-bound cooldown PASS ('.$count.' assertions)'."\n";
