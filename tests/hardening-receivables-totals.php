<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/wholesale-portal-operations.php';
$checks=0;$serial=0;$pdo=app_pdo();
function hrt_check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);++$GLOBALS['checks'];}
function hrt_close(mixed $a,float $b): bool{return abs((float)$a-$b)<0.001;}
function hrt_account(PDO $pdo,int $org,string $name): int{
    $pdo->prepare("INSERT INTO wholesale_accounts(organization_id,public_id,business_name,account_status) VALUES (?,?,?,'active')")->execute([$org,'hrt-'.bin2hex(random_bytes(8)),$name]);return (int)$pdo->lastInsertId();
}
function hrt_invoice(PDO $pdo,int $org,int $account,string $status,?string $due,array $amounts,string $created='2026-11-03 12:00:00'): int{
    $key='hrt-'.(++$GLOBALS['serial']).'-'.bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO wholesale_orders(organization_id,wholesale_account_id,public_id,order_number,status,items_json,total) VALUES (?,?,?,?,'delivered','[]',100)")->execute([$org,$account,$key,$key]);$order=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO wholesale_invoices(organization_id,wholesale_account_id,wholesale_order_id,public_id,invoice_number,status,issue_date,due_date,total,created_at) VALUES (?,?,?,?,?,?,?, ?,100,?)')->execute([$org,$account,$order,$key,$key,$status,'2026-01-01',$due,$created]);$id=(int)$pdo->lastInsertId();
    foreach($amounts as $n=>$amount)$pdo->prepare('INSERT INTO wholesale_receivable_entries(organization_id,wholesale_account_id,wholesale_invoice_id,public_id,entry_type,amount_delta,effective_date) VALUES (?,?,?,?,?,?,?)')->execute([$org,$account,$id,$key.'-'.$n,$n===0?'invoice':($amount<0?'payment':'refund'),$amount,'2026-01-01']);
    return $id;
}
hrt_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),'The aggregate regression must execute native MariaDB PDO.');
$pdo->beginTransaction();
try{
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Hardening receivables '.bin2hex(random_bytes(5))]);$org=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Hardening receivables foreign '.bin2hex(random_bytes(5))]);$foreign=(int)$pdo->lastInsertId();
    $account=hrt_account($pdo,$org,'Large account');$foreignAccount=hrt_account($pdo,$foreign,'Foreign account');
    $due=(new DateTimeImmutable('today'))->modify('-45 days')->format('Y-m-d');
    hrt_check(wholesale_receivables_aging($pdo,$org)['receivables']===0.0,'An empty ledger must return zero.');
    $count=0;
    foreach([100,101,300,301,2001] as $boundary){
        while($count<$boundary){hrt_invoice($pdo,$org,$account,'issued',$due,[100.01]);$count++;}
        $totals=wholesale_receivables_aging($pdo,$org);
        hrt_check(hrt_close($totals['receivables'],$boundary*100.01),'All '.$boundary.' invoices must contribute to receivables.');
        hrt_check(hrt_close($totals['days31to60'],$boundary*100.01)&&$totals['openInvoices']===$boundary&&$totals['overdueInvoices']===$boundary,'Aging amounts and counts must use the complete ledger.');
        $rows=wholesale_receivables_list($pdo,$org);hrt_check(count($rows)===min(300,$boundary),'The display page remains bounded while totals are complete.');
    }
    $first=wholesale_receivables_list($pdo,$org,75,0);$second=wholesale_receivables_list($pdo,$org,75,75);
    hrt_check(count(array_intersect(array_column($first,'id'),array_column($second,'id')))===0,'Stable invoice ordering must prevent duplicate adjacent pages.');
    $page=wholesale_receivables_page_info($pdo,$org,null,75,75,count($second));
    hrt_check($page['totalCount']===2001&&$page['nextOffset']===150&&$page['displayedCount']===75,'Detail page metadata must expose the complete count and continuation.');
    $baseline=wholesale_receivables_aging($pdo,$org);hrt_invoice($pdo,$foreign,$foreignAccount,'issued',$due,[900000]);
    hrt_check(wholesale_receivables_aging($pdo,$org)===$baseline,'Foreign tenant invoices must not enter any aggregate.');
    hrt_check(wholesale_receivables_aging($pdo,$foreign,$account)['receivables']===0.0,'A foreign account identifier must return no account balances.');
    $mixed=hrt_account($pdo,$org,'Aging edge cases');$today=new DateTimeImmutable('today');
    foreach([0,1,30,31,60,61,90,91] as $days)hrt_invoice($pdo,$org,$mixed,'issued',$today->modify('-'.$days.' days')->format('Y-m-d'),[10]);
    hrt_invoice($pdo,$org,$mixed,'issued',null,[5]);
    hrt_invoice($pdo,$org,$mixed,'credit_balance',$due,[100,-120]);
    hrt_invoice($pdo,$org,$mixed,'paid',$due,[100,-100]);
    hrt_invoice($pdo,$org,$mixed,'draft',$due,[999]);hrt_invoice($pdo,$org,$mixed,'void',$due,[-999]);
    $totals=wholesale_receivables_aging($pdo,$org,$mixed);
    foreach(['current'=>15,'days1to30'=>20,'days31to60'=>20,'days61to90'=>20,'days90plus'=>10,'overdue'=>70,'receivables'=>85,'customerCredits'=>20,'openInvoices'=>9,'overdueInvoices'=>7] as $field=>$value)
        hrt_check(hrt_close($totals[$field],(float)$value),'Full account aging boundary '.$field.' must preserve invoice/payment/credit/draft/void semantics.');
    // Reproduce the exact old portal failure: the only unpaid and credit invoices are older than its page.
    $portal=hrt_account($pdo,$org,'Older unpaid customer');
    hrt_invoice($pdo,$org,$portal,'issued',$due,[500],'2000-01-01 00:00:00');
    hrt_invoice($pdo,$org,$portal,'credit_balance',$due,[100,-175],'2000-01-02 00:00:00');
    for($i=0;$i<100;$i++)hrt_invoice($pdo,$org,$portal,'paid',$due,[100,-100]);
    $dashboard=wholesale_portal_dashboard($pdo,$org,$portal);
    hrt_check(count($dashboard['invoices'])===100&&array_sum(array_column($dashboard['invoices'],'balance'))===0.0,'The fixture must really omit the older unpaid and credit records from the first display page.');
    hrt_check(hrt_close($dashboard['summary']['receivables'],500)&&hrt_close($dashboard['summary']['overdue'],500)&&hrt_close($dashboard['summary']['customerCredits'],75),'Portal dashboard must include older account debt and credit.');
    hrt_check($dashboard['invoicePage']['totalCount']===102&&$dashboard['invoicePage']['nextOffset']===100,'Portal must disclose the full invoice count.');
    $next=wholesale_portal_dashboard($pdo,$org,$portal,100);
    hrt_check(count($next['invoices'])===2&&$next['invoicePage']['nextOffset']===null,'The account can retrieve its older invoice detail page.');
    hrt_check($next['summary']===$dashboard['summary'],'Changing the display page must never change monetary totals.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
echo 'Complete receivables totals: '.$checks." checks passed.\n";
