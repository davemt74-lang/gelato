<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/food-monster-pin.php';

/**
 * FM-G9C: ephemeral room observation. A camera observation is NOT an account
 * session, a secure biometric verification, or permission to execute tools.
 */
function fm9c_empty_room(): array
{
    return ['status'=>'not_connected','source'=>null,'people'=>[],'currentSpeaker'=>null];
}

function fm9c_room_context(array $operator,?array $session=null,?int $now=null): array
{
    if(!fm9a_is_super_admin_session($operator))return fm9c_empty_room();
    $session??=(array)($_SESSION['fm9c_room']??[]);
    $now??=time();
    if((int)($session['org']??0)!==(int)$operator['organization_id']
      ||(int)($session['operator']??0)!==(int)$operator['id']
      ||(int)($session['seen']??0)<$now-15
      ||(int)($session['seen']??0)>$now+2)return fm9c_empty_room();
    return ['status'=>'active','source'=>'opt_in_browser_camera',
        'people'=>array_values((array)($session['people']??[])),
        'currentSpeaker'=>null];
}

function fm9c_normalize_tracks(array $observations): array
{
    if(count($observations)>8)throw new InvalidArgumentException('Too many camera observations.');
    $out=[];$seen=[];
    foreach($observations as $obs){
        if(!is_array($obs))throw new InvalidArgumentException('Invalid room observation.');
        $track=(string)($obs['trackId']??'');
        if(!preg_match('/^r-[a-f0-9]{16,32}$/D',$track)||isset($seen[$track]))
            throw new InvalidArgumentException('Invalid or duplicate camera track.');
        $seen[$track]=true;
        $b=$obs['box']??null;
        if(!is_array($b)||count($b)!==4)throw new InvalidArgumentException('Invalid observation geometry.');
        $coords=[];
        foreach($b as $value){
            if(!is_numeric($value)||!is_finite((float)$value)||$value<0||$value>1)
                throw new InvalidArgumentException('Invalid observation coordinates.');
            $coords[]=round((float)$value,4);
        }
        if($coords[2]<=0||$coords[3]<=0||$coords[0]+$coords[2]>1.001||$coords[1]+$coords[3]>1.001)
            throw new InvalidArgumentException('Invalid face boundary.');
        $email=trim(strtolower((string)($obs['localProfileEmail']??'')));
        if(strlen($email)>254||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))
            throw new InvalidArgumentException('Invalid local profile reference.');
        $out[]=['trackId'=>$track,'box'=>$coords,'localProfileEmail'=>$email];
    }
    return $out;
}

/** A locally enrolled face may be associated with its existing staff record.
 * This lookup is NOT an authentication method, confidence proof, or LLM permission.
 * The active trusted device and explicit preexisting face enrollment are mandatory.
 */
function fm9c_local_staff(PDO $pdo,int $org,string $email): ?array
{
    if($org<1||$email==='')return null;
    if(!fmpin_ready($pdo))return null;
    $device=fmpin_device($pdo);
    if(!$device||(int)$device['organization_id']!==$org)return null;
    $query=$pdo->prepare("SELECT u.id,u.display_name
       FROM users u
       INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
       INNER JOIN fm_pin_device_accounts da ON da.user_id=u.id AND da.organization_id=om.organization_id AND da.device_id=?
       WHERE u.email=? AND u.status='active' AND u.archived_at IS NULL
         AND NOT EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
           WHERE ur.membership_id=om.id AND ur.revoked_at IS NULL
             AND (r.is_owner_role=1 OR (r.slug='super_admin' AND r.is_system_role=1)))
       LIMIT 1");
    $query->execute([$org,(int)$device['id'],$email]);
    $r=$query->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

function fm9c_room_update(PDO $pdo,array $operator,array $observations,int $seenAt=null): array
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin session required.');
    $now=$seenAt??time();
    $tracks=fm9c_normalize_tracks($observations);
    $org=(int)$operator['organization_id'];
    $people=[];$ids=[];
    foreach($tracks as $obs){
        $person=fm9c_local_staff($pdo,$org,$obs['localProfileEmail']);
        $id=$person?(int)$person['id']:null;
        // Two tracks claiming one identity are ambiguous, never double-recognized.
        if($id!==null)$ids[$id]=($ids[$id]??0)+1;
        $people[]=['trackId'=>$obs['trackId'],'box'=>$obs['box'],
            'personId'=>$id,'displayName'=>$person?(string)$person['display_name']:null,
            'identitySource'=>$id!==null?'local_face_profile':'unknown',
            'identityAssurance'=>$id!==null?'provisional_visual_match':'none',
            'websiteAuthenticated'=>false,'agentAdministration'=>false];
    }
    foreach($people as &$p){
        if($p['personId']!==null&&($ids[$p['personId']]??0)>1){
            $p['personId']=null;$p['displayName']=null;
            $p['identitySource']='ambiguous';$p['identityAssurance']='none';
        }
    }
    unset($p);
    $_SESSION['fm9c_room']=['org'=>$org,'operator'=>(int)$operator['id'],'seen'=>$now,'people'=>$people];
    return fm9c_room_context($operator,$_SESSION['fm9c_room'],$now);
}

function fm9c_room_stop(array $operator): void
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin session required.');
    unset($_SESSION['fm9c_room']);
}
