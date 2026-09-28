<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-operations.php';
$user=app_require_auth();
if(!app_has_permission('glasses.view',$user))app_json_response(['ok'=>false,'error'=>'AR glasses permission required.'],403);
try{app_json_response(['ok'=>true,'catalog'=>glasses_vision_ops_catalog(app_pdo(),$user)]);}catch(Throwable $e){error_log('[gelato-vision-operations-api] '.$e->getMessage());app_json_response(['ok'=>false,'error'=>'Vision Operations could not refresh.'],500);}
