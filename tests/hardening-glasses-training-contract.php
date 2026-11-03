<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/hardening-glasses-training-fixture.php';
require_once __DIR__.'/../includes/glasses-employee-runtimes.php';

$checks=0;
function hgt_check(bool $condition,string $message): void {
    global $checks;if(!$condition)throw new RuntimeException($message);$checks++;
}
function hgt_value(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}
function hgt_reject(callable $action,string $message,string $needle=''): string {
    try{$action();}catch(InvalidArgumentException|DomainException $error){
        hgt_check($needle===''||str_contains($error->getMessage(),$needle),$message.' Unexpected denial: '.$error->getMessage());return $error->getMessage();
    }
    throw new RuntimeException($message.' must be denied.');
}
function hgt_assignment_state(PDO $pdo,int $org,array $assignment): array {
    $q=$pdo->prepare('SELECT a.status,a.completed_at,t.status generic_status,t.completed_at generic_completed_at FROM glasses_training_assignments a JOIN training_assignments t ON t.id=a.training_assignment_id AND t.organization_id=a.organization_id WHERE a.organization_id=? AND a.id=?');
    $q->execute([$org,(int)$assignment['id']]);return $q->fetch();
}
function hgt_end_count(PDO $pdo,int $org,string $public): int {
    return (int)hgt_value($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?",[$org,$public]);
}

$pdo=app_pdo();
hgt_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','Training ownership acceptance requires MariaDB/MySQL.');
hgt_check((int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,'Training ownership must execute with native PDO prepares.');
$f=hgt_fixture_seed($pdo);$org=$f['organizationId'];$a=$f['devices']['A']['row'];$b=$f['devices']['B']['row'];$foreign=$f['devices']['foreign']['row'];$manager=$f['users']['manager'];
$start=fn(array $assignment,array $device)=>glasses_training_session_start($pdo,$org,$assignment['public_id'],$device,(int)$device['paired_by']);
$end=fn(array $session,array $device,string $status='completed')=>glasses_training_session_end($pdo,$org,$session['public_id'],$status,'Ownership acceptance',$device);
$sessionA=$start($f['assignments']['A'],$a);$sessionB=$start($f['assignments']['B'],$b);
hgt_check($sessionA['status']==='active'&&$sessionB['status']==='active','Independent devices may run their own assigned training sessions.');
$before=hgt_fixture_snapshot($pdo,$org);
hgt_check($start($f['assignments']['A'],$a)['public_id']===$sessionA['public_id'],'An own-session start retry is idempotent.');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before,'A repeated start does not duplicate sessions, device events or audit.');
foreach(['completed','cancelled','interrupted'] as $status)hgt_reject(fn()=>$end($sessionA,$b,$status),'A second authenticated device cannot end the first session','Training session not found.');
$unknown=hgt_reject(fn()=>glasses_training_session_end($pdo,$org,'not-a-session','completed','',$b),'Unknown session','Training session not found.');
$otherTenant=hgt_reject(fn()=>glasses_training_session_end($pdo,$f['foreignOrganizationId'],$sessionA['public_id'],'completed','',$foreign),'Foreign tenant cannot read or end a session');
hgt_check($unknown===$otherTenant,'Cross-tenant and unknown session failures disclose no session ownership.');
hgt_reject(fn()=>$end($sessionA,$foreign),'Mismatched device organization','Active paired glasses');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before,'All device and tenant denials preserve both sessions and all linked ledgers.');

// A cached device from a previously successful authentication is not durable authority.
$pairA=(int)$a['paired_by'];
$pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE organization_id=? AND user_id=?")->execute([$org,$pairA]);
hgt_reject(fn()=>glasses_authenticate_token($pdo,$f['devices']['A']['token']),'Suspended pairing cannot authenticate','re-pair');
hgt_reject(fn()=>$end($sessionA,$a),'Suspended pairing cannot use a cached device','re-pair');
hgt_reject(fn()=>$start($f['assignments']['A'],$a),'Suspended pairing cannot replay a start','re-pair');
hgt_check(hgt_value($pdo,'SELECT status FROM organization_memberships WHERE organization_id=? AND user_id=?',[$f['foreignOrganizationId'],$pairA])==='active','The active foreign membership does not authorize this restaurant.');
$pdo->prepare("UPDATE organization_memberships SET status='active' WHERE organization_id=? AND user_id=?")->execute([$org,$pairA]);
$pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$pairA]);
hgt_reject(fn()=>$end($sessionA,$a),'Globally suspended accountable identity','re-pair');
$pdo->prepare("UPDATE users SET status='active',archived_at=NOW(6) WHERE id=?")->execute([$pairA]);
hgt_reject(fn()=>$end($sessionA,$a),'Archived accountable identity','re-pair');
$pdo->prepare('UPDATE users SET archived_at=NULL WHERE id=?')->execute([$pairA]);
$pdo->prepare("UPDATE glasses_devices SET status='revoked',revoked_at=NOW(6) WHERE id=?")->execute([$a['id']]);
hgt_reject(fn()=>$end($sessionA,$a),'Revoked cached device','Active paired glasses');
$pdo->prepare("UPDATE glasses_devices SET status='active',revoked_at=NULL,token_hash=? WHERE id=?")->execute([hash('sha256',bin2hex(random_bytes(32))),$a['id']]);
hgt_reject(fn()=>$end($sessionA,$a),'A rotated token invalidates a cached device','Active paired glasses');
$pdo->prepare('UPDATE glasses_devices SET token_hash=? WHERE id=?')->execute([$a['token_hash'],$a['id']]);
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before,'Account, revocation and re-pair denials create no training writes.');

// Corrupt parent references fail closed; no tenant or employee ledger is silently repaired.
$assignmentA=$f['assignments']['A'];
foreach(['device_id'=>$b['id'],'user_id'=>$f['users']['operatorB']['id'],'training_assignment_id'=>$f['assignments']['B']['training_assignment_id']] as $column=>$value){
    $pdo->prepare('UPDATE glasses_training_assignments SET '.$column.'=? WHERE id=?')->execute([$value,$assignmentA['id']]);
    $corrupt=hgt_fixture_snapshot($pdo,$org);hgt_reject(fn()=>$end($sessionA,$a),'Inconsistent '.$column,'manager correction');
    hgt_check(hgt_fixture_snapshot($pdo,$org)===$corrupt,'Rejected '.$column.' preserves historical evidence.');
    $pdo->prepare('UPDATE glasses_training_assignments SET '.$column.'=? WHERE id=?')->execute([$assignmentA[$column],$assignmentA['id']]);
}
$pdo->prepare("INSERT INTO training_assignments(organization_id,user_id,assignment_type,assignment_reference_id,assigned_by,status) VALUES (?,?,'glasses_vision_program',?,?,'assigned')")
    ->execute([$f['foreignOrganizationId'],$f['users']['foreign']['id'],$f['program']['public_id'],$f['users']['foreign']['id']]);$foreignGeneric=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE glasses_training_assignments SET training_assignment_id=? WHERE id=?')->execute([$foreignGeneric,$assignmentA['id']]);
$corrupt=hgt_fixture_snapshot($pdo,$org);hgt_reject(fn()=>$end($sessionA,$a),'Foreign canonical training reference','manager correction');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$corrupt&&hgt_value($pdo,'SELECT status FROM training_assignments WHERE id=?',[$foreignGeneric])==='assigned','Foreign generic training ledger remains untouched.');
$pdo->prepare('UPDATE glasses_training_assignments SET training_assignment_id=? WHERE id=?')->execute([$assignmentA['training_assignment_id'],$assignmentA['id']]);

$completed=$end($sessionA,$a);$state=hgt_assignment_state($pdo,$org,$assignmentA);
hgt_check($completed['status']==='completed'&&$completed['ended_at']!==null&&$state['status']==='completed'&&$state['generic_status']==='completed'&&$state['completed_at']!==null&&$state['generic_completed_at']!==null,'Completion commits session and both assignment ledgers together.');
$before=hgt_fixture_snapshot($pdo,$org);$replay=$end($sessionA,$a,'cancelled');
hgt_check($replay['status']==='completed'&&$replay['ended_at']===$completed['ended_at']&&$replay['end_reason']===$completed['end_reason'],'A conflicting own replay returns the original terminal outcome.');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before&&hgt_end_count($pdo,$org,$sessionA['public_id'])===1,'Terminal replay never repeats completion, audit, timestamps or events.');
hgt_check((int)hgt_value($pdo,"SELECT actor_user_id FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?",[$org,$sessionA['public_id']])===$pairA,'Device completion is attributed to the actual paired account, not the trainee or assigning manager.');
hgt_reject(fn()=>$end($sessionA,$b),'Another device cannot read a completed session through replay','Training session not found.');

$interrupted=$end($sessionB,$b,'interrupted');$state=hgt_assignment_state($pdo,$org,$f['assignments']['B']);
hgt_check($interrupted['status']==='interrupted'&&$state['status']==='assigned'&&$state['generic_status']==='assigned'&&$state['completed_at']===null&&$state['generic_completed_at']===null,'Interruption leaves a coherent retryable assignment with no completion timestamps.');
$nextB=$start($f['assignments']['B'],$b);$before=hgt_fixture_snapshot($pdo,$org);$end($sessionB,$b,'completed');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before&&$nextB['public_id']!==$sessionB['public_id'],'An old terminal replay cannot change a newer session or its assignment.');
$forgedViewer=$f['users']['viewer'];$forgedViewer['permissions']=['*'];
hgt_reject(fn()=>glasses_training_session_end_managed($pdo,$forgedViewer,$nextB['public_id'],'completed',''),'Caller-supplied manager permissions are ignored','glasses.manage');
$pdo->prepare("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key='glasses.manage'")->execute([$manager['role_id']]);
hgt_reject(fn()=>glasses_training_session_end_managed($pdo,$manager,$nextB['public_id'],'completed',''),'Revoked manager permission is checked again','glasses.manage');
$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='glasses.manage'")->execute([$manager['role_id']]);
$pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE id=?")->execute([$manager['membership_id']]);
hgt_reject(fn()=>glasses_training_session_end_managed($pdo,$manager,$nextB['public_id'],'completed',''),'Suspended manager cannot finish a session');
$pdo->prepare("UPDATE organization_memberships SET status='active' WHERE id=?")->execute([$manager['membership_id']]);
hgt_reject(fn()=>glasses_training_session_end_managed($pdo,$f['users']['foreign'],$nextB['public_id'],'completed',''),'Foreign manager cannot override the session','Training session not found.');
hgt_check(hgt_fixture_snapshot($pdo,$org)===$before,'All manager denials leave the session and ledgers unchanged.');
glasses_training_session_end_managed($pdo,$manager,$nextB['public_id'],'completed','Authorized manager override');
hgt_check((int)hgt_value($pdo,"SELECT actor_user_id FROM audit_log WHERE organization_id=? AND action='glasses.training_session_ended' AND entity_id=?",[$org,$nextB['public_id']])===$manager['id'],'Manager overrides retain the real manager actor.');

// The separate management route may explicitly close training on a revoked device.
$recoverAssignment=hgt_fixture_assignment($pdo,$f);$recoverSession=$start($recoverAssignment,$a);
$pdo->prepare("UPDATE glasses_devices SET status='revoked',revoked_at=NOW(6) WHERE id=?")->execute([$a['id']]);
$pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE organization_id=? AND user_id=?")->execute([$org,$pairA]);
glasses_training_session_end_managed($pdo,$manager,$recoverSession['public_id'],'interrupted','Manager cleanup after device revocation');
hgt_check(glasses_training_session($pdo,$org,$recoverSession['public_id'])['status']==='interrupted','Authorized manager cleanup remains available after device/account revocation.');
$pdo->prepare("UPDATE glasses_devices SET status='active',revoked_at=NULL WHERE id=?")->execute([$a['id']]);
$pdo->prepare("UPDATE organization_memberships SET status='active' WHERE organization_id=? AND user_id=?")->execute([$org,$pairA]);

// Failed audit inserts roll the entire lifecycle operation back, including nested calls.
$rollbackAssignment=hgt_fixture_assignment($pdo,$f,'B','operatorB');$rollbackSession=$start($rollbackAssignment,$b);$trigger='hgt_audit_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id=$org AND NEW.action IN ('glasses.training_session_started','glasses.training_session_ended') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='training audit failure fixture'; END IF; END");
try{
    $before=hgt_fixture_snapshot($pdo,$org);$failed=false;try{$end($rollbackSession,$b);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'training audit failure fixture');}
    hgt_check($failed&&hgt_fixture_snapshot($pdo,$org)===$before,'Audit failure rolls back session, both assignments and device event.');
    $pdo->beginTransaction();$pdo->prepare("UPDATE organizations SET name='Outer training transaction marker' WHERE id=?")->execute([$org]);
    $failed=false;try{$end($rollbackSession,$b,'cancelled');}catch(PDOException $error){$failed=str_contains($error->getMessage(),'training audit failure fixture');}
    hgt_check($failed&&$pdo->inTransaction()&&hgt_fixture_snapshot($pdo,$org)===$before,'A nested audit failure rolls back the operation while preserving its outer transaction.');
    hgt_check(hgt_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])==='Outer training transaction marker','Nested rollback preserves earlier caller writes.');$pdo->rollBack();
    $before=hgt_fixture_snapshot($pdo,$org);$failed=false;try{$start($recoverAssignment,$a);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'training audit failure fixture');}
    hgt_check($failed&&hgt_fixture_snapshot($pdo,$org)===$before,'A failed start audit cannot leave an active session or assignment behind.');
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}
$end($rollbackSession,$b,'cancelled');

// Existing runtime management uses the same canonical lifecycle and current manager actor.
$freshManager=accounts_actor($pdo,$org,$manager['id']);
$station=kds_station_save($pdo,$org,$f['locations']['home'],['name'=>'Training runtime station','slug'=>'hgt-runtime-'.bin2hex(random_bytes(5)),'targetSeconds'=>300],$manager['id']);
$runtime=glasses_employee_runtime_create($pdo,$freshManager,$a['public_id'],$f['users']['operatorA']['id'],$station['public_id']);
$runtimeAssignment=hgt_fixture_assignment($pdo,$f);
$runtimeSession=glasses_employee_runtime_training_start($pdo,$freshManager,$runtime['publicId'],$runtimeAssignment['public_id']);
hgt_check($runtimeSession['context']['runtimePublicId']===$runtime['publicId']&&(int)$runtimeSession['created_by']===$manager['id'],'Runtime training retains runtime binding and the authenticated manager actor.');
hgt_reject(fn()=>glasses_employee_runtime_training_end($pdo,$forgedViewer,$runtime['publicId'],$runtimeSession['public_id'],'completed'),'Runtime cannot trust a stale or forged manager');
$runtimeEnd=glasses_employee_runtime_training_end($pdo,$freshManager,$runtime['publicId'],$runtimeSession['public_id'],'completed');
hgt_check($runtimeEnd['status']==='completed'&&hgt_end_count($pdo,$org,$runtimeSession['public_id'])===1,'Runtime completion uses the atomic, audited manager transition.');
echo "Glasses training ownership: PASS ($checks checks; device/tenant/account denial, parent ledger integrity, replay, manager/runtime authority, audit attribution and rollback)\n";
