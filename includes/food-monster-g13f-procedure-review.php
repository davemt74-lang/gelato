<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13e-intelligence.php';

/**
 * Explicit owner-typed workflow only. No model-generated operational text,
 * voice-triggered publication, or automatic staff performance decisions.
 */
function fm13f_intent(string $message):?array
{
    $text=trim(preg_replace('/\s+/u',' ',$message)??$message);
    if(preg_match('/^(?:review training procedures|training procedure improvements|review unclear training steps)[.!]?$/iu',$text))
        return ['action'=>'review'];
    if(preg_match('/^draft menu procedure ([1-9][0-9]{0,9})\s*:\s*(.{15,2400})$/ius',$text,$m))
        return ['action'=>'draft','itemId'=>(int)$m[1],'replacement'=>trim($m[2])];
    if(preg_match('/^(approve|reject|publish) procedure (prp-[a-f0-9]{20})[.!]?$/iu',$text,$m))
        return ['action'=>strtolower($m[1]),'proposalId'=>strtolower($m[2])];
    return null;
}
function fm13f_authorized(array $operator,string $channel):bool
{
    return $channel==='text'
        &&fm9a_is_super_admin_session($operator)
        &&app_has_permission('menu.view',$operator)
        &&app_has_permission('menu.manage',$operator);
}
function fm13f_notes_digest(string $notes):string
{
    return hash('sha256',$notes);
}
function fm13f_validate_note(string $old,string $replacement):bool
{
    $replacement=trim($replacement);
    return mb_strlen($replacement,'UTF-8')>=15
        &&mb_strlen($replacement,'UTF-8')<=2400
        &&$replacement!==trim($old)
        &&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$replacement);
}
/**
 * Evidence reflects activity, not correctness or employee competence. Only
 * records of actual requests count; missing legacy action labels count zero.
 */
function fm13f_review_signals(array $rows,array $authorizedPeople):array
{
    $allow=array_fill_keys(array_map('intval',$authorizedPeople),true);
    $items=[];$seenHelp=[];
    foreach($rows as $row){
        $personId=(int)($row['person_id']??0);
        if($personId<=0||!isset($allow[$personId])||($row['role']??'')!=='system')continue;
        $meta=$row['structured']??null;
        if(!is_array($meta)||($meta['collection']??'')!=='fm13c_guided_training'
            ||($meta['source']??'')!=='gelato_training')continue;
        $state=$meta['state']??null;
        if(!is_array($state)||($state['kind']??'')!=='menu'
            ||!ctype_digit((string)($state['id']??'')))continue;
        $id=(int)$state['id'];if($id<1)continue;
        $action=(string)($meta['action']??'');
        $isHelp=in_array($action,['hint','question'],true);
        $isRevisit=in_array($action,['repeat','previous','restart'],true);
        if(!$isHelp&&!$isRevisit)continue;
        if(!isset($items[$id]))$items[$id]=['itemId'=>$id,'helpRequests'=>0,
            'revisits'=>0,'peopleRequestingHelp'=>0,'reviewSuggested'=>false];
        if($isHelp){
            $items[$id]['helpRequests']++;
            if(!isset($seenHelp[$id][$personId])){
                $items[$id]['peopleRequestingHelp']++;
                $seenHelp[$id][$personId]=true;
            }
        }
        if($isRevisit)$items[$id]['revisits']++;
    }
    foreach($items as &$entry){
        $entry['reviewSuggested']=$entry['helpRequests']>=3||$entry['revisits']>=4
            ||$entry['peopleRequestingHelp']>=2;
    }
    unset($entry);
    usort($items,static fn($a,$b)=>
        ($b['helpRequests']+$b['revisits'])<=>($a['helpRequests']+$a['revisits']));
    return array_slice($items,0,15);
}
function fm13f_proposal_thread(PDO $pdo,int $org,int $ownerId,bool $create):?string
{
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=?
      AND user_id=? AND title='Food Monster Procedure Reviews' AND archived_at IS NULL
      ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$ownerId]);
    $thread=(string)($q->fetchColumn()?:'');
    if($thread!=='')return $thread;
    return $create?(string)gaw_create_thread($pdo,$org,$ownerId,'text',
        'Food Monster Procedure Reviews')['public_id']:null;
}
function fm13f_latest_proposal(array $messages,string $proposalId):?array
{
    foreach(array_reverse($messages) as $row){
        $meta=$row['structured']??null;
        if(($row['role']??'')!=='system'||!is_array($meta)
            ||($meta['collection']??'')!=='fm13f_procedure_review'
            ||($meta['source']??'')!=='gelato_server'
            ||($meta['proposalId']??'')!==$proposalId)continue;
        $proposal=$meta['proposal']??null;
        return is_array($proposal)?$proposal:null;
    }
    return null;
}
/** Pure audit policy, re-evaluated under SQL item lock on each decision. */
function fm13f_decide(array $proposal,string $action,string $currentNotes,int $now):array
{
    $status=(string)($proposal['status']??'');
    $validId=preg_match('/^prp-[a-f0-9]{20}$/D',(string)($proposal['id']??''))===1;
    if(!$validId||!in_array($status,['draft','approved','rejected','published'],true))
        return ['allowed'=>false,'reason'=>'Invalid proposal state'];
    if(in_array($status,['rejected','published'],true))
        return ['allowed'=>false,'reason'=>'This proposal has already been closed'];
    if($now-(int)($proposal['createdAt']??0)>604800||(int)($proposal['createdAt']??0)>$now)
        return ['allowed'=>false,'reason'=>'Proposal expired; draft another review'];
    if(!hash_equals((string)($proposal['sourceHash']??''),fm13f_notes_digest($currentNotes)))
        return ['allowed'=>false,'reason'=>'The menu procedure changed; draft a new review before approval or publication'];
    if(!fm13f_validate_note($currentNotes,(string)($proposal['replacement']??'')))
        return ['allowed'=>false,'reason'=>'The replacement must be a distinct, complete procedure'];
    if($action==='reject'&&in_array($status,['draft','approved'],true))
        return ['allowed'=>true,'status'=>'rejected'];
    if($action==='approve'&&$status==='draft')
        return ['allowed'=>true,'status'=>'approved'];
    if($action==='publish'&&$status==='approved')
        return ['allowed'=>true,'status'=>'published'];
    return ['allowed'=>false,'reason'=>'The proposal must be drafted, approved, then published in that order'];
}
function fm13f_source_snapshot(PDO $pdo,int $org,int $menuId):?array
{
    $q=$pdo->prepare("SELECT id,name,preparation_notes,version FROM menu_items WHERE organization_id=?
      AND id=? AND is_active=1 LIMIT 1 FOR UPDATE");
    $q->execute([$org,$menuId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
function fm13f_record(PDO $pdo,int $org,int $ownerId,string $thread,
    array $proposal,string $turnId):void
{
    gaw_append($pdo,$org,$ownerId,$thread,'system','Procedure review record updated',[
        'channel'=>'text','structured'=>[
            'collection'=>'fm13f_procedure_review','source'=>'gelato_server',
            'proposalId'=>$proposal['id'],'proposal'=>$proposal,
            'sourceTurnId'=>$turnId
        ]]);
}
