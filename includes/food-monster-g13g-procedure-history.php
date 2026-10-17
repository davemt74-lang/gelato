<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13f-procedure-review.php';

/**
 * G13G reads the immutable server-owned G13F proposal event ledger.
 * Menu edits outside Food Monster are NOT reconstructed or assumed absent.
 */
function fm13g_intent(string $message):?array
{
    $s=trim(preg_replace('/\s+/u',' ',$message)??$message);
    if(preg_match('/^procedure history ([1-9][0-9]{0,9})[.!]?$/iu',$s,$m))
        return ['action'=>'history','itemId'=>(int)$m[1]];
    if(preg_match('/^compare procedure (ver-[a-f0-9]{20})[.!]?$/iu',$s,$m))
        return ['action'=>'compare','versionId'=>strtolower($m[1])];
    if(preg_match('/^draft rollback procedure (ver-[a-f0-9]{20})[.!]?$/iu',$s,$m))
        return ['action'=>'rollback_draft','versionId'=>strtolower($m[1])];
    return null;
}
function fm13g_version_id(array $proposal):string
{
    return 'ver-'.substr(hash('sha256','fm-g13g|'.
        (string)($proposal['orgId']??'').'|'.
        (string)($proposal['ownerId']??'').'|'.
        (string)($proposal['itemId']??'').'|'.
        (string)($proposal['id']??'')),0,20);
}
function fm13g_versions(array $events,int $org,int $owner,?int $itemId=null):array
{
    // gaw_messages returns oldest->newest. The last canonical event per
    // proposal determines whether the publication was actually completed.
    $last=[];
    foreach($events as $row){
        if(($row['role']??'')!=='system')continue;
        $meta=$row['structured']??null;
        if(!is_array($meta)||($meta['collection']??'')!=='fm13f_procedure_review'
            ||($meta['source']??'')!=='gelato_server')continue;
        $p=$meta['proposal']??null;
        if(!is_array($p)||($meta['proposalId']??'')!==($p['id']??'')
            ||!preg_match('/^prp-[a-f0-9]{20}$/D',(string)($p['id']??'')))continue;
        if((int)($p['orgId']??0)!==$org||(int)($p['ownerId']??0)!==$owner)continue;
        $last[$p['id']]=$p;
    }
    $versions=[];
    foreach($last as $proposal){
        if(($proposal['status']??'')!=='published'||(int)($proposal['publishedAt']??0)<=0)continue;
        $item=(int)($proposal['itemId']??0);
        if($item<1||($itemId!==null&&$itemId!==$item))continue;
        $before=(string)($proposal['original']??'');
        $after=(string)($proposal['replacement']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($proposal['sourceHash']??''))
            ||!hash_equals((string)$proposal['sourceHash'],fm13f_notes_digest($before))
            ||!fm13f_validate_note($before,$after))continue;
        $versions[]=[
            'versionId'=>fm13g_version_id($proposal),
            'proposalId'=>(string)$proposal['id'],
            'itemId'=>$item,'name'=>mb_substr((string)($proposal['itemName']??''),0,100,'UTF-8'),
            'publishedAt'=>(int)$proposal['publishedAt'],
            'sourceVersion'=>isset($proposal['sourceVersion'])?(int)$proposal['sourceVersion']:null,
            'before'=>$before,'after'=>$after,
            'beforeHash'=>fm13f_notes_digest($before),
            'afterHash'=>fm13f_notes_digest($after),
            'orgId'=>$org,'ownerId'=>$owner
        ];
    }
    usort($versions,static fn($a,$b)=>($b['publishedAt']<=>$a['publishedAt'])
        ?:strcmp($b['versionId'],$a['versionId']));
    return $versions;
}
function fm13g_find_version(array $versions,string $versionId):?array
{
    foreach($versions as $v)if(hash_equals((string)$v['versionId'],$versionId))return $v;
    return null;
}
/** A repeat delivery of the same saved typed chat turn is never another draft. */
function fm13g_turn_seen(array $events,string $turnId):bool
{
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId))return true;
    foreach($events as $row){
        $meta=$row['structured']??null;
        if(($row['role']??'')==='system'&&is_array($meta)
          &&($meta['collection']??'')==='fm13f_procedure_review'
          &&($meta['source']??'')==='gelato_server'
          &&($meta['sourceTurnId']??'')===$turnId)return true;
    }
    return false;
}
/** Rollback is only a proposed replacement, not a direct menu update. */
function fm13g_rollback_proposal(array $version,array $item,int $org,int $owner,
    int $now,string $id):?array
{
    if(!preg_match('/^prp-[a-f0-9]{20}$/D',$id)
        ||(int)($version['orgId']??0)!==$org||(int)($version['ownerId']??0)!==$owner
        ||(int)($item['id']??0)!==(int)($version['itemId']??-1))return null;
    $current=(string)($item['preparation_notes']??'');
    $target=(string)($version['before']??'');
    if(!fm13f_validate_note($current,$target))return null;
    return [
        'id'=>$id,'ownerId'=>$owner,'orgId'=>$org,
        'itemId'=>(int)$item['id'],
        'itemName'=>mb_substr((string)($item['name']??''),0,100,'UTF-8'),
        'sourceHash'=>fm13f_notes_digest($current),'sourceVersion'=>(int)($item['version']??0),
        'original'=>$current,'replacement'=>$target,'createdAt'=>$now,
        'status'=>'draft','approvedAt'=>null,'publishedAt'=>null,
        'rollbackOf'=>$version['versionId'],'rollbackSourceHash'=>$version['beforeHash']
    ];
}
function fm13g_diff_summary(array $version):array
{
    $before=(string)$version['before'];$after=(string)$version['after'];
    $first=array_values(array_filter(array_map('trim',
        preg_split('/\R/u',$before)?:[]),static fn($v)=>$v!==''));
    $second=array_values(array_filter(array_map('trim',
        preg_split('/\R/u',$after)?:[]),static fn($v)=>$v!==''));
    return ['versionId'=>$version['versionId'],'itemId'=>$version['itemId'],
        'before'=>$before,'after'=>$after,
        'beforeLines'=>count($first),'afterLines'=>count($second),
        'beforeHash'=>$version['beforeHash'],'afterHash'=>$version['afterHash']];
}
