<?php
declare(strict_types=1);

/** B8D18J8A: minimal, permission-scoped Wholesale fulfillment reads. */
function fm8d18j8a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('wholesale.view',$actor)
        &&app_has_permission('wholesale.agent',$actor);
}
function fm8d18j8a_id(mixed $value):bool
{
    return is_string($value)&&strlen($value)>=2&&strlen($value)<=120
        &&preg_match('/^[A-Za-z0-9_.:-]+$/D',$value)===1;
}
function fm8d18j8a_search_fields(array $args):array
{
    $query=$args['query']??null;
    return !is_string($query)||mb_strlen(trim($query),'UTF-8')<2
        ||mb_strlen(trim($query),'UTF-8')>120
        ||preg_match('/[%_;\\x00-\\x1F]/u',$query)?['query']:[];
}
function fm8d18j8a_order_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['order_public_id']??null))$missing[]='order_public_id';
    if(!in_array($args['section']??null,['overview','lines','batches','shortages'],true))$missing[]='section';
    return $missing;
}
function fm8d18j8a_batch_fields(array $args):array
{
    $missing=[];
    foreach(['order_public_id','batch_public_id'] as $key)
        if(!fm8d18j8a_id($args[$key]??null))$missing[]=$key;
    if(!in_array($args['section']??null,['status','items','stock'],true))$missing[]='section';
    return $missing;
}
function fm8d18j8a_search(array $args,array $actor):array
{
    if(!fm8d18j8a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if(fm8d18j8a_search_fields($args))throw new InvalidArgumentException('Provide a specific saved Wholesale order number or business name.');
    require_once __DIR__.'/wholesale-fulfillment.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $query=trim((string)$args['query']);
    $q=$pdo->prepare("SELECT o.public_id,o.order_number,o.status,o.promised_for,a.business_name
        FROM wholesale_orders o JOIN wholesale_accounts a
          ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id
        WHERE o.organization_id=? AND a.archived_at IS NULL
          AND (o.public_id=? OR LOCATE(LOWER(?),LOWER(o.order_number))>0
               OR LOCATE(LOWER(?),LOWER(a.business_name))>0)
        ORDER BY o.created_at DESC,o.id DESC LIMIT 9");
    $q->execute([$org,$query,$query,$query]);$all=$q->fetchAll();
    $rows=array_map(static fn(array $r):array=>[
        'orderPublicId'=>(string)$r['public_id'],'orderNumber'=>(string)$r['order_number'],
        'businessName'=>(string)$r['business_name'],'status'=>(string)$r['status'],
        'promisedFor'=>$r['promised_for'],
    ],array_slice($all,0,8));
    $parts=[];foreach($rows as $r)$parts[]=$r['orderNumber'].' ['.$r['orderPublicId'].'] · '.$r['businessName'].' · '.$r['status'];
    $answer=$parts?'Saved Wholesale orders: '.implode('; ',$parts).'.':'No saved Wholesale order matched.';
    if(count($all)>8)$answer.=' More matches exist; narrow the search.';
    if(count($rows)>1)$answer.=' Select one exact order public ID.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1500,'UTF-8'),'source'=>'Gelato Wholesale Fulfillment',
        'readOnly'=>true,'matches'=>$rows,'truncated'=>count($all)>8,
    ]];
}
function fm8d18j8a_order(array $args,array $actor):array
{
    if(!fm8d18j8a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if($missing=fm8d18j8a_order_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-fulfillment.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $order=wholesale_fulfillment_order($pdo,$org,(string)$args['order_public_id']);
    $section=(string)$args['section'];$rows=[];$lines=[];$all=[];
    if($section==='overview'){
        $progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);
        $rows=['status'=>$order['status'],'orderNumber'=>$order['order_number'],
            'allocationPercent'=>$progress['allocationPercent'],'deliveryPercent'=>$progress['deliveryPercent'],
            'ordered'=>$progress['ordered'],'allocated'=>$progress['allocated'],'delivered'=>$progress['delivered'],
            'promisedFor'=>$order['promised_for'],'promisedWindowStart'=>$order['promised_window_start']??null];
        $lines[]='status '.$order['status'].' · '.$progress['allocationPercent'].'% allocated · '.$progress['deliveryPercent'].'% delivered';
    }elseif($section==='lines'){
        $all=wholesale_fulfillment_order_lines($pdo,$org,(int)$order['id']);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['lineNumber'=>$r['lineNumber'],'name'=>$r['name'],'sku'=>$r['sku'],
                'ordered'=>$r['ordered'],'allocated'=>$r['allocated'],'delivered'=>$r['delivered'],
                'remainingToAllocate'=>$r['remainingToAllocate'],'unit'=>$r['unit']];
            $lines[]='#'.$r['lineNumber'].' '.$r['name'].' · '.$r['allocated'].'/'.$r['ordered'].' '.$r['unit'].' allocated';
        }
    }elseif($section==='batches'){
        $all=wholesale_fulfillment_batches($pdo,$org,(int)$order['id']);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['batchPublicId'=>$r['id'],'batchNumber'=>$r['number'],
                'status'=>$r['status'],'fulfillmentType'=>$r['fulfillmentType'],'items'=>count($r['items'])];
            $lines[]=$r['number'].' ['.$r['id'].'] · '.$r['status'].' · '.count($r['items']).' lines';
        }
    }else{
        $availability=wholesale_fulfillment_order_availability($pdo,$org,$order);
        $all=array_values(array_filter((array)$availability['items'],static fn($r)=>(float)$r['shortage']>.0001));
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['name'=>$r['name'],'unit'=>$r['unit'],'onHand'=>$r['onHand'],
                'remainingDemand'=>$r['remainingDemand'],'shortage'=>$r['shortage']];
            $lines[]=$r['name'].' short '.$r['shortage'].' '.$r['unit'];
        }
        if($availability['issues'])$lines[]='Demand mapping issues: '.count($availability['issues']).'.';
    }
    $answer=$order['order_number'].' ('.$order['public_id'].') · '.$order['business_name'].' · '.$section.': '.($lines?implode('; ',$lines):'No recorded entries.');
    if(count($all)>10)$answer.=' More records exist; first ten shown.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1800,'UTF-8'),'source'=>'Gelato Wholesale Fulfillment',
        'readOnly'=>true,'orderPublicId'=>(string)$order['public_id'],
        'orderNumber'=>(string)$order['order_number'],'section'=>$section,
        'records'=>$rows,'truncated'=>count($all)>10||mb_strlen($answer,'UTF-8')>1800,
    ]];
}
function fm8d18j8a_batch(array $args,array $actor):array
{
    if(!fm8d18j8a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if($missing=fm8d18j8a_batch_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-fulfillment.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $batch=wholesale_fulfillment_batch($pdo,$org,(string)$args['batch_public_id']);
    if((string)$batch['order_public_id']!==(string)$args['order_public_id'])
        throw new InvalidArgumentException('That batch does not belong to the selected Wholesale order.');
    $section=(string)$args['section'];$rows=[];$lines=[];$all=[];
    if($section==='status'){
        $rows=['status'=>$batch['status'],'batchNumber'=>$batch['fulfillment_number'],
            'orderNumber'=>$batch['order_number'],'orderStatus'=>$batch['order_status'],
            'fulfillmentType'=>$batch['fulfillment_type'],'promisedWindowStart'=>$batch['promised_window_start']];
        $lines[]=$batch['fulfillment_number'].' · '.$batch['status'].' · order '.$batch['order_status'].' · '.$batch['fulfillment_type'];
    }elseif($section==='items'){
        $all=wholesale_fulfillment_batch_items($pdo,$org,(int)$batch['id']);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['lineNumber'=>(int)$r['line_number'],'name'=>$r['item_name_snapshot'],
                'quantity'=>(float)$r['quantity'],'unit'=>$r['sell_uom_snapshot']];
            $lines[]='#'.$r['line_number'].' '.$r['item_name_snapshot'].' · '.$r['quantity'].' '.$r['sell_uom_snapshot'];
        }
    }else{
        $availability=wholesale_fulfillment_batch_availability($pdo,$org,$batch);
        $all=(array)$availability['items'];
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['name'=>$r['name'],'required'=>$r['required'],
                'onHand'=>$r['onHand'],'shortage'=>$r['shortage'],'unit'=>$r['unit']];
            $lines[]=$r['name'].' · needs '.$r['required'].' '.$r['unit'].' · short '.$r['shortage'];
        }
        if($availability['issues'])$lines[]='Demand mapping issues: '.count($availability['issues']).'.';
    }
    $answer=$batch['fulfillment_number'].' ('.$batch['public_id'].') · '.$section.': '.($lines?implode('; ',$lines):'No recorded entries.');
    if(count($all)>10)$answer.=' More records exist; first ten shown.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1800,'UTF-8'),'source'=>'Gelato Wholesale Fulfillment',
        'readOnly'=>true,'batchPublicId'=>(string)$batch['public_id'],
        'batchNumber'=>(string)$batch['fulfillment_number'],'orderPublicId'=>(string)$batch['order_public_id'],
        'section'=>$section,'records'=>$rows,
        'truncated'=>count($all)>10||mb_strlen($answer,'UTF-8')>1800,
    ]];
}
function fm8d18j8a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j8a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'search_wholesale_orders'=>[
            'description'=>'Find exact saved Wholesale order numbers, public IDs or business names. Read-only, tenant-scoped, up to eight minimal identities, without customer contact data. Do not guess IDs; ask to select one.',
            'parameters'=>['type'=>'object','properties'=>[
                'query'=>['type'=>'string','description'=>'Specific order number, saved public ID or business name'],
            ],'required'=>['query'],'additionalProperties'=>false],
            'allowedArguments'=>['query'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8a_search_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8a_search($args,$user),
        ],
        'read_wholesale_order'=>[
            'description'=>'Read ONE section of ONE exact saved Wholesale order: overview, item lines and unallocated amounts, fulfillment batches, or projected inventory shortages. Read-only and no contact details. Never invent an order public ID.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale order public ID'],
                'section'=>['type'=>'string','enum'=>['overview','lines','batches','shortages']],
            ],'required'=>['order_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8a_order_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8a_order($args,$user),
        ],
        'read_wholesale_batch'=>[
            'description'=>'Read ONE section of ONE exact saved fulfillment batch belonging to an exact Wholesale order: status, item lines, or inventory availability. Read-only. Always match saved batch and order public IDs.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact parent Wholesale order public ID'],
                'batch_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale fulfillment batch public ID'],
                'section'=>['type'=>'string','enum'=>['status','items','stock']],
            ],'required'=>['order_public_id','batch_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','batch_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8a_batch_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8a_batch($args,$user),
        ],
    ];
}
