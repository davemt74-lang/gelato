<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/food-monster-pin.php';
require_once __DIR__.'/agent-eyes-enrollment.php';
require_once __DIR__.'/agent-eyes-presence-timeline.php';

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
        'currentSpeaker'=>fm11a_current_speaker($session,$now)];
}

/**
 * FM-G11A: a short-lived room speaker marker is written only from the server's
 * confirmed FM-G9D voice event, never from webcam presence or client speaker IDs.
 * This is room context, NOT account authentication or action authorization.
 */
function fm11a_room_clear_speaker(array $operator): void
{
    if(!fm9a_is_super_admin_session($operator))return;
    if(isset($_SESSION['fm9c_room'])&&is_array($_SESSION['fm9c_room']))
        unset($_SESSION['fm9c_room']['speaker']);
}
function fm11a_current_speaker(array $session,int $now):?array
{
    $speaker=$session['speaker']??null;
    if(!is_array($speaker)||($now-(int)($speaker['seen']??0))>5
       ||(int)($speaker['seen']??0)>$now+1)return null;
    $id=(int)($speaker['personId']??0);
    $track=(string)($speaker['trackId']??'');
    if($id<1||!preg_match('/^r-[a-f0-9]{16,32}$/D',$track))return null;
    $matches=0;
    foreach((array)($session['people']??[]) as $person){
        if((int)($person['personId']??0)===$id&&($person['trackId']??'')===$track
          &&($person['identityAssurance']??'')==='provisional_visual_match')$matches++;
    }
    if($matches!==1)return null;
    return ['personId'=>$id,'trackId'=>$track,
        'identityAssurance'=>'correlated_voice_and_visual_observation',
        'websiteAuthenticated'=>false,'agentAdministration'=>false,
        'handoffFromPreviousVerifiedTurn'=>($speaker['handoff']??false)===true];
}
function fm11a_room_voice_turn(array $operator,?array $voiceSpeaker,?int $now=null):?array
{
    // Snapshot only a still-valid verified speaker before invalidating the old turn.
    // Unknown or typed speech MUST clear attribution rather than inherit it.
    $previous=fm9a_is_super_admin_session($operator)
        ? fm11a_current_speaker((array)($_SESSION['fm9c_room']??[]),$now??time()) : null;
    fm11a_room_clear_speaker($operator);
    if(!fm9a_is_super_admin_session($operator)||!is_array($voiceSpeaker))return null;
    $session=(array)($_SESSION['fm9c_room']??[]);
    $now??=time();
    if((int)($session['org']??0)!==(int)$operator['organization_id']
      ||(int)($session['operator']??0)!==(int)$operator['id']
      ||(int)($session['seen']??0)<$now-6
      ||(int)($session['seen']??0)>$now+1)return null;
    if(($voiceSpeaker['identityAssurance']??'')!=='provisional_voice_match'
      ||!preg_match('/^voice-[a-zA-Z0-9-]{8,90}$/D',(string)($voiceSpeaker['voiceEventId']??'')))
        return null;
    $id=(int)($voiceSpeaker['personId']??0);
    if($id<=0)return null;
    $candidates=array_values(array_filter((array)($session['people']??[]),
        static fn($p)=>is_array($p)
          &&(int)($p['personId']??0)===$id
          &&($p['identityAssurance']??'')==='provisional_visual_match'));
    if(count($candidates)!==1)return null;
    $track=(string)($candidates[0]['trackId']??'');
    if(!preg_match('/^r-[a-f0-9]{16,32}$/D',$track))return null;
    $_SESSION['fm9c_room']['speaker']=['personId'=>$id,'trackId'=>$track,'seen'=>$now,
        'handoff'=>$previous!==null && (int)$previous['personId']!==$id];
    return fm11a_current_speaker($_SESSION['fm9c_room'],$now);
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
    if($r)return $r;
    // AE2 is separate from Face/PIN authentication; even an owner can have
    // a provisional room label without gaining identity-based tool authority.
    if(!fmeyes_ready($pdo))return null;
    $q=$pdo->prepare("SELECT u.id,u.display_name FROM users u
       INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
       INNER JOIN fm_eyes_local_enrollments e ON e.user_id=u.id AND e.organization_id=om.organization_id
         AND e.device_id=? AND e.revoked_at IS NULL
       WHERE u.email=? AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,(int)$device['id'],$email]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function fm9c_room_update(PDO $pdo,array $operator,array $observations,int $seenAt=null,bool $allowGreeting=false): array
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
    $old=(array)($_SESSION['fm9c_room']??[]);
    $session=['org'=>$org,'operator'=>(int)$operator['id'],'seen'=>$now,'people'=>$people];
    if((int)($old['org']??0)===$org
       &&(int)($old['operator']??0)===(int)$operator['id']
       &&isset($old['speaker'])&&fm11a_current_speaker($old,$now)){
        $session['speaker']=$old['speaker'];
    }
    $_SESSION['fm9c_room']=$session;
    $greetings=fmeyes5_observe($pdo,$operator,$people,$now,$allowGreeting);
    return fm9c_room_context($operator,$_SESSION['fm9c_room'],$now)+['proactiveGreetings'=>$greetings];
}

function fm9c_room_stop(array $operator,?PDO $pdo=null): void
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin session required.');
    if($pdo)fmeyes5_stop($pdo,$operator,time());
    unset($_SESSION['fm9c_room']);
}
