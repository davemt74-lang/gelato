<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/kds-slot-references.php';
function globalSlotAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$tickets=[];$slots=[];
for($i=1;$i<=27;$i++){
    // Intentionally unrelated real POS check numbers: these are not order refs.
    $tickets[]=['checkPublicId'=>'pos-'.$i,'checkNumber'=>(string)(8000+$i),'items'=>[['item_name_snapshot'=>'Pizza']]];
    $slots[]=['slot'=>$i,'checkPublicId'=>'pos-'.$i,'checkNumber'=>(string)(8000+$i)];
}
foreach([1,4,8,9,12,16,17,24,25,27] as $number){
    $res=kds_resolve_order_reference($tickets,$slots,$number);
    globalSlotAssert($res['status']==='ok','Order '.$number.' must resolve across pages');
    globalSlotAssert((string)$res['ticket']['checkNumber']===(string)(8000+$number),'Order '.$number.' confused with POS ticket');
}
globalSlotAssert(kds_resolve_order_reference($tickets,$slots,28)['status']==='empty','non-existent order must not resolve');
globalSlotAssert(kds_resolve_order_reference($tickets,$slots,0)['status']==='invalid','zero is not a valid order reference');
globalSlotAssert(kds_resolve_order_reference($tickets,array_slice($slots,0,8),12)['status']==='stale','missing offscreen mapping must not silently resolve');
$reordered=$tickets;[$reordered[10],$reordered[11]]=[$reordered[11],$reordered[10]];
globalSlotAssert(kds_resolve_order_reference($reordered,$slots,12)['status']==='stale','reordered ticket must not resolve to the wrong POS check');
$forged=$slots;$forged[11]['checkPublicId']='fake-order';
globalSlotAssert(kds_resolve_order_reference($tickets,$forged,12)['status']==='stale','forged client mapping must be rejected');
globalSlotAssert(kds_resolve_order_reference([],[],1)['status']==='empty','empty kitchen must be supported');
echo "kds-global-order-reference=ok\n";
