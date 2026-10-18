<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-g14a-pilot.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$in=app_json_input();app_verify_request_csrf($in);
try {
    if(($in['action']??'')!=='ask')throw new InvalidArgumentException('Use Agent Chat pilot commands.');
    $message=trim((string)($in['message']??''));
    $threadId=trim((string)($in['threadId']??($_GET['threadId']??'')));
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',$threadId)
        ||$message===''||mb_strlen($message,'UTF-8')>700)
        throw new InvalidArgumentException('A valid current Agent Chat thread and command are required.');
    $turn=gaw_messages($pdo,$org,$uid,$threadId,1)[0]??[];
    if(($turn['role']??'')!=='user'||($turn['channel']??'')!=='text'
        ||trim((string)($turn['content']??''))!==$message
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($turn['public_id']??'')))
        throw new DomainException('Use a fresh typed request in your authenticated Agent Chat.');
    if(!fm14a_authorized($user,(string)$turn['channel']))
        throw new DomainException('Only the Super Admin may review or record kitchen pilot checks.');
    $intent=fm14a_intent($message);
    if($intent===null)throw new InvalidArgumentException('Use kitchen pilot readiness, start kitchen pilot, record kitchen pilot GATE OUTCOME: evidence, or close kitchen pilot.');
    $pdo->beginTransaction();
    try{
        // Serialize two tabs using the trusted current owner, not the
        // untrusted client conversation or any perceived speaker.
        $lock=$pdo->prepare("SELECT u.id FROM users u
            INNER JOIN organization_memberships om ON om.user_id=u.id
                AND om.organization_id=? AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
            FOR UPDATE");
        $lock->execute([$org,$uid]);
        if(!$lock->fetchColumn())throw new DomainException('Owner account is unavailable.');
        $ledger=fm14a_thread($pdo,$org,$uid,$intent['action']!=='status');
        $messages=$ledger?gaw_messages($pdo,$org,$uid,$ledger,300):[];
        $events=fm14a_events($messages,$uid);
        $snapshot=fm14a_state($events,time());
        $reason=fm14a_validate_action($intent,$snapshot,(string)$turn['public_id'],$events);
        if($reason!==null)throw new DomainException($reason);
        $action=$intent['action'];
        if($action!=='status'){
            $runId=$action==='start'?'pilot-'.bin2hex(random_bytes(10)):(string)$snapshot['runId'];
            $event=['collection'=>'fm14a_kitchen_pilot','source'=>'gelato_server',
                'ownerId'=>$uid,'runId'=>$runId,'action'=>$action,
                'sourceTurnId'=>(string)$turn['public_id'],'recordedAt'=>time()];
            if($action==='record'){
                $event['gate']=$intent['gate'];$event['outcome']=$intent['outcome'];
                $event['evidence']=$intent['evidence'];
            }
            gaw_append($pdo,$org,$uid,$ledger,'system','Kitchen pilot field check recorded',[
                'channel'=>'text','structured'=>$event]);
            $events[]=$event;$snapshot=fm14a_state($events,time());
            app_audit($pdo,$org,$uid,'food_monster.pilot_'.$action,'agent_conversation',
                $ledger,null,['pilotId'=>$runId,'gate'=>$intent['gate']??null,
                    'outcome'=>$intent['outcome']??null]);
        }
        $partial=count($messages)>=300;
        $pdo->commit();
        $data=$snapshot;
        $data['partialHistory']=$partial;
        $data['criteria']='Seven manual field checks; recordings are not stored or required.';
        $data['operatorReported']=$snapshot['allOperatorReportedPass'];
        // Operator assertions are not verified observations. Do not advertise
        // certification regardless of how many pass boxes have been checked.
        $data['fieldCertified']=false;
        $answer=$snapshot['active']?'Kitchen pilot '.$snapshot['runId'].' is active. ':
            ($snapshot['closed']?'The latest kitchen pilot is closed. ':'No active kitchen pilot. Type "start kitchen pilot" to begin. ');
        $answer.=$snapshot['passed'].' of '.$snapshot['total'].' checks have fresh, operator-reported passes. ';
        if($snapshot['allOperatorReportedPass'])$answer.='All check results are self-reported; independent device and kitchen certification remains outstanding. ';
        else $answer.='Open the Agent Brain result panel for remaining checks. ';
        $answer.='Do not include employee names, biometrics, audio or private customer data in evidence. ';
        if($partial)$answer.='History is partial; earlier pilot events may be omitted.';
        app_json_response(['ok'=>true,'skill'=>'food_monster.kitchen_pilot',
            'answer'=>$answer,'sources'=>['Gelato owner-reported kitchen pilot checklist'],
            'data'=>$data]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-G14A pilot status error: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Kitchen pilot information is unavailable. No check was recorded.'],503);
}
