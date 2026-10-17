<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-g13e-intelligence.php';

$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
try{
    if(($input['action']??'')!=='ask')throw new InvalidArgumentException('Read-only training intelligence only.');
    $text=trim((string)($input['message']??''));
    $room=trim((string)($input['threadId']??($_GET['threadId']??'')));
    if($text===''||mb_strlen($text,'UTF-8')>200
        ||preg_match('/^agent-[a-f0-9]{20}$/D',$room)!==1)
        throw new InvalidArgumentException('Enter a valid Agent Chat request and conversation.');
    $latest=gaw_messages($pdo,$org,$uid,$room,1)[0]??[];
    if(($latest['role']??'')!=='user'||($latest['channel']??'')!=='text'
        ||trim((string)($latest['content']??''))!==$text)
        throw new DomainException('A fresh authenticated typed Agent Chat message is required.');
    $scope=fm13e_scope($user,fm13e_intent($text),'text',fm9a_is_super_admin_session($user));
    if($scope===null)throw new DomainException('This training insight view is not authorized.');
    $people=[];
    if($scope==='self'){
        $p=$pdo->prepare("SELECT u.id,u.display_name FROM users u
            JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
                AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
        $p->execute([$org,$uid]);$one=$p->fetch(PDO::FETCH_ASSOC);
        if($one)$people[]=$one;
    }else{
        // Super Admin only, and membership checked by SQL. Never accept a
        // participant ID, name, biometric or employee roster from the browser.
        $p=$pdo->prepare("SELECT u.id,u.display_name FROM users u
            JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
                AND om.status='active'
            WHERE u.status='active' AND u.archived_at IS NULL
            ORDER BY u.id ASC LIMIT 31");
        $p->execute([$org]);$people=$p->fetchAll(PDO::FETCH_ASSOC);
    }
    $teamCapped=count($people)>30;
    $people=array_slice($people,0,30);
    $ids=array_map('intval',array_column($people,'id'));
    $data=fm13e_recent_records($pdo,$org,$ids);
    // A staff self-view must not reveal recipe activity when the account has
    // no recipe read permission, even if old private records remain present.
    if(!fm13b_allowed($user,'recipe',false,false)){
        $data['rows']=array_values(array_filter($data['rows'],static function($row):bool{
            $state=$row['structured']['state']??[];
            return ($state['kind']??'')!=='recipe';
        }));
    }
    $summary=fm13e_aggregate($data['rows'],$ids,30);
    $summary['capped']=$data['capped']||$teamCapped;
    foreach($summary['participants'] as &$entry){
        $current=$entry['current'];
        $material=null;
        try{$material=fm13c_fetch_material($pdo,$org,$current);}catch(Throwable $e){
            // A stale or no-longer-readable source must not fabricate a title.
        }
        foreach($people as $person)if((int)$person['id']===(int)$entry['personId']){
            $entry['displayName']=$scope==='self'?'Your training':
                mb_substr((string)($person['display_name']??'Participant'),0,70,'UTF-8');
            break;
        }
        $entry['sourceAvailable']=$material!==null
            &&($material['digest']??'')===($current['digest']??'');
        $entry['sourceTitle']=$entry['sourceAvailable']
            ?mb_substr((string)$material['name'],0,100,'UTF-8'):null;
        unset($entry['current']['digest']);
    }
    unset($entry);
    $result=fm13e_answer($summary,$scope,$people);
    app_audit($pdo,$org,$uid,'food_monster.training_intelligence_read',
        'agent_conversation',$room,null,
        ['scope'=>$scope,'count'=>$summary['peopleWithActivity'],'partial'=>$summary['capped']]);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G13E training intelligence failed: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Training intelligence is unavailable. No training records were changed.'],503);
}
