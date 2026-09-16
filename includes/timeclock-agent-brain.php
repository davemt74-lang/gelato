<?php
declare(strict_types=1);

require_once __DIR__.'/timeclock-agent-core.php';

function timeclock_agent_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!app_has_permission('attendance.view',$user)||!timeclock_agent_ready($pdo))return [];
    $org=(int)$user['organization_id'];$signals=[];
    $noShows=timeclock_agent_no_shows($pdo,$org,null,10);
    if($noShows){
        $count=count($noShows);$severity=$count>=2?'high':'normal';$score=$count>=2?82:68;
        $signals[]=[
            'key'=>'timeclock:no-shows','node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','severity'=>$severity,'score'=>$score,
            'title'=>'Attendance coverage needs attention','detail'=>$count.' potential no-show'.($count===1?'':'s').' detected during active shifts.','href'=>'timeclock.php',
            'evidence'=>['noShows'=>$count,'employees'=>array_values(array_map(static fn($r)=>(string)$r['display_name'],$noShows))],
            'nextMove'=>['node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','mode'=>'review','label'=>'Review attendance','prompt'=>'Review current attendance exceptions and tell me which no-shows or late arrivals need manager attention first.'],
        ];
    }
    $long=$pdo->prepare("SELECT COUNT(*) FROM time_clock_entries WHERE organization_id=? AND status='open' AND clocked_out_at IS NULL AND clocked_in_at<=DATE_SUB(NOW(6),INTERVAL 12 HOUR)");$long->execute([$org]);$longOpen=(int)$long->fetchColumn();
    if($longOpen>0){
        $signals[]=[
            'key'=>'timeclock:long-open','node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','severity'=>'high','score'=>88,
            'title'=>'Long-running clock entries need review','detail'=>$longOpen.' open time-clock entr'.($longOpen===1?'y has':'ies have').' exceeded 12 hours.','href'=>'timeclock.php',
            'evidence'=>['longOpenEntries'=>$longOpen],
            'nextMove'=>['node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','mode'=>'review','label'=>'Review time clock','prompt'=>'Show me employees with unusually long open clock entries and the related attendance context.'],
        ];
    }
    $summary=tv_daily_summary($pdo,$org,date('Y-m-d'));$variance=$summary['actualMinutes']-$summary['scheduledMinutes'];
    if($summary['scheduledMinutes']>0&&$variance>=60){
        $pct=$summary['scheduledMinutes']>0?round(($variance/$summary['scheduledMinutes'])*100):0;
        $signals[]=[
            'key'=>'timeclock:labor-over','node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','severity'=>$pct>=20?'high':'normal','score'=>$pct>=20?74:56,
            'title'=>'Actual labor is above schedule','detail'=>'Clocked labor is '.$variance.' minutes above today\'s scheduled labor so far.','href'=>'timeclock.php',
            'evidence'=>['scheduledMinutes'=>$summary['scheduledMinutes'],'actualMinutes'=>$summary['actualMinutes'],'varianceMinutes'=>$variance,'variancePercent'=>$pct],
            'nextMove'=>['node'=>'timeclock','nodeLabel'=>'Time Clock + Attendance','mode'=>'review','label'=>'Review labor variance','prompt'=>'Compare scheduled versus actual labor today and explain where the variance is coming from.'],
        ];
    }
    return $signals;
}

function timeclock_agent_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=timeclock_agent_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);$rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}
