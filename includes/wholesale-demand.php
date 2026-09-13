<?php
declare(strict_types=1);

require_once __DIR__.'/wholesale-commerce.php';
require_once __DIR__.'/unit-conversion.php';

function wholesale_demand_ready(PDO $pdo): bool
{
    foreach (['inventory_commitments','wholesale_orders','wholesale_order_items','wholesale_skus','wholesale_products','recipes','inventory_item_sources','inventory_items'] as $table) {
        if (!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}

function wholesale_demand_committed_status(string $status): bool
{
    return in_array($status,['confirmed','in_production','ready','out_for_delivery'],true);
}

function wholesale_demand_order(PDO $pdo,int $org,string|int $id): ?array
{
    if(is_int($id)||ctype_digit((string)$id)){$q=$pdo->prepare('SELECT * FROM wholesale_orders WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,(int)$id]);}
    else{$q=$pdo->prepare('SELECT * FROM wholesale_orders WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,(string)$id]);}
    $row=$q->fetch();return $row?:null;
}

function wholesale_demand_order_date(array $order): string
{
    foreach(['promised_for','requested_for'] as $field){$raw=trim((string)($order[$field]??''));if($raw!==''){$date=substr($raw,0,10);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if($dt&&$dt->format('Y-m-d')===$date)return $date;}}
    $created=trim((string)($order['created_at']??''));return $created!==''?substr($created,0,10):date('Y-m-d');
}

function wholesale_demand_recipe_batches(array $row): ?float
{
    $ordered=max(0,(float)($row['order_quantity']??0));if($ordered<=0)return null;
    $content=(float)($row['content_quantity']??0);$contentUom=trim((string)($row['content_uom']??''));$sellUom=trim((string)($row['sell_uom_snapshot']??''));
    $explicit=(float)($row['recipe_yield_per_batch']??0);$explicitUom=trim((string)($row['recipe_yield_unit']??''));
    if($explicit>0){
        if($explicitUom==='')return $ordered/$explicit;
        if($sellUom!==''){$yieldInSell=restaurant_unit_convert($explicit,$explicitUom,$sellUom);if($yieldInSell!==null&&$yieldInSell>0)return $ordered/$yieldInSell;}
        if($content>0&&$contentUom!==''){$yieldInContent=restaurant_unit_convert($explicit,$explicitUom,$contentUom);if($yieldInContent!==null&&$yieldInContent>0)return ($ordered*$content)/$yieldInContent;}
        return null;
    }
    $recipeYield=(float)($row['yield_quantity']??0);$recipeUom=trim((string)($row['yield_unit']??''));
    if($content<=0||$contentUom===''||$recipeYield<=0||$recipeUom==='')return null;
    $yieldInContentUnit=restaurant_unit_convert($recipeYield,$recipeUom,$contentUom);
    if($yieldInContentUnit===null||$yieldInContentUnit<=0)return null;
    return ($ordered*$content)/$yieldInContentUnit;
}

function wholesale_demand_release_order(PDO $pdo,int $org,string $orderPublic,?int $userId=null): int
{
    $q=$pdo->prepare("UPDATE inventory_commitments SET status='released',released_at=COALESCE(released_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND source_type='wholesale_order' AND source_parent_public_id=? AND status='active'");
    $q->execute([$userId,$org,$orderPublic]);return $q->rowCount();
}

function wholesale_demand_line_rows(PDO $pdo,int $org,int $orderId): array
{
    $q=$pdo->prepare("SELECT oi.line_number,oi.quantity order_quantity,oi.sell_uom_snapshot,oi.sku_snapshot,oi.item_name_snapshot,oi.wholesale_sku_id,s.public_id sku_public_id,s.recipe_yield_per_batch,s.recipe_yield_unit,s.content_quantity,s.content_uom,p.public_id product_public_id,p.name product_name,p.recipe_id,r.public_id recipe_public_id,r.name recipe_name,r.yield_quantity,r.yield_unit FROM wholesale_order_items oi LEFT JOIN wholesale_skus s ON s.id=oi.wholesale_sku_id AND s.organization_id=oi.organization_id LEFT JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id LEFT JOIN recipes r ON r.id=p.recipe_id AND r.organization_id=p.organization_id AND r.status='active' AND r.archived_at IS NULL WHERE oi.organization_id=? AND oi.wholesale_order_id=? ORDER BY oi.line_number");
    $q->execute([$org,$orderId]);return $q->fetchAll();
}

function wholesale_demand_sync_order(PDO $pdo,int $org,string|int $orderId,?int $userId=null): array
{
    if(!wholesale_demand_ready($pdo))throw new RuntimeException('Wholesale demand commitment migration is not installed.');
    $order=wholesale_demand_order($pdo,$org,$orderId);if(!$order)throw new InvalidArgumentException('Wholesale order not found.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $released=wholesale_demand_release_order($pdo,$org,(string)$order['public_id'],$userId);
        $result=['orderId'=>$order['public_id'],'orderNumber'=>$order['order_number'],'status'=>$order['status'],'commitmentDate'=>wholesale_demand_order_date($order),'created'=>0,'released'=>$released,'issues'=>[],'ingredientQuantity'=>0.0];
        if(!wholesale_demand_committed_status((string)$order['status'])){if($ownsTransaction)$pdo->commit();return $result;}
        $lines=wholesale_demand_line_rows($pdo,$org,(int)$order['id']);
        if(!$lines){$result['issues'][]=['type'=>'no_normalized_lines','message'=>'Order has no normalized wholesale lines and cannot create inventory commitments.'];if($ownsTransaction)$pdo->commit();return $result;}
        $ingredientQ=$pdo->prepare("SELECT s.quantity_per_source,s.unit,i.id inventory_item_id,i.public_id inventory_public_id,i.name inventory_name,i.base_unit FROM inventory_item_sources s JOIN inventory_items i ON i.id=s.inventory_item_id AND i.organization_id=s.organization_id WHERE s.organization_id=? AND s.source_type='recipe' AND s.source_public_id=? AND s.quantity_per_source IS NOT NULL AND s.quantity_per_source>0 AND i.archived_at IS NULL AND i.status='active'");
        $upsert=$pdo->prepare("INSERT INTO inventory_commitments (organization_id,public_id,inventory_item_id,source_type,source_public_id,source_parent_public_id,commitment_date,quantity,unit,status,basis_json,created_by,updated_by,released_at) VALUES (?,?,?,'wholesale_order',?,?,?,?,?,'active',?,?,?,NULL) ON DUPLICATE KEY UPDATE commitment_date=VALUES(commitment_date),quantity=VALUES(quantity),unit=VALUES(unit),status='active',basis_json=VALUES(basis_json),updated_by=VALUES(updated_by),released_at=NULL,updated_at=NOW(6)");
        foreach($lines as $line){
            $lineNo=(int)$line['line_number'];$sourcePublic=(string)$order['public_id'].':line:'.$lineNo;
            if(empty($line['wholesale_sku_id'])){$result['issues'][]=['type'=>'legacy_line','line'=>$lineNo,'message'=>'Legacy/free-form order line cannot be converted into recipe demand.'];continue;}
            if(empty($line['recipe_public_id'])){$result['issues'][]=['type'=>'missing_recipe','line'=>$lineNo,'sku'=>$line['sku_snapshot'],'message'=>'Wholesale SKU product is not mapped to an active recipe.'];continue;}
            $batches=wholesale_demand_recipe_batches($line);
            if($batches===null||$batches<=0){$result['issues'][]=['type'=>'missing_yield','line'=>$lineNo,'sku'=>$line['sku_snapshot'],'message'=>'Configure a compatible recipe yield or SKU content quantity/unit before this line can reserve ingredients.'];continue;}
            $ingredientQ->execute([$org,'recipe:'.$line['recipe_public_id']]);$ingredients=$ingredientQ->fetchAll();
            if(!$ingredients){$result['issues'][]=['type'=>'missing_inventory_mapping','line'=>$lineNo,'recipe'=>$line['recipe_name'],'message'=>'Recipe has no inventory source mappings.'];continue;}
            foreach($ingredients as $ingredient){
                $sourceQty=(float)$ingredient['quantity_per_source']*$batches;$from=trim((string)$ingredient['unit']);$to=trim((string)$ingredient['base_unit']);
                $baseQty=restaurant_unit_convert($sourceQty,$from,$to);
                if($baseQty===null){$result['issues'][]=['type'=>'unit_mismatch','line'=>$lineNo,'inventory'=>$ingredient['inventory_name'],'message'=>'Cannot convert ingredient unit '.$from.' to inventory base unit '.$to.'.'];continue;}
                $baseQty=round($baseQty,4);if($baseQty<=0)continue;
                $public='icommit-'.substr(hash('sha256',$org.'|wholesale_order|'.$sourcePublic.'|'.$ingredient['inventory_item_id']),0,24);
                $basis=['orderId'=>$order['public_id'],'orderNumber'=>$order['order_number'],'lineNumber'=>$lineNo,'sku'=>$line['sku_snapshot'],'sellQuantity'=>(float)$line['order_quantity'],'sellUom'=>$line['sell_uom_snapshot'],'recipeId'=>$line['recipe_public_id'],'recipeName'=>$line['recipe_name'],'recipeBatches'=>round($batches,6),'ingredientPerBatch'=>(float)$ingredient['quantity_per_source'],'ingredientUnit'=>$from,'inventoryBaseUnit'=>$to,'quantityBeforeConversion'=>round($sourceQty,6)];
                $upsert->execute([$org,$public,(int)$ingredient['inventory_item_id'],$sourcePublic,(string)$order['public_id'],$result['commitmentDate'],$baseQty,$to,json_encode($basis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$userId,$userId]);
                $result['created']++;$result['ingredientQuantity']+=$baseQty;
            }
        }
        $result['ingredientQuantity']=round($result['ingredientQuantity'],4);
        if($ownsTransaction)$pdo->commit();return $result;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function wholesale_demand_sync_organization(PDO $pdo,int $org,?int $userId=null): array
{
    if(!wholesale_demand_ready($pdo))throw new RuntimeException('Wholesale demand commitment migration is not installed.');
    $q=$pdo->prepare("SELECT DISTINCT o.id FROM wholesale_orders o LEFT JOIN inventory_commitments c ON c.organization_id=o.organization_id AND c.source_type='wholesale_order' AND c.source_parent_public_id=o.public_id AND c.status='active' WHERE o.organization_id=? AND (o.status IN ('confirmed','in_production','ready','out_for_delivery') OR c.id IS NOT NULL) ORDER BY o.id");$q->execute([$org]);
    $orders=[];$issues=[];$created=0;$released=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=wholesale_demand_sync_order($pdo,$org,(int)$id,$userId);$orders[]=$r;$created+=(int)$r['created'];$released+=(int)$r['released'];foreach($r['issues'] as $issue)$issues[]=['orderId'=>$r['orderId']]+$issue;}
    return ['orders'=>count($orders),'created'=>$created,'released'=>$released,'issues'=>$issues,'results'=>$orders];
}

function wholesale_demand_forecast(PDO $pdo,int $org,int $days=7): array
{
    if(!wholesale_demand_ready($pdo))throw new RuntimeException('Wholesale demand commitment migration is not installed.');
    $days=max(1,min(60,$days));
    $q=$pdo->prepare("SELECT i.public_id inventory_id,i.name,i.base_unit,i.on_hand_quantity,i.par_level,i.reorder_point,COALESCE(SUM(c.quantity),0) committed_quantity,COUNT(DISTINCT c.source_parent_public_id) order_count,MIN(c.commitment_date) next_commitment_date,MAX(c.commitment_date) last_commitment_date FROM inventory_items i LEFT JOIN inventory_commitments c ON c.organization_id=i.organization_id AND c.inventory_item_id=i.id AND c.source_type='wholesale_order' AND c.status='active' AND c.commitment_date<=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE i.organization_id=? AND i.archived_at IS NULL AND i.status='active' GROUP BY i.id HAVING committed_quantity>0 ORDER BY committed_quantity DESC,i.name");$q->execute([$days,$org]);$rows=[];$totals=['items'=>0,'orders'=>0,'shortages'=>0];
    foreach($q->fetchAll() as $r){$on=(float)$r['on_hand_quantity'];$committed=(float)$r['committed_quantity'];$available=$on-$committed;$target=$r['par_level']!==null?(float)$r['par_level']:($r['reorder_point']!==null?(float)$r['reorder_point']:0.0);$replenish=max(0,$target-$available);$shortage=max(0,-$available);$rows[]=['inventoryId'=>$r['inventory_id'],'name'=>$r['name'],'unit'=>$r['base_unit'],'onHand'=>$on,'committed'=>round($committed,4),'availableToPromise'=>round($available,4),'par'=>$r['par_level']!==null?(float)$r['par_level']:null,'reorderPoint'=>$r['reorder_point']!==null?(float)$r['reorder_point']:null,'replenishToTarget'=>round($replenish,4),'shortage'=>round($shortage,4),'orderCount'=>(int)$r['order_count'],'nextDate'=>$r['next_commitment_date'],'lastDate'=>$r['last_commitment_date']];$totals['items']++;if($shortage>0)$totals['shortages']++;}
    $oq=$pdo->prepare("SELECT DISTINCT source_parent_public_id FROM inventory_commitments WHERE organization_id=? AND source_type='wholesale_order' AND status='active' AND commitment_date<=DATE_ADD(CURDATE(),INTERVAL ? DAY)");$oq->execute([$org,$days]);$totals['orders']=count($oq->fetchAll(PDO::FETCH_COLUMN));
    return ['days'=>$days,'totals'=>$totals,'items'=>$rows];
}

function wholesale_demand_order_detail(PDO $pdo,int $org,string $publicId): array
{
    $order=wholesale_demand_order($pdo,$org,$publicId);if(!$order)throw new InvalidArgumentException('Wholesale order not found.');
    $q=$pdo->prepare("SELECT c.*,i.public_id inventory_public_id,i.name inventory_name,i.base_unit FROM inventory_commitments c JOIN inventory_items i ON i.id=c.inventory_item_id AND i.organization_id=c.organization_id WHERE c.organization_id=? AND c.source_type='wholesale_order' AND c.source_parent_public_id=? ORDER BY c.status='active' DESC,c.commitment_date,i.name");$q->execute([$org,$publicId]);$rows=$q->fetchAll();foreach($rows as &$r){$r['basis']=$r['basis_json']?json_decode((string)$r['basis_json'],true):null;unset($r['basis_json']);}
    return ['order'=>['id'=>$order['public_id'],'number'=>$order['order_number'],'status'=>$order['status'],'requestedFor'=>$order['requested_for'],'promisedFor'=>$order['promised_for']],'commitments'=>$rows];
}

function wholesale_demand_save_sku_production(PDO $pdo,int $org,array $input,int $userId): array
{
    $public=trim((string)($input['skuId']??''));if($public==='')throw new InvalidArgumentException('Wholesale SKU is required.');
    $q=$pdo->prepare("SELECT s.*,p.name product_name FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id WHERE s.organization_id=? AND s.public_id=? AND s.archived_at IS NULL LIMIT 1");$q->execute([$org,$public]);$sku=$q->fetch();if(!$sku)throw new InvalidArgumentException('Wholesale SKU not found.');
    $yield=($input['recipeYieldPerBatch']??'')!==''?(float)$input['recipeYieldPerBatch']:null;if($yield!==null&&$yield<=0)throw new InvalidArgumentException('Recipe yield per batch must be greater than zero.');
    $yieldUnit=mb_substr(trim((string)($input['recipeYieldUnit']??'')),0,80,'UTF-8')?:null;
    $content=($input['contentQuantity']??'')!==''?(float)$input['contentQuantity']:null;if($content!==null&&$content<=0)throw new InvalidArgumentException('Content quantity must be greater than zero.');
    $contentUom=mb_substr(trim((string)($input['contentUom']??'')),0,80,'UTF-8')?:null;
    if(($content===null)!==($contentUom===null))throw new InvalidArgumentException('Content quantity and content unit must be configured together.');
    $pdo->prepare('UPDATE wholesale_skus SET recipe_yield_per_batch=?,recipe_yield_unit=?,content_quantity=?,content_uom=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$yield,$yieldUnit,$content,$contentUom,$userId,$org,(int)$sku['id']]);
    $q=$pdo->prepare("SELECT s.public_id,s.sku,s.name,s.sell_uom,s.recipe_yield_per_batch,s.recipe_yield_unit,s.content_quantity,s.content_uom,p.name product_name,r.public_id recipe_id,r.name recipe_name,r.yield_quantity,r.yield_unit recipe_output_unit FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id LEFT JOIN recipes r ON r.id=p.recipe_id AND r.organization_id=p.organization_id WHERE s.organization_id=? AND s.id=?");$q->execute([$org,(int)$sku['id']]);return $q->fetch()?:[];
}

function wholesale_demand_production_profiles(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT s.public_id,s.sku,s.name,s.sell_uom,s.recipe_yield_per_batch,s.recipe_yield_unit,s.content_quantity,s.content_uom,p.name product_name,r.public_id recipe_id,r.name recipe_name,r.yield_quantity,r.yield_unit recipe_output_unit FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id LEFT JOIN recipes r ON r.id=p.recipe_id AND r.organization_id=p.organization_id AND r.status='active' AND r.archived_at IS NULL WHERE s.organization_id=? AND s.status='active' AND s.archived_at IS NULL ORDER BY p.name,s.name");$q->execute([$org]);return $q->fetchAll();
}
