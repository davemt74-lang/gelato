<?php
declare(strict_types=1);

function employee_shift_comms_ready(PDO $pdo): bool
{
    try {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('employee_shift_messages','employee_shift_message_reads')");
        return (int)$q->fetchColumn()===2;
    } catch (Throwable) {
        return false;
    }
}

function employee_shift_public_id(string $prefix='handoff'): string
{
    return $prefix.'-'.bin2hex(random_bytes(10));
}

function employee_shift_scope(PDO $pdo,int $org,int $userId): array
{
    $locationIds=[];$positionIds=[];$shiftIds=[];$currentShift=null;
    $m=$pdo->prepare("SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND user_id=? AND status='active' LIMIT 1");
    $m->execute([$org,$userId]);$primary=$m->fetchColumn();if($primary)$locationIds[]=(int)$primary;

    $p=$pdo->prepare("SELECT DISTINCT up.position_id FROM organization_memberships om INNER JOIN user_positions up ON up.membership_id=om.id AND up.status='active' AND up.ended_at IS NULL WHERE om.organization_id=? AND om.user_id=? AND om.status='active'");
    $p->execute([$org,$userId]);foreach($p->fetchAll(PDO::FETCH_COLUMN) as $id)$positionIds[]=(int)$id;

    $s=$pdo->prepare("SELECT s.id,s.public_id,s.location_id,s.position_id,s.title,s.starts_at,s.ends_at
      FROM schedule_shifts s INNER JOIN schedule_weeks w ON w.id=s.schedule_week_id AND w.organization_id=s.organization_id AND w.status IN ('published','locked')
      WHERE s.organization_id=? AND s.user_id=? AND s.archived_at IS NULL AND s.status<>'cancelled'
        AND s.ends_at>=DATE_SUB(NOW(6),INTERVAL 12 HOUR) AND s.starts_at<=DATE_ADD(NOW(6),INTERVAL 14 DAY)
      ORDER BY CASE WHEN s.starts_at<=NOW(6) AND s.ends_at>=NOW(6) THEN 0 WHEN s.starts_at>NOW(6) THEN 1 ELSE 2 END,
               ABS(TIMESTAMPDIFF(SECOND,NOW(6),s.starts_at)) LIMIT 40");
    $s->execute([$org,$userId]);$shifts=$s->fetchAll();
    foreach($shifts as $row){$shiftIds[]=(int)$row['id'];if($row['location_id'])$locationIds[]=(int)$row['location_id'];if($row['position_id'])$positionIds[]=(int)$row['position_id'];if($currentShift===null)$currentShift=$row;}

    $clock=null;try{$clock=tv_open_clock($pdo,$org,$userId);}catch(Throwable){}
    if($clock&&!empty($clock['schedule_shift_id'])){
        foreach($shifts as $row){if((int)$row['id']===(int)$clock['schedule_shift_id']){$currentShift=$row;break;}}
        if(!$currentShift||((int)($currentShift['id']??0)!==(int)$clock['schedule_shift_id'])){
            $q=$pdo->prepare('SELECT id,public_id,location_id,position_id,title,starts_at,ends_at FROM schedule_shifts WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,(int)$clock['schedule_shift_id']]);$row=$q->fetch();if($row)$currentShift=$row;
        }
    }

    return ['locationIds'=>array_values(array_unique(array_filter($locationIds))),'positionIds'=>array_values(array_unique(array_filter($positionIds))),'shiftIds'=>array_values(array_unique(array_filter($shiftIds))),'currentShift'=>$currentShift,'clock'=>$clock];
}

function employee_shift_message_matches(array $row,int $userId,array $scope): bool
{
    if(!empty($row['target_user_id'])&&(int)$row['target_user_id']!==$userId)return false;
    if(!empty($row['schedule_shift_id'])&&!in_array((int)$row['schedule_shift_id'],$scope['shiftIds'],true))return false;
    if(!empty($row['location_id'])&&!in_array((int)$row['location_id'],$scope['locationIds'],true))return false;
    if(!empty($row['position_id'])&&!in_array((int)$row['position_id'],$scope['positionIds'],true))return false;
    return true;
}

function employee_shift_messages_for_user(PDO $pdo,int $org,int $userId,bool $includeResolved=false,bool $includeAll=false): array
{
    if(!employee_shift_comms_ready($pdo))return [];
    $scope=employee_shift_scope($pdo,$org,$userId);
    $where=$includeResolved
      ? "m.organization_id=? AND m.archived_at IS NULL"
      : "m.organization_id=? AND m.archived_at IS NULL AND m.status='open' AND m.effective_from<=NOW(6) AND (m.effective_until IS NULL OR m.effective_until>=NOW(6))";
    $q=$pdo->prepare("SELECT m.public_id,m.message_type,m.location_id,m.position_id,m.schedule_shift_id,m.target_user_id,m.station,m.title,m.body,m.priority,m.status,m.effective_from,m.effective_until,m.resolved_at,m.created_at,
      r.read_at,u.display_name created_by_name,l.name location_name,p.name position_name,s.public_id shift_public_id,s.title shift_title,s.starts_at shift_starts_at
      FROM employee_shift_messages m
      LEFT JOIN employee_shift_message_reads r ON r.message_id=m.id AND r.organization_id=m.organization_id AND r.user_id=?
      LEFT JOIN users u ON u.id=m.created_by LEFT JOIN locations l ON l.id=m.location_id LEFT JOIN positions p ON p.id=m.position_id LEFT JOIN schedule_shifts s ON s.id=m.schedule_shift_id
      WHERE {$where}
      ORDER BY FIELD(m.priority,'urgent','high','normal','low'),m.created_at DESC LIMIT 160");
    $q->execute([$userId,$org]);$rows=$q->fetchAll();
    if($includeAll)return $rows;
    return array_values(array_filter($rows,static fn(array $row):bool=>employee_shift_message_matches($row,$userId,$scope)));
}

function employee_shift_message_for_user(PDO $pdo,int $org,int $userId,string $publicId): ?array
{
    if(!employee_shift_comms_ready($pdo))return null;$scope=employee_shift_scope($pdo,$org,$userId);
    $q=$pdo->prepare("SELECT m.*,r.read_at FROM employee_shift_messages m LEFT JOIN employee_shift_message_reads r ON r.message_id=m.id AND r.organization_id=m.organization_id AND r.user_id=? WHERE m.organization_id=? AND m.public_id=? AND m.archived_at IS NULL LIMIT 1");
    $q->execute([$userId,$org,$publicId]);$row=$q->fetch();if(!$row||!employee_shift_message_matches($row,$userId,$scope))return null;return $row;
}

function employee_shift_validate_target(PDO $pdo,int $org,string $type,int $id): array
{
    $type=$type?:'all';if($type==='all')return ['location_id'=>null,'position_id'=>null,'schedule_shift_id'=>null,'target_user_id'=>null];if($id<=0)throw new InvalidArgumentException('Choose an audience target.');
    if($type==='location'){$q=$pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Location target is invalid.');return ['location_id'=>$id,'position_id'=>null,'schedule_shift_id'=>null,'target_user_id'=>null];}
    if($type==='position'){$q=$pdo->prepare("SELECT id FROM positions WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Position target is invalid.');return ['location_id'=>null,'position_id'=>$id,'schedule_shift_id'=>null,'target_user_id'=>null];}
    if($type==='shift'){$q=$pdo->prepare("SELECT id FROM schedule_shifts WHERE organization_id=? AND id=? AND archived_at IS NULL AND status<>'cancelled'");$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Shift target is invalid.');return ['location_id'=>null,'position_id'=>null,'schedule_shift_id'=>$id,'target_user_id'=>null];}
    if($type==='user'){$q=$pdo->prepare("SELECT om.user_id FROM organization_memberships om INNER JOIN users u ON u.id=om.user_id WHERE om.organization_id=? AND om.user_id=? AND om.status='active' AND u.status='active' AND u.archived_at IS NULL");$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Employee target is invalid.');return ['location_id'=>null,'position_id'=>null,'schedule_shift_id'=>null,'target_user_id'=>$id];}
    throw new InvalidArgumentException('Unsupported shift communication audience.');
}

function employee_shift_message_save(PDO $pdo,int $org,array $input,int $actor,bool $manager=false): array
{
    if(!employee_shift_comms_ready($pdo))throw new RuntimeException('Shift handoff migration is not installed. Run upgrade.php.');
    $title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$body=trim((string)($input['body']??''));if($body==='')throw new InvalidArgumentException('A shift handoff message is required.');if($title==='')$title=$manager?'Shift update':'Shift handoff';
    $type=$manager?(string)($input['messageType']??'handoff'):'handoff';if(!in_array($type,['handoff','announcement','note'],true))$type='handoff';
    $priority=(string)($input['priority']??'normal');if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';if(!$manager&&$priority==='urgent')$priority='high';
    $station=mb_substr(trim((string)($input['station']??'')),0,120,'UTF-8')?:null;$expires=trim((string)($input['expiresAt']??''));
    if($expires!==''){try{$expires=(new DateTimeImmutable($expires))->format('Y-m-d H:i:s');}catch(Throwable){throw new InvalidArgumentException('Handoff expiration time is invalid.');}}
    elseif(!$manager)$expires=(new DateTimeImmutable('+36 hours'))->format('Y-m-d H:i:s');else $expires=null;

    if($manager){$target=employee_shift_validate_target($pdo,$org,(string)($input['targetType']??'all'),(int)($input['targetId']??0));}
    else{
        $scope=employee_shift_scope($pdo,$org,$actor);$shift=$scope['currentShift'];$location=$shift['location_id']??($scope['locationIds'][0]??null);$position=$shift['position_id']??($scope['positionIds'][0]??null);
        if(!$location&&!$position)throw new RuntimeException('Gelato could not determine your restaurant shift scope. Ask a manager to post this handoff.');
        $target=['location_id'=>$location? (int)$location:null,'position_id'=>$position? (int)$position:null,'schedule_shift_id'=>null,'target_user_id'=>null];
    }

    $public=employee_shift_public_id($type==='announcement'?'shift-update':'handoff');
    $pdo->prepare("INSERT INTO employee_shift_messages (organization_id,public_id,message_type,location_id,position_id,schedule_shift_id,target_user_id,station,title,body,priority,status,effective_until,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'open',?,?,?)")
      ->execute([$org,$public,$type,$target['location_id'],$target['position_id'],$target['schedule_shift_id'],$target['target_user_id'],$station,$title,$body,$priority,$expires,$actor,$actor]);
    $q=$pdo->prepare('SELECT * FROM employee_shift_messages WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function employee_shift_message_read(PDO $pdo,int $org,int $userId,string $publicId): void
{
    $row=employee_shift_message_for_user($pdo,$org,$userId,$publicId);if(!$row)throw new InvalidArgumentException('Shift communication not found.');
    $pdo->prepare('INSERT INTO employee_shift_message_reads (organization_id,message_id,user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE read_at=NOW(6)')->execute([$org,(int)$row['id'],$userId]);
}

function employee_shift_message_resolve(PDO $pdo,int $org,string $publicId,int $actor): void
{
    $q=$pdo->prepare("UPDATE employee_shift_messages SET status='resolved',resolved_by=?,resolved_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND status='open' AND archived_at IS NULL");$q->execute([$actor,$actor,$org,$publicId]);if($q->rowCount()!==1)throw new InvalidArgumentException('Open shift communication not found.');
}

function employee_shift_audiences(PDO $pdo,int $org): array
{
    $locations=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name");$locations->execute([$org]);
    $positions=$pdo->prepare("SELECT id,name FROM positions WHERE organization_id=? AND status='active' ORDER BY name");$positions->execute([$org]);
    $shifts=$pdo->prepare("SELECT s.id,s.public_id,s.title,s.starts_at,u.display_name,l.name location_name,p.name position_name FROM schedule_shifts s INNER JOIN schedule_weeks w ON w.id=s.schedule_week_id AND w.status IN ('published','locked') LEFT JOIN users u ON u.id=s.user_id LEFT JOIN locations l ON l.id=s.location_id LEFT JOIN positions p ON p.id=s.position_id WHERE s.organization_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.ends_at>=DATE_SUB(NOW(6),INTERVAL 6 HOUR) AND s.starts_at<=DATE_ADD(NOW(6),INTERVAL 14 DAY) ORDER BY s.starts_at LIMIT 120");$shifts->execute([$org]);
    $users=$pdo->prepare("SELECT u.id,u.display_name,om.job_title,l.name location_name FROM organization_memberships om INNER JOIN users u ON u.id=om.user_id LEFT JOIN locations l ON l.id=om.primary_location_id WHERE om.organization_id=? AND om.status='active' AND u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name");$users->execute([$org]);
    return ['locations'=>$locations->fetchAll(),'positions'=>$positions->fetchAll(),'shifts'=>$shifts->fetchAll(),'users'=>$users->fetchAll()];
}

function employee_shift_arrival_brief(PDO $pdo,int $org,int $userId): array
{
    if(!employee_shift_comms_ready($pdo))return ['active'=>false,'headline'=>'Shift briefing is not installed.','messages'=>[],'announcements'=>[],'tasks'=>[],'summary'=>['unreadHandoffs'=>0,'unreadAnnouncements'=>0,'openTasks'=>0]];
    $scope=employee_shift_scope($pdo,$org,$userId);$clock=$scope['clock'];$shift=$scope['currentShift'];$now=time();$active=false;
    if($clock&&!empty($clock['clocked_in_at']))$active=strtotime((string)$clock['clocked_in_at'])>=$now-5400;
    if(!$active&&$shift&&!empty($shift['starts_at'])){$delta=strtotime((string)$shift['starts_at'])-$now;$active=$delta>=0&&$delta<=7200;}
    $messages=employee_shift_messages_for_user($pdo,$org,$userId);$unreadMessages=array_values(array_filter($messages,static fn(array $r):bool=>empty($r['read_at'])));
    $announcements=function_exists('employee_home_announcements')?employee_home_announcements($pdo,$org,$userId):[];$unreadAnnouncements=array_values(array_filter($announcements,static fn(array $r):bool=>empty($r['read_at'])));
    $tasks=(function_exists('operations_core_ready')&&operations_core_ready($pdo)&&function_exists('employee_home_tasks'))?employee_home_tasks($pdo,$org,$userId):[];
    $headline=$clock?'Welcome in — here is what changed before your shift.':($active?'Your next-shift brief is ready.':'Your restaurant handoff brief.');
    $high=count(array_filter($unreadMessages,static fn(array $r):bool=>in_array((string)$r['priority'],['urgent','high'],true)));
    return ['active'=>$active,'headline'=>$headline,'shift'=>$shift,'messages'=>array_slice($unreadMessages,0,8),'announcements'=>array_slice($unreadAnnouncements,0,5),'tasks'=>array_slice($tasks,0,8),'summary'=>['unreadHandoffs'=>count($unreadMessages),'unreadAnnouncements'=>count($unreadAnnouncements),'openTasks'=>count($tasks),'highPriority'=>$high]];
}
