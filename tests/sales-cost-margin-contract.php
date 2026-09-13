<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/sales-cost-core.php';

$pdo=app_pdo();
function scm_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function scm_id(PDO $pdo,string $sql,array $args=[]): int {$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

scm_assert(sales_cost_ready($pdo),'Sales Cost Intelligence must be installed.');
$slug='margin-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Margin CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Margin','Manager','Margin Manager']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$uid,$location]);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status) VALUES (?,'Pizza',?,'active')")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$section,'margherita-'.$slug]);$menuItem=(int)$pdo->lastInsertId();

$flour=operations_inventory_upsert($pdo,$org,'Flour',$uid);$cheese=operations_inventory_upsert($pdo,$org,'Mozzarella',$uid);
$pdo->prepare("UPDATE inventory_items SET base_unit='lb',unit_cost=2.00,on_hand_quantity=20 WHERE id=?")->execute([(int)$flour['id']]);
$pdo->prepare("UPDATE inventory_items SET base_unit='lb',unit_cost=4.00,on_hand_quantity=20 WHERE id=?")->execute([(int)$cheese['id']]);
$recipePublic='recipe-'.$slug;$ingredients=json_encode([['name'=>'Flour','quantity'=>0.5,'unit'=>'lb'],['name'=>'Mozzarella','quantity'=>8,'unit'=>'oz']],JSON_THROW_ON_ERROR);
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,ingredients_json,status,created_by,updated_by) VALUES (?,?,?,'Pizza',?,'active',?,?)")->execute([$org,$recipePublic,'Margherita Pizza',$ingredients,$uid,$uid]);$recipeId=(int)$pdo->lastInsertId();
$recipeCost=sales_cost_recipe($pdo,$org,$recipeId,2,10);scm_assert(abs((float)$recipeCost['recipeCost']-3.0)<0.001,'Recipe base cost should be $3.00.');scm_assert(abs((float)$recipeCost['unitCost']-1.65)<0.001,'Recipe unit cost should be $1.65 after 2 servings and 10% waste.');scm_assert((float)$recipeCost['coverage']===1.0,'Recipe should have full cost coverage.');$cheeseLine=$recipeCost['ingredients'][1]??[];scm_assert(abs((float)($cheeseLine['convertedQuantity']??0)-0.5)<0.0001,'Eight ounces must convert to 0.5 pounds.');

sales_ensure_csv_integration($pdo,$org,$uid);$pdo->prepare("INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales) VALUES (?,?,?,'csv','daily','all','2026-09-10','2026-09-10',10,20,110,100)")->execute([$org,$location,'id:'.$location]);$periodId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales_item_periods (organization_id,sales_period_id,menu_item_id,source_item_key,source_item_id,item_name,category_name,quantity,gross_sales,net_sales) VALUES (?,?,?,?,?,'Margherita Pizza','Pizza',10,110,100)")->execute([$org,$periodId,$menuItem,'id:marg','MARG']);
$profile=sales_cost_save_profile($pdo,$org,['targetType'=>'menu_item','menuItemId'=>$menuItem,'costMethod'=>'recipe','recipeId'=>$recipePublic,'servingsPerRecipe'=>2,'wasteFactorPercent'=>10],$uid);scm_assert((string)$profile['cost_method']==='recipe','Recipe profile should save.');

$pdo->prepare("INSERT INTO inventory_transactions (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,note,created_by,created_at) VALUES (?,?,'waste',-2,18,'lb','CI waste',?,'2026-09-11 12:00:00')")->execute([$org,(int)$flour['id'],$uid]);
$vendorPublic='vendor-'.$slug;$pdo->prepare("INSERT INTO vendors (organization_id,public_id,name,status,created_by,updated_by) VALUES (?,?,'CI Foods','active',?,?)")->execute([$org,$vendorPublic,$uid,$uid]);$vendor=(int)$pdo->lastInsertId();
$poPublic='po-'.$slug;$pdo->prepare("INSERT INTO purchase_orders (organization_id,public_id,vendor_id,order_number,status,subtotal,total_amount,created_by,updated_by) VALUES (?,?,? ,?,'received',20,20,?,?)")->execute([$org,$poPublic,$vendor,'CI-'.$slug,$uid,$uid]);$po=(int)$pdo->lastInsertId();
$poiPublic='poi-'.$slug;$pdo->prepare("INSERT INTO purchase_order_items (organization_id,purchase_order_id,inventory_item_id,public_id,description,ordered_packs,pack_size,unit,ordered_base_quantity,received_base_quantity,price_per_pack,line_total) VALUES (?,?,?,?, 'Flour',2,5,'lb',10,5,10,20)")->execute([$org,$po,(int)$flour['id'],$poiPublic]);$poi=(int)$pdo->lastInsertId();
$receiptPublic='receipt-'.$slug;$pdo->prepare("INSERT INTO goods_receipts (organization_id,purchase_order_id,vendor_id,public_id,receipt_number,received_at,received_by) VALUES (?,?,?,?,?,'2026-09-11 10:00:00',?)")->execute([$org,$po,$vendor,$receiptPublic,'R-'.$slug,$uid]);$receipt=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO goods_receipt_items (organization_id,goods_receipt_id,purchase_order_item_id,inventory_item_id,public_id,received_base_quantity,unit,actual_price_per_pack) VALUES (?,?,?,?,?,5,'lb',12)")->execute([$org,$receipt,$poi,(int)$flour['id'],'gri-'.$slug]);
$pdo->prepare("INSERT INTO vendor_price_history (organization_id,vendor_id,inventory_item_id,price_per_pack,pack_size,unit,source_type,effective_at,created_by) VALUES (?,?,?,10,5,'lb','manual','2026-08-01 00:00:00',?),(?,?,?,12,5,'lb','receipt','2026-09-11 00:00:00',?)")->execute([$org,$vendor,(int)$flour['id'],$uid,$org,$vendor,(int)$flour['id'],$uid]);

$report=sales_cost_report($pdo,$org,'2026-09-01','2026-09-30',$location);$t=$report['totals'];scm_assert(abs((float)$t['netSales']-100)<0.01,'Net sales should be $100.');scm_assert(abs((float)$t['theoreticalCogsKnownItems']-16.50)<0.01,'Theoretical COGS should be $16.50.');scm_assert(abs((float)$t['grossProfitKnownItemsBeforeLaborOverhead']-83.50)<0.01,'Gross profit before labor/overhead should be $83.50.');scm_assert(abs((float)$t['grossMarginKnownItemsPercent']-83.50)<0.01,'Gross margin should be 83.5%.');scm_assert(abs((float)$t['costCoveragePercent']-100)<0.01,'Cost coverage should be 100%.');scm_assert(abs((float)$t['purchaseReceipts']-12)<0.01,'Received-purchase spend should use actual pack price and equal $12.');scm_assert(abs((float)$t['wasteCurrentCost']-4)<0.01,'Waste current cost should equal $4.');scm_assert(count($report['priceDrift'])>=1&&abs((float)$report['priceDrift'][0]['changePercent']-20)<0.01,'Vendor unit cost drift should be +20%.');scm_assert(str_contains($report['disclaimer'],'not period COGS'),'Report must distinguish purchase spend from COGS.');

$manual=sales_cost_save_profile($pdo,$org,['id'=>$profile['public_id'],'targetType'=>'menu_item','menuItemId'=>$menuItem,'costMethod'=>'manual','manualUnitCost'=>2.25,'servingsPerRecipe'=>1,'wasteFactorPercent'=>0],$uid);scm_assert((string)$manual['cost_method']==='manual','Manual cost override should save.');$manualReport=sales_cost_report($pdo,$org,'2026-09-01','2026-09-30',$location);scm_assert(abs((float)$manualReport['totals']['theoreticalCogsKnownItems']-22.50)<0.01,'Manual item cost should drive COGS after override.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Margin Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$other=sales_cost_report($pdo,$otherOrg,'2026-09-01','2026-09-30',null);scm_assert((float)$other['totals']['netSales']===0.0,'Cross-organization sales cost data leaked.');scm_assert(count($other['items'])===0,'Cross-organization item margin data leaked.');

echo "sales-cost-margin contract passed\n";
