<?php
declare(strict_types=1);
$database=in_array('--db',$argv??[],true);
if($database){
    require __DIR__.'/../includes/bootstrap.php';
}else{
    // Mirrors the existing canonical owner-role decision; production uses bootstrap.php.
    function app_role_has_owner_authority(array $role): bool
    {
        return (int)($role['is_owner_role']??0)===1
            || ((string)($role['role_slug']??$role['slug']??'')==='super_admin'
                && (int)($role['is_system_role']??0)===1);
    }
}
require __DIR__.'/../includes/food-monster-identity-authority.php';
$checks=0;
function fm9a_check(bool $ok,string $reason):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"FM-G9A FAIL: ".$reason."\n");exit(1);}
    $checks++;
}
$owner=['id'=>10,'organization_id'=>5,'display_name'=>'Owner','role_slug'=>'super_admin','is_system_role'=>1,'is_owner_role'=>1,'permissions'=>['*']];
$employee=['id'=>20,'organization_id'=>5,'display_name'=>'Manager','role_slug'=>'manager','is_system_role'=>1,'is_owner_role'=>0,'permissions'=>['timeclock.view']];
$fakeOwner=$employee;$fakeOwner['role_slug']='super_admin';$fakeOwner['is_system_role']=0;$fakeOwner['permissions']=['*'];
$legacyOwner=$owner;$legacyOwner['is_owner_role']=0;
fm9a_check(fm9a_is_super_admin_session($owner),'trusted Super Admin session');
fm9a_check(fm9a_is_super_admin_session($legacyOwner),'canonical system Super Admin migration compatibility');
fm9a_check(!fm9a_is_super_admin_session($employee),'manager is not Super Admin');
fm9a_check(!fm9a_is_super_admin_session($fakeOwner),'custom role slug and wildcard cannot elevate');
fm9a_check(!fm9a_is_super_admin_session(['role_slug'=>'super_admin']),'no session is not authority');
foreach(['read','routine'] as $type)
    fm9a_check(fm9a_agent_action_decision($owner,$type)==='delegated','owner-delegated '.$type);
fm9a_check(fm9a_agent_action_decision($owner,'high_impact')==='approval_required','high impact must request owner approval');
foreach(['read','routine','high_impact','delete_everything'] as $type)
    fm9a_check(fm9a_agent_action_decision($employee,$type)==='denied','employee cannot delegate '.$type);
fm9a_check(fm9a_agent_action_decision($owner,'unknown')==='denied','unknown impact denied');
$rows=[
 ['user_id'=>20,'display_name'=>'Cook','clock_public_id'=>'clock-1','clocked_in_at'=>'2026-10-16 08:00:00','shift_title'=>'Prep','location_id'=>1],
 ['user_id'=>20,'display_name'=>'Cook','clock_public_id'=>'clock-duplicate','clocked_in_at'=>'2026-10-16 08:01:00'],
 ['user_id'=>30,'display_name'=>'Manager','clock_public_id'=>'clock-2','clocked_in_at'=>'2026-10-16 08:03:00'],
 ['user_id'=>0,'clock_public_id'=>'clock-invalid'],['user_id'=>35,'clock_public_id'=>''],
];
$normalized=fm9a_clocked_in_people($rows);
fm9a_check(count($normalized)===2,'two canonical people from open clocks, no duplicates');
fm9a_check($normalized[0]['personId']===20&&$normalized[0]['locationId']===1,'existing employee and location reused');
fm9a_check($normalized[0]['websiteAuthenticated']===false,'clock-in is never website login');
fm9a_check($normalized[1]['agentAdministration']===false,'manager clock-in confers no agent administration');
$context=fm9a_build_context($owner,$rows,'available');
fm9a_check($context['operator']['personId']===10&&$context['operator']['clockInRequired']===false,'owner does not clock in');
fm9a_check(count($context['clockedIn']['people'])===2,'owner sees staff on shift');
fm9a_check($context['room']['status']==='not_connected'&&$context['room']['currentSpeaker']===null,'no imaginary room or speaker identity');
fm9a_check($context['agentAuthority']['routine']==='delegated','agent has own routine delegation');
fm9a_check($context['agentAuthority']['highImpact']==='approval_required','super admin approves high-impact actions');
$unavailable=fm9a_build_context($owner,[],'unavailable');
fm9a_check($unavailable['clockedIn']['status']==='unavailable','unknown roster not reported as none working');
$restricted=fm9a_build_context($employee,$rows,'available');
fm9a_check($restricted['clockedIn']['status']==='not_authorized'&&$restricted['clockedIn']['people']===[],'non-owner cannot read organization roster from bootstrap');
fm9a_check($restricted['agentAuthority']['routine']==='denied','employee website session has no delegation');
try{fm9a_build_context(['id'=>10],[],'available');fm9a_check(false,'reject incomplete login');}
catch(InvalidArgumentException){fm9a_check(true,'reject incomplete login');}
$api=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
fm9a_check(str_contains($api,'fm9a_context($pdo,$user)'),'real authenticated Agent Workspace includes context');
fm9a_check(str_contains($api,'app_require_auth()'),'canonical server authentication remains');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-identity-authority.php');
fm9a_check(str_contains($core,'timeclock_agent_active_staff($pdo,(int)$operator[\'organization_id\'],null)'),'roster scoped to authenticated organization');
fm9a_check(!str_contains($core,'$_GET')&&!str_contains($core,'$_POST'),'no untrusted identity or authorization input');
if($database){
    $pdo=app_pdo();$pdo->beginTransaction();
    try{
        $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9A Integration Test','America/Phoenix')");
        $org=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);
        $location=(int)$pdo->lastInsertId();
        $email='fm9a-'.bin2hex(random_bytes(5)).'@example.test';
        $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$email,'x','On','Shift','On Shift']);
        $workerId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")->execute([$org,$workerId,$location]);
        $clockId='fm9a-'.bin2hex(random_bytes(6));
        $pdo->prepare("INSERT INTO time_clock_entries (organization_id,public_id,user_id,clocked_in_at,status,created_by,updated_by) VALUES (?,?,?,NOW(6),'open',?,?)")->execute([$org,$clockId,$workerId,$workerId,$workerId]);
        $dbOwner=$owner;$dbOwner['organization_id']=$org;
        $snapshot=fm9a_context($pdo,$dbOwner);
        fm9a_check($snapshot['clockedIn']['status']==='available','real time-clock tables ready');
        fm9a_check(count($snapshot['clockedIn']['people'])===1,'real active shift read');
        fm9a_check($snapshot['clockedIn']['people'][0]['personId']===$workerId,'real employee id reused');
        fm9a_check($snapshot['clockedIn']['people'][0]['clockPublicId']===$clockId,'real attendance record reused');
        $otherOrg=$dbOwner;$otherOrg['organization_id']=$org+123456;
        fm9a_check(fm9a_context($pdo,$otherOrg)['clockedIn']['people']===[],'other organization cannot read worker');
        $employee['organization_id']=$org;
        fm9a_check(fm9a_context($pdo,$employee)['clockedIn']['people']===[],'non-owner does not read clock-ins');
        $pdo->prepare("UPDATE time_clock_entries SET status='closed',clocked_out_at=NOW(6) WHERE organization_id=? AND user_id=?")->execute([$org,$workerId]);
        fm9a_check(fm9a_context($pdo,$dbOwner)['clockedIn']['people']===[],'clock-out ends staff participation');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
    }
}
echo "FM-G9A identity and authority PASS ($checks checks".($database?', database-backed':'').")\n";
