<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-assembly-learning.php';
$user=app_require_auth();
if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$pdo=app_pdo();
try{
    $in=app_json_input();app_verify_request_csrf($in);
    if((string)($in['action']??'')!=='flag_ingredient')
        throw new InvalidArgumentException('Unsupported ingredient feedback request.');
    app_json_response(['ok'=>true,'candidate'=>glasses_assembly_learning_submit($pdo,$user,$in)],201);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){
    error_log('[gelato-assembly-learning] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Unable to queue the ingredient feedback. No model was changed.'],503);
}
