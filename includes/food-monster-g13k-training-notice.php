<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13c-training.php';
require_once __DIR__.'/food-monster-g13j-refresh.php';

/**
 * G13K: return a generic, optional notice only for a fresh authenticated
 * account turn or a server-matched voice participant. Never interpret camera
 * identity, room-operator login or client-provided person IDs as speaker proof.
 */
function fm13k_eligible(array $user,?array $speaker,string $role,string $channel):?int
{
    if($role!=='user'||!in_array($channel,['text','voice'],true))return null;
    $uid=(int)($user['id']??0);
    if($channel==='text'){
        return $uid>0&&(fm9a_is_super_admin_session($user)
            ||app_has_permission('training.self_view',$user)
            ||app_has_permission('employee.self',$user))?$uid:null;
    }
    if($channel==='voice'&&is_array($speaker)
       &&($speaker['identityAssurance']??'')==='provisional_voice_match'
       &&fm9a_is_super_admin_session($user)
       &&app_has_permission('voice.agent',$user)){
        $personId=(int)($speaker['personId']??0);
        // A room operator's personal account never substitutes for a speaker.
        return $personId>0&&$personId!==$uid?$personId:null;
    }
    return null;
}
function fm13k_policy(?array $prior,?array $material,?array $previous,
    int $personId,int $now,int $cooldown=86400):bool
{
    if($personId<1||$now<=0||!fm13j_source_changed($prior,$material))return false;
    $digest=(string)$material['digest'];
    $status=fm13j_status($prior,$material);
    if($status['acknowledgedUpdate']||$status['refreshInProgress']||$status['refreshCompleted'])
        return false;
    if(is_array($previous)
       &&(int)($previous['personId']??0)===$personId
       &&(string)($previous['sourceDigest']??'')===$digest
       &&(int)($previous['sentAt']??0)>0
       &&$now-(int)$previous['sentAt']<max(3600,$cooldown))
        return false;
    return true;
}
function fm13k_notice_thread(PDO $pdo,int $org,int $personId,bool $create):?string
{
    $title='Food Monster Training Notices';
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations
         WHERE organization_id=? AND user_id=? AND title=? AND archived_at IS NULL
         ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$personId,$title]);
    $thread=(string)($q->fetchColumn()?:'');
    if($thread!=='')return $thread;
    return $create?(string)gaw_create_thread($pdo,$org,$personId,'text',$title)['public_id']:null;
}
function fm13k_latest_notice(PDO $pdo,int $org,int $personId,?string $thread):?array
{
    if(!$thread)return null;
    $rows=gaw_messages($pdo,$org,$personId,$thread,30);
    // Only server-owned system records in the separate notice ledger count.
    for($i=count($rows)-1;$i>=0;$i--){
        $row=$rows[$i];$meta=$row['structured']??null;
        if(($row['role']??'')!=='system'||!is_array($meta)
           ||($meta['collection']??'')!=='fm13k_training_notice'
           ||($meta['source']??'')!=='gelato_server'
           ||(int)($meta['personId']??0)!==$personId)continue;
        return $meta;
    }
    return null;
}
/**
 * A person-row lock protects duplicate alerts across two simultaneous tabs.
 * The notice ledger is separate from G13C so its latest training state
 * remains a canonical fm13c_guided_training event.
 */
function fm13k_try_notice(PDO $pdo,array $user,int $personId):?array
{
    $org=(int)($user['organization_id']??0);
    if($org<1||$personId<1)return null;
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT u.id FROM users u
            INNER JOIN organization_memberships om ON om.user_id=u.id
                AND om.organization_id=? AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
            FOR UPDATE");
        $q->execute([$org,$personId]);
        if(!$q->fetchColumn()){$pdo->commit();return null;}
        $training=fm13c_training_thread($pdo,$org,$personId,false);
        if(!$training){$pdo->commit();return null;}
        $state=fm13c_read_state($pdo,$org,$personId,$training);
        if(!is_array($state)||($state['kind']??'')!=='menu'){
            $pdo->commit();return null;
        }
        $material=fm13c_fetch_material($pdo,$org,$state);
        if(!fm13j_source_changed($state,$material)){$pdo->commit();return null;}
        $noticeThread=fm13k_notice_thread($pdo,$org,$personId,false);
        $previous=fm13k_latest_notice($pdo,$org,$personId,$noticeThread);
        $now=time();
        if(!fm13k_policy($state,$material,$previous,$personId,$now)){
            $pdo->commit();return null;
        }
        if(!$noticeThread)$noticeThread=fm13k_notice_thread($pdo,$org,$personId,true);
        $meta=['collection'=>'fm13k_training_notice','source'=>'gelato_server',
           'personId'=>$personId,'sourceDigest'=>$material['digest'],
           'priorDigest'=>$state['digest'],'sentAt'=>$now,'kind'=>'menu'];
        gaw_append($pdo,$org,$personId,$noticeThread,'system',
            'A saved training procedure has been updated. Review is available.',[
                'channel'=>'text','structured'=>$meta
            ]);
        $pdo->commit();
        // Never include name, item ID, stored procedure, employee identity
        // or other participant data in a shared room response.
        return ['available'=>true,'type'=>'menu_training_change',
            'text'=>'One of your saved training procedures has been updated. Review it when convenient.',
            'suggestedCommand'=>'review updated training'];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
