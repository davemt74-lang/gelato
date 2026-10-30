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
    return $tools;
}
