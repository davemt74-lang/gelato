<?php
declare(strict_types=1);
/**
 * AE5: consent-scoped participant event journal. No faces, voice samples,
 * descriptors or unknown visitors are stored.
 */
function fmeyes5_ready(PDO $pdo): bool
{
    static $known=[];
    $key=spl_object_id($pdo);
    if(isset($known[$key]))return $known[$key];
    $q=$pdo->query("SHOW TABLES LIKE 'fm_eyes_presence_events'");
    return $known[$key]=(bool)$q->fetchColumn();
}
function fmeyes5_log(PDO $pdo,array $owner,int $person,string $type,int $now): void
{
    if(!in_array($type,['arrival','departure','greeting_offered'],true))throw new InvalidArgumentException('Invalid timeline event');
    $q=$pdo->prepare('INSERT INTO fm_eyes_presence_events
      (organization_id,user_id,actor_user_id,event_key,event_type,occurred_at)
      VALUES (?,?,?,?,?,FROM_UNIXTIME(?))');
    $q->execute([(int)$owner['organization_id'],$person,(int)$owner['id'],
      bin2hex(random_bytes(16)),$type,$now]);
}
function fmeyes5_eligible(PDO $pdo,int $org,int $person,int $now): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM fm_eyes_presence_events
      WHERE organization_id=? AND user_id=? AND event_type='greeting_offered'
        AND occurred_at >= FROM_UNIXTIME(?)");
    $q->execute([$org,$person,$now-7200]);
    return (int)$q->fetchColumn()===0;
}
function fmeyes5_observe(PDO $pdo,array $owner,array $people,int $now,bool $allowGreeting=false): array
{
    if(!fm9a_is_super_admin_session($owner)||!fmeyes5_ready($pdo))return [];
    $org=(int)$owner['organization_id'];$actor=(int)$owner['id'];
    $state=(array)($_SESSION['fmeyes5_presence']??[]);
    if(($state['org']??0)!==$org||($state['actor']??0)!==$actor)
        $state=['org'=>$org,'actor'=>$actor,'people'=>[]];
    $active=(array)($state['people']??[]);
    $currently=[];
    foreach($people as $p){
        if(!is_array($p)||($p['identityAssurance']??'')!=='provisional_visual_match'
            ||($p['identitySource']??'')!=='local_face_profile')continue;
        $id=(int)($p['personId']??0);
        if($id>0&&!empty($p['displayName']))$currently[$id]=trim((string)$p['displayName']);
    }
    $greetings=[];
    // End a visit only after an actual lapse, not one noisy detector frame.
    foreach($active as $person=>$seen){
        $uid=(int)$person;
        if(!isset($currently[$uid])&&$now-(int)$seen>=7){
            fmeyes5_log($pdo,$owner,$uid,'departure',$now);
            unset($active[$person]);
        }
    }
    foreach($currently as $uid=>$name){
        if(!array_key_exists($uid,$active)){
            fmeyes5_log($pdo,$owner,$uid,'arrival',$now);
            if($allowGreeting&&fmeyes5_eligible($pdo,$org,$uid,$now)){
                // Only a suggestion to the authorized, opted-in speech client.
                $first=preg_split('/\s+/u',$name,2)[0]??'';
                $first=mb_substr($first,0,45,'UTF-8');
                if($first!==''){
                    fmeyes5_log($pdo,$owner,$uid,'greeting_offered',$now);
                    $greetings[]=['personId'=>$uid,'text'=>'Welcome, '.$first.'.','provisional'=>true];
                }
            }
        }
        $active[$uid]=$now;
    }
    $_SESSION['fmeyes5_presence']=['org'=>$org,'actor'=>$actor,'people'=>$active];
    return array_slice($greetings,0,8);
}
function fmeyes5_stop(PDO $pdo,array $owner,int $now): void
{
    if(!fm9a_is_super_admin_session($owner))return;
    $state=(array)($_SESSION['fmeyes5_presence']??[]);
    unset($_SESSION['fmeyes5_presence']);
    if(($state['org']??0)!==(int)$owner['organization_id']
        ||($state['actor']??0)!==(int)$owner['id']||!fmeyes5_ready($pdo))return;
    foreach(array_keys((array)($state['people']??[])) as $person)
        fmeyes5_log($pdo,$owner,(int)$person,'departure',$now);
}
function fmeyes5_timeline(PDO $pdo,array $owner,int $person=0,int $limit=40): array
{
    if(!fm9a_is_super_admin_session($owner))throw new DomainException('Super Admin required');
    if(!fmeyes5_ready($pdo))return [];
    $limit=max(1,min(100,$limit));$org=(int)$owner['organization_id'];
    $sql="SELECT e.id,e.user_id,e.event_type,e.occurred_at,u.display_name
      FROM fm_eyes_presence_events e
      INNER JOIN users u ON u.id=e.user_id
      WHERE e.organization_id=?";
    $args=[$org];
    if($person>0){$sql.=' AND e.user_id=?';$args[]=$person;}
    $sql.=' ORDER BY e.occurred_at DESC,e.id DESC LIMIT '.$limit;
    $q=$pdo->prepare($sql);$q->execute($args);
    return array_map(static fn($r)=>[
      'id'=>(int)$r['id'],'personId'=>(int)$r['user_id'],
      'displayName'=>(string)$r['display_name'],'type'=>(string)$r['event_type'],
      'occurredAt'=>(string)$r['occurred_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}
