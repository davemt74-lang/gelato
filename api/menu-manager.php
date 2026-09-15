<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/menu-manager-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];

function menu_manager_api_require(array $user,string $permission): void
{
    if(!app_has_permission($permission,$user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to complete this menu action.'],403);
}

if(!menu_manager_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Menu Manager is not installed. Run Upgrade first.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    menu_manager_api_require($user,'menu.view');
    $action=(string)($_GET['action']??'state');
    try{
        if($action==='state'){
            $query=(string)($_GET['q']??'');$categoryId=(int)($_GET['categoryId']??0);$archived=!empty($_GET['archived'])&&app_has_permission('menu.manage',$user);
            app_json_response(['ok'=>true,'categories'=>menu_manager_categories($pdo,$org,true),'items'=>menu_manager_list_items($pdo,$org,$query,$categoryId?:null,$archived),'canManage'=>app_has_permission('menu.manage',$user)]);
        }
        if($action==='item'){
            $itemId=(int)($_GET['id']??0);app_json_response(['ok'=>true,'item'=>menu_manager_item_payload($pdo,$org,$itemId)]);
        }
        if($action==='ingredients'){
            app_json_response(['ok'=>true,'ingredients'=>menu_manager_ingredient_search($pdo,$org,(string)($_GET['q']??''),80)]);
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
        app_json_response(['ok'=>true,'category'=>$category]);
    }
    if($action==='item.save'){
        $id=((int)($payload['id']??0))?:null;$item=menu_manager_save_item($pdo,$org,$id,is_array($payload['item']??null)?$payload['item']:[],$userId);
        app_audit($pdo,$org,$userId,'menu.item_saved','menu_item',(string)$item['id'],null,['name'=>$item['name'],'status'=>$item['profile']['lifecycleStatus']]);
        app_json_response(['ok'=>true,'item'=>$item]);
    }
    if($action==='item.duplicate'){
        $item=menu_manager_duplicate($pdo,$org,(int)($payload['id']??0),$userId);
        app_audit($pdo,$org,$userId,'menu.item_duplicated','menu_item',(string)$item['id'],null,['name'=>$item['name']]);
        app_json_response(['ok'=>true,'item'=>$item]);
    }
    if(in_array($action,['item.publish','item.pause','item.resume','item.archive','item.draft'],true)){
        $verb=substr($action,5);$item=menu_manager_set_status($pdo,$org,(int)($payload['id']??0),$verb,$userId);
        app_audit($pdo,$org,$userId,'menu.item_'.$verb,'menu_item',(string)$item['id'],null,['name'=>$item['name'],'status'=>$item['profile']['lifecycleStatus']]);
        app_json_response(['ok'=>true,'item'=>$item]);
    }
    app_json_response(['ok'=>false,'message'=>'Unknown menu action.'],400);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Menu Manager POST failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'The menu action could not be completed.'],500);}
