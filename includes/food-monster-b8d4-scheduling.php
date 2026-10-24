<?php
declare(strict_types=1);

/**
 * FM-B8D4 — natural-language Scheduling skills backed by Gelato's existing
 * Scheduling Agent permission, availability, proposal and confirmation APIs.
 * Model arguments are untrusted: names are resolved against active, in-tenant
 * staff; dates and time ranges are validated; shift writes require an explicit
 * owner/manager confirmation stored in the server-side session.
 */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/employee-shift-communications.php';
require_once __DIR__.'/scheduling-agent-core.php';

function fm8d4_can(array $actor,string $permission):bool
{
    return app_has_permission($permission,$actor);
}
function fm8d4_schedule_day(string $raw):string
{
    if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$raw))throw new InvalidArgumentException('Please provide a date in YYYY-MM-DD format.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
    if(!$d||$d->format('Y-m-d')!==$raw)throw new InvalidArgumentException('The selected date is invalid.');
    return $raw;
}
function fm8d4_schedule_clock(string $raw):string
{
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$raw))
        throw new InvalidArgumentException('Please provide a 24-hour time in HH:MM format.');
    return $raw;
}
function fm8d4_schedule_range(array $args):array
{
    $day=fm8d4_schedule_day((string)($args['date']??''));
    $start=fm8d4_schedule_clock((string)($args['start_time']??''));
    $end=fm8d4_schedule_clock((string)($args['end_time']??''));
    $from=$day.' '.$start.':00';$until=$day.' '.$end.':00';
    $seconds=strtotime($until)-strtotime($from);
    if($seconds<=0||$seconds>16*3600)
        throw new InvalidArgumentException('The shift must end after it starts, on the same day, and be no longer than 16 hours.');
    return [$from,$until];
}
function fm8d4_schedule_week(array $args):string
{
    $day=trim((string)($args['week_date']??''));
    if($day==='')$day=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d');
    return scheduling_week_start(fm8d4_schedule_day($day));
}
function fm8d4_staff_resolve(PDO $pdo,int $org,string $raw):array
{
    $name=mb_strtolower(trim(preg_replace('/\s+/u',' ',$raw)??$raw),'UTF-8');
    if($name===''||mb_strlen($name,'UTF-8')>100)
        return ['error'=>'Please tell me which employee to check.'];
    $exact=[];$partial=[];
    foreach(scheduling_staff($pdo,$org) as $person){
        $names=array_filter([(string)($person['preferred_name']??''),(string)($person['display_name']??''),(string)($person['first_name']??'')]);
        $normalized=array_unique(array_map(static fn(string $s):string=>
            mb_strtolower(trim($s),'UTF-8'),$names));
        if(in_array($name,$normalized,true))$exact[(int)$person['user_id']]=$person;
        else foreach($normalized as $candidate)
            if(str_starts_with($candidate,$name.' ')||str_contains($candidate,$name))
                {$partial[(int)$person['user_id']]=$person;break;}
    }
    $matches=$exact?:$partial;
    if(count($matches)===1)return ['staff'=>array_values($matches)[0]];
    if(!$matches)return ['error'=>'I could not match that name to an active employee in this restaurant. Please provide the employee’s full name.'];
    return ['error'=>'More than one employee matches that name: '.implode(', ',array_map(
        static fn(array $p):string=>sac_staff_name($p),array_values($matches))).'. Please use the full name.'];
}
function fm8d4_shift_text(array $shift):string
{
    return date('D M j',strtotime((string)$shift['starts_at'])).' '.
        date('g:i A',strtotime((string)$shift['starts_at'])).'–'.
        date('g:i A',strtotime((string)$shift['ends_at'])).' '.
        trim((string)($shift['title']??'Shift'));
}
function fm8d4_schedule_answer(array $rows,string $subject,string $week):string
{
    $rows=array_values(array_filter($rows,static fn(array $shift):bool=>
        ($shift['status']??'')!=='cancelled'));
    if(!$rows)return $subject.' has no recorded shifts for the week of '.$week.'.';
    return $subject.' — week of '.$week.': '.implode('; ',array_map(
        'fm8d4_shift_text',$rows)).'.';
}
function fm8d4_schedule_observation(string $answer,string $source='Staff Scheduling'):array
{
    return ['ok'=>true,'data'=>['answer'=>mb_substr($answer,0,2600,'UTF-8'),'source'=>$source]];
}
function fm8d4_schedule_catalogue(array $principal):array
{
    $hasSelf=fm8d4_can($principal,'schedule.self');
    $hasAll=fm8d4_can($principal,'schedule.view')||fm8d4_can($principal,'schedule.manage');
    $manage=fm8d4_can($principal,'schedule.manage')&&fm8d4_can($principal,'schedule.agent');
    $tools=[];
    if($hasSelf||$hasAll){
        $tools['get_my_schedule']=[
            'description'=>'Read the signed-in employee’s actual schedule for a week, including shift dates and times. Use for questions about my next shift or my schedule. Optional week_date YYYY-MM-DD selects any day in that week. No employee ID or names accepted. Data stays in Gelato.',
            'parameters'=>['type'=>'object','properties'=>['week_date'=>['type'=>'string','description'=>'Any date in the requested week, YYYY-MM-DD. If omitted use this week.']],'additionalProperties'=>false],
            'allowedArguments'=>['week_date'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                fm8d4_can($actor,'schedule.self')||fm8d4_can($actor,'schedule.view')||fm8d4_can($actor,'schedule.manage'),
            'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
                $week=fm8d4_schedule_week($args);
                $employee=scheduling_staff_user($pdo,$org,$uid);
                if(!$employee)return fm8d4_schedule_observation('No active employee scheduling profile is linked to your account.');
                return fm8d4_schedule_observation(fm8d4_schedule_answer(
                    scheduling_shifts($pdo,$org,$week,$uid),sac_staff_name($employee),$week));
            },
        ];
    }
    if($hasAll){
        $tools['get_employee_schedule']=[
            'description'=>'Read a named active employee’s shifts for a week. Use when a manager asks when an employee works. Specify employee_name and optional week_date YYYY-MM-DD; never guess between duplicate names.',
            'parameters'=>['type'=>'object','properties'=>[
                'employee_name'=>['type'=>'string'],'week_date'=>['type'=>'string',
                'description'=>'Any date in requested week, YYYY-MM-DD'],
            ],'required'=>['employee_name'],'additionalProperties'=>false],
            'allowedArguments'=>['employee_name','week_date'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                fm8d4_can($actor,'schedule.view')||fm8d4_can($actor,'schedule.manage'),
            'validate'=>static fn(array $args,array $actor):array=>
                trim((string)($args['employee_name']??''))===''?['employee_name']:[],
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();$org=(int)$actor['organization_id'];
                $match=fm8d4_staff_resolve($pdo,$org,(string)$args['employee_name']);
                if(isset($match['error']))return fm8d4_schedule_observation($match['error']);
                $p=$match['staff'];$week=fm8d4_schedule_week($args);
                return fm8d4_schedule_observation(fm8d4_schedule_answer(
                    scheduling_shifts($pdo,$org,$week,(int)$p['user_id']),sac_staff_name($p),$week));
            },
        ];
        $tools['get_day_roster']=[
            'description'=>'Read restaurant shift roster for a particular calendar date. Requires date YYYY-MM-DD; no private employee payroll or salaries.',
            'parameters'=>['type'=>'object','properties'=>['date'=>['type'=>'string']],'required'=>['date'],'additionalProperties'=>false],
            'allowedArguments'=>['date'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                fm8d4_can($actor,'schedule.view')||fm8d4_can($actor,'schedule.manage'),
            'validate'=>static fn(array $args,array $actor):array=>empty($args['date'])?['date']:[],
            'execute'=>static function(array $args,array $actor):array{
                $day=fm8d4_schedule_day((string)$args['date']);
                $pdo=app_pdo();$org=(int)$actor['organization_id'];
                $rows=array_filter(scheduling_shifts($pdo,$org,scheduling_week_start($day)),
                    static fn(array $r):bool=>substr((string)$r['starts_at'],0,10)===$day&&($r['status']??'')!=='cancelled');
                if(!$rows)return fm8d4_schedule_observation('No shifts are scheduled for '.$day.'.');
                $parts=[];foreach($rows as $row)
                    $parts[]=($row['display_name']?:'Open shift').' '.fm8d4_shift_text($row);
                return fm8d4_schedule_observation(date('l M j',strtotime($day)).': '.implode('; ',$parts).'.');
            },
        ];
        $tools['check_staff_availability']=[
            'description'=>'Check one named employee’s availability and existing overlapping shifts for exact date/start/end. Return conflicts or the recorded available status; do not assume no recurring restriction means confirmed consent.',
            'parameters'=>['type'=>'object','properties'=>[
                'employee_name'=>['type'=>'string'],'date'=>['type'=>'string'],
                'start_time'=>['type'=>'string','description'=>'24-hour HH:MM'],
                'end_time'=>['type'=>'string','description'=>'24-hour HH:MM'],
            ],'required'=>['employee_name','date','start_time','end_time'],'additionalProperties'=>false],
            'allowedArguments'=>['employee_name','date','start_time','end_time'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                fm8d4_can($actor,'schedule.view')||fm8d4_can($actor,'schedule.manage'),
            'validate'=>static fn(array $args,array $actor):array=>
                array_values(array_filter(['employee_name','date','start_time','end_time'],
                    static fn(string $name):bool=>trim((string)($args[$name]??''))==='')),
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();$org=(int)$actor['organization_id'];
                $match=fm8d4_staff_resolve($pdo,$org,(string)$args['employee_name']);
                if(isset($match['error']))return fm8d4_schedule_observation($match['error']);
                [$start,$end]=fm8d4_schedule_range($args);
                $p=$match['staff'];$who=sac_staff_name($p);
                $availability=scheduling_availability_check($pdo,$org,(int)$p['user_id'],$start,$end);
                $conflicts=scheduling_shift_conflicts($pdo,$org,(int)$p['user_id'],$start,$end);
                if($conflicts)return fm8d4_schedule_observation($who.' has '.count($conflicts).' overlapping scheduled shift(s) for that time.');
                if(!$availability['ok'])return fm8d4_schedule_observation($who.' is not available for that time: '.$availability['reason']);
                return fm8d4_schedule_observation($who.' has no shift overlap. Recorded availability check: '.$availability['reason'].' Confirm with the employee when no availability preferences are recorded.','Staff Scheduling / Employee Availability');
            },
        ];
    }
    if($manage){
        $tools['propose_staff_shift']=[
            'description'=>'PREPARE, but do not create, a shift for a named employee. Resolve identity, validate availability and overlapping shifts, then stage a time-limited confirmation proposal. The manager must explicitly say Confirm in chat before the existing Scheduling Agent executes it. Date YYYY-MM-DD and 24-hour HH:MM start/end times are required; if missing ask the user.',
            'parameters'=>['type'=>'object','properties'=>[
                'employee_name'=>['type'=>'string'],'date'=>['type'=>'string'],
                'start_time'=>['type'=>'string','description'=>'24-hour HH:MM'],
                'end_time'=>['type'=>'string','description'=>'24-hour HH:MM'],
            ],'required'=>['employee_name','date','start_time','end_time'],'additionalProperties'=>false],
            'allowedArguments'=>['employee_name','date','start_time','end_time'],
            // Staging an ephemeral proposal is not a business write; the
            // actual shift_create is an explicit-confirmation gated action.
            'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                fm8d4_can($actor,'schedule.agent')&&fm8d4_can($actor,'schedule.manage'),
            'validate'=>static fn(array $args,array $actor):array=>
                array_values(array_filter(['employee_name','date','start_time','end_time'],
                    static fn(string $name):bool=>trim((string)($args[$name]??''))==='')),
            'execute'=>static function(array $args,array $actor):array{
                $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
                $match=fm8d4_staff_resolve($pdo,$org,(string)$args['employee_name']);
                if(isset($match['error']))return fm8d4_schedule_observation($match['error']);
                [$start,$end]=fm8d4_schedule_range($args);
                $person=$match['staff'];$staffId=(int)$person['user_id'];
                try{sac_validate_candidate($pdo,$org,$staffId,$start,$end);}
                catch(InvalidArgumentException $e){
                    return fm8d4_schedule_observation('No shift was created or proposed for '.sac_staff_name($person).': '.$e->getMessage());
                }
                $payload=['title'=>($person['job_title']?:'Shift'),'startsAt'=>$start,'endsAt'=>$end,
                    'userId'=>$staffId,'status'=>'scheduled','enforceAvailability'=>true];
                $summary='Proposed action: schedule '.sac_staff_name($person).' on '.
                    date('l M j',strtotime($start)).' from '.date('g:i A',strtotime($start)).
                    ' to '.date('g:i A',strtotime($end)).'.';
                $newSession=session_status()!==PHP_SESSION_ACTIVE;
                if($newSession&&!session_start())throw new RuntimeException('Could not open secure Scheduling confirmation session.');
                try{
                    $pending=sac_pending_store($org,$uid,'shift_create',$payload,$summary);
                    app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
                        (string)$pending['id'],null,['type'=>'shift_create','source'=>'food_monster_model']);
                }finally{if($newSession&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
                return fm8d4_schedule_observation($summary."\n\nReply Confirm to create the shift, or Cancel to discard it. No shift has been saved yet.",'Staff Scheduling / Employee Availability');
            },
        ];
    }
    return $tools;
}

/** The only phrase gate is intentional: explicit approval/cancellation of an
 * already staged proposal, never discovery or routing of arbitrary intents.
 * Fresh server permissions and the existing Scheduling Agent business
 * validation run again before the database is changed. */
function fm8d4_confirm_pending(PDO $pdo,array $principal,array $history,?callable $refreshOverride=null):?array
{
    $last=end($history);$message=mb_strtolower(trim((string)($last['content']??'')),'UTF-8');
    if(!in_array($message,['confirm','cancel'],true))return null;
    $org=(int)($principal['organization_id']??0);$uid=(int)($principal['id']??0);
    $newSession=session_status()!==PHP_SESSION_ACTIVE;
    if($newSession&&!session_start())return null;
    try{
        $pending=sac_pending_get($org,$uid);
        if(!$pending||($pending['type']??'')!=='shift_create')return null;
        $actor=$refreshOverride!==null?$refreshOverride():fm8d2_refresh_operator($pdo,$principal);
        if(!is_array($actor)||(int)$actor['id']!==$uid||(int)$actor['organization_id']!==$org)
            return ['ok'=>true,'skill'=>'schedule.permission_denied','answer'=>'Your account access changed; I did not change the schedule.','sources'=>[],'data'=>['status'=>'denied','toolCalls'=>0]];
        if($message==='cancel'){
            sac_pending_clear($org,$uid);
            app_audit($pdo,$org,$uid,'schedule.agent_action_discarded','schedule_agent_proposal',
                (string)$pending['id'],null,['type'=>$pending['type'],'source'=>'food_monster_model']);
            return ['ok'=>true,'skill'=>'schedule.action_cancelled','answer'=>'Cancelled. I did not change the schedule.','sources'=>['Staff Scheduling'],'data'=>['status'=>'cancelled','toolCalls'=>0]];
        }
        if(!fm8d4_can($actor,'schedule.agent')||!fm8d4_can($actor,'schedule.manage'))
            return ['ok'=>true,'skill'=>'schedule.permission_denied','answer'=>'Schedule management permission is required to confirm this shift. Nothing was changed.','sources'=>[],'data'=>['status'=>'denied','toolCalls'=>0]];
        try{$result=sac_execute_pending($pdo,$org,$uid,$actor,$pending);}
        catch(Throwable $e){
            return ['ok'=>true,'skill'=>'schedule.action_stopped',
                'answer'=>'I did not complete the schedule change: '.($e instanceof InvalidArgumentException?$e->getMessage():'The existing Scheduling Agent could not verify this action.'),
                'sources'=>['Staff Scheduling'],'data'=>['status'=>'not_committed','toolCalls'=>0]];
        }
        sac_pending_clear($org,$uid);
        app_audit($pdo,$org,$uid,'schedule.agent_action_confirmed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>$pending['type'],'source'=>'food_monster_model']);
        return ['ok'=>true,'skill'=>'schedule.action_confirmed',
            'answer'=>(string)($result['answer']??'Schedule change confirmed.'),
            'sources'=>['Staff Scheduling'],'data'=>[
                'status'=>'completed','toolCalls'=>0,
                'action'=>$result['data']['action']??'',
                'shiftId'=>$result['data']['shiftId']??'']];
    }finally{if($newSession&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
