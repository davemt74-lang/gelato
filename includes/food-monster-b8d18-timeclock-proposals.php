<?php
declare(strict_types=1);

/**
 * FM-B8D18F: typed/spoken model-selected personal clock and break proposals.
 * The Time Clock Agent owns all confirmation, state-version and voice-identity
 * checks; this adapter never directly writes time-clock or attendance rows.
 */
require_once __DIR__.'/timeclock-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';

function fm8d18f_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('timeclock.self',$actor)
        &&app_has_permission('timeclock.agent',$actor);
}

function fm8d18f_fields(array $args):array
{
    return in_array($args['action']??null,
        ['clock_in','clock_out','break_start','break_end'],true)?[]:['action'];
}

/** Does not mutate any record; the specialist repeats these checks on confirm. */
function fm8d18f_preflight(PDO $pdo,array $actor,string $action):string
{
    if(!fm8d18f_can($actor))throw new DomainException('Personal Time Clock Agent permission is required.');
    if(fm8d18f_fields(['action'=>$action]))throw new InvalidArgumentException('Choose a valid personal clock or break action.');
    $clock=tv_open_clock($pdo,(int)$actor['organization_id'],(int)$actor['id']);
    if($action==='clock_in'){
        if($clock)throw new InvalidArgumentException('You are already clocked in.');
        return 'Proposed action: clock you in now.';
    }
    if(!$clock)throw new InvalidArgumentException('Clock in before clocking out or taking breaks.');
    if($action==='clock_out')return 'Proposed action: clock you out now.';
    $q=$pdo->prepare('SELECT id FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
    $q->execute([(int)$clock['id']]);$openBreak=$q->fetchColumn()!==false;
    if($action==='break_start'){
        if($openBreak)throw new InvalidArgumentException('A break is already running.');
        return 'Proposed action: start your break now.';
    }
    if(!$openBreak)throw new InvalidArgumentException('There is no active break to end.');
    return 'Proposed action: end your break now.';
}

function fm8d18f_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18f_can($actor))throw new DomainException('Personal Time Clock Agent permission is required.');
    if($missing=fm8d18f_fields($args))
        throw new InvalidArgumentException('Specify one personal time-clock action: '.implode(', ',$missing).'.');
    $pdo=app_pdo();
    if(!timeclock_agent_ready($pdo))
        throw new RuntimeException('Time Clock is unavailable. Complete Gelato upgrade.php first.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $voice=($context['channel']??'')==='voice';
    $eventId=$voice?trim((string)($context['voiceEventId']??'')):'';
    if($voice){
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Verified personal voice identity and one-use operator approval are required before staging this clock action.');
    }
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure Time Clock confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('timeclock',$org,$uid)!==null)
            throw new InvalidArgumentException('An agent action is already pending. Confirm or Cancel it before requesting another.');
        $action=(string)$args['action'];
        $summary=fm8d18f_preflight($pdo,$actor,$action);
        $pending=timeclock_agent_propose($pdo,$actor,$action,
            ['source'=>$voice?'voice':'agent','voiceEventId'=>$eventId],$summary);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)$pending['answer'],
            'source'=>'Gelato Time Clock Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>$pending['data']['proposal']??null,
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}

function fm8d18f_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18f_can($actor))return [];
    if(($context['channel']??'')==='voice'
       &&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_my_timeclock_action'=>[
        'description'=>'ONLY when the currently signed-in employee explicitly asks to clock THEMSELVES in/out, start THEIR break or end THEIR break. Works for typed requests or an authorized voice turn. Choose exactly one action; NEVER infer this from a schedule question, infer another employee ID, or imply a completed punch. Stages an expiring Time Clock Agent confirmation with fresh state checks; a separate Confirm is required. Spoken actions also require a matching voice identity event and one-use operator approval.',
        'parameters'=>['type'=>'object','properties'=>[
            'action'=>['type'=>'string','enum'=>['clock_in','clock_out','break_start','break_end']],
        ],'required'=>['action'],'additionalProperties'=>false],
        'allowedArguments'=>['action'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18f_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18f_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18f_propose($args,$user,$context),
    ]];
}
