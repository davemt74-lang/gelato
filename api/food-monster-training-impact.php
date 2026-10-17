<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-g13i-training-impact.php';
$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$in=app_json_input();app_verify_request_csrf($in);
try{
    if(($in['action']??'')!=='ask')throw new InvalidArgumentException('Read-only training change review only.');
    $message=trim((string)($in['message']??''));
    $room=trim((string)($in['threadId']??($_GET['threadId']??'')));
    if($message===''||mb_strlen($message,'UTF-8')>180
       ||!preg_match('/^agent-[a-f0-9]{20}$/D',$room))
        throw new InvalidArgumentException('A valid Agent Chat conversation is required.');
    $last=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($last['role']??'')!=='user'||($last['channel']??'')!=='text'
       ||trim((string)($last['content']??''))!==$message)
        throw new DomainException('A fresh, authenticated typed request is required.');
    $intent=fm13i_intent($message);
    $owner=fm9a_is_super_admin_session($user);
    if($intent===null||!fm13i_allowed($user,$intent,'text',$owner))
        throw new DomainException('This training impact view is not authorized.');
    $sql="SELECT u.id,u.display_name FROM users u
         JOIN organization_memberships om ON om.user_id=u.id
          AND om.organization_id=? AND om.status='active'
         WHERE u.status='active' AND u.archived_at IS NULL";
    $args=[$org];
    if($intent==='self'){$sql.=' AND u.id=?';$args[]=$uid;}
    $sql.=' ORDER BY u.id ASC LIMIT 31';
    $q=$pdo->prepare($sql);$q->execute($args);
    $roster=$q->fetchAll(PDO::FETCH_ASSOC);
    $partial=count($roster)>30;$roster=array_slice($roster,0,30);
    $audit=fm13h_recent($pdo,$org,500);$partial=$partial||$audit['partial'];
    $lastRevisions=fm13i_revisions($audit['events'],$org,$uid);
    $list=[];
    foreach($roster as $person){
        $personId=(int)$person['id'];
        $thread=fm13c_training_thread($pdo,$org,$personId,false);
        if(!$thread)continue;
        $state=fm13c_read_state($pdo,$org,$personId,$thread);
        // Menu prep records only: no employee gets another user's
        // private recipe text or a general health/performance profile.
        if(!is_array($state)||($state['kind']??'')!=='menu'
          ||!ctype_digit((string)($state['id']??'')))continue;
        $item=(int)$state['id'];if($item<=0)continue;
        $material=null;
        try{$material=fm13c_fetch_material($pdo,$org,$state);}catch(Throwable $e){
            // A missing or inactive item is explicitly flagged, not guessed.
        }
        $assessment=fm13i_assess($state,$material,$lastRevisions[$item]??null);
        $assessment['displayName']=$intent==='self'?'Your training':
            mb_substr((string)($person['display_name']??'Participant'),0,70,'UTF-8');
        $assessment['personId']=$intent==='team'?$personId:null;
        $list[]=$assessment;
    }
    $result=fm13i_summary($list,$intent,$partial);
    app_audit($pdo,$org,$uid,'food_monster.training_change_impact_read',
        'agent_conversation',$room,null,['scope'=>$intent,'count'=>count($list),
            'affected'=>$result['data']['affected'],'partial'=>$partial]);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13I training impact failed: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Training impact information is unavailable. No progress was changed.'],503);
}
