<?php
declare(strict_types=1);

/**
 * FM-G14C: explicit, owner-reported failure-to-fix workflow within the
 * existing G14A per-owner event ledger. No external task system, automatic
 * repairs, automatic device certification or inferred employee identities.
 */
function fm14c_intent(string $message):?array
{
    $message=trim(preg_replace('/\s+/u',' ',$message)??$message);
    if(preg_match('/^(?:kitchen pilot fixes|show kitchen pilot failures|kitchen pilot followups)[.!]?$/iu',$message))
        return ['action'=>'status'];
    if(preg_match('/^(plan|resolve) kitchen pilot fix (microphone|transcription|handoff|camera|timeclock|kds|recovery):\s*(.{20,500})$/ius',$message,$m))
        return ['action'=>strtolower($m[1])==='plan'?'plan_fix':'resolve_fix',
            'gate'=>strtolower($m[2]),'note'=>trim($m[3])];
    return null;
}
/** Return the latest per-gate issue in the active or most recently closed run. */
function fm14c_followups(array $events,int $now):array
{
    $run=null;$closed=false;$issues=[];$lastAction=[];
    foreach($events as $event){
        $runId=(string)($event['runId']??'');
        if(!preg_match('/^pilot-[a-f0-9]{20}$/D',$runId))continue;
        $action=(string)($event['action']??'');
        if($action==='start'){$run=$runId;$closed=false;$issues=[];$lastAction=[];continue;}
        if($run!==$runId||$closed)continue;
        if($action==='close'){$closed=true;continue;}
        $gate=(string)($event['gate']??'');
        if(!isset(fm14a_gates()[$gate]))continue;
        $when=(int)($event['recordedAt']??0);
        if($when<=0||$when>$now)continue;
        if($action==='record'){
            if(!in_array($event['outcome']??'',['pass','fail','blocked'],true)
                ||!fm14a_evidence_ok((string)($event['evidence']??'')))continue;
            if(in_array($event['outcome'],['fail','blocked'],true)){
                // A later regression reopens a previously fixed issue.
                $issues[$gate]=[
                    'gate'=>$gate,'status'=>'open','outcome'=>$event['outcome'],
                    'failureAt'=>$when,'failureEvidence'=>(string)$event['evidence'],
                    'plan'=>null,'plannedAt'=>null,'resolution'=>null,'resolvedAt'=>null,
                    'lastTestAt'=>$when
                ];
            }elseif(isset($issues[$gate])){
                $issue=&$issues[$gate];
                $issue['lastTestAt']=$when;
                if(in_array($issue['status'],['planned','ready_for_review'],true)
                   &&$issue['plannedAt']!==null&&$when >= $issue['plannedAt'])
                    $issue['status']='ready_for_review';
                unset($issue);
            }
        }elseif($action==='plan_fix'&&isset($issues[$gate])
            &&in_array($issues[$gate]['status'],['open','planned'],true)
            &&fm14a_evidence_ok((string)($event['note']??''))){
            $issues[$gate]['plan']=(string)$event['note'];
            $issues[$gate]['plannedAt']=$when;
            $issues[$gate]['status']='planned';
        }elseif($action==='resolve_fix'&&isset($issues[$gate])
            &&$issues[$gate]['status']==='ready_for_review'
            &&fm14a_evidence_ok((string)($event['note']??''))){
            $issues[$gate]['resolution']=(string)$event['note'];
            $issues[$gate]['resolvedAt']=$when;
            $issues[$gate]['status']='resolved';
        }
    }
    $list=[];
    foreach(fm14a_gates() as $gate=>$title){
        if(!isset($issues[$gate]))continue;
        $issue=$issues[$gate];
        $issue['title']=$title;
        $list[]=$issue;
    }
    $unresolved=count(array_filter($list,static fn($i)=>$i['status']!=='resolved'));
    return ['runId'=>$run,'closed'=>$closed,'issues'=>$list,
        'unresolved'=>$unresolved,'resolved'=>count($list)-$unresolved,
        'operatorReported'=>true,'automaticRepair'=>false];
}
function fm14c_validate(array $intent,array $state,string $turnId,array $events,int $now):?string
{
    if(!in_array(($intent['action']??''),['plan_fix','resolve_fix'],true))return null;
    if(!$state['active'])return 'Start an active kitchen pilot before managing a fix.';
    if(!isset(fm14a_gates()[$intent['gate']??''])
        ||!fm14a_evidence_ok((string)($intent['note']??'')))
        return 'A known gate and a 20–500 character non-sensitive note are required.';
    $f=fm14c_followups($events,$now);
    $issues=array_values(array_filter($f['issues'],static fn($i)=>$i['gate']===$intent['gate']));
    $issue=$issues[0]??null;
    if($intent['action']==='plan_fix'){
        if(!$issue||!in_array($issue['status'],['open','planned'],true))
            return 'Record a failed or blocked field check before planning a fix.';
    }elseif(!$issue||$issue['status']!=='ready_for_review'){
        return 'A planned fix requires a NEW operator-reported passing field check before resolution. Nothing was resolved.';
    }
    return null;
}
