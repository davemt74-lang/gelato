<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/sales-intelligence-core.php';
require_once __DIR__.'/../includes/sales-pos-connectors.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canView=app_has_permission('sales.view',$user);$canImport=app_has_permission('sales.import',$user);$canAgent=app_has_permission('sales.agent',$user);$canIntegrations=app_has_permission('sales.integrations.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Sales Intelligence permission required.'],403);
if(!sales_intelligence_ready($pdo))app_json_response(['ok'=>false,'message'=>'Sales Intelligence migration is not installed. Run upgrade.php.'],503);
sales_ensure_csv_integration($pdo,$org,$uid);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'dashboard');
    if($action==='dashboard'){
        $to=trim((string)($_GET['to']??date('Y-m-d')));$from=trim((string)($_GET['from']??(new DateTimeImmutable($to))->modify('-27 days')->format('Y-m-d')));$locationId=isset($_GET['locationId'])&&$_GET['locationId']!==''?(int)$_GET['locationId']:null;
        try{$data=sales_dashboard($pdo,$org,$from,$to,$locationId);$data['connectorStatus']=['toast'=>sales_toast_status()];$locations=scheduling_locations($pdo,$org);app_json_response(['ok'=>true,'data'=>$data,'locations'=>$locations,'permissions'=>['view'=>$canView,'import'=>$canImport,'agent'=>$canAgent,'integrations'=>$canIntegrations]]);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    if($action==='forecast'){
        $public=trim((string)($_GET['id']??''));if($public==='')app_json_response(['ok'=>false,'message'=>'Forecast id is required.'],422);$detail=sales_forecast_detail($pdo,$org,$public);if(!$detail)app_json_response(['ok'=>false,'message'=>'Sales forecast not found.'],404);app_json_response(['ok'=>true,'detail'=>$detail]);
    }
    if($action==='integrations')app_json_response(['ok'=>true,'integrations'=>sales_integrations($pdo,$org),'catalog'=>sales_provider_catalog(),'connectorStatus'=>['toast'=>sales_toast_status()],'permissions'=>['manage'=>$canIntegrations]]);
    app_json_response(['ok'=>false,'message'=>'Unsupported sales action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);} 
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
try{
    if($action==='forecast'){
        $date=trim((string)($in['date']??''));$service=(string)($in['service']??'all');$locationId=isset($in['locationId'])&&$in['locationId']!==''?(int)$in['locationId']:null;$weeks=isset($in['weeks'])?(int)$in['weeks']:8;$forecast=sales_forecast($pdo,$org,$date,$service,$locationId,$uid,$weeks);app_audit($pdo,$org,$uid,'sales.forecast_generated','sales_forecast',(string)($forecast['forecast']['public_id']??''),null,['date'=>$date,'service'=>$service,'locationId'=>$locationId,'weeks'=>$weeks]);app_json_response(['ok'=>true,'forecast'=>$forecast,'message'=>'Sales and staffing-capacity forecast generated.']);
    }
    if($action==='integration_save'){
        if(!$canIntegrations)app_json_response(['ok'=>false,'message'=>'Sales integration management permission required.'],403);$row=sales_integration_save($pdo,$org,$in,$uid);app_audit($pdo,$org,$uid,'sales.integration_saved','sales_integration',(string)$row['id'],null,['provider'=>$row['provider'],'locationId'=>$row['location_id'],'isPrimary'=>(bool)$row['is_primary']]);app_json_response(['ok'=>true,'integration'=>$row,'message'=>'Sales integration settings saved.']);
    }
    if($action==='toast_test'){
        if(!$canIntegrations)app_json_response(['ok'=>false,'message'=>'Sales integration management permission required.'],403);$result=sales_toast_connection_test();app_audit($pdo,$org,$uid,'sales.toast_connection_tested','sales_integration','toast',null,['authenticated'=>true,'elapsedMs'=>$result['elapsedMs']]);app_json_response(['ok'=>true,'connection'=>$result,'message'=>'Toast authentication succeeded.']);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported sales action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
