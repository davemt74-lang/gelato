<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';
$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);
try {
    $action=(string)($in['action']??'');
    $pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
    gac_request_context($in,$pdo,$user);
    if($action==='ask'){
        if(!fm9a_is_super_admin_session($user))throw new DomainException('Super Admin required.');
        $thread=trim((string)($in['threadId']??''));
        $message=trim((string)($in['message']??''));
        if($thread===''||!gaw_thread($pdo,$org,$uid,$thread))throw new InvalidArgumentException('Conversation not found.');
        $latest=gaw_messages($pdo,$org,$uid,$thread,1);
        $last=$latest[0]??null;
        if(!$last||($last['role']??'')!=='user'||trim((string)($last['content']??''))!==$message)
            throw new InvalidArgumentException('Send this request through your authenticated Agent Chat.');
        if(($last['channel']??'')!=='text'||!empty($last['voice_event_public_id']))
            throw new DomainException('High-impact approvals must be typed in the Super Admin account. Room voice cannot approve actions.');
        $in['sourceMessageId']=(string)$last['public_id'];
        gac_request_context($in,$pdo,$user);
        if(preg_match('/^Confirm[.!]?$/i',$message)
            &&($grant=fm9i_active_grant($user,$thread))){
            fm9f_verify_grant_review($user,$grant,$in);
            $result=fm9i_execute($pdo,$user,$thread);
            $answer='Confirmed. Restaurant task '.$result['taskId'].
                ' was archived with its history preserved. Agent Brain recorded the execution under approval '.$result['approvalId'].'.';
            app_json_response(['ok'=>true,'skill'=>'food_monster.task_archived',
                'answer'=>$answer,'data'=>['execution'=>$result],'sources'=>['Restaurant Tasks']]);
        }
        if(preg_match('/^Confirm[.!]?$/i',$message)
            &&($grant=fm9h_active_grant($user,$thread))){
            fm9f_verify_grant_review($user,$grant,$in);
            $result=fm9h_execute_role($pdo,$user,$thread);
            $answer='Confirmed. '.$result['operation'].' '.$result['permission'].' on '.$result['roleId'].
                '. Permission change executed and recorded in Agent Brain (approval '.$result['approvalId'].').';
            app_json_response(['ok'=>true,'skill'=>'food_monster.permission_change_executed',
                'answer'=>$answer,'data'=>['execution'=>$result],'sources'=>['Roles & Permissions']]);
        }
        if(gac_is_confirm($message))throw new GacConfirmationConflict('That approval has expired or has already been used. Review a new approval.');
        $intent=fm9f_approval_chat_intent($message);
        if(!$intent)throw new InvalidArgumentException('Use an explicit approval request or Approve / Reject.');
        if($intent['action']==='propose'){
            $approval=fm9f_approval_propose($user,$intent['kind'],$intent['summary'],$thread,$pdo);
            $answer='High-impact approval request recorded: '.$approval['summary'].'. Reply Approve or Reject in this conversation. Approval never executes the underlying action; the relevant Gelato module still requires its own authorized confirmation.';
            $state='proposed';
        }else{
            $pending=gac_pending_get('food_monster_high_impact',$org,$uid);
            if(!$pending||($pending['payload']['threadId']??'')!==$thread)
                throw new GacConfirmationConflict('No pending high-impact approval belongs to this conversation.');
            $approval=fm9f_approval_decide($user,(string)($in['proposalId']??''),$intent['decision'],$in);
            $state=$approval['decision']==='approve'?'approved':'rejected';
            $answer=$state==='approved'
                ?(!empty($approval['handoff'])
                    ?(($approval['kind']??'')==='permission_change'
                        ? 'Approval recorded for '.$approval['handoff']['roleId'].': '.$approval['handoff']['operation'].' '.$approval['handoff']['permission'].'. No change executed yet. Type Confirm in this authenticated Agent Chat to apply the verified role permission change.'
                        : (($approval['kind']??'')==='record_deletion'
                            ? 'Approval recorded to archive task '.$approval['handoff']['taskId'].'. No task changed yet. Type Confirm in this authenticated Agent Chat to archive this exact task.'
                            : 'Approval recorded for purchase order '.$approval['handoff']['purchaseOrderId'].'. No order submitted yet. Type Confirm in this authenticated Agent Chat to execute the original Purchasing Agent proposal.'))
                    :'Approval recorded. No purchase, refund, deletion, or permission change was executed. Continue through the relevant Gelato module to confirm the authorized operation.')
                :'Request rejected. No operational action was executed.';
        }
        gaw_append($pdo,$org,$uid,$thread,'system','Food Monster high-impact request '.$state.'.',[
            'channel'=>'proactive','structured'=>['collection'=>'fm9f_high_impact_decision',
            'proposalId'=>$approval['proposalId'],'kind'=>$approval['kind'],
            'purchaseOrderId'=>$approval['handoff']['purchaseOrderId']??null,
            'state'=>$state,'executed'=>false,'actorUserId'=>$uid]]);
        app_audit($pdo,$org,$uid,'food_monster.high_impact_'.$state,'agent_conversation',$thread,null,
            ['proposalId'=>$approval['proposalId'],'kind'=>$approval['kind'],'executed'=>false]);
        $data=['approval'=>$approval,'state'=>$state];
        if($state==='proposed'){
            $data['proposal']=$approval['proposal'];$data['requiresApproval']=true;
            $answer=gac_proposal_answer(gac_pending_get('food_monster_high_impact',$org,$uid));
        }elseif(is_array($approval['handoff']['reviewedProposal']??null)){
            $data['reviewedProposal']=$approval['handoff']['reviewedProposal'];
        }
        app_json_response(['ok'=>true,'skill'=>'food_monster.approval_gate','answer'=>$answer,'data'=>$data,'sources'=>[]]);
    }
    if(in_array($action,['propose','decide'],true)){
        $typed=gac_canonical_message($pdo,$org,$uid,(string)($in['threadId']??''),(string)($in['sourceMessageId']??''),'user');
        if(($typed['channel']??'')!=='text'||!empty($typed['voice_event_public_id']))
            throw new DomainException('High-impact approvals must be typed in the Super Admin account.');
    }
    if($action==='propose'){
        $thread=trim((string)($in['threadId']??''));
        if(!gaw_thread($pdo,$org,$uid,$thread))throw new InvalidArgumentException('Conversation not found.');
        $result=fm9f_approval_propose($user,(string)($in['kind']??''),(string)($in['summary']??''),$thread,$pdo);
        app_audit($pdo,$org,$uid,'food_monster.high_impact_proposed','agent_conversation',$thread,null,
            ['proposalId'=>$result['proposalId'],'kind'=>$result['kind'],'executed'=>false]);
        app_json_response(['ok'=>true,'approval'=>$result]);
    }
    if($action==='decide'){
        $result=fm9f_approval_decide($user,trim((string)($in['proposalId']??'')),(string)($in['decision']??''),$in);
        app_audit($pdo,$org,$uid,'food_monster.high_impact_decided','agent_conversation',$result['threadId'],null,
            ['proposalId'=>$result['proposalId'],'kind'=>$result['kind'],'decision'=>$result['decision'],'executed'=>false]);
        app_json_response(['ok'=>true,'approval'=>$result]);
    }
    throw new InvalidArgumentException('Unsupported approval action.');
}catch(GacConfirmationConflict $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],409);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(Throwable $e){error_log('FM-G9F approval error: '.get_class($e));app_json_response(['ok'=>false,'message'=>'Approval workflow unavailable. No action was executed.'],503);}
