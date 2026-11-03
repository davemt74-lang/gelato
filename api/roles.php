<?php
declare(strict_types=1);
require __DIR__ . '/../includes/user-accounts.php';

$user=app_require_auth();
$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$actorId=(int)$user['id'];

function role_list(PDO $pdo,int $organizationId):array
{
    $rows=accounts_roles($pdo,$organizationId);
    usort($rows,static fn(array $a,array $b):int=>((int)$b['owner']<=>(int)$a['owner'])?:strnatcasecmp((string)$a['name'],(string)$b['name']));
    return array_map(static fn(array $row):array=>[
        'id'=>'db-role-'.$row['id'],'name'=>$row['name'],'slug'=>$row['slug'],'description'=>$row['description']??'',
        'system'=>(bool)$row['is_system_role'],'owner'=>$row['owner'],'permissions'=>$row['permissions'],
    ],$rows);
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        accounts_require($user,'roles.view');
        app_json_response(['ok'=>true,'roles'=>role_list($pdo,$organizationId)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $input=app_json_input();app_verify_request_csrf($input);
    $action=(string)($input['action']??'update');
    if(!in_array($action,['create','update'],true))throw new InvalidArgumentException('Unsupported account-type action.');
    $name=trim((string)($input['name']??''));$description=trim((string)($input['description']??''));
    if($name===''||mb_strlen($name)>120||mb_strlen($description)>500)throw new InvalidArgumentException('Use an account-type name of 1–120 characters and a description of at most 500 characters.');
    if(!is_array($input['permissions']??[]))throw new InvalidArgumentException('Choose valid account-type permissions.');
    $permissionKeys=array_values(array_unique(array_filter(array_map('strval',$input['permissions']??[]))));sort($permissionKeys,SORT_STRING);
    $roleId=$action==='update'?accounts_id($input['roleId']??'','db-role-'):0;

    $pdo->beginTransaction();
    accounts_lock_org($pdo,$organizationId);
    // Lock affected identities in one stable order before their membership versions change.
    $affected=$roleId>0?accounts_role_user_ids($pdo,$organizationId,$roleId):[];
    accounts_lock_users($pdo,array_merge([$actorId],$affected));
    $actor=accounts_actor($pdo,$organizationId,$actorId,true);
    accounts_require($actor,'roles.edit');accounts_require($actor,'roles.assign_permissions');
    if($action==='create')accounts_require($actor,'roles.create');
    $owner=in_array('*',$actor['permissions'],true);
    if(!$owner&&array_diff($permissionKeys,$actor['permissions']))throw new DomainException('You cannot grant permissions beyond your own authority.');
    $validStatement=$pdo->prepare('SELECT permission_key FROM permissions WHERE permission_key IN ('.implode(',',array_fill(0,max(1,count($permissionKeys)),'?')).')');
    $validStatement->execute($permissionKeys?:['__none__']);$validKeys=$validStatement->fetchAll(PDO::FETCH_COLUMN);sort($validKeys,SORT_STRING);
    if($validKeys!==$permissionKeys)throw new InvalidArgumentException('One or more selected permissions are invalid.');
    $previous=null;$oldKeys=[];
    if($action==='create'){
        $slug=strtolower(trim((string)($input['slug']??'')));
        $slug=trim((string)preg_replace('/[^a-z0-9]+/','_',$slug?:$name),'_');
        if($slug===''||strlen($slug)>120)throw new InvalidArgumentException('Use a valid account-type identifier of at most 120 characters.');
        $q=$pdo->prepare('INSERT INTO roles(organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable) VALUES (?,?,?,?,0,0,1)');
        $q->execute([$organizationId,$name,$slug,$description?:null]);$roleId=(int)$pdo->lastInsertId();$auditAction='role.created';
    }else{
        $q=$pdo->prepare('SELECT id FROM roles WHERE id=? AND organization_id=? FOR UPDATE');$q->execute([$roleId,$organizationId]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Account type not found.');
        foreach(accounts_roles($pdo,$organizationId) as $role)if((int)$role['id']===$roleId)$previous=$role;
        if(!$previous)throw new InvalidArgumentException('Account type not found.');
        if($previous['owner'])throw new InvalidArgumentException('The protected Super Admin account type cannot be edited.');
        $oldKeys=$previous['permissions'];sort($oldKeys,SORT_STRING);
        if(!$owner&&array_diff($oldKeys,$actor['permissions']))throw new DomainException('An account type with broader permissions must be managed by its owner.');
        if(!$owner&&in_array($actorId,array_map('intval',$affected),true)&&array_diff($validKeys,$oldKeys))
            throw new DomainException('An owner must add permissions to an account type assigned to you.');
        $pdo->prepare('UPDATE roles SET name=?,description=? WHERE id=? AND organization_id=?')->execute([$name,$description?:null,$roleId,$organizationId]);$auditAction='role.updated';
    }
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
    $grant=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
    foreach($validKeys as $key)$grant->execute([$roleId,$key]);
    if($action==='update'&&$oldKeys!==$validKeys){
        accounts_role_revoke_sessions($pdo,$organizationId,$roleId);
    }
    app_audit($pdo,$organizationId,$actorId,$auditAction,'role',(string)$roleId,
        $previous?['name'=>$previous['name'],'permissions'=>$oldKeys]:null,['name'=>$name,'permissions'=>$validKeys]);
    $pdo->commit();
    app_json_response(['ok'=>true,'message'=>$action==='create'?'Account type created.':'Account type permissions updated.','roles'=>role_list($pdo,$organizationId)]);
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $status=$error instanceof DomainException?403:($error instanceof InvalidArgumentException?422:500);
    if($status===500)error_log('Account type update failed: '.$error->getMessage());
    app_json_response(['ok'=>false,'message'=>$status===500?'The account type could not be saved.':$error->getMessage()],$status);
}
