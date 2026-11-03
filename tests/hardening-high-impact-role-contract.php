<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';

final class HhrLockStatement extends PDOStatement {
    protected function __construct(){}
    public function execute(?array $params=null): bool {
        static $reported=false;
        $target=($GLOBALS['hhr_lock_target']??'org')==='users'
            ?'SELECT id FROM users WHERE id=? FOR UPDATE'
            :"SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE";
        if(!$reported&&$this->queryString===$target){
            $reported=true;echo "locking\n";fflush(STDOUT);
        }
        return parent::execute($params);
    }
}
if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
    $GLOBALS['hhr_lock_target']=$input['waitOn']??'org';
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    $_SESSION['fm9h_role_grants'][fm9h_grant_key($input['owner'])]=$input['grant'];
    $connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HhrLockStatement::class]);
    echo json_encode(['connection'=>$connection],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
    try{$result=fm9h_execute_role($pdo,$input['owner'],$input['thread']);echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);}
    catch(DomainException|InvalidArgumentException $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
    exit;
}

$checks=0;
function hhr_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function hhr_value(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function hhr_reject(callable $work,string $message): void {
    try{$work();}catch(DomainException|InvalidArgumentException|GacConfirmationConflict){hhr_check(true,$message);return;}
    throw new RuntimeException($message.' must be denied.');
}
function hhr_snapshot(PDO $pdo,int $org): array {
    $result=[];
    foreach(['roles','organization_memberships','agent_messages','audit_log'] as $table){$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$result[$table]=$q->fetchAll();}
    $q=$pdo->prepare('SELECT rp.* FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.organization_id=? ORDER BY rp.role_id,rp.permission_id');$q->execute([$org]);$result['role_permissions']=$q->fetchAll();
    return $result;
}
function hhr_session(PDO $pdo,int $org,int $uid): array {app_session_start_user($pdo,$org,$uid);hhr_check(app_current_user(true)!==null,'Fixture session is current before a role change.');return $_SESSION;}
function hhr_current(array $session): bool {$_SESSION=$session;return app_current_user(true)!==null;}

/** Stage and approve through the exact canonical high-impact review envelope. */
function hhr_approve(PDO $pdo,array $owner,int $roleId,string $permission,string $operation='grant'): array {
    $org=(int)$owner['organization_id'];$uid=(int)$owner['id'];
    $thread=gaw_create_thread($pdo,$org,$uid,'text','High-impact role hardening');$tid=$thread['public_id'];
    $summary='role db-role-'.$roleId.' '.$operation.' '.$permission;
    $source=gaw_append($pdo,$org,$uid,$tid,'user','request approval: permission_change: '.$summary,['channel'=>'text']);
    gac_request_context(['threadId'=>$tid,'sourceMessageId'=>$source['id']],$pdo,$owner);
    $staged=fm9f_approval_propose($owner,'permission_change',$summary,$tid,$pdo);
    $pending=gac_pending_get('food_monster_high_impact',$org,$uid);
    $message=gaw_append($pdo,$org,$uid,$tid,'agent',gac_proposal_answer($pending),['structured'=>['requiresApproval'=>true,'proposal'=>gac_public_proposal($pending)]]);
    $bound=gac_pending_bind_message($pdo,'food_monster_high_impact',$org,$uid,$staged['proposalId'],$tid,$message['id']);
    $pdo->prepare('UPDATE agent_messages SET structured_json=? WHERE organization_id=? AND public_id=?')->execute([json_encode(['requiresApproval'=>true,'proposal'=>$bound],JSON_THROW_ON_ERROR),$org,$message['id']]);
    $decision=gaw_append($pdo,$org,$uid,$tid,'user','Approve',['channel'=>'text']);
    $evidence=['threadId'=>$tid,'sourceMessageId'=>$decision['id'],'proposalId'=>$bound['id'],'proposalHash'=>$bound['hash'],'proposalMessageId'=>$bound['messageId']];
    gac_request_context($evidence,$pdo,$owner);$approved=fm9f_approval_decide($owner,$bound['id'],'approve',$evidence);
    hhr_check($staged['executed']===false&&$approved['executed']===false,'Proposal and owner approval alone execute no role change.');
    $grant=fm9h_active_grant($owner,$tid);hhr_check(is_array($grant),'The approved grant remains bound to its exact thread.');
    return ['thread'=>$tid,'evidence'=>$evidence,'grant'=>$grant];
}
function hhr_workers(PDO $pdo,int $org,array $owner,array $approved,int $count,?callable $beforeRelease=null,string $waitOn='org'): array {
    $workers=[];$connections=[];$results=[];$pdo->beginTransaction();
    if($waitOn==='users')accounts_lock_users($pdo,[(int)$owner['id']]);else accounts_lock_org($pdo,$org);
    try{
        for($i=0;$i<$count;$i++){
            $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start a high-impact role worker.');$workers[]=[$process,$pipes];
            fwrite($pipes[0],json_encode(['owner'=>$owner,'thread'=>$approved['thread'],'grant'=>$approved['grant'],'waitOn'=>$waitOn],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
            $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$connections[]=(int)$ready['connection'];
            hhr_check(trim((string)fgets($pipes[1]))==='locking','The role worker reaches the canonical '.$waitOn.' lock.');
        }
        $q=$pdo->prepare("SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id IN (".implode(',',array_fill(0,count($connections),'?')).')');
        $deadline=microtime(true)+5;$waiting=[];
        // Leave MariaDB's shared INNODB_TRX cache idle for >100 ms so it can refresh.
        do{$q->execute($connections);$waiting=$q->fetchAll(PDO::FETCH_COLUMN);if(count($waiting)===$count)break;usleep(200000);}while(microtime(true)<$deadline);
        hhr_check(count($waiting)===$count,'All role workers are observed waiting in InnoDB before the authority change commits. Expected '.json_encode($connections).'; observed '.json_encode($waiting).'.');
        if($beforeRelease)$beforeRelease();$pdo->commit();
        foreach($workers as [$process,$pipes]){
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hhr_check(proc_close($process)===0,'Role worker must not hide a PDO error or deadlock: '.$errors);
            $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        }
        $workers=[];return $results;
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($workers as [$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}
    }
}

$pdo=app_pdo();hhr_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&(int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,'Role hardening requires MariaDB with native PDO prepares.');
$suffix='hhr-'.bin2hex(random_bytes(6));$orgs=[];$roles=[];$users=[];$memberships=[];
foreach(['home','other'] as $scope){$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix.'-'.$scope]);$orgs[$scope]=(int)$pdo->lastInsertId();}
$org=$orgs['home'];$other=$orgs['other'];
foreach(['owner','target','unrelated','bounded','foreign','system'] as $kind){
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,?,?,?)')
        ->execute([$kind==='foreign'?$other:$org,$kind,$kind==='owner'?'super_admin':$suffix.'-'.$kind,in_array($kind,['owner','system'],true)?1:0,$kind==='owner'?1:0,$kind==='owner'?0:1]);
    $roles[$kind]=(int)$pdo->lastInsertId();
}
foreach(['owner','employee','coworker','unrelated'] as $kind){
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,'Role',?,'active')")
        ->execute([$suffix.'-'.$kind.'@example.test',app_password_hash('Role-Hardening!42'),$kind,$kind.' Role']);$uid=(int)$pdo->lastInsertId();$users[$kind]=$uid;
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();$memberships[$kind]=$membership;
    $role=$roles[in_array($kind,['employee','coworker'],true)?'target':$kind];
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
}
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$other,$users['employee']]);$foreignMembership=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$foreignMembership,$roles['foreign'],$users['owner']]);
foreach(['roles.edit','roles.assign_permissions'] as $permission)$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?')->execute([$roles['bounded'],$permission]);
hhr_check((int)hhr_value($pdo,'SELECT COUNT(*) FROM role_permissions WHERE role_id=?',[$roles['bounded']])===2,'The non-owner fixture retains both role-management permissions.');
$ownerSession=hhr_session($pdo,$org,$users['owner']);$owner=app_current_user(true);
$homeSession=hhr_session($pdo,$org,$users['employee']);$foreignSession=hhr_session($pdo,$other,$users['employee']);$coworkerSession=hhr_session($pdo,$org,$users['coworker']);$unrelatedSession=hhr_session($pdo,$org,$users['unrelated']);$_SESSION=$ownerSession;
$beforeForeign=hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$foreignMembership]);
$beforeUnrelated=hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$memberships['unrelated']]);
hhr_reject(fn()=>fm9h_execute_role($pdo,$owner,'agent-'.str_repeat('a',20)),'An unapproved role mutation');
hhr_reject(fn()=>fm9h_prepare_role($pdo,$owner,'role db-role-'.$roles['owner'].' grant roles.view'),'Protected owner role');
hhr_reject(fn()=>fm9h_prepare_role($pdo,$owner,'role db-role-'.$roles['system'].' grant roles.view'),'Protected system role');
hhr_reject(fn()=>fm9h_prepare_role($pdo,$owner,'role db-role-'.$roles['foreign'].' grant roles.view'),'Foreign role');
$approved=hhr_approve($pdo,$owner,$roles['target'],'roles.view');$firstApproved=$approved;$grantSession=$_SESSION;
hhr_check(!in_array('roles.view',fm9h_role_state($pdo,$org,$roles['target'])['keys'],true),'Approval preserves the current role permissions until Confirm.');
$bad=$approved['evidence'];$bad['proposalHash']=str_repeat('0',64);
hhr_reject(fn()=>fm9f_verify_grant_review($owner,$approved['grant'],$bad),'Mismatched role approval evidence');
fm9f_verify_grant_review($owner,$approved['grant'],$approved['evidence']);
$changed=fm9h_execute_role($pdo,$owner,$approved['thread']);$ownerSession=$_SESSION;
hhr_check($changed['executed']&&in_array('roles.view',fm9h_role_state($pdo,$org,$roles['target'])['keys'],true),'An exact approved custom-role grant persists.');
hhr_check(!hhr_current($homeSession)&&!hhr_current($coworkerSession),'Role changes invalidate every affected restaurant session.');
hhr_check(hhr_current($foreignSession)&&hhr_current($unrelatedSession),'Other restaurants and unrelated roles keep their sessions.');
hhr_check(hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$foreignMembership])===$beforeForeign&&hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$memberships['unrelated']])===$beforeUnrelated,'Session revocation is scoped to the changed role in its restaurant.');
$_SESSION=$ownerSession;$before=hhr_snapshot($pdo,$org);hhr_reject(fn()=>fm9h_execute_role($pdo,$owner,$approved['thread']),'Consumed approval replay');hhr_check(hhr_snapshot($pdo,$org)===$before,'A replay changes no permission, receipt, audit or membership version.');
$newHomeSession=hhr_session($pdo,$org,$users['employee']);$_SESSION=$ownerSession;

// Permission, session invalidation, canonical audit and approval receipt are one commit.
$approved=hhr_approve($pdo,$owner,$roles['target'],'roles.view','revoke');$before=hhr_snapshot($pdo,$org);$trigger='hhr_audit_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id=$org AND NEW.action='food_monster.permission_change_executed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='role audit failure fixture'; END IF; END");
try{
    $failed=false;try{fm9h_execute_role($pdo,$owner,$approved['thread']);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'role audit failure fixture');}
    hhr_check($failed&&hhr_snapshot($pdo,$org)===$before,'An audit failure rolls back permission changes, session versions and both audit/receipt records.');
    hhr_check(fm9h_active_grant($owner,$approved['thread'])!==null,'A rolled-back role transaction does not falsely consume its approval.');
}finally{$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}
fm9h_execute_role($pdo,$owner,$approved['thread']);$ownerSession=$_SESSION;
hhr_check(!hhr_current($newHomeSession)&&!hhr_current($homeSession),'Revoking a permission invalidates new sessions and cannot resurrect older sessions.');
hhr_check(hhr_current($foreignSession),'Repeated home-restaurant permission changes preserve the foreign session.');$_SESSION=$ownerSession;
hhr_check((int)hhr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='role.updated'",[$org])===2,'Both committed role changes produce canonical role.updated audit entries.');
hhr_check((int)hhr_value($pdo,"SELECT COUNT(*) FROM agent_messages WHERE organization_id=? AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9h_high_impact_execution'",[$org])===2,'Only committed changes produce approval execution receipts.');
$before=hhr_snapshot($pdo,$org);$_SESSION=$grantSession;
hhr_reject(fn()=>fm9h_execute_role($pdo,$owner,$firstApproved['thread']),'Copied approval after the role returns to its original permission state');
hhr_check(hhr_snapshot($pdo,$org)===$before,'A durable receipt blocks approval reuse even when the original permission hash matches again.');$_SESSION=$ownerSession;

// Each current-authority change commits while a cached owner request waits.
foreach(['owner_role','membership','global_account','global_after_snapshot'] as $change){
    $approved=hhr_approve($pdo,$owner,$roles['target'],'roles.view');
    $beforeKeys=fm9h_role_state($pdo,$org,$roles['target'])['keys'];$beforeAudit=(int)hhr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='role.updated'",[$org]);
    $targetVersion=hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$memberships['employee']]);
    $results=hhr_workers($pdo,$org,$owner,$approved,1,function()use($pdo,$org,$users,$memberships,$roles,$change):void{
        accounts_lock_users($pdo,[$users['owner']]);
        if($change==='owner_role'){
            $pdo->prepare('UPDATE user_roles SET revoked_at=NOW(6) WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['owner']]);
            $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$memberships['owner'],$roles['bounded'],$users['owner']]);
        }elseif($change==='membership')$pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE id=?")->execute([$memberships['owner']]);
        else $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$users['owner']]);
    },$change==='global_after_snapshot'?'users':'org');
    hhr_check(!$results[0]['ok'],'A waiting request cannot retain revoked '.$change.' authority.');
    hhr_check(fm9h_role_state($pdo,$org,$roles['target'])['keys']===$beforeKeys&&(int)hhr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='role.updated'",[$org])===$beforeAudit&&hhr_value($pdo,'SELECT updated_at FROM organization_memberships WHERE id=?',[$memberships['employee']])===$targetVersion,'Denied '.$change.' leaves the target role, affected sessions and audit unchanged.');
    $pdo->prepare('UPDATE user_roles SET revoked_at=NULL WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['owner']]);
    $pdo->prepare('DELETE FROM user_roles WHERE membership_id=? AND role_id=?')->execute([$memberships['owner'],$roles['bounded']]);
    $pdo->prepare("UPDATE organization_memberships SET status='active' WHERE id=?")->execute([$memberships['owner']]);$pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$users['owner']]);
    unset($_SESSION['fm9h_role_grants'][fm9h_grant_key($owner)]);
}

// Even copied receipts in independent sessions cannot apply one old role state twice.
$approved=hhr_approve($pdo,$owner,$roles['target'],'roles.view');$beforeAudit=(int)hhr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='role.updated'",[$org]);
$results=hhr_workers($pdo,$org,$owner,$approved,2);
hhr_check(count(array_filter($results,fn($result)=>$result['ok']))===1,'Competing confirmations yield exactly one role-state transition.');
hhr_check(in_array('roles.view',fm9h_role_state($pdo,$org,$roles['target'])['keys'],true)&&(int)hhr_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='role.updated'",[$org])===$beforeAudit+1,'Competing confirmations persist one canonical audit and the expected permission.');
echo "High-impact role changes: PASS ($checks checks; exact owner approvals, canonical role policy, tenant-scoped session revocation, audit rollback and deterministic authority/concurrent-confirmation races)\n";
