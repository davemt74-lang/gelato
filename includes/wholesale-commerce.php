<?php
declare(strict_types=1);

require_once __DIR__ . '/wholesale-portal.php';

function wholesale_commerce_ready(PDO $pdo): bool
{
    foreach (['wholesale_products','wholesale_skus','wholesale_price_lists','wholesale_price_list_items','wholesale_account_price_lists','wholesale_quote_items','wholesale_order_items','wholesale_quote_events','wholesale_order_events'] as $table) {
        if (!wholesale_portal_table_ready($pdo,$table)) return false;
    }
    return true;
}

function wholesale_commerce_public_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

function wholesale_commerce_date(?string $value=null): string
{
    if ($value === null || trim($value) === '') return date('Y-m-d');
    $date=DateTimeImmutable::createFromFormat('Y-m-d',trim($value));
    if(!$date || $date->format('Y-m-d')!==trim($value)) throw new InvalidArgumentException('Invalid pricing date.');
    return $date->format('Y-m-d');
}

function wholesale_commerce_account(PDO $pdo,int $org,int $accountId): array
{
    $q=$pdo->prepare("SELECT * FROM wholesale_accounts WHERE organization_id=? AND id=? AND archived_at IS NULL LIMIT 1");
    $q->execute([$org,$accountId]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Wholesale account not found.');
    return $row;
}

function wholesale_commerce_price_list_for_account(PDO $pdo,int $org,int $accountId,?string $onDate=null): ?array
{
    $date=wholesale_commerce_date($onDate);
    $q=$pdo->prepare("SELECT p.* FROM wholesale_account_price_lists a JOIN wholesale_price_lists p ON p.id=a.price_list_id AND p.organization_id=a.organization_id WHERE a.organization_id=? AND a.wholesale_account_id=? AND p.status='active' AND p.archived_at IS NULL AND (a.effective_from IS NULL OR a.effective_from<=?) AND (a.effective_until IS NULL OR a.effective_until>=?) AND (p.effective_from IS NULL OR p.effective_from<=?) AND (p.effective_until IS NULL OR p.effective_until>=?) ORDER BY COALESCE(a.effective_from,'1000-01-01') DESC,a.id DESC LIMIT 1");
    $q->execute([$org,$accountId,$date,$date,$date,$date]);
    if($row=$q->fetch()) return $row;
    $q=$pdo->prepare("SELECT * FROM wholesale_price_lists WHERE organization_id=? AND status='active' AND archived_at IS NULL AND is_default=1 AND (effective_from IS NULL OR effective_from<=?) AND (effective_until IS NULL OR effective_until>=?) ORDER BY COALESCE(effective_from,'1000-01-01') DESC,id DESC LIMIT 1");
    $q->execute([$org,$date,$date]);$row=$q->fetch();
    return $row?:null;
}

function wholesale_commerce_catalog(PDO $pdo,int $org,?int $accountId=null,?string $onDate=null): array
{
    $date=wholesale_commerce_date($onDate);$priceList=$accountId?wholesale_commerce_price_list_for_account($pdo,$org,$accountId,$date):null;
    if(!$priceList){$q=$pdo->prepare("SELECT * FROM wholesale_price_lists WHERE organization_id=? AND status='active' AND archived_at IS NULL AND is_default=1 ORDER BY id DESC LIMIT 1");$q->execute([$org]);$priceList=$q->fetch()?:null;}
    $q=$pdo->prepare("SELECT s.*,p.public_id product_public_id,p.name product_name,p.category,p.recipe_id FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id WHERE s.organization_id=? AND s.status='active' AND s.archived_at IS NULL AND p.status='active' AND p.archived_at IS NULL ORDER BY p.category,p.name,s.name");
    $q->execute([$org]);
    $price=$pdo->prepare("SELECT * FROM wholesale_price_list_items WHERE organization_id=? AND price_list_id=? AND wholesale_sku_id=? AND (effective_from IS NULL OR effective_from<=?) AND (effective_until IS NULL OR effective_until>=?) ORDER BY COALESCE(effective_from,'1000-01-01') DESC,id DESC LIMIT 1");
    $rows=[];
    foreach($q->fetchAll() as $row){
        $priceRow=null;
        if($priceList){$price->execute([$org,(int)$priceList['id'],(int)$row['id'],$date,$date]);$priceRow=$price->fetch()?:null;}
        $rows[]=['id'=>$row['public_id'],'sku'=>$row['sku'],'name'=>$row['name'],'productId'=>$row['product_public_id'],'productName'=>$row['product_name'],'category'=>$row['category'],'sellUom'=>$row['sell_uom'],'unitsPerSellUom'=>(float)$row['units_per_sell_uom'],'minimumQuantity'=>(float)$row['minimum_quantity'],'quantityIncrement'=>(float)$row['quantity_increment'],'unitPrice'=>$priceRow?(float)$priceRow['unit_price']:null,'priceMinimumQuantity'=>$priceRow&&$priceRow['minimum_quantity']!==null?(float)$priceRow['minimum_quantity']:null,'priceList'=>$priceList?['id'=>$priceList['public_id'],'name'=>$priceList['name'],'minimumOrderAmount'=>(float)$priceList['minimum_order_amount'],'currency'=>$priceList['currency']]:null];
    }
    return $rows;
}

function wholesale_commerce_resolve_lines(PDO $pdo,int $org,int $accountId,array $items,?string $onDate=null): array
{
    if(!$items) throw new InvalidArgumentException('Add at least one wholesale item.');
    $date=wholesale_commerce_date($onDate);$priceList=null;$resolved=[];$snapshot=[];$subtotal=0.0;
    $skuQ=$pdo->prepare("SELECT s.*,p.name product_name,p.public_id product_public_id FROM wholesale_skus s JOIN wholesale_products p ON p.id=s.wholesale_product_id AND p.organization_id=s.organization_id WHERE s.organization_id=? AND s.public_id=? AND s.status='active' AND s.archived_at IS NULL AND p.status='active' AND p.archived_at IS NULL LIMIT 1");
    foreach(array_values(array_slice($items,0,100)) as $index=>$raw){
        if(!is_array($raw)) throw new InvalidArgumentException('Wholesale line '.($index+1).' is invalid.');
        $quantity=(float)($raw['quantity']??0);if($quantity<=0)throw new InvalidArgumentException('Wholesale line '.($index+1).' quantity must be greater than zero.');
        $skuPublic=trim((string)($raw['skuId']??$raw['sku_id']??''));
        if($skuPublic!==''){
            $skuQ->execute([$org,$skuPublic]);$sku=$skuQ->fetch();if(!$sku)throw new InvalidArgumentException('Wholesale SKU not found for line '.($index+1).'.');
            if(!$priceList)$priceList=wholesale_commerce_price_list_for_account($pdo,$org,$accountId,$date);if(!$priceList)throw new RuntimeException('No active wholesale price list is available for this account.');
            $pq=$pdo->prepare("SELECT * FROM wholesale_price_list_items WHERE organization_id=? AND price_list_id=? AND wholesale_sku_id=? AND (effective_from IS NULL OR effective_from<=?) AND (effective_until IS NULL OR effective_until>=?) ORDER BY COALESCE(effective_from,'1000-01-01') DESC,id DESC LIMIT 1");$pq->execute([$org,(int)$priceList['id'],(int)$sku['id'],$date,$date]);$price=$pq->fetch();if(!$price)throw new InvalidArgumentException('No active price is configured for SKU '.$sku['sku'].'.');
            $minimum=$price['minimum_quantity']!==null?(float)$price['minimum_quantity']:(float)$sku['minimum_quantity'];$increment=max(.0001,(float)$sku['quantity_increment']);
            if($quantity+0.000001<$minimum)throw new InvalidArgumentException($sku['name'].' requires a minimum quantity of '.$minimum.'.');
            $steps=$quantity/$increment;if(abs($steps-round($steps))>0.000001)throw new InvalidArgumentException($sku['name'].' must be ordered in increments of '.$increment.'.');
            $unit=(float)$price['unit_price'];$line=round($quantity*$unit,2);
            $resolved[]=['skuId'=>(int)$sku['id'],'skuPublicId'=>$sku['public_id'],'sku'=>$sku['sku'],'name'=>$sku['name'],'quantity'=>$quantity,'sellUom'=>$sku['sell_uom'],'unitPrice'=>$unit,'lineSubtotal'=>$line,'pricingSource'=>'canonical','metadata'=>['productId'=>$sku['product_public_id'],'productName'=>$sku['product_name'],'priceListId'=>$priceList['public_id']]];
            $snapshot[]=['skuId'=>$sku['public_id'],'sku'=>$sku['sku'],'quantity'=>$quantity,'product'=>$sku['name'],'unit'=>$sku['sell_uom'],'unitPrice'=>$unit,'lineSubtotal'=>$line];$subtotal+=$line;continue;
        }
        $name=mb_substr(trim((string)($raw['product']??$raw['name']??$raw['item']??'')),0,220,'UTF-8');$unitPrice=(float)($raw['unitPrice']??$raw['unit_price']??0);
        if($name===''||$unitPrice<0)throw new InvalidArgumentException('Legacy wholesale line '.($index+1).' requires a product name and valid unit price.');
        $uom=mb_substr(trim((string)($raw['unit']??'unit')),0,80,'UTF-8')?:'unit';$line=round($quantity*$unitPrice,2);
        $resolved[]=['skuId'=>null,'skuPublicId'=>null,'sku'=>null,'name'=>$name,'quantity'=>$quantity,'sellUom'=>$uom,'unitPrice'=>$unitPrice,'lineSubtotal'=>$line,'pricingSource'=>'legacy','metadata'=>[]];
        $snapshot[]=['quantity'=>$quantity,'product'=>$name,'unit'=>$uom,'unitPrice'=>$unitPrice,'lineSubtotal'=>$line];$subtotal+=$line;
    }
    $subtotal=round($subtotal,2);
    if($priceList&&(float)$priceList['minimum_order_amount']>0&&$subtotal+0.0001<(float)$priceList['minimum_order_amount'])throw new InvalidArgumentException('Order subtotal is below the '.$priceList['name'].' minimum of $'.number_format((float)$priceList['minimum_order_amount'],2).'.');
    return ['priceList'=>$priceList,'lines'=>$resolved,'snapshot'=>$snapshot,'subtotal'=>$subtotal];
}

function wholesale_commerce_totals(float $subtotal,mixed $deliveryFee,mixed $taxRatePercent): array
{
    $delivery=max(0,round((float)$deliveryFee,2));$rate=max(0,min(30,(float)$taxRatePercent));$tax=round($subtotal*$rate/100,2);
    return ['subtotal'=>round($subtotal,2),'deliveryFee'=>$delivery,'taxRatePercent'=>$rate,'taxTotal'=>$tax,'total'=>round($subtotal+$delivery+$tax,2)];
}

function wholesale_commerce_quote_event(PDO $pdo,int $org,int $quoteId,string $type,string $summary,?int $userId=null,array $metadata=[]): void
{
    if(!wholesale_portal_table_ready($pdo,'wholesale_quote_events'))return;
    $q=$pdo->prepare('INSERT INTO wholesale_quote_events (organization_id,wholesale_quote_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');$q->execute([$org,$quoteId,mb_substr($type,0,60,'UTF-8'),mb_substr($summary,0,500,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId]);
}

function wholesale_commerce_order_event(PDO $pdo,int $org,int $orderId,string $type,string $summary,?int $userId=null,array $metadata=[]): void
{
    if(!wholesale_portal_table_ready($pdo,'wholesale_order_events'))return;
    $q=$pdo->prepare('INSERT INTO wholesale_order_events (organization_id,wholesale_order_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)');$q->execute([$org,$orderId,mb_substr($type,0,60,'UTF-8'),mb_substr($summary,0,500,'UTF-8'),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null,$userId]);
}

function wholesale_commerce_insert_quote_lines(PDO $pdo,int $org,int $quoteId,array $lines): void
{
    $q=$pdo->prepare("INSERT INTO wholesale_quote_items (organization_id,wholesale_quote_id,line_number,wholesale_sku_id,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach(array_values($lines) as $i=>$line)$q->execute([$org,$quoteId,$i+1,$line['skuId'],$line['sku'],$line['name'],$line['quantity'],$line['sellUom'],$line['unitPrice'],$line['lineSubtotal'],$line['pricingSource'],$line['metadata']?json_encode($line['metadata'],JSON_THROW_ON_ERROR):null]);
}

function wholesale_commerce_insert_order_lines(PDO $pdo,int $org,int $orderId,array $lines): void
{
    $q=$pdo->prepare("INSERT INTO wholesale_order_items (organization_id,wholesale_order_id,line_number,wholesale_sku_id,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach(array_values($lines) as $i=>$line)$q->execute([$org,$orderId,$i+1,$line['skuId'],$line['sku'],$line['name'],$line['quantity'],$line['sellUom'],$line['unitPrice'],$line['lineSubtotal'],$line['pricingSource'],$line['metadata']?json_encode($line['metadata'],JSON_THROW_ON_ERROR):null]);
}

function wholesale_commerce_quote_lines(PDO $pdo,int $org,int $quoteId): array
{
    if(!wholesale_portal_table_ready($pdo,'wholesale_quote_items'))return [];
    $q=$pdo->prepare('SELECT line_number,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source FROM wholesale_quote_items WHERE organization_id=? AND wholesale_quote_id=? ORDER BY line_number');$q->execute([$org,$quoteId]);return $q->fetchAll();
}

function wholesale_commerce_order_lines(PDO $pdo,int $org,int $orderId): array
{
    if(!wholesale_portal_table_ready($pdo,'wholesale_order_items'))return [];
    $q=$pdo->prepare('SELECT line_number,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source FROM wholesale_order_items WHERE organization_id=? AND wholesale_order_id=? ORDER BY line_number');$q->execute([$org,$orderId]);return $q->fetchAll();
}

function wholesale_commerce_copy_quote_lines_to_order(PDO $pdo,int $org,int $quoteId,int $orderId): int
{
    if(!wholesale_commerce_ready($pdo))return 0;$q=$pdo->prepare('SELECT * FROM wholesale_quote_items WHERE organization_id=? AND wholesale_quote_id=? ORDER BY line_number');$q->execute([$org,$quoteId]);$rows=$q->fetchAll();if(!$rows)return 0;
    $ins=$pdo->prepare("INSERT INTO wholesale_order_items (organization_id,wholesale_order_id,line_number,wholesale_sku_id,sku_snapshot,item_name_snapshot,quantity,sell_uom_snapshot,unit_price_snapshot,line_subtotal,pricing_source,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach($rows as $row)$ins->execute([$org,$orderId,$row['line_number'],$row['wholesale_sku_id'],$row['sku_snapshot'],$row['item_name_snapshot'],$row['quantity'],$row['sell_uom_snapshot'],$row['unit_price_snapshot'],$row['line_subtotal'],$row['pricing_source'],$row['metadata_json']]);
    return count($rows);
}

function wholesale_commerce_create_quote(PDO $pdo,int $org,array $account,array $input,int $userId): array
{
    $status=(string)($input['status']??'sent');if(!in_array($status,['draft','sent'],true))$status='sent';
    $valid=trim((string)($input['validUntil']??''));if($valid!==''){$d=DateTimeImmutable::createFromFormat('Y-m-d',$valid);if(!$d||$d->format('Y-m-d')!==$valid)throw new InvalidArgumentException('Quote expiration date is invalid.');}
    $pricing=wholesale_commerce_resolve_lines($pdo,$org,(int)$account['id'],(array)($input['items']??[]));$totals=wholesale_commerce_totals($pricing['subtotal'],$input['deliveryFee']??0,$input['taxRatePercent']??0);
    $public=wholesale_commerce_public_id('wquote');$number='Q-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO wholesale_quotes (organization_id,wholesale_account_id,price_list_id,public_id,quote_number,status,items_json,subtotal,delivery_fee,tax_total,tax_rate_percent,total,valid_until,customer_message,terms_text,sent_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?='sent',NOW(6),NULL),?,?)");
        $q->execute([$org,(int)$account['id'],$pricing['priceList']['id']??null,$public,$number,$status,json_encode($pricing['snapshot'],JSON_THROW_ON_ERROR),$totals['subtotal'],$totals['deliveryFee'],$totals['taxTotal'],$totals['taxRatePercent'],$totals['total'],$valid?:null,mb_substr(trim((string)($input['customerMessage']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['terms']??'')),0,10000,'UTF-8')?:null,$status,$userId,$userId]);
        $id=(int)$pdo->lastInsertId();wholesale_commerce_insert_quote_lines($pdo,$org,$id,$pricing['lines']);wholesale_commerce_quote_event($pdo,$org,$id,'created','Wholesale quote created.',$userId,['quoteNumber'=>$number,'status'=>$status,'total'=>$totals['total'],'pricingMode'=>$pricing['priceList']?'canonical_or_mixed':'legacy']);
        $pdo->commit();return ['publicId'=>$public,'quoteNumber'=>$number,'id'=>$id,'totals'=>$totals,'lines'=>$pricing['lines']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function wholesale_commerce_create_order(PDO $pdo,int $org,array $account,array $input,int $userId): array
{
    $status=(string)($input['status']??'confirmed');if(!in_array($status,['requested','confirmed','in_production','ready','out_for_delivery','delivered','cancelled'],true))$status='confirmed';
    $pricing=wholesale_commerce_resolve_lines($pdo,$org,(int)$account['id'],(array)($input['items']??[]));$totals=wholesale_commerce_totals($pricing['subtotal'],$input['deliveryFee']??0,$input['taxRatePercent']??0);
    $public=wholesale_commerce_public_id('worder');$number='W-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
    $requested=trim((string)($input['requestedFor']??''));if($requested!==''){$d=DateTimeImmutable::createFromFormat('Y-m-d',$requested);if(!$d||$d->format('Y-m-d')!==$requested)throw new InvalidArgumentException('Requested fulfillment date is invalid.');}
    $promised=trim((string)($input['promisedFor']??''));
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO wholesale_orders (organization_id,wholesale_account_id,price_list_id,public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,tax_rate_percent,total,fulfillment_type,requested_for,promised_for,customer_notes,internal_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $q->execute([$org,(int)$account['id'],$pricing['priceList']['id']??null,$public,$number,$status,json_encode($pricing['snapshot'],JSON_THROW_ON_ERROR),$totals['subtotal'],$totals['deliveryFee'],$totals['taxTotal'],$totals['taxRatePercent'],$totals['total'],mb_substr(trim((string)($input['fulfillmentType']??'')),0,40,'UTF-8')?:null,$requested?:null,$promised?:null,mb_substr(trim((string)($input['customerNotes']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['internalNotes']??'')),0,10000,'UTF-8')?:null,$userId,$userId]);
        $id=(int)$pdo->lastInsertId();wholesale_commerce_insert_order_lines($pdo,$org,$id,$pricing['lines']);wholesale_commerce_order_event($pdo,$org,$id,'created','Wholesale order created.',$userId,['orderNumber'=>$number,'status'=>$status,'total'=>$totals['total'],'pricingMode'=>$pricing['priceList']?'canonical_or_mixed':'legacy']);
        $pdo->commit();return ['publicId'=>$public,'orderNumber'=>$number,'id'=>$id,'totals'=>$totals,'lines'=>$pricing['lines']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function wholesale_commerce_save_product(PDO $pdo,int $org,array $input,int $userId): array
{
    $public=trim((string)($input['id']??''));$name=mb_substr(trim((string)($input['name']??'')),0,220,'UTF-8');if($name==='')throw new InvalidArgumentException('Product name is required.');
    $category=mb_substr(trim((string)($input['category']??'')),0,120,'UTF-8')?:null;$description=mb_substr(trim((string)($input['description']??'')),0,10000,'UTF-8')?:null;$recipeId=null;$recipePublic=trim((string)($input['recipeId']??''));
    if($recipePublic!==''){$q=$pdo->prepare("SELECT id FROM recipes WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$recipePublic]);$recipeId=(int)($q->fetchColumn()?:0);if(!$recipeId)throw new InvalidArgumentException('Recipe not found.');}
    if($public===''){$public=wholesale_commerce_public_id('wprod');$pdo->prepare('INSERT INTO wholesale_products (organization_id,public_id,name,category,description,recipe_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$org,$public,$name,$category,$description,$recipeId,$userId,$userId]);}
    else{$q=$pdo->prepare('UPDATE wholesale_products SET name=?,category=?,description=?,recipe_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$name,$category,$description,$recipeId,$userId,$org,$public]);if(!$q->rowCount())throw new InvalidArgumentException('Wholesale product not found.');}
    $q=$pdo->prepare('SELECT * FROM wholesale_products WHERE organization_id=? AND public_id=?');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function wholesale_commerce_save_sku(PDO $pdo,int $org,array $input,int $userId): array
{
    $productPublic=trim((string)($input['productId']??''));$q=$pdo->prepare("SELECT id FROM wholesale_products WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$productPublic]);$productId=(int)($q->fetchColumn()?:0);if(!$productId)throw new InvalidArgumentException('Wholesale product not found.');
    $public=trim((string)($input['id']??''));$sku=mb_substr(strtoupper(trim((string)($input['sku']??''))),0,100,'UTF-8');$name=mb_substr(trim((string)($input['name']??'')),0,220,'UTF-8');if($sku===''||$name==='')throw new InvalidArgumentException('SKU code and name are required.');
    $uom=mb_substr(trim((string)($input['sellUom']??'case')),0,80,'UTF-8')?:'case';$units=max(.0001,(float)($input['unitsPerSellUom']??1));$minimum=max(.0001,(float)($input['minimumQuantity']??1));$increment=max(.0001,(float)($input['quantityIncrement']??1));$yield=isset($input['recipeYieldPerBatch'])&&$input['recipeYieldPerBatch']!==''?max(.0001,(float)$input['recipeYieldPerBatch']):null;$yieldUnit=mb_substr(trim((string)($input['recipeYieldUnit']??'')),0,80,'UTF-8')?:null;
    if($public===''){$public=wholesale_commerce_public_id('wsku');$pdo->prepare('INSERT INTO wholesale_skus (organization_id,wholesale_product_id,public_id,sku,name,sell_uom,units_per_sell_uom,minimum_quantity,quantity_increment,recipe_yield_per_batch,recipe_yield_unit,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$org,$productId,$public,$sku,$name,$uom,$units,$minimum,$increment,$yield,$yieldUnit,$userId,$userId]);}
    else{$q=$pdo->prepare('UPDATE wholesale_skus SET wholesale_product_id=?,sku=?,name=?,sell_uom=?,units_per_sell_uom=?,minimum_quantity=?,quantity_increment=?,recipe_yield_per_batch=?,recipe_yield_unit=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$productId,$sku,$name,$uom,$units,$minimum,$increment,$yield,$yieldUnit,$userId,$org,$public]);if(!$q->rowCount())throw new InvalidArgumentException('Wholesale SKU not found.');}
    $q=$pdo->prepare('SELECT * FROM wholesale_skus WHERE organization_id=? AND public_id=?');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function wholesale_commerce_save_price_list(PDO $pdo,int $org,array $input,int $userId): array
{
    $public=trim((string)($input['id']??''));$name=mb_substr(trim((string)($input['name']??'')),0,180,'UTF-8');if($name==='')throw new InvalidArgumentException('Price list name is required.');$minimum=max(0,(float)($input['minimumOrderAmount']??0));$default=!empty($input['isDefault'])?1:0;
    if($default)$pdo->prepare('UPDATE wholesale_price_lists SET is_default=0 WHERE organization_id=? AND archived_at IS NULL')->execute([$org]);
    if($public===''){$public=wholesale_commerce_public_id('wpl');$pdo->prepare("INSERT INTO wholesale_price_lists (organization_id,public_id,name,currency,minimum_order_amount,is_default,created_by,updated_by) VALUES (?,?,?,'USD',?,?,?,?)")->execute([$org,$public,$name,$minimum,$default,$userId,$userId]);}
    else{$q=$pdo->prepare('UPDATE wholesale_price_lists SET name=?,minimum_order_amount=?,is_default=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$name,$minimum,$default,$userId,$org,$public]);if(!$q->rowCount())throw new InvalidArgumentException('Price list not found.');}
    $q=$pdo->prepare('SELECT * FROM wholesale_price_lists WHERE organization_id=? AND public_id=?');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function wholesale_commerce_set_price(PDO $pdo,int $org,array $input,int $userId): array
{
    $listPublic=trim((string)($input['priceListId']??''));$skuPublic=trim((string)($input['skuId']??''));
    $q=$pdo->prepare("SELECT id FROM wholesale_price_lists WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$listPublic]);$listId=(int)($q->fetchColumn()?:0);if(!$listId)throw new InvalidArgumentException('Price list not found.');
    $q=$pdo->prepare("SELECT id FROM wholesale_skus WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$skuPublic]);$skuId=(int)($q->fetchColumn()?:0);if(!$skuId)throw new InvalidArgumentException('Wholesale SKU not found.');
    $price=(float)($input['unitPrice']??-1);if($price<0)throw new InvalidArgumentException('Unit price must be zero or greater.');$minimum=isset($input['minimumQuantity'])&&$input['minimumQuantity']!==''?max(.0001,(float)$input['minimumQuantity']):null;$from=trim((string)($input['effectiveFrom']??''))?:date('Y-m-d');$until=trim((string)($input['effectiveUntil']??''))?:null;
    $public=wholesale_commerce_public_id('wprice');$pdo->prepare('INSERT INTO wholesale_price_list_items (organization_id,price_list_id,wholesale_sku_id,public_id,unit_price,minimum_quantity,effective_from,effective_until,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$listId,$skuId,$public,$price,$minimum,$from,$until,$userId]);
    return ['id'=>$public,'unitPrice'=>$price,'minimumQuantity'=>$minimum,'effectiveFrom'=>$from,'effectiveUntil'=>$until];
}

function wholesale_commerce_assign_price_list(PDO $pdo,int $org,int $accountId,string $priceListPublic,int $userId): void
{
    wholesale_commerce_account($pdo,$org,$accountId);$q=$pdo->prepare("SELECT id FROM wholesale_price_lists WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL");$q->execute([$org,$priceListPublic]);$listId=(int)($q->fetchColumn()?:0);if(!$listId)throw new InvalidArgumentException('Price list not found.');
    $pdo->prepare('UPDATE wholesale_account_price_lists SET effective_until=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE organization_id=? AND wholesale_account_id=? AND effective_until IS NULL')->execute([$org,$accountId]);
    $pdo->prepare('INSERT INTO wholesale_account_price_lists (organization_id,wholesale_account_id,price_list_id,effective_from,created_by) VALUES (?,?,?,CURDATE(),?)')->execute([$org,$accountId,$listId,$userId]);
}
