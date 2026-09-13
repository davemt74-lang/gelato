<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/wholesale-receivables.php';

$permission=$_SERVER['REQUEST_METHOD']==='GET'?'wholesale.receivables.view':'wholesale.receivables.manage';
$user=app_require_permission($permission);$pdo=app_pdo();$org=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!wholesale_receivables_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale receivables migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'list');
    try{
        if($action==='list')app_json_response(['ok'=>true,'invoices'=>wholesale_receivables_list($pdo,$org),'aging'=>wholesale_receivables_aging($pdo,$org),'orders'=>wholesale_receivables_orders($pdo,$org)]);
        if($action==='detail'){$id=trim((string)($_GET['id']??''));if($id==='')throw new InvalidArgumentException('Wholesale invoice is required.');app_json_response(['ok'=>true,'detail'=>wholesale_receivables_detail($pdo,$org,$id)]);}
        throw new InvalidArgumentException('Unsupported Wholesale receivables action.');
    }catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');
try{
    if($action==='invoice.create'){$orderId=trim((string)($input['orderId']??''));if($orderId==='')throw new InvalidArgumentException('Wholesale order is required.');$detail=wholesale_receivables_create_invoice($pdo,$org,$orderId,$input,$userId);app_json_response(['ok'=>true,'message'=>'Invoice draft created from the canonical Wholesale order.','detail'=>$detail]);}
    $invoiceId=trim((string)($input['invoiceId']??''));if($invoiceId==='')throw new InvalidArgumentException('Wholesale invoice is required.');
    if($action==='invoice.issue'){$detail=wholesale_receivables_issue($pdo,$org,$invoiceId,$input,$userId);app_json_response(['ok'=>true,'message'=>'Invoice issued and Wholesale revenue recognized.','detail'=>$detail]);}
    if($action==='payment.record'){$detail=wholesale_receivables_record($pdo,$org,$invoiceId,'payment',$input,$userId);app_json_response(['ok'=>true,'message'=>'Payment recorded against A/R.','detail'=>$detail]);}
    if($action==='credit.record'){$detail=wholesale_receivables_record($pdo,$org,$invoiceId,'credit',$input,$userId);app_json_response(['ok'=>true,'message'=>'Credit recorded and recognized Wholesale revenue adjusted.','detail'=>$detail]);}
    if($action==='refund.record'){$detail=wholesale_receivables_record($pdo,$org,$invoiceId,'refund',$input,$userId);app_json_response(['ok'=>true,'message'=>'Customer credit balance refunded.','detail'=>$detail]);}
    if($action==='invoice.void'){$detail=wholesale_receivables_void($pdo,$org,$invoiceId,(string)($input['reason']??''),$userId);app_json_response(['ok'=>true,'message'=>'Invoice voided.','detail'=>$detail]);}
    throw new InvalidArgumentException('Unsupported Wholesale receivables action.');
}catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
