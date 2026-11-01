<?php
declare(strict_types=1);

/** B8D18J4A: model-selected self-only recurring availability and exceptions read. */
require_once __DIR__.'/scheduling-core.php';

function fm8d18j4a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.self',$actor);
}
function fm8d18j4a_fields(array $args):array
{
    return in_array($args['status']??'all',['all','pending','approved','rejected'],true)?[]:['status'];
}
function fm8d18j4a_read(array $args,array $actor):array
{
    if(!fm8d18j4a_can($actor))throw new DomainException('Personal schedule permission is required.');
    if($missing=fm8d18j4a_fields($args))throw new InvalidArgumentException('Unsupported '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $staff=scheduling_staff_user($pdo,$org,$uid);
    if(!$staff)return ['ok'=>true,'data'=>['answer'=>'No active staff schedule profile is linked to your account.',
        'source'=>'Gelato Staff Scheduling','readOnly'=>true,'recurring'=>[],'exceptions'=>[]]];
    $windows=array_map(static fn(array $row):array=>[
        'weekday'=>(int)$row['weekday'],'start'=>(string)$row['start_time'],
        'end'=>(string)$row['end_time'],'type'=>(string)$row['availability_type'],
    ],scheduling_availability($pdo,$org,$uid));
    $status=(string)($args['status']??'all');
    $rows=scheduling_exceptions($pdo,$org,$uid,$status==='all'?'':$status);
    $exceptions=array_map(static fn(array $row):array=>[
        'requestId'=>(string)$row['public_id'],'date'=>(string)$row['exception_date'],
        'start'=>$row['start_time'],'end'=>$row['end_time'],
        'type'=>(string)$row['exception_type'],'status'=>(string)$row['status'],
        'note'=>$row['note']??null,
    ],array_slice($rows,0,30));
    $answer='Your saved recurring availability has '.count($windows).' window(s). '.
        'Showing '.count($exceptions).' most recent '.($status==='all'?'':' '.$status).
        ' availability exception(s). These are recorded preferences and requests, not a promise that a shift is assigned.';
    if(count($rows)>30)$answer.=' Older requests are omitted from this summary.';
    return ['ok'=>true,'data'=>['answer'=>$answer,'source'=>'Gelato Staff Scheduling (authenticated self read)',
        'readOnly'=>true,'recurring'=>$windows,'exceptions'=>$exceptions]];
}
function fm8d18j4a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j4a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['get_my_availability'=>[
        'description'=>'Read actual SAVED weekly availability windows and dated exceptions for the signed-in employee only. Use for "when am I available", "show my availability", "what time-off requests are pending", or "what did management approve". May filter status. Do not infer availability when records are absent, do not confuse recurring windows with scheduled shifts, and do not claim anything changed.',
        'parameters'=>['type'=>'object','properties'=>[
            'status'=>['type'=>'string','enum'=>['all','pending','approved','rejected'],
                'description'=>'Optional availability exception status filter; defaults to all'],
        ],'additionalProperties'=>false],
        'allowedArguments'=>['status'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j4a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j4a_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j4a_read($args,$user),
    ]];
}
