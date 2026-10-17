<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-g13g-procedure-history.php';

$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$in=app_json_input();app_verify_request_csrf($in);
try{
    if(($in['action']??'')!=='ask')throw new InvalidArgumentException('Use an authenticated Agent Chat procedure request.');
    $message=trim((string)($in['message']??''));
    $room=trim((string)($in['threadId']??($_GET['threadId']??'')));
    if($message===''||mb_strlen($message,'UTF-8')>250
        ||!preg_match('/^agent-[a-f0-9]{20}$/D',$room))
        throw new InvalidArgumentException('A valid text question and Agent conversation are required.');
    $turn=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($turn['role']??'')!=='user'||($turn['channel']??'')!=='text'
        ||trim((string)($turn['content']??''))!==$message
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($turn['public_id']??'')))
        throw new DomainException('A fresh typed Super Admin chat message is required.');
    if(!fm13f_authorized($user,(string)$turn['channel']))
        throw new DomainException('Super Admin and Menu Manager permissions are required.');
    $intent=fm13g_intent($message);
    if(!$intent)throw new InvalidArgumentException('Use procedure history ID, compare procedure VERSION, or draft rollback procedure VERSION.');
    $action=$intent['action'];$turnId=(string)$turn['public_id'];
    if($action==='rollback_draft')$pdo->beginTransaction();
    try{
        $ledger=fm13f_proposal_thread($pdo,$org,$uid,false);
        $events=$ledger?gaw_messages($pdo,$org,$uid,$ledger,300):[];
        $versions=fm13g_versions($events,$org,$uid);
        $partial=count($events)===300;
        $source=null;$version=null;$proposal=null;
        if($action==='history'){
            $itemId=(int)$intent['itemId'];
            $q=$pdo->prepare("SELECT id,name,preparation_notes,version,is_active FROM menu_items
                WHERE organization_id=? AND id=? LIMIT 1");
            $q->execute([$org,$itemId]);$source=$q->fetch(PDO::FETCH_ASSOC);
            if(!$source)throw new DomainException('The menu item does not belong to this organization.');
            $history=array_values(array_filter($versions,static fn($v)=>(int)$v['itemId']===$itemId));
            $entries=[];
            foreach(array_slice($history,0,40) as $v){
                $entries[]=['versionId'=>$v['versionId'],'itemId'=>$v['itemId'],
                    'publishedAt'=>$v['publishedAt'],
                    'beforeHash'=>$v['beforeHash'],'afterHash'=>$v['afterHash'],
                    'sourceVersion'=>$v['sourceVersion']];
            }
            $matches=$history&&hash_equals((string)$history[0]['afterHash'],
                fm13f_notes_digest((string)($source['preparation_notes']??'')));
            $answer=$entries?'Found '.count($entries).' recorded Food Monster procedure publications for '.
                (string)$source['name'].'.':'No published Food Monster procedure revisions were found for this menu item.';
            $answer.=' These are only Food Monster publications visible in the authorized 300-event history, not a full history of changes through the Menu Manager or other editors.';
            if($history&&!$matches)$answer.=' The current procedure differs from the most recent recorded publication.';
            if($partial||count($history)>40)$answer.=' Results may omit older versions.';
            $data=['mode'=>'history','itemId'=>$itemId,'itemName'=>(string)$source['name'],
                'menuVersion'=>(int)$source['version'],'isActive'=>(bool)$source['is_active'],
                'currentMatchesLatest'=>$matches,'partial'=>$partial||count($history)>40,
                'revisions'=>$entries];
        }else{
            $version=fm13g_find_version($versions,(string)$intent['versionId']);
            if(!$version)throw new DomainException('That published version was not found in this authorized account history.');
            $itemId=(int)$version['itemId'];
            if($action==='compare'){
                $q=$pdo->prepare("SELECT id,name,preparation_notes,version,is_active FROM menu_items
                    WHERE organization_id=? AND id=? LIMIT 1");
                $q->execute([$org,$itemId]);$source=$q->fetch(PDO::FETCH_ASSOC);
                if(!$source)throw new DomainException('The menu item does not belong to this organization.');
                $difference=fm13g_diff_summary($version);
                $isCurrent=hash_equals($version['afterHash'],
                    fm13f_notes_digest((string)($source['preparation_notes']??'')));
                $answer='Revision '.$version['versionId'].' of '.$source['name'].
                    ' changed the stored preparation notes from the before text to the after text.'.
                    ($isCurrent?' This revision matches the current published notes.':
                        ' The current menu notes differ from this revision.').
                    ' To restore the before text, type "draft rollback procedure '.$version['versionId'].
                    '". This will NOT change the menu until you separately approve and publish its new proposal.';
                $data=['mode'=>'compare','itemName'=>(string)$source['name'],
                    'menuVersion'=>(int)$source['version'],'isActive'=>(bool)$source['is_active'],
                    'isCurrent'=>$isCurrent,'revision'=>$difference];
            }else{
                // A rollback is a new G13F draft, not direct publication. The
                // item row lock and canonical turn replay guard serialize drafts.
                $source=fm13f_source_snapshot($pdo,$org,$itemId);
                if(!$source)throw new DomainException('Only active menu items may receive a rollback draft.');
                $events=$ledger?gaw_messages($pdo,$org,$uid,$ledger,300):[];
                if(fm13g_turn_seen($events,$turnId))
                    throw new DomainException('This saved chat turn has already created a procedure proposal.');
                $version=fm13g_find_version(fm13g_versions($events,$org,$uid),(string)$intent['versionId']);
                if(!$version)throw new DomainException('The selected version has changed or is no longer available.');
                $proposal=fm13g_rollback_proposal($version,$source,$org,$uid,time(),
                    'prp-'.bin2hex(random_bytes(10)));
                if(!$proposal)throw new DomainException('The historical before text is unavailable, invalid, or already matches the current procedure.');
                fm13f_record($pdo,$org,$uid,$ledger,$proposal,$turnId);
                app_audit($pdo,$org,$uid,'food_monster.procedure_rollback_draft',
                    'menu_item',(string)$itemId,null,
                    ['proposalId'=>$proposal['id'],'sourceVersionId'=>$version['versionId'],
                        'sourceHash'=>$proposal['sourceHash'],'menuVersion'=>$proposal['sourceVersion']]);
                $answer='Rollback draft '.$proposal['id'].' restores the BEFORE text of '.
                    $version['versionId'].' for '.$proposal['itemName'].
                    '. No menu changes were made. Review the proposed full text against kitchen standards:'.
                    "\n".$proposal['replacement'].
                    "\nIf appropriate, type \"approve procedure ".$proposal['id'].
                    "\". A separate \"publish procedure ".$proposal['id'].
                    "\" is still required; any intervening menu edit invalidates this draft.";
                $data=['mode'=>'rollback_draft','proposalId'=>$proposal['id'],
                    'versionId'=>$version['versionId'],'itemId'=>$itemId,
                    'status'=>'draft','published'=>false,'sourceHash'=>$proposal['sourceHash']];
            }
        }
        if($pdo->inTransaction())$pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    if($action!=='rollback_draft')app_audit($pdo,$org,$uid,
        'food_monster.procedure_'.($action==='history'?'history_read':'version_compare'),
        'menu_item',(string)($data['itemId']??$version['itemId']??0),null,
        ['revisionId'=>$version['versionId']??null,'partial'=>$partial]);
    app_json_response(['ok'=>true,'skill'=>'food_monster.procedure_version_history',
        'answer'=>$answer,'sources'=>['Gelato server-owned published procedure proposal history'],
        'data'=>$data]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13G procedure version history failed: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Procedure version history is unavailable. No menu changes were made.'],503);
}
