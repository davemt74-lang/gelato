<?php
declare(strict_types=1);

/**
 * 3K2D: native model-selected Daily Prep tools for Gelato Food Monster.
 * These tools SELECT authorized records or STAGE existing Prep Agent proposals.
 * They do not independently mutate checklists or bypass Confirm/Cancel.
 */
require_once __DIR__.'/prep-agent-core.php';
require_once __DIR__.'/food-monster-b8d18-prep-list-proposals.php';

function fm8d18j11_can(array $actor): bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('prep.intelligence.view',$actor)
        &&app_has_permission('prep.intelligence.agent',$actor)
        &&daily_quicklist_can_view($actor);
}
function fm8d18j11_voice_guard(PDO $pdo,array $actor,array $context): void
{
    if(($context['channel']??'')!=='voice')return;
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $voiceEvent=trim((string)($context['voiceEventId']??''));
    if(($context['privateVoiceApproved']??false)!==true
        ||$voiceEvent===''||!tv_voice_event_valid($pdo,$org,$uid,$voiceEvent))
        throw new DomainException('Matching voice identity and one-use private-voice approval are required for spoken Daily Prep tools.');
}
function fm8d18j11_date(array $args): string
{
    $date=trim((string)($args['date']??''));
    if($date==='')$date=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d');
    return daily_quicklist_date($date);
}
function fm8d18j11_read(array $args,array $actor,array $context): array
{
    if(!fm8d18j11_can($actor))throw new DomainException('Daily Prep Agent permission is required.');
    $pdo=app_pdo();fm8d18j11_voice_guard($pdo,$actor,$context);
    if(!daily_quicklist_ready($pdo))throw new RuntimeException('Daily Prep is not installed. Run upgrade.php.');
    $date=fm8d18j11_date($args);$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not open secure Daily Prep Agent context.');
    try{
        $rows=daily_quicklist_items($pdo,$org,$date);
        $selection=pqa_context_save($org,$uid,$date,$rows,(string)($context['threadId']??''));
        $prep=array_values(array_filter($rows,static fn(array $r):bool=>$r['type']==='prep'));
        $done=count(array_filter($prep,static fn(array $r):bool=>$r['status']==='done'));
        $message='Daily Prep for '.$date.': '.$done.' of '.count($prep).' prep items done. '.
            pqa_numbered_brief($selection).
            ' You can say "mark the second prep item done" or name the item. '.
            'Any changes require a separate Confirm. Open Daily Prep to view recipes and timers.';
        return ['ok'=>true,'data'=>[
            'answer'=>$message,'source'=>'Gelato official Daily Prep/Need More records',
            'proof'=>['url'=>'daily-prep.php'],
            'date'=>$date,'prepCount'=>count($prep),
            'needMoreCount'=>count($selection['lists']['inventory']),
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j11_allowed_actions(array $actor): array
{
    $actions=['generate_menu','mark_done','reopen','timer_start','timer_pause',
        'timer_resume','timer_stop','set_batch_target','add_need_more','remove_item'];
    if(daily_quicklist_can_manage($actor))
        array_push($actions,'set_status','set_manager_note','notify_managers');
    return $actions;
}
function fm8d18j11_action_fields(array $args,array $actor): array
{
    $missing=[];
    $action=$args['action']??null;$list=$args['list']??null;$mode=$args['reference_mode']??null;
    if(!is_string($action)||!in_array($action,fm8d18j11_allowed_actions($actor),true))$missing[]='action';
    if(!in_array($list,['prep','inventory'],true))$missing[]='list';
    if(!in_array($mode,['name','ordinal','focused','none'],true))$missing[]='reference_mode';
    if(isset($args['date'])){
        if(!is_string($args['date']))$missing[]='date';
        else try{fm8d18j11_date($args);}catch(InvalidArgumentException){$missing[]='date';}
    }
    if($missing)return array_values(array_unique($missing));
    $prepOnly=['generate_menu','mark_done','reopen','timer_start','timer_pause',
        'timer_resume','timer_stop','set_batch_target'];
    if(in_array($action,$prepOnly,true)&&$list!=='prep')$missing[]='list';
    if($action==='add_need_more'&&$list!=='inventory')$missing[]='list';
    $noSelection=in_array($action,['generate_menu','add_need_more'],true);
    if($noSelection){
        if($mode!=='none')$missing[]='reference_mode';
        if($action==='add_need_more'
            &&(!is_string($args['name']??null)||trim($args['name'])===''||
                mb_strlen(trim($args['name']),'UTF-8')>220))$missing[]='name';
    }else{
        if($mode==='none')$missing[]='reference_mode';
        if($mode==='name'&&(!is_string($args['name']??null)
            ||trim($args['name'])===''||mb_strlen(trim($args['name']),'UTF-8')>220))$missing[]='name';
        if($mode==='ordinal'&&(!is_int($args['ordinal']??null)
            ||$args['ordinal']<1||$args['ordinal']>20))$missing[]='ordinal';
    }
    if($action==='set_batch_target'&&(!isset($args['quantity'])
        ||!is_int($args['quantity'])&&!is_float($args['quantity'])
        ||!is_finite((float)$args['quantity'])||(float)$args['quantity']<0.001
        ||(float)$args['quantity']>10000))$missing[]='quantity';
    if($action==='set_batch_target'&&isset($args['unit'])
        &&(!is_string($args['unit'])||mb_strlen($args['unit'],'UTF-8')>40))$missing[]='unit';
    if($action==='set_status'){
        $allowed=$list==='prep'?['todo','in_progress','paused','done']:['need_more','ordered','received'];
        if(!in_array($args['status']??null,$allowed,true))$missing[]='status';
    }
    if($action==='set_manager_note'
        &&(!is_string($args['note']??null)||trim($args['note'])===''||
            mb_strlen($args['note'],'UTF-8')>2000))$missing[]='note';
    if($action==='add_need_more'&&isset($args['note'])
        &&(!is_string($args['note'])||mb_strlen($args['note'],'UTF-8')>120))$missing[]='note';
    return array_values(array_unique($missing));
}
function fm8d18j11_intent(array $args,array $row): array
{
    $action=(string)$args['action'];
    $intent=['target'=>$row['title'],'kind'=>(string)$args['list'],
        'resolvedItemId'=>(string)$row['id']];
    if($action==='mark_done'||$action==='reopen')return $intent+[
        'op'=>'toggle','status'=>$action==='mark_done'?'done':'todo'];
    if(str_starts_with($action,'timer_'))return $intent+['op'=>'timer','mode'=>substr($action,6)];
    if($action==='set_batch_target')return $intent+['op'=>'set_target',
        'quantity'=>(float)$args['quantity'],'unit'=>(string)($args['unit']??'')];
    if($action==='remove_item')return $intent+['op'=>'archive'];
    if($action==='set_status')return $intent+['op'=>'update','status'=>$args['status']];
    if($action==='set_manager_note')return $intent+['op'=>'update','note'=>trim((string)$args['note'])];
    if($action==='notify_managers')return $intent+['op'=>'notify'];
    throw new InvalidArgumentException('Unsupported Daily Prep tool action.');
}
function fm8d18j11_propose(array $args,array $actor,array $context): array
{
    if(!fm8d18j11_can($actor))throw new DomainException('Daily Prep Agent permission is required.');
    if($missing=fm8d18j11_action_fields($args,$actor))
        throw new InvalidArgumentException('Specify '.implode(', ',$missing).' for the Daily Prep action.');
    $pdo=app_pdo();fm8d18j11_voice_guard($pdo,$actor,$context);
    if(!daily_quicklist_ready($pdo))throw new RuntimeException('Daily Prep is not installed. Run upgrade.php.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $date=fm8d18j11_date($args);$action=$args['action'];
    $threadId=(string)($context['threadId']??'');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not open secure Prep Agent proposal session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('prep',$org,$uid)!==null)
            throw new InvalidArgumentException('Another Gelato Agent action is pending. Confirm or Cancel it first.');
        if($action==='generate_menu'){
            $result=pac_quicklist_generate_proposal($pdo,$org,$uid,$actor,$date);
        }elseif($action==='add_need_more'){
            $intent=['op'=>'add_need_more','kind'=>'inventory',
                'target'=>trim((string)$args['name']),'note'=>trim((string)($args['note']??''))];
            $result=pqa_propose($pdo,$org,$uid,$actor,$intent,$date);
        }else{
            $kind=$args['list'];$mode=$args['reference_mode'];
            if($mode==='name')$row=pqa_resolve_item($pdo,$org,$date,$kind,(string)$args['name']);
            else $row=pqa_context_resolve($pdo,$org,$uid,$threadId,$date,$kind,$mode,
                (int)($args['ordinal']??0));
            $intent=fm8d18j11_intent($args,$row);
            $result=pqa_propose($pdo,$org,$uid,$actor,$intent,$date);
            if(!pqa_context_get($org,$uid,$threadId))
                pqa_context_save($org,$uid,$date,daily_quicklist_items($pdo,$org,$date),$threadId);
            pqa_context_focus($org,$uid,$threadId,$kind,(string)$row['id']);
        }
        if(($result['skill']??'')==='prep.action_proposal')
            app_audit($pdo,$org,$uid,'prep.agent_model_selected_proposal','prep_agent_proposal',
                (string)($result['data']['proposal']['id']??''),null,
                ['nativeTool'=>'propose_daily_prep_action','action'=>$action,'date'=>$date,
                    'source'=>($context['channel']??'')==='voice'?'authorized_voice':'typed_chat']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)($result['answer']??'Daily Prep is up to date.'),
            'source'=>'Gelato official Prep Agent (native model-selected skill)',
            'requiresConfirmation'=>($result['data']['requiresConfirmation']??false)===true,
            'proposal'=>$result['data']['proposal']??null,
            'proof'=>['url'=>'daily-prep.php'],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j11_catalogue(array $actor,array $context=[]): array
{
    if(!fm8d18j11_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    $actions=fm8d18j11_allowed_actions($actor);
    return [
        'get_daily_prep_checklist'=>[
            'description'=>'Read the signed-in restaurant dated Daily Prep and active Need More lists. Show current numbered item names and statuses; store a short-lived, user-scoped numbered selection so follow-up phrases such as "the second item" can be resolved without giving the model database IDs. Date optional: omit for restaurant-local today. Read only; no updates.',
            'parameters'=>['type'=>'object','properties'=>[
                'date'=>['type'=>'string','description'=>'Optional restaurant-local YYYY-MM-DD date; omit for today'],
            ],'additionalProperties'=>false],
            'allowedArguments'=>['date'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $a):bool=>fm8d18j11_can($user),
            'validate'=>static function(array $a,array $user):array{
                try{fm8d18j11_date($a);return [];}catch(InvalidArgumentException){return ['date'];}
            },
            'execute'=>static fn(array $a,array $user):array=>fm8d18j11_read($a,$user,$context),
        ],
        'propose_daily_prep_action'=>[
            'description'=>'ONLY when the employee explicitly asks to change Daily Prep or Need More. Choose the real action and one item by exact name, ordinal from a previously displayed numbered list, or focused "that item" after a prior selection. The model NEVER supplies item IDs. Use reference_mode none only for generate_menu or adding a named Need More request. For targets use saved recipe yield and do not invent units. Manager status, notes and notices require management permission. This tool stages an expiring official Prep Agent proposal; a separate Confirm and fresh server checks execute it. No stock adjustments or edits to master recipes. Unverified voice cannot use it.',
            'parameters'=>['type'=>'object','properties'=>[
                'action'=>['type'=>'string','enum'=>$actions],
                'list'=>['type'=>'string','enum'=>['prep','inventory'],
                    'description'=>'prep for dated checklist; inventory for Need More list'],
                'reference_mode'=>['type'=>'string','enum'=>['name','ordinal','focused','none']],
                'name'=>['type'=>'string','description'=>'Exact requested item name, or new Need More title; never invent one'],
                'ordinal'=>['type'=>'integer','minimum'=>1,'maximum'=>20,
                    'description'=>'1-based number from a previously displayed list in the same conversation'],
                'date'=>['type'=>'string','description'=>'Optional restaurant-local YYYY-MM-DD; omit for today'],
                'quantity'=>['type'=>'number','exclusiveMinimum'=>0,'maximum'=>10000,
                    'description'=>'Explicit desired recipe batch yield, only for set_batch_target'],
                'unit'=>['type'=>'string','description'=>'Optional supplied saved recipe yield unit, must match canonical unit'],
                'status'=>['type'=>'string','enum'=>['todo','in_progress','paused','done','need_more','ordered','received']],
                'note'=>['type'=>'string','maxLength'=>2000,
                    'description'=>'Explicit manager note or Need More quantity note; never infer it'],
            ],'required'=>['action','list','reference_mode'],'additionalProperties'=>false],
            'allowedArguments'=>['action','list','reference_mode','name','ordinal','date','quantity','unit','status','note'],
            'impact'=>'read',
            'authorize'=>static fn(array $user,array $a):bool=>fm8d18j11_can($user)
                &&fm8d18j11_action_fields($a,$user)===[],
            'validate'=>static fn(array $a,array $user):array=>fm8d18j11_action_fields($a,$user),
            'execute'=>static fn(array $a,array $user):array=>fm8d18j11_propose($a,$user,$context),
        ],
    ];
}
