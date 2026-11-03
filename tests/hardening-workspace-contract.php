<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/workspace-bootstrap.php';

function hws_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function hws_scalar(PDO $pdo, string $sql, array $args = []): mixed
{
    $query = $pdo->prepare($sql); $query->execute($args); return $query->fetchColumn();
}
function hws_actor(PDO $pdo, int $organizationId, string $label, array $permissions): array
{
    $token = bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,0,0)")
        ->execute([$organizationId,$label,'hardening-'.$token]);
    $roleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
        ->execute([$label.'-'.$token.'@example.test',password_hash('Hardening-test!123',PASSWORD_DEFAULT),$label,'Staff',$label.' Staff']);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,job_title,status) VALUES (?,?,'Employee','active')")
        ->execute([$organizationId,$userId]);
    $membershipId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)')->execute([$membershipId,$roleId]);
    $query=$pdo->prepare('SELECT u.*,om.organization_id,om.job_title,r.id role_id,r.name role_name,r.slug role_slug,r.description role_description,r.is_system_role,r.is_owner_role FROM users u JOIN organization_memberships om ON om.user_id=u.id JOIN user_roles ur ON ur.membership_id=om.id JOIN roles r ON r.id=ur.role_id WHERE u.id=? AND om.organization_id=?');
    $query->execute([$userId,$organizationId]);
    $user=$query->fetch(); $user['permissions']=$permissions; return $user;
}
$pdo=app_pdo();
hws_assert($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) == false,'Tests must use production native prepares.');
$pdo->beginTransaction();
try {
    $name='Hardening workspace '.bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$name]);
    $org=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$name.' other']);
    $other=(int)$pdo->lastInsertId();
    $employee=hws_actor($pdo,$org,'Employee',['dashboard.view','inventory.view']);
    $manager=hws_actor($pdo,$org,'Manager',['users.view','roles.view','jobs.view']);
    $outsider=hws_actor($pdo,$other,'Outsider',['*']);
    $insert=$pdo->prepare("INSERT INTO jobs (organization_id,public_id,slug,title,status) VALUES (?,?,?,?,?)");
    $insert->execute([$org,'hardening-draft','hardening-draft','Private hiring plan','draft']);
    $insert->execute([$org,'hardening-live','hardening-live','Public vacancy','published']);
    $insert->execute([$other,'hardening-other','hardening-other','Other organization secret','draft']);
    $before=(int)hws_scalar($pdo,'SELECT COUNT(*) FROM jobs WHERE organization_id=?',[$org]);
    $payload=workspace_bootstrap($pdo,$employee);
    hws_assert(count($payload['users'])===1&&$payload['users'][0]['id']==='db-user-'.$employee['id'],'Unprivileged workspace must contain only its own user.');
    hws_assert(count($payload['roles'])===1&&$payload['roles'][0]['id']==='db-role-'.$employee['role_id'],'Unprivileged workspace must contain only its own role.');
    hws_assert($payload['currentUser']['role']['permissions']===['dashboard.view','inventory.view'],'Own role must retain all effective permissions.');
    hws_assert($payload['permissions']===[]&&$payload['jobs']===[],'Unprivileged workspace must contain neither permission catalogue nor unpublished jobs.');
    $json=json_encode($payload,JSON_THROW_ON_ERROR);
    hws_assert(!str_contains($json,$manager['email'])&&!str_contains($json,$outsider['email'])&&!str_contains($json,'Private hiring plan'),'Unprivileged bootstrap must not disclose hidden records.');
    hws_assert(!str_contains($json,'password_hash')&&!str_contains($json,$employee['password_hash']),'Even the current actor must not receive credentials.');
    hws_assert($payload['organizationId']===$org&&strlen($payload['session']['identity'])===32,'Private draft scope must include organization and a noncredential session nonce.');

    $usersOnly=$employee; $usersOnly['permissions']=['users.view'];
    $payload=workspace_bootstrap($pdo,$usersOnly);
    hws_assert(count($payload['users'])===2,'users.view may receive its organization roster.');
    hws_assert(count($payload['roles'])===1&&$payload['permissions']===[]&&$payload['jobs']===[],'users.view must not imply roles.view or jobs.view.');
    $rolesOnly=$employee; $rolesOnly['permissions']=['roles.view'];
    $payload=workspace_bootstrap($pdo,$rolesOnly);
    hws_assert(count($payload['users'])===1&&count($payload['roles'])>=2&&count($payload['permissions'])>0,'roles.view may receive roles/catalogue but not another user record.');
    hws_assert($payload['jobs']===[],'roles.view must not imply jobs.view.');
    $jobsOnly=$employee; $jobsOnly['permissions']=['jobs.view'];
    $payload=workspace_bootstrap($pdo,$jobsOnly);
    hws_assert(count($payload['jobs'])===2&&count($payload['users'])===1&&count($payload['roles'])===1,'jobs.view may receive own-organization jobs without roster access.');
    $payload=workspace_bootstrap($pdo,$manager);
    hws_assert(count($payload['users'])===2&&count($payload['jobs'])===2,'Authorized manager retains the organization workspace records.');
    $json=json_encode($payload,JSON_THROW_ON_ERROR);
    hws_assert(!str_contains($json,$outsider['email'])&&!str_contains($json,'Other organization secret'),'Authorized workspace must still enforce organization isolation.');
    hws_assert((int)hws_scalar($pdo,'SELECT COUNT(*) FROM jobs WHERE organization_id=?',[$org])===$before,'Reading any workspace must not create or publish jobs.');

    $owner=$employee; $owner['role_slug']='super_admin'; $owner['is_system_role']=1; $owner['permissions']=['*'];
    $payload=workspace_bootstrap($pdo,$owner);
    hws_assert($payload['currentUser']['role']['owner']===true&&$payload['currentUser']['role']['permissions']===['*'],'Canonical owner authority must survive the bootstrap.');
    $impostor=$employee; $impostor['role_slug']='super_admin'; $impostor['is_system_role']=0;
    hws_assert(workspace_bootstrap($pdo,$impostor)['currentUser']['role']['owner']===false,'A custom role name must not create owner authority.');
    $pdo->rollBack();
    echo "hardening workspace permissions behavioral contract passed\n";
} catch (Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}
