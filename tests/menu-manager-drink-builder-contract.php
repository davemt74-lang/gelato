<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/location-core.php';
require_once __DIR__.'/../includes/menu-drink-core.php';
require_once __DIR__.'/../includes/menu-order-integration.php';
require_once __DIR__.'/../includes/kds-core.php';

$pdo=app_pdo();
function drink_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function drink_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

$slug='drink-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Drink Builder CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,['name'=>'Drink Builder Store','public_slug'=>'drink-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>10]);
$locationId=(int)$location['id'];
$email=$slug.'@example.test';$password='DrinkBuilder-CI!42';
$registration=customer_account_register($pdo,$org,['firstName'=>'Drink','lastName'=>'Builder','email'=>$email,'phone'=>'6025550199','password'=>$password,'passwordConfirm'=>$password]);
$userId=(int)$registration['userId'];
$customerId=(int)drink_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
drink_assert($customerId>0,'Drink builder customer must exist in CRM.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);

$category=menu_manager_category_save($pdo,$org,null,['name'=>'Drinks','description'=>'Cold beverages','sortOrder'=>20,'status'=>'active']);
$drink=menu_drink_save($pdo,$org,null,[
    'drinkKind'=>'fountain','name'=>'Fountain Drink','categoryId'=>$category['id'],'description'=>'Choose your fountain flavor.','kitchenStation'=>'Beverage',
    'sizes'=>[
        ['clientKey'=>'20oz','label'=>'20 oz','sizeCode'=>'20oz','amount'=>2.00,'sortOrder'=>1],
        ['clientKey'=>'32oz','label'=>'32 oz','sizeCode'=>'32oz','amount'=>3.00,'sortOrder'=>2],
    ],
    'modifierGroups'=>[
        ['name'=>'Flavor','type'=>'choice','minSelect'=>1,'maxSelect'=>1,'sortOrder'=>1,'options'=>[
            ['name'=>'Coke','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>1],
            ['name'=>'Diet Coke','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>2],
            ['name'=>'Sprite','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>3],
        ]],
        ['name'=>'Ice','type'=>'choice','minSelect'=>1,'maxSelect'=>1,'sortOrder'=>2,'options'=>[
            ['name'=>'Regular Ice','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>1],
            ['name'=>'Light Ice','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>2],
            ['name'=>'No Ice','defaultPriceDelta'=>0,'maxQuantity'=>1,'sortOrder'=>3],
        ]],
        ['name'=>'Drink Add-ons','type'=>'add_on','minSelect'=>0,'maxSelect'=>3,'sortOrder'=>3,'options'=>[
            ['name'=>'Flavor Shot','defaultPriceDelta'=>0.50,'maxQuantity'=>2,'sortOrder'=>1,'priceDeltas'=>['20oz'=>0.50,'32oz'=>0.75]],
        ]],
    ],
    'distribution'=>['publicMenu'=>true,'onlineOrder'=>true,'pos'=>true,'packages'=>true,'catering'=>false],
    'locationAvailability'=>[(string)$locationId=>true],
],$userId);

drink_assert((string)$drink['profile']['itemType']==='drink','Drink save must classify the canonical menu item as drink.');
drink_assert((string)($drink['metadata']['drinkKind']??'')==='fountain','Drink kind must persist in canonical menu metadata.');
drink_assert((string)$drink['profile']['lifecycleStatus']==='draft'&&!$drink['active'],'New Drinks must begin as non-selling drafts.');
drink_assert(count($drink['sizes'])===2,'Drink must retain both size/price variants.');
drink_assert(count($drink['modifierGroups'])===3,'Drink must retain Flavor, Ice and paid add-on groups.');

$drink=menu_drink_set_status($pdo,$org,(int)$drink['id'],'publish',$userId);
drink_assert((string)$drink['profile']['lifecycleStatus']==='published'&&$drink['active'],'Publish must activate the Drink.');
foreach(['public_menu','online_order','pos','packages'] as $channel)drink_assert(menu_manager_item_channel_enabled($pdo,$org,(int)$drink['id'],$channel,$locationId),'Published Drink must distribute to '.$channel.'.');

$price20=0;$price32=0;foreach($drink['sizes'] as $size){if($size['label']==='20 oz')$price20=(int)$size['id'];if($size['label']==='32 oz')$price32=(int)$size['id'];}
drink_assert($price20>0&&$price32>0,'Drink size price IDs must exist.');
$catalog20=menu_manager_customization_addons($pdo,$org,$price20,'online_order',$locationId);
$byGroup=[];foreach($catalog20 as $group)$byGroup[$group['name']]=$group;
drink_assert(($byGroup['Flavor']['type']??'')==='choice'&&($byGroup['Flavor']['minSelect']??0)===1&&($byGroup['Flavor']['maxSelect']??0)===1,'Flavor must be a required single-choice group.');
drink_assert(($byGroup['Ice']['type']??'')==='choice','Ice must be a Drink choice group.');
drink_assert(($byGroup['Drink Add-ons']['type']??'')==='add_on','Paid Drink extras must remain add-ons.');

$opts=[];foreach($catalog20 as $group)foreach($group['options'] as $option)$opts[$option['name']]=$option;
drink_assert(isset($opts['Coke'],$opts['Diet Coke'],$opts['Regular Ice'],$opts['Flavor Shot']),'Required Drink choices must be exposed to ordering.');
drink_assert(abs((float)$opts['Flavor Shot']['priceDelta']-0.50)<0.001,'20 oz Flavor Shot must cost $0.50.');
$catalog32=menu_manager_customization_addons($pdo,$org,$price32,'online_order',$locationId);$shot32=null;foreach($catalog32 as $group)foreach($group['options'] as $option)if($option['name']==='Flavor Shot')$shot32=$option;
drink_assert(is_array($shot32)&&abs((float)$shot32['priceDelta']-0.75)<0.001,'32 oz Flavor Shot must use its size-specific $0.75 price.');

$missingRejected=false;try{menu_manager_validate_addons($pdo,$org,$price20,[],'online_order',$locationId);}catch(InvalidArgumentException $e){$missingRejected=str_contains($e->getMessage(),'Flavor requires');}
drink_assert($missingRejected,'Server must reject a Drink that omits its required Flavor.');
$tooManyRejected=false;try{menu_manager_validate_addons($pdo,$org,$price20,[['optionId'=>$opts['Coke']['id'],'quantity'=>1],['optionId'=>$opts['Diet Coke']['id'],'quantity'=>1],['optionId'=>$opts['Regular Ice']['id'],'quantity'=>1]],'online_order',$locationId);}catch(InvalidArgumentException $e){$tooManyRejected=str_contains($e->getMessage(),'Flavor has too many');}
drink_assert($tooManyRejected,'Server must reject multiple flavors when Flavor max is one.');
$selected=menu_manager_validate_addons($pdo,$org,$price20,[['optionId'=>$opts['Coke']['id'],'quantity'=>1],['optionId'=>$opts['Regular Ice']['id'],'quantity'=>1],['optionId'=>$opts['Flavor Shot']['id'],'quantity'=>1]],'online_order',$locationId);
drink_assert(count($selected)===3,'Valid Drink order must retain Flavor, Ice and paid add-on selections.');

$station=kds_station_save($pdo,$org,$locationId,['name'=>'Beverage','slug'=>'beverage-'.$slug,'targetSeconds'=>120,'sortOrder'=>1],$userId);
kds_route_save($pdo,$org,$locationId,(int)$drink['id'],(string)$station['public_id'],$userId);
customer_account_start_session($pdo,$org,$userId);$account=customer_account_current($pdo,$org);
drink_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Drink test customer must be able to order online.');
$order=menu_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,'idempotencyKey'=>'drink_'.bin2hex(random_bytes(18)),'note'=>'Drink builder test',
    'items'=>[['priceId'=>$price20,'quantity'=>1,'instructions'=>'','customizations'=>['removals'=>[],'substitutions'=>[],'note'=>'','addOns'=>[
        ['optionId'=>(int)$opts['Coke']['id'],'quantity'=>1],['optionId'=>(int)$opts['Regular Ice']['id'],'quantity'=>1],['optionId'=>(int)$opts['Flavor Shot']['id'],'quantity'=>1],
    ]]]],
]);
$check=pos_check_details($pdo,$org,(string)$order['check_public_id']);
drink_assert(abs((float)$check['subtotal']-2.50)<0.001,'20 oz $2 Drink plus $0.50 Flavor Shot must total $2.50 before tax.');
$q=$pdo->prepare("SELECT id,modifier_amount,gross_amount,special_instructions FROM pos_check_items WHERE organization_id=? AND check_id=? AND status='active' LIMIT 1");$q->execute([$org,(int)$check['id']]);$line=$q->fetch();
drink_assert(abs((float)$line['modifier_amount']-0.50)<0.001&&abs((float)$line['gross_amount']-2.50)<0.001,'POS must retain the paid Drink add-on separately from the base price.');
drink_assert(str_contains((string)$line['special_instructions'],'Coke')&&str_contains((string)$line['special_instructions'],'Regular Ice')&&str_contains((string)$line['special_instructions'],'Flavor Shot'),'POS/KDS instructions must preserve Drink choices and add-ons.');
drink_assert((int)drink_one($pdo,'SELECT COUNT(*) FROM pos_check_item_modifiers WHERE organization_id=? AND pos_check_item_id=?',[$org,(int)$line['id']])===3,'Flavor, Ice and paid add-on must each have an auditable POS modifier row.');
drink_assert((int)drink_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=?',[$org,(int)$check['id']])===1,'Customized Drink must flow to KDS as one canonical menu line.');

$copy=menu_drink_duplicate($pdo,$org,(int)$drink['id'],$userId);
drink_assert((int)$copy['id']!==(int)$drink['id']&&(string)$copy['profile']['itemType']==='drink','Duplicate Drink must create another Drink item.');
drink_assert((string)($copy['metadata']['drinkKind']??'')==='fountain'&&(string)$copy['profile']['lifecycleStatus']==='draft','Duplicate Drink must preserve Drink kind but not sales lifecycle.');

$page=(string)file_get_contents(__DIR__.'/../menu-manager.php');$js=(string)file_get_contents(__DIR__.'/../assets/js/menu-manager-drink.js');$actions=(string)file_get_contents(__DIR__.'/../includes/admin-add-actions.php');
drink_assert(str_contains($page,'+ Add Drink')&&str_contains($page,'itemTypeFilter')&&str_contains($page,'menu-manager-drink.js'),'Menu Manager must expose and load the Drink builder.');
foreach(['Flavor choices','Ice choices','Paid add-ons','Package Deals','Save & Publish'] as $needle)drink_assert(str_contains($js,$needle),'Drink builder must include '.$needle.'.');
drink_assert(str_contains($actions,"menu-manager.php?action=add-drink")&&!str_contains($actions,"'id'=>'drink','group'=>'Menu & Products','icon'=>'◌','label'=>'Add Drink','description'=>'Create a drink item through the upcoming"),'Global + Add Drink must navigate to the live builder.');

echo "menu-manager-drink-builder=ok\n";
