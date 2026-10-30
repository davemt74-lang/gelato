<?php
declare(strict_types=1);

/**
 * FM-B8D18A: LLM-selected proposals for exact restaurant stock adjustments and
 * prep tasks. These tools do not write business rows. All model arguments are
 * untrusted, scoped to the authenticated tenant, and confirmed by the existing
 * Operations Agent with its own fresh permission and stale-record checks.
 */
require_once __DIR__.'/operations-agent-inventory-actions.php';
require_once __DIR__.'/agent-confirmation-core.php';
require_once __DIR__.'/operations-agent-task-actions.php';
require_once __DIR__.'/operations-core.php';

function fm8d18_can(array $actor,string $area):bool
{
    $required=$area==='inventory'
        ?['inventory.agent','inventory.view','inventory.manage']
        :['tasks.agent','tasks.view','tasks.manage'];
    foreach($required as $permission)
        if(!app_has_permission($permission,$actor))return false;
    return (int)($actor['organization_id']??0)>0&&(int)($actor['id']??0)>0;
}

function fm8d18_stock_fields(array $args):array
{
    $missing=[];
    $name=$args['item_name']??null;
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>220
       ||preg_match('/[;\r\n]/u',$name))$missing[]='item_name';
    if(!in_array($args['action']??null,['receive','use','waste','count'],true))
        $missing[]='action';
    $value=$args['quantity']??null;
    if((!is_float($value)&&!is_int($value))||!is_finite((float)$value)
       ||$value<0||$value>10000000||round((float)$value,4)!==(float)$value
       ||(($args['action']??'')!=='count'&&$value<=0))
        $missing[]='quantity';
    $raw=$args['unit']??null;
    $unit=is_string($raw)?ops_agent_inventory_unit($raw):'';
    if(!in_array($unit,['lb','oz','g','kg','l','ml','ea','case','bag','box',
        'bottle','gal','qt','pan','tray','batch','container','dozen','cup'],true))
        $missing[]='unit';
    return $missing;
}

function fm8d18_prep_fields(array $args):array
{
    $items=$args['items']??null;
    if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>30)
        return ['items'];
    $seen=[];
    foreach($items as $item){
        if(!is_string($item)||trim($item)===''
           ||mb_strlen(trim($item),'UTF-8')>240||preg_match('/[;\r\n]/u',$item))
            return ['items'];
        $normalized=mb_strtolower(trim($item),'UTF-8');
        if(isset($seen[$normalized]))return ['items'];
        $seen[$normalized]=true;
    }
    return [];
}


function fm8d18_new_item_fields(array $args):array
{
    $missing=[];
    $name=$args['item_name']??null;
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>220
       ||preg_match('/[;\\r\\n]/u',$name))$missing[]='item_name';
    $raw=$args['unit']??null;
    $unit=is_string($raw)?ops_agent_inventory_unit($raw):'';
    if(!in_array($unit,['lb','oz','g','kg','l','ml','ea','case','bag','box',
        'bottle','gal','qt','pan','tray','batch','container','dozen','cup'],true))
        $missing[]='unit';
    $quantity=$args['starting_quantity']??null;
    if((!is_float($quantity)&&!is_int($quantity))||!is_finite((float)$quantity)
       ||$quantity<0||$quantity>10000000||round((float)$quantity,4)!==(float)$quantity)
        $missing[]='starting_quantity';
    $category=$args['category']??'';
    if(!is_string($category)||mb_strlen(trim($category),'UTF-8')>100
       ||preg_match('/[;\\r\\n]/u',(string)$category))$missing[]='category';
    return $missing;
}

function fm8d18_task_status_fields(array $args):array
{
    $missing=[];
    $name=$args['task_title']??null;
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[;\\r\\n]/u',$name))$missing[]='task_title';
    if(!in_array($args['status']??null,['in_progress','completed','blocked','accepted'],true))
        $missing[]='status';
    return $missing;
}

function fm8d18_propose(string $type,array $payload,string $summary,array $actor):array
{
    $org=(int)$actor['organization_id'];$user=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())
        throw new RuntimeException('Could not start the secure Operations confirmation session.');
    try{
        if(gac_pending_get('operations',$org,$user)!==null)
            throw new InvalidArgumentException('There is already a pending Operations action. Confirm or Cancel it before requesting a new one.');
        $pending=gac_pending_store('operations',$org,$user,$type,$payload,$summary);
        app_audit(app_pdo(),$org,$user,'operations.agent_action_proposed',
            'operations_agent_proposal',(string)$pending['id'],null,
            ['type'=>$type,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply this change, or Cancel to discard it. Nothing has been changed yet.",
            'source'=>'Gelato Operations (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$type],
        ]];
    }finally{
        if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
    }
}

function fm8d18_catalogue(array $principal):array
{
    $tools=[];
    if(fm8d18_can($principal,'inventory')){
        $tools['propose_inventory_stock_change']=[
            'description'=>'ONLY when the user explicitly requests one stock action: receive, use, waste or count a specified quantity of one EXISTING inventory item. Interpret natural spoken or typed language, using exact saved item name and saved base unit; if any detail is missing, ask. This only stages a confirmation proposal. Do not use for new inventory-item creation, hypotheticals, or prep-list requests. Never guess quantities, names or units.',
            'parameters'=>['type'=>'object','properties'=>[
                'item_name'=>['type'=>'string','description'=>'Exact complete name of one existing stock item'],
                'action'=>['type'=>'string','enum'=>['receive','use','waste','count']],
                'quantity'=>['type'=>'number','description'=>'Nonnegative numeric quantity, positive except a zero count'],
                'unit'=>['type'=>'string','description'=>'Exact saved inventory base unit; no unit conversion'],
            ],'required'=>['item_name','action','quantity','unit'],'additionalProperties'=>false],
            'allowedArguments'=>['item_name','action','quantity','unit'],
            'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18_can($actor,'inventory'),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18_stock_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18_can($actor,'inventory'))
                    throw new DomainException('Inventory management permission is required.');
                if($invalid=fm8d18_stock_fields($args))
                    throw new InvalidArgumentException('Provide an exact item, stock action, quantity and saved unit: '.implode(', ',$invalid).'.');
                $org=(int)$actor['organization_id'];$user=(int)$actor['id'];
                $intent=['name'=>trim($args['item_name']),
                    'type'=>$args['action'],'quantity'=>(float)$args['quantity'],
                    'unit'=>ops_agent_inventory_unit($args['unit'])];
                $payload=ops_agent_inventory_prepare(app_pdo(),$org,$intent);
                $summary='Proposed '.$payload['type'].' for “'.$payload['name'].'”: '.
                    number_format((float)$payload['expectedOnHand'],4).' → '.
                    number_format((float)$payload['resultOnHand'],4).' '.$payload['unit'].
                    '. Exact item and saved unit verified.';
                return fm8d18_propose('inventory_adjust',$payload,$summary,$actor);
            },
        ];
    }
    if(fm8d18_can($principal,'tasks')){
        $tools['propose_prep_tasks']=[
            'description'=>'ONLY when the user explicitly asks to add one or more concrete items to the restaurant prep task list. Convert a natural typed/spoken list into exact individual prep-task names. Do not invent or infer omitted tasks. If there are no specified tasks, ask which items. This stages one proposal for confirmation and does not create any tasks. Employee assignment is not supported by this tool; use the existing Operations Agent if assignment is requested.',
            'parameters'=>['type'=>'object','properties'=>[
                'items'=>['type'=>'array','items'=>['type'=>'string'],
                    'description'=>'1 to 30 explicitly named prep tasks, one plain string each'],
            ],'required'=>['items'],'additionalProperties'=>false],
            'allowedArguments'=>['items'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18_can($actor,'tasks'),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18_prep_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18_can($actor,'tasks'))
                    throw new DomainException('Task management permission is required.');
                if(fm8d18_prep_fields($args))
                    throw new InvalidArgumentException('Specify 1 to 30 concrete prep tasks, each no longer than 240 characters.');
                $items=array_values(array_map('trim',$args['items']));
                $summary='Proposed action: add '.count($items).' item(s) to the restaurant prep task list: '.
                    implode(', ',array_slice($items,0,8)).(count($items)>8?'…':'').'.';
                return fm8d18_propose('prep_tasks',
                    ['items'=>$items,'assigneeIds'=>[],'transcript'=>'Food Monster model-selected prep proposal'],
                    $summary,$actor);
            },
        ];
    }

    if(fm8d18_can($principal,'inventory')){
        $tools['propose_inventory_item_creation']=[
            'description'=>'ONLY for an explicit request to create a NEW inventory catalog record, not for receiving stock into an existing item. Require one full item name, saved standard base unit and starting on-hand quantity. The user must separately confirm the proposed item creation; no stock or catalog record is saved by this tool. Do not invent a unit or starting quantity.',
            'parameters'=>['type'=>'object','properties'=>[
                'item_name'=>['type'=>'string','description'=>'Complete name for the new item'],
                'unit'=>['type'=>'string','description'=>'Standard saved base unit'],
                'starting_quantity'=>['type'=>'number','description'=>'Initial on-hand quantity; zero is allowed'],
                'category'=>['type'=>'string','description'=>'Optional explicit inventory category'],
            ],'required'=>['item_name','unit','starting_quantity'],'additionalProperties'=>false],
            'allowedArguments'=>['item_name','unit','starting_quantity','category'],
            'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18_can($actor,'inventory'),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18_new_item_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18_can($actor,'inventory'))
                    throw new DomainException('Inventory management permission is required.');
                if($missing=fm8d18_new_item_fields($args))
                    throw new InvalidArgumentException('Provide a valid new item name, unit and initial quantity: '.implode(', ',$missing).'.');
                $intent=['name'=>trim($args['item_name']),
                    'unit'=>ops_agent_inventory_unit($args['unit']),
                    'quantity'=>(float)$args['starting_quantity'],
                    'category'=>trim((string)($args['category']??''))];
                $payload=ops_agent_inventory_new_prepare(app_pdo(),(int)$actor['organization_id'],$intent);
                $summary='Proposed NEW inventory record “'.$payload['name'].'”, base unit '.$payload['unit'].
                    ', starting stock '.number_format((float)$payload['quantity'],4).
                    ($payload['category']!==''?', category '.$payload['category']:'').'. No item has been created.';
                return fm8d18_propose('inventory_create',$payload,$summary,$actor);
            },
        ];
    }
    if(fm8d18_can($principal,'tasks')){
        $tools['propose_task_status_change']=[
            'description'=>'ONLY for an explicit request to change the status of one EXISTING restaurant task with an exact complete task title. Supported statuses are in_progress, completed, blocked and accepted. Never guess a task title or bulk-update. The existing Operations Agent verifies the task is active, uniquely matched and free from required proof before asking for separate confirmation.',
            'parameters'=>['type'=>'object','properties'=>[
                'task_title'=>['type'=>'string','description'=>'Exact complete saved title of one restaurant task'],
                'status'=>['type'=>'string','enum'=>['in_progress','completed','blocked','accepted']],
            ],'required'=>['task_title','status'],'additionalProperties'=>false],
            'allowedArguments'=>['task_title','status'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18_can($actor,'tasks'),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18_task_status_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18_can($actor,'tasks'))
                    throw new DomainException('Task management permission is required.');
                if($missing=fm8d18_task_status_fields($args))
                    throw new InvalidArgumentException('Specify one exact task and allowed status: '.implode(', ',$missing).'.');
                $payload=ops_agent_task_status_prepare(app_pdo(),(int)$actor['organization_id'],
                    ['name'=>trim($args['task_title']),'status'=>$args['status']]);
                $summary='Proposed task status change for “'.$payload['title'].'”: '.
                    str_replace('_',' ',$payload['expectedStatus']).' → '.
                    str_replace('_',' ',$payload['status']).'. Confirm to apply.';
                return fm8d18_propose('task_status_change',$payload,$summary,$actor);
            },
        ];
    }

    return $tools;
}
