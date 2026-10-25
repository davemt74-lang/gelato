<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/food-monster-pin.php';

/** AE2 enrollment authorizes provisional visual labels only, never sign-in. */
function fmeyes_ready(PDO $pdo): bool
{
    static $ready=null;
    if($ready!==null)return $ready;
    try{$ready=(bool)$pdo->query("SHOW TABLES LIKE 'fm_eyes_local_enrollments'")->fetchColumn();}
    catch(Throwable $e){$ready=false;}
    return $ready;
}
function fmeyes_device(PDO $pdo,array $operator):array
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin session required.');
    if(!fmeyes_ready($pdo))throw new RuntimeException('Agent Eyes migration required. Run upgrade.php.');
    $device=fmpin_device($pdo);
    if(!$device||(int)$device['organization_id']!==(int)$operator['organization_id'])
        throw new DomainException('Use a registered, approved terminal.');
    return $device;
}
function fmeyes_candidates(PDO $pdo,array $operator):array
{
    $device=fmeyes_device($pdo,$operator);
    $org=(int)$operator['organization_id'];
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.display_name,u.email,
       CASE WHEN e.revoked_at IS NULL AND e.user_id IS NOT NULL THEN 1 ELSE 0 END AS enrolled
       FROM users u
       INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
       LEFT JOIN fm_eyes_local_enrollments e ON e.organization_id=om.organization_id AND e.device_id=? AND e.user_id=u.id
       WHERE u.status='active' AND u.archived_at IS NULL
       ORDER BY u.display_name,u.id LIMIT 300");
    $q->execute([$org,(int)$device['id']]);
    return array_map(static fn($r)=>['id'=>(int)$r['id'],
        'name'=>(string)$r['display_name'],'email'=>(string)$r['email'],
        'enrolled'=>(int)$r['enrolled']===1],$q->fetchAll(PDO::FETCH_ASSOC));
}
function fmeyes_commit(PDO $pdo,array $operator,int $personId,bool $consent):array
{
    if(!$consent)throw new InvalidArgumentException('The participant must consent to local face enrollment.');
    if($personId<1)throw new InvalidArgumentException('Select an existing participant.');
    $device=fmeyes_device($pdo,$operator);
    $org=(int)$operator['organization_id'];
    $q=$pdo->prepare("SELECT u.id,u.email,u.display_name FROM users u
      INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
      WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$personId]);$person=$q->fetch(PDO::FETCH_ASSOC);
    if(!$person)throw new DomainException('Participant unavailable in this organization.');
    $q=$pdo->prepare("INSERT INTO fm_eyes_local_enrollments
       (organization_id,device_id,user_id,approved_by,consented_at,revoked_at)
       VALUES (?,?,?,?,NOW(6),NULL)
       ON DUPLICATE KEY UPDATE approved_by=VALUES(approved_by),consented_at=NOW(6),revoked_at=NULL");
    $q->execute([$org,(int)$device['id'],$personId,(int)$operator['id']]);
    app_audit($pdo,$org,(int)$operator['id'],'agent_eyes.enrollment_approved','user',(string)$personId);
    return ['id'=>$personId,'email'=>strtolower((string)$person['email']),
        'name'=>(string)$person['display_name']];
}
function fmeyes_revoke(PDO $pdo,array $operator,int $personId):array
{
    if($personId<1)throw new InvalidArgumentException('Select a participant.');
    $device=fmeyes_device($pdo,$operator);$org=(int)$operator['organization_id'];
    $q=$pdo->prepare("SELECT u.email FROM users u INNER JOIN fm_eyes_local_enrollments e
      ON e.user_id=u.id AND e.organization_id=? AND e.device_id=?
      WHERE u.id=? LIMIT 1");
    $q->execute([$org,(int)$device['id'],$personId]);$email=$q->fetchColumn();
    if($email===false)throw new DomainException('No enrollment on this terminal.');
    $pdo->prepare("UPDATE fm_eyes_local_enrollments SET revoked_at=NOW(6)
       WHERE organization_id=? AND device_id=? AND user_id=?")
       ->execute([$org,(int)$device['id'],$personId]);
    app_audit($pdo,$org,(int)$operator['id'],'agent_eyes.enrollment_revoked','user',(string)$personId);
    return ['email'=>strtolower((string)$email)];
}
