<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-g13f-procedure-review.php';
require_once __DIR__.'/../includes/food-monster-g13h-unified-audit.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$in=app_json_input();app_verify_request_csrf($in);
try{
    if(($in['action']??'')!=='ask')throw new InvalidArgumentException('Only the governed Agent Chat review is accepted.');
    $message=trim((string)($in['message']??''));
    $room=trim((string)($in['threadId']??($_GET['threadId']??'')));
    if($message===''||mb_strlen($message,'UTF-8')>2600
        ||!preg_match('/^agent-[a-f0-9]{20}$/D',$room))
        throw new InvalidArgumentException('A valid Agent Chat message and thread are required.');
    $turn=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($turn['role']??'')!=='user'||($turn['channel']??'')!=='text'
        ||trim((string)($turn['content']??''))!==$message
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($turn['public_id']??'')))
        throw new DomainException('Use a fresh typed message in your authenticated Agent Chat.');
    if(!fm13f_authorized($user,(string)$turn['channel']))
        throw new DomainException('Super Admin and Menu Manager permission are required.');
    $intent=fm13f_intent($message);
    if(!$intent)throw new InvalidArgumentException('Use review training procedures, draft menu procedure ID: text, approve/reject procedure ID, or publish procedure ID.');
    $action=$intent['action'];$turnId=(string)$turn['public_id'];
    if($action==='review'){
        $q=$pdo->prepare("SELECT u.id FROM users u
          INNER JOIN organization_memberships om ON om.user_id=u.id
            AND om.organization_id=? AND om.status='active'
          WHERE u.status='active' AND u.archived_at IS NULL
          ORDER BY u.id LIMIT 31");
        $q->execute([$org]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $cappedPeople=count($ids)>30;$ids=array_slice($ids,0,30);
        $evidence=fm13e_recent_records($pdo,$org,$ids);
        $signals=fm13f_review_signals($evidence['rows'],$ids);
        $items=[];
        foreach($signals as $signal){
            $item=menu_training_item($pdo,$org,$signal['itemId']);
            if(!$item)continue;
            $signal['name']=mb_substr((string)$item['name'],0,90,'UTF-8');
            $signal['recordedPreparation']=mb_substr((string)$item['preparationNotes'],0,300,'UTF-8');
            $signal['source']='Active Gelato menu item #'.$signal['itemId'];
            $items[]=$signal;
        }
        $partial=$cappedPeople||$evidence['capped'];
        $notices=array_values(array_filter($items,static fn($i)=>$i['reviewSuggested']));
        $answer=$notices
            ?'Recorded training help and repeat activity suggests reviewing: '.
                implode('; ',array_map(static fn($i)=>$i['name'].' (menu #'.$i['itemId'].')',
                    array_slice($notices,0,8))).'.'
            :'No menu procedure crossed the configured repeated-help threshold in this limited history.';
        $answer.=' Suggestions are not proof that instructions are wrong. Compare with current kitchen standards, then manually draft a complete correction for Super Admin approval before publication.';
        if($partial)$answer.=' This is a partial 60-day history; older or additional staff events may be excluded.';
        app_audit($pdo,$org,$uid,'food_monster.procedure_review_read',
            'agent_conversation',$room,null,['candidateCount'=>count($items),'partial'=>$partial]);
        app_json_response(['ok'=>true,'skill'=>'food_monster.procedure_review',
            'answer'=>$answer,'sources'=>['Gelato canonical guided training actions','Active menu preparation notes'],
            'data'=>['readOnly'=>true,'partial'=>$partial,'candidates'=>$items]]);
    }

    // DB item row lock serializes approval, publication and draft writes.
    $pdo->beginTransaction();
    try{
        $proposal=null;$ledger=null;
        if($action==='draft'){
            $itemId=(int)$intent['itemId'];
            $item=fm13f_source_snapshot($pdo,$org,$itemId);
            if(!$item)throw new DomainException('The requested active menu item was not found.');
            $old=(string)($item['preparation_notes']??'');
            $replacement=(string)$intent['replacement'];
            if(!fm13f_validate_note($old,$replacement))
                throw new InvalidArgumentException('Supply a different, complete procedure between 15 and 2400 characters.');
            $ledger=fm13f_proposal_thread($pdo,$org,$uid,true);
            $proposal=[
                'id'=>'prp-'.bin2hex(random_bytes(10)),'ownerId'=>$uid,'orgId'=>$org,
                'itemId'=>$itemId,'itemName'=>mb_substr((string)$item['name'],0,100,'UTF-8'),
                'sourceHash'=>fm13f_notes_digest($old),'sourceVersion'=>(int)($item['version']??0),'original'=>$old,
                'replacement'=>$replacement,'createdAt'=>time(),
                'status'=>'draft','approvedAt'=>null,'publishedAt'=>null
            ];
            fm13f_record($pdo,$org,$uid,$ledger,$proposal,$turnId);
            $response='Draft '.$proposal['id'].' was saved for '.$proposal['itemName'].
                '. Existing instruction: '.mb_substr($old,0,380,'UTF-8').
                '. Proposed full replacement: '.mb_substr($replacement,0,700,'UTF-8').
                '. No menu record changed. Review with the kitchen manager, then type "approve procedure '.
                $proposal['id'].'" or "reject procedure '.$proposal['id'].'".';
        }else{
            $id=(string)$intent['proposalId'];
            $ledger=fm13f_proposal_thread($pdo,$org,$uid,false);
            if(!$ledger)throw new DomainException('No procedure review records belong to your account.');
            // First read is only a locator; re-read under menu row lock.
            $existing=fm13f_latest_proposal(gaw_messages($pdo,$org,$uid,$ledger,300),$id);
            if(!$existing||(int)($existing['ownerId']??0)!==$uid
                ||(int)($existing['orgId']??0)!==$org)
                throw new DomainException('A current proposal belonging to this organization is required.');
            $item=fm13f_source_snapshot($pdo,$org,(int)$existing['itemId']);
            if(!$item)throw new DomainException('The source menu item is no longer active.');
            $proposal=fm13f_latest_proposal(gaw_messages($pdo,$org,$uid,$ledger,300),$id);
            if(!$proposal||(int)($proposal['ownerId']??0)!==$uid
                ||(int)($proposal['itemId']??0)!==(int)$item['id'])
                throw new DomainException('Proposal state changed during review.');
            $old=(string)($item['preparation_notes']??'');
            if(isset($proposal['sourceVersion'])
                &&(int)$proposal['sourceVersion']!==(int)($item['version']??-1))
                throw new DomainException('The menu item version changed; start a new review.');
            $decision=fm13f_decide($proposal,$action,$old,time());
            if(!$decision['allowed'])throw new DomainException($decision['reason']);
            if($action==='approve')$proposal['approvedAt']=time();
            if($action==='publish'){
                // The approved replacement is locked to the original exact
                // procedure text; concurrent edits must force a fresh review.
                $sql="UPDATE menu_items SET preparation_notes=?,version=version+1,updated_at=NOW(6)
                    WHERE organization_id=? AND id=? AND is_active=1
                      AND COALESCE(preparation_notes,'')=?";
                $args=[(string)$proposal['replacement'],$org,(int)$proposal['itemId'],$old];
                if(isset($proposal['sourceVersion'])){
                    $sql.=' AND version=?';
                    $args[]=(int)$proposal['sourceVersion'];
                }
                $q=$pdo->prepare($sql);
                $q->execute($args);
                if($q->rowCount()!==1)
                    throw new DomainException('The menu procedure changed; nothing was published.');
                fm13h_audit_procedure($pdo,$org,$uid,(int)$proposal['itemId'],
                    (string)$proposal['itemName'],$old,(string)$proposal['replacement'],
                    (int)($item['version']??0),(int)($item['version']??0)+1,
                    'food_monster',(string)$proposal['id']);
                $proposal['publishedAt']=time();
            }
            $proposal['status']=$decision['status'];
            fm13f_record($pdo,$org,$uid,$ledger,$proposal,$turnId);
            $response=$action==='publish'
                ?'Published approved menu preparation notes for '.$proposal['itemName'].
                    '. Source version was verified under lock. This update changes training instructions, not ingredients, allergens, menu prices, or employee certifications.'
                :ucfirst($action).' recorded for '.$proposal['itemName'].' ('.$proposal['id'].
                    '). '.($action==='approve'
                        ?'Approval alone made no changes. Type "publish procedure '.$proposal['id'].'" to apply the exact approved replacement.'
                        :'Nothing was published.');
        }
        app_audit($pdo,$org,$uid,'food_monster.procedure_'.$action,
            'menu_item',(string)$proposal['itemId'],null,
            ['proposalId'=>$proposal['id'],'status'=>$proposal['status'],
                'reviewThread'=>$ledger,'sourceHash'=>$proposal['sourceHash']]);
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    app_json_response(['ok'=>true,'skill'=>'food_monster.procedure_review',
        'answer'=>$response,'sources'=>['Gelato Menu Manager: '.$proposal['itemName']],
        'data'=>['proposalId'=>$proposal['id'],'itemId'=>$proposal['itemId'],
            'status'=>$proposal['status'],'published'=>$proposal['status']==='published',
            'requiresExplicitPublish'=>$proposal['status']==='approved']]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13F procedure review error: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Procedure review unavailable. No unapproved menu changes were published.'],503);
}
