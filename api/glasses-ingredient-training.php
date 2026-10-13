<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-ingredient-training.php';

$user=app_require_auth();
header('Cache-Control: private, no-store, max-age=0');
if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'Glasses access required.'],403);
$pdo=app_pdo();
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $runtime=trim((string)($_GET['runtimePublicId']??''));
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'catalog'=>glasses_ingredient_training_catalog($pdo,$user,$runtime)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    app_verify_request_csrf($in);
    if(($in['action']??null)!=='capture')throw new InvalidArgumentException('Unsupported ingredient training action.');
    app_json_response(['ok'=>true,'capture'=>glasses_ingredient_training_capture($pdo,$user,$in)],201);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[gelato-ingredient-training] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Ingredient training request failed. Check existing Vision Lab migrations and permissions.'],503);
}
