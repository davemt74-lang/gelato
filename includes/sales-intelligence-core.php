<?php
declare(strict_types=1);

require_once __DIR__.'/prep-intelligence-core.php';
require_once __DIR__.'/scheduling-core.php';

function sales_intelligence_ready(PDO $pdo): bool
{
    foreach(['sales_integrations','sales_import_batches','sales_import_rows','sales_periods','sales_item_periods','sales_hourly','sales_forecasts','sales_forecast_items'] as $table){
        if(!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}

function sales_public_id(string $prefix): string { return $prefix.'-'.bin2hex(random_bytes(10)); }

function sales_key(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/[^\pL\pN]+/u','-',$value)??$value;
    return trim(mb_substr($value,0,180,'UTF-8'),'-')?:'all';
}

function sales_provider_catalog(): array
{
    return [
        ['provider'=>'csv','name'=>'CSV Upload','status'=>'ready','description'=>'Manual daily, weekly or monthly sales files.'],
        ['provider'=>'toast','name'=>'Toast','status'=>'connector_ready','description'=>'OAuth client-credentials + restaurant GUID adapter; credentials are configured outside uploaded sales files.'],
        ['provider'=>'square','name'=>'Square','status'=>'planned','description'=>'Provider adapter can be added without changing the canonical sales ledger.'],
        ['provider'=>'clover','name'=>'Clover','status'=>'planned','description'=>'Provider adapter can be added without changing the canonical sales ledger.'],
    ];
}

function sales_ensure_csv_integration(PDO $pdo,int $org,int $userId): void
{
    $q=$pdo->prepare("SELECT id FROM sales_integrations WHERE organization_id=? AND provider='csv' AND location_id IS NULL ORDER BY id LIMIT 1");$q->execute([$org]);
    if($q->fetchColumn()) return;
    $q=$pdo->prepare('SELECT COUNT(*) FROM sales_integrations WHERE organization_id=? AND is_primary=1');$q->execute([$org]);$primary=(int)$q->fetchColumn()===0?1:0;
    $pdo->prepare("INSERT INTO sales_integrations (organization_id,provider,display_name,status,is_primary,sync_enabled,created_by,updated_by) VALUES (?,'csv','CSV Upload','active',?,0,?,?)")->execute([$org,$primary,$userId,$userId]);
}

function sales_primary_provider(PDO $pdo,int $org): string
{
    $q=$pdo->prepare("SELECT provider FROM sales_integrations WHERE organization_id=? AND is_primary=1 AND status IN ('active','available','connected') ORDER BY updated_at DESC,id DESC LIMIT 1");$q->execute([$org]);
    return (string)($q->fetchColumn()?:'csv');
}

function sales_integrations(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT si.*,l.name location_name FROM sales_integrations si LEFT JOIN locations l ON l.id=si.location_id AND l.organization_id=si.organization_id WHERE si.organization_id=? ORDER BY si.is_primary DESC,si.provider,si.location_id");$q->execute([$org]);
    return $q->fetchAll();
}

function sales_integration_save(PDO $pdo,int $org,array $input,int $userId): array
{
    $provider=sales_key((string)($input['provider']??''));if(!in_array($provider,['csv','toast','square','clover'],true))throw new InvalidArgumentException('Unsupported sales provider.');
    $locationId=isset($input['locationId'])&&$input['locationId']!==''?(int)$input['locationId']:null;
    if($locationId){$q=$pdo->prepare("SELECT id FROM locations WHERE id=? AND organization_id=? AND status='active'");$q->execute([$locationId,$org]);if(!$q->fetchColumn())throw new InvalidArgumentException('Sales integration location was not found.');}
    $external=mb_substr(trim((string)($input['restaurantExternalId']??'')),0,180,'UTF-8')?:null;
    $sync=!empty($input['syncEnabled'])?1:0;$primary=!empty($input['isPrimary'])?1:0;$display=mb_substr(trim((string)($input['displayName']??ucfirst($provider))),0,160,'UTF-8')?:ucfirst($provider);
    $q=$pdo->prepare('SELECT id,last_synced_at FROM sales_integrations WHERE organization_id=? AND provider=? AND '.($locationId?'location_id=?':'location_id IS NULL').' ORDER BY id LIMIT 1');$args=[$org,$provider];if($locationId)$args[]=$locationId;$q->execute($args);$existing=$q->fetch()?:null;$id=(int)($existing['id']??0);
    if($primary&&$provider!=='csv'&&empty($existing['last_synced_at']))throw new InvalidArgumentException('A POS provider cannot become the primary sales source until a successful sync has populated the ledger.');
    if($primary)$pdo->prepare('UPDATE sales_integrations SET is_primary=0,updated_by=?,updated_at=NOW(6) WHERE organization_id=?')->execute([$userId,$org]);
    if($id){$pdo->prepare('UPDATE sales_integrations SET display_name=?,status=?,is_primary=?,restaurant_external_id=?,sync_enabled=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$display,$provider==='csv'?'active':(string)($input['status']??'available'),$primary,$external,$sync,$userId,$id,$org]);}
    else{$pdo->prepare('INSERT INTO sales_integrations (organization_id,location_id,provider,display_name,status,is_primary,restaurant_external_id,sync_enabled,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,$locationId,$provider,$display,$provider==='csv'?'active':'available',$primary,$external,$sync,$userId,$userId]);$id=(int)$pdo->lastInsertId();}
    $q=$pdo->prepare("SELECT si.*,l.name location_name FROM sales_integrations si LEFT JOIN locations l ON l.id=si.location_id WHERE si.organization_id=? AND si.id=?");$q->execute([$org,$id]);return $q->fetch()?:[];
}

function sales_csv_delimiter(string $path): string
{
    $fh=fopen($path,'rb');if(!$fh)return ',';$line=(string)fgets($fh);fclose($fh);$scores=[];foreach([',',"\t",';','|'] as $delimiter)$scores[$delimiter]=substr_count($line,$delimiter);arsort($scores);$best=(string)array_key_first($scores);return ($scores[$best]??0)>0?$best:',';
}

function sales_header_key(string $value): string
{
    $value=preg_replace('/^\xEF\xBB\xBF/','',$value)??$value;$value=mb_strtolower(trim($value),'UTF-8');$value=preg_replace('/[^a-z0-9]+/u','_',$value)??$value;return trim($value,'_');
}

function sales_alias_map(): array
{
    return [
        'date'=>['date','business_date','sale_date','sales_date','period_start','start_date'],
        'period_end'=>['period_end','end_date','through_date'],
        'granularity'=>['granularity','period_type','frequency'],
        'service_period'=>['service_period','daypart','meal_period'],
        'location'=>['location','location_name','store','store_name','restaurant','restaurant_name'],
        'row_type'=>['row_type','record_type','type'],
        'hour'=>['hour','hour_start','time','time_start'],
        'tickets'=>['tickets','checks','orders','transactions','check_count','order_count'],
        'covers'=>['covers','guests','guest_count','guest_counts'],
        'gross_sales'=>['gross_sales','gross','gross_revenue'],
        'net_sales'=>['net_sales','net','revenue','sales','total_sales'],
        'tax'=>['tax','taxes','tax_amount'],
        'tips'=>['tips','tip','tip_amount'],
        'discounts'=>['discounts','discount','discount_amount'],
        'comps'=>['comps','comp','comp_amount'],
        'voids'=>['voids','void','void_amount'],
        'refunds'=>['refunds','refund','refund_amount'],
        'service_charges'=>['service_charges','service_charge','service_charge_amount','fees'],
        'item_name'=>['item_name','menu_item','product','item','product_name'],
        'item_id'=>['item_id','external_item_id','sku','plu','product_id'],
        'category'=>['category','menu_group','section','sales_category'],
        'quantity'=>['quantity','qty','item_quantity','units','units_sold'],
        'item_sales'=>['item_sales','item_net_sales','item_revenue','product_sales'],
        'item_gross_sales'=>['item_gross_sales','item_gross','product_gross_sales'],
    ];
}

function sales_column_mapping(array $headers): array
{
    $normalized=[];foreach($headers as $i=>$h)$normalized[sales_header_key((string)$h)]=$i;$map=[];
    foreach(sales_alias_map() as $field=>$aliases){foreach($aliases as $alias){if(array_key_exists($alias,$normalized)){$map[$field]=$normalized[$alias];break;}}}
    return $map;
}

function sales_recognized_row(array $row,array $map): array
{
    $out=[];foreach($map as $field=>$index)$out[$field]=trim((string)($row[$index]??''));return $out;
}

function sales_cell(array $row,array $map,string $field): string { $i=$map[$field]??null;return $i===null?'':trim((string)($row[$i]??'')); }

function sales_number(string $value): ?float
{
    $value=trim($value);if($value==='')return null;$negative=str_starts_with($value,'(')&&str_ends_with($value,')');$value=str_replace(['$',',','%','(',')',' '],'',$value);if(!is_numeric($value))return null;$n=(float)$value;return $negative?-$n:$n;
}

function sales_int_value(string $value): int { $n=sales_number($value);return $n===null?0:max(0,(int)round($n)); }

function sales_date_value(string $value): ?DateTimeImmutable
{
    $value=trim($value);if($value==='')return null;
    if(preg_match('/^\d{4}-\d{2}$/',$value))return new DateTimeImmutable($value.'-01');
    foreach(['Y-m-d','m/d/Y','n/j/Y','Y/m/d','m-d-Y','n-j-Y'] as $format){$d=DateTimeImmutable::createFromFormat('!'.$format,$value);if($d&&$d->format($format)===$value)return $d;}
    try{return new DateTimeImmutable($value);}catch(Throwable){return null;}
}

function sales_period_values(string $dateValue,string $endValue,string $granularityValue): ?array
{
    $start=sales_date_value($dateValue);if(!$start)return null;$gran=mb_strtolower(trim($granularityValue),'UTF-8');if(!in_array($gran,['daily','weekly','monthly'],true))$gran='';$end=sales_date_value($endValue);
    if(!$end&&preg_match('/^\d{4}-\d{2}$/',trim($dateValue)))$end=$start->modify('last day of this month');
    if(!$end){if($gran==='weekly')$end=$start->modify('+6 days');elseif($gran==='monthly')$end=$start->modify('last day of this month');else $end=$start;}
    if($end<$start)[$start,$end]=[$end,$start];$days=(int)$start->diff($end)->days+1;
    if($gran==='')$gran=$days>=27?'monthly':($days>=6?'weekly':'daily');
    return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'granularity'=>$gran];
}

function sales_service_period(string $value): string
{
    $v=mb_strtolower(trim($value),'UTF-8');$aliases=['breakfast'=>'morning','brunch'=>'morning','midday'=>'lunch','afternoon'=>'lunch','evening'=>'dinner','night'=>'closing','late night'=>'closing'];$v=$aliases[$v]??$v;return in_array($v,['all','morning','lunch','dinner','closing'],true)?$v:'all';
}

function sales_hour_value(string $value): ?int
{
    $value=trim($value);if($value==='')return null;if(ctype_digit($value))return max(0,min(23,(int)$value));
    foreach(['H:i','G:i','g:i A','g A','g:iA','ga'] as $f){$d=DateTimeImmutable::createFromFormat('!'.$f,strtoupper($value));if($d)return (int)$d->format('G');}return null;
}

function sales_location(PDO $pdo,int $org,string $name,?int $fallbackId=null): array
{
    if($fallbackId){$q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$fallbackId]);if($r=$q->fetch())return ['id'=>(int)$r['id'],'key'=>'id:'.(int)$r['id'],'name'=>$r['name']];}
    $name=trim($name);if($name!==''){$q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1");$q->execute([$org,$name]);if($r=$q->fetch())return ['id'=>(int)$r['id'],'key'=>'id:'.(int)$r['id'],'name'=>$r['name']];return ['id'=>null,'key'=>'name:'.sales_key($name),'name'=>$name];}
    return ['id'=>null,'key'=>'all','name'=>'All locations'];
}

function sales_menu_match_map(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT id,name FROM menu_items WHERE organization_id=? AND is_active=1");$q->execute([$org]);$map=[];foreach($q->fetchAll() as $r)$map[sales_key((string)$r['name'])]=(int)$r['id'];return $map;
}

function sales_zero_metrics(): array
{
    return ['tickets'=>0,'covers'=>0,'gross'=>0.0,'net'=>0.0,'tax'=>0.0,'tips'=>0.0,'discounts'=>0.0,'comps'=>0.0,'voids'=>0.0,'refunds'=>0.0,'serviceCharges'=>0.0];
}

function sales_metrics_add(array $a,array $b): array
{
    foreach(array_keys(sales_zero_metrics()) as $key)$a[$key]=($a[$key]??0)+($b[$key]??0);return $a;
}

function sales_period_upsert(PDO $pdo,int $org,array $location,string $provider,int $batchId,array $period,string $service,array $metrics): int
{
    $sql="INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,import_batch_id,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,comps_amount,voids_amount,refunds_amount,service_charges_amount,source_metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),import_batch_id=VALUES(import_batch_id),tickets=VALUES(tickets),covers=VALUES(covers),gross_sales=VALUES(gross_sales),net_sales=VALUES(net_sales),tax_amount=VALUES(tax_amount),tips_amount=VALUES(tips_amount),discounts_amount=VALUES(discounts_amount),comps_amount=VALUES(comps_amount),voids_amount=VALUES(voids_amount),refunds_amount=VALUES(refunds_amount),service_charges_amount=VALUES(service_charges_amount),source_metadata_json=VALUES(source_metadata_json),updated_at=NOW(6)";
    $pdo->prepare($sql)->execute([$org,$location['id'],$location['key'],$provider,$batchId,$period['granularity'],$service,$period['start'],$period['end'],$metrics['tickets'],$metrics['covers'],$metrics['gross'],$metrics['net'],$metrics['tax'],$metrics['tips'],$metrics['discounts'],$metrics['comps'],$metrics['voids'],$metrics['refunds'],$metrics['serviceCharges'],json_encode(['locationName'=>$location['name']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $q=$pdo->prepare('SELECT id FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider=? AND granularity=? AND service_period=? AND period_start=? AND period_end=?');$q->execute([$org,$location['key'],$provider,$period['granularity'],$service,$period['start'],$period['end']]);return (int)$q->fetchColumn();
}

function sales_import_csv(PDO $pdo,int $org,int $userId,string $path,string $originalName,?int $locationId=null,bool $allowDuplicate=false): array
{
    if(!sales_intelligence_ready($pdo))throw new RuntimeException('Sales Intelligence migration is not installed.');if(!is_file($path)||filesize($path)<1)throw new InvalidArgumentException('The CSV file is empty.');if(filesize($path)>15*1024*1024)throw new InvalidArgumentException('Sales CSV files must be 15 MB or smaller.');
    $checksum=hash_file('sha256',$path);if(!$checksum)throw new RuntimeException('Could not checksum the sales file.');if(!$allowDuplicate){$q=$pdo->prepare("SELECT public_id FROM sales_import_batches WHERE organization_id=? AND file_checksum=? AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute([$org,$checksum]);if($existing=$q->fetchColumn())throw new InvalidArgumentException('This exact sales file was already imported as '.$existing.'.');}
    sales_ensure_csv_integration($pdo,$org,$userId);$delimiter=sales_csv_delimiter($path);$fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Could not open the sales CSV.');$headers=fgetcsv($fh,0,$delimiter);if(!is_array($headers)||!$headers){fclose($fh);throw new InvalidArgumentException('The CSV needs a header row.');}$headers=array_map(fn($v)=>preg_replace('/^\xEF\xBB\xBF/','',(string)$v)??(string)$v,$headers);$map=sales_column_mapping($headers);if(!isset($map['date'])){fclose($fh);throw new InvalidArgumentException('The CSV needs a date/business_date/period_start column.');}
    $public=sales_public_id('sales-import');$pdo->prepare("INSERT INTO sales_import_batches (organization_id,location_id,public_id,provider,original_filename,file_checksum,detected_delimiter,detected_headers_json,mapping_json,status,imported_by) VALUES (?,?,?,'csv',?,?,?,?,?,'processing',?)")->execute([$org,$locationId,$public,mb_substr($originalName,0,255,'UTF-8'),$checksum,$delimiter,json_encode($headers,JSON_UNESCAPED_UNICODE),json_encode($map,JSON_UNESCAPED_UNICODE),$userId]);$batchId=(int)$pdo->lastInsertId();$menuMap=sales_menu_match_map($pdo,$org);$rowNo=1;$accepted=0;$rejected=0;$minDate=null;$maxDate=null;$touchedPeriods=[];$summarySeen=[];$hourlyPeriodMetrics=[];$errors=[];
    $insRow=$pdo->prepare('INSERT INTO sales_import_rows (organization_id,import_batch_id,row_number,row_status,rejection_reason,normalized_json,raw_json) VALUES (?,?,?,?,?,?,?)');
    try{$pdo->beginTransaction();while(($row=fgetcsv($fh,0,$delimiter))!==false){$rowNo++;if(count(array_filter($row,fn($v)=>trim((string)$v)!==''))===0)continue;$raw=sales_recognized_row($row,$map);$period=sales_period_values(sales_cell($row,$map,'date'),sales_cell($row,$map,'period_end'),sales_cell($row,$map,'granularity'));if(!$period){$rejected++;$reason='Invalid or missing period date.';$insRow->execute([$org,$batchId,$rowNo,'rejected',$reason,null,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);if(count($errors)<12)$errors[]='Row '.$rowNo.': '.$reason;continue;}
        $service=sales_service_period(sales_cell($row,$map,'service_period'));$location=sales_location($pdo,$org,sales_cell($row,$map,'location'),$locationId);$itemName=mb_substr(sales_cell($row,$map,'item_name'),0,240,'UTF-8');$rowType=mb_strtolower(sales_cell($row,$map,'row_type'),'UTF-8');$isItem=$itemName!==''||$rowType==='item';$hour=sales_hour_value(sales_cell($row,$map,'hour'));
        $metrics=['tickets'=>sales_int_value(sales_cell($row,$map,'tickets')),'covers'=>sales_int_value(sales_cell($row,$map,'covers')),'gross'=>sales_number(sales_cell($row,$map,'gross_sales'))??0.0,'net'=>sales_number(sales_cell($row,$map,'net_sales'))??0.0,'tax'=>sales_number(sales_cell($row,$map,'tax'))??0.0,'tips'=>sales_number(sales_cell($row,$map,'tips'))??0.0,'discounts'=>sales_number(sales_cell($row,$map,'discounts'))??0.0,'comps'=>sales_number(sales_cell($row,$map,'comps'))??0.0,'voids'=>sales_number(sales_cell($row,$map,'voids'))??0.0,'refunds'=>sales_number(sales_cell($row,$map,'refunds'))??0.0,'serviceCharges'=>sales_number(sales_cell($row,$map,'service_charges'))??0.0];
        $periodCacheKey=implode('|',[$location['key'],$period['granularity'],$service,$period['start'],$period['end']]);if(!isset($touchedPeriods[$periodCacheKey])){$periodId=sales_period_upsert($pdo,$org,$location,'csv',$batchId,$period,$service,sales_zero_metrics());$pdo->prepare('DELETE FROM sales_item_periods WHERE organization_id=? AND sales_period_id=?')->execute([$org,$periodId]);$touchedPeriods[$periodCacheKey]=$periodId;}else $periodId=$touchedPeriods[$periodCacheKey];
        if(!$isItem){if($hour!==null&&$period['granularity']==='daily'){$hourlyPeriodMetrics[$periodCacheKey]=sales_metrics_add($hourlyPeriodMetrics[$periodCacheKey]??sales_zero_metrics(),$metrics);}else{sales_period_upsert($pdo,$org,$location,'csv',$batchId,$period,$service,$metrics);$summarySeen[$periodCacheKey]=true;}}
        if($isItem){if($itemName===''){$rejected++;$reason='Item row is missing item_name.';$insRow->execute([$org,$batchId,$rowNo,'rejected',$reason,null,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);continue;}$sourceId=mb_substr(sales_cell($row,$map,'item_id'),0,180,'UTF-8');$sourceKey=$sourceId!==''?'id:'.sales_key($sourceId):'name:'.sales_key($itemName);$menuId=$menuMap[sales_key($itemName)]??null;$qty=sales_number(sales_cell($row,$map,'quantity'))??0.0;$itemNet=sales_number(sales_cell($row,$map,'item_sales'));if($itemNet===null)$itemNet=$metrics['net'];$itemGross=sales_number(sales_cell($row,$map,'item_gross_sales'));if($itemGross===null)$itemGross=$metrics['gross']?:$itemNet;$category=mb_substr(sales_cell($row,$map,'category'),0,180,'UTF-8')?:null;$pdo->prepare("INSERT INTO sales_item_periods (organization_id,sales_period_id,menu_item_id,source_item_key,source_item_id,item_name,category_name,quantity,gross_sales,net_sales,discounts_amount,refunds_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE menu_item_id=COALESCE(VALUES(menu_item_id),menu_item_id),item_name=VALUES(item_name),category_name=VALUES(category_name),quantity=quantity+VALUES(quantity),gross_sales=gross_sales+VALUES(gross_sales),net_sales=net_sales+VALUES(net_sales),discounts_amount=discounts_amount+VALUES(discounts_amount),refunds_amount=refunds_amount+VALUES(refunds_amount),updated_at=NOW(6)")->execute([$org,$periodId,$menuId,$sourceKey,$sourceId?:null,$itemName,$category,$qty,$itemGross,$itemNet,$metrics['discounts'],$metrics['refunds']]);}
        if($hour!==null&&$period['granularity']==='daily'&&!$isItem){$pdo->prepare("INSERT INTO sales_hourly (organization_id,location_id,location_key,source_provider,import_batch_id,business_date,hour_start,tickets,covers,gross_sales,net_sales) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),import_batch_id=VALUES(import_batch_id),tickets=VALUES(tickets),covers=VALUES(covers),gross_sales=VALUES(gross_sales),net_sales=VALUES(net_sales),updated_at=NOW(6)")->execute([$org,$location['id'],$location['key'],'csv',$batchId,$period['start'],$hour,$metrics['tickets'],$metrics['covers'],$metrics['gross'],$metrics['net']]);}
        $normalized=['period'=>$period,'servicePeriod'=>$service,'locationKey'=>$location['key'],'rowType'=>$isItem?'item':'summary','itemName'=>$itemName?:null,'hour'=>$hour];$insRow->execute([$org,$batchId,$rowNo,'accepted',null,json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$accepted++;$minDate=$minDate===null||$period['start']<$minDate?$period['start']:$minDate;$maxDate=$maxDate===null||$period['end']>$maxDate?$period['end']:$maxDate;
    }
    foreach($touchedPeriods as $key=>$periodId){if(isset($summarySeen[$key]))continue;if(isset($hourlyPeriodMetrics[$key])){$m=$hourlyPeriodMetrics[$key];$pdo->prepare('UPDATE sales_periods SET tickets=?,covers=?,gross_sales=?,net_sales=?,tax_amount=?,tips_amount=?,discounts_amount=?,comps_amount=?,voids_amount=?,refunds_amount=?,service_charges_amount=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$m['tickets'],$m['covers'],$m['gross'],$m['net'],$m['tax'],$m['tips'],$m['discounts'],$m['comps'],$m['voids'],$m['refunds'],$m['serviceCharges'],$org,$periodId]);continue;}$q=$pdo->prepare('SELECT COALESCE(SUM(gross_sales),0),COALESCE(SUM(net_sales),0) FROM sales_item_periods WHERE organization_id=? AND sales_period_id=?');$q->execute([$org,$periodId]);[$gross,$net]=$q->fetch(PDO::FETCH_NUM)?:[0,0];$pdo->prepare('UPDATE sales_periods SET gross_sales=?,net_sales=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([(float)$gross,(float)$net,$org,$periodId]);}
    $count=max(0,$rowNo-1);$status=$accepted>0?'completed':'failed';$pdo->prepare('UPDATE sales_import_batches SET period_start=?,period_end=?,row_count=?,accepted_count=?,rejected_count=?,status=?,error_summary=?,imported_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$minDate,$maxDate,$count,$accepted,$rejected,$status,$errors?implode("\n",$errors):null,$org,$batchId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare("UPDATE sales_import_batches SET status='failed',error_summary=? WHERE organization_id=? AND id=?")->execute([mb_substr($e->getMessage(),0,5000,'UTF-8'),$org,$batchId]);fclose($fh);throw $e;}fclose($fh);
    return ['publicId'=>$public,'rowCount'=>max(0,$rowNo-1),'accepted'=>$accepted,'rejected'=>$rejected,'periodStart'=>$minDate,'periodEnd'=>$maxDate,'headers'=>$headers,'mapping'=>$map,'errors'=>$errors];
}

function sales_mean_median(array $values): ?float
{
    $values=array_values(array_filter(array_map('floatval',$values),fn($v)=>is_finite($v)));if(!$values)return null;sort($values);$n=count($values);$mean=array_sum($values)/$n;$median=$n%2?$values[(int)floor($n/2)]:($values[$n/2-1]+$values[$n/2])/2;return ($mean+$median)/2;
}

function sales_confidence(array $values): float
{
    $values=array_values(array_map('floatval',$values));$n=count($values);if(!$n)return 0.0;$mean=array_sum($values)/$n;if($n===1)return .28;$variance=0.0;foreach($values as $v)$variance+=($v-$mean)**2;$sd=sqrt($variance/max(1,$n-1));$cv=abs($mean)>0.01?$sd/abs($mean):1.0;$sample=min(1,$n/8);return round(max(.2,min(.95,(.35+.6*$sample)/(1+min(2,$cv)*.35))),4);
}

function sales_service_window(string $date,string $service): array
{
    $service=sales_service_period($service);$starts=['morning'=>'05:00:00','lunch'=>'11:00:00','dinner'=>'16:00:00','closing'=>'22:00:00','all'=>'00:00:00'];$ends=['morning'=>'11:00:00','lunch'=>'16:00:00','dinner'=>'22:00:00','closing'=>'05:00:00','all'=>'00:00:00'];$start=new DateTimeImmutable($date.' '.$starts[$service]);$end=$service==='all'?$start->modify('+1 day'):new DateTimeImmutable($date.' '.$ends[$service]);if($service==='closing')$end=$end->modify('+1 day');return [$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')];
}

function sales_scheduled_labor(PDO $pdo,int $org,string $date,string $service='all',?int $locationId=null): array
{
    [$start,$end]=sales_service_window($date,$service);$where="s.organization_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.starts_at<? AND s.ends_at>?";$args=[$org,$end,$start];if($locationId){$where.=' AND s.location_id=?';$args[]=$locationId;}
    $sql="SELECT COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,GREATEST(s.starts_at,?),LEAST(s.ends_at,?)))/60),0) hours,COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,GREATEST(s.starts_at,?),LEAST(s.ends_at,?)))/60*COALESCE(sp.hourly_labor_cost,0)),0) cost FROM schedule_shifts s LEFT JOIN staff_profiles sp ON sp.organization_id=s.organization_id AND sp.user_id=s.user_id WHERE {$where}";$stmt=$pdo->prepare($sql);$stmt->execute([$start,$end,$start,$end,...$args]);$r=$stmt->fetch()?:[];return ['hours'=>round((float)($r['hours']??0),2),'cost'=>round((float)($r['cost']??0),2)];
}

function sales_actual_labor_range(PDO $pdo,int $org,string $from,string $to,?int $locationId=null): array
{
    $end=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');$start=$from.' 00:00:00';$where="t.organization_id=? AND t.clocked_in_at>=? AND t.clocked_in_at<? AND t.clocked_out_at IS NOT NULL";$args=[$org,$start,$end];if($locationId){$where.=' AND COALESCE(s.location_id,om.primary_location_id)=?';$args[]=$locationId;}
    $sql="SELECT COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,t.clocked_in_at,t.clocked_out_at)-COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE,b.started_at,COALESCE(b.ended_at,t.clocked_out_at))) FROM time_clock_breaks b WHERE b.time_clock_entry_id=t.id),0))/60),0) hours,COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,t.clocked_in_at,t.clocked_out_at)-COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE,b.started_at,COALESCE(b.ended_at,t.clocked_out_at))) FROM time_clock_breaks b WHERE b.time_clock_entry_id=t.id),0))/60*COALESCE(sp.hourly_labor_cost,0)),0) cost FROM time_clock_entries t LEFT JOIN schedule_shifts s ON s.id=t.schedule_shift_id AND s.organization_id=t.organization_id LEFT JOIN organization_memberships om ON om.organization_id=t.organization_id AND om.user_id=t.user_id LEFT JOIN staff_profiles sp ON sp.organization_id=t.organization_id AND sp.user_id=t.user_id WHERE {$where}";$stmt=$pdo->prepare($sql);$stmt->execute($args);$r=$stmt->fetch()?:[];return ['hours'=>round((float)($r['hours']??0),2),'cost'=>round((float)($r['cost']??0),2)];
}

function sales_position_capacity(PDO $pdo,int $org,string $targetDate,string $service,float $requiredHours,?int $locationId=null): array
{
    [$start,$end]=sales_service_window($targetDate,$service);$q=$pdo->prepare("SELECT p.name,COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,GREATEST(s.starts_at,?),LEAST(s.ends_at,?)))/60),0) scheduled_hours FROM positions p LEFT JOIN schedule_shifts s ON s.position_id=p.id AND s.organization_id=p.organization_id AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.starts_at<? AND s.ends_at>? ".($locationId?'AND s.location_id=? ':'')."WHERE p.organization_id=? AND p.status='active' GROUP BY p.id,p.name HAVING scheduled_hours>0 ORDER BY scheduled_hours DESC");$args=[$start,$end,$end,$start];if($locationId)$args[]=$locationId;$args[]=$org;$q->execute($args);$target=$q->fetchAll();
    $histStart=(new DateTimeImmutable($targetDate))->modify('-8 weeks')->format('Y-m-d 00:00:00');$histEnd=$targetDate.' 00:00:00';$q=$pdo->prepare("SELECT p.name,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,s.starts_at,s.ends_at)/60),0) hours FROM schedule_shifts s JOIN positions p ON p.id=s.position_id AND p.organization_id=s.organization_id WHERE s.organization_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.starts_at>=? AND s.starts_at<? AND DAYOFWEEK(s.starts_at)=DAYOFWEEK(?) ".($locationId?'AND s.location_id=? ':'')."GROUP BY p.id,p.name");$args=[$org,$histStart,$histEnd,$targetDate];if($locationId)$args[]=$locationId;$q->execute($args);$hist=$q->fetchAll();$total=array_sum(array_map(fn($r)=>(float)$r['hours'],$hist));$targetMap=[];foreach($target as $r)$targetMap[(string)$r['name']]=(float)$r['scheduled_hours'];$out=[];foreach($hist as $r){$share=$total>0?(float)$r['hours']/$total:0;$needed=$requiredHours>0?$requiredHours*$share:0;$scheduled=$targetMap[(string)$r['name']]??0;$out[]=['position'=>$r['name'],'historicalShare'=>round($share,4),'requiredHours'=>round($needed,2),'scheduledHours'=>round($scheduled,2),'gapHours'=>round($scheduled-$needed,2)];}return $out;
}

function sales_forecast(PDO $pdo,int $org,string $targetDate,string $service='all',?int $locationId=null,?int $userId=null,int $weeks=8): array
{
    if(!sales_intelligence_ready($pdo))throw new RuntimeException('Sales Intelligence migration is not installed.');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$targetDate);if(!$d||$d->format('Y-m-d')!==$targetDate)throw new InvalidArgumentException('Forecast date is invalid.');$service=sales_service_period($service);$provider=sales_primary_provider($pdo,$org);$location=sales_location($pdo,$org,'',$locationId);$from=$d->modify('-'.max(4,min(16,$weeks)).' weeks')->format('Y-m-d');
    $where="organization_id=? AND source_provider=? AND location_key=? AND granularity='daily' AND service_period=? AND period_start>=? AND period_start<? AND DAYOFWEEK(period_start)=DAYOFWEEK(?)";$q=$pdo->prepare("SELECT period_start,tickets,covers,net_sales,gross_sales FROM sales_periods WHERE {$where} ORDER BY period_start DESC LIMIT 16");$q->execute([$org,$provider,$location['key'],$service,$from,$targetDate,$targetDate]);$samples=$q->fetchAll();
    if(count($samples)<2&&$service!=='all'){$hours=['morning'=>[5,11],'lunch'=>[11,16],'dinner'=>[16,22],'closing'=>[22,24]][$service]??null;if($hours){$q=$pdo->prepare("SELECT business_date period_start,SUM(tickets) tickets,SUM(covers) covers,SUM(net_sales) net_sales,SUM(gross_sales) gross_sales FROM sales_hourly WHERE organization_id=? AND source_provider=? AND location_key=? AND business_date>=? AND business_date<? AND DAYOFWEEK(business_date)=DAYOFWEEK(?) AND hour_start>=? AND hour_start<? GROUP BY business_date ORDER BY business_date DESC LIMIT 16");$q->execute([$org,$provider,$location['key'],$from,$targetDate,$targetDate,$hours[0],$hours[1]]);$samples=$q->fetchAll();}}
    if(!$samples)throw new RuntimeException('Not enough daily/hourly sales history exists for this forecast yet. Import daily sales data first.');$net=array_map(fn($r)=>(float)$r['net_sales'],$samples);$tickets=array_map(fn($r)=>(float)$r['tickets'],$samples);$covers=array_map(fn($r)=>(float)$r['covers'],$samples);$forecastNet=sales_mean_median($net)??0;$forecastTickets=sales_mean_median($tickets);$forecastCovers=sales_mean_median($covers);$confidence=sales_confidence($net);
    $historyEnd=$d->modify('-1 day')->format('Y-m-d');$actual=sales_actual_labor_range($pdo,$org,$from,$historyEnd,$locationId);$q=$pdo->prepare("SELECT COALESCE(SUM(net_sales),0) FROM sales_periods WHERE organization_id=? AND source_provider=? AND location_key=? AND granularity='daily' AND service_period='all' AND period_start BETWEEN ? AND ?");$q->execute([$org,$provider,$location['key'],$from,$historyEnd]);$histSales=(float)$q->fetchColumn();$salesPerLaborHour=$actual['hours']>0?$histSales/$actual['hours']:null;$requiredHours=$salesPerLaborHour&&$salesPerLaborHour>0?$forecastNet/$salesPerLaborHour:null;$scheduled=sales_scheduled_labor($pdo,$org,$targetDate,$service,$locationId);$projectedCost=$requiredHours!==null&&$actual['hours']>0?$requiredHours*($actual['cost']/$actual['hours']):null;
    $existing=$pdo->prepare('SELECT id,public_id FROM sales_forecasts WHERE organization_id=? AND location_key=? AND forecast_date=? AND service_period=? AND source_provider=?');$existing->execute([$org,$location['key'],$targetDate,$service,$provider]);$old=$existing->fetch();$public=$old['public_id']??sales_public_id('sales-forecast');$basis=['sampleDates'=>array_column($samples,'period_start'),'lookbackWeeks'=>$weeks,'historicalSalesPerLaborHour'=>$salesPerLaborHour!==null?round($salesPerLaborHour,2):null,'actualLaborHours'=>$actual['hours'],'actualLaborCost'=>$actual['cost'],'decisionBoundary'=>'Operational staffing-capacity estimate only; no employee-level scheduling, discipline, compensation or termination recommendation.'];
    $sql="INSERT INTO sales_forecasts (organization_id,location_id,location_key,public_id,forecast_date,service_period,source_provider,projected_tickets,projected_covers,projected_net_sales,projected_labor_hours,projected_labor_cost,scheduled_labor_hours,scheduled_labor_cost,confidence,sample_count,method,basis_json,generated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),projected_tickets=VALUES(projected_tickets),projected_covers=VALUES(projected_covers),projected_net_sales=VALUES(projected_net_sales),projected_labor_hours=VALUES(projected_labor_hours),projected_labor_cost=VALUES(projected_labor_cost),scheduled_labor_hours=VALUES(scheduled_labor_hours),scheduled_labor_cost=VALUES(scheduled_labor_cost),confidence=VALUES(confidence),sample_count=VALUES(sample_count),basis_json=VALUES(basis_json),generated_by=VALUES(generated_by),generated_at=NOW(6),updated_at=NOW(6)";$pdo->prepare($sql)->execute([$org,$location['id'],$location['key'],$public,$targetDate,$service,$provider,$forecastTickets,$forecastCovers,$forecastNet,$requiredHours,$projectedCost,$scheduled['hours'],$scheduled['cost'],$confidence,count($samples),'same_weekday_mean_median',json_encode($basis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId]);$q=$pdo->prepare('SELECT id FROM sales_forecasts WHERE organization_id=? AND public_id=?');$q->execute([$org,$public]);$forecastId=(int)$q->fetchColumn();$pdo->prepare('DELETE FROM sales_forecast_items WHERE organization_id=? AND sales_forecast_id=?')->execute([$org,$forecastId]);
    $sampleDates=array_column($samples,'period_start');$items=[];if($sampleDates){$placeholders=implode(',',array_fill(0,count($sampleDates),'?'));$q=$pdo->prepare("SELECT sip.source_item_key,MAX(sip.item_name) item_name,MAX(sip.category_name) category_name,MAX(sip.menu_item_id) menu_item_id,AVG(sip.quantity) qty,AVG(sip.net_sales) sales,COUNT(*) samples FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.source_provider=? AND sp.location_key=? AND sp.service_period=? AND sp.period_start IN ({$placeholders}) GROUP BY sip.source_item_key ORDER BY sales DESC LIMIT 100");$q->execute([$org,$provider,$location['key'],$service,...$sampleDates]);foreach($q->fetchAll() as $r){$c=min($confidence,max(.2,(int)$r['samples']/max(1,count($samples))*$confidence));$pdo->prepare('INSERT INTO sales_forecast_items (organization_id,sales_forecast_id,menu_item_id,source_item_key,item_name,category_name,projected_quantity,projected_net_sales,confidence,sample_count) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,$forecastId,$r['menu_item_id']?:null,$r['source_item_key'],$r['item_name'],$r['category_name'],$r['qty'],$r['sales'],$c,(int)$r['samples']]);$items[]=['itemName'=>$r['item_name'],'category'=>$r['category_name'],'quantity'=>round((float)$r['qty'],2),'netSales'=>round((float)$r['sales'],2),'confidence'=>round($c,4),'samples'=>(int)$r['samples']];}}
    if(prep_intelligence_ready($pdo)){$pdo->prepare("DELETE FROM prep_demand_signals WHERE organization_id=? AND source_type='sales_forecast' AND source_public_id=?")->execute([$org,$public]);if($forecastCovers!==null)prep_add_demand_signal($pdo,$org,['date'=>$targetDate,'service'=>$service,'type'=>'sales_forecast','itemName'=>'Projected covers','quantity'=>round($forecastCovers,2),'unit'=>'covers','confidence'=>$confidence,'sourceType'=>'sales_forecast','sourcePublicId'=>$public,'metadata'=>['projectedNetSales'=>round($forecastNet,2)]],$userId);foreach(array_slice($items,0,30) as $item)prep_add_demand_signal($pdo,$org,['date'=>$targetDate,'service'=>$service,'type'=>'sales_item_forecast','itemName'=>$item['itemName'],'quantity'=>$item['quantity'],'unit'=>'ea','confidence'=>$item['confidence'],'sourceType'=>'sales_forecast','sourcePublicId'=>$public,'metadata'=>['category'=>$item['category'],'projectedNetSales'=>$item['netSales']]],$userId);}
    $positions=$requiredHours!==null?sales_position_capacity($pdo,$org,$targetDate,$service,$requiredHours,$locationId):[];return sales_forecast_detail($pdo,$org,$public)+['positionCapacity'=>$positions];
}

function sales_forecast_detail(PDO $pdo,int $org,string $public): array
{
    $q=$pdo->prepare("SELECT f.*,l.name location_name FROM sales_forecasts f LEFT JOIN locations l ON l.id=f.location_id WHERE f.organization_id=? AND f.public_id=? LIMIT 1");$q->execute([$org,$public]);$f=$q->fetch();if(!$f)return [];$f['basis']=$f['basis_json']?json_decode((string)$f['basis_json'],true):null;unset($f['basis_json']);$q=$pdo->prepare('SELECT item_name,category_name,projected_quantity,projected_net_sales,confidence,sample_count FROM sales_forecast_items WHERE organization_id=? AND sales_forecast_id=? ORDER BY projected_net_sales DESC,projected_quantity DESC LIMIT 100');$q->execute([$org,(int)$f['id']]);return ['forecast'=>$f,'items'=>$q->fetchAll()];
}

function sales_dashboard(PDO $pdo,int $org,string $from,string $to,?int $locationId=null): array
{
    $provider=sales_primary_provider($pdo,$org);$location=sales_location($pdo,$org,'',$locationId);$q=$pdo->prepare("SELECT period_start,tickets,covers,gross_sales,net_sales,discounts_amount,comps_amount,voids_amount,refunds_amount FROM sales_periods WHERE organization_id=? AND source_provider=? AND location_key=? AND granularity='daily' AND service_period='all' AND period_start BETWEEN ? AND ? ORDER BY period_start");$q->execute([$org,$provider,$location['key'],$from,$to]);$daily=$q->fetchAll();$totals=['tickets'=>0,'covers'=>0,'grossSales'=>0.0,'netSales'=>0.0,'discounts'=>0.0,'comps'=>0.0,'voids'=>0.0,'refunds'=>0.0];foreach($daily as $r){$totals['tickets']+=(int)$r['tickets'];$totals['covers']+=(int)$r['covers'];$totals['grossSales']+=(float)$r['gross_sales'];$totals['netSales']+=(float)$r['net_sales'];$totals['discounts']+=(float)$r['discounts_amount'];$totals['comps']+=(float)$r['comps_amount'];$totals['voids']+=(float)$r['voids_amount'];$totals['refunds']+=(float)$r['refunds_amount'];}$totals['averageCheck']=$totals['tickets']>0?round($totals['netSales']/$totals['tickets'],2):null;$actual=sales_actual_labor_range($pdo,$org,$from,$to,$locationId);$totals['actualLaborHours']=$actual['hours'];$totals['actualLaborCost']=$actual['cost'];$totals['laborPercent']=$totals['netSales']>0?round($actual['cost']/$totals['netSales']*100,2):null;$totals['salesPerLaborHour']=$actual['hours']>0?round($totals['netSales']/$actual['hours'],2):null;
    $q=$pdo->prepare("SELECT sip.item_name,sip.category_name,SUM(sip.quantity) quantity,SUM(sip.net_sales) net_sales FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.source_provider=? AND sp.location_key=? AND sp.period_start BETWEEN ? AND ? GROUP BY sip.source_item_key,sip.item_name,sip.category_name ORDER BY net_sales DESC LIMIT 40");$q->execute([$org,$provider,$location['key'],$from,$to]);$items=$q->fetchAll();$q=$pdo->prepare("SELECT granularity,period_start,period_end,net_sales,tickets,covers FROM sales_periods WHERE organization_id=? AND source_provider=? AND location_key=? AND granularity IN ('weekly','monthly') AND period_end>=? AND period_start<=? ORDER BY period_start DESC LIMIT 24");$q->execute([$org,$provider,$location['key'],$from,$to]);$periods=$q->fetchAll();$q=$pdo->prepare("SELECT public_id,original_filename,period_start,period_end,row_count,accepted_count,rejected_count,status,imported_at FROM sales_import_batches WHERE organization_id=? ORDER BY id DESC LIMIT 20");$q->execute([$org]);$imports=$q->fetchAll();$q=$pdo->prepare("SELECT public_id,forecast_date,service_period,projected_net_sales,projected_covers,projected_labor_hours,scheduled_labor_hours,confidence FROM sales_forecasts WHERE organization_id=? AND source_provider=? AND forecast_date>=CURDATE() ORDER BY forecast_date,FIELD(service_period,'morning','lunch','dinner','closing','all') LIMIT 30");$q->execute([$org,$provider]);return ['provider'=>$provider,'location'=>$location,'totals'=>$totals,'daily'=>$daily,'periodSummaries'=>$periods,'items'=>$items,'imports'=>$imports,'forecasts'=>$q->fetchAll(),'integrations'=>sales_integrations($pdo,$org),'providerCatalog'=>sales_provider_catalog()];
}
