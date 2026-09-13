<?php
declare(strict_types=1);

require_once __DIR__.'/wholesale-commerce.php';
require_once __DIR__.'/sales-intelligence-core.php';

const WHOLESALE_SALES_PROVIDER='gelato_wholesale';

function wholesale_receivables_ready(PDO $pdo): bool
{
    foreach(['wholesale_invoices','wholesale_invoice_items','wholesale_receivable_entries','wholesale_receivable_events','sales_periods','sales_item_periods'] as $table){
        if(!wholesale_portal_table_ready($pdo,$table))return false;
    }
    return true;
}

function wholesale_receivables_money(float $value): float{return round($value+($value>=0?1e-9:-1e-9),2);}

function wholesale_receivables_date(mixed $value,string $label,?string $default=null): string
{
    $value=trim((string)$value);if($value==='')$value=$default??date('Y-m-d');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' is invalid.');
    return $value;
}

function wholesale_receivables_terms_days(?string $terms): ?int
{
    $terms=trim((string)$terms);if($terms==='')return 0;$lower=mb_strtolower($terms,'UTF-8');
    if(in_array($lower,['due on receipt','upon receipt','cod','c.o.d.','prepaid','due immediately','immediate'],true))return 0;
    if(preg_match('/\bnet\s*[- ]?\s*(\d{1,3})\b/i',$terms,$m))return min(365,max(0,(int)$m[1]));
    return null;
}

function wholesale_receivables_due_date(string $issueDate,?string $terms): ?string
{
    $days=wholesale_receivables_terms_days($terms);if($days===null)return null;
    return (new DateTimeImmutable($issueDate))->modify('+'.$days.' days')->format('Y-m-d');
}

function wholesale_receivables_order(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT o.*,a.business_name,a.payment_terms,a.account_status,COALESCE(pl.currency,'USD') currency FROM wholesale_orders o JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id LEFT JOIN wholesale_price_lists pl ON pl.id=o.price_list_id AND pl.organization_id=o.organization_id WHERE o.organization_id=? AND o.public_id=? AND a.archived_at IS NULL LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Wholesale order not found.');return $row;
}

function wholesale_receivables_invoice(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT i.*,a.business_name,a.public_id account_public_id,o.public_id order_public_id,o.order_number,o.status order_status FROM wholesale_invoices i JOIN wholesale_accounts a ON a.id=i.wholesale_account_id AND a.organization_id=i.organization_id JOIN wholesale_orders o ON o.id=i.wholesale_order_id AND o.organization_id=i.organization_id WHERE i.organization_id=? AND i.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Wholesale invoice not found.');return $row;
}

function wholesale_receivables_invoice_items(PDO $pdo,int $org,int $invoiceId): array
{
    $q=$pdo->prepare('SELECT id,line_number,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source FROM wholesale_invoice_items WHERE organization_id=? AND wholesale_invoice_id=? ORDER BY line_number');$q->execute([$org,$invoiceId]);return $q->fetchAll();
}

function wholesale_receivables_entries(PDO $pdo,int $org,int $invoiceId): array
{
    $q=$pdo->prepare('SELECT public_id,entry_type,amount_delta,currency,effective_date,payment_method,external_reference,note,created_at FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=? ORDER BY effective_date,id');$q->execute([$org,$invoiceId]);return $q->fetchAll();
}

function wholesale_receivables_balance(PDO $pdo,int $org,int $invoiceId): float
{
    $q=$pdo->prepare('SELECT COALESCE(SUM(amount_delta),0) FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=?');$q->execute([$org,$invoiceId]);return wholesale_receivables_money((float)$q->fetchColumn());
}

function wholesale_receivables_event(PDO $pdo,int $org,int $invoiceId,string $type,string $summary,?int $userId=null,array $metadata=[]): void
{
    $q=$pdo->prepare('INSERT INTO wholesale_receivable_events (organization_id,wholesale_invoice_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');
    $q->execute([$org,$invoiceId,mb_substr($type,0,60,'UTF-8'),mb_substr($summary,0,500,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,$userId]);
}

function wholesale_receivables_snapshot_lines(PDO $pdo,int $org,array $order): array
{
    $q=$pdo->prepare('SELECT id,line_number,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source FROM wholesale_order_items WHERE organization_id=? AND wholesale_order_id=? ORDER BY line_number');$q->execute([$org,(int)$order['id']]);$rows=$q->fetchAll();
    if($rows)return $rows;
    $legacy=wholesale_portal_items($order);$out=[];$sum=0.0;
    foreach(array_values($legacy) as $idx=>$raw){
        if(!is_array($raw))continue;$qty=is_numeric($raw['quantity']??null)?(float)$raw['quantity']:1.0;if($qty<=0)$qty=1.0;
        $name=trim((string)($raw['product']??$raw['name']??$raw['item']??''));if($name==='')$name='Wholesale item '.($idx+1);
        $line=is_numeric($raw['lineSubtotal']??null)?(float)$raw['lineSubtotal']:(is_numeric($raw['line_subtotal']??null)?(float)$raw['line_subtotal']:null);
        $unit=is_numeric($raw['unitPrice']??null)?(float)$raw['unitPrice']:(is_numeric($raw['unit_price']??null)?(float)$raw['unit_price']:null);
        if($line===null&&$unit!==null)$line=$qty*$unit;if($unit===null&&$line!==null)$unit=$line/$qty;if($line===null||$unit===null)continue;
        $line=wholesale_receivables_money($line);$sum+=$line;$out[]=['id'=>null,'line_number'=>$idx+1,'sku_snapshot'=>$raw['sku']??null,'item_name_snapshot'=>mb_substr($name,0,220,'UTF-8'),'quantity'=>$qty,'sell_uom_snapshot'=>$raw['unit']??'unit','unit_price_snapshot'=>$unit,'line_subtotal'=>$line,'pricing_source'=>'legacy'];
    }
    if(!$out||abs($sum-(float)$order['subtotal'])>.02){return [['id'=>null,'line_number'=>1,'sku_snapshot'=>null,'item_name_snapshot'=>'Wholesale order '.$order['order_number'],'quantity'=>1,'sell_uom_snapshot'=>'order','unit_price_snapshot'=>(float)$order['subtotal'],'line_subtotal'=>(float)$order['subtotal'],'pricing_source'=>'legacy_summary']];}
    return $out;
}

function wholesale_receivables_insert_entry(PDO $pdo,int $org,array $invoice,string $type,float $delta,string $date,int $userId,array $input=[]): int
{
    $public=wholesale_commerce_public_id('wre');$method=mb_substr(trim((string)($input['paymentMethod']??'')),0,80,'UTF-8')?:null;$reference=mb_substr(trim((string)($input['reference']??'')),0,180,'UTF-8')?:null;$note=mb_substr(trim((string)($input['note']??'')),0,1000,'UTF-8')?:null;
    $q=$pdo->prepare('INSERT INTO wholesale_receivable_entries (organization_id,wholesale_account_id,wholesale_invoice_id,public_id,entry_type,amount_delta,currency,effective_date,payment_method,external_reference,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$org,(int)$invoice['wholesale_account_id'],(int)$invoice['id'],$public,$type,wholesale_receivables_money($delta),$invoice['currency'],$date,$method,$reference,$note,$userId]);return (int)$pdo->lastInsertId();
}

function wholesale_receivables_sync_status(PDO $pdo,int $org,int $invoiceId): array
{
    $q=$pdo->prepare('SELECT * FROM wholesale_invoices WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$invoiceId]);$invoice=$q->fetch();if(!$invoice)throw new RuntimeException('Wholesale invoice disappeared.');
    if(in_array((string)$invoice['status'],['draft','void'],true))return $invoice;
    $balance=wholesale_receivables_balance($pdo,$org,$invoiceId);$total=(float)$invoice['total'];
    if($balance>.005)$status=$balance+0.005<$total?'partially_paid':'issued';elseif($balance<-.005)$status='credit_balance';else $status='paid';
    $paid=$status==='paid'?'COALESCE(paid_at,NOW(6))':'NULL';$pdo->prepare("UPDATE wholesale_invoices SET status=?,paid_at={$paid},updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$status,$org,$invoiceId]);$invoice['status']=$status;$invoice['paid_at']=$status==='paid'?($invoice['paid_at']?:date('Y-m-d H:i:s')):null;return $invoice;
}

function wholesale_receivables_create_invoice(PDO $pdo,int $org,string $orderPublic,array $input,int $userId): array
{
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables migration is not installed.');
    $pdo->beginTransaction();try{
        $order=wholesale_receivables_order($pdo,$org,$orderPublic,true);if($order['status']==='cancelled')throw new InvalidArgumentException('Cancelled Wholesale orders cannot be invoiced.');
        $existing=$pdo->prepare('SELECT public_id FROM wholesale_invoices WHERE organization_id=? AND wholesale_order_id=? LIMIT 1');$existing->execute([$org,(int)$order['id']]);if($public=$existing->fetchColumn()){$pdo->commit();return wholesale_receivables_detail($pdo,$org,(string)$public);}
        $issue=wholesale_receivables_date($input['issueDate']??null,'Issue date');$terms=mb_substr(trim((string)($input['paymentTerms']??$order['payment_terms']??'')),0,120,'UTF-8')?:null;
        $dueRaw=trim((string)($input['dueDate']??''));$due=$dueRaw!==''?wholesale_receivables_date($dueRaw,'Due date'):wholesale_receivables_due_date($issue,$terms);if($due!==null&&$due<$issue)throw new InvalidArgumentException('Due date cannot be before the issue date.');
        $public=wholesale_commerce_public_id('winv');$number='WINV-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$customerRef=mb_substr(trim((string)($input['customerReference']??'')),0,180,'UTF-8')?:null;$customerNote=mb_substr(trim((string)($input['customerNote']??'')),0,10000,'UTF-8')?:null;$internalNote=mb_substr(trim((string)($input['internalNote']??'')),0,10000,'UTF-8')?:null;
        $q=$pdo->prepare("INSERT INTO wholesale_invoices (organization_id,wholesale_account_id,wholesale_order_id,public_id,invoice_number,status,issue_date,due_date,payment_terms_snapshot,currency,subtotal,delivery_fee,tax_total,total,customer_reference,customer_note,internal_note,created_by,updated_by) VALUES (?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $q->execute([$org,(int)$order['wholesale_account_id'],(int)$order['id'],$public,$number,$issue,$due,$terms,$order['currency']?:'USD',(float)$order['subtotal'],(float)$order['delivery_fee'],(float)$order['tax_total'],(float)$order['total'],$customerRef,$customerNote,$internalNote,$userId,$userId]);$invoiceId=(int)$pdo->lastInsertId();
        $lines=wholesale_receivables_snapshot_lines($pdo,$org,$order);$ins=$pdo->prepare('INSERT INTO wholesale_invoice_items (organization_id,wholesale_invoice_id,wholesale_order_item_id,line_number,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');foreach($lines as $line)$ins->execute([$org,$invoiceId,$line['id']??null,(int)$line['line_number'],$line['sku_snapshot']??null,$line['item_name_snapshot'],(float)$line['quantity'],$line['sell_uom_snapshot']??null,(float)$line['unit_price_snapshot'],(float)$line['line_subtotal'],$line['pricing_source']??'canonical']);
        wholesale_receivables_event($pdo,$org,$invoiceId,'created','Invoice '.$number.' created from order '.$order['order_number'].'.',$userId,['orderId'=>$orderPublic,'total'=>(float)$order['total']]);wholesale_commerce_order_event($pdo,$org,(int)$order['id'],'invoice_created','Wholesale invoice '.$number.' created.',$userId,['invoiceId'=>$public]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.invoice_created','wholesale_invoice',$public,null,['orderId'=>$orderPublic,'total'=>(float)$order['total']]);return wholesale_receivables_detail($pdo,$org,$public);
}

function wholesale_receivables_issue(PDO $pdo,int $org,string $invoicePublic,array $input,int $userId): array
{
    $pdo->beginTransaction();try{
        $invoice=wholesale_receivables_invoice($pdo,$org,$invoicePublic,true);if($invoice['status']!=='draft'){if($invoice['status']==='void')throw new InvalidArgumentException('Voided invoices cannot be issued.');$pdo->commit();return wholesale_receivables_detail($pdo,$org,$invoicePublic);}
        if($invoice['order_status']!=='delivered')throw new InvalidArgumentException('Wholesale revenue can be recognized only after the canonical order is delivered.');
        $issue=wholesale_receivables_date($input['issueDate']??$invoice['issue_date'],'Issue date');$terms=mb_substr(trim((string)($input['paymentTerms']??$invoice['payment_terms_snapshot']??'')),0,120,'UTF-8')?:null;$dueRaw=trim((string)($input['dueDate']??$invoice['due_date']??''));$due=$dueRaw!==''?wholesale_receivables_date($dueRaw,'Due date'):wholesale_receivables_due_date($issue,$terms);if($due===null)throw new InvalidArgumentException('Set a due date because the account payment terms are not a recognized Net-N or due-on-receipt term.');if($due<$issue)throw new InvalidArgumentException('Due date cannot be before the issue date.');
        $pdo->prepare("UPDATE wholesale_invoices SET status='issued',issue_date=?,due_date=?,payment_terms_snapshot=?,issued_at=COALESCE(issued_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$issue,$due,$terms,$userId,$org,(int)$invoice['id']]);$invoice['issue_date']=$issue;$invoice['due_date']=$due;$invoice['payment_terms_snapshot']=$terms;
        wholesale_receivables_insert_entry($pdo,$org,$invoice,'invoice',(float)$invoice['total'],$issue,$userId,['note'=>'Invoice issued']);wholesale_receivables_event($pdo,$org,(int)$invoice['id'],'issued','Invoice '.$invoice['invoice_number'].' issued for $'.number_format((float)$invoice['total'],2).'.',$userId,['dueDate'=>$due]);wholesale_commerce_order_event($pdo,$org,(int)$invoice['wholesale_order_id'],'invoice_issued','Wholesale invoice '.$invoice['invoice_number'].' issued.',$userId,['invoiceId'=>$invoicePublic,'dueDate'=>$due]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    wholesale_receivables_sync_sales_date($pdo,$org,$issue);if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.invoice_issued','wholesale_invoice',$invoicePublic,['status'=>'draft'],['status'=>'issued','dueDate'=>$due]);return wholesale_receivables_detail($pdo,$org,$invoicePublic);
}

function wholesale_receivables_record(PDO $pdo,int $org,string $invoicePublic,string $type,array $input,int $userId): array
{
    if(!in_array($type,['payment','credit','refund'],true))throw new InvalidArgumentException('Unsupported receivable entry type.');if(!is_numeric($input['amount']??null))throw new InvalidArgumentException('Amount is required.');$amount=wholesale_receivables_money((float)$input['amount']);if($amount<=0)throw new InvalidArgumentException('Amount must be greater than zero.');$date=wholesale_receivables_date($input['effectiveDate']??null,'Effective date');
    $pdo->beginTransaction();try{
        $invoice=wholesale_receivables_invoice($pdo,$org,$invoicePublic,true);if(in_array($invoice['status'],['draft','void'],true))throw new InvalidArgumentException('Issue the invoice before recording receivable activity.');$balance=wholesale_receivables_balance($pdo,$org,(int)$invoice['id']);
        if($type==='payment'){if($balance<=.005)throw new InvalidArgumentException('This invoice has no positive balance to pay.');if($amount>$balance+.005)throw new InvalidArgumentException('Payment cannot exceed the outstanding balance of $'.number_format($balance,2).'.');$delta=-$amount;}
        elseif($type==='credit'){$q=$pdo->prepare("SELECT COALESCE(SUM(-amount_delta),0) FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=? AND entry_type='credit'");$q->execute([$org,(int)$invoice['id']]);$credited=(float)$q->fetchColumn();$remaining=max(0,(float)$invoice['total']-$credited);if($amount>$remaining+.005)throw new InvalidArgumentException('Credit cannot exceed the remaining creditable invoice amount of $'.number_format($remaining,2).'.');$delta=-$amount;}
        else{if($balance>=-.005)throw new InvalidArgumentException('Record a credit before refunding a paid invoice; there is no customer credit balance to refund.');$available=-$balance;if($amount>$available+.005)throw new InvalidArgumentException('Refund cannot exceed the customer credit balance of $'.number_format($available,2).'.');$delta=$amount;}
        wholesale_receivables_insert_entry($pdo,$org,$invoice,$type,$delta,$date,$userId,$input);wholesale_receivables_event($pdo,$org,(int)$invoice['id'],$type,$type==='payment'?'Payment recorded for $'.number_format($amount,2).'.':($type==='credit'?'Credit recorded for $'.number_format($amount,2).'.':'Customer refund recorded for $'.number_format($amount,2).'.'),$userId,['amount'=>$amount,'effectiveDate'=>$date]);wholesale_receivables_sync_status($pdo,$org,(int)$invoice['id']);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($type==='credit')wholesale_receivables_sync_sales_date($pdo,$org,$date);if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.receivable_'.$type,'wholesale_invoice',$invoicePublic,null,['amount'=>$amount,'effectiveDate'=>$date]);return wholesale_receivables_detail($pdo,$org,$invoicePublic);
}

function wholesale_receivables_void(PDO $pdo,int $org,string $invoicePublic,string $reason,int $userId): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('A void reason is required.');$issueDate=null;
    $pdo->beginTransaction();try{
        $invoice=wholesale_receivables_invoice($pdo,$org,$invoicePublic,true);if($invoice['status']==='void'){$pdo->commit();return wholesale_receivables_detail($pdo,$org,$invoicePublic);}
        if($invoice['status']==='draft'){$pdo->prepare("UPDATE wholesale_invoices SET status='void',voided_at=NOW(6),internal_note=CONCAT(COALESCE(internal_note,''),IF(COALESCE(internal_note,'')='','',CHAR(10)),'Void: ',?),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$reason,$userId,$org,(int)$invoice['id']]);}
        else{$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=? AND entry_type IN ('payment','credit','refund')");$q->execute([$org,(int)$invoice['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Invoices with payments, credits, or refunds cannot be voided; use a credit/refund workflow instead.');$balance=wholesale_receivables_balance($pdo,$org,(int)$invoice['id']);if(abs($balance-(float)$invoice['total'])>.005)throw new InvalidArgumentException('Invoice balance is no longer eligible for voiding.');$date=date('Y-m-d');wholesale_receivables_insert_entry($pdo,$org,$invoice,'void',-(float)$invoice['total'],$date,$userId,['note'=>$reason]);$issueDate=(string)$invoice['issue_date'];$pdo->prepare("UPDATE wholesale_invoices SET status='void',voided_at=NOW(6),paid_at=NULL,internal_note=CONCAT(COALESCE(internal_note,''),IF(COALESCE(internal_note,'')='','',CHAR(10)),'Void: ',?),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$reason,$userId,$org,(int)$invoice['id']]);}
        wholesale_receivables_event($pdo,$org,(int)$invoice['id'],'voided','Invoice '.$invoice['invoice_number'].' voided.',$userId,['reason'=>$reason]);wholesale_commerce_order_event($pdo,$org,(int)$invoice['wholesale_order_id'],'invoice_voided','Wholesale invoice '.$invoice['invoice_number'].' voided.',$userId,['invoiceId'=>$invoicePublic,'reason'=>$reason]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($issueDate)wholesale_receivables_sync_sales_date($pdo,$org,$issueDate);if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.invoice_voided','wholesale_invoice',$invoicePublic,null,['reason'=>$reason]);return wholesale_receivables_detail($pdo,$org,$invoicePublic);
}

function wholesale_receivables_detail(PDO $pdo,int $org,string $invoicePublic): array
{
    $invoice=wholesale_receivables_invoice($pdo,$org,$invoicePublic);$balance=wholesale_receivables_balance($pdo,$org,(int)$invoice['id']);$today=date('Y-m-d');$daysOverdue=$invoice['due_date']&&$balance>.005&&$invoice['due_date']<$today?(int)(new DateTimeImmutable($invoice['due_date']))->diff(new DateTimeImmutable($today))->days:0;
    return ['invoice'=>['id'=>$invoice['public_id'],'number'=>$invoice['invoice_number'],'status'=>$invoice['status'],'businessName'=>$invoice['business_name'],'accountId'=>$invoice['account_public_id'],'orderId'=>$invoice['order_public_id'],'orderNumber'=>$invoice['order_number'],'orderStatus'=>$invoice['order_status'],'issueDate'=>$invoice['issue_date'],'dueDate'=>$invoice['due_date'],'paymentTerms'=>$invoice['payment_terms_snapshot'],'currency'=>$invoice['currency'],'subtotal'=>(float)$invoice['subtotal'],'deliveryFee'=>(float)$invoice['delivery_fee'],'taxTotal'=>(float)$invoice['tax_total'],'total'=>(float)$invoice['total'],'balance'=>$balance,'daysOverdue'=>$daysOverdue,'customerReference'=>$invoice['customer_reference'],'customerNote'=>$invoice['customer_note'],'internalNote'=>$invoice['internal_note'],'issuedAt'=>$invoice['issued_at'],'paidAt'=>$invoice['paid_at'],'voidedAt'=>$invoice['voided_at']],'items'=>wholesale_receivables_invoice_items($pdo,$org,(int)$invoice['id']),'entries'=>wholesale_receivables_entries($pdo,$org,(int)$invoice['id'])];
}

function wholesale_receivables_list(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT i.*,a.business_name,o.order_number,o.public_id order_public_id,COALESCE(e.balance,0) balance FROM wholesale_invoices i JOIN wholesale_accounts a ON a.id=i.wholesale_account_id AND a.organization_id=i.organization_id JOIN wholesale_orders o ON o.id=i.wholesale_order_id AND o.organization_id=i.organization_id LEFT JOIN (SELECT organization_id,wholesale_invoice_id,SUM(amount_delta) balance FROM wholesale_receivable_entries GROUP BY organization_id,wholesale_invoice_id) e ON e.organization_id=i.organization_id AND e.wholesale_invoice_id=i.id WHERE i.organization_id=? ORDER BY CASE WHEN i.status NOT IN ('draft','void','paid') THEN 0 ELSE 1 END,i.due_date,i.created_at DESC LIMIT 300");$q->execute([$org]);$today=date('Y-m-d');$out=[];foreach($q->fetchAll() as $r){$balance=wholesale_receivables_money((float)$r['balance']);$days=$r['due_date']&&$balance>.005&&$r['due_date']<$today?(int)(new DateTimeImmutable($r['due_date']))->diff(new DateTimeImmutable($today))->days:0;$out[]=['id'=>$r['public_id'],'number'=>$r['invoice_number'],'businessName'=>$r['business_name'],'orderNumber'=>$r['order_number'],'orderId'=>$r['order_public_id'],'status'=>$r['status'],'issueDate'=>$r['issue_date'],'dueDate'=>$r['due_date'],'total'=>(float)$r['total'],'balance'=>$balance,'daysOverdue'=>$days];}return $out;
}

function wholesale_receivables_orders(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT o.public_id,o.order_number,o.status,o.total,o.delivered_at,a.business_name,a.payment_terms,i.public_id invoice_id,i.invoice_number,i.status invoice_status FROM wholesale_orders o JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id LEFT JOIN wholesale_invoices i ON i.wholesale_order_id=o.id AND i.organization_id=o.organization_id WHERE o.organization_id=? AND o.status<>'cancelled' ORDER BY FIELD(o.status,'delivered','out_for_delivery','ready','in_production','confirmed','requested'),o.updated_at DESC LIMIT 300");$q->execute([$org]);return $q->fetchAll();
}

function wholesale_receivables_aging(PDO $pdo,int $org): array
{
    $today=new DateTimeImmutable(date('Y-m-d'));$rows=wholesale_receivables_list($pdo,$org);$b=['current'=>0.0,'days1to30'=>0.0,'days31to60'=>0.0,'days61to90'=>0.0,'days90plus'=>0.0,'overdue'=>0.0,'receivables'=>0.0,'customerCredits'=>0.0,'openInvoices'=>0,'overdueInvoices'=>0];
    foreach($rows as $r){$balance=(float)$r['balance'];if($balance<-.005){$b['customerCredits']+=-$balance;continue;}if($balance<=.005||in_array($r['status'],['draft','void'],true))continue;$b['receivables']+=$balance;$b['openInvoices']++;$days=(int)$r['daysOverdue'];if($days<=0)$b['current']+=$balance;else{$b['overdue']+=$balance;$b['overdueInvoices']++;if($days<=30)$b['days1to30']+=$balance;elseif($days<=60)$b['days31to60']+=$balance;elseif($days<=90)$b['days61to90']+=$balance;else $b['days90plus']+=$balance;}}
    foreach(['current','days1to30','days31to60','days61to90','days90plus','overdue','receivables','customerCredits'] as $k)$b[$k]=wholesale_receivables_money($b[$k]);return $b;
}

function wholesale_receivables_sync_sales_date(PDO $pdo,int $org,string $date): void
{
    if(!sales_intelligence_ready($pdo))return;$date=wholesale_receivables_date($date,'Sales date');$provider=WHOLESALE_SALES_PROVIDER;
    $periodQ=$pdo->prepare("SELECT id FROM sales_periods WHERE organization_id=? AND source_provider=? AND location_key='all' AND granularity='daily' AND service_period='all' AND period_start=? AND period_end=?");$periodQ->execute([$org,$provider,$date,$date]);if($oldId=(int)$periodQ->fetchColumn()){$pdo->prepare('DELETE FROM sales_item_periods WHERE organization_id=? AND sales_period_id=?')->execute([$org,$oldId]);$pdo->prepare('DELETE FROM sales_periods WHERE organization_id=? AND id=?')->execute([$org,$oldId]);}
    $iq=$pdo->prepare("SELECT i.id,i.invoice_number,i.subtotal,i.delivery_fee,i.tax_total,i.total FROM wholesale_invoices i WHERE i.organization_id=? AND i.status NOT IN ('draft','void') AND i.issue_date=?");$iq->execute([$org,$date]);$invoices=$iq->fetchAll();
    $cq=$pdo->prepare("SELECT e.amount_delta,i.invoice_number,i.subtotal,i.delivery_fee,i.tax_total,i.total FROM wholesale_receivable_entries e JOIN wholesale_invoices i ON i.id=e.wholesale_invoice_id AND i.organization_id=e.organization_id WHERE e.organization_id=? AND e.entry_type='credit' AND e.effective_date=? AND i.status<>'void'");$cq->execute([$org,$date]);$credits=$cq->fetchAll();if(!$invoices&&!$credits)return;
    $gross=0.0;$net=0.0;$tax=0.0;$fees=0.0;$refundRevenue=0.0;$items=[];
    $lineQ=$pdo->prepare('SELECT sku_snapshot,item_name_snapshot,quantity,line_subtotal FROM wholesale_invoice_items WHERE organization_id=? AND wholesale_invoice_id=? ORDER BY line_number');
    foreach($invoices as $i){$gross+=(float)$i['subtotal'];$net+=(float)$i['subtotal'];$tax+=(float)$i['tax_total'];$fees+=(float)$i['delivery_fee'];$lineQ->execute([$org,(int)$i['id']]);foreach($lineQ->fetchAll() as $line){$key='wholesale:'.($line['sku_snapshot']?:substr(hash('sha256',(string)$line['item_name_snapshot']),0,20));if(!isset($items[$key]))$items[$key]=['name'=>$line['item_name_snapshot'],'sku'=>$line['sku_snapshot'],'quantity'=>0.0,'gross'=>0.0,'net'=>0.0,'refunds'=>0.0];$items[$key]['quantity']+=(float)$line['quantity'];$items[$key]['gross']+=(float)$line['line_subtotal'];$items[$key]['net']+=(float)$line['line_subtotal'];}}
    foreach($credits as $c){$amount=abs((float)$c['amount_delta']);$total=max(.01,(float)$c['total']);$rev=wholesale_receivables_money($amount*((float)$c['subtotal']/$total));$taxShare=wholesale_receivables_money($amount*((float)$c['tax_total']/$total));$feeShare=wholesale_receivables_money($amount-$rev-$taxShare);$net-=$rev;$tax-=$taxShare;$fees-=$feeShare;$refundRevenue+=$rev;}
    $meta=json_encode(['source'=>'Wholesale A/R','recognition'=>'invoice_issue','provider'=>$provider],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare("INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,comps_amount,voids_amount,refunds_amount,service_charges_amount,source_metadata_json) VALUES (?,NULL,'all',?,'daily','all',?,?,?,0,?,?,?,?,0,0,0,?,?,?)")->execute([$org,$provider,$date,$date,count($invoices),wholesale_receivables_money($gross),wholesale_receivables_money($net),wholesale_receivables_money($tax),0,wholesale_receivables_money($refundRevenue),wholesale_receivables_money($fees),$meta]);$periodId=(int)$pdo->lastInsertId();
    $ins=$pdo->prepare('INSERT INTO sales_item_periods (organization_id,sales_period_id,menu_item_id,source_item_key,source_item_id,item_name,category_name,quantity,gross_sales,net_sales,discounts_amount,refunds_amount) VALUES (?,?,NULL,?,?,?,\'Wholesale\',?,?,?,0,?)');foreach($items as $key=>$item)$ins->execute([$org,$periodId,$key,$item['sku'],$item['name'],round($item['quantity'],3),wholesale_receivables_money($item['gross']),wholesale_receivables_money($item['net']),0]);if($refundRevenue>.005)$ins->execute([$org,$periodId,'wholesale:credits',null,'Wholesale credits',0,0,-wholesale_receivables_money($refundRevenue),wholesale_receivables_money($refundRevenue)]);
}
