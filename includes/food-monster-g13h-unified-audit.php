<?php
declare(strict_types=1);
require_once __DIR__.'/agent-workspace-core.php';

/**
 * Add-only procedure audit inside the existing tenant- and actor-owned Agent
 * conversation store. Must be called within the same SQL transaction as the
 * menu write. No schema migration, no external service and no silent failure.
 */
function fm13h_audit_procedure(PDO $pdo,int $org,int $actorId,int $itemId,
    string $name,string $before,string $after,int $beforeVersion,int $afterVersion,
    string $origin,?string $proposalId=null):?string
{
    if($before===$after)return null;
    if($org<1||$actorId<1||$itemId<1||$afterVersion<1
       ||!in_array($origin,['menu_manager','food_monster'],true))
        throw new InvalidArgumentException('Invalid procedure audit context.');
    $title='Menu Procedure Audit';
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=?
        AND user_id=? AND title=? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$actorId,$title]);
    $thread=(string)($q->fetchColumn()?:'');
    if($thread==='')$thread=(string)gaw_create_thread($pdo,$org,$actorId,'text',$title)['public_id'];
    $event=[
        'collection'=>'fm13h_procedure_revision','source'=>'gelato_server',
        'orgId'=>$org,'actorId'=>$actorId,'itemId'=>$itemId,
        'name'=>mb_substr($name,0,100,'UTF-8'),
        'origin'=>$origin,'proposalId'=>$proposalId,
        'before'=>$before,'after'=>$after,
        'beforeHash'=>hash('sha256',$before),'afterHash'=>hash('sha256',$after),
        'beforeVersion'=>$beforeVersion,'afterVersion'=>$afterVersion,
    ];
    $saved=gaw_append($pdo,$org,$actorId,$thread,'system','Menu preparation procedure revision',[
        'channel'=>'text','structured'=>$event]);
    return (string)($saved['id']??'');
}

/** Return only normalized, hash-verified events from the authenticated tenant. */
function fm13h_normalize(array $row,int $org,int $ownerId):?array
{
    if(($row['role']??'')!=='system')return null;
    $m=$row['structured']??null;
    if(!is_array($m)||($m['collection']??'')!=='fm13h_procedure_revision'
       ||($m['source']??'')!=='gelato_server'||(int)($m['orgId']??0)!==$org
       ||(int)($m['actorId']??0)!==(int)($row['person_id']??0))return null;
    $public=(string)($row['public_id']??'');
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$public))return null;
    $before=(string)($m['before']??'');$after=(string)($m['after']??'');
    if($before===$after||!hash_equals(hash('sha256',$before),(string)($m['beforeHash']??''))
       ||!hash_equals(hash('sha256',$after),(string)($m['afterHash']??'')))return null;
    $item=(int)($m['itemId']??0);
    $base=(int)($m['beforeVersion']??-1);$next=(int)($m['afterVersion']??-1);
    if($item<1||$base<0||$next<1||$next<=$base
       ||!in_array($m['origin']??'', ['menu_manager','food_monster'],true))return null;
    return [
        'versionId'=>'ver-'.substr($public,4),
        'proposalId'=>(string)($m['proposalId']??''),
        'itemId'=>$item,'name'=>(string)($m['name']??''),
        'publishedAt'=>strtotime((string)($row['created_at']??''))?:0,
        'sourceVersion'=>$base,'afterVersion'=>$next,
        'before'=>$before,'after'=>$after,
        'beforeHash'=>hash('sha256',$before),'afterHash'=>hash('sha256',$after),
        'orgId'=>$org,'ownerId'=>$ownerId,'actorId'=>(int)$row['person_id'],
        'origin'=>(string)$m['origin'],
    ];
}
function fm13h_unified(array $existing,array $events,int $org,int $ownerId):array
{
    $new=[];$already=[];
    foreach($events as $row){
        $rev=fm13h_normalize($row,$org,$ownerId);
        if($rev!==null){$new[]=$rev;if($rev['proposalId']!=='')$already[$rev['proposalId']]=true;}
    }
    foreach($existing as $rev){
        if(isset($already[(string)($rev['proposalId']??'')]))continue;
        $rev['origin']='food_monster_legacy';$rev['afterVersion']=null;
        $new[]=$rev;
    }
    usort($new,static fn($a,$b)=>($b['publishedAt']<=>$a['publishedAt'])
        ?:strcmp($b['versionId'],$a['versionId']));
    return $new;
}
/** Bounded repository read, not dependent on single owner's conversation. */
function fm13h_recent(PDO $pdo,int $org,int $limit=500):array
{
    $limit=max(1,min(500,$limit));
    $q=$pdo->prepare("SELECT m.public_id,m.role,m.structured_json,m.created_at,
         c.user_id AS person_id
         FROM agent_conversations c JOIN agent_messages m
           ON m.conversation_id=c.id AND m.organization_id=c.organization_id
         WHERE c.organization_id=? AND c.title='Menu Procedure Audit'
           AND m.role='system' AND m.structured_json LIKE '%fm13h_procedure_revision%'
         ORDER BY m.id DESC LIMIT ".($limit+1));
    $q->execute([$org]);
    $raw=$q->fetchAll(PDO::FETCH_ASSOC);
    $partial=count($raw)>$limit;
    $raw=array_slice($raw,0,$limit);
    $events=[];
    foreach(array_reverse($raw) as $row){
        $row['structured']=json_decode((string)($row['structured_json']??''),true);
        unset($row['structured_json']);$events[]=$row;
    }
    return ['events'=>$events,'partial'=>$partial];
}
