<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
$pdo=app_pdo();
if(($argv[1]??'seed')==='inspect'){
    $org=(int)($argv[2]??0);$out=[];
    foreach(['equipment_assets'=>['public_id','name','created_by','updated_by'],'equipment_service_contacts'=>['public_id','company_name','created_by','updated_by'],'knowledge_documents'=>['public_id','title','content','version','created_by','updated_by']] as $table=>$fields){
        $q=$pdo->prepare('SELECT '.implode(',',$fields).' FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$out[$table]=$q->fetchAll();
    }
    $q=$pdo->prepare("SELECT action FROM audit_log WHERE organization_id=? AND action IN ('equipment.created','equipment.updated','equipment.contact_created','equipment.contact_updated','knowledge.created','knowledge.updated') ORDER BY id");$q->execute([$org]);$out['audits']=$q->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
$suffix=bin2hex(random_bytes(6));$orgs=[];$users=[];$password='Native-PDO-Hardening!42';
foreach(['home','other'] as $kind){
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Native PDO '.$kind.' '.$suffix]);$orgs[$kind]=(int)$pdo->lastInsertId();
}
foreach(['owner','employee','otherOwner','roleManager'] as $kind){
    $org=$orgs[$kind==='otherOwner'?'other':'home'];$email=$kind.'-'.$suffix.'@example.test';$owner=in_array($kind,['owner','otherOwner'],true);
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at) VALUES (?,?,?,'PDO',?,'active',NOW(6),NOW(6))")->execute([$email,app_password_hash($password),$kind,$kind.' PDO']);$uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);$membership=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,?,?)')->execute([$org,'PDO '.$kind,'pdo-'.$kind,$owner?1:0,$owner?1:0]);$role=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
    $users[$kind]=['id'=>$uid,'email'=>$email,'organizationId'=>$org,'membershipId'=>$membership,'roleId'=>$role];
}
$grant=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key=?');
foreach(['roles.view','roles.create','roles.edit','roles.assign_permissions'] as $key)$grant->execute([$users['roleManager']['roleId'],$key]);
$testRoles=[];
foreach(['managerExtra'=>['Manager extra','pdo-manager-extra','dashboard.view',0],'broader'=>['Broader authority','pdo-broader','equipment.edit',0],'legacyProtected'=>['Legacy Super Admin','super_admin',null,1]] as $key=>$definition){
    $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,?,0)')->execute([$orgs['home'],$definition[0],$definition[1],$definition[3]]);$id=(int)$pdo->lastInsertId();$testRoles[$key]=$id;
    if($definition[2]!==null)$grant->execute([$id,$definition[2]]);
}
$pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$users['roleManager']['membershipId'],$testRoles['managerExtra'],$users['owner']['id']]);
echo json_encode(['password'=>$password,'users'=>$users,'organizations'=>$orgs,'roles'=>$testRoles,'nativePrepares'=>!(bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)],JSON_THROW_ON_ERROR);
