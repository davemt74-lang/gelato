<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/hardening-glasses-training-fixture.php';

/** The barrier observes a real InnoDB lock attempt, not an artificial service result. */
final class HgtRaceStatement extends PDOStatement {
    protected function __construct(){}
    public function execute(?array $params=null): bool {
        static $announced=false;
        if(!$announced&&$this->queryString==="SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE"){
            $announced=true;echo "locking\n";fflush(STDOUT);
        }
        return parent::execute($params);
    }
}
function hgtr_connection(): PDO {
    $db=app_config()['database'];$dsn=(string)($db['dsn']??'');
    if($dsn==='')$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$db['host']??'127.0.0.1',$db['port']??3306,$db['name'],$db['charset']??'utf8mb4');
    return new PDO($dsn,(string)$db['username'],(string)$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
}
if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    $connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HgtRaceStatement::class]);
    echo json_encode(['ready'=>true,'connection'=>$connection],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
    try{
        $result=match($input['action']){
            'start'=>glasses_training_session_start($pdo,$input['org'],$input['assignment'],$input['device'],(int)$input['device']['paired_by']),
            'end'=>glasses_training_session_end($pdo,$input['org'],$input['session'],$input['status'],'Concurrent device '.$input['status'],$input['device']),
            'manager_end'=>glasses_training_session_end_managed($pdo,$input['manager'],$input['session'],$input['status'],'Concurrent manager '.$input['status']),
        };
        echo json_encode(['ok'=>true,'session'=>$result],JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException|DomainException $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
    exit;
}

$checks=0;
function hgtr_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function hgtr_value(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function hgtr_race(PDO $pdo,int $org,array $inputs,?callable $beforeRelease=null): array {
    $workers=[];$results=[];$connections=[];$pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM organizations WHERE id=? FOR UPDATE')->execute([$org]);
    try{
        foreach($inputs as $input){
            $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start a training race worker.');
            $workers[]=[$process,$pipes];
            fwrite($pipes[0],json_encode(['org'=>$org]+$input,JSON_THROW_ON_ERROR));fclose($pipes[0]);
            stream_set_timeout($pipes[1],15);
            $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);
            hgtr_check(($ready['ready']??false)===true,'A fresh training worker connects.');$connections[]=(int)$ready['connection'];
            hgtr_check(trim((string)fgets($pipes[1]))==='locking','The worker reaches the actual training transaction lock.');
        }
        $placeholders=implode(',',array_fill(0,count($connections),'?'));
        $q=$pdo->prepare("SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id IN ($placeholders)");
        $deadline=microtime(true)+5;$waiting=[];
        // MariaDB refreshes the shared INNODB_TRX cache only after >100 ms without a read.
        // A 10 ms poll can keep an initial empty snapshot alive indefinitely.
        do{$q->execute($connections);$waiting=$q->fetchAll(PDO::FETCH_COLUMN);if(count($waiting)===count($connections))break;usleep(200000);}while(microtime(true)<$deadline);
        hgtr_check(count($waiting)===count($connections),'Every competing process is observed waiting in InnoDB before release. Expected '.json_encode($connections).'; observed '.json_encode($waiting).'.');
        if($beforeRelease)$beforeRelease();
        $pdo->commit();
        foreach($workers as [$process,$pipes]){
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            hgtr_check(proc_close($process)===0,'A training race worker must not deadlock or fail: '.$errors);
            $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        }
        $workers=[];return $results;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($workers as [$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}
    }
}
function hgtr_state(PDO $pdo,int $org,array $assignment): array {
    $q=$pdo->prepare('SELECT a.*,t.status generic_status,t.completed_at generic_completed_at FROM glasses_training_assignments a JOIN training_assignments t ON t.organization_id=a.organization_id AND t.id=a.training_assignment_id WHERE a.organization_id=? AND a.id=?');
    $q->execute([$org,$assignment['id']]);return $q->fetch();
}
function hgtr_assert_ending(PDO $pdo,int $org,array $assignment,array $session,array $results): void {
    hgtr_check(count(array_filter($results,fn($r)=>$r['ok']))===count($results),'Both competing end requests return the single serialized result.');
    $final=glasses_training_session($pdo,$org,$session['public_id']);$state=hgtr_state($pdo,$org,$assignment);
    foreach($results as $result)hgtr_check($result['session']['status']===$final['status']&&$result['session']['ended_at']===$final['ended_at']&&$result['session']['end_reason']===$final['end_reason'],'Racing endings agree on status, reason and completion time.');
    $complete=$final['status']==='completed';
    hgtr_check($state['status']===($complete?'completed':'assigned')&&$state['generic_status']===$state['status']&&($state['completed_at']!==null)===$complete&&($state['generic_completed_at']!==null)===$complete,'Competing endings leave both ledgers and completion timestamps coherent.');
    hgtr_check((int)hgtr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?",[$org,$session['public_id']])===1,'Exactly one terminal transition is audited.');
    hgtr_check((int)hgtr_value($pdo,"SELECT COUNT(*) FROM glasses_device_events WHERE organization_id=? AND event_type='training_session_ended' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.sessionPublicId'))=?",[$org,$session['public_id']])===1,'Exactly one end device event is persisted.');
}

$pdo=app_pdo();hgtr_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&(int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,'Training race acceptance requires MariaDB with native PDO prepares.');
$f=hgt_fixture_seed($pdo);$org=$f['organizationId'];$a=$f['devices']['A']['row'];$b=$f['devices']['B']['row'];
$make=static function()use($pdo,$f,$org,$a):array{$assignment=hgt_fixture_assignment($pdo,$f);return [$assignment,glasses_training_session_start($pdo,$org,$assignment['public_id'],$a,(int)$a['paired_by'])];};
$input=fn(array $session,string $status):array=>['action'=>'end','session'=>$session['public_id'],'status'=>$status,'device'=>$a];
foreach([['completed','completed'],['completed','cancelled'],['cancelled','completed'],['interrupted','completed']] as $statuses){
    [$assignment,$session]=$make();$results=hgtr_race($pdo,$org,[$input($session,$statuses[0]),$input($session,$statuses[1])]);
    hgtr_assert_ending($pdo,$org,$assignment,$session,$results);
}
[$assignment,$session]=$make();
$results=hgtr_race($pdo,$org,[$input($session,'completed'),['action'=>'manager_end','manager'=>$f['users']['manager'],'session'=>$session['public_id'],'status'=>'cancelled']]);
hgtr_assert_ending($pdo,$org,$assignment,$session,$results);
$q=$pdo->prepare("SELECT actor_user_id,new_values_json FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?");$q->execute([$org,$session['public_id']]);$audit=$q->fetch();$meta=json_decode($audit['new_values_json'],true,512,JSON_THROW_ON_ERROR);
hgtr_check((int)$audit['actor_user_id']===($meta['authority']==='device'?(int)$a['paired_by']:$f['users']['manager']['id']),'A manager/device race records the real winning actor and authority.');

// Starting while ending must not resurrect a completed parent or duplicate a device slot.
foreach(['completed','cancelled'] as $status){
    [$assignment,$session]=$make();
    $results=hgtr_race($pdo,$org,[$input($session,$status),['action'=>'start','assignment'=>$assignment['public_id'],'device'=>$a]]);
    hgtr_check($results[0]['ok']&&$results[0]['session']['status']===$status,'The racing end commits its terminal outcome.');
    $state=hgtr_state($pdo,$org,$assignment);$q=$pdo->prepare("SELECT * FROM glasses_training_sessions WHERE organization_id=? AND assignment_id=? AND status='active'");$q->execute([$org,$assignment['id']]);$active=$q->fetchAll();
    if($status==='completed')hgtr_check($state['status']==='completed'&&$state['generic_status']==='completed'&&count($active)===0,'A racing start never reactivates a completed assignment.');
    else{
        hgtr_check(count($active)<=1&&$state['generic_status']==='assigned'&&$state['status']===(count($active)?'active':'assigned'),'Cancellation/start ordering yields at most one active session and consistent ledgers.');
        if($active)glasses_training_session_end($pdo,$org,$active[0]['public_id'],'cancelled','Race cleanup',$a);
    }
    hgtr_check((int)hgtr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?",[$org,$session['public_id']])===1,'Start/end race records one ending of the original session.');
}

$unbound=hgt_fixture_assignment($pdo,$f,'A','operatorA',false);
$results=hgtr_race($pdo,$org,[['action'=>'start','assignment'=>$unbound['public_id'],'device'=>$a],['action'=>'start','assignment'=>$unbound['public_id'],'device'=>$b]]);
hgtr_check(count(array_filter($results,fn($r)=>$r['ok']))===1,'Two devices racing an unbound assignment produce exactly one owner.');
$winner=$results[0]['ok']?0:1;$winningDevice=$winner===0?$a:$b;$state=hgtr_state($pdo,$org,$unbound);
hgtr_check((int)$state['device_id']===(int)$winningDevice['id']&&$state['status']==='active','The committed assignment and session use the same winning device.');
glasses_training_session_end($pdo,$org,$results[$winner]['session']['public_id'],'completed','Unbound assignment race cleanup',$winningDevice);

// Cached authority must be checked after the transaction lock waits for a committed change.
[$assignment,$session]=$make();$before=hgt_fixture_snapshot($pdo,$org);
$results=hgtr_race($pdo,$org,[$input($session,'completed')],function()use($pdo,$org,$a):void{
    $pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE organization_id=? AND user_id=?")->execute([$org,(int)$a['paired_by']]);
});
hgtr_check(!$results[0]['ok']&&str_contains($results[0]['error'],'re-pair')&&hgt_fixture_snapshot($pdo,$org)===$before,'An in-flight cached device cannot finish after its accountable membership is suspended.');
$pdo->prepare("UPDATE organization_memberships SET status='active' WHERE organization_id=? AND user_id=?")->execute([$org,(int)$a['paired_by']]);
$results=hgtr_race($pdo,$org,[['action'=>'manager_end','manager'=>$f['users']['manager'],'session'=>$session['public_id'],'status'=>'completed']],function()use($pdo,$f):void{
    $pdo->prepare("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key='glasses.manage'")->execute([$f['users']['manager']['role_id']]);
});
hgtr_check(!$results[0]['ok']&&str_contains($results[0]['error'],'glasses.manage')&&hgt_fixture_snapshot($pdo,$org)===$before,'An in-flight manager action cannot use a permission revoked while it waited.');
$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='glasses.manage'")->execute([$f['users']['manager']['role_id']]);

// An outer repeatable-read snapshot cannot restore a revoked manager grant.
$other=hgtr_connection();$pdo->beginTransaction();
try{
    hgtr_value($pdo,'SELECT COUNT(*) FROM role_permissions WHERE role_id=?',[$f['users']['manager']['role_id']]);
    $other->prepare("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key='glasses.manage'")->execute([$f['users']['manager']['role_id']]);
    $denied=false;try{glasses_training_session_end_managed($pdo,$f['users']['manager'],$session['public_id'],'completed','Stale outer snapshot');}catch(DomainException $error){$denied=str_contains($error->getMessage(),'glasses.manage');}
    hgtr_check($denied&&$pdo->inTransaction(),'Current permission locks override an older outer transaction snapshot.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
hgtr_check(hgt_fixture_snapshot($pdo,$org)===$before,'Stale-snapshot denial preserves the active session and both assignments.');
glasses_training_session_end($pdo,$org,$session['public_id'],'completed','Final race cleanup',$a);
echo "Glasses training concurrency: PASS ($checks checks; deterministic independent InnoDB waiters, duplicate/conflicting ends, start/end, unbound ownership and changing authority)\n";
