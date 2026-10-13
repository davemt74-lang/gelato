<?php
declare(strict_types=1);

require_once __DIR__.'/agent-workspace-core.php';

/**
 * A voice match is a personalization signal, not an authentication grant.
 * Manager match-any may identify another enrolled employee, but the caller's
 * authenticated session remains responsible for permissions and audit.
 */
function avc_verified_speaker(PDO $pdo,int $org,array $actor,string $eventId): ?array
{
    if($eventId===''||strlen($eventId)>100||!app_has_permission('voice.agent',$actor))return null;
    $q=$pdo->prepare("SELECT e.matched_user_id,u.display_name,u.first_name FROM voice_identity_events e INNER JOIN users u ON u.id=e.matched_user_id INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=e.organization_id AND om.status='active' INNER JOIN voice_identity_profiles p ON p.organization_id=e.organization_id AND p.user_id=u.id AND p.status='active' WHERE e.organization_id=? AND e.public_id=? AND e.session_user_id=? AND e.event_type='match' AND e.created_at>=DATE_SUB(NOW(6),INTERVAL 15 MINUTE) AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$eventId,(int)$actor['id']]);
    $r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)return null;
    $speaker=(int)$r['matched_user_id'];
    if($speaker!==(int)$actor['id']&&!app_has_permission('voice.manage',$actor))return null;
    $first=trim((string)($r['first_name']??''));
    if($first==='')$first=explode(' ',trim((string)$r['display_name']))[0]??'';
    // Never accept a name supplied by the browser or from a transcript.
    return ['userId'=>$speaker,'firstName'=>mb_substr($first,0,50,'UTF-8'),'displayName'=>(string)$r['display_name']];
}

/**
 * Called BEFORE storing the new user turn. Uses per-user Agent Brain history,
 * including conversations outside the current thread.
 */
function avc_greeting(PDO $pdo,int $org,int $userId,string $firstName): ?string
{
    if($firstName==='')return null;
    $q=$pdo->prepare("SELECT COUNT(*) total,MAX(created_at) last_seen,CASE WHEN MAX(created_at) IS NULL OR DATE(MAX(created_at))<CURRENT_DATE() THEN 1 ELSE 0 END new_day,COALESCE(TIMESTAMPDIFF(MINUTE,MAX(created_at),NOW(6)),999999) idle_min FROM agent_messages WHERE organization_id=? AND user_id=? AND role='user'");
    $q->execute([$org,$userId]);
    $row=$q->fetch(PDO::FETCH_ASSOC)?:[];
    if((int)($row['new_day']??0)===1)return 'Good to hear from you, '.$firstName.'.';
    if((int)($row['idle_min']??0)>=120)return 'Welcome back, '.$firstName.'.';
    return null;
}

function avc_kds_record(PDO $pdo,int $org,array $actor,array $speaker,string $question,string $answer,string $voiceEventId,array $context=[]): void
{
    if(!gaw_ready($pdo))return;
    $uid=(int)$speaker['userId'];
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=? AND title='KDS voice conversation' AND created_at>=CURRENT_DATE() AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$uid]);
    $thread=(string)($q->fetchColumn()?:'');
    if($thread==='')$thread=(string)gaw_create_thread($pdo,$org,$uid,'voice','KDS voice conversation')['public_id'];
    $metadata=['source'=>'kds','actorUserId'=>(int)$actor['id'],'speakerVerified'=>true,'locationId'=>(int)($context['locationId']??0),'stationPublicId'=>(string)($context['stationPublicId']??'')];
    gaw_append($pdo,$org,$uid,$thread,'user',$question,['channel'=>'voice','voiceEventId'=>$voiceEventId,'structured'=>$metadata]);
    gaw_append($pdo,$org,$uid,$thread,'agent',$answer,['channel'=>'voice','structured'=>$metadata]);
}
