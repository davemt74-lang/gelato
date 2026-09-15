<?php
declare(strict_types=1);

require_once __DIR__.'/restaurant-brain.php';

function menu_operations_tables(): array
{
    return ['menu_item_operational_status','menu_price_operational_status','menu_item_availability_schedules','menu_operation_events'];
}

function menu_operations_ready(PDO $pdo): bool
{
    try {
        foreach(menu_operations_tables() as $table){
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$table]);
            if((int)$q->fetchColumn()!==1)return false;
        }
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='menu_items' AND column_name='sort_order'");
        return (int)$q->fetchColumn()===1;
    } catch(Throwable) { return false; }
}

function menu_operations_channels(): array
{
    return ['public_menu','online_order','pos','packages','catering'];
}

function menu_operations_public_id(): string
{
    return 'menuevt-'.bin2hex(random_bytes(10));
}

function menu_operations_location_timezone(PDO $pdo,int $org,?int $locationId): string
{
    $timezone='America/Phoenix';
    try {
        $q=$pdo->prepare('SELECT timezone FROM organizations WHERE id=? LIMIT 1');$q->execute([$org]);$orgTz=trim((string)$q->fetchColumn());if($orgTz!=='')$timezone=$orgTz;
        if($locationId){$q=$pdo->prepare('SELECT timezone FROM locations WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$locationId]);$locTz=trim((string)$q->fetchColumn());if($locTz!=='')$timezone=$locTz;}
        new DateTimeZone($timezone);
    } catch(Throwable) { $timezone='UTC'; }
    return $timezone;
}

function menu_operations_parse_resume(PDO $pdo,int $org,int $locationId,?string $value): ?string
{
    $value=trim((string)$value);if($value==='')return null;
    try {
        $local=new DateTimeImmutable($value,new DateTimeZone(menu_operations_location_timezone($pdo,$org,$locationId)));
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch(Throwable) { throw new InvalidArgumentException('Auto-resume time is invalid.'); }
}

function menu_operations_item_row(PDO $pdo,int $org,int $itemId): array
{
    $q=$pdo->prepare('SELECT i.id,i.name,i.section_id,i.is_active,i.sort_order,s.name category_name FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id WHERE i.organization_id=? AND i.id=? LIMIT 1');$q->execute([$org,$itemId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Menu item was not found.');return $row;
}

function menu_operations_price_row(PDO $pdo,int $org,int $priceId): array
{
    $q=$pdo->prepare('SELECT p.id,p.menu_item_id,p.option_name,p.size_code,p.amount,i.name item_name FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id WHERE i.organization_id=? AND p.id=? LIMIT 1');$q->execute([$org,$priceId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Menu size / price was not found.');return $row;
}

function menu_operations_location_row(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare('SELECT id,name,status,timezone FROM locations WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$locationId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Restaurant location was not found.');return $row;
}

function menu_operations_event(PDO $pdo,int $org,string $type,string $summary,?int $itemId,?int $priceId,?int $locationId,?int $userId,array $details=[]): void
{
    if(!menu_operations_ready($pdo))return;
    $summary=mb_substr(trim($summary),0,500,'UTF-8');if($summary==='')$summary=$type;
    $json=$details?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $pdo->prepare('INSERT INTO menu_operation_events (organization_id,public_id,event_type,menu_item_id,menu_item_price_id,location_id,summary,details_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$org,menu_operations_public_id(),mb_substr($type,0,80,'UTF-8'),$itemId,$priceId,$locationId,$summary,$json,$userId]);
}

function menu_operations_status_is_blocking(array $row): bool
{
    if(empty($row['is_sold_out']))return false;
    $resume=trim((string)($row['resume_at']??''));
    if($resume==='')return true;
    try{return new DateTimeImmutable($resume,new DateTimeZone('UTC'))>new DateTimeImmutable('now',new DateTimeZone('UTC'));}catch(Throwable){return true;}
}

function menu_operations_item_status(PDO $pdo,int $org,int $itemId,int $locationId): array
{
    if(!menu_operations_ready($pdo))return ['soldOut'=>false,'reason'=>'','resumeAt'=>null];
    $q=$pdo->prepare('SELECT is_sold_out,sold_out_reason,resume_at,updated_at FROM menu_item_operational_status WHERE organization_id=? AND menu_item_id=? AND location_id=? LIMIT 1');$q->execute([$org,$itemId,$locationId]);$row=$q->fetch();
    if(!$row)return ['soldOut'=>false,'reason'=>'','resumeAt'=>null];
    return ['soldOut'=>menu_operations_status_is_blocking($row),'storedSoldOut'=>(bool)$row['is_sold_out'],'reason'=>(string)($row['sold_out_reason']??''),'resumeAt'=>$row['resume_at'],'updatedAt'=>$row['updated_at']];
}

function menu_operations_price_status(PDO $pdo,int $org,int $priceId,int $locationId): array
{
    if(!menu_operations_ready($pdo))return ['soldOut'=>false,'reason'=>'','resumeAt'=>null];
    $q=$pdo->prepare('SELECT is_sold_out,sold_out_reason,resume_at,updated_at FROM menu_price_operational_status WHERE organization_id=? AND menu_item_price_id=? AND location_id=? LIMIT 1');$q->execute([$org,$priceId,$locationId]);$row=$q->fetch();
    if(!$row)return ['soldOut'=>false,'reason'=>'','resumeAt'=>null];
    return ['soldOut'=>menu_operations_status_is_blocking($row),'storedSoldOut'=>(bool)$row['is_sold_out'],'reason'=>(string)($row['sold_out_reason']??''),'resumeAt'=>$row['resume_at'],'updatedAt'=>$row['updated_at']];
}

function menu_operations_set_item_status(PDO $pdo,int $org,int $itemId,int $locationId,bool $soldOut,string $reason='',?string $resumeAt=null,?int $userId=null): array
{
    $item=menu_operations_item_row($pdo,$org,$itemId);$location=menu_operations_location_row($pdo,$org,$locationId);
    $reason=mb_substr(trim($reason),0,500,'UTF-8')?:null;$resume=$soldOut?menu_operations_parse_resume($pdo,$org,$locationId,$resumeAt):null;
    $pdo->prepare("INSERT INTO menu_item_operational_status (organization_id,menu_item_id,location_id,is_sold_out,sold_out_reason,resume_at,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_sold_out=VALUES(is_sold_out),sold_out_reason=VALUES(sold_out_reason),resume_at=VALUES(resume_at),updated_by=VALUES(updated_by),updated_at=NOW(6)")
        ->execute([$org,$itemId,$locationId,$soldOut?1:0,$reason,$resume,$userId]);
    $summary=$item['name'].' '.($soldOut?'sold out':'available').' at '.$location['name'].($reason?' — '.$reason:'');
    menu_operations_event($pdo,$org,$soldOut?'item.sold_out':'item.available',$summary,$itemId,null,$locationId,$userId,['resumeAt'=>$resume]);
    menu_operations_sync_brain($pdo,$org,$userId);
    return menu_operations_item_status($pdo,$org,$itemId,$locationId);
}

function menu_operations_set_price_status(PDO $pdo,int $org,int $priceId,int $locationId,bool $soldOut,string $reason='',?string $resumeAt=null,?int $userId=null): array
{
    $price=menu_operations_price_row($pdo,$org,$priceId);$location=menu_operations_location_row($pdo,$org,$locationId);
    $reason=mb_substr(trim($reason),0,500,'UTF-8')?:null;$resume=$soldOut?menu_operations_parse_resume($pdo,$org,$locationId,$resumeAt):null;
    $pdo->prepare("INSERT INTO menu_price_operational_status (organization_id,menu_item_price_id,location_id,is_sold_out,sold_out_reason,resume_at,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_sold_out=VALUES(is_sold_out),sold_out_reason=VALUES(sold_out_reason),resume_at=VALUES(resume_at),updated_by=VALUES(updated_by),updated_at=NOW(6)")
        ->execute([$org,$priceId,$locationId,$soldOut?1:0,$reason,$resume,$userId]);
    $label=trim((string)$price['option_name'])?:trim((string)$price['size_code']);$summary=$price['item_name'].' · '.$label.' '.($soldOut?'sold out':'available').' at '.$location['name'].($reason?' — '.$reason:'');
    menu_operations_event($pdo,$org,$soldOut?'price.sold_out':'price.available',$summary,(int)$price['menu_item_id'],$priceId,$locationId,$userId,['resumeAt'=>$resume]);
    menu_operations_sync_brain($pdo,$org,$userId);
    return menu_operations_price_status($pdo,$org,$priceId,$locationId);
}

function menu_operations_schedule_allows(PDO $pdo,int $org,int $itemId,string $channel,?int $locationId): bool
{
    if(!menu_operations_ready($pdo))return true;if(!in_array($channel,menu_operations_channels(),true))return false;
    $params=[$org,$itemId,$channel];$locationSql='location_id IS NULL';
    if($locationId){$locationSql='(location_id IS NULL OR location_id=?)';$params[]=$locationId;}
    $q=$pdo->prepare("SELECT day_of_week,start_time,end_time FROM menu_item_availability_schedules WHERE organization_id=? AND menu_item_id=? AND channel=? AND {$locationSql} AND is_enabled=1 ORDER BY location_id IS NULL,day_of_week,start_time");$q->execute($params);$rows=$q->fetchAll();if(!$rows)return true;
    $now=new DateTimeImmutable('now',new DateTimeZone(menu_operations_location_timezone($pdo,$org,$locationId)));$day=(int)$now->format('w');$time=$now->format('H:i:s');
    foreach($rows as $row){if((int)$row['day_of_week']!==$day)continue;$start=(string)$row['start_time'];$end=(string)$row['end_time'];if($start===$end)return true;if($start<$end&&$time>=$start&&$time<$end)return true;if($start>$end&&($time>=$start||$time<$end))return true;}
    return false;
}

function menu_operations_item_sellable(PDO $pdo,int $org,int $itemId,string $channel,?int $locationId): bool
{
    if(!menu_operations_ready($pdo))return true;
    if($locationId){$status=menu_operations_item_status($pdo,$org,$itemId,$locationId);if(!empty($status['soldOut']))return false;}
    return menu_operations_schedule_allows($pdo,$org,$itemId,$channel,$locationId);
}

function menu_operations_price_sellable(PDO $pdo,int $org,int $priceId,string $channel,?int $locationId): bool
{
    if(!menu_operations_ready($pdo)||!$locationId)return true;
    $status=menu_operations_price_status($pdo,$org,$priceId,$locationId);return empty($status['soldOut']);
}

function menu_operations_save_schedule(PDO $pdo,int $org,int $itemId,?int $locationId,string $channel,array $rows,?int $userId): array
{
    menu_operations_item_row($pdo,$org,$itemId);if($locationId)menu_operations_location_row($pdo,$org,$locationId);if(!in_array($channel,menu_operations_channels(),true))throw new InvalidArgumentException('Unknown menu distribution channel.');
    $clean=[];foreach($rows as $row){if(!is_array($row))continue;$day=(int)($row['dayOfWeek']??-1);if($day<0||$day>6)throw new InvalidArgumentException('Schedule day must be between Sunday and Saturday.');$start=trim((string)($row['startTime']??''));$end=trim((string)($row['endTime']??''));if(!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',$start)||!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/',$end))throw new InvalidArgumentException('Schedule start and end times are required.');$clean[]=[$day,strlen($start)===5?$start.':00':$start,strlen($end)===5?$end.':00':$end];}
    $pdo->beginTransaction();try{$sql='DELETE FROM menu_item_availability_schedules WHERE organization_id=? AND menu_item_id=? AND channel=? AND '.($locationId?'location_id=?':'location_id IS NULL');$args=[$org,$itemId,$channel];if($locationId)$args[]=$locationId;$pdo->prepare($sql)->execute($args);foreach($clean as [$day,$start,$end])$pdo->prepare('INSERT INTO menu_item_availability_schedules (organization_id,menu_item_id,location_id,channel,day_of_week,start_time,end_time,is_enabled,created_by,updated_by) VALUES (?,?,?,?,?,?,?,1,?,?)')->execute([$org,$itemId,$locationId,$channel,$day,$start,$end,$userId,$userId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $item=menu_operations_item_row($pdo,$org,$itemId);menu_operations_event($pdo,$org,'schedule.updated',$item['name'].' availability schedule updated for '.$channel,$itemId,null,$locationId,$userId,['rows'=>count($clean),'channel'=>$channel]);menu_operations_sync_brain($pdo,$org,$userId);return menu_operations_schedules($pdo,$org,$itemId,$locationId);
}

function menu_operations_schedules(PDO $pdo,int $org,int $itemId,?int $locationId=null): array
{
    if(!menu_operations_ready($pdo))return [];$sql='SELECT id,location_id,channel,day_of_week,start_time,end_time,is_enabled,updated_at FROM menu_item_availability_schedules WHERE organization_id=? AND menu_item_id=?'.($locationId?' AND (location_id IS NULL OR location_id=?)':'').' ORDER BY channel,location_id,day_of_week,start_time';$args=[$org,$itemId];if($locationId)$args[]=$locationId;$q=$pdo->prepare($sql);$q->execute($args);return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'channel'=>(string)$r['channel'],'dayOfWeek'=>(int)$r['day_of_week'],'startTime'=>substr((string)$r['start_time'],0,5),'endTime'=>substr((string)$r['end_time'],0,5),'enabled'=>(bool)$r['is_enabled'],'updatedAt'=>$r['updated_at']],$q->fetchAll());
}

function menu_operations_update_price(PDO $pdo,int $org,int $priceId,float $amount,?int $userId): array
{
    if($amount<0||$amount>100000)throw new InvalidArgumentException('Price must be between $0.00 and $100,000.00.');$price=menu_operations_price_row($pdo,$org,$priceId);$amount=round($amount,2);$pdo->prepare('UPDATE menu_item_prices SET amount=? WHERE id=?')->execute([$amount,$priceId]);$label=trim((string)$price['option_name']);menu_operations_event($pdo,$org,'price.updated',$price['item_name'].' · '.$label.' price changed from $'.number_format((float)$price['amount'],2).' to $'.number_format($amount,2),(int)$price['menu_item_id'],$priceId,null,$userId,['before'=>(float)$price['amount'],'after'=>$amount]);menu_operations_sync_brain($pdo,$org,$userId);return menu_operations_price_row($pdo,$org,$priceId);
}

function menu_operations_bulk_price(PDO $pdo,int $org,array $itemIds,string $mode,float $value,?int $userId): int
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$itemIds),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');if(!in_array($mode,['percent','fixed'],true))throw new InvalidArgumentException('Bulk price mode must be percent or fixed amount.');if(abs($value)>100000)throw new InvalidArgumentException('Bulk price change is too large.');$count=0;
    $pdo->beginTransaction();try{foreach($ids as $itemId){$item=menu_operations_item_row($pdo,$org,$itemId);$q=$pdo->prepare('SELECT id,amount FROM menu_item_prices WHERE menu_item_id=? AND (active_until IS NULL OR active_until>NOW(6)) FOR UPDATE');$q->execute([$itemId]);foreach($q->fetchAll() as $price){$before=(float)$price['amount'];$after=$mode==='percent'?$before*(1+$value/100):$before+$value;$after=max(0,round($after,2));$pdo->prepare('UPDATE menu_item_prices SET amount=? WHERE id=?')->execute([$after,(int)$price['id']]);$count++;}menu_operations_event($pdo,$org,'price.bulk_updated',$item['name'].' prices bulk adjusted',$itemId,null,null,$userId,['mode'=>$mode,'value'=>$value]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}menu_operations_sync_brain($pdo,$org,$userId);return $count;
}

function menu_operations_reorder_categories(PDO $pdo,int $org,array $ids,?int $userId): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Category order is empty.');$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT id FROM menu_sections WHERE organization_id=?');$q->execute([$org]);$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));foreach($ids as $id)if(!in_array($id,$valid,true))throw new InvalidArgumentException('A menu category does not belong to this restaurant.');foreach($ids as $index=>$id)$pdo->prepare('UPDATE menu_sections SET sort_order=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([($index+1)*10,$org,$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}menu_operations_event($pdo,$org,'categories.reordered','Menu categories reordered',null,null,null,$userId,['ids'=>$ids]);menu_operations_sync_brain($pdo,$org,$userId);
}

function menu_operations_reorder_items(PDO $pdo,int $org,int $categoryId,array $ids,?int $userId): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Item order is empty.');$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT id FROM menu_items WHERE organization_id=? AND section_id=?');$q->execute([$org,$categoryId]);$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));foreach($ids as $id)if(!in_array($id,$valid,true))throw new InvalidArgumentException('A menu item is not in this category.');foreach($ids as $index=>$id)$pdo->prepare('UPDATE menu_items SET sort_order=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([($index+1)*10,$org,$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}menu_operations_event($pdo,$org,'items.reordered','Menu items reordered',null,null,null,$userId,['categoryId'=>$categoryId,'ids'=>$ids]);menu_operations_sync_brain($pdo,$org,$userId);
}

function menu_operations_recent_events(PDO $pdo,int $org,int $limit=30): array
{
    if(!menu_operations_ready($pdo))return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT e.public_id,e.event_type,e.summary,e.menu_item_id,e.menu_item_price_id,e.location_id,e.details_json,e.created_at,u.display_name actor_name FROM menu_operation_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.organization_id=? ORDER BY e.id DESC LIMIT {$limit}");$q->execute([$org]);$out=[];foreach($q->fetchAll() as $r){$details=[];if($r['details_json'])try{$details=json_decode((string)$r['details_json'],true,32,JSON_THROW_ON_ERROR)?:[];}catch(Throwable){}$out[]=['id'=>(string)$r['public_id'],'type'=>(string)$r['event_type'],'summary'=>(string)$r['summary'],'itemId'=>$r['menu_item_id']!==null?(int)$r['menu_item_id']:null,'priceId'=>$r['menu_item_price_id']!==null?(int)$r['menu_item_price_id']:null,'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'details'=>$details,'actor'=>(string)($r['actor_name']??''),'createdAt'=>$r['created_at']];}return $out;
}

function menu_operations_image_completeness(PDO $pdo,int $org): array
{
    $result=['menuItems'=>['total'=>0,'missing'=>0],'ingredients'=>['total'=>0,'missing'=>0],'locations'=>['total'=>0,'missing'=>0]];
    try{$q=$pdo->prepare('SELECT COUNT(*) total,SUM(main_image_file_id IS NULL) missing FROM menu_items WHERE organization_id=? AND is_active=1');$q->execute([$org]);$r=$q->fetch();$result['menuItems']=['total'=>(int)($r['total']??0),'missing'=>(int)($r['missing']??0)];}catch(Throwable){}
    try{$q=$pdo->prepare('SELECT COUNT(*) total,SUM(image_file_id IS NULL) missing FROM ingredients WHERE organization_id=?');$q->execute([$org]);$r=$q->fetch();$result['ingredients']=['total'=>(int)($r['total']??0),'missing'=>(int)($r['missing']??0)];}catch(Throwable){}
    try{$q=$pdo->prepare("SELECT COUNT(*) total,SUM(cover_image_file_id IS NULL) missing FROM locations WHERE organization_id=? AND status='active'");$q->execute([$org]);$r=$q->fetch();$result['locations']=['total'=>(int)($r['total']??0),'missing'=>(int)($r['missing']??0)];}catch(Throwable){}
    return $result;
}

function menu_operations_summary(PDO $pdo,int $org,?int $locationId=null): array
{
    $summary=['published'=>0,'draft'=>0,'paused'=>0,'soldOutItems'=>0,'soldOutSizes'=>0,'scheduledItems'=>0,'missingImages'=>menu_operations_image_completeness($pdo,$org)];
    try{$q=$pdo->prepare("SELECT COALESCE(mp.lifecycle_status,IF(i.is_active=1,'published','paused')) status,COUNT(*) total FROM menu_items i LEFT JOIN menu_item_profiles mp ON mp.organization_id=i.organization_id AND mp.menu_item_id=i.id WHERE i.organization_id=? GROUP BY status");$q->execute([$org]);foreach($q->fetchAll() as $r)if(isset($summary[(string)$r['status']]))$summary[(string)$r['status']]=(int)$r['total'];}catch(Throwable){}
    if(menu_operations_ready($pdo)){
        $args=[$org];$location='';if($locationId){$location=' AND location_id=?';$args[]=$locationId;}$q=$pdo->prepare("SELECT COUNT(*) FROM menu_item_operational_status WHERE organization_id=? {$location} AND is_sold_out=1 AND (resume_at IS NULL OR resume_at>UTC_TIMESTAMP(6))");$q->execute($args);$summary['soldOutItems']=(int)$q->fetchColumn();$q=$pdo->prepare("SELECT COUNT(*) FROM menu_price_operational_status WHERE organization_id=? {$location} AND is_sold_out=1 AND (resume_at IS NULL OR resume_at>UTC_TIMESTAMP(6))");$q->execute($args);$summary['soldOutSizes']=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(DISTINCT menu_item_id) FROM menu_item_availability_schedules WHERE organization_id=? AND is_enabled=1');$q->execute([$org]);$summary['scheduledItems']=(int)$q->fetchColumn();
    }
    return $summary;
}

function menu_operations_location_state(PDO $pdo,int $org,int $locationId): array
{
    menu_operations_location_row($pdo,$org,$locationId);$items=[];$q=$pdo->prepare("SELECT i.id,i.name,s.name category_name FROM menu_items i JOIN menu_sections s ON s.id=i.section_id WHERE i.organization_id=? ORDER BY s.sort_order,i.sort_order,i.name");$q->execute([$org]);foreach($q->fetchAll() as $item){$itemId=(int)$item['id'];$row=['id'=>$itemId,'name'=>(string)$item['name'],'category'=>(string)$item['category_name'],'status'=>menu_operations_item_status($pdo,$org,$itemId,$locationId),'sizes'=>[]];$p=$pdo->prepare('SELECT id,option_name,size_code,amount FROM menu_item_prices WHERE menu_item_id=? AND (active_until IS NULL OR active_until>NOW(6)) ORDER BY sort_order,id');$p->execute([$itemId]);foreach($p->fetchAll() as $price)$row['sizes'][]=['id'=>(int)$price['id'],'label'=>(string)$price['option_name'],'sizeCode'=>(string)($price['size_code']??''),'amount'=>(float)$price['amount'],'status'=>menu_operations_price_status($pdo,$org,(int)$price['id'],$locationId)];$items[]=$row;}return $items;
}

function menu_operations_snapshot_text(PDO $pdo,int $org): string
{
    $summary=menu_operations_summary($pdo,$org);$lines=['Menu Operations Snapshot','Published items: '.$summary['published'],'Draft items: '.$summary['draft'],'Paused items: '.$summary['paused'],'Active item-level sold-outs: '.$summary['soldOutItems'],'Active size-level sold-outs: '.$summary['soldOutSizes'],'Items with availability schedules: '.$summary['scheduledItems']];$img=$summary['missingImages'];$lines[]='Missing images: '.$img['menuItems']['missing'].' menu item(s), '.$img['ingredients']['missing'].' ingredient(s), '.$img['locations']['missing'].' location(s).';
    try{$q=$pdo->prepare("SELECT l.name location_name,i.name item_name,s.sold_out_reason,s.resume_at FROM menu_item_operational_status s JOIN menu_items i ON i.id=s.menu_item_id JOIN locations l ON l.id=s.location_id WHERE s.organization_id=? AND s.is_sold_out=1 AND (s.resume_at IS NULL OR s.resume_at>UTC_TIMESTAMP(6)) ORDER BY l.name,i.name LIMIT 40");$q->execute([$org]);$rows=$q->fetchAll();if($rows){$lines[]='Current item sold-outs:';foreach($rows as $r)$lines[]='- '.$r['location_name'].': '.$r['item_name'].(($r['sold_out_reason']??'')?' — '.$r['sold_out_reason']:'').($r['resume_at']?' — resumes '.$r['resume_at'].' UTC':'');}}
    catch(Throwable){}
    try{$q=$pdo->prepare("SELECT l.name location_name,i.name item_name,p.option_name,s.sold_out_reason,s.resume_at FROM menu_price_operational_status s JOIN menu_item_prices p ON p.id=s.menu_item_price_id JOIN menu_items i ON i.id=p.menu_item_id JOIN locations l ON l.id=s.location_id WHERE s.organization_id=? AND s.is_sold_out=1 AND (s.resume_at IS NULL OR s.resume_at>UTC_TIMESTAMP(6)) ORDER BY l.name,i.name,p.sort_order LIMIT 40");$q->execute([$org]);$rows=$q->fetchAll();if($rows){$lines[]='Current size sold-outs:';foreach($rows as $r)$lines[]='- '.$r['location_name'].': '.$r['item_name'].' / '.$r['option_name'].(($r['sold_out_reason']??'')?' — '.$r['sold_out_reason']:'').($r['resume_at']?' — resumes '.$r['resume_at'].' UTC':'');}}
    catch(Throwable){}
    $events=menu_operations_recent_events($pdo,$org,12);if($events){$lines[]='Recent menu changes:';foreach($events as $event)$lines[]='- '.$event['createdAt'].' '.$event['summary'];}
    return implode("\n",$lines);
}

function menu_operations_sync_brain(PDO $pdo,int $org,?int $userId): void
{
    if(!menu_operations_ready($pdo))return;restaurant_brain_write_knowledge($pdo,$org,'menu_operations','current','Menu Operations Snapshot',menu_operations_snapshot_text($pdo,$org),$userId);
}

function menu_operations_search(PDO $pdo,int $org,string $query='',int $limit=20,?int $locationId=null): array
{
    $limit=max(1,min(50,$limit));$query=trim($query);$like='%'.$query.'%';$q=$pdo->prepare("SELECT i.id,i.name,i.description,s.name category_name,mp.lifecycle_status,MIN(p.amount) min_price,MAX(p.amount) max_price,COUNT(p.id) size_count FROM menu_items i JOIN menu_sections s ON s.id=i.section_id LEFT JOIN menu_item_profiles mp ON mp.organization_id=i.organization_id AND mp.menu_item_id=i.id LEFT JOIN menu_item_prices p ON p.menu_item_id=i.id AND (p.active_until IS NULL OR p.active_until>NOW(6)) WHERE i.organization_id=? AND (?='' OR i.name LIKE ? OR i.description LIKE ? OR s.name LIKE ?) GROUP BY i.id,s.id,mp.id ORDER BY s.sort_order,i.sort_order,i.name LIMIT {$limit}");$q->execute([$org,$query,$like,$like,$like]);$out=[];foreach($q->fetchAll() as $r){$item=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'description'=>(string)($r['description']??''),'category'=>(string)$r['category_name'],'lifecycle'=>(string)($r['lifecycle_status']??''),'minPrice'=>$r['min_price']!==null?(float)$r['min_price']:null,'maxPrice'=>$r['max_price']!==null?(float)$r['max_price']:null,'sizeCount'=>(int)$r['size_count']];if($locationId)$item['operationalStatus']=menu_operations_item_status($pdo,$org,(int)$r['id'],$locationId);$out[]=$item;}return $out;
}
