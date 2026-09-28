<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-models.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];

if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        app_json_response(['ok'=>true,'catalog'=>glasses_vision_model_catalog($pdo,$user)]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    if(!app_has_permission('glasses.manage',$user))
        app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);

    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');

    if($action==='package.create'){
        $package=glasses_vision_model_package_create($pdo,$org,$in,(int)$user['id']);
        app_json_response(['ok'=>true,'package'=>$package],201);
    }

    if($action==='package.retire'){
        $package=glasses_vision_model_package_retire($pdo,$org,(string)($in['publicId']??''),(int)$user['id']);
        app_json_response(['ok'=>true,'package'=>$package]);
    }

    if($action==='rollout.create'){
        $rollout=glasses_vision_model_rollout_create($pdo,$org,$in,(int)$user['id']);
        app_json_response(['ok'=>true,'rollout'=>$rollout],201);
    }

    if($action==='rollout.activate'){
        $rollout=glasses_vision_model_rollout_activate($pdo,$org,(string)($in['publicId']??''),(int)$user['id']);
        app_json_response(['ok'=>true,'rollout'=>$rollout]);
    }

    if($action==='rollout.advance'){
        $rollout=glasses_vision_model_rollout_advance(
            $pdo,$org,(string)($in['publicId']??''),(float)($in['canaryPercent']??-1),(int)$user['id']
        );
        app_json_response(['ok'=>true,'rollout'=>$rollout]);
    }

    if($action==='rollout.advance_override'){
        $rollout=glasses_vision_model_rollout_advance_override(
            $pdo,$org,(string)($in['publicId']??''),(float)($in['canaryPercent']??-1),(int)$user['id'],(string)($in['reason']??'')
        );
        app_json_response(['ok'=>true,'rollout'=>$rollout]);
    }

    if($action==='rollout.pause'){
        $rollout=glasses_vision_model_rollout_pause($pdo,$org,(string)($in['publicId']??''),(int)$user['id']);
        app_json_response(['ok'=>true,'rollout'=>$rollout]);
    }

    if($action==='rollout.rollback'){
        $rollout=glasses_vision_model_rollout_rollback(
            $pdo,$org,(string)($in['publicId']??''),(int)$user['id'],(string)($in['reason']??'')
        );
        app_json_response(['ok'=>true,'rollout'=>$rollout]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported vision model action.'],422);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-vision-models] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Vision model request could not be completed.'],500);
}
