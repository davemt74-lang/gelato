<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/location-core.php';
require_once __DIR__.'/../includes/menu-manager-core.php';
require_once __DIR__.'/../includes/menu-operations-core.php';

$pdo=app_pdo();
function mop_assert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function mop_one(PDO $pdo,string $sql,array $args=[]): mixed { $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn(); }

mop_assert(menu_manager_ready($pdo),'Menu Manager must be installed.');
mop_assert(menu_operations_ready($pdo),'Menu Operations must be installed.');

$slug='menuops-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Menu Operations CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$locA=location_save($pdo,$org,null,['name'=>'Downtown '.$slug,'public_slug'=>'downtown-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>15]);
$locB=location_save($pdo,$org,null,['name'=>'North '.$slug,'public_slug'=>'north-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20]);
$locAId=(int)$locA['id'];$locBId=(int)$locB['id'];

$password='MenuOps-CI!42';
$registration=customer_account_register($pdo,$org,['firstName'=>'Menu','lastName'=>'Operator','email'=>$slug.'@example.test','phone'=>'6025550188','password'=>$password,'passwordConfirm'=>$password]);
$userId=(int)$registration['userId'];
mop_assert($userId>0,'A valid actor user is required.');

$catA=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,null,['name'=>'Pizza '.$slug,'description'=>'Pizza','sortOrder'=>10,'status'=>'active']));
$catB=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,null,['name'=>'Specials '.$slug,'description'=>'Specials','sortOrder'=>20,'status'=>'active']));

$makeItem=static function(string $name,float $small,float $large) use($pdo,$org,$userId,$catA,$locAId,$locBId): array {
    $item=menu_manager_save_item($pdo,$org,null,[
        'name'=>$name,'categoryId'=>$catA['id'],'description'=>'Menu Operations test item','preparationNotes'=>'Test only','kitchenStation'=>'Pizza / Oven',
        'sizes'=>[
            ['clientKey'=>'small','label'=>'Small','sizeCode'=>'S','amount'=>$small,'sortOrder'=>1],
            ['clientKey'=>'large','label'=>'Large','sizeCode'=>'L','amount'=>$large,'sortOrder'=>2],
        ],
        'ingredients'=>[], 'modifierGroups'=>[],
        'distribution'=>['publicMenu'=>true,'onlineOrder'=>true,'pos'=>true,'packages'=>true,'catering'=>false],
        'locationAvailability'=>[(string)$locAId=>true,(string)$locBId=>true],
    ],$userId);
    return menu_manager_set_status($pdo,$org,(int)$item['id'],'publish',$userId);
};

$itemA=$makeItem('Operations Pepperoni '.$slug,12.00,18.00);
$itemB=$makeItem('Operations Margherita '.$slug,11.00,17.00);
$itemAId=(int)$itemA['id'];$itemBId=(int)$itemB['id'];
$largeA=0;foreach($itemA['sizes'] as $size) if($size['label']==='Large') $largeA=(int)$size['id'];
mop_assert($largeA>0,'Large price variant must exist.');

// One shared menu can have different live availability at each restaurant.
$status=menu_operations_set_item_status($pdo,$org,$itemAId,$locAId,true,'86: dough batch depleted',null,$userId);
mop_assert(!empty($status['soldOut']),'Location A item 86 must be active.');
mop_assert(!menu_operations_item_sellable($pdo,$org,$itemAId,'online_order',$locAId),'86 item must be blocked at Location A.');
mop_assert(menu_operations_item_sellable($pdo,$org,$itemAId,'online_order',$locBId),'The same shared item must remain sellable at Location B.');
menu_operations_set_item_status($pdo,$org,$itemAId,$locAId,false,'',null,$userId);

// Size-level availability is independent by location and supports timed auto-resume.
menu_operations_set_price_status($pdo,$org,$largeA,$locBId,true,'Large boxes unavailable',null,$userId);
mop_assert(!menu_operations_price_sellable($pdo,$org,$largeA,'online_order',$locBId),'Large must be unavailable only at Location B.');
mop_assert(menu_operations_price_sellable($pdo,$org,$largeA,'online_order',$locAId),'Large must remain available at Location A.');
$past=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->modify('-2 hours')->format('Y-m-d H:i:s');
$resumed=menu_operations_set_price_status($pdo,$org,$largeA,$locBId,true,'Temporary 86',$past,$userId);
mop_assert(empty($resumed['soldOut'])&&!empty($resumed['storedSoldOut']),'Expired 86 must automatically stop blocking while retaining its audit state.');
mop_assert(menu_operations_price_sellable($pdo,$org,$largeA,'online_order',$locBId),'Expired size 86 must auto-resume selling.');

// Day/time schedules affect the real selling projection.
$now=new DateTimeImmutable('now',new DateTimeZone('America/Phoenix'));$today=(int)$now->format('w');$otherDay=($today+1)%7;
menu_operations_save_schedule($pdo,$org,$itemAId,$locAId,'online_order',[['dayOfWeek'=>$otherDay,'startTime'=>'00:00','endTime'=>'00:00']],$userId);
mop_assert(!menu_operations_item_sellable($pdo,$org,$itemAId,'online_order',$locAId),'An off-day schedule must block the item.');
menu_operations_save_schedule($pdo,$org,$itemAId,$locAId,'online_order',[['dayOfWeek'=>$today,'startTime'=>'00:00','endTime'=>'00:00']],$userId);
mop_assert(menu_operations_item_sellable($pdo,$org,$itemAId,'online_order',$locAId),'An all-day current-day schedule must allow the item.');

// Inline and bulk price operations persist and are auditable.
$smallA=(int)$itemA['sizes'][0]['id'];
$updated=menu_operations_update_price($pdo,$org,$smallA,13.25,$userId);
mop_assert(abs((float)$updated['amount']-13.25)<0.001,'Inline price update must persist.');
$count=menu_operations_bulk_price($pdo,$org,[$itemAId,$itemBId],'fixed',1.00,$userId);
mop_assert($count===4,'Bulk pricing must update all four active size rows.');
mop_assert(abs((float)mop_one($pdo,'SELECT amount FROM menu_item_prices WHERE id=?',[$smallA])-14.25)<0.001,'Bulk fixed adjustment must apply after inline editing.');

// Ordering controls persist for categories and menu items.
menu_operations_reorder_categories($pdo,$org,[(int)$catB['id'],(int)$catA['id']],$userId);
$catBSort=(int)mop_one($pdo,'SELECT sort_order FROM menu_sections WHERE organization_id=? AND id=?',[$org,(int)$catB['id']]);
$catASort=(int)mop_one($pdo,'SELECT sort_order FROM menu_sections WHERE organization_id=? AND id=?',[$org,(int)$catA['id']]);
mop_assert($catBSort<$catASort,'Category reorder must persist.');
menu_operations_reorder_items($pdo,$org,(int)$catA['id'],[$itemBId,$itemAId],$userId);
$itemBSort=(int)mop_one($pdo,'SELECT sort_order FROM menu_items WHERE organization_id=? AND id=?',[$org,$itemBId]);
$itemASort=(int)mop_one($pdo,'SELECT sort_order FROM menu_items WHERE organization_id=? AND id=?',[$org,$itemAId]);
mop_assert($itemBSort<$itemASort,'Item reorder must persist.');

$summary=menu_operations_summary($pdo,$org,$locAId);
mop_assert($summary['published']>=2&&$summary['scheduledItems']>=1,'Operations summary must include published and scheduled menu state.');
$events=menu_operations_recent_events($pdo,$org,50);
mop_assert(count($events)>=8,'Operational price, availability, schedule and reorder changes must create history events.');

// Every operational mutation refreshes the same central Restaurant Agent knowledge store.
$brain=$pdo->prepare("SELECT title,content,version FROM agent_knowledge_records WHERE organization_id=? AND source_type='menu_operations' AND source_public_id='current' LIMIT 1");
$brain->execute([$org]);$brainRow=$brain->fetch();
mop_assert(is_array($brainRow),'Menu Operations must write a central Agent Brain knowledge snapshot.');
mop_assert(str_contains((string)$brainRow['content'],'Operations Pepperoni')&&str_contains((string)$brainRow['content'],'Recent menu changes'),'Agent Brain snapshot must contain live menu state and operation history.');
mop_assert((int)$brainRow['version']>1,'Agent Brain snapshot must refresh as operations change.');

// Main Agent Brain exposes both read and permission-gated write skills for this domain.
$agent=(string)file_get_contents(__DIR__.'/../api/agent-brain.php');
foreach(['menu.summary','menu.search','menu.availability','menu.recent_changes','menu.set_item_availability','menu.set_size_availability','menu.update_price','menu.bulk_price','menu.schedule_save','menu.reorder_categories','menu.reorder_items','menu.bulk_move','menu.bulk_distribution','menu.bulk_status'] as $skill){
    mop_assert(str_contains($agent,$skill),'Central Agent Brain must expose '.$skill.'.');
}

// Menu Manager operations UI and requested shell/header behavior are contract-protected.
$manager=(string)file_get_contents(__DIR__.'/../menu-manager.php');$opsJs=(string)file_get_contents(__DIR__.'/../assets/js/menu-operations.js');
mop_assert(str_contains($manager,'menu-operations')&&str_contains($opsJs,'soldOut'),'Menu Manager must load live Menu Operations controls.');
$api=(string)file_get_contents(__DIR__.'/../api/menu-manager.php');foreach(['operations.bulk_move','operations.bulk_distribution','operations.bulk_status','operations.schedule_save'] as $action)mop_assert(str_contains($api,$action),'Menu Manager API must expose '.$action.'.');

$shell=(string)file_get_contents(__DIR__.'/../includes/admin-shell-core.php');
$kdsNeedle="if(app_has_permission('kds.view',$user)) $links[]=['label'=>'KDS'";$posNeedle="if(app_has_permission('pos.use',$user)) $links[]=['label'=>'POS'";
$kdsAt=strpos($shell,$kdsNeedle);$posAt=strpos($shell,$posNeedle);
mop_assert($kdsAt!==false&&$posAt!==false&&$kdsAt<$posAt,'Canonical header must render KDS followed by POS.');
$addCss=(string)file_get_contents(__DIR__.'/../css/global-add-canvas.css');
mop_assert(str_contains($addCss,'.gac-launch')&&str_contains($addCss,'background:#fff;color:#111'),'Global + button must use white background and black plus.');
$workspace=(string)file_get_contents(__DIR__.'/../index.html');$universal=(string)file_get_contents(__DIR__.'/../js/universal-admin-page-shell.js');
mop_assert(str_contains($workspace,'<a class="brand" href="workspace.php"')&&str_contains($universal,'<a class="uas-brand" href="workspace.php"'),'Both admin sidebar brands must link to the default dashboard.');

echo "menu-operations-agent-brain=ok\n";
