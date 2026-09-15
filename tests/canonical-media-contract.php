<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/location-core.php';
require_once __DIR__.'/../includes/menu-manager-core.php';
require_once __DIR__.'/../includes/media-core.php';

function cm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function cm_one(PDO $pdo,string $sql,array $args=[]):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
$pdo=app_pdo();cm_assert(media_schema_ready($pdo),'Canonical media migration must be installed.');
$slug='media-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Media CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Other Media CI '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$hash=password_hash('Media-CI!42',PASSWORD_DEFAULT);$email=$slug.'@example.test';
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$email,$hash,'Media','CI','Media CI']);$userId=(int)$pdo->lastInsertId();

$location=location_save($pdo,$org,null,['name'=>'Stonefellows Media Test','public_slug'=>'media-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','pickup_enabled'=>true,'online_ordering_enabled'=>true]);$locationId=(int)$location['id'];
$otherLocation=location_save($pdo,$otherOrg,null,['name'=>'Other Store','public_slug'=>'other-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','pickup_enabled'=>true]);
$category=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,null,['name'=>'Pizza','status'=>'active']));
$item=menu_manager_save_item($pdo,$org,null,['name'=>'Image Pizza','categoryId'=>$category['id'],'description'=>'Pizza with canonical media.','sizes'=>[['clientKey'=>'one','label'=>'Regular','sizeCode'=>'REG','amount'=>12.00]],'ingredients'=>[['name'=>'Mozzarella','canRemove'=>true]],'distribution'=>['publicMenu'=>true,'onlineOrder'=>true,'pos'=>true,'packages'=>true,'catering'=>false],'locationAvailability'=>[(string)$locationId=>true]],$userId);
$item=menu_manager_set_status($pdo,$org,(int)$item['id'],'publish',$userId);$ingredientId=(int)$item['ingredients'][0]['id'];cm_assert($ingredientId>0,'Menu item must have a canonical ingredient ID.');

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);cm_assert(is_string($png),'PNG fixture must decode.');
$itemFile=media_store_image_bytes($pdo,$org,$userId,$png,'pizza.png');$itemMedia=media_assign_target($pdo,$org,'menu_item',(int)$item['id'],(int)$itemFile['id']);cm_assert(str_contains((string)$itemMedia['file']['url'],'uploads/media/org-'.$org.'/'),'Menu item image must use organization-scoped public media storage.');
$ingredientFile=media_store_image_bytes($pdo,$org,$userId,$png,'mozzarella.png');media_assign_target($pdo,$org,'ingredient',$ingredientId,(int)$ingredientFile['id']);
$locationFile=media_store_image_bytes($pdo,$org,$userId,$png,'store.png');media_assign_target($pdo,$org,'location',$locationId,(int)$locationFile['id']);

cm_assert((int)cm_one($pdo,'SELECT main_image_file_id FROM menu_items WHERE organization_id=? AND id=?',[$org,(int)$item['id']])===(int)$itemFile['id'],'Menu item must persist its main image file ID.');
cm_assert((int)cm_one($pdo,'SELECT image_file_id FROM ingredients WHERE organization_id=? AND id=?',[$org,$ingredientId])===(int)$ingredientFile['id'],'Ingredient must persist its image file ID.');
cm_assert((int)cm_one($pdo,'SELECT cover_image_file_id FROM locations WHERE organization_id=? AND id=?',[$org,$locationId])===(int)$locationFile['id'],'Location must persist its cover image file ID.');

$public=media_public_menu_sections($pdo,$org);$publicItem=null;foreach($public as $section)foreach($section['items'] as $row)if($row['name']==='Image Pizza')$publicItem=$row;
cm_assert(is_array($publicItem)&&str_contains((string)$publicItem['imageUrl'],'uploads/media/'),'Public menu projection must expose the menu item image.');
cm_assert(($publicItem['ingredientDetails'][0]['id']??0)===$ingredientId&&str_contains((string)($publicItem['ingredientDetails'][0]['imageUrl']??''),'uploads/media/'),'Public menu projection must expose ingredient images.');
$covers=media_location_cover_map($pdo,$org);cm_assert(isset($covers[$locationId])&&str_contains($covers[$locationId],'uploads/media/'),'Location cover map must expose the store-specific cover image.');

$svg='<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';$rejected=false;try{media_store_image_bytes($pdo,$org,$userId,$svg,'bad.svg');}catch(InvalidArgumentException){$rejected=true;}cm_assert($rejected,'SVG uploads must be rejected.');
$crossOrg=false;try{media_assign_target($pdo,$org,'location',(int)$otherLocation['id'],(int)$itemFile['id']);}catch(InvalidArgumentException){$crossOrg=true;}cm_assert($crossOrg,'Media targets must be organization scoped.');

$replacement=media_store_image_bytes($pdo,$org,$userId,$png,'pizza-new.png');$oldId=(int)$itemFile['id'];media_assign_target($pdo,$org,'menu_item',(int)$item['id'],(int)$replacement['id']);cm_assert((string)cm_one($pdo,'SELECT deleted_at FROM files WHERE organization_id=? AND id=?',[$org,$oldId])!=='','Replacing an unshared image must retire the previous file ledger row.');
media_remove_target($pdo,$org,'menu_item',(int)$item['id']);media_remove_target($pdo,$org,'ingredient',$ingredientId);media_remove_target($pdo,$org,'location',$locationId);
cm_assert(cm_one($pdo,'SELECT main_image_file_id FROM menu_items WHERE organization_id=? AND id=?',[$org,(int)$item['id']])===null,'Removing media must clear the menu item image reference.');
cm_assert(cm_one($pdo,'SELECT image_file_id FROM ingredients WHERE organization_id=? AND id=?',[$org,$ingredientId])===null,'Removing media must clear the ingredient image reference.');
cm_assert(cm_one($pdo,'SELECT cover_image_file_id FROM locations WHERE organization_id=? AND id=?',[$org,$locationId])===null,'Removing media must clear the location cover reference.');

echo "canonical-media=ok\n";
