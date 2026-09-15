<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/menu-manager-core.php';
require_once __DIR__.'/../includes/menu-operations-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];

function menu_manager_api_require(array $user,string $permission): void
{
    if(!app_has_permission($permission,$user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to complete this menu action.'],403);
}

function menu_manager_api_locations(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT id,name,status,timezone FROM locations WHERE organization_id=? ORDER BY status='active' DESC,name,id");$q->execute([$org]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'status'=>(string)$r['status'],'timezone'=>(string)($r['timezone']??'')],$q->fetchAll());
}

if(!menu_manager_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Menu Manager is not installed. Run Upgrade first.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    menu_manager_api_require($user,'menu.view');
    $action=(string)($_GET['action']??'state');
    try{
        if($action==='state'){
            $query=(string)($_GET['q']??'');$categoryId=(int)($_GET['categoryId']??0);$archived=!empty($_GET['archived'])&&app_has_permission('menu.manage',$user);
            app_json_response(['ok'=>true,'categories'=>menu_manager_categories($pdo,$org,true),'items'=>menu_manager_list_items($pdo,$org,$query,$categoryId?:null,$archived),'canManage'=>app_has_permission('menu.manage',$user),'operationsReady'=>menu_operations_ready($pdo)]);
        }
        if($action==='item'){
            $itemId=(int)($_GET['id']??0);$item=menu_manager_item_payload($pdo,$org,$itemId);if(menu_operations_ready($pdo))$item['availabilitySchedules']=menu_operations_schedules($pdo,$org,$itemId);app_json_response(['ok'=>true,'item'=>$item]);
        }
        if($action==='ingredients'){
            app_json_response(['ok'=>true,'ingredients'=>menu_manager_ingredient_search($pdo,$org,(string)($_GET['q']??''),80)]);
        }
        if($action==='operations'){
            if(!menu_operations_ready($pdo))app_json_response(['ok'=>false,'message'=>'Menu Operations is not installed. Run Upgrade once.'],503);
            $locationId=(int)($_GET['locationId']??0);if($locationId)menu_operations_location_row($pdo,$org,$locationId);
            app_json_response(['ok'=>true,'summary'=>menu_operations_summary($pdo,$org,$locationId?:null),'events'=>menu_operations_recent_events($pdo,$org,40),'images'=>menu_operations_image_completeness($pdo,$org),'locations'=>menu_manager_api_locations($pdo,$org),'locationState'=>$locationId?menu_operations_location_state($pdo,$org,$locationId):[]]);
        }
        if($action==='schedules'){
            if(!menu_operations_ready($pdo))app_json_response(['ok'=>false,'message'=>'Menu Operations is not installed. Run Upgrade once.'],503);
            $itemId=(int)($_GET['itemId']??0);$locationId=(int)($_GET['locationId']??0);menu_operations_item_row($pdo,$org,$itemId);app_json_response(['ok'=>true,'schedules'=>menu_operations_schedules($pdo,$org,$itemId,$locationId?:null)]);
        }
        app_json_response(['ok'=>false,'message'=>'Unknown menu action.'],400);
    }catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    catch(Throwable $e){error_log('Menu Manager GET failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Menu data could not be loaded.'],500);}
}

if($_SERVER['REQUEST_METHOD']!=='POST') app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
menu_manager_api_require($user,'menu.manage');
$payload=[];try{$payload=json_decode((string)file_get_contents('php://input'),true,128,JSON_THROW_ON_ERROR);}catch(Throwable){}
if(!is_array($payload))$payload=[];
if(!app_verify_csrf((string)($payload['csrfToken']??''))) app_json_response(['ok'=>false,'message'=>'The request expired. Refresh and try again.'],419);
$action=(string)($payload['action']??'');

try{
    if($action==='category.save'){
        $category=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,((int)($payload['id']??0))?:null,is_array($payload['category']??null)?$payload['category']:[]));
        app_audit($pdo,$org,$userId,'menu.category_saved','menu_section',(string)$category['id'],null,['name'=>$category['name'],'status'=>$category['status']]);
        if(menu_operations_ready($pdo)){menu_operations_event($pdo,$org,'category.saved','Menu category '.$category['name'].' saved',null,null,null,$userId,['categoryId'=>$category['id']]);menu_operations_sync_brain($pdo,$org,$userId);}
        app_json_response(['ok'=>true,'category'=>$category]);
    }
    if($action==='item.save'){
        $id=((int)($payload['id']??0))?:null;$item=menu_manager_save_item($pdo,$org,$id,is_array($payload['item']??null)?$payload['item']:[],$userId);
        app_audit($pdo,$org,$userId,'menu.item_saved','menu_item',(string)$item['id'],null,['name'=>$item['name'],'status'=>$item['profile']['lifecycleStatus']]);
        if(menu_operations_ready($pdo)){menu_operations_event($pdo,$org,'item.saved',$item['name'].' menu configuration saved',(int)$item['id'],null,null,$userId,['lifecycle'=>$item['profile']['lifecycleStatus']]);menu_operations_sync_brain($pdo,$org,$userId);}
        app_json_response(['ok'=>true,'item'=>$item]);
    }
    if($action==='item.duplicate'){
        $item=menu_manager_duplicate($pdo,$org,(int)($payload['id']??0),$userId);
        app_audit($pdo,$org,$userId,'menu.item_duplicated','menu_item',(string)$item['id'],null,['name'=>$item['name']]);
        if(menu_operations_ready($pdo)){menu_operations_event($pdo,$org,'item.duplicated',$item['name'].' created as a draft copy',(int)$item['id'],null,null,$userId);menu_operations_sync_brain($pdo,$org,$userId);}
        app_json_response(['ok'=>true,'item'=>$item]);
    }
    if(in_array($action,['item.publish','item.pause','item.resume','item.archive','item.draft'],true)){
        $verb=substr($action,5);$item=menu_manager_set_status($pdo,$org,(int)($payload['id']??0),$verb,$userId);
        app_audit($pdo,$org,$userId,'menu.item_'.$verb,'menu_item',(string)$item['id'],null,['name'=>$item['name'],'status'=>$item['profile']['lifecycleStatus']]);
        if(menu_operations_ready($pdo)){menu_operations_event($pdo,$org,'item.'.$verb,$item['name'].' changed to '.$item['profile']['lifecycleStatus'],(int)$item['id'],null,null,$userId);menu_operations_sync_brain($pdo,$org,$userId);}
        app_json_response(['ok'=>true,'item'=>$item]);
    }

    if(str_starts_with($action,'operations.')){
        if(!menu_operations_ready($pdo))app_json_response(['ok'=>false,'message'=>'Menu Operations is not installed. Run Upgrade once.'],503);
        if($action==='operations.item_status'){
            $status=menu_operations_set_item_status($pdo,$org,(int)($payload['itemId']??0),(int)($payload['locationId']??0),!empty($payload['soldOut']),(string)($payload['reason']??''),isset($payload['resumeAt'])?(string)$payload['resumeAt']:null,$userId);
            app_audit($pdo,$org,$userId,'menu.operations.item_status','menu_item',(string)($payload['itemId']??''),null,['locationId'=>(int)($payload['locationId']??0),'soldOut'=>!empty($payload['soldOut'])]);app_json_response(['ok'=>true,'status'=>$status]);
        }
        if($action==='operations.price_status'){
            $status=menu_operations_set_price_status($pdo,$org,(int)($payload['priceId']??0),(int)($payload['locationId']??0),!empty($payload['soldOut']),(string)($payload['reason']??''),isset($payload['resumeAt'])?(string)$payload['resumeAt']:null,$userId);
            app_audit($pdo,$org,$userId,'menu.operations.price_status','menu_item_price',(string)($payload['priceId']??''),null,['locationId'=>(int)($payload['locationId']??0),'soldOut'=>!empty($payload['soldOut'])]);app_json_response(['ok'=>true,'status'=>$status]);
        }
        if($action==='operations.price_update'){
            $price=menu_operations_update_price($pdo,$org,(int)($payload['priceId']??0),(float)($payload['amount']??-1),$userId);app_audit($pdo,$org,$userId,'menu.operations.price_update','menu_item_price',(string)$price['id'],null,['amount'=>(float)$price['amount']]);app_json_response(['ok'=>true,'price'=>$price]);
        }
        if($action==='operations.bulk_price'){
            $count=menu_operations_bulk_price($pdo,$org,is_array($payload['itemIds']??null)?$payload['itemIds']:[],(string)($payload['mode']??''),(float)($payload['value']??0),$userId);app_audit($pdo,$org,$userId,'menu.operations.bulk_price','menu',null,null,['count'=>$count,'mode'=>$payload['mode']??null,'value'=>$payload['value']??null]);app_json_response(['ok'=>true,'updatedPrices'=>$count]);
        }
        if($action==='operations.reorder_categories'){
            menu_operations_reorder_categories($pdo,$org,is_array($payload['ids']??null)?$payload['ids']:[],$userId);app_audit($pdo,$org,$userId,'menu.operations.categories_reordered','menu',null);app_json_response(['ok'=>true]);
        }
        if($action==='operations.reorder_items'){
            menu_operations_reorder_items($pdo,$org,(int)($payload['categoryId']??0),is_array($payload['ids']??null)?$payload['ids']:[],$userId);app_audit($pdo,$org,$userId,'menu.operations.items_reordered','menu_section',(string)($payload['categoryId']??''));app_json_response(['ok'=>true]);
        }
        if($action==='operations.schedule_save'){
            $schedules=menu_operations_save_schedule($pdo,$org,(int)($payload['itemId']??0),((int)($payload['locationId']??0))?:null,(string)($payload['channel']??'online_order'),is_array($payload['rows']??null)?$payload['rows']:[],$userId);app_audit($pdo,$org,$userId,'menu.operations.schedule_saved','menu_item',(string)($payload['itemId']??''),null,['channel'=>$payload['channel']??'online_order','locationId'=>(int)($payload['locationId']??0)]);app_json_response(['ok'=>true,'schedules'=>$schedules]);
        }
        if($action==='operations.bulk_distribution'){
            $ids=array_values(array_unique(array_filter(array_map('intval',is_array($payload['itemIds']??null)?$payload['itemIds']:[]),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');$channel=(string)($payload['channel']??'');$column=menu_manager_channels()[$channel]??null;if($column===null)throw new InvalidArgumentException('Unknown distribution channel.');$enabled=!empty($payload['enabled'])?1:0;
            $pdo->beginTransaction();try{foreach($ids as $id){menu_operations_item_row($pdo,$org,$id);$pdo->prepare("UPDATE menu_item_profiles SET {$column}=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND menu_item_id=?")->execute([$enabled,$userId,$org,$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}menu_operations_event($pdo,$org,'distribution.bulk_updated','Bulk menu distribution updated',null,null,null,$userId,['itemIds'=>$ids,'channel'=>$channel,'enabled'=>(bool)$enabled]);menu_operations_sync_brain($pdo,$org,$userId);app_audit($pdo,$org,$userId,'menu.operations.bulk_distribution','menu',null,null,['count'=>count($ids),'channel'=>$channel,'enabled'=>(bool)$enabled]);app_json_response(['ok'=>true,'updatedItems'=>count($ids)]);
        }
        if($action==='operations.bulk_status'){
            $ids=array_values(array_unique(array_filter(array_map('intval',is_array($payload['itemIds']??null)?$payload['itemIds']:[]),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');$verb=(string)($payload['status']??'');if(!in_array($verb,['publish','pause','resume','archive','draft'],true))throw new InvalidArgumentException('Unknown lifecycle status.');foreach($ids as $id)menu_manager_set_status($pdo,$org,$id,$verb,$userId);menu_operations_event($pdo,$org,'lifecycle.bulk_updated','Bulk menu lifecycle changed to '.$verb,null,null,null,$userId,['itemIds'=>$ids]);menu_operations_sync_brain($pdo,$org,$userId);app_audit($pdo,$org,$userId,'menu.operations.bulk_status','menu',null,null,['count'=>count($ids),'status'=>$verb]);app_json_response(['ok'=>true,'updatedItems'=>count($ids)]);
        }
    }
    app_json_response(['ok'=>false,'message'=>'Unknown menu action.'],400);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Menu Manager POST failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'The menu action could not be completed.'],500);}
