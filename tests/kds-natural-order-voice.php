<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/kds-spoken-orders.php';
function check(bool $condition,string $what):void{if(!$condition)throw new RuntimeException($what);}
$order=['checkNumber'=>'9081','tableName'=>'Patio 3','late'=>false,'items'=>[
 ['quantity'=>2,'item_name_snapshot'=>'Breakfast Pizza','option_name_snapshot'=>'Large','special_instructions'=>'Add bacon, no onions'],
 ['quantity'=>1,'item_name_snapshot'=>'Calzone','special_instructions'=>'Ricotta on side'],
 ['quantity'=>3,'item_name_snapshot'=>'Coffee','status'=>'queued']
]];
$read=kso_ticket($order,12);
check(str_contains($read,'Order 12, ticket 9081.'),'global order slot and POS number distinguished');
check(str_contains($read,'Table Patio 3'),'table context preserved');
check(str_contains($read,'two Breakfast Pizza'),'quantities spoken naturally');
check(str_contains($read,'Large'),'options not dropped');
check(str_contains($read,'Add bacon, no onions'),'special instructions retained verbatim');
check(str_contains($read,'one Calzone'),'singular item');
check(str_contains($read,'three Coffee'),'additional item quantities');
check(!str_contains($read,'late'),'unflagged ticket not called late');
$update=kso_ticket($order,12,[$order['items'][0]]);
check(str_starts_with($update,'Update for Order 12'),'new modifiers update labeled');
check(!str_contains($update,'Calzone'),'only newly added items announced');
$order['late']=true;
check(str_contains(kso_ticket($order,12),'running late'),'late alert is grounded in ticket flag');
check(kso_quantity(27)==='27','unlimited kitchen item quantities');
check(kso_quantity(1.5)==='1.5','fractional quantities not rounded improperly');
check(!str_contains(kso_ticket(['checkNumber'=>'8001','items'=>[]],23),'undefined'),'missing item data does not leak placeholders');
echo "kds-natural-order-voice=ok\n";
