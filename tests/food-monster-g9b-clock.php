<?php
declare(strict_types=1);
$database=in_array('--db',$argv??[],true);
if($database)require __DIR__.'/../includes/bootstrap.php';
else {
    function app_role_has_owner_authority(array $u): bool
    {
        return (int)($u['is_owner_role']??0)===1
            || (($u['role_slug']??'')==='super_admin'&&(int)($u['is_system_role']??0)===1);
    }
}
require __DIR__.'/../includes/food-monster-employee-clock.php';
$n=0;
function fm9b_ok(bool $ok,string $label):void
{
    global $n;
    if(!$ok){fwrite(STDERR,"FM-G9B FAIL: ".$label."\n");exit(1);}
    ++$n;
}
foreach(['123456','654321','111111','121212','000000','999999','abc123','12345','1234567',''] as $pin)
    fm9b_ok(!fm9b_valid_pin($pin),'unsafe PIN rejected');
foreach(['907286','201937'] as $pin)fm9b_ok(fm9b_valid_pin($pin),'valid six-digit PIN');
$api=(string)file_get_contents(__DIR__.'/../api/employee-clock.php');
$clock=(string)file_get_contents(__DIR__.'/../includes/food-monster-employee-clock.php');
$popup=(string)file_get_contents(__DIR__.'/../js/food-monster-clock-popup.js');
$time=(string)file_get_contents(__DIR__.'/../timeclock.php');
$food=(string)file_get_contents(__DIR__.'/../food-monster.php');
$canvas=(string)file_get_contents(__DIR__.'/../agent-canvas.php');
$mainAgent=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
$workspace=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
fm9b_ok(str_contains($api,'fm9a_is_super_admin_session($user)'),'Super Admin checked from actual backend session');
fm9b_ok(str_contains($api,'app_verify_request_csrf($in)'),'POST protected by CSRF');
fm9b_ok(str_contains($clock,"tv_clock_in(")&&str_contains($clock,"tv_clock_out("),'canonical time-clock operations reused');
fm9b_ok(str_contains($clock,'FOR UPDATE'),'PIN and shift operations serialized');
fm9b_ok(str_contains($clock,'password_verify(')&&str_contains($clock,'app_password_hash('),'PIN hashed/verified');
fm9b_ok(str_contains($clock,'failures>=5')||str_contains($clock,'$fails>=5'),'wrong PIN lockout');
fm9b_ok(str_contains($clock,"'is_owner_role'")||str_contains($clock,"r.is_owner_role=1"),'owner roles explicitly barred from employee clock');
fm9b_ok(!str_contains($clock,'$_SESSION')&&!str_contains($api,'$_SESSION'),'no employee login session mutation');
fm9b_ok(!str_contains($clock,'fmpin_signin')&&!str_contains($api,'fmpin_signin'),'website login PIN is not used for attendance');
fm9b_ok(str_contains($popup,'gelato-agent-before-send')&&str_contains($popup,'preventDefault'),'natural Agent Chat triggers modal');
fm9b_ok(str_contains($popup,'gelato-employee-clock-changed'),'attendance event emitted');
fm9b_ok(str_contains($popup,'refreshContext'),'Agent Brain context refreshed');
fm9b_ok(str_contains($food,'food-monster-clock-popup.js')&&str_contains($canvas,'food-monster-clock-popup.js')&&str_contains($time,'food-monster-clock-popup.js'),'Food Monster, Agent Canvas and Time Clock load popup');
fm9b_ok(str_contains($mainAgent,'async function refreshContext()')&&str_contains($workspace,"'food_monster_context'"),'context refresh is authenticated on server');
if($database){
    $pdo=app_pdo();
    fm9b_ok(fm9b_ready($pdo),'shift PIN migration available');
    app_boot_session();
    $sessionBefore=$_SESSION;
    $pdo->beginTransaction();
    try{
        $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9B CI','America/Phoenix')");
        $org=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Kitchen']);
        $loc=(int)$pdo->lastInsertId();
        $newPerson=static function(string $label)use($pdo,$org,$loc):array{
            $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
                ->execute(['fm9b-'.bin2hex(random_bytes(5)).'@example.test',app_password_hash('AdminPassword987'),'Shift','Person',$label]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")->execute([$org,$id,$loc]);
            return ['id'=>$id,'membershipId'=>(int)$pdo->lastInsertId()];
        };
        $own=$newPerson('Super Admin');
        $staff=$newPerson('Shift Cook');
        $manager=$newPerson('Shift Manager');
        $visitor=$newPerson('Visitor');
        $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Super Admin','super_admin',1,1)")
            ->execute([$org]);$ownerRole=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Staff','staff',1,0)")
            ->execute([$org]);$staffRole=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Manager','manager',1,0)")
            ->execute([$org]);$managerRole=(int)$pdo->lastInsertId();
        foreach([[$own['membershipId'],$ownerRole],[$staff['membershipId'],$staffRole],[$manager['membershipId'],$managerRole]] as $roles)
            $pdo->prepare('INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)')->execute($roles);
        $owner=['id'=>$own['id'],'organization_id'=>$org,'display_name'=>'Super Admin','is_owner_role'=>1,'role_slug'=>'super_admin','is_system_role'=>1,'permissions'=>['*']];
        $nonowner=['id'=>$staff['id'],'organization_id'=>$org,'is_owner_role'=>0,'role_slug'=>'staff','is_system_role'=>1,'permissions'=>[]];
        fm9b_ok(fm9b_employee($pdo,$org,$staff['id'])!==null,'active employee accepted');
        fm9b_ok(fm9b_employee($pdo,$org,$manager['id'])!==null,'manager is eligible for clocking');
        fm9b_ok(fm9b_employee($pdo,$org,$own['id'])===null,'owner excluded from clock');
        fm9b_ok(fm9b_employee($pdo,$org,$visitor['id'])===null,'unassigned person excluded');
        fm9b_ok(fm9b_employee($pdo,$org+123,$staff['id'])===null,'cross-organization person blocked');
        $roster=fm9b_roster($pdo,$owner);
        fm9b_ok(count($roster)===2,'roster includes exactly active staff and managers');
        fm9b_ok(!isset($roster[0]['email'])&&!isset($roster[0]['pin_hash']),'roster excludes secrets and login data');
        fm9b_ok(!isset($roster[0]['password_hash']),'roster excludes password hashes');
        try{fm9b_roster($pdo,$nonowner);fm9b_ok(false,'employee denied roster');}catch(DomainException){fm9b_ok(true,'employee denied roster');}
        try{fm9b_set_pin($pdo,$nonowner,$staff['id'],'907286','AdminPassword987');fm9b_ok(false,'employee denied PIN setup');}
        catch(DomainException){fm9b_ok(true,'employee denied PIN setup');}
        try{fm9b_set_pin($pdo,$owner,$staff['id'],'907286','incorrect');fm9b_ok(false,'incorrect owner password denied');}
        catch(DomainException){fm9b_ok(true,'incorrect owner password denied');}
        fm9b_set_pin($pdo,$owner,$staff['id'],'907286','AdminPassword987');
        fm9b_set_pin($pdo,$owner,$manager['id'],'201937','AdminPassword987');
        $roster=fm9b_roster($pdo,$owner);
        fm9b_ok((bool)$roster[0]['pinSet']&&(bool)$roster[1]['pinSet'],'both shift credentials available');
        $r=$pdo->prepare("SELECT pin_hash FROM fm_employee_clock_pins WHERE organization_id=? AND user_id=?");
        $r->execute([$org,$staff['id']]);$stored=$r->fetchColumn();
        fm9b_ok($stored!=='907286'&&password_verify('907286',(string)$stored),'only hashed PIN retained');
        try{fm9b_clock($pdo,$owner,$staff['id'],'907286','delete_everything');fm9b_ok(false,'invalid action blocked');}
        catch(InvalidArgumentException){fm9b_ok(true,'invalid action blocked');}
        try{fm9b_clock($pdo,$nonowner,$staff['id'],'907286','clock_in');fm9b_ok(false,'staff cannot become operator');}
        catch(DomainException){fm9b_ok(true,'staff cannot become operator');}
        try{fm9b_clock($pdo,$owner,$own['id'],'907286','clock_in');fm9b_ok(false,'owner clock-in denied');}
        catch(DomainException){fm9b_ok(true,'owner clock-in denied');}
        try{fm9b_clock($pdo,$owner,$staff['id'],'201937','clock_in');fm9b_ok(false,'other employee PIN denied');}
        catch(DomainException){fm9b_ok(true,'other employee PIN denied');}
        $in=fm9b_clock($pdo,$owner,$staff['id'],'907286','clock_in');
        fm9b_ok($in['status']==='clocked_in'&&$in['personId']===$staff['id'],'employee clocked in');
        fm9b_ok(count(fm9a_context($pdo,$owner)['clockedIn']['people'])===1,'Agent Brain sees on-duty employee');
        try{fm9b_clock($pdo,$owner,$staff['id'],'907286','clock_in');fm9b_ok(false,'duplicate clock in denied');}
        catch(InvalidArgumentException){fm9b_ok(true,'duplicate clock in denied');}
        $out=fm9b_clock($pdo,$owner,$staff['id'],'907286','clock_out');
        fm9b_ok($out['status']==='clocked_out','employee clocked out');
        fm9b_ok(fm9a_context($pdo,$owner)['clockedIn']['people']===[],'Agent Brain removes off-duty employee');
        try{fm9b_clock($pdo,$owner,$staff['id'],'907286','clock_out');fm9b_ok(false,'duplicate clock out denied');}
        catch(InvalidArgumentException){fm9b_ok(true,'duplicate clock out denied');}
        fm9b_ok($_SESSION===$sessionBefore,'Super Admin web session remains unchanged');
        for($i=0;$i<5;$i++){
            try{fm9b_clock($pdo,$owner,$manager['id'],'000001','clock_in');fm9b_ok(false,'wrong PIN denied');}
            catch(DomainException){fm9b_ok(true,'incorrect PIN rejected');}
        }
        $q=$pdo->prepare('SELECT locked_until FROM fm_employee_clock_pins WHERE organization_id=? AND user_id=?');
        $q->execute([$org,$manager['id']]);
        fm9b_ok((bool)$q->fetchColumn(),'five failures activate lock');
        try{fm9b_clock($pdo,$owner,$manager['id'],'201937','clock_in');fm9b_ok(false,'PIN lock blocks correct PIN');}
        catch(DomainException){fm9b_ok(true,'PIN lock blocks correct PIN');}
        fm9b_set_pin($pdo,$owner,$manager['id'],'201937','AdminPassword987');
        $managerIn=fm9b_clock($pdo,$owner,$manager['id'],'201937','clock_in');
        fm9b_ok($managerIn['status']==='clocked_in','owner reset clears PIN lock');
        fm9b_ok(fm9a_context($pdo,$owner)['clockedIn']['people'][0]['agentAdministration']===false,'clocked-in manager gets no agent permissions');
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
}
echo "FM-G9B employee shift PIN contract PASS ($n assertions".($database?', database-backed':'').")\n";
