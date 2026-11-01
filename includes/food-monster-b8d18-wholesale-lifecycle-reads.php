<?php
declare(strict_types=1);

/** B8D18J9A: tenant-scoped Wholesale commercial, quote and order-plan reads. */
require_once __DIR__.'/food-monster-b8d18-wholesale-reads.php';

function fm8d18j9a_can(array $actor):bool
{
    return fm8d18j8a_can($actor);
}
function fm8d18j9a_quote_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['quote_public_id']??null))$missing[]='quote_public_id';
    if(!in_array($args['section']??null,['summary','lines','events'],true))$missing[]='section';
    return $missing;
}
function fm8d18j9a_order_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['order_public_id']??null))$missing[]='order_public_id';
    if(!in_array($args['section']??null,['commercial','plan','events'],true))$missing[]='section';
    return $missing;
}
function fm8d18j9a_quote(PDO $pdo,int $org,string $public):array
{
    $q=$pdo->prepare("SELECT q.*,a.business_name FROM wholesale_quotes q JOIN wholesale_accounts a
        ON a.id=q.wholesale_account_id AND a.organization_id=q.organization_id
        WHERE q.organization_id=? AND q.public_id=? AND a.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$public]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Exact Wholesale quote not found.');
    return $row;
}
function fm8d18j9a_quote_search(array $args,array $actor):array
{
    if(!fm8d18j9a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if(fm8d18j8a_search_fields($args))throw new InvalidArgumentException('Provide a specific Wholesale quote number or business name.');
    require_once __DIR__.'/wholesale-commerce.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$needle=trim((string)$args['query']);
    if(!wholesale_commerce_ready($pdo))throw new RuntimeException('Wholesale commerce is unavailable. Run upgrade.php first.');
    $q=$pdo->prepare("SELECT q.public_id,q.quote_number,q.status,q.total,q.valid_until,a.business_name
        FROM wholesale_quotes q JOIN wholesale_accounts a ON a.id=q.wholesale_account_id
        AND a.organization_id=q.organization_id WHERE q.organization_id=? AND a.archived_at IS NULL
        AND (q.public_id=? OR LOCATE(LOWER(?),LOWER(q.quote_number))>0
             OR LOCATE(LOWER(?),LOWER(a.business_name))>0)
        ORDER BY q.created_at DESC,q.id DESC LIMIT 9");
    $q->execute([$org,$needle,$needle,$needle]);$all=$q->fetchAll();
    $rows=array_map(static fn(array $r):array=>[
        'quotePublicId'=>(string)$r['public_id'],'quoteNumber'=>(string)$r['quote_number'],
        'status'=>(string)$r['status'],'businessName'=>(string)$r['business_name'],
        'total'=>(float)$r['total'],'validUntil'=>$r['valid_until'],
    ],array_slice($all,0,8));
    $pieces=[];foreach($rows as $r)$pieces[]=$r['quoteNumber'].' ['.$r['quotePublicId'].'] · '.$r['businessName'].' · '.$r['status'];
    $answer=$pieces?'Saved Wholesale quotes: '.implode('; ',$pieces).'.':'No saved Wholesale quote matched.';
    if(count($all)>8)$answer.=' Narrow your search; additional matches exist.';
    if(count($rows)>1)$answer.=' Select one exact quote ID.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1500,'UTF-8'),'source'=>'Gelato Wholesale Quotes',
        'readOnly'=>true,'matches'=>$rows,'truncated'=>count($all)>8,
    ]];
}
function fm8d18j9a_quote_read(array $args,array $actor):array
{
    if(!fm8d18j9a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if($missing=fm8d18j9a_quote_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-commerce.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_commerce_ready($pdo))throw new RuntimeException('Wholesale commerce is unavailable. Run upgrade.php first.');
    $quote=fm8d18j9a_quote($pdo,$org,(string)$args['quote_public_id']);
    $section=(string)$args['section'];$rows=[];$parts=[];$all=[];
    if($section==='summary'){
        $rows=['quoteNumber'=>$quote['quote_number'],'businessName'=>$quote['business_name'],
            'status'=>$quote['status'],'subtotal'=>(float)$quote['subtotal'],
            'deliveryFee'=>(float)$quote['delivery_fee'],'taxTotal'=>(float)$quote['tax_total'],
            'total'=>(float)$quote['total'],'validUntil'=>$quote['valid_until'],
            'sentAt'=>$quote['sent_at'],'acceptedAt'=>$quote['accepted_at'],'declinedAt'=>$quote['declined_at']];
        $parts[]='status '.$quote['status'].'; total '.$quote['total'].'; valid until '.($quote['valid_until']?:'not recorded');
    }elseif($section==='lines'){
        $all=wholesale_commerce_quote_lines($pdo,$org,(int)$quote['id']);
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['lineNumber'=>(int)$r['line_number'],'sku'=>$r['sku_snapshot'],
                'name'=>$r['item_name_snapshot'],'quantity'=>(float)$r['quantity'],
                'unit'=>$r['sell_uom_snapshot'],'unitPrice'=>(float)$r['unit_price_snapshot'],
                'lineSubtotal'=>(float)$r['line_subtotal']];
            $parts[]='#'.$r['line_number'].' '.$r['item_name_snapshot'].' · '.$r['quantity'].' '.$r['sell_uom_snapshot'];
        }
    }else{
        $q=$pdo->prepare("SELECT event_type,summary,created_at FROM wholesale_quote_events
            WHERE organization_id=? AND wholesale_quote_id=? ORDER BY id DESC LIMIT 11");
        $q->execute([$org,(int)$quote['id']]);$all=$q->fetchAll();
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['type'=>$r['event_type'],'summary'=>$r['summary'],'at'=>$r['created_at']];
            $parts[]=$r['event_type'].' · '.$r['created_at'];
        }
    }
    $answer=$quote['quote_number'].' ['.$quote['public_id'].'] · '.$section.': '
        .($parts?implode('; ',$parts):'No saved records.').(count($all)>10?' More entries exist.':'');
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1600,'UTF-8'),'source'=>'Gelato Wholesale Quotes',
        'readOnly'=>true,'quotePublicId'=>(string)$quote['public_id'],'section'=>$section,
        'records'=>$rows,'truncated'=>count($all)>10||mb_strlen($answer,'UTF-8')>1600,
    ]];
}
function fm8d18j9a_order_read(array $args,array $actor):array
{
    if(!fm8d18j9a_can($actor))throw new DomainException('Wholesale Agent view permission is required.');
    if($missing=fm8d18j9a_order_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-fulfillment.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $order=wholesale_fulfillment_order($pdo,$org,(string)$args['order_public_id']);
    $section=(string)$args['section'];$rows=[];$parts=[];$all=[];
    if($section==='commercial'){
        $rows=['orderNumber'=>$order['order_number'],'businessName'=>$order['business_name'],
            'status'=>$order['status'],'accountStatus'=>$order['account_status'],
            'subtotal'=>(float)$order['subtotal'],'deliveryFee'=>(float)$order['delivery_fee'],
            'taxTotal'=>(float)$order['tax_total'],'total'=>(float)$order['total'],
            'requestedFor'=>$order['requested_for'],'promisedFor'=>$order['promised_for']];
        $parts[]='status '.$order['status'].'; saved total '.$order['total'].'; account '.$order['account_status'];
    }elseif($section==='plan'){
        $locationId=(int)($order['wholesale_account_location_id']??0);$current=null;
        $q=$pdo->prepare("SELECT id,public_id,name FROM wholesale_account_locations
            WHERE organization_id=? AND wholesale_account_id=? AND status='active'
            ORDER BY is_primary DESC,name,id LIMIT 11");
        $q->execute([$org,(int)$order['wholesale_account_id']]);$all=$q->fetchAll();
        $locations=[];
        foreach(array_slice($all,0,10) as $location){
            if((int)$location['id']===$locationId)$current=['publicId'=>$location['public_id'],'name'=>$location['name']];
            $locations[]=['publicId'=>$location['public_id'],'name'=>$location['name']];
        }
        $rows=['fulfillmentType'=>$order['fulfillment_type'],
            'currentLocation'=>$current,'activeLocations'=>$locations,
            'requestedWindowStart'=>$order['requested_window_start']??null,
            'requestedWindowEnd'=>$order['requested_window_end']??null,
            'promisedWindowStart'=>$order['promised_window_start']??null,
            'promisedWindowEnd'=>$order['promised_window_end']??null];
        $parts[]='fulfillment '.$order['fulfillment_type'].'; location '.($current['name']??'none selected')
            .'; promised '.($order['promised_window_start']??'unset').' to '.($order['promised_window_end']??'unset');
    }else{
        $q=$pdo->prepare("SELECT event_type,summary,created_at FROM wholesale_order_events
            WHERE organization_id=? AND wholesale_order_id=? ORDER BY id DESC LIMIT 11");
        $q->execute([$org,(int)$order['id']]);$all=$q->fetchAll();
        foreach(array_slice($all,0,10) as $r){
            $rows[]=['type'=>$r['event_type'],'summary'=>$r['summary'],'at'=>$r['created_at']];
            $parts[]=$r['event_type'].' · '.$r['created_at'];
        }
    }
    $answer=$order['order_number'].' ['.$order['public_id'].'] · '.$section.': '
        .($parts?implode('; ',$parts):'No saved events.');
    if(count($all)>10)$answer.=' More records exist.';
    return ['ok'=>true,'data'=>[
        'answer'=>mb_substr($answer,0,1700,'UTF-8'),'source'=>'Gelato Wholesale Orders',
        'readOnly'=>true,'orderPublicId'=>(string)$order['public_id'],'section'=>$section,
        'records'=>$rows,'truncated'=>count($all)>10||mb_strlen($answer,'UTF-8')>1700,
    ]];
}
function fm8d18j9a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j9a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'search_wholesale_quotes'=>[
            'description'=>'Find saved Wholesale quote numbers, exact quote IDs or account business names. Tenant-scoped and read-only, up to eight minimal identities. Quote acceptance is exclusively a buyer-portal workflow, not a model action.',
            'parameters'=>['type'=>'object','properties'=>[
                'query'=>['type'=>'string','description'=>'Specific quote number, public ID or business name'],
            ],'required'=>['query'],'additionalProperties'=>false],
            'allowedArguments'=>['query'],'impact'=>'read',
            'authorize'=>static fn(array $u,array $a):bool=>fm8d18j9a_can($u),
            'validate'=>static fn(array $a,array $u):array=>fm8d18j8a_search_fields($a),
            'execute'=>static fn(array $a,array $u):array=>fm8d18j9a_quote_search($a,$u),
        ],
        'read_wholesale_quote'=>[
            'description'=>'Read ONE section of one exact saved Wholesale quote: commercial summary, priced lines, or event history. No buyer contact fields or writes. Never accept or decline a quote on the buyer’s behalf.',
            'parameters'=>['type'=>'object','properties'=>[
                'quote_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale quote public ID'],
                'section'=>['type'=>'string','enum'=>['summary','lines','events']],
            ],'required'=>['quote_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['quote_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $u,array $a):bool=>fm8d18j9a_can($u),
            'validate'=>static fn(array $a,array $u):array=>fm8d18j9a_quote_fields($a),
            'execute'=>static fn(array $a,array $u):array=>fm8d18j9a_quote_read($a,$u),
        ],
        'read_wholesale_order_lifecycle'=>[
            'description'=>'Read ONE section of one exact saved Wholesale order: commercial totals/status, fulfillment plan (including active location IDs and promised windows), or audited order history. No contacts or writes. Production and delivered state comes from Operations/Fulfillment.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale order public ID'],
                'section'=>['type'=>'string','enum'=>['commercial','plan','events']],
            ],'required'=>['order_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $u,array $a):bool=>fm8d18j9a_can($u),
            'validate'=>static fn(array $a,array $u):array=>fm8d18j9a_order_fields($a),
            'execute'=>static fn(array $a,array $u):array=>fm8d18j9a_order_read($a,$u),
        ],
    ];
}
