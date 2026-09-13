<?php
declare(strict_types=1);

require_once __DIR__.'/sales-intelligence-core.php';
require_once __DIR__.'/purchasing-core.php';

function sales_cost_ready(PDO $pdo): bool
{
    return sales_intelligence_ready($pdo)
        && operations_core_ready($pdo)
        && restaurant_brain_table_ready($pdo,'recipes')
        && restaurant_brain_table_ready($pdo,'sales_cost_profiles');
}

function sales_cost_unit(string $unit): ?array
{
    $u=mb_strtolower(trim($unit),'UTF-8');
    $u=preg_replace('/\s+/u',' ',$u)??$u;
    $aliases=[
        'lbs'=>'lb','pound'=>'lb','pounds'=>'lb','ounces'=>'oz','ounce'=>'oz',
        'grams'=>'g','gram'=>'g','kilograms'=>'kg','kilogram'=>'kg',
        'milliliters'=>'ml','milliliter'=>'ml','liters'=>'l','liter'=>'l','litres'=>'l','litre'=>'l',
        'teaspoon'=>'tsp','teaspoons'=>'tsp','tablespoon'=>'tbsp','tablespoons'=>'tbsp',
        'cups'=>'cup','fluid ounce'=>'fl oz','fluid ounces'=>'fl oz','floz'=>'fl oz',
        'each'=>'ea','unit'=>'ea','units'=>'ea','piece'=>'ea','pieces'=>'ea','count'=>'ea'
    ];
    $u=$aliases[$u]??$u;
    $map=[
        'g'=>['dimension'=>'weight','factor'=>1.0],
        'kg'=>['dimension'=>'weight','factor'=>1000.0],
        'oz'=>['dimension'=>'weight','factor'=>28.349523125],
        'lb'=>['dimension'=>'weight','factor'=>453.59237],
        'ml'=>['dimension'=>'volume','factor'=>1.0],
        'l'=>['dimension'=>'volume','factor'=>1000.0],
        'tsp'=>['dimension'=>'volume','factor'=>4.92892159375],
        'tbsp'=>['dimension'=>'volume','factor'=>14.78676478125],
        'fl oz'=>['dimension'=>'volume','factor'=>29.5735295625],
        'cup'=>['dimension'=>'volume','factor'=>236.5882365],
        'ea'=>['dimension'=>'count','factor'=>1.0],
    ];
    if($u==='')return null;
    if(!isset($map[$u]))return ['unit'=>$u,'dimension'=>'unknown','factor'=>1.0];
    return ['unit'=>$u]+$map[$u];
}

function sales_cost_convert(float $quantity,string $fromUnit,string $toUnit): ?float
{
    $from=sales_cost_unit($fromUnit);$to=sales_cost_unit($toUnit);
    if(!$from&&!$to)return $quantity;
    if(!$from||!$to)return null;
    if($from['unit']===$to['unit'])return $quantity;
    if($from['dimension']==='unknown'||$to['dimension']==='unknown'||$from['dimension']!==$to['dimension'])return null;
    return $quantity*((float)$from['factor']/(float)$to['factor']);
}

function sales_cost_recipes(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT public_id,name,category,yield_quantity,yield_unit FROM recipes WHERE organization_id=? AND status='active' AND archived_at IS NULL ORDER BY name");
    $q->execute([$org]);return $q->fetchAll();
}

function sales_cost_profile(PDO $pdo,int $org,?int $menuItemId,string $sourceItemKey): ?array
{
    if($menuItemId){
        $q=$pdo->prepare("SELECT p.*,r.public_id recipe_public_id,r.name recipe_name,m.name menu_item_name FROM sales_cost_profiles p LEFT JOIN recipes r ON r.id=p.recipe_id LEFT JOIN menu_items m ON m.id=p.menu_item_id WHERE p.organization_id=? AND p.menu_item_id=? AND p.status='active' AND p.archived_at IS NULL LIMIT 1");
        $q->execute([$org,$menuItemId]);if($row=$q->fetch())return $row;
    }
    if($sourceItemKey!==''){
        $q=$pdo->prepare("SELECT p.*,r.public_id recipe_public_id,r.name recipe_name,m.name menu_item_name FROM sales_cost_profiles p LEFT JOIN recipes r ON r.id=p.recipe_id LEFT JOIN menu_items m ON m.id=p.menu_item_id WHERE p.organization_id=? AND p.source_item_key=? AND p.status='active' AND p.archived_at IS NULL LIMIT 1");
        $q->execute([$org,$sourceItemKey]);if($row=$q->fetch())return $row;
    }
    return null;
}

function sales_cost_profiles(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT p.*,r.public_id recipe_public_id,r.name recipe_name,m.name menu_item_name FROM sales_cost_profiles p LEFT JOIN recipes r ON r.id=p.recipe_id AND r.organization_id=p.organization_id LEFT JOIN menu_items m ON m.id=p.menu_item_id AND m.organization_id=p.organization_id WHERE p.organization_id=? AND p.archived_at IS NULL ORDER BY COALESCE(m.name,p.source_item_key),p.id");
    $q->execute([$org]);return $q->fetchAll();
}

function sales_cost_save_profile(PDO $pdo,int $org,array $in,int $uid): array
{
    $public=trim((string)($in['id']??''));
    $targetType=(string)($in['targetType']??'menu_item');$menuId=null;$sourceKey=null;
    if($targetType==='menu_item'){
        $menuId=(int)($in['menuItemId']??0);if($menuId<1)throw new InvalidArgumentException('Choose a menu item.');
        $q=$pdo->prepare('SELECT id FROM menu_items WHERE organization_id=? AND id=? AND is_active=1');$q->execute([$org,$menuId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Menu item not found.');
    }elseif($targetType==='source_item'){
        $sourceKey=mb_substr(trim((string)($in['sourceItemKey']??'')),0,220,'UTF-8');if($sourceKey==='')throw new InvalidArgumentException('Choose an imported sales item.');
        $q=$pdo->prepare('SELECT 1 FROM sales_item_periods WHERE organization_id=? AND source_item_key=? LIMIT 1');$q->execute([$org,$sourceKey]);if(!$q->fetchColumn())throw new InvalidArgumentException('Imported sales item not found.');
    }else throw new InvalidArgumentException('Invalid cost target.');
    $method=(string)($in['costMethod']??'recipe');if(!in_array($method,['recipe','manual'],true))throw new InvalidArgumentException('Cost method must be recipe or manual.');
    $recipeId=null;$manual=null;$servings=max(.0001,(float)($in['servingsPerRecipe']??1));$waste=max(0,min(100,(float)($in['wasteFactorPercent']??0)));
    if($method==='recipe'){
        $recipePublic=trim((string)($in['recipeId']??''));if($recipePublic==='')throw new InvalidArgumentException('Choose a recipe.');
        $q=$pdo->prepare("SELECT id FROM recipes WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$recipePublic]);$recipeId=(int)($q->fetchColumn()?:0);if(!$recipeId)throw new InvalidArgumentException('Recipe not found.');
    }else{
        if(!isset($in['manualUnitCost'])||$in['manualUnitCost']==='')throw new InvalidArgumentException('Manual unit cost is required.');$manual=max(0,(float)$in['manualUnitCost']);
    }
    $notes=mb_substr(trim((string)($in['notes']??'')),0,1000,'UTF-8')?:null;
    if($public!==''){
        $q=$pdo->prepare('SELECT id FROM sales_cost_profiles WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$org,$public]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new InvalidArgumentException('Cost profile not found.');
        $pdo->prepare("UPDATE sales_cost_profiles SET menu_item_id=?,source_item_key=?,recipe_id=?,cost_method=?,manual_unit_cost=?,servings_per_recipe=?,waste_factor_percent=?,notes=?,status='active',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$menuId,$sourceKey,$recipeId,$method,$manual,$servings,$waste,$notes,$uid,$org,$id]);
    }else{
        $public=sales_public_id('cost-profile');
        $pdo->prepare("INSERT INTO sales_cost_profiles (organization_id,public_id,menu_item_id,source_item_key,recipe_id,cost_method,manual_unit_cost,servings_per_recipe,waste_factor_percent,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE recipe_id=VALUES(recipe_id),cost_method=VALUES(cost_method),manual_unit_cost=VALUES(manual_unit_cost),servings_per_recipe=VALUES(servings_per_recipe),waste_factor_percent=VALUES(waste_factor_percent),notes=VALUES(notes),status='active',archived_at=NULL,updated_by=VALUES(updated_by),updated_at=NOW(6)")->execute([$org,$public,$menuId,$sourceKey,$recipeId,$method,$manual,$servings,$waste,$notes,$uid,$uid]);
        $q=$pdo->prepare('SELECT public_id FROM sales_cost_profiles WHERE organization_id=? AND '.($menuId?'menu_item_id=?':'source_item_key=?').' AND archived_at IS NULL LIMIT 1');$q->execute([$org,$menuId?:$sourceKey]);$public=(string)$q->fetchColumn();
    }
    foreach(sales_cost_profiles($pdo,$org) as $row)if((string)$row['public_id']===$public)return $row;
    throw new RuntimeException('Cost profile could not be loaded.');
}

function sales_cost_recipe(PDO $pdo,int $org,int $recipeId,float $servings,float $wastePct=0): array
{
    $q=$pdo->prepare("SELECT id,public_id,name,ingredients_json FROM recipes WHERE organization_id=? AND id=? AND status='active' AND archived_at IS NULL LIMIT 1");$q->execute([$org,$recipeId]);$recipe=$q->fetch();if(!$recipe)return ['unitCost'=>null,'coverage'=>0.0,'ingredients'=>[],'gaps'=>['Recipe not found.']];
    $ingredients=json_decode((string)($recipe['ingredients_json']??'[]'),true);if(!is_array($ingredients))$ingredients=[];
    $itemQ=$pdo->prepare("SELECT id,name,normalized_key,base_unit,unit_cost,updated_at FROM inventory_items WHERE organization_id=? AND normalized_key=? AND status='active' AND archived_at IS NULL LIMIT 1");
    $lines=[];$gaps=[];$total=0.0;$parsedCount=0;$costedCount=0;
    foreach($ingredients as $ingredient){
        $parsed=operations_parse_ingredient($ingredient);if(!$parsed)continue;$parsedCount++;$key=operations_slug(operations_normalized_ingredient((string)$parsed['name']));$itemQ->execute([$org,$key]);$inv=$itemQ->fetch()?:null;$line=['ingredient'=>$parsed['name'],'quantity'=>$parsed['quantity'],'unit'=>$parsed['unit'],'inventoryItem'=>$inv['name']??null,'inventoryUnit'=>$inv['base_unit']??null,'unitCost'=>isset($inv['unit_cost'])?(float)$inv['unit_cost']:null,'extendedCost'=>null,'status'=>'missing'];
        if(!$inv){$gaps[]=$parsed['name'].': inventory item not mapped.';$line['status']='inventory_missing';$lines[]=$line;continue;}
        if($parsed['quantity']===null){$gaps[]=$parsed['name'].': ingredient quantity missing.';$line['status']='quantity_missing';$lines[]=$line;continue;}
        if($inv['unit_cost']===null){$gaps[]=$parsed['name'].': inventory unit cost missing.';$line['status']='cost_missing';$lines[]=$line;continue;}
        $converted=sales_cost_convert((float)$parsed['quantity'],(string)$parsed['unit'],(string)($inv['base_unit']??''));
        if($converted===null){$gaps[]=$parsed['name'].': cannot convert '.($parsed['unit']?:'unspecified unit').' to '.(($inv['base_unit']??'')?:'unspecified inventory unit').'.';$line['status']='unit_mismatch';$lines[]=$line;continue;}
        $extended=$converted*(float)$inv['unit_cost'];$line['convertedQuantity']=round($converted,5);$line['extendedCost']=round($extended,4);$line['status']='costed';$total+=$extended;$costedCount++;$lines[]=$line;
    }
    $servings=max(.0001,$servings);$wasteMultiplier=1+max(0,$wastePct)/100;$unitCost=$parsedCount>0&&$costedCount>0?($total*$wasteMultiplier/$servings):null;$coverage=$parsedCount>0?$costedCount/$parsedCount:0;
    return ['recipePublicId'=>$recipe['public_id'],'recipeName'=>$recipe['name'],'recipeCost'=>round($total,4),'servingsPerRecipe'=>$servings,'wasteFactorPercent'=>$wastePct,'unitCost'=>$unitCost!==null?round($unitCost,4):null,'coverage'=>round($coverage,4),'costedIngredients'=>$costedCount,'ingredientCount'=>$parsedCount,'ingredients'=>$lines,'gaps'=>$gaps];
}

function sales_cost_resolve(PDO $pdo,int $org,?int $menuItemId,string $sourceItemKey,array &$cache=[]): array
{
    $cacheKey=($menuItemId?'m:'.$menuItemId:'s:'.$sourceItemKey);if(isset($cache[$cacheKey]))return $cache[$cacheKey];
    $profile=sales_cost_profile($pdo,$org,$menuItemId,$sourceItemKey);if(!$profile)return $cache[$cacheKey]=['known'=>false,'unitCost'=>null,'coverage'=>0.0,'method'=>'unmapped','profile'=>null,'gaps'=>['No cost profile mapped.']];
    if((string)$profile['cost_method']==='manual')return $cache[$cacheKey]=['known'=>$profile['manual_unit_cost']!==null,'unitCost'=>$profile['manual_unit_cost']!==null?(float)$profile['manual_unit_cost']:null,'coverage'=>$profile['manual_unit_cost']!==null?1.0:0.0,'method'=>'manual','profile'=>$profile,'gaps'=>$profile['manual_unit_cost']===null?['Manual unit cost missing.']:[]];
    if(!$profile['recipe_id'])return $cache[$cacheKey]=['known'=>false,'unitCost'=>null,'coverage'=>0.0,'method'=>'recipe','profile'=>$profile,'gaps'=>['Recipe mapping missing.']];
    $detail=sales_cost_recipe($pdo,$org,(int)$profile['recipe_id'],(float)$profile['servings_per_recipe'],(float)$profile['waste_factor_percent']);
    return $cache[$cacheKey]=['known'=>$detail['unitCost']!==null,'unitCost'=>$detail['unitCost'],'coverage'=>$detail['coverage'],'method'=>'recipe','profile'=>$profile,'recipe'=>$detail,'gaps'=>$detail['gaps']];
}

function sales_cost_targets(PDO $pdo,int $org,int $days=180): array
{
    $provider=sales_primary_provider($pdo,$org);$from=(new DateTimeImmutable('today'))->modify('-'.max(7,min(730,$days)).' days')->format('Y-m-d');
    $q=$pdo->prepare("SELECT sip.menu_item_id,sip.source_item_key,MAX(sip.item_name) item_name,MAX(sip.category_name) category_name,SUM(sip.quantity) quantity,SUM(sip.net_sales) net_sales FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.source_provider=? AND sp.granularity='daily' AND sp.service_period='all' AND sp.period_start>=? GROUP BY sip.menu_item_id,sip.source_item_key ORDER BY net_sales DESC,item_name");$q->execute([$org,$provider,$from]);$rows=$q->fetchAll();
    foreach($rows as &$row){$p=sales_cost_profile($pdo,$org,$row['menu_item_id']?(int)$row['menu_item_id']:null,(string)$row['source_item_key']);$row['profile']=$p;$row['targetType']=$row['menu_item_id']?'menu_item':'source_item';}unset($row);return $rows;
}

function sales_cost_purchase_spend(PDO $pdo,int $org,string $from,string $to): array
{
    if(!purchasing_ready($pdo))return ['recognizedSpend'=>0.0,'pricedLines'=>0,'unpricedLines'=>0,'scope'=>'organization'];$end=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
    $q=$pdo->prepare("SELECT gri.received_base_quantity,gri.actual_price_per_pack,poi.price_per_pack,poi.pack_size,poi.ordered_base_quantity,poi.line_total FROM goods_receipt_items gri JOIN goods_receipts gr ON gr.id=gri.goods_receipt_id AND gr.organization_id=gri.organization_id JOIN purchase_order_items poi ON poi.id=gri.purchase_order_item_id AND poi.organization_id=gri.organization_id WHERE gri.organization_id=? AND gr.received_at>=? AND gr.received_at<?");$q->execute([$org,$from.' 00:00:00',$end]);$spend=0.0;$priced=0;$unpriced=0;
    foreach($q->fetchAll() as $r){$qty=(float)$r['received_base_quantity'];$cost=null;if((float)$r['pack_size']>0&&$r['actual_price_per_pack']!==null)$cost=$qty/(float)$r['pack_size']*(float)$r['actual_price_per_pack'];elseif((float)$r['ordered_base_quantity']>0&&(float)$r['line_total']>0)$cost=$qty*((float)$r['line_total']/(float)$r['ordered_base_quantity']);elseif((float)$r['pack_size']>0&&$r['price_per_pack']!==null)$cost=$qty/(float)$r['pack_size']*(float)$r['price_per_pack'];if($cost===null){$unpriced++;continue;}$spend+=$cost;$priced++;}
    return ['recognizedSpend'=>round($spend,2),'pricedLines'=>$priced,'unpricedLines'=>$unpriced,'scope'=>'organization'];
}

function sales_cost_waste(PDO $pdo,int $org,string $from,string $to): array
{
    $end=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');$q=$pdo->prepare("SELECT t.quantity_delta,i.unit_cost FROM inventory_transactions t JOIN inventory_items i ON i.id=t.inventory_item_id AND i.organization_id=t.organization_id WHERE t.organization_id=? AND t.transaction_type='waste' AND t.created_at>=? AND t.created_at<?");$q->execute([$org,$from.' 00:00:00',$end]);$cost=0.0;$count=0;$unpriced=0;foreach($q->fetchAll() as $r){$count++;if($r['unit_cost']===null){$unpriced++;continue;}$cost+=abs((float)$r['quantity_delta'])*(float)$r['unit_cost'];}return ['currentCost'=>round($cost,2),'transactions'=>$count,'unpricedTransactions'=>$unpriced,'scope'=>'organization','basis'=>'current inventory unit cost'];
}

function sales_cost_price_drift(PDO $pdo,int $org,string $to): array
{
    if(!purchasing_ready($pdo))return [];$q=$pdo->prepare("SELECT h.inventory_item_id,i.name,h.price_per_pack,h.pack_size,h.unit,h.effective_at,v.name vendor_name FROM vendor_price_history h JOIN inventory_items i ON i.id=h.inventory_item_id AND i.organization_id=h.organization_id JOIN vendors v ON v.id=h.vendor_id AND v.organization_id=h.organization_id WHERE h.organization_id=? AND h.effective_at<? ORDER BY h.inventory_item_id,h.effective_at DESC,h.id DESC LIMIT 2000");$q->execute([$org,(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')]);$seen=[];$out=[];foreach($q->fetchAll() as $r){$id=(int)$r['inventory_item_id'];if(!isset($seen[$id])){$seen[$id]=[$r];continue;}if(count($seen[$id])===1){$seen[$id][]=$r;$latest=$seen[$id][0];$prior=$r;$latestUnit=(float)$latest['pack_size']>0?(float)$latest['price_per_pack']/(float)$latest['pack_size']:null;$priorUnit=(float)$prior['pack_size']>0?(float)$prior['price_per_pack']/(float)$prior['pack_size']:null;if($latestUnit!==null&&$priorUnit!==null&&$priorUnit>0){$out[]=['inventoryItem'=>$latest['name'],'vendor'=>$latest['vendor_name'],'latestUnitCost'=>round($latestUnit,4),'priorUnitCost'=>round($priorUnit,4),'changePercent'=>round(($latestUnit-$priorUnit)/$priorUnit*100,2),'latestAt'=>$latest['effective_at'],'priorAt'=>$prior['effective_at']];}}}
    usort($out,static fn($a,$b)=>abs($b['changePercent'])<=>abs($a['changePercent']));return array_slice($out,0,25);
}

function sales_cost_report(PDO $pdo,int $org,string $from,string $to,?int $locationId=null): array
{
    if(!sales_cost_ready($pdo))throw new RuntimeException('Sales Cost Intelligence migration is not installed.');$provider=sales_primary_provider($pdo,$org);$location=sales_location($pdo,$org,'',$locationId);
    $q=$pdo->prepare("SELECT sip.menu_item_id,sip.source_item_key,MAX(sip.item_name) item_name,MAX(sip.category_name) category_name,SUM(sip.quantity) quantity,SUM(sip.net_sales) net_sales FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.source_provider=? AND sp.location_key=? AND sp.granularity='daily' AND sp.service_period='all' AND sp.period_start BETWEEN ? AND ? GROUP BY sip.menu_item_id,sip.source_item_key ORDER BY net_sales DESC");$q->execute([$org,$provider,$location['key'],$from,$to]);$salesItems=$q->fetchAll();
    $cache=[];$items=[];$trackedSales=0.0;$costedSales=0.0;$cogs=0.0;$knownUnits=0.0;$allUnits=0.0;
    foreach($salesItems as $row){$qty=(float)$row['quantity'];$sales=(float)$row['net_sales'];$cost=sales_cost_resolve($pdo,$org,$row['menu_item_id']?(int)$row['menu_item_id']:null,(string)$row['source_item_key'],$cache);$unit=$cost['unitCost'];$itemCogs=$unit!==null?$qty*(float)$unit:null;$profit=$itemCogs!==null?$sales-$itemCogs:null;$margin=$profit!==null&&$sales!=0?$profit/$sales*100:null;$trackedSales+=$sales;$allUnits+=$qty;if($unit!==null){$costedSales+=$sales;$cogs+=(float)$itemCogs;$knownUnits+=$qty;}
        $items[]=['menuItemId'=>$row['menu_item_id']?(int)$row['menu_item_id']:null,'sourceItemKey'=>$row['source_item_key'],'itemName'=>$row['item_name'],'category'=>$row['category_name'],'quantity'=>round($qty,3),'netSales'=>round($sales,2),'costMethod'=>$cost['method'],'unitCost'=>$unit!==null?round((float)$unit,4):null,'costCoverage'=>round((float)$cost['coverage'],4),'theoreticalCogs'=>$itemCogs!==null?round($itemCogs,2):null,'grossProfitBeforeLaborOverhead'=>$profit!==null?round($profit,2):null,'grossMarginPercent'=>$margin!==null?round($margin,2):null,'gaps'=>$cost['gaps'],'profile'=>$cost['profile']?['id'=>$cost['profile']['public_id'],'recipeName'=>$cost['profile']['recipe_name']??null,'servingsPerRecipe'=>(float)$cost['profile']['servings_per_recipe'],'wasteFactorPercent'=>(float)$cost['profile']['waste_factor_percent']]:null];
    }
    $sales=sales_dashboard($pdo,$org,$from,$to,$locationId);$purchase=sales_cost_purchase_spend($pdo,$org,$from,$to);$waste=sales_cost_waste($pdo,$org,$from,$to);$gross=$costedSales-$cogs;
    return ['provider'=>$provider,'location'=>$location,'from'=>$from,'to'=>$to,'totals'=>['netSales'=>(float)$sales['totals']['netSales'],'trackedItemSales'=>round($trackedSales,2),'costedItemSales'=>round($costedSales,2),'costCoveragePercent'=>$trackedSales>0?round($costedSales/$trackedSales*100,2):0.0,'unitCoveragePercent'=>$allUnits>0?round($knownUnits/$allUnits*100,2):0.0,'theoreticalCogsKnownItems'=>round($cogs,2),'grossProfitKnownItemsBeforeLaborOverhead'=>round($gross,2),'grossMarginKnownItemsPercent'=>$costedSales>0?round($gross/$costedSales*100,2):null,'purchaseReceipts'=>$purchase['recognizedSpend'],'wasteCurrentCost'=>$waste['currentCost']], 'items'=>$items,'purchaseSpend'=>$purchase,'waste'=>$waste,'priceDrift'=>sales_cost_price_drift($pdo,$org,$to),'targets'=>sales_cost_targets($pdo,$org),'profiles'=>sales_cost_profiles($pdo,$org),'recipes'=>sales_cost_recipes($pdo,$org),'disclaimer'=>'Theoretical COGS uses current mapped ingredient/manual unit costs. Purchase receipts are cash/inventory inflow, not period COGS. Gross profit shown is before labor, occupancy, taxes and overhead.'];
}
