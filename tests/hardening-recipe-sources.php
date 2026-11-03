<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/operations-core.php';
require_once dirname(__DIR__).'/includes/sales-cost-core.php';
require_once dirname(__DIR__).'/includes/prep-intelligence-core.php';
require_once dirname(__DIR__).'/includes/wholesale-demand.php';
$pdo=app_pdo();$checks=0;$suffix=bin2hex(random_bytes(5));
function hrs_check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);++$GLOBALS['checks'];}
function hrs_reject(callable $call,string $message): void{try{$call();}catch(InvalidArgumentException|PDOException){hrs_check(true,$message);return;}throw new RuntimeException($message);}
function hrs_rows(PDO $pdo,string $sql,array $args=[]): array{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();}
function hrs_sources(PDO $pdo,int $org,string $recipe): array{return hrs_rows($pdo,"SELECT s.*,i.normalized_key FROM inventory_item_sources s JOIN inventory_items i ON i.id=s.inventory_item_id AND i.organization_id=s.organization_id WHERE s.organization_id=? AND s.source_type='recipe' AND s.source_public_id=? ORDER BY i.normalized_key",[$org,'recipe:'.$recipe]);}
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Recipe source integrity '.$suffix]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Recipe source isolation '.$suffix]);$foreign=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture','Recipe','Worker','Recipe Worker','active')")->execute(['hrs-'.$suffix.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$recipe='hrs-'.$suffix;$name='Hardening dough '.$suffix;
$initial=[['name'=>'flour','quantity'=>2,'unit'=>'kg'],['name'=>'sea salt','quantity'=>'1/10','unit'=>'kg'],['name'=>'olive oil','quantity'=>'1/2','unit'=>'l']];
$pdo->prepare("INSERT INTO recipes(organization_id,public_id,name,ingredients_json,yield_quantity,yield_unit,status,created_by,updated_by) VALUES (?,?,?,?,1,'batch','active',?,?)")->execute([$org,$recipe,$name,json_encode($initial),$uid,$uid]);$recipeId=(int)$pdo->lastInsertId();
$sync=operations_sync_inventory_sources($pdo,$org,$uid);$sources=hrs_sources($pdo,$org,$recipe);
hrs_check(count($sources)===3&&$sync['recipeReferences']===3,'Initial recipe sync must establish all three ingredient sources.');
$ids=[];foreach($sources as $row){$ids[$row['normalized_key']]=(int)$row['inventory_item_id'];$pdo->prepare('UPDATE inventory_items SET base_unit=?,unit_cost=?,on_hand_quantity=40 WHERE id=?')->execute([$row['unit'],$row['normalized_key']==='olive-oil'?10:2,(int)$row['inventory_item_id']]);}
hrs_check(abs((float)$sources[1]['quantity_per_source']-.5)<1e-8,'The object fraction must reach the canonical inventory source as one half.');
$cost=sales_cost_recipe($pdo,$org,$recipeId,1);
hrs_check(abs((float)$cost['unitCost']-9.2)<1e-8&&$cost['coverage']===1.0,'Recipe cost must use fractional amounts and complete ingredient coverage.');
$pdo->prepare("INSERT INTO inventory_transactions(organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,created_by) VALUES (?,?,'receipt',40,40,'kg',?)")->execute([$org,$ids['flour'],$uid]);
$inventoryBefore=hrs_rows($pdo,'SELECT id,base_unit,on_hand_quantity,unit_cost FROM inventory_items WHERE organization_id=? ORDER BY id',[$org]);
$ledgerBefore=hrs_rows($pdo,'SELECT * FROM inventory_transactions WHERE organization_id=? ORDER BY id',[$org]);
$pdo->prepare("INSERT INTO inventory_item_sources(organization_id,inventory_item_id,source_type,source_public_id,source_name,quantity_per_source,unit) VALUES (?,?,'manual','manual:preserve','Manual source',9,'kg')")->execute([$org,$ids['flour']]);
$foreignItem=operations_inventory_upsert($pdo,$foreign,'Foreign flour',$uid);
$pdo->prepare("INSERT INTO inventory_item_sources(organization_id,inventory_item_id,source_type,source_public_id,source_name,quantity_per_source,unit) VALUES (?,?,'recipe',?,'Foreign source',900,'kg')")->execute([$foreign,(int)$foreignItem['id'],'recipe:'.$recipe]);
$foreignBefore=hrs_sources($pdo,$foreign,$recipe);
$pdo->prepare("INSERT INTO prep_plans(organization_id,public_id,plan_date,title) VALUES (?,?,?,?)")->execute([$org,'hrs-plan-'.$suffix,'2026-11-03','Recipe source plan']);$planId=(int)$pdo->lastInsertId();$plan=['id'=>$planId,'public_id'=>'hrs-plan-'.$suffix];
$pdo->prepare("INSERT INTO prep_recommendations(organization_id,plan_id,public_id,normalized_key,title,recommended_quantity,unit,confidence) VALUES (?,?,?,?,?,2,'batch',1)")->execute([$org,$planId,'hrs-rec-'.$suffix,prep_normalized_key($name),$name]);
$beforeForecast=prep_inventory_forecasts_rebuild($pdo,$org,$plan,$uid);
hrs_check(count($beforeForecast)===3,'Actual prep forecast must initially include every ingredient.');
$pdo->prepare("INSERT INTO wholesale_accounts(organization_id,public_id,business_name,account_status) VALUES (?,?,?,'active')")->execute([$org,'hrs-account-'.$suffix,'Recipe source buyer']);$accountId=(int)$pdo->lastInsertId();$account=wholesale_commerce_account($pdo,$org,$accountId);
$product=wholesale_commerce_save_product($pdo,$org,['name'=>'Hardening dough product '.$suffix,'recipeId'=>$recipe],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'HRS-'.$suffix,'name'=>'Dough batch','sellUom'=>'batch','minimumQuantity'=>1,'quantityIncrement'=>1],$uid);
wholesale_demand_save_sku_production($pdo,$org,['skuId'=>$sku['public_id'],'recipeYieldPerBatch'=>1,'recipeYieldUnit'=>'batch'],$uid);
$priceList=wholesale_commerce_save_price_list($pdo,$org,['name'=>'Recipe source prices','isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$priceList['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>100,'effectiveFrom'=>date('Y-m-d')],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$priceList['public_id'],$uid);
$order=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>3]],'status'=>'confirmed'],$uid);
$demand=wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);
hrs_check($demand['created']===3&&$demand['issues']===[],'Actual wholesale commitment service must initially reserve all three ingredients.');
$changed=[['name'=>'flour','quantity'=>'1/2','unit'=>'kg'],['name'=>'Flour','quantity'=>'1 1/2','unit'=>'kilograms'],['name'=>'olive oil','quantity'=>'1/2','unit'=>'l']];
$pdo->prepare('UPDATE recipes SET ingredients_json=?,updated_at=NOW(6) WHERE id=?')->execute([json_encode($changed),$recipeId]);
$sync=operations_sync_inventory_sources($pdo,$org,$uid);$after=hrs_sources($pdo,$org,$recipe);
hrs_check(count($after)===2&&array_column($after,'normalized_key')===['flour','olive-oil'],'Removed salt must no longer be an active recipe source.');
hrs_check((float)$after[0]['quantity_per_source']===2.0,'Duplicate compatible flour lines must sum, not overwrite.');
hrs_check($sync['retiredRecipeReferences']===1,'The sync receipt must identify the retired ingredient reference.');
$audit=hrs_rows($pdo,"SELECT previous_values_json,new_values_json FROM audit_log WHERE organization_id=? AND action='inventory.recipe_sources_reconciled' AND entity_id=? ORDER BY id DESC LIMIT 1",[$org,$recipe])[0];
$old=json_decode($audit['previous_values_json'],true);$new=json_decode($audit['new_values_json'],true);
hrs_check(count($old['sources'])===3&&count($new['sources'])===2&&strlen($new['recipeContentSha256'])===64,'Before/after source history and exact recipe-content fingerprint must be committed together.');
$metadata=json_decode($after[0]['metadata_json'],true);
hrs_check(count($metadata['ingredientLines'])===2&&$metadata['parserVersion']===1,'Raw duplicate lines must survive in versioned source metadata.');
$newForecast=prep_inventory_forecasts_rebuild($pdo,$org,$plan,$uid);
hrs_check(count($newForecast)===2&&!in_array('sea salt',array_column($newForecast,'inventory_name'),true),'An actual prep rebuild must drop removed ingredient demand.');
$required=array_column($newForecast,'required_quantity','inventory_name');
hrs_check((float)$required['flour']===4.0&&(float)$required['olive oil']===1.0,'Forecast quantities must use the complete reconciled recipe source set.');
wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);
$commitments=hrs_rows($pdo,"SELECT inventory_item_id,quantity,status FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? ORDER BY inventory_item_id",[$org,$order['publicId']]);
$active=array_values(array_filter($commitments,static fn(array $r):bool=>$r['status']==='active'));
hrs_check(count($active)===2&&!in_array($ids['sea-salt'],array_map('intval',array_column($active,'inventory_item_id')),true),'Actual commitment refresh must release removed salt and retain current ingredients.');
hrs_check(count($commitments)===3,'Retired commitment history must remain available.');
hrs_check(hrs_sources($pdo,$foreign,$recipe)===$foreignBefore,'Source reconciliation must not touch same-named recipes in another tenant.');
hrs_check(count(hrs_rows($pdo,"SELECT id FROM inventory_item_sources WHERE organization_id=? AND source_type='manual'",[$org]))===1,'Manual source links must be preserved.');
hrs_check(hrs_rows($pdo,'SELECT id,base_unit,on_hand_quantity,unit_cost FROM inventory_items WHERE organization_id=? ORDER BY id',[$org])===$inventoryBefore,'Recipe projection changes must not change any physical stock unit, quantity or cost.');
hrs_check(hrs_rows($pdo,'SELECT * FROM inventory_transactions WHERE organization_id=? ORDER BY id',[$org])===$ledgerBefore,'Recipe synchronization must preserve physical stock history.');
$beforeFailed=hrs_sources($pdo,$org,$recipe);
$belowPrecision=[['name'=>'flour','quantity'=>'0.00001','unit'=>'kg'],['name'=>'olive oil','quantity'=>'1/2','unit'=>'l']];
$pdo->prepare('UPDATE recipes SET ingredients_json=? WHERE id=?')->execute([json_encode($belowPrecision),$recipeId]);
hrs_reject(fn()=>operations_sync_inventory_sources($pdo,$org,$uid),'A positive ingredient quantity that rounds to zero must require review before source replacement.');
hrs_check(hrs_sources($pdo,$org,$recipe)===$beforeFailed,'A below-precision ingredient must preserve the previous complete source set.');
$precisionRecipe=['public_id'=>'precision-check','name'=>'Precision check','status'=>'active','archived_at'=>null,'ingredients_json'=>json_encode([['name'=>'flour','quantity'=>'0.00003','unit'=>'kg'],['name'=>'flour','quantity'=>'0.00003','unit'=>'kg']])];
$precisionPlan=operations_recipe_source_plan([$precisionRecipe]);
hrs_check(round($precisionPlan['recipe:precision-check']['ingredients']['flour']['quantity'],4)===0.0001,'Compatible duplicates must be summed before the storage precision check.');
$invalid=[['name'=>'flour','quantity'=>2,'unit'=>'kg'],['name'=>'olive oil','quantity'=>'1-2','unit'=>'l']];
$pdo->prepare('UPDATE recipes SET ingredients_json=? WHERE id=?')->execute([json_encode($invalid),$recipeId]);
hrs_reject(fn()=>operations_sync_inventory_sources($pdo,$org,$uid),'An ambiguous amount must abort before replacing the current source set.');
hrs_check(hrs_sources($pdo,$org,$recipe)===$beforeFailed,'A failed validation must retain the complete previous source set.');
$partialCost=sales_cost_recipe($pdo,$org,$recipeId,1);
hrs_check($partialCost['unitCost']===null&&$partialCost['coverage']===0.5&&count($partialCost['gaps'])===1,'An incomplete recipe must not present a partial total as a full unit cost.');
$pdo->beginTransaction();
try{
    $pdo->prepare('UPDATE organizations SET name=? WHERE id=?')->execute(['Outer transaction sentinel',$org]);
    hrs_reject(fn()=>operations_sync_inventory_sources($pdo,$org,$uid),'A nested failure must roll back its savepoint.');
    hrs_check($pdo->inTransaction()&&$pdo->query('SELECT name FROM organizations WHERE id='.$org)->fetchColumn()==='Outer transaction sentinel','A nested sync failure must preserve caller-owned work and transaction.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
$changed[0]['quantity']=1;$pdo->prepare('UPDATE recipes SET ingredients_json=? WHERE id=?')->execute([json_encode($changed),$recipeId]);
$trigger='hrs_fail_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id={$org} AND NEW.action='inventory.recipe_sources_reconciled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected recipe audit failure'; END IF; END");
try{
    hrs_reject(fn()=>operations_sync_inventory_sources($pdo,$org,$uid),'A failed audit insert must fail the entire recipe sync.');
    hrs_check(!$pdo->inTransaction()&&hrs_sources($pdo,$org,$recipe)===$beforeFailed,'Audit failure after writes must roll back all source changes.');
    hrs_check(hrs_rows($pdo,'SELECT id,base_unit,on_hand_quantity,unit_cost FROM inventory_items WHERE organization_id=? ORDER BY id',[$org])===$inventoryBefore,'Audit failure must not leave partial inventory changes.');
}finally{$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}
$pdo->prepare('UPDATE recipes SET archived_at=NOW(6),updated_at=NOW(6) WHERE id=?')->execute([$recipeId]);
operations_sync_inventory_sources($pdo,$org,$uid);
hrs_check(hrs_sources($pdo,$org,$recipe)===[],'Archived recipes must have no active source links after synchronization.');
hrs_check(prep_inventory_forecasts_rebuild($pdo,$org,$plan,$uid)===[],'Archived recipes must not create fresh prep demand.');
$released=wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);
hrs_check($released['created']===0&&count($released['issues'])>0,'Actual wholesale refresh must report the missing active recipe.');
hrs_check(hrs_rows($pdo,"SELECT id FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'",[$org,$order['publicId']])===[],'Archived recipes must not retain active commitments after the domain refresh.');
hrs_check(hrs_rows($pdo,'SELECT * FROM inventory_transactions WHERE organization_id=? ORDER BY id',[$org])===$ledgerBefore,'Archive/source/demand refresh must never rewrite consumed or received stock history.');
echo 'Recipe source reconciliation: '.$checks." checks passed.\n";
