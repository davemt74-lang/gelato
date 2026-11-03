<?php
declare(strict_types=1);
require_once __DIR__.'/operations-core.php';
/**
 * Canonical Operations Core task archive. Preserves rows and history.
 * Both Operations UI and approved Agent Brain requests use this service.
 */
function operations_archive_task(PDO $pdo,array $operator,string $publicId,
    ?string $expectedStatus=null,?string $expectedUpdatedAt=null,?callable $onArchived=null,
    ?callable $authorizeArchive=null):array
{
    // Load this only at execution, so pure Agent policy loaders remain independent
    // of the application bootstrap.
    require_once __DIR__.'/user-accounts.php';
    $org=(int)($operator['organization_id']??0);$uid=(int)($operator['id']??0);
    if($org<1||$uid<1||!app_has_permission('tasks.manage',$operator))
        throw new DomainException('Task management authority required.');
    if(!preg_match('/^task-[a-f0-9]{20}$/D',$publicId))
        throw new InvalidArgumentException('Invalid task reference.');
    if($pdo->inTransaction())throw new LogicException('Task archiving requires its own transaction.');
    $pdo->beginTransaction();
    try {
        // Account and role writers acquire the restaurant first, then identities.
        // Hold those authorities while waiting for the task; a revocation which
        // obtained the same locks first must be visible before this action runs.
        accounts_lock_org($pdo,$org);
        accounts_lock_users($pdo,[$uid]);
        $q=$pdo->prepare('SELECT id FROM organization_memberships
            WHERE organization_id=? AND user_id=? FOR UPDATE');
        $q->execute([$org,$uid]);$q->fetchColumn();
        $q=$pdo->prepare('SELECT * FROM restaurant_tasks
            WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');
        $q->execute([$org,$publicId]);$task=$q->fetch(PDO::FETCH_ASSOC);
        // This is a current locking read after every possible preceding wait.
        // Role assignments/permissions are stable under the restaurant lock.
        $fresh=accounts_actor($pdo,$org,$uid,true);
        if(!app_has_permission('tasks.manage',$fresh))
            throw new DomainException('Task management authority required.');
        $fresh['is_owner_role']=0;
        foreach(accounts_roles($pdo,$org,(int)$fresh['membership_id']) as $role){
            if($role['owner']){$fresh['is_owner_role']=1;break;}
        }
        // A server-owned caller may impose a stricter policy (for example a
        // current owner and unexpired approved Agent action) inside this commit.
        if($authorizeArchive)$authorizeArchive($fresh);
        if(!$task)throw new InvalidArgumentException('Task not found or already archived.');
        if(!empty($task['source_type']))
            throw new DomainException('Source-linked tasks cannot be archived; use their source workflow.');
        if(($expectedStatus!==null && (string)$task['status']!==$expectedStatus)
            ||($expectedUpdatedAt!==null && (string)$task['updated_at']!==$expectedUpdatedAt))
            throw new DomainException('Task changed since approval. A new review is required.');
        $q=$pdo->prepare('UPDATE restaurant_tasks SET archived_at=NOW(6),
            status="cancelled",updated_by=?,updated_at=NOW(6)
            WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$uid,(int)$task['id'],$org]);
        if($q->rowCount()!==1)throw new RuntimeException('Task archival did not complete.');
        operations_task_event($pdo,$org,(int)$task['id'],'archived','Task archived',$uid);
        app_audit($pdo,$org,$uid,'tasks.archived','restaurant_task',$publicId);
        $receipt=['taskId'=>$publicId,'archived'=>true,'status'=>'cancelled',
            'formerStatus'=>(string)$task['status']];
        if($onArchived)$onArchived($receipt,$task);
        $pdo->commit();
        return $receipt;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
