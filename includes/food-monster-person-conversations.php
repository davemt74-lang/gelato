<?php
declare(strict_types=1);
require_once __DIR__.'/agent-workspace-core.php';
require_once __DIR__.'/food-monster-identity-authority.php';
require_once __DIR__.'/food-monster-conversation-memory.php';

/**
 * FM-G9D conversational archive. Voice identification is an optional,
 * provisional association, NEVER website authentication or tool authority.
 * Unknown turns remain only in the Super Admin's shared conversation.
 */
function fm9d_resolve_speaker(PDO $pdo,array $operator,string $voiceEventId): ?array
{
    if(!fm9a_is_super_admin_session($operator)
       ||!app_has_permission('voice.agent',$operator)
       ||!app_has_permission('voice.manage',$operator)
       ||!preg_match('/^voice-[a-zA-Z0-9-]{8,90}$/D',$voiceEventId))return null;
    $org=(int)$operator['organization_id'];
    $q=$pdo->prepare("SELECT u.id,u.display_name,e.public_id
        FROM voice_identity_events e
        INNER JOIN voice_identity_profiles vp ON vp.organization_id=e.organization_id
            AND vp.user_id=e.matched_user_id AND vp.status='active' AND vp.consented_at IS NOT NULL
        INNER JOIN users u ON u.id=e.matched_user_id AND u.status='active' AND u.archived_at IS NULL
        INNER JOIN organization_memberships om ON om.organization_id=e.organization_id
            AND om.user_id=u.id AND om.status='active'
        WHERE e.organization_id=? AND e.public_id=? AND e.session_user_id=?
          AND e.event_type='match' AND e.created_at>=DATE_SUB(NOW(6),INTERVAL 60 SECOND)
          AND e.matched_user_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM agent_messages m
              WHERE m.organization_id=e.organization_id AND m.voice_event_public_id=e.public_id
                AND m.user_id=e.matched_user_id
                AND m.structured_json LIKE '%\"fm9d_person_turn\"%')
        LIMIT 1");
    $q->execute([$org,$voiceEventId,(int)$operator['id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    return ['personId'=>(int)$row['id'],'displayName'=>(string)$row['display_name'],
        'voiceEventId'=>$voiceEventId,'identityAssurance'=>'provisional_voice_match'];
}

function fm9d_day_title(?string $day=null): string
{
    return 'Food Monster · '.($day??date('Y-m-d'));
}

function fm9d_person_thread(PDO $pdo,int $org,int $personId): string
{
    $title=fm9d_day_title();
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=? AND user_id=?
       AND title=? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId,$title]);
    $found=(string)($q->fetchColumn()?:'');
    if($found!=='')return $found;
    // New canonical GAW thread; no shadow participant databases.
    $thread=gaw_create_thread($pdo,$org,$personId,'voice',$title);
    return (string)$thread['public_id'];
}

function fm9d_mirror_user_turn(PDO $pdo,array $operator,array $speaker,string $text,string $operatorThread): array
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin required.');
    $org=(int)$operator['organization_id'];
    $personId=(int)($speaker['personId']??0);
    $voice=(string)($speaker['voiceEventId']??'');
    if($personId<1||$voice===''||!gaw_thread($pdo,$org,(int)$operator['id'],$operatorThread))
        throw new InvalidArgumentException('Invalid speaker event or operator conversation.');
    $thread=fm9d_person_thread($pdo,$org,$personId);
    $meta=['collection'=>'fm9d_person_turn','speakerPersonId'=>$personId,
        'actorUserId'=>(int)$operator['id'],
        'identityAssurance'=>'provisional_voice_match',
        'memoryCategory'=>fm9e_classify($text),
        'source'=>'food_monster_conversation','operatorThreadId'=>$operatorThread];
    $reportMessage=gaw_append($pdo,$org,$personId,$thread,'user',$text,[
        'channel'=>'voice','voiceEventId'=>$voice,'structured'=>$meta]);
    return ['personId'=>$personId,'threadId'=>$thread,'voiceEventId'=>$voice,
        'personMessageId'=>(string)($reportMessage['id']??''),
        'operatorThreadId'=>$operatorThread,'createdAt'=>time(),
        'reportMessageId'=>$meta['memoryCategory']==='operational_report'?(string)($reportMessage['id']??''):null];
}

/** Append the agent response to the previously verified person, at most once. */
function fm9d_mirror_reply(PDO $pdo,array $operator,array $pending,string $operatorThread,string $answer): bool
{
    if(!fm9a_is_super_admin_session($operator)||$operatorThread!==(string)($pending['operatorThreadId']??'')
        ||time()-(int)($pending['createdAt']??0)>120 ||time()<(int)($pending['createdAt']??0))return false;
    $org=(int)$operator['organization_id'];$personId=(int)($pending['personId']??0);
    $thread=(string)($pending['threadId']??'');
    if($personId<1||!gaw_thread($pdo,$org,$personId,$thread))return false;
    $meta=['collection'=>'fm9d_person_reply','source'=>'food_monster_conversation',
        'actorUserId'=>(int)$operator['id'],'identityAssurance'=>'provisional_voice_match'];
    gaw_append($pdo,$org,$personId,$thread,'agent',$answer,['channel'=>'voice','structured'=>$meta]);
    return true;
}

/** Manager label, clock status and camera presence do not control access. */
function fm9d_person_collections(PDO $pdo,array $operator,int $personId): array
{
    if(!fm9a_is_super_admin_session($operator))throw new DomainException('Super Admin required.');
    $org=(int)$operator['organization_id'];
    $q=$pdo->prepare("SELECT u.id,u.display_name FROM users u INNER JOIN organization_memberships om
        ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
        WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$personId]);$person=$q->fetch(PDO::FETCH_ASSOC);
    if(!$person)throw new InvalidArgumentException('Person does not belong to this organization.');
    $threads=$pdo->prepare("SELECT public_id,title,created_at,last_message_at
        FROM agent_conversations WHERE organization_id=? AND user_id=?
        AND title LIKE 'Food Monster · %' ORDER BY id DESC LIMIT 90");
    $threads->execute([$org,$personId]);
    return ['personId'=>$personId,'displayName'=>(string)$person['display_name'],
        'collections'=>$threads->fetchAll(PDO::FETCH_ASSOC)];
}
