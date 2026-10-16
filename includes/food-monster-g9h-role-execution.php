<?php
declare(strict_types=1);
/**
 * FM-G9H: narrowly scoped, one-time role permission execution.
 * Uses Gelato's canonical roles/role_permissions/permissions tables.
 * An owner must explicitly approve, then type Confirm in the same Agent Chat.
 * No API caller or model chooses the affected role or permission at execution.
 */
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/agent-workspace-core.php';

function fm9h_owner_required(array $operator):void {
    if(!fm9a_is_super_admin_session($operator)
        ||!app_has_permission('roles.edit',$operator)
        ||!app_has_permission('roles.assign_permissions',$operator))
        throw new DomainException('Super Admin role and permission authority required.');
}
function fm9h_parse_role_request(string $summary):array {
    if(!preg_match('/^role\s+(?:db-role-)?([1-9][0-9]{0,8})\s+(grant|revoke)\s+([a-z][a-z0-9_.]{1,79})$/D',
        trim($summary),$m))throw new InvalidArgumentException(
            'Specify: role db-role-123 grant tasks.view (or revoke).');
    return ['roleId'=>(int)$m[1],'operation'=>$m[2],'permission'=>$m[3]];
}
function fm9h_role_state(PDO $pdo,int $org,int $roleId,bool $lock=false):array {
    $q=$pdo->prepare('SELECT id,name,description,is_owner_role,is_system_role
        FROM roles WHERE organization_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$org,$roleId]);$role=$q->fetch(PDO::FETCH_ASSOC);
    if(!$role||(int)$role['is_owner_role']!==0||(int)$role['is_system_role']!==0)
        throw new DomainException('Only this organization’s unprotected custom roles may be changed.');
    $p=$pdo->prepare('SELECT p.permission_key FROM role_permissions rp
        INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? ORDER BY p.permission_key');
    $p->execute([$roleId]);$keys=array_map('strval',$p->fetchAll(PDO::FETCH_COLUMN));
    return ['id'=>$roleId,'name'=>(string)$role['name'],'description'=>(string)($role['description']??''),
        'keys'=>$keys,'version'=>hash('sha256',json_encode([$roleId,(string)$role['name'],
            (string)($role['description']??''),$keys],JSON_THROW_ON_ERROR))];
}
function fm9h_prepare_role(PDO $pdo,array $operator,string $summary):array {
    fm9h_owner_required($operator);
    $request=fm9h_parse_role_request($summary);
    $state=fm9h_role_state($pdo,(int)$operator['organization_id'],$request['roleId']);
    $q=$pdo->prepare('SELECT id FROM permissions WHERE permission_key=?');
    $q->execute([$request['permission']]);$permissionId=(int)$q->fetchColumn();
    if($permissionId<1)throw new InvalidArgumentException('That Gelato permission does not exist.');
    $present=in_array($request['permission'],$state['keys'],true);
    if(($request['operation']==='grant'&&$present)||($request['operation']==='revoke'&&!$present))
        throw new DomainException('Permission already has the requested state.');
    return $request+[
        'permissionId'=>$permissionId,
        'roleName'=>$state['name'],
        'version'=>$state['version'],
        'summary'=>ucfirst($request['operation']).' '.$request['permission'].' on role '.$state['name']
            .' (db-role-'.$request['roleId'].').'
    ];
}
function fm9h_grant_key(array $operator):string {
    return (int)$operator['organization_id'].':'.(int)$operator['id'];
}
function fm9h_issue_grant(array $operator,array $binding,string $threadId,string $approvalId):array {
    fm9h_owner_required($operator);
    if(!preg_match('/^gap-[a-f0-9]{16}$/D',$approvalId)
        ||!preg_match('/^agent-[a-f0-9]{20}$/D',$threadId)
        ||empty($binding['version'])||empty($binding['permissionId']))
        throw new InvalidArgumentException('Role approval is incomplete.');
    $grant=$binding+['threadId'=>$threadId,'approvalId'=>$approvalId,
        'expires'=>time()+600,'consumed'=>false];
    $_SESSION['fm9h_role_grants'][fm9h_grant_key($operator)]=$grant;
    return ['approvalId'=>$approvalId,'roleId'=>'db-role-'.$binding['roleId'],
        'operation'=>$binding['operation'],'permission'=>$binding['permission'],
        'expiresAt'=>date(DATE_ATOM,$grant['expires']),'executed'=>false,
        'status'=>'approved_for_domain_confirmation'];
}
function fm9h_active_grant(array $operator,string $threadId):?array {
    if(!fm9a_is_super_admin_session($operator))return null;
    $grant=$_SESSION['fm9h_role_grants'][fm9h_grant_key($operator)]??null;
    return is_array($grant)&&($grant['consumed']??true)===false
        &&(int)($grant['expires']??0)>time()
        &&hash_equals((string)($grant['threadId']??''),$threadId)?$grant:null;
}
function fm9h_execute_role(PDO $pdo,array $operator,string $threadId):array {
    fm9h_owner_required($operator);
    $grant=fm9h_active_grant($operator,$threadId);
    if(!$grant)throw new DomainException('A current role permission approval is required in this conversation.');
    if($pdo->inTransaction())throw new LogicException('Permission update requires an independent transaction.');
    $org=(int)$operator['organization_id'];$uid=(int)$operator['id'];
    $pdo->beginTransaction();
    try {
        $state=fm9h_role_state($pdo,$org,(int)$grant['roleId'],true);
        if(!hash_equals((string)$grant['version'],$state['version']))
            throw new DomainException('Role permissions changed since approval. Request a new approval.');
        $q=$pdo->prepare('SELECT id FROM permissions WHERE id=? AND permission_key=?');
        $q->execute([(int)$grant['permissionId'],(string)$grant['permission']]);
        if((int)$q->fetchColumn()!==(int)$grant['permissionId'])
            throw new DomainException('The approved permission is no longer available.');
        if($grant['operation']==='grant'){
            if(in_array($grant['permission'],$state['keys'],true))
                throw new DomainException('Permission already granted.');
            $pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)')
                ->execute([(int)$grant['roleId'],(int)$grant['permissionId']]);
        }elseif($grant['operation']==='revoke'){
            if(!in_array($grant['permission'],$state['keys'],true))
                throw new DomainException('Permission already revoked.');
            $q=$pdo->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?');
            $q->execute([(int)$grant['roleId'],(int)$grant['permissionId']]);
            if($q->rowCount()!==1)throw new RuntimeException('Permission change was not applied.');
        }else throw new DomainException('Invalid approved operation.');
        $event=[
            'collection'=>'fm9h_high_impact_execution','kind'=>'permission_change',
            'state'=>'executed','executed'=>true,'roleId'=>(int)$grant['roleId'],
            'permission'=>$grant['permission'],'operation'=>$grant['operation'],
            'approvalId'=>$grant['approvalId'],'actorUserId'=>$uid
        ];
        gaw_append($pdo,$org,$uid,$threadId,'system','Food Monster executed approved role permission change.',
            ['channel'=>'proactive','structured'=>$event]);
        app_audit($pdo,$org,$uid,'food_monster.permission_change_executed','role',
            (string)$grant['roleId'],null,$event);
        $pdo->commit();
        $_SESSION['fm9h_role_grants'][fm9h_grant_key($operator)]['consumed']=true;
        return ['executed'=>true,'roleId'=>'db-role-'.$grant['roleId'],
            'permission'=>$grant['permission'],'operation'=>$grant['operation'],
            'approvalId'=>$grant['approvalId'],'state'=>'executed'];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
