<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/location-core.php';
require_once __DIR__.'/../includes/package-deals-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-core.php';

$pdo=app_pdo();
function pkg_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function pkg_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

pkg_assert(package_deals_ready($pdo),'Package Deals must be installed.');
pkg_assert(discount_table_ready($pdo),'Typed discounts must be installed.');
$types=discount_types();
foreach(['package_deal','coupon','make_good','admin_discount','loyalty_reward','catering_wholesale_adjustment','other'] as $type)pkg_assert(isset($types[$type]),'Discount type '.$type.' must be available.');
pkg_assert(abs(discount_calculate(100,'percent',10)-10)<0.001,'Percent discount math must be correct.');
pkg_assert(abs(discount_calculate(100,'fixed',15)-15)<0.001,'Fixed discount math must be correct.');

$slug='package-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Package CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,[
    'name'=>'Package Pickup','public_slug'=>'package-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix',
    'dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>true,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20,
]);
$locationId=(int)$location['id'];

function pkg_menu_item(PDO $pdo,int $org,string $sectionName,string $sectionSlug,string $itemName,string $itemSlug,float $amount): array
{
    $q=$pdo->prepare('SELECT id FROM menu_sections WHERE organization_id=? AND slug=? LIMIT 1');$q->execute([$org,$sectionSlug]);$sectionId=(int)($q->fetchColumn()?:0);
    if($sectionId<1){$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,?,?,'active',1)")->execute([$org,$sectionName,$sectionSlug]);$sectionId=(int)$pdo->lastInsertId();}
    $pdo->prepare('INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,?,?,1)')->execute([$org,$sectionId,$itemName,$itemSlug]);$itemId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',?,'USD',1)")->execute([$itemId,$amount]);
    return ['itemId'=>$itemId,'priceId'=>(int)$pdo->lastInsertId()];
}
$pizzaA=pkg_menu_item($pdo,$org,'Pizza','pizza-'.$slug,'Package Margherita','margherita-'.$slug,20.00);
$pizzaB=pkg_menu_item($pdo,$org,'Pizza','pizza-'.$slug,'Package Pepperoni','pepperoni-'.$slug,22.00);
$soda=pkg_menu_item($pdo,$org,'Drinks','drinks-'.$slug,'Package Soda','soda-'.$slug,4.00);
$gelato=pkg_menu_item($pdo,$org,'Dessert','dessert-'.$slug,'Package Gelato','gelato-'.$slug,8.00);

$email=$slug.'@example.test';$password='Package-CI!42';
$registration=customer_account_register($pdo,$org,['firstName'=>'Package','lastName'=>'Customer','email'=>$email,'phone'=>'6025550199','password'=>$password,'passwordConfirm'=>$password]);
$userId=(int)$registration['userId'];
$customerId=(int)pkg_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
pkg_assert($customerId>0,'Package customer must be linked to CRM.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);

$station=kds_station_save($pdo,$org,$locationId,['name'=>'Package Line','slug'=>'package-line-'.$slug,'targetSeconds'=>300,'sortOrder'=>1],$userId);
foreach([$pizzaA,$pizzaB,$soda,$gelato] as $menu)kds_route_save($pdo,$org,$locationId,(int)$menu['itemId'],(string)$station['public_id'],$userId);

$package=package_deal_save($pdo,$org,null,[
    'name'=>'Family Dinner','eyebrow'=>'Good pizza brings people together','description'=>'Two pizzas, drinks and gelato for pickup.','discountMethod'=>'percent','discountValue'=>10,'featured'=>true,
    'groups'=>[
        ['label'=>'Pizza','requiredQuantity'=>2,'priceIds'=>[$pizzaA['priceId'],$pizzaB['priceId']]],
        ['label'=>'Drinks','requiredQuantity'=>2,'priceIds'=>[$soda['priceId']]],
        ['label'=>'Dessert','requiredQuantity'=>1,'priceIds'=>[$gelato['priceId']]],
    ],
],$userId);
pkg_assert($package['status']==='draft','New packages must start as draft.');
pkg_assert(count($package['groups'])===3,'Package must retain all three selection categories.');
pkg_assert(package_deal_public_list($pdo,$org)===[],'Draft packages must not appear publicly.');
$search=package_deal_menu_search($pdo,$org,'Package');
pkg_assert(count($search)>=4,'Package menu search must return live menu choices.');

$package=package_deal_set_status($pdo,$org,$package['id'],'activate',$userId);
pkg_assert($package['status']==='active','Draft package must activate.');
pkg_assert(count(package_deal_public_list($pdo,$org))===1,'Active package must appear publicly.');

$copy=package_deal_duplicate($pdo,$org,$package['id'],$userId);
pkg_assert($copy['status']==='draft','Duplicate Package must create a draft.');
pkg_assert($copy['id']!==$package['id'],'Duplicate Package must create a new package identity.');
pkg_assert(count($copy['groups'])===3,'Duplicate Package must copy all categories.');
pkg_assert((int)$copy['stats']['redemptions']===0,'Duplicate Package must not copy sales history.');

customer_account_start_session($pdo,$org,$userId);$account=customer_account_current($pdo,$org);
pkg_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Customer must have online ordering permission.');
$package=package_deal_payload($pdo,$org,package_deal_row($pdo,$org,$package['id']));
$selections=[];
foreach($package['groups'] as $group){
    if($group['label']==='Pizza')$selections[$group['id']]=[$pizzaA['priceId'],$pizzaB['priceId']];
    elseif($group['label']==='Drinks')$selections[$group['id']]=[$soda['priceId'],$soda['priceId']];
    else $selections[$group['id']]=[$gelato['priceId']];
}
$idempotency='package_'.bin2hex(random_bytes(18));
$order=package_deal_submit_pickup($pdo,$org,$account,['package'=>$package['slug'],'locationId'=>$locationId,'idempotencyKey'=>$idempotency,'selections'=>$selections,'note'=>'Package CI pickup']);
pkg_assert(empty($order['duplicate']),'First package checkout must create the order.');
$checkPublic=(string)$order['check_public_id'];
$check=pos_check_details($pdo,$org,$checkPublic);
pkg_assert($check['serviceMode']==='pickup','Package deals must always use pickup service mode.');
pkg_assert(abs((float)$check['subtotal']-58.00)<0.001,'Package retail subtotal must use current menu prices.');
pkg_assert(abs((float)$check['discountAmount']-5.80)<0.001,'Package Deal must apply the configured 10% discount.');
pkg_assert(abs((float)$check['saleDue']-52.20)<0.001,'Package sale due must reflect package savings.');
$qty=(float)pkg_one($pdo,"SELECT COALESCE(SUM(quantity),0) FROM pos_check_items WHERE organization_id=? AND check_id=? AND status='active'",[$org,(int)$check['id']]);
pkg_assert(abs($qty-5.0)<0.001,'Package must expand into five real menu units in the canonical POS check.');
$lineCount=(int)pkg_one($pdo,"SELECT COUNT(*) FROM pos_check_items WHERE organization_id=? AND check_id=? AND status='active'",[$org,(int)$check['id']]);
pkg_assert($lineCount===4,'Repeated identical drink selections may aggregate, while all real menu lines remain intact.');
$instructions=(string)pkg_one($pdo,"SELECT GROUP_CONCAT(COALESCE(special_instructions,'') SEPARATOR ' | ') FROM pos_check_items WHERE organization_id=? AND check_id=?",[$org,(int)$check['id']]);
pkg_assert(str_contains($instructions,'PACKAGE: Family Dinner'),'POS/KDS instructions must retain package identity.');
$kdsCount=(int)pkg_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=?',[$org,(int)$check['id']]);
pkg_assert($kdsCount===4,'Package POS lines must flow through the native KDS.');

$discountRow=$pdo->prepare("SELECT * FROM pos_discounts WHERE organization_id=? AND pos_check_id=? AND status='active'");$discountRow->execute([$org,(int)$check['id']]);$discounts=$discountRow->fetchAll();
pkg_assert(count($discounts)===1,'Package checkout must create exactly one typed discount record.');
pkg_assert($discounts[0]['discount_type']==='package_deal','Package checkout must be classified as Package Deal.');
pkg_assert($discounts[0]['method']==='percent','Package discount method must be tracked.');
pkg_assert(abs((float)$discounts[0]['amount']-5.80)<0.001,'Tracked package discount amount must be exact.');
$redemption=$pdo->prepare('SELECT * FROM package_redemptions WHERE organization_id=? AND pos_check_id=?');$redemption->execute([$org,(int)$check['id']]);$redeem=$redemption->fetch();
pkg_assert((bool)$redeem,'Package checkout must write a redemption record.');
pkg_assert(abs((float)$redeem['retail_amount']-58.00)<0.001&&abs((float)$redeem['discount_amount']-5.80)<0.001&&abs((float)$redeem['package_amount']-52.20)<0.001,'Redemption must track retail, savings and package revenue.');

$again=package_deal_submit_pickup($pdo,$org,$account,['package'=>$package['slug'],'locationId'=>$locationId,'idempotencyKey'=>$idempotency,'selections'=>$selections,'note'=>'Package CI pickup']);
pkg_assert(!empty($again['duplicate']),'Repeated package checkout token must be idempotent.');
pkg_assert((int)pkg_one($pdo,'SELECT COUNT(*) FROM package_redemptions WHERE organization_id=? AND pos_check_id=?',[$org,(int)$check['id']])===1,'Idempotent retry must not duplicate package redemption.');
pkg_assert((int)pkg_one($pdo,'SELECT COUNT(*) FROM pos_discounts WHERE organization_id=? AND pos_check_id=?',[$org,(int)$check['id']])===1,'Idempotent retry must not duplicate package discount.');

$packageAfter=package_deal_payload($pdo,$org,package_deal_row($pdo,$org,$package['id']));
pkg_assert((int)$packageAfter['stats']['redemptions']===1,'Package stats must count the order.');
pkg_assert(abs((float)$packageAfter['stats']['discounts']-5.80)<0.001,'Package stats must track savings.');
pkg_assert(abs((float)$packageAfter['stats']['revenue']-52.20)<0.001,'Package stats must track realized package revenue.');

$managerCheck=pos_create_check($pdo,$org,$locationId,['serviceMode'=>'pickup','tableName'=>'Discount CI','guestCount'=>1],$userId);
$managerCheck=pos_add_item($pdo,$org,(string)$managerCheck['publicId'],$pizzaA['priceId'],1,'',$userId);
$makeGood=discount_apply($pdo,$org,(string)$managerCheck['publicId'],'make_good','fixed',2.00,'Late order recovery',$userId,'ci','make-good');
pkg_assert(abs((float)$makeGood['check']['discountAmount']-2.00)<0.001,'Make Good discount must apply to the POS check.');
$admin=discount_apply($pdo,$org,(string)$managerCheck['publicId'],'admin_discount','percent',10,'Manager courtesy',$userId,'ci','admin');
pkg_assert(abs((float)$admin['check']['discountAmount']-4.00)<0.001,'Typed discounts must aggregate on the POS check.');
pkg_assert(count(discount_check_discounts($pdo,$org,(int)$managerCheck['id']))===2,'POS must retain separate typed discount records.');
$voided=discount_void($pdo,$org,(string)$makeGood['discount']['public_id'],$userId);
pkg_assert(abs((float)$voided['check']['discountAmount']-2.00)<0.001,'Voiding one discount must preserve the remaining typed discount.');

$paused=package_deal_set_status($pdo,$org,$package['id'],'pause',$userId);pkg_assert($paused['status']==='paused','Pause Deal must persist paused status.');
pkg_assert(package_deal_public_list($pdo,$org)===[],'Paused deal must disappear from public ordering.');
$blocked=false;try{package_deal_submit_pickup($pdo,$org,$account,['package'=>$package['slug'],'locationId'=>$locationId,'idempotencyKey'=>'blocked_'.bin2hex(random_bytes(18)),'selections'=>$selections]);}catch(InvalidArgumentException){$blocked=true;}
pkg_assert($blocked,'Paused package must reject checkout server-side.');
$resumed=package_deal_set_status($pdo,$org,$package['id'],'resume',$userId);pkg_assert($resumed['status']==='active','Resume Deal must return a paused package to active.');
pkg_assert(count(package_deal_public_list($pdo,$org))===1,'Resumed package must return to public ordering.');
$ended=package_deal_set_status($pdo,$org,$package['id'],'end',$userId);pkg_assert($ended['status']==='ended','End Deal must persist ended status.');
pkg_assert(package_deal_public_list($pdo,$org)===[],'Ended deal must remain out of public ordering.');
$archived=package_deal_set_status($pdo,$org,$package['id'],'archive',$userId);pkg_assert($archived['status']==='archived','Archive Deal must persist archive status.');
$normalList=package_deal_list($pdo,$org,false);pkg_assert(!in_array($package['id'],array_column($normalList,'id'),true),'Archived deal must disappear from the normal admin list.');
$archiveList=package_deal_list($pdo,$org,true);pkg_assert(in_array($package['id'],array_column($archiveList,'id'),true),'Archived deal must remain available in archived history.');

$page=(string)file_get_contents(__DIR__.'/../packages.php');
$adminPage=(string)file_get_contents(__DIR__.'/../packages-admin.php');
$adminJs=(string)file_get_contents(__DIR__.'/../assets/js/packages-admin.js');
pkg_assert(str_contains($page,'Takeout Only · No Delivery'),'Public package page must state pickup-only fulfillment.');
pkg_assert(str_contains($adminPage,'+ Add New Package'),'Package admin must expose Add New Package.');
pkg_assert(str_contains($adminJs,'Duplicate Package'),'Package admin must expose Duplicate Package.');
pkg_assert(str_contains($adminJs,'Pause Deal')&&str_contains($adminJs,'End Deal')&&str_contains($adminJs,'Archive Deal'),'Package lifecycle controls must remain in the admin UI.');

echo "package-deals=ok\n";
