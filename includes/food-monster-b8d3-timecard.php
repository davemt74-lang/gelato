<?php
declare(strict_types=1);

/**
 * FM-B8D3: self-service Time Clock evidence for the authenticated operator.
 * No model-supplied person/user identifier is accepted. The clock facts and
 * calculated totals stay in Gelato; only a local final answer reaches chat.
 */
function fm8d3_hours_period(array $args,DateTimeImmutable $now):array
{
    $tz=$now->getTimezone();
    $today=$now->setTime(0,0,0);
    $period=(string)($args['period']??'last_14_days');
    if($period==='last_14_days'){$start=$today->modify('-13 days');$end=$today->modify('+1 day');}
    elseif($period==='this_week'){$start=$today->modify('monday this week');$end=$start->modify('+7 days');}
    elseif($period==='last_week'){$end=$today->modify('monday this week');$start=$end->modify('-7 days');}
    elseif($period==='custom'){
        $a=(string)($args['start_date']??'');$b=(string)($args['end_date']??'');
        if($a===''||$b==='')return ['missing'=>array_values(array_filter([
            $a===''?'start_date':null,$b===''?'end_date':null
        ]))];
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$a,$tz);
        $last=DateTimeImmutable::createFromFormat('!Y-m-d',$b,$tz);
        if(!$start||!$last||$start->format('Y-m-d')!==$a||$last->format('Y-m-d')!==$b)
            throw new InvalidArgumentException('Use valid YYYY-MM-DD dates.');
        $end=$last->modify('+1 day');
    }else throw new InvalidArgumentException('Unsupported timecard period.');
    $days=(int)$start->diff($end)->format('%r%a');
    if($days<1||$days>31||$start>$today->modify('+1 day')
       ||$start->format('Y')<'2000')
        throw new InvalidArgumentException('Choose a valid period of up to 31 days.');
    return ['start'=>$start,'end'=>$end,
        'startDate'=>$start->format('Y-m-d'),
        'endDate'=>$end->modify('-1 day')->format('Y-m-d'),
        'period'=>$period,'days'=>$days];
}
function fm8d3_time(string $value,DateTimeZone $tz):int
{
    $value=trim($value);
    if($value==='')throw new InvalidArgumentException('Timecard timestamp missing.');
    return (new DateTimeImmutable($value,$tz))->getTimestamp();
}
function fm8d3_hours_calculate(array $rows,array $breakRows,array $range,DateTimeImmutable $now):array
{
    $tz=$now->getTimezone();
    $start=$range['start']->getTimestamp();
    $end=$range['end']->getTimestamp();
    $snapshot=$now->getTimestamp();
    $breaks=[];
    foreach($breakRows as $b){
        if(!is_array($b))continue;
        $id=(int)($b['time_clock_entry_id']??0);
        if($id>0)$breaks[$id][]=$b;
    }
    $completed=0;$open=0;$entries=0;$openEntries=0;$anomalies=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $id=(int)($row['id']??0);
        if($id<1)continue;
        $in=fm8d3_time((string)($row['clocked_in_at']??''),$tz);
        $isOpen=empty($row['clocked_out_at']);
        $out=$isOpen?$snapshot:fm8d3_time((string)$row['clocked_out_at'],$tz);
        $lo=max($in,$start);$hi=min($out,$end,$snapshot);
        if($hi<=$lo){
            if($out<$in)$anomalies[]='Entry #'.$id.' has an invalid clock-out time.';
            continue;
        }
        $breakSeconds=0;$intervals=[];
        foreach($breaks[$id]??[] as $b){
            try{
                $begin=max($lo,fm8d3_time((string)($b['started_at']??''),$tz));
                $finish=min($hi,empty($b['ended_at'])?$snapshot:
                    fm8d3_time((string)$b['ended_at'],$tz));
                if($finish>$begin)$intervals[]=[$begin,$finish];
            }catch(Throwable){$anomalies[]='A break has an invalid timestamp.';}
        }
        usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);
        $cursor=$lo;
        foreach($intervals as [$begin,$finish]){
            if($finish<=$cursor)continue;
            $breakSeconds+=max(0,$finish-max($cursor,$begin));
            $cursor=max($cursor,$finish);
        }
        $seconds=max(0,$hi-$lo-$breakSeconds);
        if($isOpen){$open+=$seconds;$openEntries++;}
        else{$completed+=$seconds;$entries++;}
    }
    return ['completedSeconds'=>$completed,'openSeconds'=>$open,
        'completedEntries'=>$entries,'openEntries'=>$openEntries,
        'anomalies'=>array_values(array_unique($anomalies))];
}
function fm8d3_hours_format(int $seconds):string
{
    $minutes=(int)round(max(0,$seconds)/60);
    return intdiv($minutes,60).'h '.($minutes%60).'m';
}
function fm8d3_hours_answer(string $name,array $range,array $totals):string
{
    $label=$range['startDate'].' through '.$range['endDate'];
    if($totals['completedEntries']===0&&$totals['openEntries']===0)
        return $name.', I checked your Gelato Time Clock for '.$label.
            ' and found no clock entries. I cannot verify worked hours for that period; that is not proof you worked zero hours.';
    $answer=$name.', your Gelato Time Clock shows '.fm8d3_hours_format($totals['completedSeconds']).
        ' of completed clocked time after recorded breaks for '.$label.
        ', across '.$totals['completedEntries'].' completed '.($totals['completedEntries']===1?'entry':'entries').'.';
    if($totals['openEntries']>0)$answer.=' You also have '.fm8d3_hours_format($totals['openSeconds']).
        ' on '.$totals['openEntries'].' open '.($totals['openEntries']===1?'entry':'entries').
        '; that time is still in progress and is not included in the completed total.';
    if(!empty($totals['anomalies']))$answer.=' Some records need review: '.implode(' ',$totals['anomalies']);
    $answer.=' These are time-clock records, not a finalized payroll statement.';
    return $answer;
}
function fm8d3_hours_timezone(PDO $pdo,int $org):DateTimeZone
{
    try{
        $q=$pdo->prepare('SELECT timezone FROM organizations WHERE id=? LIMIT 1');
        $q->execute([$org]);
        return new DateTimeZone(trim((string)$q->fetchColumn())?:'America/Phoenix');
    }catch(Throwable){return new DateTimeZone('America/Phoenix');}
}
function fm8d3_hours_read(PDO $pdo,array $user,array $args):array
{
    if(!app_has_permission('timeclock.self',$user)
       &&!app_has_permission('timeclock.view',$user))
        throw new DomainException('Your account does not have Time Clock access.');
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    $tz=fm8d3_hours_timezone($pdo,$org);
    $now=new DateTimeImmutable('now',$tz);
    $range=fm8d3_hours_period($args,$now);
    if(isset($range['missing']))throw new InvalidArgumentException('Provide: '.implode(', ',$range['missing']));
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name IN ('time_clock_entries','time_clock_breaks')");
    if((int)$q->fetchColumn()!==2)throw new RuntimeException('Time Clock is not installed.');
    $sql="SELECT id,clocked_in_at,clocked_out_at,status FROM time_clock_entries
      WHERE organization_id=? AND user_id=? AND clocked_in_at<?
        AND (clocked_out_at IS NULL OR clocked_out_at>=?)
      ORDER BY clocked_in_at ASC LIMIT 601";
    $q=$pdo->prepare($sql);
    $q->execute([$org,$uid,$range['end']->format('Y-m-d H:i:s'),
        $range['start']->format('Y-m-d H:i:s')]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>600)throw new RuntimeException('Too many timecard entries; ask for a shorter period.');
    $breakRows=[];
    if($rows){
        $ids=array_map(static fn($r)=>(int)$r['id'],$rows);
        $holders=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT time_clock_entry_id,started_at,ended_at
            FROM time_clock_breaks WHERE organization_id=? AND time_clock_entry_id IN ($holders)
            ORDER BY started_at");
        $q->execute(array_merge([$org],$ids));
        $breakRows=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $totals=fm8d3_hours_calculate($rows,$breakRows,$range,$now);
    $name=trim((string)($user['first_name']??''))?:trim((string)($user['display_name']??''));
    $name=$name!==''?$name:'You';
    return ['ok'=>true,'data'=>[
        'answer'=>fm8d3_hours_answer($name,$range,$totals),
        'period'=>[$range['startDate'],$range['endDate']],
        'completedMinutes'=>(int)round($totals['completedSeconds']/60),
        'openMinutes'=>(int)round($totals['openSeconds']/60),
        'completedEntries'=>$totals['completedEntries'],
        'openEntries'=>$totals['openEntries'],
        'source'=>'Gelato Time Clock',
    ]];
}
