<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/timeclock-voice-core.php';
require_once __DIR__.'/../includes/agent-confirmation-core.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/timeclock-agent-core.php';
require_once __DIR__.'/../includes/timeclock-agent-brain.php';

function tca_fail(string $message,int $code): never { fwrite(STDERR,"FAIL {$code}: {$message}\n"); exit($code); }
function tca_ok(bool $condition,string $message,int $code): void { if(!$condition)tca_fail($message,$code); }

app_boot_session();
$_SESSION['agent_node_pending']=[];
$pdo=app_pdo();
tca_ok(timeclock_agent_ready($pdo),'time-clock schema unavailable',2);

$node=gaw_agent_node('timeclock');
tca_ok(is_array($node),'timeclock node missing from registry',3);
tca_ok(($node['route']??'')==='api/timeclock-agent.php','timeclock node route is wrong',4);
tca_ok(($node['domain']??'')==='timeclock_attendance','timeclock node domain is wrong',5);
tca_ok(($node['mode']??'')==='read_confirmed_write','timeclock node mode is wrong',6);

$pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('Timeclock Agent CI','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);
$loc=(int)$pdo->lastInsertId();

$makeUser=static function(string $email,string $first,string $last) use ($pdo,$org,$loc): int {
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
        ->execute([$email,'x',$first,$last,$first.' '.$last]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")
        ->execute([$org,$id,$loc]);
    return $id;
};
$uid=$makeUser('clock-agent@example.test','Clock','Worker');
$other=$makeUser('noshow-agent@example.test','NoShow','Worker');

$now=time();
$shift=scheduling_shift_save($pdo,$org,[
    'title'=>'Cook','startsAt'=>date('Y-m-d H:i:s',$now-120),'endsAt'=>date('Y-m-d H:i:s',$now+6*3600),
    'userId'=>$uid,'locationId'=>$loc,'breakMinutes'=>30,
],$uid);
tca_ok(!empty($shift['id']),'primary shift setup failed',7);
$noShowShift=scheduling_shift_save($pdo,$org,[
    'title'=>'Prep','startsAt'=>date('Y-m-d H:i:s',$now-30*60),'endsAt'=>date('Y-m-d H:i:s',$now+5*3600),
    'userId'=>$other,'locationId'=>$loc,'breakMinutes'=>30,
],$uid);
tca_ok(!empty($noShowShift['id']),'no-show shift setup failed',8);

$user=[
    'id'=>$uid,'organization_id'=>$org,'display_name'=>'Clock Worker','first_name'=>'Clock','role_slug'=>'staff',
    'permissions'=>['timeclock.agent','timeclock.self','timeclock.view','attendance.view','schedule.self','tasks.self'],
];
$manager=[
    'id'=>$uid,'organization_id'=>$org,'display_name'=>'Clock Manager','first_name'=>'Clock','role_slug'=>'manager',
    'permissions'=>['timeclock.agent','timeclock.view','attendance.view'],
];

$route=gaw_route($user,'Who is clocked in now?');
tca_ok(($route['node']??'')==='timeclock'||($route['route']??'')==='api/timeclock-agent.php','explicit time-clock request did not route to timeclock node',9);
$generic=gaw_route($user,'show order status');
tca_ok(($generic['node']??'')!=='timeclock'&&($generic['route']??'')!=='api/timeclock-agent.php','generic order request was hijacked by timeclock',10);
$latePickup=gaw_route($user,'show late pickup orders');
tca_ok(($latePickup['node']??'')!=='timeclock'&&($latePickup['route']??'')!=='api/timeclock-agent.php','late pickup request was hijacked by timeclock',38);
$reservationNoShow=gaw_route($user,'mark this reservation no-show');
tca_ok(($reservationNoShow['node']??'')!=='timeclock'&&($reservationNoShow['route']??'')!=='api/timeclock-agent.php','reservation no-show request was hijacked by timeclock',39);

$proposal=timeclock_agent_handle($pdo,$user,['message'=>'clock me in']);
tca_ok(!empty($proposal['data']['requiresConfirmation']),'clock-in did not require confirmation',11);
tca_ok(tv_open_clock($pdo,$org,$uid)===null,'clock-in mutated before confirmation',12);
tca_ok(gaw_pending_action_node($org,$uid)==='timeclock','pending node was not timeclock',13);
$confirmed=timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);
tca_ok(($confirmed['skill']??'')==='timeclock.action_confirmed','clock-in confirmation failed',14);
$open=tv_open_clock($pdo,$org,$uid);tca_ok(is_array($open),'confirmed clock-in did not persist',15);

$cancelProposal=timeclock_agent_handle($pdo,$user,['message'=>'start my break']);
tca_ok(!empty($cancelProposal['data']['requiresConfirmation']),'break start did not require confirmation',16);
$cancelled=timeclock_agent_handle($pdo,$user,['message'=>'Cancel']);
tca_ok(($cancelled['skill']??'')==='timeclock.action_cancelled','break cancel failed',17);
$q=$pdo->prepare('SELECT COUNT(*) FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL');$q->execute([(int)$open['id']]);
tca_ok((int)$q->fetchColumn()===0,'cancelled break proposal mutated state',18);

$start=timeclock_agent_handle($pdo,$user,['message'=>'start my break']);
tca_ok(!empty($start['data']['requiresConfirmation']),'second break proposal missing confirmation',19);
timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);
$q=$pdo->prepare('SELECT id FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL LIMIT 1');$q->execute([(int)$open['id']]);$breakId=(int)$q->fetchColumn();
tca_ok($breakId>0,'confirmed break start did not persist',20);

$end=timeclock_agent_handle($pdo,$user,['message'=>'end my break']);
tca_ok(!empty($end['data']['requiresConfirmation']),'break end did not require confirmation',21);
$pdo->prepare('UPDATE time_clock_breaks SET ended_at=NOW(6) WHERE id=?')->execute([$breakId]);
$stale=false;try{timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);}catch(InvalidArgumentException $e){$stale=str_contains($e->getMessage(),'break state changed');}
tca_ok($stale,'stale break proposal was not rejected',22);

// Restore an open break and let the Agent close it through the confirmed lifecycle.
tv_break_action($pdo,$org,$uid,$uid,'start','manual');
$end2=timeclock_agent_handle($pdo,$user,['message'=>'end my break']);
tca_ok(!empty($end2['data']['requiresConfirmation']),'restored break end proposal missing confirmation',23);
timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);
$q=$pdo->prepare('SELECT COUNT(*) FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL');$q->execute([(int)$open['id']]);
tca_ok((int)$q->fetchColumn()===0,'confirmed break end did not persist',24);

$out=timeclock_agent_handle($pdo,$user,['message'=>'clock me out']);
tca_ok(!empty($out['data']['requiresConfirmation']),'clock-out did not require confirmation',25);
timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);
tca_ok(tv_open_clock($pdo,$org,$uid)===null,'confirmed clock-out did not persist',26);

// A clock-out proposal must reject a changed canonical entry.
tv_clock_in($pdo,$org,$uid,$uid,'manual');
$staleOut=timeclock_agent_handle($pdo,$user,['message'=>'clock me out']);
tca_ok(!empty($staleOut['data']['requiresConfirmation']),'stale clock-out setup failed',27);
$clock=tv_open_clock($pdo,$org,$uid);$pdo->prepare('UPDATE time_clock_entries SET updated_at=DATE_ADD(updated_at,INTERVAL 1 SECOND) WHERE id=?')->execute([(int)$clock['id']]);
$staleClock=false;try{timeclock_agent_handle($pdo,$user,['message'=>'Confirm']);}catch(InvalidArgumentException $e){$staleClock=str_contains($e->getMessage(),'time-clock state changed');}
tca_ok($staleClock,'stale clock proposal was not rejected',28);
// Clean up without using the Agent so manager-read tests can proceed predictably.
tv_clock_out($pdo,$org,$uid,$uid,'manual');

$active=timeclock_agent_handle($pdo,$manager,['message'=>'who is clocked in now?','pageContext'=>['module'=>'timeclock','locationId'=>$loc]]);
tca_ok(($active['skill']??'')==='timeclock.active_staff','manager active-staff read failed',29);
$attendance=timeclock_agent_handle($pdo,$manager,['message'=>'show attendance exceptions','pageContext'=>['module'=>'timeclock','locationId'=>$loc,'selectedDate'=>date('Y-m-d')]]);
tca_ok(($attendance['skill']??'')==='attendance.exceptions','attendance exception read failed',30);
tca_ok(count($attendance['data']['noShows']??[])>=1,'current no-show was not surfaced',31);
$labor=timeclock_agent_handle($pdo,$manager,['message'=>'show labor variance','pageContext'=>['module'=>'timeclock','locationId'=>$loc,'selectedDate'=>date('Y-m-d')]]);
tca_ok(($labor['skill']??'')==='attendance.labor','labor variance read failed',32);
$employee=timeclock_agent_handle($pdo,$manager,['message'=>'this employee attendance','pageContext'=>['module'=>'timeclock','selectedUserId'=>$other,'selectedDate'=>date('Y-m-d')]]);
tca_ok(($employee['skill']??'')==='attendance.employee_context'&&($employee['data']['employee']['id']??0)===$other,'selected employee context was not re-resolved canonically',33);

$withoutAgent=$user;$withoutAgent['permissions']=['timeclock.self'];$denied=false;
try{timeclock_agent_handle($pdo,$withoutAgent,['message'=>'my clock status']);}catch(TimeclockAgentPermissionException){$denied=true;}
tca_ok($denied,'missing timeclock.agent permission was not rejected',34);
$readOnly=$manager;$selfDenied=false;
try{timeclock_agent_handle($pdo,$readOnly,['message'=>'clock me in']);}catch(TimeclockAgentPermissionException){$selfDenied=true;}
tca_ok($selfDenied,'manager without timeclock.self could mutate personal time clock',35);

$signals=timeclock_agent_brain_signal_rows($pdo,$manager);
$keys=array_column($signals,'key');
tca_ok(in_array('timeclock:no-shows',$keys,true),'Agent Brain did not receive no-show signal',36);

$audits=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE organization_id={$org} AND action LIKE 'timeclock.agent_action_%'")->fetchColumn();
tca_ok($audits>=6,'Agent proposal/confirmation audit trail is incomplete',37);

echo "timeclockAgent=ok noShows=".count($attendance['data']['noShows'])." brainSignals=".count($signals)." audits={$audits}\n";