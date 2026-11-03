<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/daily-quicklist-purchase-drafts.php';
$checks=0;
function nmvi_assert(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function nmvi_reject(callable $operation,string $message):void{
    try{$operation();}catch(InvalidArgumentException|DomainException){nmvi_assert(true,$message);return;}
    throw new RuntimeException($message);
}
function nmvi_option(array $comparison,string $id):array{
    foreach($comparison['options'] as $option)if($option['vendorItemId']===$id)return $option;
    throw new RuntimeException('Expected supplier option missing.');
}
$pdo=app_pdo();$slug='nmvi-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$slug.'-other']);$other=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'x','Vendor','Tester','Vendor Tester','active')")
    ->execute([$slug.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$stock=$slug.'-flour';
$pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by)
    VALUES (?,?,?,?, 'kg',8,'active',?)")->execute([$org,$stock,$stock,'Flour '.$slug,$uid]);$stockId=(int)$pdo->lastInsertId();
$request=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','title'=>'Ignored title','inventoryId'=>$stock],false);
daily_quicklist_mutate($pdo,$org,$uid,'update',['id'=>$request,'requestedStockQuantity'=>5],true);
$actor=['id'=>$uid,'organization_id'=>$org,'permissions'=>['tasks.manage','inventory.view','purchasing.view','purchasing.manage']];
$reader=$actor;$reader['permissions']=['tasks.manage','inventory.view','purchasing.view'];
nmvi_assert(dqpo_can_compare($reader)&&!dqpo_can_draft($reader),'A purchasing reader can compare without gaining write access.');
nmvi_assert(!dqpo_can_compare(['permissions'=>['tasks.manage','inventory.view']]),'Purchasing View is required for supplier pricing.');
nmvi_assert(!dqpo_can_compare(['permissions'=>['tasks.self','inventory.view','purchasing.view']]),'Ordinary staff cannot use manager purchasing comparisons.');
nmvi_reject(static fn()=>dqpo_comparison($pdo,$other,$request),'Foreign tenant comparison leaked.');
$a=purchasing_save_vendor($pdo,$org,['name'=>'A Bulk '.$slug,'minimumOrderAmount'=>75,'leadTimeDays'=>2],$uid);
$b=purchasing_save_vendor($pdo,$org,['name'=>'B Exact '.$slug,'minimumOrderAmount'=>60,'leadTimeDays'=>1],$uid);
$c=purchasing_save_vendor($pdo,$org,['name'=>'C Unpriced '.$slug],$uid);
$save=static function(array $vendor,string $sku,?float $pack,string $unit,?float $price,bool $preferred=false)use($pdo,$org,$uid,$stock):array{
    return purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendor['public_id'],'inventoryId'=>$stock,
        'vendorSku'=>$sku,'packSize'=>$pack,'packUnit'=>$unit,'pricePerPack'=>$price,'preferred'=>$preferred],$uid);
};
$ma=$save($a,'BULK-3',3,'kg',21,true);
$mb=$save($b,'EXACT-5',5,'kg',40);
$mb2=$save($b,'SMALL-2',2,'kg',18);
$mc=$save($c,'UNKNOWN',2,'kg',null);
$bad=$save($c,'POUNDS',10,'lb',1);
$comparison=dqpo_comparison($pdo,$org,$request);
nmvi_assert($comparison['canDraft']&&count($comparison['options'])===5,'Every active supplier mapping is available for comparison.');
$oa=nmvi_option($comparison,$ma['public_id']);$ob=nmvi_option($comparison,$mb['public_id']);
nmvi_assert($oa['preferred']&&$oa['unitPrice']===7.0&&$oa['estimatedSubtotal']===42.0,'Bulk vendor shows normalized price and the actual required-pack subtotal.');
nmvi_assert($ob['unitPrice']===8.0&&$ob['estimatedSubtotal']===40.0,'A higher unit price may still give the lower order subtotal.');
nmvi_assert($oa['packs']===2&&$oa['orderedQuantity']===6.0&&$oa['overageQuantity']===1.0,'Whole pack overage is disclosed.');
nmvi_assert($oa['minimumShortfall']===33.0&&count($oa['warnings'])>=2&&$oa['leadTimeDays']===2,'Vendor minimum shortfall and saved lead time are disclosed.');
$unknown=nmvi_option($comparison,$mc['public_id']);
nmvi_assert($unknown['eligible']&&$unknown['pricePerPack']===null&&$unknown['unitPrice']===null&&$unknown['estimatedSubtotal']===null,'Missing prices stay unknown, not zero or cheapest.');
$wrongUnit=nmvi_option($comparison,$bad['public_id']);
nmvi_assert(!$wrongUnit['eligible']&&$wrongUnit['unitPrice']===null&&$wrongUnit['estimatedSubtotal']===null,'No invented unit conversion or misleading cost comparison.');
nmvi_reject(static fn()=>dqpo_quote($pdo,$org,$request,null,$bad['public_id']),'Invalid unit selection was accepted.');
nmvi_reject(static fn()=>dqpo_quote($pdo,$org,$request,null,'not-this-mapping'),'Unknown selected supplier silently fell back.');
$qA=dqpo_quote($pdo,$org,$request);
nmvi_assert($qA['vendorItemId']===$ma['public_id'],'Default quote honors the preferred eligible mapping.');
$qB=dqpo_quote($pdo,$org,$request,null,$mb2['public_id']);
nmvi_assert($qB['vendorItemId']===$mb2['public_id']&&$qB['packs']===3&&$qB['estimatedSubtotal']===54.0,'An explicit second SKU for the same vendor retains its own pack and price.');
nmvi_reject(static fn()=>dqpo_create_draft($pdo,$org,$uid,$actor,$request,$qA['quoteHash'],$mb2['public_id']),'A quote for a different mapping authorized another supplier selection.');
nmvi_reject(static fn()=>dqpo_create_draft($pdo,$org,$uid,$reader,$request,$qB['quoteHash'],$mb2['public_id']),'A read-only actor created a draft.');
nmvi_reject(static fn()=>dqpo_set_preferred($pdo,$org,$uid,$reader,$request,$mb2['public_id'],$comparison['comparisonHash']),'A read-only actor changed a preference.');
$foreignActor=$actor;$foreignActor['organization_id']=$other;
nmvi_reject(static fn()=>dqpo_set_preferred($pdo,$org,$uid,$foreignActor,$request,$mb2['public_id'],$comparison['comparisonHash']),'Actor tenant mismatch changed a preference.');

// Changing any reviewed vendor terms invalidates consent before any write.
$pdo->prepare('UPDATE vendors SET minimum_order_amount=80 WHERE organization_id=? AND id=?')->execute([$org,(int)$b['id']]);
nmvi_reject(static fn()=>dqpo_create_draft($pdo,$org,$uid,$actor,$request,$qB['quoteHash'],$mb2['public_id']),'Changed supplier minimum did not invalidate draft preview.');
nmvi_reject(static fn()=>dqpo_set_preferred($pdo,$org,$uid,$actor,$request,$mb2['public_id'],$comparison['comparisonHash']),'Changed supplier terms did not invalidate preference review.');
$pdo->prepare('UPDATE vendors SET minimum_order_amount=60 WHERE organization_id=? AND id=?')->execute([$org,(int)$b['id']]);

// Price history is per exact saved mapping; preference changes are not new quotes.
$pdo->prepare("INSERT INTO vendor_price_history(organization_id,vendor_id,inventory_item_id,vendor_item_id,price_per_pack,pack_size,unit,source_type,created_by)
    VALUES (?,?,?,?,17,2,'kg','receiving',?)")->execute([$org,(int)$b['id'],$stockId,(int)$mb2['id'],$uid]);
$comparison=dqpo_comparison($pdo,$org,$request);$small=nmvi_option($comparison,$mb2['public_id']);
nmvi_assert(count($small['priceHistory'])===2&&$small['priceHistory'][0]['source']==='receiving'&&$small['priceHistory'][0]['unitPrice']===8.5,'Latest receiving/catalog history is timestamped and normalized.');
nmvi_assert(count(nmvi_option($comparison,$mb['public_id'])['priceHistory'])===1,'Different SKUs for one supplier do not share price history.');
$historyBefore=(int)$pdo->query('SELECT COUNT(*) FROM vendor_price_history WHERE organization_id='.$org)->fetchColumn();
$poBefore=(int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE organization_id='.$org)->fetchColumn();
// Simulate legacy concurrent edits that left two preferred flags, then explicitly repair the chosen mapping.
$pdo->prepare('UPDATE inventory_vendor_items SET is_preferred=1 WHERE organization_id=? AND id=?')->execute([$org,(int)$mb2['id']]);
$comparison=dqpo_comparison($pdo,$org,$request);
$preferred=dqpo_set_preferred($pdo,$org,$uid,$actor,$request,$mb2['public_id'],$comparison['comparisonHash']);
nmvi_assert(nmvi_option($preferred,$mb2['public_id'])['preferred']&&!nmvi_option($preferred,$ma['public_id'])['preferred'],'Preference switches atomically to the exact SKU.');
$q=$pdo->prepare('SELECT vendor_name,vendor_sku,unit_cost,on_hand_quantity FROM inventory_items WHERE organization_id=? AND id=?');$q->execute([$org,$stockId]);$canonical=$q->fetch();
nmvi_assert($canonical['vendor_name']===$b['name']&&$canonical['vendor_sku']==='SMALL-2'&&(float)$canonical['unit_cost']===9.0&&(float)$canonical['on_hand_quantity']===8.0,'Preferred vendor metadata sync does not change stock.');
nmvi_assert((int)$pdo->query('SELECT COUNT(*) FROM vendor_price_history WHERE organization_id='.$org)->fetchColumn()===$historyBefore,'Preference does not fabricate a price-history entry.');
nmvi_assert((int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE organization_id='.$org)->fetchColumn()===$poBefore,'Preference does not implicitly create an order.');
nmvi_assert(daily_quicklist_history($pdo,$org,$request)[0]['event']==='vendor_preferred','Preference is recorded in Need More audit history.');

$quote=dqpo_quote($pdo,$org,$request,null,$mb2['public_id']);
$created=dqpo_create_draft($pdo,$org,$uid,$actor,$request,$quote['quoteHash'],$mb2['public_id']);
$order=purchasing_order($pdo,$org,$created['orderId']);
nmvi_assert($order['status']==='draft'&&(int)$order['items'][0]['vendor_item_id']===(int)$mb2['id']&&(float)$order['items'][0]['pack_size']===2.0,'Draft stores the exact mapping that was reviewed, even with several vendor SKUs.');
nmvi_assert((float)$order['subtotal']===54.0&&$order['submitted_at']===null,'Below-minimum review creates a draft only, with its exact price.');
nmvi_reject(static fn()=>purchasing_submit($pdo,$org,$created['orderId'],$uid),'Vendor minimum no longer blocks submission.');
nmvi_assert(daily_quicklist_history($pdo,$org,$request)[0]['event']==='po_draft_created','Draft creation is linked in Need More history.');
$blocked=dqpo_comparison($pdo,$org,$request);
nmvi_assert(!$blocked['canDraft']&&count($blocked['options'])===5&&$blocked['openOrders'][0]['orderId']===$created['orderId'],'Existing orders block duplication while preserving read-only supplier comparisons.');
nmvi_assert($blocked['openOrders'][0]['outstandingQuantity']===6.0,'Open-order coverage uses the saved line quantity.');
$future=dqpo_set_preferred($pdo,$org,$uid,$actor,$request,$ma['public_id'],$blocked['comparisonHash']);
nmvi_assert(nmvi_option($future,$ma['public_id'])['preferred']&&!$future['canDraft'],'Future vendor preference can change without changing or duplicating an existing order.');
nmvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[['inventoryId'=>$stock,'packs'=>2]],$uid),'Manual entry bypassed Need More open-order protection.');
$pdo->prepare('UPDATE purchase_order_items SET received_base_quantity=2 WHERE organization_id=? AND purchase_order_id=?')->execute([$org,(int)$order['id']]);
$pdo->prepare("UPDATE purchase_orders SET status='partially_received' WHERE organization_id=? AND id=?")->execute([$org,(int)$order['id']]);
$partial=dqpo_comparison($pdo,$org,$request);
nmvi_assert(!$partial['canDraft']&&$partial['openOrders'][0]['outstandingQuantity']===4.0,'Partially received orders retain exact remaining coverage.');
$pdo->prepare("UPDATE purchase_orders SET status='cancelled' WHERE organization_id=? AND id=?")->execute([$org,(int)$order['id']]);
nmvi_assert(dqpo_comparison($pdo,$org,$request)['canDraft'],'A cancelled order no longer blocks a new reviewed draft.');
$pdo->prepare('UPDATE inventory_vendor_items SET archived_at=NOW(6) WHERE id=?')->execute([(int)$mb['id']]);
$pdo->prepare("UPDATE vendors SET status='inactive' WHERE id=?")->execute([(int)$c['id']]);
$active=dqpo_comparison($pdo,$org,$request);
nmvi_assert(count($active['options'])===2,'Archived mappings and inactive suppliers disappear from options.');
nmvi_reject(static fn()=>dqpo_quote($pdo,$org,$request,null,$mb['public_id']),'An archived selected mapping silently fell back.');
$q=$pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=?');$q->execute([$org]);
nmvi_assert((int)$q->fetchColumn()===0,'Comparison, preference and draft flows must not post inventory.');

// Fractional receipts must hash identically in preview and the locked confirmation path.
foreach([0.1,0.2] as $amount){
    $receipt=operations_inventory_adjust($pdo,$org,$stock,$amount,'receive',$uid,'Fractional receipt fixture');
    daily_quicklist_reconcile_receipt($pdo,$org,$uid,$actor,$request,(int)$receipt['last_transaction_id'],null,$amount);
}
$fractional=dqpo_quote($pdo,$org,$request,null,$ma['public_id']);
$pdo->beginTransaction();
try{
    $locked=dqpo_quote($pdo,$org,$request,null,$ma['public_id']);
    nmvi_assert($locked['quoteHash']===$fractional['quoteHash']&&$locked['remainingQuantity']===4.7,
        'Exact DECIMAL receipt totals and locked allocation reads preserve the same reviewed quote.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
$beforeDraft=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$fractionalDraft=dqpo_create_draft($pdo,$org,$uid,$actor,$request,$fractional['quoteHash'],$ma['public_id']);
nmvi_assert($fractionalDraft['status']==='draft'&&(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()===$beforeDraft,
    'A draft after fractional receipt allocation succeeds without another physical inventory entry.');
echo 'need-more-vendor-intelligence: '.$checks." checks passed\n";
