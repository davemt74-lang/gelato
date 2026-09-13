<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/sales-cost-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canView=app_has_permission('sales.costs.view',$user)||app_has_permission('sales.view',$user);$canManage=app_has_permission('sales.costs.manage',$user)||app_has_permission('sales.import',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Sales cost permission required.'],403);
if(!sales_cost_ready($pdo))app_json_response(['ok'=>false,'message'=>'Sales Cost Intelligence migration is not installed. Run upgrade.php.'],503);

function sales_cost_api_date(string $value,string $fallback): string {
    $value=trim($value);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:$fallback;
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $today=new DateTimeImmutable('today');$defaultFrom=$today->modify('-29 days')->format('Y-m-d');$from=sales_cost_api_date((string)($_GET['from']??''),$defaultFrom);$to=sales_cost_api_date((string)($_GET['to']??''),$today->format('Y-m-d'));if($from>$to)[$from,$to]=[$to,$from];$locationId=isset($_GET['locationId'])&&$_GET['locationId']!==''?(int)$_GET['locationId']:null;
        $report=sales_cost_report($pdo,$org,$from,$to,$locationId);app_audit($pdo,$org,$uid,'sales.cost_report_viewed','sales_cost_report',$from.'_'.$to,null,['from'=>$from,'to'=>$to,'locationId'=>$locationId]);app_json_response(['ok'=>true,'report'=>$report,'permissions'=>['manage'=>$canManage]]);
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Sales cost management permission required.'],403);$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
        if($action==='profile_save'){$profile=sales_cost_save_profile($pdo,$org,$in,$uid);app_audit($pdo,$org,$uid,'sales.cost_profile_saved','sales_cost_profile',(string)$profile['public_id'],null,['costMethod'=>$profile['cost_method'],'menuItemId'=>$profile['menu_item_id'],'sourceItemKey'=>$profile['source_item_key'],'recipeId'=>$profile['recipe_public_id']??null]);app_json_response(['ok'=>true,'profile'=>$profile]);}
        app_json_response(['ok'=>false,'message'=>'Unsupported sales cost action.'],422);
    }
    header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
