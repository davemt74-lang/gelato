<?php
declare(strict_types=1);

/** B8D18J10B: model-selected Wholesale receivables draft proposals. */
require_once __DIR__.'/food-monster-b8d18-wholesale-lifecycle-proposals.php';
require_once __DIR__.'/wholesale-receivables-agent-actions.php';

function fm8d18j10b_can(array $actor):bool
{
    return fm8d18j8b_can($actor)
        &&app_has_permission('wholesale.receivables.view',$actor)
        &&app_has_permission('wholesale.receivables.manage',$actor);
}
function fm8d18j10b_draft_fields(array $args):array
{
    $missing=fm8d18j9b_base_fields($args);
    if(!isset($args['saved_total'])||!is_numeric($args['saved_total'])||(float)$args['saved_total']<0
        ||!is_finite((float)$args['saved_total']))$missing[]='saved_total';
    if(!is_string($args['issue_date']??null)
        ||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',(string)$args['issue_date']))$missing[]='issue_date';
    if(($args['create_draft']??null)!==true)$missing[]='create_draft=true';
    return $missing;
}
function fm8d18j10b_void_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['invoice_public_id']??null))$missing[]='invoice_public_id';
    foreach(['invoice_number','order_number','business_name'] as $field){
        $value=$args[$field]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value,'UTF-8')>240
            ||preg_match('/[\\x00-\\x1F]/u',$value))$missing[]=$field;
    }
    if(!is_string($args['reason']??null)||trim((string)$args['reason'])===''
        ||mb_strlen((string)$args['reason'],'UTF-8')>500)$missing[]='reason';
    if(($args['void_draft']??null)!==true)$missing[]='void_draft=true';
    return $missing;
}
function fm8d18j10b_propose(array $args,array $actor,string $kind):array
{
    if(!fm8d18j10b_can($actor))
        throw new DomainException('Wholesale Agent and receivables management permissions are required.');
    $missing=$kind==='invoice_create_draft'?fm8d18j10b_draft_fields($args):
        ($kind==='invoice_void_draft'?fm8d18j10b_void_fields($args):['action']);
    if($missing)throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables are unavailable. Run upgrade.php.');
    if($kind==='invoice_create_draft'){
        $order=wholesale_receivables_order($pdo,$org,(string)$args['order_public_id']);
        if((string)$order['order_number']!==trim((string)$args['order_number'])
            ||(string)$order['business_name']!==trim((string)$args['business_name']))
            throw new InvalidArgumentException('The saved order number/business does not match this exact order.');
        if(!in_array((string)$order['status'],['confirmed','in_production','ready','out_for_delivery','delivered'],true)
            ||(string)$order['account_status']!=='active')
            throw new InvalidArgumentException('Draft creation requires an active account and a confirmed or later non-cancelled order.');
        $total=wholesale_receivables_money((float)$order['total']);
        if(abs($total-(float)$args['saved_total'])>.005)throw new InvalidArgumentException('The acknowledged saved order total differs from the real order.');
        $issue=wholesale_receivables_date($args['issue_date'],'Draft issue date');
        $lines=wholesale_receivables_snapshot_lines($pdo,$org,$order);
        if(!$lines)throw new InvalidArgumentException('Cannot create a draft without canonical order lines.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_invoices WHERE organization_id=? AND wholesale_order_id=?');
        $q->execute([$org,(int)$order['id']]);
        if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('An invoice already exists for this order.');
        $payload=['orderPublicId'=>(string)$order['public_id'],'createDraft'=>true,'issueDate'=>$issue,
            'expectedOrder'=>['status'=>(string)$order['status'],'updatedAt'=>(string)$order['updated_at'],
                'orderNumber'=>(string)$order['order_number'],'businessName'=>(string)$order['business_name'],
                'total'=>$total]];
        $summary='Proposed action: CREATE an unissued draft invoice for order '.$order['order_number']
            .' / '.$order['business_name'].' at saved total '.number_format($total,2,'.','')
            .' with draft issue date '.$issue.'. It will not recognize revenue or contact the customer.';
    }else{
        $invoice=wholesale_receivables_invoice($pdo,$org,(string)$args['invoice_public_id']);
        if((string)$invoice['invoice_number']!==trim((string)$args['invoice_number'])
            ||(string)$invoice['order_number']!==trim((string)$args['order_number'])
            ||(string)$invoice['business_name']!==trim((string)$args['business_name']))
            throw new InvalidArgumentException('The saved invoice/order/business does not match this exact invoice.');
        if((string)$invoice['status']!=='draft')throw new InvalidArgumentException('Only untouched draft invoices can be voided by the model-selected action.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=?');
        $q->execute([$org,(int)$invoice['id']]);
        if((int)$q->fetchColumn()>0||abs(wholesale_receivables_balance($pdo,$org,(int)$invoice['id']))>.005)
            throw new InvalidArgumentException('The draft has ledger entries or a balance; use Receivables for review.');
        $reason=trim((string)$args['reason']);
        $payload=['invoicePublicId'=>(string)$invoice['public_id'],'voidDraft'=>true,'reason'=>$reason,
            'expectedInvoice'=>['status'=>(string)$invoice['status'],'updatedAt'=>(string)$invoice['updated_at'],
                'invoiceNumber'=>(string)$invoice['invoice_number'],'orderPublicId'=>(string)$invoice['order_public_id']]];
        $summary='Proposed action: VOID untouched draft invoice '.$invoice['invoice_number']
            .' for '.$invoice['business_name'].' / order '.$invoice['order_number']
            .'. Reason: '.$reason.'. Only this exact unchanged draft is eligible.';
    }
    $result=fm8d18j8b_stage($actor,$kind,$payload,$summary);
    $result['data']['answer']=str_replace('No order allocation has changed yet.',
        'No invoice, payment, credit, refund, or recognized revenue has changed yet.',
        (string)$result['data']['answer']);
    return $result;
}
function fm8d18j10b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j10b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    $base=[
        'order_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale order public ID'],
        'order_number'=>['type'=>'string','description'=>'Matching saved order number'],
        'business_name'=>['type'=>'string','description'=>'Matching saved account business name'],
    ];
    return [
        'propose_wholesale_invoice_draft'=>[
            'description'=>'ONLY when Wholesale Receivables manager explicitly requests one UNISSUED draft invoice for exact saved active confirmed-or-later order without an invoice. Require matching saved number/business, canonical total and deliberate create_draft=true. Does not recognize revenue, email, issue, or take payment. Stages separate Wholesale Agent Confirm and locks/rechecks order at confirmation.',
            'parameters'=>['type'=>'object','properties'=>$base+[
                'saved_total'=>['type'=>'number'],'issue_date'=>['type'=>'string','description'=>'Exact YYYY-MM-DD date'],
                'create_draft'=>['type'=>'boolean']],
                'required'=>['order_public_id','order_number','business_name','saved_total','issue_date','create_draft'],
                'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','order_number','business_name','saved_total','issue_date','create_draft'],
            'impact'=>'read','authorize'=>static fn(array $user,array $args):bool=>fm8d18j10b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j10b_draft_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j10b_propose($args,$user,'invoice_create_draft'),
        ],
        'propose_wholesale_invoice_draft_void'=>[
            'description'=>'ONLY when manager explicitly requests VOID of ONE exact unchanged, unissued and untouched draft invoice, with matching order number/business, reason and deliberate void_draft=true. Never void issued invoices, modify ledger entries, issue payments, refunds or credits. Separate Confirm and locked version/ledger recheck.',
            'parameters'=>['type'=>'object','properties'=>[
                'invoice_public_id'=>['type'=>'string'],'invoice_number'=>['type'=>'string'],
                'order_number'=>['type'=>'string'],'business_name'=>['type'=>'string'],
                'reason'=>['type'=>'string'],'void_draft'=>['type'=>'boolean']],
                'required'=>['invoice_public_id','invoice_number','order_number','business_name','reason','void_draft'],
                'additionalProperties'=>false],
            'allowedArguments'=>['invoice_public_id','invoice_number','order_number','business_name','reason','void_draft'],
            'impact'=>'read','authorize'=>static fn(array $user,array $args):bool=>fm8d18j10b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j10b_void_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j10b_propose($args,$user,'invoice_void_draft'),
        ],
    ];
}
