<?php
declare(strict_types=1);

/**
 * FM-KB2: explicit protected-owner approval for one HomeServer connection.
 * No fixed domain, shared master token, browser cookie reuse or remote database.
 */
require_once __DIR__.'/food-monster-knowledge-api.php';

function fm_kb2_owner(array $user): bool
{
    return (int)($user['id']??0)>0 && (int)($user['organization_id']??0)>0
        && (int)($user['is_owner_role']??0)===1;
}

function fm_kb2_secret(string $prefix): string
{
    return $prefix.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
}

function fm_kb2_hash(string $secret): string
{
    return hash('sha256',$secret);
}

function fm_kb2_scopes(array $user,array $requested): array
{
    if(!fm_kb2_owner($user))throw new DomainException('Food Monster owner approval is required.');
    if(!$requested||count($requested)>2)throw new InvalidArgumentException('Select at least one knowledge category.');
    $authorized=fm_kb1_allowed_types($user);
    $scopes=[];
    foreach($requested as $value){
        if(!is_string($value)||!in_array($value,['menu','recipe'],true))
            throw new InvalidArgumentException('Invalid knowledge category.');
        if(!in_array($value,$authorized,true))throw new DomainException('Knowledge category not permitted.');
        if(in_array($value,$scopes,true))throw new InvalidArgumentException('Duplicate knowledge category.');
        $scopes[]=$value;
    }
    sort($scopes,SORT_STRING);
    return $scopes;
}

function fm_kb2_https(array $server): bool
{
    return in_array(strtolower((string)($server['HTTPS']??'')),['on','1'],true)
        || (string)($server['SERVER_PORT']??'')==='443';
}

function fm_kb2_instance(PDO $pdo): string
{
    $id=$pdo->query('SELECT public_id FROM fm_vp3_instance WHERE id=1')->fetchColumn();
    if(!is_string($id)||!preg_match('/^[a-fA-F0-9-]{36}$/D',$id))
        throw new RuntimeException('VP3 instance setup is missing. Run upgrade.php.');
    return strtolower($id);
}

function fm_kb2_create(PDO $pdo,array $owner,string $label,array $requested): array
{
    $scopes=fm_kb2_scopes($owner,$requested);
    $label=trim($label);
    if($label===''||mb_strlen($label,'UTF-8')>80)
        throw new InvalidArgumentException('Give the connection a label of 1–80 characters.');
    $id=bin2hex(random_bytes(16));
    $code=fm_kb2_secret('fmlink_');
    $sql="INSERT INTO fm_vp3_connections
      (public_id,organization_id,owner_user_id,label,scopes_json,pairing_code_sha256,status,code_expires_at)
      VALUES (?,?,?,?,?,?,'pending',DATE_ADD(NOW(6), INTERVAL 10 MINUTE))";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$id,(int)$owner['organization_id'],(int)$owner['id'],$label,
        json_encode($scopes,JSON_THROW_ON_ERROR),fm_kb2_hash($code)]);
    return ['id'=>$id,'pairingCode'=>$code,'label'=>$label,'scopes'=>$scopes,'expiresInSeconds'=>600];
}

function fm_kb2_redeem(PDO $pdo,string $code): array
{
    if(!preg_match('/^fmlink_[A-Za-z0-9_-]{43}$/D',$code))
        throw new DomainException('Invalid or expired pairing authorization.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM fm_vp3_connections
            WHERE pairing_code_sha256=? AND status='pending'
              AND code_expires_at>NOW(6) AND revoked_at IS NULL LIMIT 1 FOR UPDATE");
        $q->execute([fm_kb2_hash($code)]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || !fm_kb2_active_owner($pdo,(int)$row['organization_id'],(int)$row['owner_user_id']))
            throw new DomainException('Invalid or expired pairing authorization.');
        $token=fm_kb2_secret('fm2_');
        $update=$pdo->prepare("UPDATE fm_vp3_connections
            SET pairing_code_sha256=NULL,access_token_sha256=?,status='active',activated_at=NOW(6)
            WHERE id=? AND status='pending' AND pairing_code_sha256=?");
        $update->execute([fm_kb2_hash($token),(int)$row['id'],fm_kb2_hash($code)]);
        if($update->rowCount()!==1)throw new DomainException('Pairing already used.');
        $instance=fm_kb2_instance($pdo);
        $pdo->commit();
        return ['ok'=>true,'schema'=>'food-monster-vp3-v1',
            'instanceId'=>$instance,'connectionId'=>(string)$row['public_id'],
            'tokenType'=>'Bearer','accessToken'=>$token,
            'scopes'=>json_decode((string)$row['scopes_json'],true,512,JSON_THROW_ON_ERROR)];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function fm_kb2_active_owner(PDO $pdo,int $org,int $userId): bool
{
    if($org<1||$userId<1)return false;
    $q=$pdo->prepare("SELECT 1 FROM users u
      INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
      INNER JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
      INNER JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
      WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
        AND om.status='active'
        AND (r.is_owner_role=1 OR (r.slug='super_admin' AND r.is_system_role=1))
      LIMIT 1");
    $q->execute([$org,$userId]);
    return (bool)$q->fetchColumn();
}

function fm_kb2_bearer(array $server): string
{
    $header=trim((string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??''));
    if(!preg_match('/^Bearer (fm2_[A-Za-z0-9_-]{43})$/iD',$header,$matches))
        throw new DomainException('A valid HomeServer bearer credential is required.');
    return $matches[1];
}

function fm_kb2_grant(PDO $pdo,string $token): array
{
    if(!preg_match('/^fm2_[A-Za-z0-9_-]{43}$/D',$token))
        throw new DomainException('Invalid connection credential.');
    $q=$pdo->prepare("SELECT id,public_id,organization_id,owner_user_id,scopes_json
        FROM fm_vp3_connections WHERE access_token_sha256=?
         AND status='active' AND revoked_at IS NULL LIMIT 1");
    $q->execute([fm_kb2_hash($token)]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!fm_kb2_active_owner($pdo,(int)$row['organization_id'],(int)$row['owner_user_id']))
        throw new DomainException('HomeServer access revoked or no longer authorized.');
    $scopes=json_decode((string)$row['scopes_json'],true);
    if(!is_array($scopes)||!$scopes||array_diff($scopes,['menu','recipe']))
        throw new DomainException('Invalid stored scopes.');
    $permissions=[];
    if(in_array('menu',$scopes,true))$permissions[]='menu.view';
    if(in_array('recipe',$scopes,true))array_push($permissions,'recipes.view','recipes.agent');
    $pdo->prepare("UPDATE fm_vp3_connections SET last_used_at=NOW(6)
                   WHERE id=? AND status='active'")->execute([(int)$row['id']]);
    return ['connectionId'=>(string)$row['public_id'],
       'user'=>['organization_id'=>(int)$row['organization_id'],
                'id'=>(int)$row['owner_user_id'],'permissions'=>$permissions],
       'scopes'=>$scopes];
}

function fm_kb2_connections(PDO $pdo,array $owner): array
{
    if(!fm_kb2_owner($owner))throw new DomainException('Owner access required.');
    $q=$pdo->prepare('SELECT public_id,label,scopes_json,status,created_at,code_expires_at,activated_at,revoked_at,last_used_at
      FROM fm_vp3_connections WHERE organization_id=? AND owner_user_id=? ORDER BY created_at DESC LIMIT 100');
    $q->execute([(int)$owner['organization_id'],(int)$owner['id']]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function(array $row):array{
        $row['scopes']=json_decode((string)$row['scopes_json'],true)?:[];
        unset($row['scopes_json']);
        return $row;
    },$rows);
}

function fm_kb2_revoke(PDO $pdo,array $owner,string $id): bool
{
    if(!fm_kb2_owner($owner))throw new DomainException('Owner access required.');
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid connection identifier.');
    $q=$pdo->prepare("UPDATE fm_vp3_connections
        SET status='revoked',revoked_at=NOW(6),pairing_code_sha256=NULL,access_token_sha256=NULL
        WHERE public_id=? AND organization_id=? AND owner_user_id=? AND status<>'revoked'");
    $q->execute([$id,(int)$owner['organization_id'],(int)$owner['id']]);
    return $q->rowCount()===1;
}

/**
 * KB4B: bearer-holder self-revocation for this single Food Monster instance.
 *
 * This is deliberately distinct from fm_kb2_grant(): a revoked or suspended
 * owner must still be able to destroy a bearer credential, but can never
 * use that credential to read knowledge. No caller-provided org/owner/id is
 * accepted and no different connection may be revoked by bearer.
 */
function fm_kb4b_revoke_self(PDO $pdo,string $token): array
{
    if(!preg_match('/^fm2_[A-Za-z0-9_-]{43}$/D',$token))
        throw new DomainException('Invalid connection credential.');
    $digest=fm_kb2_hash($token);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT id,public_id FROM fm_vp3_connections
            WHERE access_token_sha256=? AND status='active' AND revoked_at IS NULL
            LIMIT 1 FOR UPDATE");
        $q->execute([$digest]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Connection is already revoked or unavailable.');
        $q=$pdo->prepare("UPDATE fm_vp3_connections
            SET status='revoked',revoked_at=NOW(6),
                access_token_sha256=NULL,pairing_code_sha256=NULL
            WHERE id=? AND access_token_sha256=? AND status='active'
                AND revoked_at IS NULL");
        $q->execute([(int)$row['id'],$digest]);
        if($q->rowCount()!==1)throw new DomainException('Connection revocation conflict.');
        $instanceId=fm_kb2_instance($pdo);
        $pdo->commit();
        return [
            'ok'=>true,'schema'=>'food-monster-vp3-v1','revoked'=>true,
            'instanceId'=>$instanceId,'connectionId'=>(string)$row['public_id']
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
