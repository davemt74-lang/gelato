<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/daily-quicklist-core.php';

$user=app_require_auth();
if(!daily_quicklist_can_view($user))app_json_response(['ok'=>false,'message'=>'Daily Prep is available to operational staff only.'],403);
$pdo=app_pdo();$org=(int)$user['organization_id'];$actor=(int)$user['id'];
if(!daily_quicklist_ready($pdo))app_json_response(['ok'=>false,'message'=>'Daily Prep is not installed. Run upgrade.php first.'],503);
$manager=daily_quicklist_can_manage($user);
$canRecipe=app_has_permission('recipes.view',$user);
$canInventory=app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'bootstrap');
        if($action==='history'){
            if(!$manager)app_json_response(['ok'=>false,'message'=>'Manager access required.'],403);
            app_json_response(['ok'=>true,'events'=>daily_quicklist_history($pdo,$org,(string)($_GET['id']??''))]);
        }
        if($action!=='bootstrap')throw new InvalidArgumentException('Unknown list action.');
        $date=daily_quicklist_date($_GET['date']??date('Y-m-d'));
        $recipes=$canRecipe?daily_quicklist_recipes($pdo,$org):[];
        app_json_response([
            'ok'=>true,'date'=>$date,'manager'=>$manager,'canRecipe'=>$canRecipe,'canInventory'=>$canInventory,
            'items'=>daily_quicklist_items($pdo,$org,$date),
            'suggestions'=>daily_quicklist_menu_suggestions($pdo,$org,$recipes),
            'recipes'=>$recipes,
            'inventory'=>$canInventory?daily_quicklist_inventory($pdo,$org):[],
        ]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $input=app_json_input();app_verify_request_csrf($input);
    $action=(string)($input['action']??'');
    if($action==='seed'){
        $count=daily_quicklist_seed($pdo,$org,$actor,(string)($input['date']??''),$canRecipe);
        app_audit($pdo,$org,$actor,'daily_quicklist.seed','daily_quicklist_item',null,null,['count'=>$count]);
        app_json_response(['ok'=>true,'count'=>$count]);
    }
    if($action==='add'){
        if(!empty($input['inventoryId'])&&!$canInventory)app_json_response(['ok'=>false,'message'=>'Inventory access is required to link a stock item.'],403);
        $id=daily_quicklist_add($pdo,$org,$actor,$input,$canRecipe);
        app_audit($pdo,$org,$actor,'daily_quicklist.add','daily_quicklist_item',$id,null,
            ['type'=>(string)($input['type']??'')]);
        app_json_response(['ok'=>true,'id'=>$id]);
    }
    if(in_array($action,['toggle','timer','archive','update','notify'],true)){
        $result=daily_quicklist_mutate($pdo,$org,$actor,$action,$input,$manager);
        app_audit($pdo,$org,$actor,'daily_quicklist.'.$action,'daily_quicklist_item',$result['id'],null,
            ['status'=>$result['status']??null,'timerMode'=>$input['mode']??null]);
        app_json_response(['ok'=>true]+$result);
    }
    throw new InvalidArgumentException('Unsupported list action.');
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('daily-prep api: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Unable to update the list. Please retry.'],500);
}
