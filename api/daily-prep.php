<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/daily-quicklist-core.php';
require_once __DIR__ . '/../includes/daily-quicklist-receipts.php';

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
        if($action==='purchasing_board'){
            if(!daily_quicklist_receipt_permission($user))
                app_json_response(['ok'=>false,'message'=>'Manager and Inventory View permissions required.'],403);
            require_once __DIR__.'/../includes/daily-quicklist-purchasing-board.php';
            $date=daily_quicklist_date($_GET['date']??date('Y-m-d'));
            app_json_response(['ok'=>true,'board'=>daily_quicklist_purchasing_board($pdo,$org,$date)]);
        }
        if($action==='receipts'){
            if(!daily_quicklist_receipt_permission($user))
                app_json_response(['ok'=>false,'message'=>'Inventory View and manager permissions required.'],403);
            app_json_response(['ok'=>true,'receipts'=>daily_quicklist_receipt_candidates($pdo,$org,(string)($_GET['id']??'')),
                'progress'=>daily_quicklist_receipt_progress($pdo,$org,(string)($_GET['id']??''))]);
        }
        if($action!=='bootstrap')throw new InvalidArgumentException('Unknown list action.');
        $date=daily_quicklist_date($_GET['date']??date('Y-m-d'));
        $recipes=$canRecipe?daily_quicklist_recipes($pdo,$org):[];
        $items=daily_quicklist_items($pdo,$org,$date);
        $inventory=$canInventory?daily_quicklist_inventory($pdo,$org):[];
        app_json_response([
            'ok'=>true,'date'=>$date,'manager'=>$manager,'canRecipe'=>$canRecipe,'canInventory'=>$canInventory,
            'items'=>$items,
            'recipeScales'=>$canRecipe?daily_quicklist_recipe_projections($items,$recipes):[],
            'suggestions'=>daily_quicklist_menu_suggestions($pdo,$org,$recipes,$date),
            'recipes'=>$recipes,
            'inventory'=>$inventory,
            'stockAlerts'=>$canInventory?daily_quicklist_stock_alerts($inventory,$recipes,$items):[],
            'receiptLinks'=>daily_quicklist_receipt_permission($user)?daily_quicklist_receipt_links($pdo,$org):[],
        ]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $input=app_json_input();app_verify_request_csrf($input);
    $action=(string)($input['action']??'');
    if($action==='reconcile_receipt'){
        if(!daily_quicklist_receipt_permission($user))
            app_json_response(['ok'=>false,'message'=>'Inventory View and manager permissions required.'],403);
        $raw=$input['transactionId']??null;
        if(!is_int($raw)||$raw<1)throw new InvalidArgumentException('Choose a valid saved receipt.');
        $allocated=$input['allocatedQuantity']??null;
        if($allocated!==null&&(!is_int($allocated)&&!is_float($allocated)
            ||!is_finite((float)$allocated)||$allocated<=0))
            throw new InvalidArgumentException('Choose a positive amount from this recorded receipt.');
        $result=daily_quicklist_reconcile_receipt($pdo,$org,$actor,$user,
            (string)($input['id']??''),$raw,null,$allocated===null?null:(float)$allocated);
        app_audit($pdo,$org,$actor,'daily_quicklist.receipt_reconciled','daily_quicklist_item',$result['id'],null,
            ['transactionId'=>$raw,'allocatedQuantity'=>$result['receipt']['quantity']??null]);
        app_json_response(['ok'=>true]+$result);
    }
    if($action==='generate_menu'){
        $result=daily_quicklist_apply_menu_selection($pdo,$org,$actor,
            (string)($input['date']??''),is_array($input['keys']??null)?$input['keys']:[],
            $canRecipe);
        app_audit($pdo,$org,$actor,'daily_quicklist.menu_generated','daily_quicklist_item',null,null,
            ['date'=>$result['date'],'requested'=>$result['requested'],'created'=>count($result['created']),'skipped'=>count($result['skipped'])]);
        app_json_response(['ok'=>true]+$result);
    }
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
    if($action==='update'&&array_key_exists('requestedStockQuantity',$input)
        &&!daily_quicklist_receipt_permission($user))
        app_json_response(['ok'=>false,'message'=>'Inventory View and manager permissions required for a numeric stock target.'],403);
    if(in_array($action,['toggle','timer','archive','update','notify','set_target'],true)){
        $result=daily_quicklist_mutate($pdo,$org,$actor,$action,$input,$manager,$canRecipe);
        app_audit($pdo,$org,$actor,'daily_quicklist.'.$action,'daily_quicklist_item',$result['id'],null,
            ['status'=>$result['status']??null,'timerMode'=>$input['mode']??null,'targetYieldQuantity'=>$action==='set_target'?($input['targetYieldQuantity']??null):null]);
        app_json_response(['ok'=>true]+$result);
    }
    throw new InvalidArgumentException('Unsupported list action.');
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('daily-prep api: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Unable to update the list. Please retry.'],500);
}
