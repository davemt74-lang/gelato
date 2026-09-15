<?php
declare(strict_types=1);

require_once __DIR__.'/sales-intelligence-core.php';
require_once __DIR__.'/sales-location-rollup.php';

function pos_ready(PDO $pdo): bool
{
    foreach (['pos_settings','pos_checks','pos_check_items','pos_tenders'] as $table) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if ((int)$q->fetchColumn() !== 1) return false;
    }
    return sales_intelligence_ready($pdo);
}

function pos_money(float $value): float { return round(max(0.0,$value)+1e-9,2); }
function pos_rate(float $value): float { return round(max(0.0,min(0.5,$value)),6); }

function pos_order_types(): array
{
    return ['dine_in','delivery','pickup'];
}

function pos_order_type(string $value): string
{
    $value=strtolower(trim($value));
    if(in_array($value,pos_order_types(),true)) return $value;
    return match($value){
        'bar' => 'dine_in',
        'takeout' => 'pickup',
        default => '',
    };
}

function pos_transaction(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $result=$work();
        if($owns)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function pos_location(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare("SELECT l.id,l.name,o.timezone FROM locations l JOIN organizations o ON o.id=l.organization_id WHERE l.organization_id=? AND l.id=? AND l.status='active' LIMIT 1");
    $q->execute([$org,$locationId]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('The POS location was not found.');
    return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'key'=>'id:'.(int)$row['id'],'timezone'=>(string)($row['timezone']?:'America/Phoenix')];
}

function pos_clock(PDO $pdo,int $org,int $locationId): DateTimeImmutable
{
    $location=pos_location($pdo,$org,$locationId);
    try{$tz=new DateTimeZone($location['timezone']);}catch(Throwable){$tz=new DateTimeZone('America/Phoenix');}
    return new DateTimeImmutable('now',$tz);
}

function pos_locations(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT id,name,city,state FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");$q->execute([$org]);
    return array_map(static fn(array $r): array=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'city'=>$r['city'],'state'=>$r['state']],$q->fetchAll());
}

function pos_primary_location_id(PDO $pdo,int $org,int $membershipId): ?int
{
    $q=$pdo->prepare('SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$membershipId]);$id=(int)($q->fetchColumn()?:0);return $id>0?$id:null;
}

function pos_ensure_sales_integration(PDO $pdo,int $org,int $userId): void
{
    $q=$pdo->prepare("SELECT id FROM sales_integrations WHERE organization_id=? AND provider='gelato_pos' AND location_id IS NULL ORDER BY id LIMIT 1");$q->execute([$org]);
    if($q->fetchColumn()) return;
    $q=$pdo->prepare('SELECT COUNT(*) FROM sales_integrations WHERE organization_id=? AND is_primary=1');$q->execute([$org]);$primary=(int)$q->fetchColumn()===0?1:0;
    $pdo->prepare("INSERT INTO sales_integrations (organization_id,provider,display_name,status,is_primary,sync_enabled,created_by,updated_by) VALUES (?,'gelato_pos','Gelato Native POS','active',?,1,?,?)")->execute([$org,$primary,$userId,$userId]);
}

function pos_settings(PDO $pdo,int $org,int $locationId): array
{
    $location=pos_location($pdo,$org,$locationId);$q=$pdo->prepare('SELECT * FROM pos_settings WHERE organization_id=? AND location_key=? LIMIT 1');$q->execute([$org,$location['key']]);$row=$q->fetch();
    if(!$row)return ['locationId'=>$locationId,'locationName'=>$location['name'],'taxRate'=>0.0,'serviceChargeRate'=>0.0,'defaultServiceMode'=>'dine_in'];
    $mode=pos_order_type((string)$row['default_service_mode'])?:'dine_in';
    return ['locationId'=>$locationId,'locationName'=>$location['name'],'taxRate'=>(float)$row['tax_rate'],'serviceChargeRate'=>(float)$row['service_charge_rate'],'defaultServiceMode'=>$mode];
}

function pos_settings_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    $location=pos_location($pdo,$org,$locationId);$tax=pos_rate((float)($input['taxRate']??0));$service=pos_rate((float)($input['serviceChargeRate']??0));$mode=pos_order_type((string)($input['defaultServiceMode']??'dine_in'));
    if($mode==='')$mode='dine_in';
    $pdo->prepare("INSERT INTO pos_settings (organization_id,location_id,location_key,tax_rate,service_charge_rate,default_service_mode,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),tax_rate=VALUES(tax_rate),service_charge_rate=VALUES(service_charge_rate),default_service_mode=VALUES(default_service_mode),updated_by=VALUES(updated_by),updated_at=NOW(6)")->execute([$org,$locationId,$location['key'],$tax,$service,$mode,$userId]);
    pos_ensure_sales_integration($pdo,$org,$userId);
    if(!empty($input['makePrimary'])){$pdo->prepare('UPDATE sales_integrations SET is_primary=0,updated_by=?,updated_at=NOW(6) WHERE organization_id=?')->execute([$userId,$org]);$pdo->prepare("UPDATE sales_integrations SET is_primary=1,status='active',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND provider='gelato_pos' AND location_id IS NULL")->execute([$userId,$org]);}
    return pos_settings($pdo,$org,$locationId);
}

function pos_menu(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT s.id section_id,s.name section_name,s.sort_order section_sort,i.id item_id,i.name item_name,i.description,p.id price_id,p.option_name,p.size_code,p.amount,p.currency,p.sort_order price_sort FROM menu_sections s JOIN menu_items i ON i.section_id=s.id AND i.organization_id=s.organization_id AND i.is_active=1 JOIN menu_item_prices p ON p.menu_item_id=i.id AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) WHERE s.organization_id=? AND s.status='active' ORDER BY s.sort_order,s.name,i.name,p.sort_order,p.amount,p.id");$q->execute([$org]);
    $sections=[];foreach($q->fetchAll() as $r){$sid=(int)$r['section_id'];$iid=(int)$r['item_id'];if(!isset($sections[$sid]))$sections[$sid]=['id'=>$sid,'name'=>(string)$r['section_name'],'items'=>[]];if(!isset($sections[$sid]['items'][$iid]))$sections[$sid]['items'][$iid]=['id'=>$iid,'name'=>(string)$r['item_name'],'description'=>$r['description'],'prices'=>[]];$sections[$sid]['items'][$iid]['prices'][]=['id'=>(int)$r['price_id'],'optionName'=>(string)$r['option_name'],'sizeCode'=>$r['size_code'],'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency']];}
    foreach($sections as &$section)$section['items']=array_values($section['items']);unset($section);return array_values($sections);
}

function pos_check_base(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql='SELECT c.*,l.name location_name,u.display_name opened_by_name FROM pos_checks c JOIN locations l ON l.id=c.location_id AND l.organization_id=c.organization_id JOIN users u ON u.id=c.opened_by WHERE c.organization_id=? AND c.public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('POS check was not found.');return $row;
}

function pos_check_details(PDO $pdo,int $org,string $publicId): array
{
    $c=pos_check_base($pdo,$org,$publicId,false);$items=$pdo->prepare('SELECT id,menu_item_id,menu_item_price_id,item_name_snapshot,option_name_snapshot,category_name_snapshot,quantity,unit_price,gross_amount,net_amount,special_instructions,status,void_reason,voided_at FROM pos_check_items WHERE organization_id=? AND check_id=? ORDER BY id');$items->execute([$org,(int)$c['id']]);$tenders=$pdo->prepare('SELECT id,public_id,tender_type,amount,tip_amount,received_amount,change_amount,external_reference,status,processed_at FROM pos_tenders WHERE organization_id=? AND check_id=? ORDER BY id');$tenders->execute([$org,(int)$c['id']]);
    $saleDue=pos_money((float)$c['subtotal']-(float)$c['discount_amount']+(float)$c['tax_amount']+(float)$c['service_charge_amount']);$salePaid=0.0;$allTenders=$tenders->fetchAll();foreach($allTenders as $t)if($t['status']==='captured')$salePaid+=(float)$t['amount'];$mode=pos_order_type((string)$c['service_mode'])?:'dine_in';
    return ['id'=>(int)$c['id'],'publicId'=>(string)$c['public_id'],'checkNumber'=>(string)$c['check_number'],'locationId'=>(int)$c['location_id'],'locationName'=>(string)$c['location_name'],'businessDate'=>(string)$c['business_date'],'serviceMode'=>$mode,'tableName'=>$c['table_name'],'guestCount'=>(int)$c['guest_count'],'status'=>(string)$c['status'],'currency'=>(string)$c['currency'],'subtotal'=>(float)$c['subtotal'],'discountAmount'=>(float)$c['discount_amount'],'discountReason'=>$c['discount_reason'],'taxRate'=>(float)$c['tax_rate'],'taxAmount'=>(float)$c['tax_amount'],'serviceChargeRate'=>(float)$c['service_charge_rate'],'serviceChargeAmount'=>(float)$c['service_charge_amount'],'tipAmount'=>(float)$c['tip_amount'],'totalAmount'=>(float)$c['total_amount'],'amountPaid'=>(float)$c['amount_paid'],'saleDue'=>$saleDue,'salePaid'=>pos_money($salePaid),'balanceDue'=>pos_money(max(0,$saleDue-$salePaid)),'notes'=>$c['notes'],'openedBy'=>(string)$c['opened_by_name'],'openedAt'=>(string)$c['opened_at'],'closedAt'=>$c['closed_at'],'items'=>$items->fetchAll(),'tenders'=>$allTenders];
}

function pos_normalize_check_rows(array $rows): array
{
    foreach($rows as &$row){$row['service_mode']=pos_order_type((string)($row['service_mode']??''))?:'dine_in';}unset($row);return $rows;
}

function pos_open_checks(PDO $pdo,int $org,?int $locationId=null): array
{
    $hasServiceTables=false;
    try{
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='service_tables'");
        $q->execute();
        $hasServiceTables=(int)$q->fetchColumn()===1;
    }catch(Throwable){}
    $tableSelect=$hasServiceTables?',t.id table_id':',NULL table_id';
    $tableJoin=$hasServiceTables?' LEFT JOIN service_tables t ON t.organization_id=c.organization_id AND t.location_id=c.location_id AND t.active_check_id=c.id':'';
    $sql="SELECT c.public_id,c.check_number,c.location_id,l.name location_name,c.service_mode,c.table_name,c.guest_count,c.subtotal,c.discount_amount,c.tax_amount,c.service_charge_amount,c.tip_amount,c.total_amount,c.amount_paid,c.opened_at,u.display_name opened_by_name".$tableSelect." FROM pos_checks c JOIN locations l ON l.id=c.location_id JOIN users u ON u.id=c.opened_by".$tableJoin." WHERE c.organization_id=? AND c.status='open'";
    $args=[$org];if($locationId){$sql.=' AND c.location_id=?';$args[]=$locationId;}$sql.=' ORDER BY c.opened_at DESC,c.id DESC LIMIT 100';$q=$pdo->prepare($sql);$q->execute($args);return pos_normalize_check_rows($q->fetchAll());
}

function pos_recent_checks(PDO $pdo,int $org,?int $locationId=null,int $limit=30): array
{
    $limit=max(1,min(100,$limit));$sql="SELECT c.public_id,c.check_number,c.location_id,l.name location_name,c.service_mode,c.table_name,c.guest_count,c.status,c.total_amount,c.closed_at,c.cancelled_at FROM pos_checks c JOIN locations l ON l.id=c.location_id WHERE c.organization_id=? AND c.status<>'open'";$args=[$org];if($locationId){$sql.=' AND c.location_id=?';$args[]=$locationId;}$sql.=' ORDER BY COALESCE(c.closed_at,c.cancelled_at,c.updated_at) DESC,c.id DESC LIMIT '.$limit;$q=$pdo->prepare($sql);$q->execute($args);return pos_normalize_check_rows($q->fetchAll());
}

function pos_recalculate_check(PDO $pdo,int $org,int $checkId): void
{
    $q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount),0) subtotal FROM pos_check_items WHERE organization_id=? AND check_id=? AND status='active'");$q->execute([$org,$checkId]);$subtotal=pos_money((float)$q->fetchColumn());
    $q=$pdo->prepare('SELECT discount_amount,tax_rate,service_charge_rate FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$checkId]);$c=$q->fetch();if(!$c)throw new RuntimeException('POS check disappeared during recalculation.');$discount=min($subtotal,pos_money((float)$c['discount_amount']));$base=pos_money(max(0,$subtotal-$discount));$tax=pos_money($base*(float)$c['tax_rate']);$service=pos_money($base*(float)$c['service_charge_rate']);
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) sale_paid,COALESCE(SUM(tip_amount),0) tips FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");$q->execute([$org,$checkId]);$p=$q->fetch()?:[];$salePaid=pos_money((float)($p['sale_paid']??0));$tips=pos_money((float)($p['tips']??0));$total=pos_money($base+$tax+$service+$tips);$amountPaid=pos_money($salePaid+$tips);
    $pdo->prepare('UPDATE pos_checks SET subtotal=?,discount_amount=?,tax_amount=?,service_charge_amount=?,tip_amount=?,total_amount=?,amount_paid=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$subtotal,$discount,$tax,$service,$tips,$total,$amountPaid,$org,$checkId]);
}

function pos_create_check(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    $location=pos_location($pdo,$org,$locationId);$settings=pos_settings($pdo,$org,$locationId);$mode=pos_order_type((string)($input['serviceMode']??$settings['defaultServiceMode']));if($mode==='')throw new InvalidArgumentException('Choose a valid POS order type.');$table=mb_substr(trim((string)($input['tableName']??'')),0,120,'UTF-8')?:null;$guests=max(1,min(99,(int)($input['guestCount']??1)));$notes=mb_substr(trim((string)($input['notes']??'')),0,5000,'UTF-8')?:null;$now=pos_clock($pdo,$org,$locationId);$public=sales_public_id('pos-check');$number=$now->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));pos_ensure_sales_integration($pdo,$org,$userId);
    $pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,public_id,check_number,business_date,service_mode,table_name,guest_count,status,tax_rate,service_charge_rate,notes,opened_by,opened_at) VALUES (?,?,?,?,?,?,?,?,'open',?,?,?,?,?)")->execute([$org,$locationId,$public,$number,$now->format('Y-m-d'),$mode,$table,$guests,$settings['taxRate'],$settings['serviceChargeRate'],$notes,$userId,$now->format('Y-m-d H:i:s.u')]);return pos_check_details($pdo,$org,$public);
}

function pos_require_open_check(PDO $pdo,int $org,string $publicId,bool $forUpdate=true): array
{
    $c=pos_check_base($pdo,$org,$publicId,$forUpdate);if($c['status']!=='open')throw new InvalidArgumentException('This check is no longer open.');return $c;
}

function pos_add_item(PDO $pdo,int $org,string $publicId,int $priceId,float $quantity,string $instructions,int $userId): array
{
    $quantity=round($quantity,3);if($quantity<=0||$quantity>99)throw new InvalidArgumentException('Item quantity must be between 0.001 and 99.');$instructions=mb_substr(trim($instructions),0,1000,'UTF-8');
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$priceId,$quantity,$instructions,$userId):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);$q=$pdo->prepare("SELECT p.id price_id,p.menu_item_id,p.option_name,p.amount,p.currency,i.name item_name,s.name category_name FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.is_active=1 JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id AND s.status='active' WHERE p.id=? AND i.organization_id=? AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) LIMIT 1");$q->execute([$priceId,$org]);$m=$q->fetch();if(!$m)throw new InvalidArgumentException('That menu price is not currently available.');$gross=pos_money((float)$m['amount']*$quantity);$pdo->prepare("INSERT INTO pos_check_items (organization_id,check_id,menu_item_id,menu_item_price_id,item_name_snapshot,option_name_snapshot,category_name_snapshot,quantity,unit_price,gross_amount,net_amount,special_instructions,status,added_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',?)")->execute([$org,(int)$c['id'],(int)$m['menu_item_id'],$priceId,(string)$m['item_name'],(string)$m['option_name'],(string)$m['category_name'],$quantity,(float)$m['amount'],$gross,$gross,$instructions?:null,$userId]);pos_recalculate_check($pdo,$org,(int)$c['id']);return pos_check_details($pdo,$org,$publicId);
    });
}

function pos_update_item(PDO $pdo,int $org,string $publicId,int $itemId,float $quantity,string $instructions): array
{
    $quantity=round($quantity,3);if($quantity<=0||$quantity>99)throw new InvalidArgumentException('Item quantity must be between 0.001 and 99.');$instructions=mb_substr(trim($instructions),0,1000,'UTF-8');
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$itemId,$quantity,$instructions):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);$q=$pdo->prepare("SELECT unit_price FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? AND status='active' LIMIT 1 FOR UPDATE");$q->execute([$org,(int)$c['id'],$itemId]);$unit=$q->fetchColumn();if($unit===false)throw new InvalidArgumentException('Open POS item was not found.');$gross=pos_money((float)$unit*$quantity);$pdo->prepare('UPDATE pos_check_items SET quantity=?,gross_amount=?,net_amount=?,special_instructions=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND id=?')->execute([$quantity,$gross,$gross,$instructions?:null,$org,(int)$c['id'],$itemId]);pos_recalculate_check($pdo,$org,(int)$c['id']);return pos_check_details($pdo,$org,$publicId);
    });
}

function pos_void_item(PDO $pdo,int $org,string $publicId,int $itemId,string $reason,int $userId): array
{
    $reason=mb_substr(trim($reason),0,500,'UTF-8');if($reason==='')throw new InvalidArgumentException('A void reason is required.');
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$itemId,$reason,$userId):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);$q=$pdo->prepare("UPDATE pos_check_items SET status='voided',void_reason=?,voided_by=?,voided_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND id=? AND status='active'");$q->execute([$reason,$userId,$org,(int)$c['id'],$itemId]);if($q->rowCount()!==1)throw new InvalidArgumentException('Open POS item was not found.');pos_recalculate_check($pdo,$org,(int)$c['id']);return pos_check_details($pdo,$org,$publicId);
    });
}

function pos_apply_discount(PDO $pdo,int $org,string $publicId,float $amount,string $reason): array
{
    $amount=pos_money($amount);$reason=mb_substr(trim($reason),0,500,'UTF-8');if($amount>0&&$reason==='')throw new InvalidArgumentException('A discount reason is required.');
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$amount,$reason):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);pos_recalculate_check($pdo,$org,(int)$c['id']);$q=$pdo->prepare('SELECT subtotal FROM pos_checks WHERE organization_id=? AND id=?');$q->execute([$org,(int)$c['id']]);$subtotal=(float)$q->fetchColumn();if($amount>$subtotal)throw new InvalidArgumentException('Discount cannot exceed the current subtotal.');$pdo->prepare('UPDATE pos_checks SET discount_amount=?,discount_reason=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$amount,$amount>0?$reason:null,$org,(int)$c['id']]);pos_recalculate_check($pdo,$org,(int)$c['id']);return pos_check_details($pdo,$org,$publicId);
    });
}

function pos_cancel_check(PDO $pdo,int $org,string $publicId,string $reason,int $userId): array
{
    $reason=mb_substr(trim($reason),0,500,'UTF-8');if($reason==='')throw new InvalidArgumentException('A cancellation reason is required.');
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$reason,$userId):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);$q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");$q->execute([$org,(int)$c['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('A check with captured payment cannot be cancelled; use a refund workflow.');$now=pos_clock($pdo,$org,(int)$c['location_id']);$pdo->prepare("UPDATE pos_checks SET status='cancelled',cancel_reason=?,cancelled_by=?,cancelled_at=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='open'")->execute([$reason,$userId,$now->format('Y-m-d H:i:s.u'),$org,(int)$c['id']]);return pos_check_details($pdo,$org,$publicId);
    });
}

function pos_rebuild_sales_day(PDO $pdo,int $org,int $locationId,string $businessDate): void
{
    $location=pos_location($pdo,$org,$locationId);$provider='gelato_pos';$q=$pdo->prepare("SELECT COUNT(*) tickets,COALESCE(SUM(guest_count),0) covers,COALESCE(SUM(subtotal),0) gross,COALESCE(SUM(GREATEST(0,subtotal-discount_amount)),0) net,COALESCE(SUM(tax_amount),0) tax,COALESCE(SUM(tip_amount),0) tips,COALESCE(SUM(discount_amount),0) discounts,COALESCE(SUM(service_charge_amount),0) service_charges FROM pos_checks WHERE organization_id=? AND location_id=? AND business_date=? AND status='paid'");$q->execute([$org,$locationId,$businessDate]);$m=$q->fetch()?:[];$tickets=(int)($m['tickets']??0);
    $q=$pdo->prepare("SELECT COALESCE(SUM(i.gross_amount),0) FROM pos_check_items i JOIN pos_checks c ON c.id=i.check_id AND c.organization_id=i.organization_id WHERE i.organization_id=? AND c.location_id=? AND c.business_date=? AND c.status='paid' AND i.status='voided'");$q->execute([$org,$locationId,$businessDate]);$voids=pos_money((float)$q->fetchColumn());
    $find=$pdo->prepare("SELECT id FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider=? AND granularity='daily' AND service_period='all' AND period_start=? AND period_end=? LIMIT 1");$find->execute([$org,$location['key'],$provider,$businessDate,$businessDate]);$periodId=(int)($find->fetchColumn()?:0);
    if($tickets<1){if($periodId)$pdo->prepare('DELETE FROM sales_periods WHERE organization_id=? AND id=?')->execute([$org,$periodId]);$pdo->prepare("DELETE FROM sales_hourly WHERE organization_id=? AND location_key=? AND source_provider=? AND business_date=?")->execute([$org,$location['key'],$provider,$businessDate]);sales_rebuild_all_location_rollups($pdo,$org,$provider);return;}
    $pdo->prepare("INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,import_batch_id,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,comps_amount,voids_amount,refunds_amount,service_charges_amount,source_metadata_json) VALUES (?,?,?,? ,NULL,'daily','all',?,?,?,?,?,?,?,?,?,0,?,0,?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),import_batch_id=NULL,tickets=VALUES(tickets),covers=VALUES(covers),gross_sales=VALUES(gross_sales),net_sales=VALUES(net_sales),tax_amount=VALUES(tax_amount),tips_amount=VALUES(tips_amount),discounts_amount=VALUES(discounts_amount),voids_amount=VALUES(voids_amount),service_charges_amount=VALUES(service_charges_amount),source_metadata_json=VALUES(source_metadata_json),updated_at=NOW(6)")->execute([$org,$locationId,$location['key'],$provider,$businessDate,$businessDate,$tickets,(int)($m['covers']??0),pos_money((float)($m['gross']??0)),pos_money((float)($m['net']??0)),pos_money((float)($m['tax']??0)),pos_money((float)($m['tips']??0)),pos_money((float)($m['discounts']??0)),$voids,pos_money((float)($m['service_charges']??0)),json_encode(['locationName'=>$location['name'],'source'=>'Gelato Native POS'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $find->execute([$org,$location['key'],$provider,$businessDate,$businessDate]);$periodId=(int)$find->fetchColumn();$pdo->prepare('DELETE FROM sales_item_periods WHERE organization_id=? AND sales_period_id=?')->execute([$org,$periodId]);
    $q=$pdo->prepare("SELECT i.menu_item_id,MAX(i.item_name_snapshot) item_name,MAX(i.category_name_snapshot) category_name,SUM(i.quantity) quantity,SUM(i.gross_amount) gross,SUM(i.gross_amount*CASE WHEN c.subtotal>0 THEN GREATEST(0,1-(c.discount_amount/c.subtotal)) ELSE 1 END) net FROM pos_check_items i JOIN pos_checks c ON c.id=i.check_id AND c.organization_id=i.organization_id WHERE i.organization_id=? AND c.location_id=? AND c.business_date=? AND c.status='paid' AND i.status='active' GROUP BY i.menu_item_id");$q->execute([$org,$locationId,$businessDate]);$ins=$pdo->prepare('INSERT INTO sales_item_periods (organization_id,sales_period_id,menu_item_id,source_item_key,source_item_id,item_name,category_name,quantity,gross_sales,net_sales,discounts_amount,refunds_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)');foreach($q->fetchAll() as $r){$gross=pos_money((float)$r['gross']);$net=pos_money((float)$r['net']);$ins->execute([$org,$periodId,(int)$r['menu_item_id'],'menu:'.(int)$r['menu_item_id'],(string)$r['menu_item_id'],(string)$r['item_name'],$r['category_name'],(float)$r['quantity'],$gross,$net,pos_money(max(0,$gross-$net))]);}
    $pdo->prepare("DELETE FROM sales_hourly WHERE organization_id=? AND location_key=? AND source_provider=? AND business_date=?")->execute([$org,$location['key'],$provider,$businessDate]);$q=$pdo->prepare("SELECT closed_hour,COUNT(*) tickets,COALESCE(SUM(guest_count),0) covers,COALESCE(SUM(subtotal),0) gross,COALESCE(SUM(GREATEST(0,subtotal-discount_amount)),0) net FROM pos_checks WHERE organization_id=? AND location_id=? AND business_date=? AND status='paid' AND closed_hour IS NOT NULL GROUP BY closed_hour ORDER BY closed_hour");$q->execute([$org,$locationId,$businessDate]);$ins=$pdo->prepare("INSERT INTO sales_hourly (organization_id,location_id,location_key,source_provider,import_batch_id,business_date,hour_start,tickets,covers,gross_sales,net_sales) VALUES (?,?,?,?,NULL,?,?,?,?,?,?)");foreach($q->fetchAll() as $r)$ins->execute([$org,$locationId,$location['key'],$provider,$businessDate,(int)$r['closed_hour'],(int)$r['tickets'],(int)$r['covers'],pos_money((float)$r['gross']),pos_money((float)$r['net'])]);
    sales_rebuild_all_location_rollups($pdo,$org,$provider);$pdo->prepare("UPDATE sales_integrations SET status='active',last_synced_at=NOW(6),last_sync_status='success',last_sync_message='Native POS sales posted',updated_at=NOW(6) WHERE organization_id=? AND provider='gelato_pos' AND location_id IS NULL")->execute([$org]);
}

function pos_record_tender(PDO $pdo,int $org,string $publicId,array $input,int $userId): array
{
    $type=(string)($input['tenderType']??'');if(!in_array($type,['cash','external_card'],true))throw new InvalidArgumentException('Tender must be cash or external terminal card.');$tip=pos_money((float)($input['tipAmount']??0));if($tip>10000)throw new InvalidArgumentException('Tip amount is outside the supported range.');$reference=mb_substr(trim((string)($input['externalReference']??'')),0,255,'UTF-8')?:null;
    return pos_transaction($pdo,function()use($pdo,$org,$publicId,$input,$userId,$type,$tip,$reference):array{
        $c=pos_require_open_check($pdo,$org,$publicId,true);pos_recalculate_check($pdo,$org,(int)$c['id']);$q=$pdo->prepare("SELECT subtotal,discount_amount,tax_amount,service_charge_amount FROM pos_checks WHERE organization_id=? AND id=?");$q->execute([$org,(int)$c['id']]);$tot=$q->fetch()?:[];$due=pos_money((float)$tot['subtotal']-(float)$tot['discount_amount']+(float)$tot['tax_amount']+(float)$tot['service_charge_amount']);$q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");$q->execute([$org,(int)$c['id']]);$paid=pos_money((float)$q->fetchColumn());$balance=pos_money(max(0,$due-$paid));if($balance<=0)throw new InvalidArgumentException('This check has no remaining payment balance.');$requested=isset($input['amount'])?pos_money((float)$input['amount']):$balance;if($requested<=0)throw new InvalidArgumentException('Tender amount must be greater than zero.');$amount=min($requested,$balance);$received=null;$change=0.0;if($type==='cash'){$received=isset($input['receivedAmount'])?pos_money((float)$input['receivedAmount']):pos_money($amount+$tip);if($received+0.001<$amount+$tip)throw new InvalidArgumentException('Cash received is less than the tender plus tip.');$change=pos_money(max(0,$received-$amount-$tip));}
        $tenderPublic=sales_public_id('pos-tender');$pdo->prepare("INSERT INTO pos_tenders (organization_id,check_id,public_id,tender_type,amount,tip_amount,received_amount,change_amount,external_reference,status,processed_by) VALUES (?,?,?,?,?,?,?,?,?,'captured',?)")->execute([$org,(int)$c['id'],$tenderPublic,$type,$amount,$tip,$received,$change,$type==='external_card'?$reference:null,$userId]);pos_recalculate_check($pdo,$org,(int)$c['id']);$q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");$q->execute([$org,(int)$c['id']]);$paid=pos_money((float)$q->fetchColumn());if($paid+0.009>=$due){$now=pos_clock($pdo,$org,(int)$c['location_id']);$pdo->prepare("UPDATE pos_checks SET status='paid',closed_by=?,closed_at=?,closed_hour=?,sales_posted_at=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='open'")->execute([$userId,$now->format('Y-m-d H:i:s.u'),(int)$now->format('G'),$now->format('Y-m-d H:i:s.u'),$org,(int)$c['id']]);pos_rebuild_sales_day($pdo,$org,(int)$c['location_id'],(string)$c['business_date']);}return pos_check_details($pdo,$org,$publicId);
    });
}
