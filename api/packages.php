<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/package-deals-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];

function package_api_require(array $user,string $permission): void
{
    if(!app_has_permission($permission,$user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to complete this package action.'],403);
}

if(!package_deals_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Package Deals is not installed. Run Upgrade first.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    package_api_require($user,'packages.view');
    $action=(string)($_GET['action']??'list');
    if($action==='menu.search'){
        package_api_require($user,'packages.manage');
        app_json_response(['ok'=>true,'items'=>package_deal_menu_search($pdo,$org,(string)($_GET['q']??''))]);
    }
    $includeArchived=!empty($_GET['archived'])&&app_has_permission('packages.manage',$user);
    app_json_response(['ok'=>true,'packages'=>package_deal_list($pdo,$org,$includeArchived),'discountTypes'=>discount_types(),'discountMethods'=>discount_methods()]);
}

if($_SERVER['REQUEST_METHOD']!=='POST') app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
package_api_require($user,'packages.manage');
$payload=[];
try{$payload=json_decode((string)file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);}catch(Throwable){}
if(!is_array($payload))$payload=[];
if(!app_verify_csrf((string)($payload['csrfToken']??$_POST['csrf_token']??''))) app_json_response(['ok'=>false,'message'=>'The request expired. Refresh the page and try again.'],419);
$action=(string)($payload['action']??'');

try{
    if($action==='save'){
        $package=package_deal_save($pdo,$org,trim((string)($payload['id']??''))?:null,is_array($payload['package']??null)?$payload['package']:[],$userId);
        app_json_response(['ok'=>true,'package'=>$package]);
    }
    if($action==='duplicate'){
        $package=package_deal_duplicate($pdo,$org,(string)($payload['id']??''),$userId);
        app_json_response(['ok'=>true,'package'=>$package]);
    }
    if($action==='activate'||$action==='pause'||$action==='resume'||$action==='end'||$action==='archive'){
        $package=package_deal_set_status($pdo,$org,(string)($payload['id']??''),$action,$userId);
        app_json_response(['ok'=>true,'package'=>$package]);
    }
    app_json_response(['ok'=>false,'message'=>'Unknown package action.'],400);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Package API failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The package action could not be completed.'],500);
}
