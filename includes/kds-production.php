<?php
declare(strict_types=1);

require_once __DIR__.'/kds-core.php';

function kds_production_recall_window_seconds(): int { return 300; }

function kds_production_table_exists(PDO $pdo,string $table): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (int)$q->fetchColumn()===1;
}

function kds_production_column_exists(PDO $pdo,string $table,string $column): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table,$column]);
    return (int)$q->fetchColumn()===1;
}

function kds_production_service_context_ready(PDO $pdo): bool
{
    return kds_production_table_exists($pdo,'service_check_contexts')
        && kds_production_column_exists($pdo,'pos_check_items','seat_number')
        && kds_production_column_exists($pdo,'pos_check_items','course_key')
        && kds_production_column_exists($pdo,'pos_check_items','course_sequence');
}

function kds_production_scope_sql(?string $stationPublicId): array
{
    $where='';
    $args=[];
    if($stationPublicId==='unrouted')$where=' AND k.station_id IS NULL';
    elseif($stationPublicId!==null&&trim($stationPublicId)!==''){
        $where=' AND s.public_id=?';
        $args[]=trim($stationPublicId);
    }
    return [$where,$args];
}

function kds_production_station_counts(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare("SELECT s.public_id,COUNT(*) item_count
        FROM kds_order_items k
        LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id
        WHERE k.organization_id=? AND k.location_id=? AND k.status NOT IN ('completed','cancelled')
        GROUP BY s.public_id");
    $q->execute([$org,$locationId]);
    $counts=['all'=>0,'unrouted'=>0];
    foreach($q->fetchAll() as $row){
        $count=(int)$row['item_count'];
        $counts['all']+=$count;
        if($row['public_id']===null)$counts['unrouted']+=$count;
        else $counts[(string)$row['public_id']]=$count;
    }
    return $counts;
}

function kds_production_board(PDO $pdo,int $org,int $locationId,?string $stationPublicId=null,bool $includeCompleted=false): array
{
    kds_location($pdo,$org,$locationId);
    $service=kds_production_service_context_ready($pdo);
    [$scopeWhere,$scopeArgs]=kds_production_scope_sql($stationPublicId);
    $expoScope=$stationPublicId===null||trim($stationPublicId)==='';

    $historyWhere=$includeCompleted
        ? " AND (k.status NOT IN ('completed','cancelled') OR COALESCE(k.completed_at,k.cancelled_at,k.updated_at)>=DATE_SUB(NOW(6),INTERVAL 2 HOUR))"
        : " AND k.status NOT IN ('completed','cancelled')";

    $itemExtra=$service
        ? ',i.seat_number,i.course_key,i.course_sequence'
        : ',NULL seat_number,NULL course_key,NULL course_sequence';
    $serviceSelect=$service
        ? ',cx.current_course_key,COALESCE(su.display_name,ou.display_name) server_name'
        : ',NULL current_course_key,ou.display_name server_name';
    $serviceJoin=$service
        ? ' LEFT JOIN service_check_contexts cx ON cx.organization_id=k.organization_id AND cx.check_id=k.check_id LEFT JOIN users su ON su.id=cx.server_user_id'
        : '';

    $sql="SELECT k.public_id,k.status,k.sent_at,k.fired_at,k.started_at,k.ready_at,k.completed_at,k.cancelled_at,k.station_id,
        TIMESTAMPDIFF(SECOND,COALESCE(k.fired_at,k.sent_at),NOW(6)) active_age_seconds,
        TIMESTAMPDIFF(SECOND,k.completed_at,NOW(6)) completed_age_seconds,
        s.public_id station_public_id,s.name station_name,s.target_seconds,
        c.public_id check_public_id,c.check_number,c.business_date,c.service_mode,c.table_name,c.guest_count,c.status check_status,
        ou.display_name opened_by_name,
        i.id pos_check_item_id,i.menu_item_id,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions,i.status pos_item_status"
        .$itemExtra.$serviceSelect."
        FROM kds_order_items k
        JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id
        JOIN users ou ON ou.id=c.opened_by
        JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id
        LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id"
        .$serviceJoin."
        WHERE k.organization_id=? AND k.location_id=?"
        .$scopeWhere.$historyWhere."
        ORDER BY CASE k.status WHEN 'ready' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'queued' THEN 2 WHEN 'held' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END,
        COALESCE(k.fired_at,k.sent_at),k.id";
    $q=$pdo->prepare($sql);
    $q->execute(array_merge([$org,$locationId],$scopeArgs));
    $rows=$q->fetchAll();

    foreach($rows as &$r){
        $status=(string)$r['status'];
        $age=max(0,(int)($r['active_age_seconds']??0));
        $target=(int)($r['target_seconds']??0);
        $active=in_array($status,['queued','in_progress','ready'],true);
        $ratio=$active&&$target>0?$age/$target:0.0;
        $completedAge=$r['completed_age_seconds']!==null?(int)$r['completed_age_seconds']:null;
        $r['ageSeconds']=$age;
        $r['slaRatio']=round($ratio,3);
        $r['late']=$active&&$target>0&&$ratio>1;
        $r['warning']=$active&&$target>0&&$ratio>=0.8&&$ratio<=1;
        $r['recallable']=$status==='completed'&&$completedAge!==null&&$completedAge>=0&&$completedAge<=kds_production_recall_window_seconds();
    }
    unset($r);

    $tickets=[];
    foreach($rows as $row){
        $key=(string)$row['check_public_id'];
        if(!isset($tickets[$key])){
            $tickets[$key]=[
                'checkPublicId'=>$key,
                'checkNumber'=>(string)$row['check_number'],
                'businessDate'=>(string)$row['business_date'],
                'serviceMode'=>(string)$row['service_mode'],
                'tableName'=>$row['table_name'],
                'guestCount'=>(int)$row['guest_count'],
                'checkStatus'=>(string)$row['check_status'],
                'serverName'=>$row['server_name']??$row['opened_by_name'],
                'currentCourseKey'=>$row['current_course_key']??null,
                'items'=>[],
                'queued'=>0,'inProgress'=>0,'ready'=>0,'held'=>0,'completed'=>0,'cancelled'=>0,
                'unrouted'=>0,'late'=>false,'warning'=>false,'oldestAgeSeconds'=>0,'activeFiredCount'=>0,'recallableCount'=>0,
            ];
        }
        $ticket=&$tickets[$key];
        $ticket['items'][]=$row;
        $status=(string)$row['status'];
        if($status==='queued'){$ticket['queued']++;$ticket['activeFiredCount']++;}
        elseif($status==='in_progress'){$ticket['inProgress']++;$ticket['activeFiredCount']++;}
        elseif($status==='ready'){$ticket['ready']++;$ticket['activeFiredCount']++;}
        elseif($status==='held')$ticket['held']++;
        elseif($status==='completed')$ticket['completed']++;
        elseif($status==='cancelled')$ticket['cancelled']++;
        if($row['station_id']===null&&!in_array($status,['completed','cancelled'],true))$ticket['unrouted']++;
        if(!empty($row['late']))$ticket['late']=true;
        if(!empty($row['warning']))$ticket['warning']=true;
        if(in_array($status,['queued','in_progress','ready'],true))$ticket['oldestAgeSeconds']=max($ticket['oldestAgeSeconds'],(int)$row['ageSeconds']);
        if(!empty($row['recallable']))$ticket['recallableCount']++;
        unset($ticket);
    }

    foreach($tickets as &$ticket){
        $ticket['readyToBump']=$expoScope
            &&$ticket['activeFiredCount']>0
            &&$ticket['ready']===$ticket['activeFiredCount']
            &&$ticket['unrouted']===0;
        if($ticket['late'])$ticket['warning']=false;
    }
    unset($ticket);

    $tickets=array_values($tickets);
    usort($tickets,static function(array $a,array $b):int{
        $aRank=$a['readyToBump']?0:($a['late']?1:($a['warning']?2:3));
        $bRank=$b['readyToBump']?0:($b['late']?1:($b['warning']?2:3));
        return $aRank<=>$bRank
            ?: $b['oldestAgeSeconds']<=>$a['oldestAgeSeconds']
            ?: strcmp($a['checkNumber'],$b['checkNumber']);
    });

    $allDay=[];
    foreach($rows as $row){
        if(!in_array((string)$row['status'],['queued','in_progress','ready'],true))continue;
        $name=(string)$row['item_name_snapshot'];
        if(!isset($allDay[$name]))$allDay[$name]=['name'=>$name,'quantity'=>0.0,'queued'=>0.0,'inProgress'=>0.0,'ready'=>0.0];
        $qty=(float)$row['quantity'];
        $allDay[$name]['quantity']+=$qty;
        if($row['status']==='queued')$allDay[$name]['queued']+=$qty;
        elseif($row['status']==='in_progress')$allDay[$name]['inProgress']+=$qty;
        elseif($row['status']==='ready')$allDay[$name]['ready']+=$qty;
    }
    $allDay=array_values($allDay);
    usort($allDay,static fn(array $a,array $b):int=>$b['quantity']<=>$a['quantity'] ?: strcmp($a['name'],$b['name']));

    $metrics=['tickets'=>count($tickets),'queued'=>0,'inProgress'=>0,'ready'=>0,'held'=>0,'late'=>0,'warning'=>0,'unrouted'=>0,'readyTickets'=>0];
    foreach($rows as $row){
        if($row['status']==='queued')$metrics['queued']++;
        elseif($row['status']==='in_progress')$metrics['inProgress']++;
        elseif($row['status']==='ready')$metrics['ready']++;
        elseif($row['status']==='held')$metrics['held']++;
        if(!empty($row['late']))$metrics['late']++;
        if(!empty($row['warning']))$metrics['warning']++;
        if($row['station_id']===null&&!in_array((string)$row['status'],['completed','cancelled'],true))$metrics['unrouted']++;
    }
    foreach($tickets as $ticket)if($ticket['readyToBump'])$metrics['readyTickets']++;

    return [
        'location'=>kds_location($pdo,$org,$locationId),
        'stations'=>kds_stations($pdo,$org,$locationId),
        'items'=>$rows,
        'tickets'=>$tickets,
        'allDay'=>$allDay,
        'metrics'=>$metrics,
        'stationCounts'=>kds_production_station_counts($pdo,$org,$locationId),
        'unroutedCount'=>$metrics['unrouted'],
        'recallWindowSeconds'=>kds_production_recall_window_seconds(),
        'scope'=>$stationPublicId??'',
        'includeCompleted'=>$includeCompleted,
    ];
}

function kds_production_ticket_rows(PDO $pdo,int $org,int $locationId,string $checkPublicId,?string $stationPublicId,bool $forUpdate=false): array
{
    kds_location($pdo,$org,$locationId);
    $q=$pdo->prepare('SELECT id FROM pos_checks WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$locationId,$checkPublicId]);
    $checkId=(int)$q->fetchColumn();
    if(!$checkId)throw new InvalidArgumentException('Kitchen ticket was not found at this location.');

    [$scopeWhere,$scopeArgs]=kds_production_scope_sql($stationPublicId);
    $sql="SELECT k.*,s.public_id station_public_id,s.name station_name
        FROM kds_order_items k
        LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id
        WHERE k.organization_id=? AND k.location_id=? AND k.check_id=?"
        .$scopeWhere.' ORDER BY k.id'.($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);
    $q->execute(array_merge([$org,$locationId,$checkId],$scopeArgs));
    return $q->fetchAll();
}

function kds_production_transition_locked(PDO $pdo,int $org,array $row,string $to,int $userId,string $eventType,string $note): void
{
    $from=(string)$row['status'];
    $valid=[
        'held'=>['queued','cancelled'],
        'queued'=>['held','in_progress','cancelled'],
        'in_progress'=>['ready','cancelled'],
        'ready'=>['in_progress','completed','cancelled'],
        'completed'=>[],
        'cancelled'=>[],
    ];
    if(!in_array($to,$valid[$from]??[],true))throw new InvalidArgumentException('Kitchen item cannot move from '.$from.' to '.$to.'.');
    if($row['station_id']===null&&$to!=='cancelled')throw new InvalidArgumentException('Route this item to an active kitchen station before changing its kitchen status.');

    $sets=['status=?','last_action_by=?','revision=revision+1','updated_at=NOW(6)'];
    $args=[$to,$userId];
    if($to==='queued'&&empty($row['fired_at']))$sets[]='fired_at=NOW(6)';
    if($to==='in_progress'&&empty($row['started_at']))$sets[]='started_at=NOW(6)';
    if($to==='ready'&&empty($row['ready_at']))$sets[]='ready_at=NOW(6)';
    if($to==='completed'&&empty($row['completed_at']))$sets[]='completed_at=NOW(6)';
    if($to==='cancelled'&&empty($row['cancelled_at']))$sets[]='cancelled_at=NOW(6)';
    if($to==='held')$sets[]='fired_at=NULL';
    $args[]=$org;$args[]=(int)$row['id'];
    $pdo->prepare('UPDATE kds_order_items SET '.implode(',',$sets).' WHERE organization_id=? AND id=?')->execute($args);
    kds_event($pdo,$org,(int)$row['id'],$eventType,$from,$to,$note,$userId);
}

function kds_production_recall_item(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return kds_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $q=$pdo->prepare("SELECT * FROM kds_order_items WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);
        $row=$q->fetch();
        if(!$row)throw new InvalidArgumentException('Kitchen item was not found.');
        if((string)$row['status']!=='completed')throw new InvalidArgumentException('Only a completed kitchen item can be recalled.');

        $q=$pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,completed_at,NOW(6)) FROM kds_order_items WHERE organization_id=? AND id=?');
        $q->execute([$org,(int)$row['id']]);
        $age=$q->fetchColumn();
        if($age===false||$age===null||(int)$age<0||(int)$age>kds_production_recall_window_seconds())
            throw new InvalidArgumentException('This completed item is outside the kitchen recall window.');

        $pdo->prepare("UPDATE kds_order_items SET status='ready',completed_at=NULL,last_action_by=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);
        kds_event($pdo,$org,(int)$row['id'],'recalled','completed','ready','Kitchen item recalled to Expo.',$userId);
        return kds_item($pdo,$org,$publicId,false);
    });
}

function kds_production_ticket_action(PDO $pdo,int $org,int $locationId,string $checkPublicId,string $action,?string $stationPublicId,int $userId): array
{
    if(!in_array($action,['fire','hold','start','ready','bump','recall'],true))
        throw new InvalidArgumentException('Kitchen ticket action is invalid.');
    if($action==='bump'&&$stationPublicId!==null&&trim($stationPublicId)!=='')
        throw new InvalidArgumentException('Only Expo / All can bump a completed kitchen ticket. Station views hand items to Expo by marking them Ready.');

    return kds_transaction($pdo,function()use($pdo,$org,$locationId,$checkPublicId,$action,$stationPublicId,$userId):array{
        $rows=kds_production_ticket_rows($pdo,$org,$locationId,$checkPublicId,$stationPublicId,true);
        if(!$rows)throw new InvalidArgumentException('This kitchen ticket has no items in the selected station view.');

        if($action==='recall'){
            $completed=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='completed'));
            $recalled=[];
            foreach($completed as $row){
                try{$recalled[]=kds_production_recall_item($pdo,$org,(string)$row['public_id'],$userId);}
                catch(InvalidArgumentException){}
            }
            if(!$recalled)throw new InvalidArgumentException('Completed items are outside the kitchen recall window.');
            return ['action'=>$action,'checkPublicId'=>$checkPublicId,'affected'=>count($recalled),'items'=>$recalled];
        }

        if($action==='fire'){
            $targets=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='held'));
            $to='queued';$event='ticket_fired';$note='Ticket batch fired.';
        } elseif($action==='hold'){
            $targets=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='queued'));
            $to='held';$event='ticket_held';$note='Ticket batch held.';
        } elseif($action==='start'){
            $targets=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='queued'));
            $to='in_progress';$event='ticket_started';$note='Ticket batch started.';
        } elseif($action==='ready'){
            $targets=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='in_progress'));
            $to='ready';$event='ticket_ready';$note='Ticket batch marked ready.';
        } else {
            $fired=array_values(array_filter($rows,static fn(array $r):bool=>in_array((string)$r['status'],['queued','in_progress','ready'],true)));
            if(!$fired)throw new InvalidArgumentException('This ticket has no fired items to bump.');
            foreach($fired as $row)if((string)$row['status']!=='ready')
                throw new InvalidArgumentException('All fired items in this ticket must be Ready before Expo can bump it.');
            $targets=$fired;$to='completed';$event='ticket_bumped';$note='Expo bumped ready ticket batch.';
        }

        if(!$targets)throw new InvalidArgumentException('No kitchen items are eligible for this ticket action.');
        foreach($targets as $row)kds_production_transition_locked($pdo,$org,$row,$to,$userId,$event,$note);
        return ['action'=>$action,'checkPublicId'=>$checkPublicId,'affected'=>count($targets)];
    });
}
