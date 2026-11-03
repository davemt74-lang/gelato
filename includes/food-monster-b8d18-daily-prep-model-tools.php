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
        if(fm8d18j11_can_receipts($actor)&&daily_quicklist_receipts_ready($pdo)){
            $parts=[];
            foreach($rows as $row){
                if($row['type']!=='inventory'||$row['status']==='received'
                    ||$row['requestedStockQuantity']===null||!$row['inventoryId'])continue;
                $progress=daily_quicklist_receipt_progress($pdo,$org,(string)$row['id']);
                $parts[]=$row['title'].': '.daily_quicklist_format_quantity($progress['receivedQuantity']).
                    ' of '.daily_quicklist_format_quantity($progress['requestedQuantity']).' '.$progress['unit'].
                    ' recorded, '.daily_quicklist_format_quantity($progress['remainingQuantity']).' remaining';
                if(count($parts)>=10)break;
            }
            if($parts)$message.=' Active numeric Need More quantities: '.implode('; ',$parts).'.';
        }
        return ['ok'=>true,'data'=>[
            'answer'=>$message,'source'=>'Gelato official Daily Prep/Need More records',
            'proof'=>['url'=>'daily-prep.php'],
            'date'=>$date,'prepCount'=>count($prep),
            'needMoreCount'=>count($selection['lists']['inventory']),
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
/** Reorder-point signals and exact recipe-name links, never a stock forecast. */
function fm8d18j11_stock_read(array $args,array $actor,array $context): array
{
    if(!fm8d18j11_can($actor)
        ||!(app_has_permission('inventory.view',$actor)||app_has_permission('inventory.manage',$actor)))
        throw new DomainException('Inventory View and Daily Prep Agent permissions are required.');
    $pdo=app_pdo();fm8d18j11_voice_guard($pdo,$actor,$context);
    if(!daily_quicklist_ready($pdo))throw new RuntimeException('Daily Prep is not installed. Run upgrade.php.');
    $org=(int)$actor['organization_id'];$date=fm8d18j11_date($args);
    $items=daily_quicklist_items($pdo,$org,$date);
    $recipes=app_has_permission('recipes.view',$actor)?daily_quicklist_recipes($pdo,$org):[];
    $alerts=daily_quicklist_stock_alerts(daily_quicklist_inventory($pdo,$org),$recipes,$items);
    $parts=[];
    foreach(array_slice($alerts,0,12) as $row){
        $parts[]=$row['name'].' (on hand '.daily_quicklist_format_quantity($row['onHand']).
            ' '.$row['unit'].', reorder point '.daily_quicklist_format_quantity($row['reorderPoint']).
            ', '.($row['alreadyRequested']?'already on Need More':'not on Need More').')'.
            ($row['prepRecipes']?' · used by active prep recipe(s): '.implode(', ',$row['prepRecipes']):'');
    }
    $answer=$alerts?'Items at or below their saved reorder point: '.implode('; ',$parts).'.'
        :'No inventory items are at or below a configured reorder point in the available stock snapshot.';
    if(count($alerts)>12)$answer.=' Showing the first 12 of '.count($alerts).'; open Inventory for the complete list.';
    $answer.=' These are reorder-point indicators, not calculated recipe shortages or purchase quantities. No stock or Need More request changed.';
    return ['ok'=>true,'data'=>['answer'=>$answer,
        'source'=>'Gelato saved inventory reorder points and authorized active Daily Prep recipes',
        'date'=>$date,'alerts'=>$alerts,'proof'=>['url'=>'daily-prep.php']]];
}
function fm8d18j11_can_receipts(array $actor): bool
{
    return fm8d18j11_can($actor)&&daily_quicklist_receipt_permission($actor);
}
function fm8d18j11_receipts_read(array $args,array $actor,array $context): array
{
    if(!fm8d18j11_can_receipts($actor))throw new DomainException('Inventory View and Prep management permissions are required.');
    $name=trim((string)($args['name']??''));
    if($name===''||mb_strlen($name,'UTF-8')>220)throw new InvalidArgumentException('Name one exact Need More item.');
    $pdo=app_pdo();fm8d18j11_voice_guard($pdo,$actor,$context);
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];$date=fm8d18j11_date($args);
    $row=pqa_resolve_item($pdo,$org,$date,'inventory',$name);
    $candidates=daily_quicklist_receipt_candidates($pdo,$org,(string)$row['id']);
    $progress=daily_quicklist_receipt_progress($pdo,$org,(string)$row['id']);
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start receipt selection session.');
    try{pqa_receipt_context_save($org,$uid,(string)($context['threadId']??''),(string)$row['id'],$candidates);}
    finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
    $parts=[];
    foreach($candidates as $n=>$receipt)$parts[]=($n+1).'. '.
        daily_quicklist_format_quantity($receipt['quantity']).' '.$receipt['unit'].' received '.$receipt['when'];
    $answer=$candidates?'Eligible ALREADY RECORDED stock receipts for '.$row['title'].': '.implode('; ',$parts).'.'
        :'No eligible confirmed inventory receipt was recorded after this Need More request. Receive stock through Operations first.';
    if($progress['requestedQuantity']!==null)
        $answer.=' Numeric Need More target: '.daily_quicklist_format_quantity($progress['requestedQuantity']).' '.$progress['unit'].
            '; already allocated: '.daily_quicklist_format_quantity($progress['receivedQuantity']).' '.$progress['unit'].
            '; still outstanding: '.daily_quicklist_format_quantity($progress['remainingQuantity']).' '.$progress['unit'].'.';
    else $answer.=' No numeric stock target is saved; linking one full receipt will close this legacy request.';
    $answer.=' Linking one requires a separate Confirm. It marks Need More received but never adjusts stock again.';
    return ['ok'=>true,'data'=>['answer'=>$answer,'count'=>count($candidates),
        'source'=>'Gelato immutable inventory receipt ledger and Need More',
        'proof'=>['url'=>'daily-prep.php']]];
}
function fm8d18j11_allowed_actions(array $actor): array
{
    $actions=['generate_menu','mark_done','reopen','timer_start','timer_pause',
        'timer_resume','timer_stop','set_batch_target','add_need_more','remove_item'];
    if(daily_quicklist_can_manage($actor))
        array_push($actions,'set_status','set_manager_note','set_requested_amount','notify_managers');
    if(fm8d18j11_can_receipts($actor))array_push($actions,'link_confirmed_receipt','set_requested_stock_quantity');
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
    if($action==='set_requested_amount'){
        if($list!=='inventory')$missing[]='list';
        if(!array_key_exists('note',$args)||!is_string($args['note'])
            ||mb_strlen(trim((string)$args['note']),'UTF-8')>120)$missing[]='note';
    }
    if($action==='set_requested_stock_quantity'){
        if($list!=='inventory')$missing[]='list';
        if(!is_int($args['quantity']??null)&&!is_float($args['quantity']??null)
            ||!is_finite((float)($args['quantity']??0))
            ||(float)($args['quantity']??0)<0.001||(float)($args['quantity']??0)>1000000
            ||abs((float)($args['quantity']??0)-round((float)($args['quantity']??0),3))>0.0000001)
            $missing[]='quantity';
        if(isset($args['unit'])&&(!is_string($args['unit'])||mb_strlen($args['unit'],'UTF-8')>40))
            $missing[]='unit';
    }
    if($action==='link_confirmed_receipt'){
        if($list!=='inventory'||$mode==='none')$missing[]='list';
        if(!is_int($args['receipt_number']??null)
            ||$args['receipt_number']<1||$args['receipt_number']>20)$missing[]='receipt_number';
        if(array_key_exists('quantity',$args)&&(!is_int($args['quantity'])&&!is_float($args['quantity'])
            ||!is_finite((float)$args['quantity'])||(float)$args['quantity']<0.001
            ||(float)$args['quantity']>1000000
            ||abs((float)$args['quantity']-round((float)$args['quantity'],3))>0.0000001))
            $missing[]='quantity';
    }
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
    if($action==='set_requested_amount')return $intent+['op'=>'update','quantityNote'=>trim((string)$args['note'])];
    if($action==='set_requested_stock_quantity')return $intent+['op'=>'update',
        'requestedStockQuantity'=>(float)$args['quantity'],'unit'=>(string)($args['unit']??'')];
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
            if($action==='link_confirmed_receipt'){
                $receiptId=pqa_receipt_context_resolve($org,$uid,$threadId,(string)$row['id'],
                    (int)$args['receipt_number']);
                $intent=['op'=>'reconcile_receipt','kind'=>'inventory','target'=>$row['title'],
                    'resolvedItemId'=>$row['id'],'receiptTransactionId'=>$receiptId];
                if(array_key_exists('quantity',$args))$intent['allocatedQuantity']=(float)$args['quantity'];
            }else $intent=fm8d18j11_intent($args,$row);
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
    $tools=[
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
            'description'=>'ONLY when the employee explicitly asks to change Daily Prep or Need More. Choose one item by exact name, ordinal from a previously displayed numbered list, or focused "that item" after a prior selection. The model NEVER supplies item IDs. Use reference_mode none only for generate_menu or adding a named Need More request. For recipe targets use saved yield. Manager status, internal notes, requested Need More amounts, and notices require management permission. The note field carries the exact requested amount for set_requested_amount. For set_requested_stock_quantity, use an explicit numeric target in the stock item's saved base unit (not a free-form note). For link_confirmed_receipt, first call get_need_more_receipts for the same named item, then use receipt_number from that numbered, expiring, session-bound list. This allocates an EXISTING physical receipt toward the saved numeric target, possibly leaving an outstanding balance; legacy unquantified requests close with one full receipt. The optional quantity is the explicitly requested allocation in canonical units. It NEVER receives stock twice. Every change stages an expiring Prep Agent proposal for separate Confirm with fresh checks. No stock adjustments or master recipe edits. Unverified voice cannot use it.',
            'parameters'=>['type'=>'object','properties'=>[
                'action'=>['type'=>'string','enum'=>$actions],
                'list'=>['type'=>'string','enum'=>['prep','inventory'],
                    'description'=>'prep for dated checklist; inventory for Need More list'],
                'reference_mode'=>['type'=>'string','enum'=>['name','ordinal','focused','none']],
                'name'=>['type'=>'string','description'=>'Exact requested item name, or new Need More title; never invent one'],
                'ordinal'=>['type'=>'integer','minimum'=>1,'maximum'=>20,
                    'description'=>'1-based number from a previously displayed list in the same conversation'],
                'receipt_number'=>['type'=>'integer','minimum'=>1,'maximum'=>20,
                    'description'=>'Number from a previous get_need_more_receipts result in this same user conversation; never a database transaction ID'],
                'date'=>['type'=>'string','description'=>'Optional restaurant-local YYYY-MM-DD; omit for today'],
                'quantity'=>['type'=>'number','exclusiveMinimum'=>0,'maximum'=>1000000,
                    'description'=>'Explicit recipe batch target for set_batch_target (max 10000), numeric Need More target for set_requested_stock_quantity, or optional partial receipt allocation for link_confirmed_receipt. Never infer numbers from free-form notes.'],
                'unit'=>['type'=>'string','description'=>'Optional supplied saved recipe yield unit, must match canonical unit'],
                'status'=>['type'=>'string','enum'=>['todo','in_progress','paused','done','need_more','ordered','received']],
                'note'=>['type'=>'string','maxLength'=>2000,
                    'description'=>'Explicit manager note, Need More quantity request, or new requested amount; never invent a unit or quantity'],
            ],'required'=>['action','list','reference_mode'],'additionalProperties'=>false],
            'allowedArguments'=>['action','list','reference_mode','name','ordinal','receipt_number','date','quantity','unit','status','note'],
            'impact'=>'read',
            'authorize'=>static fn(array $user,array $a):bool=>fm8d18j11_can($user)
                &&fm8d18j11_action_fields($a,$user)===[],
            'validate'=>static fn(array $a,array $user):array=>fm8d18j11_action_fields($a,$user),
            'execute'=>static fn(array $a,array $user):array=>fm8d18j11_propose($a,$user,$context),
        ],
    ];
    if(app_has_permission('inventory.view',$actor)||app_has_permission('inventory.manage',$actor)){
        $tools['get_need_more_stock_insights']=[
            'description'=>'Read real inventory items AT or BELOW their saved reorder point, tell which are already on Need More and which exact-name ingredient appears in active dated prep recipes when Recipe View is authorized. No inferred purchase amounts, unit conversions, or physical stock changes. Use when asked what is low for prep, what is below par or reorder level, or which low-stock ingredients affect current prep. Date optional, defaults to restaurant-local today.',
            'parameters'=>['type'=>'object','properties'=>[
                'date'=>['type'=>'string','description'=>'Optional YYYY-MM-DD prep date; omit for today'],
            ],'additionalProperties'=>false],
            'allowedArguments'=>['date'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $a):bool=>fm8d18j11_can($user)
                &&(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user)),
            'validate'=>static function(array $a,array $user):array{
                try{fm8d18j11_date($a);return [];}catch(InvalidArgumentException){return ['date'];}
            },
            'execute'=>static fn(array $a,array $user):array=>fm8d18j11_stock_read($a,$user,$context),
        ];
    }
    if(fm8d18j11_can_receipts($actor)){
        $tools['get_need_more_receipts']=[
            'description'=>'For a manager, read eligible ALREADY-RECORDED stock receipts, current numeric Need More target, already allocated and outstanding quantities in the exact saved stock unit. Return numbered receipt amounts and timestamps only; save a 15-minute user/tenant/conversation/item selection. Never supply raw transaction IDs. A separate Confirm links an existing receipt; partial allocations leave a visible balance, never change physical stock.',
            'parameters'=>['type'=>'object','properties'=>[
                'name'=>['type'=>'string','description'=>'Exact existing Need More item name'],
                'date'=>['type'=>'string','description'=>'Optional YYYY-MM-DD; defaults to restaurant-local today'],
            ],'required'=>['name'],'additionalProperties'=>false],
            'allowedArguments'=>['name','date'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $a):bool=>fm8d18j11_can_receipts($user),
            'validate'=>static function(array $a,array $user):array{
                $invalid=[];
                if(!is_string($a['name']??null)||trim((string)$a['name'])===''||mb_strlen((string)$a['name'],'UTF-8')>220)$invalid[]='name';
                try{fm8d18j11_date($a);}catch(InvalidArgumentException){$invalid[]='date';}
                return $invalid;
            },
            'execute'=>static fn(array $a,array $user):array=>fm8d18j11_receipts_read($a,$user,$context),
        ];
    }
    return $tools;
}
