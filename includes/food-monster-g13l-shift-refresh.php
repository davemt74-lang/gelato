<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13i-training-impact.php';

/**
 * G13L shift context is observational only; clock data is NOT authorization.
 * No scheduled tasks, KDS flags, staff grades or automatic training resets.
 */
function fm13l_intent(string $text):?string
{
    $q=mb_strtolower(trim(preg_replace('/\s+/u',' ',$text)??$text),'UTF-8');
    $q=rtrim($q," .!?");
    return match($q){
        'my shift training updates','my shift training refresh'=>'self',
        'team shift training updates','team shift training refreshes',
        'shift training refresh overview'=>'team',
        default=>null
    };
}
function fm13l_allowed(array $user,string $intent,string $channel):bool
{
    if($channel!=='text')return false;
    if($intent==='self')return fm13i_allowed($user,'self','text',
        fm9a_is_super_admin_session($user)) &&
        (app_has_permission('timeclock.self',$user)
         ||app_has_permission('timeclock.view',$user)
         ||fm9a_is_super_admin_session($user));
    return $intent==='team'
        &&fm13i_allowed($user,'team','text',fm9a_is_super_admin_session($user))
        &&(app_has_permission('timeclock.view',$user)
            ||app_has_permission('timeclock.manage',$user)
            ||app_has_permission('attendance.view',$user));
}
/**
 * Reads an exact tenant/person clock entry, then checks that entry's
 * active break. Unknown means unavailable clock schema, never "off shift".
 */
function fm13l_shift_context(PDO $pdo,int $org,int $personId):array
{
    if($org<1||$personId<1)return ['state'=>'unavailable','shiftId'=>null];
    try{
        $q=$pdo->prepare("SELECT id,public_id,schedule_shift_id FROM time_clock_entries
            WHERE organization_id=? AND user_id=?
              AND status='open' AND clocked_out_at IS NULL
            ORDER BY clocked_in_at DESC,id DESC LIMIT 1");
        $q->execute([$org,$personId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['state'=>'off_shift','shiftId'=>null];
        $b=$pdo->prepare("SELECT id FROM time_clock_breaks
            WHERE organization_id=? AND time_clock_entry_id=?
              AND ended_at IS NULL ORDER BY started_at DESC,id DESC LIMIT 1");
        $b->execute([$org,(int)$row['id']]);
        return ['state'=>$b->fetchColumn()?'on_break':'on_shift',
            'shiftId'=>isset($row['schedule_shift_id'])&&$row['schedule_shift_id']!==null
                ?(int)$row['schedule_shift_id']:null];
    }catch(PDOException $error){
        // Uninstalled time clock / schema permission errors should NOT
        // silently be described as an employee being off shift.
        error_log('FM-G13L shift state unavailable: '.get_class($error));
        return ['state'=>'unavailable','shiftId'=>null];
    }
}
function fm13l_notice_window(array $shift):bool
{
    // Already-nonintrusive G13K notices remain backwards-compatible on
    // installs without a Time Clock schema. On a verified open shift,
    // defer until a recorded break or off-shift conversation.
    return ($shift['state']??'unavailable')!=='on_shift';
}
/**
 * A manager sees only counts, source drift and approved active clock state.
 * No roster beyond already-authorized G13I participant scope.
 */
function fm13l_shift_summary(array $people,bool $partial):array
{
    $counts=['on_shift'=>0,'on_break'=>0,'off_shift'=>0,'unavailable'=>0];
    $outstanding=0;$deferred=0;$ready=0;
    foreach($people as $person){
        $shift=(string)($person['shiftState']??'unavailable');
        if(!isset($counts[$shift]))$shift='unavailable';
        $counts[$shift]++;
        if(in_array(($person['impact']??'current'),['needs_review','source_missing'],true)){
            $outstanding++;
            if($shift==='on_shift')$deferred++;
            else $ready++;
        }
    }
    return ['outstanding'=>$outstanding,'deferredUntilBreak'=>$deferred,
        'reviewOpportunity'=>$ready,'byShiftState'=>$counts,'partial'=>$partial,
        'readOnly'=>true,
        'note'=>'Outstanding procedure refreshes are not overdue tasks, skill scores or competency certifications. On-shift notices are deferred until a recorded break or later conversation.'];
}
