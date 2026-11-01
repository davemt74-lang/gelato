<?php
declare(strict_types=1);

/** B8D18J10: Wholesale specialist confirmation of draft-only financial actions. */
require_once __DIR__.'/wholesale-receivables.php';

function wa10_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal):array
{
    waa_require($user,'wholesale.receivables.manage',
        'Wholesale receivables management permission is required for this action.');
    waa_require($user,'wholesale.receivables.view',
        'Wholesale receivables view permission is required for this action.');
    $type=(string)($proposal['type']??'');$payload=(array)($proposal['payload']??[]);
    if(!wholesale_receivables_ready($pdo))throw new RuntimeException('Wholesale receivables unavailable. Run upgrade.php.');
    if($type==='invoice_create_draft'){
        if(($payload['createDraft']??null)!==true)throw new InvalidArgumentException('Explicit draft invoice creation acknowledgment is required.');
        $saved=wholesale_receivables_create_invoice($pdo,$org,
            (string)($payload['orderPublicId']??''),[
                'issueDate'=>(string)($payload['issueDate']??''),
            ],$uid,(array)($payload['expectedOrder']??[]));
        $invoice=$saved['invoice'];
        app_audit($pdo,$org,$uid,'wholesale.agent_invoice_draft_created','wholesale_invoice',
            (string)$invoice['id'],null,['proposalId'=>$proposal['id'],'orderId'=>$invoice['orderId']]);
        return ['skill'=>'wholesale.action_confirmed',
            'answer'=>'Confirmed. Created draft Wholesale invoice '.$invoice['number'].' from verified order '.$invoice['orderNumber'].'. This has not issued the invoice or recognized revenue.',
            'data'=>['action'=>$type,'invoiceId'=>$invoice['id'],'invoiceNumber'=>$invoice['number'],
                'orderId'=>$invoice['orderId'],'status'=>$invoice['status']],
            'sources'=>['Wholesale Receivables']];
    }
    if($type==='invoice_void_draft'){
        if(($payload['voidDraft']??null)!==true)throw new InvalidArgumentException('Explicit draft-void acknowledgment is required.');
        $reason=trim((string)($payload['reason']??''));
        if($reason===''||mb_strlen($reason,'UTF-8')>500)throw new InvalidArgumentException('An exact draft-void reason is required.');
        $saved=wholesale_receivables_void($pdo,$org,(string)($payload['invoicePublicId']??''),
            $reason,$uid,(array)($payload['expectedInvoice']??[]));
        $invoice=$saved['invoice'];
        app_audit($pdo,$org,$uid,'wholesale.agent_invoice_draft_voided','wholesale_invoice',
            (string)$invoice['id'],null,['proposalId'=>$proposal['id'],'orderId'=>$invoice['orderId']]);
        return ['skill'=>'wholesale.action_confirmed',
            'answer'=>'Confirmed. Voided untouched draft Wholesale invoice '.$invoice['number'].'. No payment, credit, refund or issued invoice was altered.',
            'data'=>['action'=>$type,'invoiceId'=>$invoice['id'],'invoiceNumber'=>$invoice['number'],
                'orderId'=>$invoice['orderId'],'status'=>$invoice['status']],
            'sources'=>['Wholesale Receivables']];
    }
    throw new InvalidArgumentException('Unsupported Wholesale receivables proposal.');
}
