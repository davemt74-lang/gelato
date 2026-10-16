<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/agent-workspace-core.php';
require_once __DIR__.'/operations-archive-core.php';

function fm9i_key(array $u):string{return (int)$u['organization_id'].':'.(int)$u['id'];}
function fm9i_require_owner(array $u):void {
    if(!fm9a_is_super_admin_session($u)||!app_has_permission('tasks.manage',$u))
        throw new DomainException('Super Admin task-management authority required.');
}
function fm9i_prepare(PDO $pdo,array $u,string $summary):array {
    fm9i_require_owner($u);
    if(!preg_match('/^task\s+(task-[a-f0-9]{20})$/D',trim($summary),$m))
        throw new InvalidArgumentException('Use: task task-<20-character-id>.');
    $q=$pdo->prepare('SELECT public_id,title,status,updated_at,source_type
        FROM restaurant_tasks WHERE organization_id=? AND public_id=?
        AND archived_at IS NULL LIMIT 1');
    $q->execute([(int)$u['organization_id'],$m[1]]);$task=$q->fetch(PDO::FETCH_ASSOC);
    if(!$task)throw new InvalidArgumentException('Task not found.');
    if(!empty($task['source_type']))
        throw new DomainException('A source-linked operational report cannot be archived through this action.');
    return ['taskId'=>$m[1],'status'=>(string)$task['status'],
        'updatedAt'=>(string)$task['updated_at'],
        'summary'=>'Archive task '.$m[1].' — '.mb_substr((string)$task['title'],0,110,'UTF-8')];
}
function fm9i_issue_grant(array $u,array $binding,string $thread,string $approvalId):array {
    fm9i_require_owner($u);
    if(!preg_match('/^gap-[a-f0-9]{16}$/D',$approvalId)||empty($binding['taskId'])
        ||empty($binding['updatedAt'])||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread))
        throw new InvalidArgumentException('Incomplete task archive approval.');
    $_SESSION['fm9i_task_archive_grants'][fm9i_key($u)]=$binding+[
        'approvalId'=>$approvalId,'threadId'=>$thread,
        'expires'=>time()+600,'consumed'=>false];
    return ['approvalId'=>$approvalId,'taskId'=>$binding['taskId'],'executed'=>false,
        'expiresAt'=>date(DATE_ATOM,time()+600),'status'=>'approved_for_domain_confirmation'];
}
function fm9i_active_grant(array $u,string $thread):?array {
    if(!fm9a_is_super_admin_session($u))return null;
    $r=$_SESSION['fm9i_task_archive_grants'][fm9i_key($u)]??null;
    return is_array($r)&&($r['consumed']??true)===false
        &&(int)($r['expires']??0)>time()
        &&hash_equals((string)($r['threadId']??''),$thread)?$r:null;
}
function fm9i_execute(PDO $pdo,array $u,string $thread):array {
    fm9i_require_owner($u);
    $grant=fm9i_active_grant($u,$thread);
    if(!$grant)throw new DomainException('A current task archive approval is required.');
    $org=(int)$u['organization_id'];$uid=(int)$u['id'];
    $result=operations_archive_task($pdo,$u,(string)$grant['taskId'],
        (string)$grant['status'],(string)$grant['updatedAt'],
        static function(array $receipt)use($pdo,$org,$uid,$thread,$grant):void {
            $meta=['collection'=>'fm9i_high_impact_execution',
                'kind'=>'record_deletion','operation'=>'archive_restaurant_task',
                'executed'=>true,'state'=>'archived',
                'taskId'=>$receipt['taskId'],'approvalId'=>$grant['approvalId'],
                'actorUserId'=>$uid];
            gaw_append($pdo,$org,$uid,$thread,'system','Food Monster archived the approved task.',[
                'channel'=>'proactive','structured'=>$meta]);
            app_audit($pdo,$org,$uid,'food_monster.task_archive_executed','restaurant_task',
                $receipt['taskId'],null,$meta);
        });
    $_SESSION['fm9i_task_archive_grants'][fm9i_key($u)]['consumed']=true;
    return $result+['executed'=>true,'approvalId'=>$grant['approvalId']];
}
