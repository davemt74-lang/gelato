<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-employee-clock.php';
require_once __DIR__.'/../includes/timeclock-agent-core.php';
/** Test-only barrier: announce the exact point before a real employee lock blocks. */
class HtcLockProbeStatement extends PDOStatement {
    protected function __construct(){}
    public function execute(?array $params=null):bool{
        static $reported=false;
        if(!$reported&&$this->queryString==='SELECT id,status,archived_at FROM users WHERE id=? FOR UPDATE'){
            $reported=true;echo "locking\n";fflush(STDOUT);
        }
        return parent::execute($params);
    }
}
$pdo=app_pdo();
if(($argv[1]??'')==='worker'){
    $in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if(!empty($in['probeEmployeeLock']))$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HtcLockProbeStatement::class]);
    echo "ready\n";fflush(STDOUT);
    try{
        $org=(int)$in['org'];$user=(int)$in['user'];$actor=(int)($in['actor']??$user);
        if($in['action']==='pin_in')fm9b_clock($pdo,$in['owner'],$user,'907286','clock_in');
        elseif($in['action']==='agent_in')timeclock_agent_execute_pending($pdo,['id'=>$user,'organization_id'=>$org,'permissions'=>['timeclock.self']],['id'=>'race-proposal','organizationId'=>$org,'userId'=>$user,'type'=>'clock_in','payload'=>['expectedClockPublicId'=>null,'expectedOpenBreakId'=>null,'source'=>'agent']]);
        elseif($in['action']==='clock_in')tv_clock_in($pdo,$org,$user,$actor);
        elseif($in['action']==='clock_out')tv_clock_out($pdo,$org,$user,$actor);
        else tv_break_action($pdo,$org,$user,$actor,$in['action']==='break_start'?'start':'end');
        echo json_encode(['success'=>true],JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException|DomainException $error){echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
    exit;
}
$checks=0;
function htc_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function htc_value(PDO $pdo,string $sql,array $args=[]):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function htc_reject(callable $operation,string $label):void{try{$operation();}catch(InvalidArgumentException){htc_check(true,$label);return;}throw new RuntimeException($label);}
/** Hold the user row while both independent clients start. The legacy services read stale state before blocking on writes. */
function htc_race(PDO $pdo,int $org,int $user,array $actions,array $extra=[],bool $oneWinner=true):array{
    $processes=[];$results=[];$pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user]);
    try{
        foreach($actions as $action){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start a time-clock worker.');
            fwrite($pipes[0],json_encode(['org'=>$org,'user'=>$user,'action'=>$action]+$extra,JSON_THROW_ON_ERROR));fclose($pipes[0]);
            stream_set_timeout($pipes[1],15);
            if(trim((string)fgets($pipes[1]))!=='ready')throw new RuntimeException('Time-clock worker failed to start.');
            $processes[]=[$process,$pipes];
        }
        $pdo->commit();
        foreach($processes as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            htc_check(proc_close($process)===0,'Race worker failed: '.$errors);
            $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        }
        $processes=[];
        $wins=count(array_filter($results,fn($r)=>$r['success']));
        htc_check($oneWinner?$wins===1:($wins>=1&&$wins<=2),'Competing transition outcomes must match their serialized order: '.implode(',',$actions));
        return $results;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($processes as [$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}
    }
}
/** Role changes commit after an in-flight PIN request has reached the employee lock. */
function htc_pin_role_race(PDO $pdo,int $org,int $user,int $membership,int $ownerRole,array $owner):void{
    $pipes=[];$process=null;$pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user]);
    try{
        $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start a PIN role-change worker.');
        fwrite($pipes[0],json_encode(['org'=>$org,'user'=>$user,'action'=>'pin_in','owner'=>$owner,'probeEmployeeLock'=>true],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        stream_set_timeout($pipes[1],15);
        htc_check(trim((string)fgets($pipes[1]))==='ready','PIN role worker starts.');
        htc_check(trim((string)fgets($pipes[1]))==='locking','PIN request reaches the employee lock before the role change commits.');
        $pdo->prepare('INSERT INTO user_roles(membership_id,role_id) VALUES (?,?)')->execute([$membership,$ownerRole]);
        $pdo->commit();
        $out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        htc_check(proc_close($process)===0,'PIN role-change worker failed: '.$errors);$process=null;
        $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        htc_check($result['success']===false,'A concurrent owner-role assignment invalidates the in-flight employee PIN action.');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        if(is_resource($process))proc_terminate($process);
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process))proc_close($process);
        $pdo->prepare('DELETE FROM user_roles WHERE membership_id=? AND role_id=?')->execute([$membership,$ownerRole]);
    }
}
htc_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','Concurrency acceptance requires MySQL/MariaDB.');
htc_check((int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,'Native prepares must remain enabled.');
$suffix=bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Timeclock hardening '.$suffix]);$org=(int)$pdo->lastInsertId();
$people=[];$memberships=[];$roles=[];
foreach(['owner','worker','other'] as $kind){
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,'Hardening',?,'active')")->execute([$kind.'-'.$suffix.'@example.test',app_password_hash('Timeclock-Hardening!42'),$kind,$kind.' Hardening']);$id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,job_title,status) VALUES (?,?,'Cook','active')")->execute([$org,$id]);$membership=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,?,?)')->execute([$org,$kind,$kind==='owner'?'super_admin':$kind,1,$kind==='owner'?1:0]);$role=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id) VALUES (?,?)')->execute([$membership,$role]);$people[$kind]=$id;$memberships[$kind]=$membership;$roles[$kind]=$role;
}
$user=$people['worker'];$owner=['id'=>$people['owner'],'organization_id'=>$org,'is_owner_role'=>1,'role_slug'=>'super_admin','is_system_role'=>1,'permissions'=>['*']];
fm9b_set_pin($pdo,$owner,$user,'907286','Timeclock-Hardening!42');
$openCount=fn()=>(int)htc_value($pdo,"SELECT COUNT(*) FROM time_clock_entries WHERE organization_id=? AND user_id=? AND status='open' AND clocked_out_at IS NULL",[$org,$user]);
$activeBreaks=fn()=>(int)htc_value($pdo,'SELECT COUNT(*) FROM time_clock_breaks b JOIN time_clock_entries t ON t.id=b.time_clock_entry_id WHERE t.organization_id=? AND t.user_id=? AND b.ended_at IS NULL',[$org,$user]);
$eventCount=fn()=>(int)htc_value($pdo,'SELECT COUNT(*) FROM attendance_events WHERE organization_id=? AND user_id=?',[$org,$user]);
$audits=fn(string $action)=>(int)htc_value($pdo,'SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action=?',[$org,'timeclock.'.$action]);
htc_race($pdo,$org,$user,['clock_in','clock_in']);
htc_check($openCount()===1,'Direct concurrent clock-in creates one open entry.');
htc_check($audits('clock_in')===1,'Only the committed clock-in is audited.');
htc_race($pdo,$org,$user,['break_start','break_start']);
htc_check($activeBreaks()===1,'Concurrent break starts create one active break.');
htc_check($audits('break_start')===1,'Only the committed break start is audited.');
htc_race($pdo,$org,$user,['break_end','break_end']);
htc_check($activeBreaks()===0,'Concurrent break ends close exactly one break.');
htc_check($audits('break_end')===1,'Only the committed break end is audited.');
htc_race($pdo,$org,$user,['clock_out','clock_out']);
htc_check($openCount()===0&&$audits('clock_out')===1,'Concurrent clock-out closes and audits one entry.');
htc_race($pdo,$org,$user,['pin_in','clock_in'],['owner'=>$owner]);
htc_check($openCount()===1,'PIN and direct paths share one active-entry invariant.');
tv_clock_out($pdo,$org,$user,$user);
htc_race($pdo,$org,$user,['agent_in','clock_in']);
htc_check($openCount()===1,'Confirmed agent and direct paths share one active-entry invariant.');
htc_reject(fn()=>tv_clock_in($pdo,$org+100000,$user,$user),'A different tenant cannot clock this employee in.');
htc_check($openCount()===1,'Cross-tenant denial leaves the legitimate entry unchanged.');

// Database errors in the audit must roll back the attendance action, even inside an outer transaction.
$trigger='htc_audit_'.$suffix;
$pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id=$org AND NEW.action IN ('timeclock.break_start','timeclock.clock_out') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='timeclock audit failure fixture'; END IF; END");
try{
    $before=$eventCount();$failed=false;
    try{tv_break_action($pdo,$org,$user,$user,'start');}catch(PDOException){$failed=true;}
    htc_check($failed&&$activeBreaks()===0&&$eventCount()===$before,'Audit failure rolls back break row and attendance event.');
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE organization_memberships SET job_title='Outer transaction marker' WHERE organization_id=? AND user_id=?")->execute([$org,$user]);
    $failed=false;try{tv_break_action($pdo,$org,$user,$user,'start');}catch(PDOException){$failed=true;}
    htc_check($failed&&$pdo->inTransaction(),'Failed nested time-clock action preserves the outer transaction.');
    htc_check(htc_value($pdo,'SELECT job_title FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$user])==='Outer transaction marker','Nested rollback preserves earlier outer writes.');
    htc_check($activeBreaks()===0&&$eventCount()===$before,'Nested failure rolls back only its operation.');
    $pdo->rollBack();
    $failed=false;try{tv_clock_out($pdo,$org,$user,$user);}catch(PDOException){$failed=true;}
    htc_check($failed&&$openCount()===1&&$eventCount()===$before,'Clock-out audit failure preserves open entry and event count.');
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}

// Existing inconsistent history is retained and fails with an actionable manager-correction error.
$duplicate=tv_public_id('legacy');
$pdo->prepare("INSERT INTO time_clock_entries(organization_id,public_id,user_id,clocked_in_at,status,created_by,updated_by) VALUES (?,?,?,DATE_SUB(NOW(6),INTERVAL 1 HOUR),'open',?,?)")->execute([$org,$duplicate,$user,$user,$user]);
$before=$eventCount();htc_reject(fn()=>tv_clock_out($pdo,$org,$user,$user),'Legacy duplicate open rows must require correction.');
htc_check($openCount()===2&&$eventCount()===$before,'Rejected duplicate state preserves historical entries and events.');
tv_correct_entry($pdo,$org,$duplicate,$people['owner'],date('Y-m-d H:i:s',time()-3600),date('Y-m-d H:i:s',time()-1800),'Resolve the isolated legacy duplicate fixture.');
htc_check($openCount()===1,'An explicit audited manager correction resolves one legacy duplicate.');
tv_clock_out($pdo,$org,$user,$user);
htc_check($openCount()===0,'Normal clock-out resumes after explicit reconciliation.');
tv_clock_in($pdo,$org,$user,$user);
htc_race($pdo,$org,$user,['break_start','clock_out'],[],false);
htc_check($openCount()===0&&$activeBreaks()===0,'Racing break start and clock-out cannot leave an active break on a closed entry.');
$before=$eventCount();$beforeAudit=$audits('clock_in');
htc_pin_role_race($pdo,$org,$user,$memberships['worker'],$roles['owner'],$owner);
htc_check($openCount()===0&&$eventCount()===$before&&$audits('clock_in')===$beforeAudit,'Rejected stale PIN eligibility leaves attendance and audits unchanged.');
echo "Time-clock concurrency: PASS ($checks checks; direct, PIN, agent, role changes, duplicate transitions, audit rollback and legacy preservation)\n";
