<?php
declare(strict_types=1);
/** FM-G8A: authenticated schedule/clock-in context, never webcam identity. */
function fmpt_access(array $user): array
{
    return [
        'schedule'=>app_has_permission('schedule.view',$user)||app_has_permission('schedule.manage',$user)||app_has_permission('schedule.self',$user),
        'scheduleTeam'=>app_has_permission('schedule.view',$user)||app_has_permission('schedule.manage',$user),
        'voiceManage'=>app_has_permission('voice.manage',$user),
        'clock'=>app_has_permission('timeclock.agent',$user)&&(app_has_permission('timeclock.view',$user)||app_has_permission('attendance.view',$user)),
    ];
}
function fmpt_classify(bool $scheduled,bool $clocked,bool $isSessionUser): string
{
    if($scheduled&&$clocked)return 'Scheduled · clocked in';
    if(!$scheduled&&$clocked)return 'Clocked in · not scheduled today';
    if($scheduled)return 'Scheduled today · clock-in not confirmed';
    return $isSessionUser?'Signed in · schedule not confirmed':'Staff · not scheduled today';
}
function fmpt_roster(PDO $pdo,array $user): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$access=fmpt_access($user);
    $session=['userId'=>$uid,'displayName'=>(string)($user['display_name']??$user['first_name']??'Signed-in user'),
      'jobTitle'=>(string)($user['job_title']??''),'signedInHere'=>true];
    $result=['session'=>$session,'staff'=>[],'access'=>$access,'generatedAt'=>date(DATE_ATOM),
      'scopeNote'=>'Only this authenticated browser session is verified as signed in. A clock-in is not a website login. Camera tracking is not identity verification.'];
    $scheduleAvailable=false;$clockAvailable=false;
    if($access['schedule']){
        try{$scheduleAvailable=(bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schedule_shifts' LIMIT 1")->fetchColumn();}catch(Throwable){}
    }
    if($access['clock']){
        try{$clockAvailable=(bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='time_clock_entries' LIMIT 1")->fetchColumn();}catch(Throwable){}
    }
    $result['access']['scheduleReady']=$scheduleAvailable;$result['access']['clockReady']=$clockAvailable;
    $people=[$uid=>['userId'=>$uid,'displayName'=>$session['displayName'],'jobTitle'=>$session['jobTitle'],'signedInHere'=>true,'scheduledToday'=>null,'onShiftNow'=>null,'clockedIn'=>null]];
    if($scheduleAvailable){
        $sql="SELECT s.user_id,u.display_name,om.job_title,
               MAX(CASE WHEN s.starts_at<=NOW(6) AND s.ends_at>NOW(6) THEN 1 ELSE 0 END) on_now
             FROM schedule_shifts s JOIN users u ON u.id=s.user_id
             JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=s.organization_id AND om.status='active'
             WHERE s.organization_id=? AND s.user_id IS NOT NULL AND s.archived_at IS NULL AND s.status<>'cancelled'
               AND s.starts_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND s.ends_at>CURDATE()";
        $args=[$org];if(!$access['scheduleTeam']){$sql.=' AND s.user_id=?';$args[]=$uid;}
        $sql.=' GROUP BY s.user_id,u.display_name,om.job_title LIMIT 60';
        $q=$pdo->prepare($sql);$q->execute($args);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(int)$row['user_id'];
            if(!isset($people[$id]))$people[$id]=['userId'=>$id,'displayName'=>(string)$row['display_name'],'jobTitle'=>(string)($row['job_title']??''),'signedInHere'=>false,'scheduledToday'=>null,'onShiftNow'=>null,'clockedIn'=>null];
            $people[$id]['scheduledToday']=true;$people[$id]['onShiftNow']=(bool)$row['on_now'];
        }
        foreach($people as &$person){if($person['scheduledToday']===null)$person['scheduledToday']=false;if($person['onShiftNow']===null)$person['onShiftNow']=false;}unset($person);
    }
    if($clockAvailable){
        $sql="SELECT t.user_id,u.display_name,om.job_title FROM time_clock_entries t JOIN users u ON u.id=t.user_id
            JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=t.organization_id AND om.status='active'
            WHERE t.organization_id=? AND t.status='open' AND t.clocked_out_at IS NULL";
        $args=[$org];if(!$access['scheduleTeam']){$sql.=' AND t.user_id=?';$args[]=$uid;}
        $sql.=' GROUP BY t.user_id,u.display_name,om.job_title LIMIT 60';
        $q=$pdo->prepare($sql);$q->execute($args);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(int)$row['user_id'];
            if(!isset($people[$id]))$people[$id]=['userId'=>$id,'displayName'=>(string)$row['display_name'],'jobTitle'=>(string)($row['job_title']??''),'signedInHere'=>false,'scheduledToday'=>null,'onShiftNow'=>null,'clockedIn'=>null];
            $people[$id]['clockedIn']=true;
        }
        foreach($people as &$person){if($person['clockedIn']===null)$person['clockedIn']=false;}unset($person);
    }
    foreach($people as &$person){
        $person['status']=($person['scheduledToday']===null||$person['clockedIn']===null)
          ?'Schedule or clock-in status unavailable'
          :fmpt_classify($person['scheduledToday'],$person['clockedIn'],$person['signedInHere']);
    }unset($person);
    $result['staff']=array_values($people);
    usort($result['staff'],static fn($a,$b)=>(int)$b['signedInHere']<=>(int)$a['signedInHere'] ?: (int)($b['clockedIn']??false)<=>(int)($a['clockedIn']??false));
    return $result;
}
