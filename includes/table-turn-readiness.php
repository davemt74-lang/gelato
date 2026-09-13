<?php
declare(strict_types=1);

require_once __DIR__.'/service-reservation-protection.php';
require_once __DIR__.'/table-turn-policy.php';

function table_turn_signed_minutes(DateTimeImmutable $from,DateTimeImmutable $to): int
{
    return (int)floor(($to->getTimestamp()-$from->getTimestamp())/60);
}

function table_turn_remaining_reset_minutes(array $table,array $policy): int
{
    $target=max(1,(int)$policy['resetTargetMinutes']);
    if((string)$table['state']!=='cleaning')return $target;
    $elapsed=max(0,(int)floor(((int)($table['cleaningElapsedSeconds']??0))/60));
    return max(0,$target-$elapsed);
}

function table_turn_readiness_queue(PDO $pdo,int $org,int $locationId,array $dashboard): array
{
    $policy=table_turn_policy($pdo,$org,$locationId);
    $now=pos_clock($pdo,$org,$locationId);
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $queue=[];
    foreach((array)($dashboard['tables']??[]) as $table){
        if(!in_array((string)$table['state'],['dirty','cleaning'],true))continue;
        if(empty($table['physicalReady'])||empty($table['managedAsset']))continue;
        $next=service_reservation_protection_next($pdo,$org,(int)$table['id'],$now);
        $readyBy=null;$minutesToReadyBy=null;$urgency='backlog';$reservationAt=null;
        if($next){
            $reservationAt=new DateTimeImmutable((string)$next['scheduledAt'],$tz);
            $readyBy=$reservationAt->modify('-'.(int)$policy['readyBufferMinutes'].' minutes');
            $minutesToReadyBy=table_turn_signed_minutes($now,$readyBy);
            if($readyBy<$now)$urgency='overdue';
            elseif($minutesToReadyBy<=(int)$policy['urgentThresholdMinutes'])$urgency='urgent';
            else $urgency='scheduled';
        }
        $remaining=table_turn_remaining_reset_minutes($table,$policy);
        $estimatedReady=$now->modify('+'.$remaining.' minutes');
        $queue[]=[
            'tableId'=>(int)$table['id'],
            'tablePublicId'=>(string)$table['publicId'],
            'tableName'=>(string)$table['name'],
            'sectionName'=>$table['sectionName']!==null?(string)$table['sectionName']:null,
            'capacity'=>(int)$table['capacity'],
            'state'=>(string)$table['state'],
            'dirtyAt'=>$table['dirtyAt']??null,
            'cleaningStartedAt'=>$table['cleaningStartedAt']??null,
            'dirtyElapsedSeconds'=>$table['dirtyElapsedSeconds']??null,
            'cleaningElapsedSeconds'=>$table['cleaningElapsedSeconds']??null,
            'resetTargetMinutes'=>(int)$policy['resetTargetMinutes'],
            'resetRemainingMinutes'=>$remaining,
            'estimatedReadyAt'=>$estimatedReady->format('Y-m-d H:i:s'),
            'urgency'=>$urgency,
            'readyBy'=>$readyBy?->format('Y-m-d H:i:s'),
            'minutesToReadyBy'=>$minutesToReadyBy,
            'atRisk'=>$readyBy!==null&&$estimatedReady>$readyBy,
            'nextReservation'=>$next?[
                'publicId'=>(string)$next['publicId'],
                'guestName'=>(string)$next['guestName'],
                'partySize'=>(int)$next['partySize'],
                'scheduledAt'=>(string)$next['scheduledAt'],
                'status'=>(string)$next['status'],
            ]:null,
            'recommendedAction'=>(string)$table['state']==='dirty'?'start_cleaning':'mark_ready',
        ];
    }
    $rank=['overdue'=>0,'urgent'=>1,'scheduled'=>2,'backlog'=>3];
    usort($queue,static function(array $a,array $b)use($rank):int{
        $r=($rank[$a['urgency']]??9)<=>($rank[$b['urgency']]??9);if($r!==0)return $r;
        $aReady=$a['readyBy']??'9999-12-31 23:59:59';$bReady=$b['readyBy']??'9999-12-31 23:59:59';$r=strcmp($aReady,$bReady);if($r!==0)return $r;
        $aDirty=$a['dirtyAt']??'9999-12-31 23:59:59';$bDirty=$b['dirtyAt']??'9999-12-31 23:59:59';$r=strcmp($aDirty,$bDirty);if($r!==0)return $r;
        return $a['tableId']<=>$b['tableId'];
    });
    foreach($queue as $i=>&$row)$row['queuePosition']=$i+1;unset($row);
    return $queue;
}

function table_turn_reset_metrics(PDO $pdo,int $org,int $locationId,string $date): array
{
    $policy=table_turn_policy($pdo,$org,$locationId);
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
    if(!$start||$start->format('Y-m-d')!==$date)$start=new DateTimeImmutable('today',$tz);
    $end=$start->modify('+1 day');
    $q=$pdo->prepare("SELECT metadata_json FROM service_events WHERE organization_id=? AND location_id=? AND event_type='table_ready' AND created_at>=? AND created_at<? ORDER BY created_at,id");
    $q->execute([$org,$locationId,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
    $reset=[];$clean=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json){
        $m=json_decode((string)$json,true);if(!is_array($m))continue;
        if(isset($m['dirtyToReadySeconds'])&&is_numeric($m['dirtyToReadySeconds']))$reset[]=max(0,(int)$m['dirtyToReadySeconds']);
        if(isset($m['cleaningSeconds'])&&is_numeric($m['cleaningSeconds']))$clean[]=max(0,(int)$m['cleaningSeconds']);
    }
    sort($reset);sort($clean);$count=count($reset);$target=max(1,(int)$policy['resetTargetMinutes'])*60;$within=count(array_filter($reset,static fn(int $v):bool=>$v<=$target));
    $median=null;if($count){$mid=intdiv($count,2);$median=$count%2?$reset[$mid]:(int)round(($reset[$mid-1]+$reset[$mid])/2);}
    return [
        'date'=>$start->format('Y-m-d'),
        'resetCount'=>$count,
        'averageResetSeconds'=>$count?(int)round(array_sum($reset)/$count):null,
        'medianResetSeconds'=>$median,
        'averageCleaningSeconds'=>$clean?(int)round(array_sum($clean)/count($clean)):null,
        'withinTargetCount'=>$within,
        'withinTargetPercent'=>$count?round($within/$count*100,1):null,
        'targetMinutes'=>(int)$policy['resetTargetMinutes'],
    ];
}

function table_turn_readiness_dashboard(PDO $pdo,int $org,int $locationId,string $date,int $userId): array
{
    $dashboard=service_reservation_protection_dashboard($pdo,$org,$locationId,$date,$userId);
    $policy=table_turn_policy($pdo,$org,$locationId);
    $queue=table_turn_readiness_queue($pdo,$org,$locationId,$dashboard);
    $dashboard['turnPolicy']=$policy;
    $dashboard['readinessQueue']=$queue;
    $dashboard['resetMetrics']=table_turn_reset_metrics($pdo,$org,$locationId,$date);
    $dashboard['urgentResetCount']=count(array_filter($queue,static fn(array $r):bool=>in_array($r['urgency'],['overdue','urgent'],true)));
    return $dashboard;
}
