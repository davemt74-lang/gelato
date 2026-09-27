<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-definition.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$canView=app_has_permission('glasses.view',$user);
$canManage=app_has_permission('glasses.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_definition_ready($pdo))app_json_response(['ok'=>false,'message'=>'AR build-definition migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $menuItemId=(int)($_GET['menuItemId']??0);
        app_json_response([
            'ok'=>true,
            'definitions'=>glasses_definition_list($pdo,$org,$menuItemId>0?$menuItemId:null),
            'permissions'=>['view'=>true,'manage'=>$canManage],
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    if(!$canManage)app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');

    if($action==='definition.compile'){
        $menuItemId=(int)($in['menuItemId']??0);
        if($menuItemId<1)throw new InvalidArgumentException('Choose a menu item.');
        $recipePublicId=trim((string)($in['recipePublicId']??''))?:null;
        $definition=glasses_definition_compile($pdo,$org,$menuItemId,$recipePublicId,$uid);
        app_audit($pdo,$org,$uid,'glasses.definition_compiled','menu_item',(string)$menuItemId,null,[
            'definitionPublicId'=>$definition['publicId'],
            'recipePublicId'=>$definition['recipePublicId'],
            'version'=>$definition['version'],
            'status'=>$definition['status'],
            'unresolvedCount'=>count((array)($definition['definition']['unresolved']??[])),
        ]);
        app_json_response(['ok'=>true,'definition'=>$definition],201);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported AR build-definition action.'],422);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-definition] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'AR build definition could not complete the request.'],500);
}
