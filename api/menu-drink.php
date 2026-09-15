<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/menu-drink-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!app_has_permission('menu.view',$user))app_json_response(['ok'=>false,'message'=>'Menu access is required.'],403);
if(!menu_manager_ready($pdo))app_json_response(['ok'=>false,'message'=>'Menu Manager is not installed. Run Upgrade first.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    try{
        $action=(string)($_GET['action']??'context');
        if($action==='context')app_json_response(['ok'=>true]+menu_drink_context($pdo,$org));
        if($action==='item'){$item=menu_manager_item_payload($pdo,$org,(int)($_GET['id']??0));if((string)($item['profile']['itemType']??'')!=='drink')throw new InvalidArgumentException('This menu item is not a Drink.');app_json_response(['ok'=>true,'item'=>$item]);}
        app_json_response(['ok'=>false,'message'=>'Unknown Drink action.'],400);
    }catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Drink builder GET failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Drink data could not be loaded.'],500);}
}

if($_SERVER['REQUEST_METHOD']!=='POST')app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu management permission is required.'],403);
$payload=[];try{$payload=json_decode((string)file_get_contents('php://input'),true,128,JSON_THROW_ON_ERROR);}catch(Throwable){}
if(!is_array($payload))$payload=[];if(!app_verify_csrf((string)($payload['csrfToken']??'')))app_json_response(['ok'=>false,'message'=>'The request expired. Refresh and try again.'],419);
$action=(string)($payload['action']??'');
try{
    if($action==='drink.save'){$id=((int)($payload['id']??0))?:null;$item=menu_drink_save($pdo,$org,$id,is_array($payload['item']??null)?$payload['item']:[],$userId);app_audit($pdo,$org,$userId,'menu.drink_saved','menu_item',(string)$item['id'],null,['name'=>$item['name'],'drinkKind'=>$item['metadata']['drinkKind']??'other']);app_json_response(['ok'=>true,'item'=>$item]);}
    if($action==='drink.duplicate'){$item=menu_drink_duplicate($pdo,$org,(int)($payload['id']??0),$userId);app_audit($pdo,$org,$userId,'menu.drink_duplicated','menu_item',(string)$item['id'],null,['name'=>$item['name']]);app_json_response(['ok'=>true,'item'=>$item]);}
    if(in_array($action,['drink.publish','drink.pause','drink.resume','drink.archive','drink.draft'],true)){$verb=substr($action,6);$item=menu_drink_set_status($pdo,$org,(int)($payload['id']??0),$verb,$userId);app_audit($pdo,$org,$userId,'menu.drink_'.$verb,'menu_item',(string)$item['id'],null,['name'=>$item['name'],'status'=>$item['profile']['lifecycleStatus']]);app_json_response(['ok'=>true,'item'=>$item]);}
    app_json_response(['ok'=>false,'message'=>'Unknown Drink action.'],400);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Drink builder POST failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'The Drink action could not be completed.'],500);}
