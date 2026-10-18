<?php
declare(strict_types=1);
require_once __DIR__.'/agent-workspace-core.php';

/**
 * Kitchen pilot evidence is a MANUAL OPERATOR REPORT, never a device
 * certification or verified biometric/safety assertion.
 */
function fm14a_gates():array
{
    return [
        'microphone'=>'Browser microphone permission and listening recovery',
        'transcription'=>'Final speech reliably appears in chat input',
        'handoff'=>'Known-speaker transition and unknown-speaker isolation',
        'camera'=>'Camera consent, tracking and disconnection recovery',
        'timeclock'=>'Active shift, break and clock-out awareness',
        'kds'=>'KDS navigation during voice/conversation without interference',
        'recovery'=>'Disconnect, reload and microphone-error recovery'
    ];
}
function fm14a_intent(string $message):?array
{
    $message=trim(preg_replace('/\s+/u',' ',$message)??$message);
    if(preg_match('/^(?:kitchen pilot readiness|kitchen pilot status|show kitchen pilot checks)[.!]?$/iu',$message))
        return ['action'=>'status'];
    if(preg_match('/^start kitchen pilot[.!]?$/iu',$message))return ['action'=>'start'];
    if(preg_match('/^close kitchen pilot[.!]?$/iu',$message))return ['action'=>'close'];
    if(preg_match('/^record kitchen pilot (microphone|transcription|handoff|camera|timeclock|kds|recovery) (pass|fail|blocked):\s*(.{20,500})$/ius',$message,$m))
        return ['action'=>'record','gate'=>strtolower($m[1]),
            'outcome'=>strtolower($m[2]),'evidence'=>trim($m[3])];
    return null;
}
function fm14a_authorized(array $user,string $channel):bool
{
    return $channel==='text'&&fm9a_is_super_admin_session($user)
        &&app_has_permission('agent.manage',$user);
}
function fm14a_evidence_ok(string $value):bool
{
    return mb_strlen($value,'UTF-8')>=20&&mb_strlen($value,'UTF-8')<=500
        &&preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value)!==1;
}
function fm14a_thread(PDO $pdo,int $org,int $owner,bool $create):?string
{
    $title='Food Monster Kitchen Pilot';
    $q=$pdo->prepare("SELECT public_id FROM agent_conversations WHERE organization_id=?
        AND user_id=? AND title=? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$owner,$title]);$thread=(string)($q->fetchColumn()?:'');
    return $thread!==''?$thread:($create
        ?(string)gaw_create_thread($pdo,$org,$owner,'text',$title)['public_id']:null);
}
/** All events are server-owned and actor-specific. Never use user authored structured JSON. */
function fm14a_events(array $messages,int $owner):array
{
    $events=[];
    foreach($messages as $m){
        $meta=$m['structured']??null;
        if(($m['role']??'')!=='system'||!is_array($meta)
            ||($meta['collection']??'')!=='fm14a_kitchen_pilot'
            ||($meta['source']??'')!=='gelato_server'
            ||(int)($meta['ownerId']??0)!==$owner
            ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($meta['sourceTurnId']??'')))continue;
        $events[]=$meta;
    }
    return $events;
}
function fm14a_state(array $events,int $now):array
{
    $run=null;$closed=false;$reports=[];
    foreach($events as $e){
        $action=(string)($e['action']??'');
        $id=(string)($e['runId']??'');
        if(!preg_match('/^pilot-[a-f0-9]{20}$/D',$id))continue;
        if($action==='start'){
            $run=$id;$closed=false;$reports=[];
        }elseif($run!==$id||$closed)continue;
        elseif($action==='close')$closed=true;
        elseif($action==='record'&&isset(fm14a_gates()[$e['gate']??''])
            &&in_array($e['outcome']??'',['pass','fail','blocked'],true)
            &&fm14a_evidence_ok((string)($e['evidence']??''))){
            $reports[$e['gate']]=[
                'outcome'=>$e['outcome'],
                'evidence'=>mb_substr((string)$e['evidence'],0,500,'UTF-8'),
                'reportedAt'=>(int)($e['recordedAt']??0),
                'sourceTurnId'=>(string)$e['sourceTurnId']
            ];
        }
    }
    $results=[];$passed=0;$expired=0;
    foreach(fm14a_gates() as $key=>$title){
        $report=$reports[$key]??null;
        $fresh=$report!==null&&$report['reportedAt']>0
            &&$report['reportedAt']<=$now&&$now-$report['reportedAt']<=604800;
        $state=$report===null?'not_tested':(!$fresh?'stale':$report['outcome']);
        if($state==='pass')$passed++;
        if($state==='stale')$expired++;
        $results[]=['gate'=>$key,'title'=>$title,'status'=>$state,
            'evidence'=>$report['evidence']??null,
            'reportedAt'=>$report['reportedAt']??null];
    }
    return ['runId'=>$run,'closed'=>$closed,'active'=>$run!==null&&!$closed,
        'passed'=>$passed,'total'=>count($results),'stale'=>$expired,
        'allOperatorReportedPass'=>$run!==null&&$passed===count($results),
        'fieldCertified'=>false,'items'=>$results,
        'evidenceType'=>'owner_reported_not_device_verified'];
}
function fm14a_validate_action(array $intent,array $state,string $turnId,array $events):?string
{
    foreach($events as $event){
        if(($event['sourceTurnId']??'')===$turnId)return 'That chat message was already processed.';
    }
    if($intent['action']==='status')return null;
    if($intent['action']==='start'&&$state['active'])return 'Close the existing pilot before starting another.';
    if(in_array($intent['action'],['close','record'],true)&&!$state['active'])
        return 'Start an active kitchen pilot before recording or closing.';
    if($intent['action']==='record'&&!fm14a_evidence_ok((string)($intent['evidence']??'')))
        return 'Enter 20–500 characters of non-sensitive test evidence.';
    return null;
}
