<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/location-core.php';
require_once __DIR__.'/../includes/menu-manager-core.php';
require_once __DIR__.'/../includes/menu-order-integration.php';
require_once __DIR__.'/../includes/kds-core.php';

$pdo=app_pdo();
function food_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function food_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

food_assert(menu_manager_ready($pdo),'Menu Manager schema must be installed.');
$slug='food-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Food Builder CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$primary=location_save($pdo,$org,null,['name'=>'Food Builder Primary','public_slug'=>'primary-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>15]);
$secondary=location_save($pdo,$org,null,['name'=>'Food Builder Secondary','public_slug'=>'secondary-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20]);
$locationId=(int)$primary['id'];$secondaryId=(int)$secondary['id'];

$email=$slug.'@example.test';$password='FoodBuilder-CI!42';
$registration=customer_account_register($pdo,$org,['firstName'=>'Food','lastName'=>'Builder','email'=>$email,'phone'=>'6025550188','password'=>$password,'passwordConfirm'=>$password]);
$userId=(int)$registration['userId'];$customerId=(int)food_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
food_assert($customerId>0,'Food builder test user must have a CRM customer.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);

$category=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,null,['name'=>'Pizza','description'=>'Wood-fired pizza','sortOrder'=>10,'status'=>'active']));
food_assert($category['name']==='Pizza','Category must be created.');
$category=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,(int)$category['id'],['name'=>'Wood-Fired Pizza','description'=>'Wood-fired pizza','sortOrder'=>5,'status'=>'active']));
food_assert($category['name']==='Wood-Fired Pizza'&&$category['sortOrder']===5,'Editable categories must persist name and order changes.');

$item=menu_manager_save_item($pdo,$org,null,[
    'name'=>'Pepperoni Pizza','categoryId'=>$category['id'],'description'=>'House sauce, mozzarella and pepperoni.','preparationNotes'=>'Bake in wood-fired oven.','kitchenStation'=>'Pizza / Oven',
    'sizes'=>[
        ['clientKey'=>'small','label'=>'Small','sizeCode'=>'S','amount'=>12.00,'sortOrder'=>1],
        ['clientKey'=>'medium','label'=>'Medium','sizeCode'=>'M','amount'=>15.00,'sortOrder'=>2],
        ['clientKey'=>'large','label'=>'Large','sizeCode'=>'L','amount'=>19.00,'sortOrder'=>3],
    ],
    'ingredients'=>[
        ['name'=>'House Sauce','canRemove'=>true],['name'=>'Mozzarella','canRemove'=>true],['name'=>'Pepperoni','canRemove'=>true],
    ],
    'modifierGroups'=>[[
        'name'=>'Pizza Add-ons','type'=>'add_on','minSelect'=>0,'maxSelect'=>6,
        'options'=>[
            ['name'=>'Extra Cheese','ingredientName'=>'Mozzarella','defaultPriceDelta'=>2.00,'maxQuantity'=>2,'priceDeltas'=>['small'=>1.50,'medium'=>2.00,'large'=>2.50]],
            ['name'=>'Double Pepperoni','ingredientName'=>'Pepperoni','defaultPriceDelta'=>3.00,'maxQuantity'=>1,'priceDeltas'=>['small'=>2.00,'medium'=>3.00,'large'=>4.00]],
            ['name'=>'Add Chicken','ingredientName'=>'Chicken','defaultPriceDelta'=>3.00,'maxQuantity'=>2,'priceDeltas'=>['small'=>2.50,'medium'=>3.00,'large'=>3.50]],
        ],
    ]],
    'distribution'=>['publicMenu'=>true,'onlineOrder'=>true,'pos'=>true,'packages'=>true,'catering'=>false],
    'locationAvailability'=>[(string)$locationId=>true,(string)$secondaryId=>false],
],$userId);
food_assert($item['profile']['lifecycleStatus']==='draft','New Food items must start as draft.');
food_assert(!$item['active'],'Draft Food item must not leak to selling channels.');
food_assert(count($item['sizes'])===3,'Food item must retain all three sizes.');
food_assert(count($item['ingredients'])===3,'Food item must retain included ingredients.');
food_assert(count($item['modifierGroups'])===1&&count($item['modifierGroups'][0]['options'])===3,'Food item must retain paid add-ons.');
food_assert(!menu_manager_item_channel_enabled($pdo,$org,(int)$item['id'],'online_order',$locationId),'Draft item must not be online orderable.');

$item=menu_manager_set_status($pdo,$org,(int)$item['id'],'publish',$userId);
food_assert($item['profile']['lifecycleStatus']==='published'&&$item['active'],'Publish must activate the canonical menu item.');
foreach(['public_menu','online_order','pos','packages'] as $channel)food_assert(menu_manager_item_channel_enabled($pdo,$org,(int)$item['id'],$channel,$locationId),'Published item must distribute to '.$channel.'.');
food_assert(!menu_manager_item_channel_enabled($pdo,$org,(int)$item['id'],'online_order',$secondaryId),'Location availability must block the secondary store.');

$publicSections=menu_manager_public_sections($pdo,$org);$publicNames=[];foreach($publicSections as $section)foreach($section['items'] as $publicItem)$publicNames[]=$publicItem['name'];
food_assert(in_array('Pepperoni Pizza',$publicNames,true),'Published Food must appear in the public menu projection.');
$online=menu_manager_channel_menu($pdo,$org,'online_order',$locationId);$onlineItem=null;foreach($online as $section)foreach($section['items'] as $row)if($row['name']==='Pepperoni Pizza')$onlineItem=$row;
food_assert(is_array($onlineItem)&&count($onlineItem['prices'])===3,'Online Ordering projection must expose all Food sizes.');
$secondaryMenu=menu_manager_channel_menu($pdo,$org,'online_order',$secondaryId);$secondaryNames=[];foreach($secondaryMenu as $section)foreach($section['items'] as $row)$secondaryNames[]=$row['name'];
food_assert(!in_array('Pepperoni Pizza',$secondaryNames,true),'Location-disabled Food must not appear in that location ordering menu.');

$mediumPrice=0;foreach($item['sizes'] as $size)if($size['label']==='Medium')$mediumPrice=(int)$size['id'];food_assert($mediumPrice>0,'Medium price ID must exist.');
$customization=menu_order_customization_context($pdo,$org,$mediumPrice,$locationId);food_assert(count($customization['ingredients'])===3,'Online customizer must expose included ingredients.');
food_assert(count($customization['addOnGroups'])===1,'Online customizer must expose the paid add-on group.');
$options=[];foreach($customization['addOnGroups'][0]['options'] as $option)$options[$option['name']]=$option;
food_assert(isset($options['Extra Cheese'],$options['Double Pepperoni'],$options['Add Chicken']),'Required pizza add-ons must be available.');
food_assert(abs((float)$options['Extra Cheese']['priceDelta']-2.00)<0.001,'Medium Extra Cheese must use its size-specific $2 price.');

$smallPrice=0;$largePrice=0;foreach($item['sizes'] as $size){if($size['label']==='Small')$smallPrice=(int)$size['id'];if($size['label']==='Large')$largePrice=(int)$size['id'];}
$smallOptions=menu_order_customization_context($pdo,$org,$smallPrice,$locationId)['addOnGroups'][0]['options'];$largeOptions=menu_order_customization_context($pdo,$org,$largePrice,$locationId)['addOnGroups'][0]['options'];
$smallExtra=array_values(array_filter($smallOptions,static fn(array $o):bool=>$o['name']==='Extra Cheese'))[0];$largeExtra=array_values(array_filter($largeOptions,static fn(array $o):bool=>$o['name']==='Extra Cheese'))[0];
food_assert(abs((float)$smallExtra['priceDelta']-1.50)<0.001&&abs((float)$largeExtra['priceDelta']-2.50)<0.001,'Extra Cheese pricing must vary by pizza size.');

$station=kds_station_save($pdo,$org,$locationId,['name'=>'Pizza Oven','slug'=>'pizza-'.$slug,'targetSeconds'=>300,'sortOrder'=>1],$userId);kds_route_save($pdo,$org,$locationId,(int)$item['id'],(string)$station['public_id'],$userId);
customer_account_start_session($pdo,$org,$userId);$account=customer_account_current($pdo,$org);food_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Test customer must be able to order online.');
$order=menu_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,'idempotencyKey'=>'food_'.bin2hex(random_bytes(18)),'note'=>'Food builder add-on test',
    'items'=>[array_merge(['priceId'=>$mediumPrice,'quantity'=>1,'instructions'=>''],['customizations'=>[
        'removals'=>[],'substitutions'=>[],'note'=>'Well done','addOns'=>[
            ['optionId'=>(int)$options['Extra Cheese']['id'],'quantity'=>1],['optionId'=>(int)$options['Double Pepperoni']['id'],'quantity'=>1],
        ],
    ]])],
]);
$check=pos_check_details($pdo,$org,(string)$order['check_public_id']);food_assert(abs((float)$check['subtotal']-20.00)<0.001,'Medium pizza plus $2 Extra Cheese and $3 Double Pepperoni must total $20 before tax.');
$line=$pdo->prepare("SELECT id,gross_amount,modifier_amount,special_instructions FROM pos_check_items WHERE organization_id=? AND check_id=? AND status='active' LIMIT 1");$line->execute([$org,(int)$check['id']]);$posLine=$line->fetch();
food_assert(abs((float)$posLine['modifier_amount']-5.00)<0.001&&abs((float)$posLine['gross_amount']-20.00)<0.001,'POS line must retain $5 of paid add-ons in the canonical item total.');
food_assert(str_contains((string)$posLine['special_instructions'],'Extra Cheese')&&str_contains((string)$posLine['special_instructions'],'Double Pepperoni'),'KDS/POS instructions must name the paid add-ons.');
food_assert((int)food_one($pdo,'SELECT COUNT(*) FROM pos_check_item_modifiers WHERE organization_id=? AND pos_check_item_id=?',[$org,(int)$posLine['id']])===2,'Each paid add-on must have an auditable POS modifier record.');
food_assert((int)food_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=?',[$org,(int)$check['id']])===1,'Customized Food item must flow to KDS as one real menu line.');

$copy=menu_manager_duplicate($pdo,$org,(int)$item['id'],$userId);food_assert($copy['id']!==$item['id']&&$copy['profile']['lifecycleStatus']==='draft','Duplicate Food must create a separate draft item.');food_assert(count($copy['sizes'])===3&&count($copy['modifierGroups'][0]['options'])===3,'Duplicate Food must copy sizes and add-on configuration without sales history.');

$pdo->prepare('UPDATE menu_item_profiles SET packages_enabled=0 WHERE organization_id=? AND menu_item_id=?')->execute([$org,(int)$item['id']]);food_assert(!menu_manager_item_channel_enabled($pdo,$org,(int)$item['id'],'packages',$locationId),'Packages distribution toggle must remove item from new package selection.');
$item=menu_manager_set_status($pdo,$org,(int)$item['id'],'pause',$userId);food_assert($item['profile']['lifecycleStatus']==='paused'&&!$item['active'],'Pause must preserve the Food item while removing it from active selling.');food_assert(!menu_manager_item_channel_enabled($pdo,$org,(int)$item['id'],'public_menu',$locationId),'Paused item must disappear from public menu distribution.');
$item=menu_manager_set_status($pdo,$org,(int)$item['id'],'resume',$userId);food_assert($item['profile']['lifecycleStatus']==='published'&&$item['active'],'Resume must republish the item.');

$page=(string)file_get_contents(__DIR__.'/../menu-manager.php');$js=(string)file_get_contents(__DIR__.'/../assets/js/menu-manager.js');$orderJs=(string)file_get_contents(__DIR__.'/../assets/js/online-order-food.js');
food_assert(str_contains($page,'+ Add Food')&&str_contains($page,'food-builder'),'Menu Manager must expose the full-screen Add Food builder.');
foreach(['Sizes & Prices','Included ingredients','Paid add-ons & upgrades','Distribution & availability','Preview & publish'] as $label)food_assert(str_contains($js,$label),'Food builder must include '.$label.'.');
food_assert(str_contains($orderJs,'Paid add-ons')&&str_contains($orderJs,'priceDelta'),'Online ordering UI must render and price paid Food add-ons.');

echo "menu-manager-food-builder=ok\n";
