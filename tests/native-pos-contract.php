<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/pos-core.php';

$pdo=app_pdo();
function posci_assert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function posci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

posci_assert(pos_ready($pdo),'Native POS must be installed.');
$slug='pos-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'POS','Manager','POS Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$section,'margherita-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'12 inch','12',20.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$settings=pos_settings_save($pdo,$org,$location,['taxRate'=>0.085,'serviceChargeRate'=>0.02,'defaultServiceMode'=>'dine_in','makePrimary'=>true],$user);
posci_assert(abs((float)$settings['taxRate']-.085)<.000001,'Tax rate was not saved.');
posci_assert(pos_primary_location_id($pdo,$org,$membership)===$location,'Primary POS location lookup failed.');
posci_assert((string)posci_one($pdo,"SELECT provider FROM sales_integrations WHERE organization_id=? AND is_primary=1",[$org])==='gelato_pos','Native POS should be selectable as the primary Sales Intelligence source.');
$menu=pos_menu($pdo,$org);posci_assert(count($menu)===1&&count($menu[0]['items'])===1&&(int)$menu[0]['items'][0]['prices'][0]['id']===$price,'POS menu must come from canonical menu tables.');

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 8','guestCount'=>2],$user);$public=(string)$check['publicId'];
$check=pos_add_item($pdo,$org,$public,$price,1,'No basil',$user);$firstId=(int)$check['items'][0]['id'];
$check=pos_add_item($pdo,$org,$public,$price,1,'',$user);$secondId=(int)$check['items'][1]['id'];
posci_assert(abs((float)$check['subtotal']-40.0)<.01,'POS must use the canonical $20 menu price twice.');
$check=pos_update_item($pdo,$org,$public,$firstId,1,'Extra crisp');posci_assert((string)$check['items'][0]['special_instructions']==='Extra crisp','Item instructions did not update.');
$check=pos_void_item($pdo,$org,$public,$secondId,'Duplicate entry',$user);posci_assert(abs((float)$check['subtotal']-20.0)<.01,'Voided line must leave the active subtotal.');
$check=pos_apply_discount($pdo,$org,$public,5.00,'Manager recovery');
posci_assert(abs((float)$check['discountAmount']-5.0)<.01,'Check discount was not applied.');
posci_assert(abs((float)$check['taxAmount']-1.28)<.01,'Tax must be recalculated server-side from the discounted taxable base.');
posci_assert(abs((float)$check['serviceChargeAmount']-.30)<.01,'Service charge must be recalculated server-side from the discounted base.');
posci_assert(abs((float)$check['balanceDue']-16.58)<.01,'Pre-tip payment balance is incorrect.');

$check=pos_record_tender($pdo,$org,$public,['tenderType'=>'external_card','amount'=>$check['balanceDue'],'tipAmount'=>3.00,'externalReference'=>'txn_ci_terminal_123'],$user);
posci_assert($check['status']==='paid','A fully tendered check must close as paid.');
posci_assert(abs((float)$check['totalAmount']-19.58)<.01,'Paid total including tip is incorrect.');
posci_assert(abs((float)$check['amountPaid']-19.58)<.01,'Recorded paid amount including tip is incorrect.');
posci_assert((string)$check['tenders'][0]['external_reference']==='txn_ci_terminal_123','External terminal transaction reference was not retained.');
$businessDate=(string)$check['businessDate'];

$q=$pdo->prepare("SELECT tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,voids_amount,service_charges_amount FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=?");$q->execute([$org,'id:'.$location,$businessDate]);$sales=$q->fetch();
posci_assert((int)$sales['tickets']===1&&(int)$sales['covers']===2,'Paid POS check must post one ticket and two covers.');
posci_assert(abs((float)$sales['gross_sales']-20.0)<.01&&abs((float)$sales['net_sales']-15.0)<.01,'POS gross/net sales rollup is incorrect.');
posci_assert(abs((float)$sales['tax_amount']-1.28)<.01&&abs((float)$sales['tips_amount']-3.0)<.01,'POS tax/tip sales rollup is incorrect.');
posci_assert(abs((float)$sales['discounts_amount']-5.0)<.01&&abs((float)$sales['voids_amount']-20.0)<.01&&abs((float)$sales['service_charges_amount']-.30)<.01,'POS discount/void/service-charge rollup is incorrect.');
$q=$pdo->prepare("SELECT quantity,gross_sales,net_sales,discounts_amount FROM sales_item_periods WHERE organization_id=? AND sales_period_id=(SELECT id FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND period_start=? AND service_period='all' LIMIT 1) AND menu_item_id=?");$q->execute([$org,$org,'id:'.$location,$businessDate,$item]);$mix=$q->fetch();posci_assert(abs((float)$mix['quantity']-1.0)<.001&&abs((float)$mix['gross_sales']-20.0)<.01&&abs((float)$mix['net_sales']-15.0)<.01,'POS item mix must preserve menu linkage and proportional check discount.');
posci_assert((int)posci_one($pdo,"SELECT COUNT(*) FROM sales_hourly WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND business_date=?",[$org,'id:'.$location,$businessDate])===1,'Paid POS check must post an hourly sales bucket.');
posci_assert((int)posci_one($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key='all' AND source_provider='gelato_pos' AND period_start=?",[$org,$businessDate])===1,'Native POS must rebuild the All locations Sales Intelligence rollup.');
posci_assert((string)posci_one($pdo,"SELECT last_sync_status FROM sales_integrations WHERE organization_id=? AND provider='gelato_pos'",[$org])==='success','Native POS integration health must record successful posting.');

$doubleBlocked=false;try{pos_record_tender($pdo,$org,$public,['tenderType'=>'cash','amount'=>1],$user);}catch(InvalidArgumentException){$doubleBlocked=true;}posci_assert($doubleBlocked,'A paid check must reject a second close/payment attempt.');
posci_assert(abs((float)posci_one($pdo,"SELECT net_sales FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND period_start=?",[$org,'id:'.$location,$businessDate])-15.0)<.01,'Rejected duplicate payment must not double-post sales.');

$cancel=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','tableName'=>'Pickup CI','guestCount'=>1],$user);$cancel=pos_add_item($pdo,$org,(string)$cancel['publicId'],$price,1,'',$user);$cancel=pos_cancel_check($pdo,$org,(string)$cancel['publicId'],'Guest changed mind',$user);posci_assert($cancel['status']==='cancelled','Open unpaid check must support audited cancellation.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{pos_check_details($pdo,$otherOrg,$public);}catch(InvalidArgumentException){$isolated=true;}posci_assert($isolated,'POS checks must be organization-isolated.');
$forbiddenColumns=(int)posci_one($pdo,"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_tenders' AND column_name IN ('card_number','pan','cvv','track_data','magstripe')");posci_assert($forbiddenColumns===0,'Native POS must not create raw payment-card storage fields.');

echo "native-pos contract passed\n";
