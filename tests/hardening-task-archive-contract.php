<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/user-accounts.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';

final class HtaWaitStatement extends PDOStatement
{
    protected function __construct(){}
    public function execute(?array $params=null): bool
    {
        static $reported=false;
        $boundary=$GLOBALS['hta_wait_on']??'org';
        $task=str_contains($this->queryString,'FROM restaurant_tasks')&&str_contains($this->queryString,'FOR UPDATE');
        $target=match($boundary){
            'users'=>$this->queryString==='SELECT id FROM users WHERE id=? FOR UPDATE',
            'task'=>$task,
            // Also recognize the old task-first boundary for the failing-before
            // run: both implementations must actually wait before revocation.
            default=>$this->queryString==="SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE"||$task,
        };
        if(!$reported&&$target){$reported=true;fwrite(STDOUT,"locking\n");fflush(STDOUT);}
        return parent::execute($params);
    }
}

if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $pdo=app_pdo();$pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    $GLOBALS['hta_wait_on']=$input['waitOn'];
    if($input['method']==='agent')$_SESSION['fm9i_task_archive_grants'][fm9i_key($input['actor'])]=$input['grant'];
    $connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HtaWaitStatement::class]);
    fwrite(STDOUT,json_encode(['connection'=>$connection],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
    try{
        $result=$input['method']==='agent'
            ?fm9i_execute($pdo,$input['actor'],$input['thread'])
            :operations_archive_task($pdo,$input['actor'],$input['taskId']);
        fwrite(STDOUT,json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR));
    }catch(DomainException|InvalidArgumentException $error){
        fwrite(STDOUT,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR));
    }
    exit;
}

$checks=0;
function hta_check(bool $ok,string $message): void
{
    global $checks;if(!$ok)throw new RuntimeException($message);$checks++;
}
function hta_value(PDO $pdo,string $sql,array $args=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}
function hta_reject(callable $work,string $message): void
{
    try{$work();}catch(DomainException|InvalidArgumentException|GacConfirmationConflict){hta_check(true,$message);return;}
    throw new RuntimeException($message.' must be denied.');
}
function hta_snapshot(PDO $pdo,int $org): array
{
    $result=[];
    foreach(['restaurant_tasks','restaurant_task_events','agent_messages','audit_log'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');
        $q->execute([$org]);$result[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
function hta_actor(PDO $pdo,int $org,int $uid): array
{
    $actor=accounts_actor($pdo,$org,$uid);$actor['is_owner_role']=0;
    foreach(accounts_roles($pdo,$org,(int)$actor['membership_id']) as $role){
        if($role['owner']){$actor['is_owner_role']=1;break;}
    }
    return $actor;
}
function hta_task(PDO $pdo,int $org,int $uid,string $title='Archive fixture'): array
{
    return operations_create_task($pdo,$org,['title'=>$title,'category'=>'general'],$uid);
}

/** A real canonical proposal and typed approval, with no task mutation. */
function hta_approve(PDO $pdo,array $owner,array $task): array
{
    $org=(int)$owner['organization_id'];$uid=(int)$owner['id'];
    // Independent cases use a new conversation and remove their previous grant.
    unset($_SESSION['fm9i_task_archive_grants'][fm9i_key($owner)]);
    $thread=gaw_create_thread($pdo,$org,$uid,'text','Task archive hardening')['public_id'];
    $source=gaw_append($pdo,$org,$uid,$thread,'user','request approval: record_deletion: task '.$task['public_id'],['channel'=>'text']);
    gac_request_context(['threadId'=>$thread,'sourceMessageId'=>$source['id']],$pdo,$owner);
    $staged=fm9f_approval_propose($owner,'record_deletion','task '.$task['public_id'],$thread,$pdo);
    $pending=gac_pending_get('food_monster_high_impact',$org,$uid);
    $message=gaw_append($pdo,$org,$uid,$thread,'agent',gac_proposal_answer($pending),
        ['structured'=>['requiresApproval'=>true,'proposal'=>gac_public_proposal($pending)]]);
    $bound=gac_pending_bind_message($pdo,'food_monster_high_impact',$org,$uid,$staged['proposalId'],$thread,$message['id']);
    $pdo->prepare('UPDATE agent_messages SET structured_json=? WHERE organization_id=? AND public_id=?')
        ->execute([json_encode(['requiresApproval'=>true,'proposal'=>$bound],JSON_THROW_ON_ERROR),$org,$message['id']]);
    $decision=gaw_append($pdo,$org,$uid,$thread,'user','Approve',['channel'=>'text']);
    $evidence=['threadId'=>$thread,'sourceMessageId'=>$decision['id'],'proposalId'=>$bound['id'],
        'proposalHash'=>$bound['hash'],'proposalMessageId'=>$bound['messageId']];
    gac_request_context($evidence,$pdo,$owner);
    $approved=fm9f_approval_decide($owner,$bound['id'],'approve',$evidence);
    hta_check(!$staged['executed']&&!$approved['executed']&&operations_task_by_public_id($pdo,$org,$task['public_id'])!==null,
        'Canonical proposal and typed owner approval leave the task active until Confirm.');
    $grant=fm9i_active_grant($owner,$thread);
    hta_check(is_array($grant),'The exact task grant belongs to its approved conversation.');
    return ['thread'=>$thread,'grant'=>$grant,'evidence'=>$evidence];
}

/** Observe actual InnoDB waits before changing authority or task state. */
function hta_workers(PDO $pdo,array $actor,array $task,?array $approved,int $count=1,
    ?callable $beforeRelease=null,string $waitOn='org'): array
{
    $org=(int)$actor['organization_id'];$workers=[];$connections=[];$results=[];
    $pdo->beginTransaction();
    if($waitOn==='users')accounts_lock_users($pdo,[(int)$actor['id']]);
    else{
        if($waitOn==='org')accounts_lock_org($pdo,$org);
        // Holding both rows lets the old implementation reach its task lock and
        // the hardened implementation reach its earlier organization lock.
        $q=$pdo->prepare('SELECT id FROM restaurant_tasks WHERE organization_id=? AND public_id=? FOR UPDATE');
        $q->execute([$org,$task['public_id']]);hta_check((bool)$q->fetchColumn(),'The parent holds the exact task lock.');
    }
    try{
        for($i=0;$i<$count;$i++){
            $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start a task archive worker.');
            $workers[]=[$process,$pipes];
            fwrite($pipes[0],json_encode(['actor'=>$actor,'taskId'=>$task['public_id'],
                'method'=>$approved?'agent':'operations','thread'=>$approved['thread']??'',
                'grant'=>$approved['grant']??null,'waitOn'=>$waitOn],JSON_THROW_ON_ERROR));
            fclose($pipes[0]);stream_set_timeout($pipes[1],15);
            $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);
            $connections[]=(int)$ready['connection'];
            hta_check(trim((string)fgets($pipes[1]))==='locking','The worker reaches its archive lock boundary.');
        }
        $q=$pdo->prepare("SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id IN ("
            .implode(',',array_fill(0,count($connections),'?')).')');
        $deadline=microtime(true)+5;$waiting=[];
        // MariaDB's shared transaction cache needs an idle interval to refresh.
        do{$q->execute($connections);$waiting=$q->fetchAll(PDO::FETCH_COLUMN);if(count($waiting)===$count)break;usleep(200000);}while(microtime(true)<$deadline);
        hta_check(count($waiting)===$count,'Every archive worker is observed waiting in InnoDB before the parent commits.');
        if($beforeRelease)$beforeRelease();
        $pdo->commit();
        foreach($workers as [$process,$pipes]){
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            hta_check(proc_close($process)===0,'Archive worker must not hide a PDO error or deadlock: '.$errors);
            $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        }
        $workers=[];return $results;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($workers as [$process,$pipes]){
            if(is_resource($process))proc_terminate($process);
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($process))proc_close($process);
        }
    }
}

$pdo=app_pdo();
hta_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&(int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,
    'Task archive hardening requires MariaDB with native PDO prepares.');
$suffix='hta-'.bin2hex(random_bytes(6));$orgs=[];$roles=[];$users=[];$memberships=[];
foreach(['home','other'] as $scope){
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix.'-'.$scope]);
    $orgs[$scope]=(int)$pdo->lastInsertId();
}
$org=$orgs['home'];$other=$orgs['other'];
foreach(['owner','manager','viewer'] as $kind){
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,?,?,?)')
        ->execute([$org,$kind,$kind==='owner'?'super_admin':$suffix.'-'.$kind,$kind==='owner'?1:0,$kind==='owner'?1:0,$kind==='owner'?0:1]);
    $roles[$kind]=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,'Archive',?,?, 'active')")
        ->execute([$suffix.'-'.$kind.'@example.test',app_password_hash('Archive-Hardening!42'),$kind,'Archive '.$kind]);
    $users[$kind]=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$users[$kind]]);
    $memberships[$kind]=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$memberships[$kind],$roles[$kind],$users[$kind]]);
}
foreach(['manager'=>'tasks.manage','viewer'=>'tasks.view'] as $kind=>$permission){
    $pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?')->execute([$roles[$kind],$permission]);
}
$owner=hta_actor($pdo,$org,$users['owner']);$manager=hta_actor($pdo,$org,$users['manager']);$viewer=hta_actor($pdo,$org,$users['viewer']);
hta_check(fm9a_is_super_admin_session($owner)&&app_has_permission('tasks.manage',$manager)&&!fm9a_is_super_admin_session($manager),
    'Saved fixture roles distinguish protected owner and ordinary task manager.');

// This mode is also run against the Release C sources to prove the authority bug.
foreach(['owner_role','membership','permission','global_account','organization'] as $change){
    $actor=$change==='permission'?$manager:$owner;
    $task=hta_task($pdo,$org,(int)$actor['id'],'Waiting '.$change);
    $approved=$change==='permission'?null:hta_approve($pdo,$owner,$task);
    $before=hta_snapshot($pdo,$org);
    $results=hta_workers($pdo,$actor,$task,$approved,1,function()use($pdo,$org,$users,$memberships,$roles,$change):void{
        if($change==='global_account'){
            $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$users['owner']]);return;
        }
        accounts_lock_users($pdo,[$users[$change==='permission'?'manager':'owner']]);
        if($change==='owner_role'){
            $pdo->prepare('UPDATE user_roles SET revoked_at=NOW(6) WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['owner']]);
            // Retain tasks.manage, so the Agent's stricter fresh owner test matters.
            $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$memberships['owner'],$roles['manager'],$users['owner']]);
        }elseif($change==='membership'){
            $pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE id=?")->execute([$memberships['owner']]);
        }elseif($change==='permission'){
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roles['manager']]);
        }else $pdo->prepare("UPDATE organizations SET status='suspended' WHERE id=?")->execute([$org]);
    },$change==='global_account'?'users':'org');
    hta_check(!$results[0]['ok'],'A request waiting on its authority boundary cannot retain revoked '.$change.' authority.');
    hta_check(hta_snapshot($pdo,$org)===$before,'Denied '.$change.' leaves task state, task history, Agent receipts and audit unchanged.');
    $pdo->prepare('UPDATE user_roles SET revoked_at=NULL WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['owner']]);
    $pdo->prepare('DELETE FROM user_roles WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['manager']]);
    $pdo->prepare("UPDATE organization_memberships SET status='active' WHERE id=?")->execute([$memberships['owner']]);
    $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$users['owner']]);
    $pdo->prepare("UPDATE organizations SET status='active' WHERE id=?")->execute([$org]);
    $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='tasks.manage'")->execute([$roles['manager']]);
}
if(($argv[1]??'')==='authority-only'){
    echo "Task archive authority: PASS ($checks checks; real lock waits and current owner, membership, permission, account and restaurant status)\n";exit;
}

$task=hta_task($pdo,$org,$users['manager'],'Native manager archive');
$result=operations_archive_task($pdo,$manager,$task['public_id']);
hta_check($result['archived']&&operations_task_by_public_id($pdo,$org,$task['public_id'])===null,'Native task managers retain their authorized archive workflow.');
$task=hta_task($pdo,$org,$users['owner']);$foreign=hta_task($pdo,$other,$users['owner']);
$before=hta_snapshot($pdo,$org);$foreignBefore=hta_snapshot($pdo,$other);
hta_reject(fn()=>operations_archive_task($pdo,$viewer,$task['public_id']),'Read-only account archive');
hta_reject(fn()=>operations_archive_task($pdo,$manager,$foreign['public_id']),'Cross-restaurant task archive');
hta_reject(fn()=>operations_archive_task($pdo,$manager,$task['public_id'],'completed',(string)$task['updated_at']),'Stale approved status');
hta_reject(fn()=>operations_archive_task($pdo,$manager,$task['public_id'],(string)$task['status'],'2000-01-01 00:00:00.000000'),'Stale approved version');
hta_check(hta_snapshot($pdo,$org)===$before&&hta_snapshot($pdo,$other)===$foreignBefore,'Denied native writes preserve both tenants and all history.');
$pdo->beginTransaction();$nested=false;
try{operations_archive_task($pdo,$manager,$task['public_id']);}catch(LogicException){$nested=true;}finally{$pdo->rollBack();}
hta_check($nested,'Archival cannot accidentally commit a caller-owned transaction.');
$linked=operations_create_task($pdo,$org,['title'=>'Source-owned task','category'=>'general','sourceType'=>'food_monster_report','sourcePublicId'=>'msg-'.str_repeat('a',20)],$users['owner']);
$before=hta_snapshot($pdo,$org);
hta_reject(fn()=>operations_archive_task($pdo,$manager,$linked['public_id']),'Native source-linked task archive');
hta_reject(fn()=>fm9i_prepare($pdo,$owner,'task '.$linked['public_id']),'Agent source-linked task approval');
hta_check(hta_snapshot($pdo,$org)===$before,'Source-owned workflows keep their task and audit untouched.');
hta_reject(fn()=>fm9i_execute($pdo,$owner,'agent-'.str_repeat('f',20)),'Unapproved or foreign-thread execution');

$approved=hta_approve($pdo,$owner,$task);$copy=$approved['grant'];
$bad=$approved['evidence'];$bad['proposalHash']=str_repeat('0',64);
hta_reject(fn()=>fm9f_verify_grant_review($owner,$approved['grant'],$bad),'Mismatched canonical review');
fm9f_verify_grant_review($owner,$approved['grant'],$approved['evidence']);
$result=fm9i_execute($pdo,$owner,$approved['thread']);
hta_check($result['executed']&&$result['archived'],'A current owner can Confirm the exact approved task.');
$q=$pdo->prepare('SELECT status,archived_at FROM restaurant_tasks WHERE organization_id=? AND public_id=?');$q->execute([$org,$task['public_id']]);$archived=$q->fetch(PDO::FETCH_ASSOC);
hta_check($archived&&$archived['status']==='cancelled'&&$archived['archived_at']!==null,'Archival preserves the task row with cancelled status.');
hta_check((int)hta_value($pdo,"SELECT COUNT(*) FROM restaurant_task_events WHERE organization_id=? AND task_id=? AND event_type='archived'",[$org,$task['id']])===1,'The task has exactly one archival history event.');
hta_check((int)hta_value($pdo,"SELECT COUNT(*) FROM agent_messages WHERE organization_id=? AND user_id IS NULL AND role='system' AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.approvalId'))=?",[$org,$copy['approvalId']])===1,'The durable receipt is a system message owned by its canonical conversation.');
$before=hta_snapshot($pdo,$org);
hta_reject(fn()=>fm9i_execute($pdo,$owner,$approved['thread']),'Consumed approval replay');
hta_check(hta_snapshot($pdo,$org)===$before,'A consumed approval adds no event or audit.');
// Simulate a restored session and an independent restoration of the old task row.
// The durable approval receipt must still reject replay of the old approval.
$pdo->prepare('UPDATE restaurant_tasks SET archived_at=NULL,status=?,updated_at=? WHERE organization_id=? AND id=?')
    ->execute([$task['status'],$task['updated_at'],$org,$task['id']]);
$_SESSION['fm9i_task_archive_grants'][fm9i_key($owner)]=$copy;$before=hta_snapshot($pdo,$org);
hta_reject(fn()=>fm9i_execute($pdo,$owner,$approved['thread']),'Copied approval after task state is restored');
hta_check(hta_snapshot($pdo,$org)===$before,'The receipt prevents replay even when task status and version match again.');

$task=hta_task($pdo,$org,$users['owner'],'Atomic audit rollback');$approved=hta_approve($pdo,$owner,$task);$before=hta_snapshot($pdo,$org);
$trigger='hta_audit_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id=$org AND NEW.action='food_monster.task_archive_executed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='archive audit failure fixture'; END IF; END");
try{
    $failed=false;try{fm9i_execute($pdo,$owner,$approved['thread']);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'archive audit failure fixture');}
    hta_check($failed&&hta_snapshot($pdo,$org)===$before,'An Agent audit failure rolls back task mutation, task event, canonical receipt and both audits.');
    hta_check(fm9i_active_grant($owner,$approved['thread'])!==null,'A rolled-back task archive does not consume its approval.');
}finally{$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}
hta_check(fm9i_execute($pdo,$owner,$approved['thread'])['executed'],'A rolled-back archive can succeed after its audit is available.');

foreach(['version','source','expiry'] as $change){
    $task=hta_task($pdo,$org,$users['owner'],'Task wait '.$change);$approved=hta_approve($pdo,$owner,$task);
    if($change==='expiry')$approved['grant']['expires']=time()+6;
    $beforeHistory=(int)hta_value($pdo,"SELECT COUNT(*) FROM restaurant_task_events WHERE organization_id=? AND event_type='archived'",[$org]);
    $beforeAudit=(int)hta_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='tasks.archived'",[$org]);
    $results=hta_workers($pdo,$owner,$task,$approved,1,function()use($pdo,$org,$task,$approved,$change):void{
        if($change==='version')$pdo->prepare('UPDATE restaurant_tasks SET updated_at=DATE_ADD(updated_at,INTERVAL 1 SECOND) WHERE organization_id=? AND id=?')->execute([$org,$task['id']]);
        elseif($change==='source')$pdo->prepare("UPDATE restaurant_tasks SET source_type='food_monster_report' WHERE organization_id=? AND id=?")->execute([$org,$task['id']]);
        else while(time()<(int)$approved['grant']['expires'])usleep(100000);
    },'task');
    hta_check(!$results[0]['ok'],'A '.$change.' change during the task wait prevents archival.');
    hta_check(operations_task_by_public_id($pdo,$org,$task['public_id'])!==null
        &&(int)hta_value($pdo,"SELECT COUNT(*) FROM restaurant_task_events WHERE organization_id=? AND event_type='archived'",[$org])===$beforeHistory
        &&(int)hta_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='tasks.archived'",[$org])===$beforeAudit,
        'A failed '.$change.' check adds no archival state, event or audit.');
}

$task=hta_task($pdo,$org,$users['owner'],'Concurrent copied confirmation');$approved=hta_approve($pdo,$owner,$task);
$beforeAudit=(int)hta_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='tasks.archived'",[$org]);
$results=hta_workers($pdo,$owner,$task,$approved,2);
hta_check(count(array_filter($results,fn(array $result):bool=>$result['ok']))===1,'Competing copied confirmations produce exactly one successful archive.');
hta_check((int)hta_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='tasks.archived'",[$org])===$beforeAudit+1,
    'Competing confirmations persist exactly one canonical archive audit.');
hta_check((int)hta_value($pdo,"SELECT COUNT(*) FROM agent_messages WHERE organization_id=? AND role='system' AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.approvalId'))=?",[$org,$approved['grant']['approvalId']])===1,
    'Competing confirmations persist exactly one approval execution receipt.');
echo "Task archive hardening: PASS ($checks checks; current authority, exact approval, source/version scope, audit rollback and deterministic lock races)\n";
