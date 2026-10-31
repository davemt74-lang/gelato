<?php
declare(strict_types=1);

/**
 * FM-B8D18C: model-selected purchasing proposals. Every tool is preparation
 * only: the existing Purchasing Agent owns final confirmation, fresh tenant/
 * permission checks, stale-order checks, and Super Admin purchase approvals.
 */
// Do not load purchasing-agent-core.php into the main chat process: that
// legacy specialist and prep-agent-core.php both declare pac_require().
// Stage its documented session contract without loading its execution code.
require_once __DIR__.'/purchasing-orders.php';
require_once __DIR__.'/agent-node-registry.php';

function fm8d18p_suggestion_lines(PDO $pdo,int $org,string $vendorId):array
{
    $lines=[];
    foreach(purchasing_suggestions($pdo,$org) as $row){
        if((string)($row['vendor']['id']??'')!==$vendorId)continue;
        $lines[]=['inventoryId'=>(string)$row['inventoryId'],
            'name'=>(string)$row['name'],'packs'=>(float)$row['suggestedPacks'],
            'packSize'=>(float)($row['vendor']['packSize']??1),
            'pricePerPack'=>$row['vendor']['pricePerPack']??null];
    }
    usort($lines,static fn($a,$b)=>strcmp($a['inventoryId'],$b['inventoryId']));
    return $lines;
}

function fm8d18p_lines_signature(array $lines):string
{
    $normalized=[];
    foreach($lines as $line)$normalized[]=[
        'inventoryId'=>(string)($line['inventoryId']??''),
        'packs'=>round((float)($line['packs']??0),4),
        'packSize'=>round((float)($line['packSize']??0),4),
        'pricePerPack'=>isset($line['pricePerPack'])&&$line['pricePerPack']!==null
            ?round((float)$line['pricePerPack'],4):null];
    return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
}

function fm8d18p_order_version(array $po):array
{
    return ['status'=>(string)($po['status']??''),
        'updatedAt'=>(string)($po['updated_at']??'')];
}

function fm8d18p_outstanding_lines(array $po):array
{
    $lines=[];
    foreach((array)($po['items']??[]) as $item){
        $remaining=max(0,(float)$item['ordered_base_quantity']-(float)$item['received_base_quantity']);
        if($remaining>0.0001)$lines[]=['itemId'=>(string)$item['public_id'],
            'inventoryId'=>(string)$item['inventory_public_id'],
            'name'=>(string)$item['inventory_name'],'quantity'=>$remaining];
    }
    return $lines;
}

function fm8d18p_outstanding_signature(array $lines):string
{
    $normalized=[];
    foreach($lines as $line)$normalized[]=[
        'itemId'=>(string)$line['itemId'],
        'quantity'=>round((float)$line['quantity'],4)];
    return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
}

function fm8d18p_pending_get(int $org,int $uid):?array
{
    $key=$org.':'.$uid;
    $row=$_SESSION['purchasing_agent_pending'][$key]??null;
    if(!is_array($row)||($row['expires']??0)<time()){
        unset($_SESSION['purchasing_agent_pending'][$key]);return null;
    }
    return $row;
}

function fm8d18p_pending_clear(int $org,int $uid):void
{
    unset($_SESSION['purchasing_agent_pending'][$org.':'.$uid]);
}

function fm8d18p_pending_store(int $org,int $uid,string $type,array $payload,string $summary):array
{
    $row=['id'=>'pap-'.bin2hex(random_bytes(8)),
        'organizationId'=>$org,'userId'=>$uid,'type'=>$type,'payload'=>$payload,
        'summary'=>$summary,'expires'=>time()+600];
    $_SESSION['purchasing_agent_pending'][$org.':'.$uid]=$row;
    return $row;
}

function fm8d18p_can(array $actor,string $action):bool
{
    $permissions=['purchasing.agent','purchasing.view',
        $action==='receive'?'receiving.manage':'purchasing.manage'];
    if((int)($actor['id']??0)<1||(int)($actor['organization_id']??0)<1)return false;
    foreach($permissions as $permission)
        if(!app_has_permission($permission,$actor))return false;
    return true;
}

function fm8d18p_fields(array $args,string $name):array
{
    $raw=$args[$name]??null;
    if(!is_string($raw)||trim($raw)===''||mb_strlen(trim($raw),'UTF-8')>180
       ||preg_match('/[;\r\n]/u',$raw))return [$name];
    return [];
}

function fm8d18p_exact_vendor(PDO $pdo,int $org,string $name):array
{
    $matched=[];
    $needle=mb_strtolower(trim($name),'UTF-8');
    foreach(purchasing_vendors($pdo,$org) as $vendor)
        if(mb_strtolower(trim((string)$vendor['name']),'UTF-8')===$needle)
            $matched[]=$vendor;
    if(count($matched)!==1)
        throw new InvalidArgumentException('No unique exact vendor match. Use the saved vendor name from Purchasing.');
    if((string)($matched[0]['status']??'')!=='active')
        throw new InvalidArgumentException('That vendor is inactive. No purchase order was proposed.');
    return $matched[0];
}

function fm8d18p_exact_order(PDO $pdo,int $org,string $number):array
{
    $matched=[];
    $needle=mb_strtolower(trim($number),'UTF-8');
    foreach(purchasing_orders($pdo,$org) as $order)
        if(mb_strtolower(trim((string)$order['order_number']),'UTF-8')===$needle
           ||mb_strtolower(trim((string)$order['public_id']),'UTF-8')===$needle)
            $matched[]=$order;
    if(count($matched)!==1)
        throw new InvalidArgumentException('No unique exact purchase order match. Use the saved PO number.');
    $po=purchasing_order($pdo,$org,(string)$matched[0]['public_id']);
    if(!$po)throw new InvalidArgumentException('The selected purchase order is no longer available.');
    return $po;
}

function fm8d18p_propose(array $actor,string $type,array $payload,string $summary):array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())
        throw new RuntimeException('Could not start the secure Purchasing confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||fm8d18p_pending_get($org,$uid)!==null)
            throw new InvalidArgumentException('There is already a pending restaurant action. Confirm or Cancel it before requesting another.');
        $proposal=fm8d18p_pending_store($org,$uid,$type,$payload,$summary);
        app_audit(app_pdo(),$org,$uid,'purchasing.agent_action_proposed',
            'purchasing_agent_proposal',(string)$proposal['id'],null,
            ['type'=>$type,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to proceed through the Purchasing Agent's existing approval workflow, or Cancel to discard it. Nothing has been changed yet.",
            'source'=>'Gelato Purchasing (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$proposal['id'],'type'=>$type],
        ]];
    }finally{
        if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
    }
}

function fm8d18p_catalogue(array $actor):array
{
    $tools=[];
    if(fm8d18p_can($actor,'manage')){
        $tools['propose_purchase_order_draft']=[
            'description'=>'ONLY when the user explicitly requests a DRAFT purchase order for one exact saved active vendor using its CURRENT saved purchasing suggestions. This creates no order until separate confirmation; never infer quantities or custom lines. If the user specified custom quantities, ask to use the Purchasing Agent instead.',
            'parameters'=>['type'=>'object','properties'=>[
                'vendor_name'=>['type'=>'string','description'=>'Exact saved active vendor name; do not guess or abbreviate'],
            ],'required'=>['vendor_name'],'additionalProperties'=>false],
            'allowedArguments'=>['vendor_name'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18p_can($user,'manage'),
            'validate'=>static fn(array $args,array $user):array=>fm8d18p_fields($args,'vendor_name'),
            'execute'=>static function(array $args,array $user):array{
                if(!fm8d18p_can($user,'manage'))throw new DomainException('Purchasing management permission is required.');
                if(fm8d18p_fields($args,'vendor_name'))throw new InvalidArgumentException('Provide an exact saved vendor name.');
                $pdo=app_pdo();$org=(int)$user['organization_id'];
                $vendor=fm8d18p_exact_vendor($pdo,$org,(string)$args['vendor_name']);
                $lines=fm8d18p_suggestion_lines($pdo,$org,(string)$vendor['public_id']);
                if(!$lines)throw new InvalidArgumentException('No current purchasing suggestions are mapped to that vendor.');
                $estimated=0.0;foreach($lines as $line)
                    if($line['pricePerPack']!==null)$estimated+=(float)$line['pricePerPack']*(float)$line['packs'];
                $summary='Proposed DRAFT PO for '.$vendor['name'].' from '.count($lines).
                    ' current suggested line(s), estimated $'.number_format($estimated,2).
                    '. The Purchasing Agent will recheck suggestions at confirmation.';
                return fm8d18p_propose($user,'draft_po',
                    ['vendorId'=>$vendor['public_id'],'signature'=>fm8d18p_lines_signature($lines)],$summary);
            },
        ];
        $tools['propose_purchase_order_status']=[
            'description'=>'ONLY when the user explicitly requests to SUBMIT or CANCEL one exact existing purchase order by its saved PO number. A submit is an owner-approved high-impact commitment: ordinary Confirm alone cannot bypass the Super Admin grant. This tool only stages the existing Purchasing Agent proposal. Never infer an order number or change multiple orders.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_number'=>['type'=>'string','description'=>'Exact saved purchase order number'],
                'action'=>['type'=>'string','enum'=>['submit','cancel']],
            ],'required'=>['order_number','action'],'additionalProperties'=>false],
            'allowedArguments'=>['order_number','action'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18p_can($user,'manage'),
            'validate'=>static function(array $args,array $user):array{
                $invalid=fm8d18p_fields($args,'order_number');
                if(!in_array($args['action']??null,['submit','cancel'],true))$invalid[]='action';
                return $invalid;
            },
            'execute'=>static function(array $args,array $user):array{
                if(!fm8d18p_can($user,'manage'))throw new DomainException('Purchasing management permission is required.');
                if(fm8d18p_fields($args,'order_number')||!in_array($args['action']??null,['submit','cancel'],true))
                    throw new InvalidArgumentException('Provide one exact PO number and submit or cancel.');
                $po=fm8d18p_exact_order(app_pdo(),(int)$user['organization_id'],(string)$args['order_number']);
                if($args['action']==='submit'&&$po['status']!=='draft')
                    throw new InvalidArgumentException('Only a draft purchase order can be submitted.');
                if($args['action']==='cancel'&&in_array($po['status'],['received','cancelled'],true))
                    throw new InvalidArgumentException('That purchase order cannot be cancelled in its current status.');
                $type=$args['action']==='submit'?'submit_po':'cancel_po';
                $summary='Proposed '.$args['action'].' of '.$po['order_number'].' for '.$po['vendor_name'].
                    ' ($'.number_format((float)$po['total_amount'],2).').'.
                    ($type==='submit_po'?' Super Admin purchase approval remains required.':'');
                return fm8d18p_propose($user,$type,
                    ['purchaseOrderId'=>$po['public_id'],'expected'=>fm8d18p_order_version($po)],$summary);
            },
        ];
    }
    if(fm8d18p_can($actor,'receive')){
        $tools['propose_purchase_order_full_receipt']=[
            'description'=>'ONLY when the user explicitly confirms ALL remaining items on one exact submitted purchase order physically arrived IN FULL without shortages, damage, or exceptions. Never infer full receipt from "delivery arrived" or a status question. This prepares a receiving proposal; nothing enters inventory until separate confirmation and fresh verification.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_number'=>['type'=>'string','description'=>'Exact saved PO number'],
                'all_items_received_in_full'=>['type'=>'boolean','description'=>'Must be explicitly stated true by the user, not inferred'],
            ],'required'=>['order_number','all_items_received_in_full'],'additionalProperties'=>false],
            'allowedArguments'=>['order_number','all_items_received_in_full'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18p_can($user,'receive'),
            'validate'=>static function(array $args,array $user):array{
                $invalid=fm8d18p_fields($args,'order_number');
                if(($args['all_items_received_in_full']??null)!==true)$invalid[]='all_items_received_in_full';
                return $invalid;
            },
            'execute'=>static function(array $args,array $user):array{
                if(!fm8d18p_can($user,'receive'))throw new DomainException('Receiving management permission is required.');
                if(fm8d18p_fields($args,'order_number')||($args['all_items_received_in_full']??null)!==true)
                    throw new InvalidArgumentException('Explicitly confirm all remaining items arrived in full, with no exceptions.');
                $po=fm8d18p_exact_order(app_pdo(),(int)$user['organization_id'],(string)$args['order_number']);
                if(!in_array($po['status'],['submitted','partially_received'],true))
                    throw new InvalidArgumentException('Only a submitted or partially received purchase order can be received.');
                $lines=fm8d18p_outstanding_lines($po);
                if(!$lines)throw new InvalidArgumentException('No outstanding quantity remains on that purchase order.');
                $summary='Proposed full receipt of '.count($lines).' outstanding line(s) on '.$po['order_number'].
                    ', with no shortages or damage. Confirmation will increase usable inventory.';
                return fm8d18p_propose($user,'receive_po_full',[
                    'purchaseOrderId'=>$po['public_id'],'expected'=>fm8d18p_order_version($po),
                    'outstandingSignature'=>fm8d18p_outstanding_signature($lines)],$summary);
            },
        ];
    }
    return $tools;
}
