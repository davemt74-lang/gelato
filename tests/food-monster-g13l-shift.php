<?php
declare(strict_types=1);
function app_has_permission(string $p,array $u):bool{return in_array($p,$u['permissions']??[],true);}
function app_role_has_owner_authority(array $u):bool{return ($u['role_slug']??'')==='super_admin';}
require __DIR__.'/../includes/food-monster-g13l-shift-refresh.php';
$n=0;function check13l(bool $ok,string $why):void{
    global $n;if(!$ok)throw new RuntimeException('FM-G13L FAIL: '.$why);$n++;
}
$admin=['id'=>1,'organization_id'=>3,'role_slug'=>'super_admin',
    'permissions'=>['menu.view','menu.manage','timeclock.view','training.self_view']];
$noClock=$admin;$noClock['permissions']=['menu.view','menu.manage'];
$staff=['id'=>7,'organization_id'=>3,'role_slug'=>'employee',
    'permissions'=>['training.self_view','timeclock.self']];
$noSelf=$staff;$noSelf['permissions']=['timeclock.self'];
$manager=['id'=>8,'organization_id'=>3,'role_slug'=>'manager',
    'permissions'=>['menu.view','menu.manage','timeclock.view']];
foreach(['my shift training updates','my shift training refresh'] as $cmd)
    check13l(fm13l_intent($cmd)==='self','self command recognized: '.$cmd);
foreach(['team shift training updates','team shift training refreshes','shift training refresh overview'] as $cmd)
    check13l(fm13l_intent($cmd)==='team','team-only command recognized: '.$cmd);
foreach(['next ticket','review updated training','team training updates','clock in',
    'approve procedure prp-'.str_repeat('a',20),'show the cash drawer','restart training'] as $cmd)
    check13l(fm13l_intent($cmd)===null,'unrelated kitchen and training commands unaffected');
check13l(fm13l_allowed($admin,'team','text'),'owner with required clock and menu visibility can view team');
check13l(!fm13l_allowed($noClock,'team','text'),'owner without time-clock visibility cannot view shifts');
check13l(!fm13l_allowed($manager,'team','text'),'manager role cannot borrow Super Admin team authority');
check13l(!fm13l_allowed($admin,'team','voice'),'shared voice never reads team shift history');
check13l(fm13l_allowed($staff,'self','text'),'employee with self permissions can see own update');
check13l(!fm13l_allowed($noSelf,'self','text'),'self clock permission alone cannot read training');
check13l(!fm13l_allowed($staff,'team','text'),'employee cannot obtain team shift overview');
check13l(!fm13l_allowed($staff,'self','voice'),'unverified or voice turn cannot read private shift report');
foreach(['on_shift'=>false,'on_break'=>true,'off_shift'=>true,'unavailable'=>true] as $state=>$allow)
    check13l(fm13l_notice_window(['state'=>$state])===$allow,
        'optional notices safe for shift state '.$state);
$sample=[
    ['impact'=>'needs_review','shiftState'=>'on_shift'],
    ['impact'=>'needs_review','shiftState'=>'on_break'],
    ['impact'=>'source_missing','shiftState'=>'off_shift'],
    ['impact'=>'current','shiftState'=>'on_shift'],
    ['impact'=>'needs_review','shiftState'=>'unavailable']
];
$sum=fm13l_shift_summary($sample,true);
check13l($sum['outstanding']===4,'only mismatched/missing sources are outstanding');
check13l($sum['deferredUntilBreak']===1,'active production shift deferral counted');
check13l($sum['reviewOpportunity']===3,'off-shift and break opportunities shown but optional');
check13l($sum['byShiftState']['on_shift']===2&&$sum['byShiftState']['on_break']===1,
    'actual shift status counts are distinct from source quality');
check13l($sum['partial']===true&&$sum['readOnly']===true,'partial and read-only flags explicit');
check13l(str_contains($sum['note'],'not overdue tasks'),'outstanding is not disciplinary grading');
$none=fm13l_shift_summary([],false);
check13l($none['outstanding']===0&&$none['deferredUntilBreak']===0,'no affected people does not invent tasks');
$notice=(string)file_get_contents(__DIR__.'/../includes/food-monster-g13k-training-notice.php');
$endpoint=(string)file_get_contents(__DIR__.'/../api/food-monster-training-impact.php');
$router=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=(string)file_get_contents(__DIR__.'/../js/agent-canvas.js');
check13l(str_contains($notice,'fm13l_notice_window($shift)')
    &&str_contains($notice,'fm13l_shift_context($pdo,$org,$personId)'),
    'G13K notice gates by server-read tenant/person clock');
check13l(str_contains($notice,'$pdo->commit();return null;'),
    'during-production suppression leaves notification ledger unchanged');
check13l(str_contains($endpoint,'fm13l_allowed($user,$intent')
    &&str_contains($endpoint,'fm13l_shift_summary($list,$partial)'),
    'typed permission-gated API provides source-backed team counts');
check13l(str_contains($endpoint,'fm13c_read_state($pdo,$org,$personId,$thread)'),
    'manager view only inspects saved person-scoped training state');
check13l(str_contains($router,'fm13l_intent($message)')
    &&str_contains($router,'fm13l_allowed($user,$shiftTrainingIntent'),
    'Agent Chat route does not bypass team clock permissions');
check13l(str_contains($endpoint,"'food_monster.shift_training_refresh'"),
    'shift view reports dedicated read-only skill');
check13l(str_contains($client,'food_monster.shift_training_refresh')
    &&str_contains($client,'esc(p.shiftState'),
    'existing Agent Canvas escapes server-approved shift labels');
echo 'FM-G13L shift-aware training review PASS ('.$n.' assertions)'."\n";
