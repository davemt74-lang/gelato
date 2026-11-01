<?php
declare(strict_types=1);

/** B8D18J10A: permission-scoped Wholesale receivable reads. */
require_once __DIR__.'/food-monster-b8d18-wholesale-lifecycle-reads.php';
require_once __DIR__.'/wholesale-receivables.php';

function fm8d18j10a_can(array $actor):bool
{
    return fm8d18j9a_can($actor)&&app_has_permission('wholesale.receivables.view',$actor);
}
function fm8d18j10a_search_fields(array $args):array
{
    return fm8d18j8a_search_fields($args);
}
function fm8d18j10a_detail_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['invoice_public_id']??null))$missing[]='invoice_public_id';
    if(!in_array($args['section']??null,['summary','items','ledger'],true))$missing[]='section';
    return $missing;
}
function fm8d18j10a_search(array $args,array $actor):array
{
    if(!fm8d18j10a_can($actor))throw new DomainException('Wholesale receivables view permission is required.');
    if(fm8d18j10a_search_fields($args))throw new InvalidArgumentException('Provide a specific invoice or order number or business name.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$query=trim((string)$args['query']);
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables are unavailable. Run upgrade.php.');
    $q=$pdo->prepare("SELECT i.public_id,i.invoice_number,i.status,i.total,i.due_date,o.order_number,a.business_name
        FROM wholesale_invoices i JOIN wholesale_orders o ON o.id=i.wholesale_order_id AND o.organization_id=i.organization_id
        JOIN wholesale_accounts a ON a.id=i.wholesale_account_id AND a.organization_id=i.organization_id
        WHERE i.organization_id=? AND (i.public_id=? OR LOCATE(LOWER(?),LOWER(i.invoice_number))>0
            OR LOCATE(LOWER(?),LOWER(o.order_number))>0 OR LOCATE(LOWER(?),LOWER(a.business_name))>0)
        ORDER BY i.created_at DESC,i.id DESC LIMIT 9");
    $q->execute([$org,$query,$query,$query,$query]);$all=$q->fetchAll();$rows=[];
    foreach(array_slice($all,0,8) as $r)$rows[]=[
        'invoicePublicId'=>$r['public_id'],'invoiceNumber'=>$r['invoice_number'],
        'orderNumber'=>$r['order_number'],'businessName'=>$r['business_name'],
        'status'=>$r['status'],'total'=>(float)$r['total'],'dueDate'=>$r['due_date']];
    $parts=[];foreach($rows as $r)$parts[]=$r['invoiceNumber'].' ['.$r['invoicePublicId'].'] · '.$r['orderNumber'].' · '.$r['status'];
    $answer=$parts?'Saved Wholesale invoices: '.implode('; ',$parts).'.':'No matching Wholesale invoices.';
    if(count($all)>8)$answer.=' More matches exist; refine the search.';
    if(count($rows)>1)$answer.=' Choose one exact invoice ID.';
    return ['ok'=>true,'data'=>['answer'=>mb_substr($answer,0,1500,'UTF-8'),
        'source'=>'Gelato Wholesale Receivables','readOnly'=>true,'matches'=>$rows,'truncated'=>count($all)>8]];
}
function fm8d18j10a_read(array $args,array $actor):array
{
    if(!fm8d18j10a_can($actor))throw new DomainException('Wholesale receivables view permission is required.');
    if($missing=fm8d18j10a_detail_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables are unavailable. Run upgrade.php.');
    $detail=wholesale_receivables_detail($pdo,$org,(string)$args['invoice_public_id']);
    $invoice=$detail['invoice'];$section=(string)$args['section'];$records=[];$parts=[];$truncated=false;
    if($section==='summary'){
        $records=[
            'invoiceNumber'=>$invoice['number'],'businessName'=>$invoice['businessName'],
            'orderNumber'=>$invoice['orderNumber'],'orderStatus'=>$invoice['orderStatus'],
            'status'=>$invoice['status'],'issueDate'=>$invoice['issueDate'],'dueDate'=>$invoice['dueDate'],
            'currency'=>$invoice['currency'],'total'=>$invoice['total'],'balance'=>$invoice['balance'],
            'daysOverdue'=>$invoice['daysOverdue'],'issuedAt'=>$invoice['issuedAt'],
            'paidAt'=>$invoice['paidAt'],'voidedAt'=>$invoice['voidedAt'],
        ];
        $parts[]='status '.$invoice['status'].'; total '.$invoice['total'].'; balance '.$invoice['balance'];
    }elseif($section==='items'){
        $all=(array)$detail['items'];$truncated=count($all)>10;
        foreach(array_slice($all,0,10) as $line){
            $records[]=['lineNumber'=>(int)$line['line_number'],'sku'=>$line['sku_snapshot'],
                'name'=>$line['item_name_snapshot'],'quantity'=>(float)$line['quantity'],
                'unit'=>$line['sell_uom_snapshot'],'unitPrice'=>(float)$line['unit_price_snapshot'],
                'lineSubtotal'=>(float)$line['line_subtotal']];
            $parts[]='#'.$line['line_number'].' '.$line['item_name_snapshot'].' '.$line['quantity'];
        }
    }else{
        $all=(array)$detail['entries'];$truncated=count($all)>10;
        foreach(array_slice($all,-10) as $entry){
            $records[]=['type'=>$entry['entry_type'],'amountDelta'=>(float)$entry['amount_delta'],
                'effectiveDate'=>$entry['effective_date'],'recordedAt'=>$entry['created_at']];
            $parts[]=$entry['entry_type'].' '.$entry['amount_delta'].' on '.$entry['effective_date'];
        }
    }
    $answer=$invoice['number'].' ['.$invoice['id'].'] · '.$section.': '.($parts?implode('; ',$parts):'No saved records.');
    if($truncated)$answer.=' Additional entries exist.';
    return ['ok'=>true,'data'=>['answer'=>mb_substr($answer,0,1700,'UTF-8'),
        'source'=>'Gelato Wholesale Receivables','readOnly'=>true,'invoicePublicId'=>$invoice['id'],
        'section'=>$section,'records'=>$records,'truncated'=>$truncated]];
}
function fm8d18j10a_aging(array $args,array $actor):array
{
    if(!fm8d18j10a_can($actor))throw new DomainException('Wholesale receivables view permission is required.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables are unavailable. Run upgrade.php.');
    $aging=wholesale_receivables_aging($pdo,$org);
    return ['ok'=>true,'data'=>['answer'=>'Wholesale A/R aging: open '.$aging['openInvoices']
        .' invoices, receivables '.$aging['receivables'].', overdue '.$aging['overdue']
        .', customer credits '.$aging['customerCredits'].'.',
        'source'=>'Gelato Wholesale Receivables','readOnly'=>true,'aging'=>$aging]];
}
function fm8d18j10a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j10a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'search_wholesale_invoices'=>[
            'description'=>'Search SAVED Wholesale invoices by a specific invoice number, order number, exact invoice ID or business name, scoped to signed-in restaurant. No payment references or buyer contacts. Select an exact invoice ID before reading a ledger.',
            'parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'required'=>['query'],'additionalProperties'=>false],
            'allowedArguments'=>['query'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j10a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j10a_search_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j10a_search($args,$user),
        ],
        'read_wholesale_invoice'=>[
            'description'=>'Read one EXACT saved Wholesale invoice public ID and ONE section: summary, items, or ledger. Ledger is bounded and excludes payment method/reference/note, private buyer contacts, and internal invoice notes. Read only.',
            'parameters'=>['type'=>'object','properties'=>[
                'invoice_public_id'=>['type'=>'string'],'section'=>['type'=>'string','enum'=>['summary','items','ledger']]],
                'required'=>['invoice_public_id','section'],'additionalProperties'=>false],
            'allowedArguments'=>['invoice_public_id','section'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j10a_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j10a_detail_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j10a_read($args,$user),
        ],
        'get_wholesale_receivables_aging'=>[
            'description'=>'Read the restaurant-scoped saved Wholesale accounts-receivable aging buckets and counts, without invoice contacts or a financial mutation. No arguments.',
            'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j10a_can($user),
            'validate'=>static fn(array $args,array $user):array=>[],
            'execute'=>static fn(array $args,array $user):array=>fm8d18j10a_aging($args,$user),
        ],
    ];
}
