<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-models.php';
require_once __DIR__.'/../includes/operational-access.php';

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
    $publicId=(string)($in['publicId']??'');
    $managementScope=operational_location_scope($pdo,$user,'glasses.manage');
    $rollout=null;
    if($action==='rollout.create'){
        $locationId=(int)($in['locationId']??0);
        glasses_vision_require_rollout_scope($managementScope,$locationId>0?$locationId:null);
    }elseif(in_array($action,['rollout.shadow_evidence','rollout.activate','rollout.advance','rollout.advance_override','rollout.pause','rollout.rollback'],true)){
        $rollout=glasses_vision_model_rollout_row($pdo,$org,$publicId,false);
        glasses_vision_require_rollout_scope($managementScope,$rollout['location_id']!==null?(int)$rollout['location_id']:null);
    }

    if($action==='package.create'){
        $package=glasses_vision_model_package_create($pdo,$org,$in,(int)$user['id']);
        app_json_response(['ok'=>true,'package'=>$package],201);
    }

    if($action==='package.retire'){
        glasses_vision_require_rollout_scope($managementScope,null);
        $package=glasses_vision_model_package_retire($pdo,$org,(string)($in['publicId']??''),(int)$user['id']);
        app_json_response(['ok'=>true,'package'=>$package]);
    }

    if($action==='rollout.create'){
        $rollout=glasses_vision_model_rollout_create($pdo,$org,$in,(int)$user['id']);
        app_json_response(['ok'=>true,'rollout'=>$rollout],201);
    }

    if(in_array($action,['rollout.shadow_evidence','rollout.activate'],true)){
        if($action==='rollout.shadow_evidence'){
            $gate=glasses_vision_shadow_rollout_gate($pdo,$org,(int)$rollout['id']);
            if(!$gate['eligible'])
                throw new InvalidArgumentException('Complete a passing shadow run with at least 30 detection-bearing frames before reviewing activation.');
            app_json_response(['ok'=>true,'shadowEvidence'=>$gate['summary']]);
        }
        $review=is_array($in['shadowReview']??null)?$in['shadowReview']:[];
        $rollout=glasses_vision_model_rollout_activate($pdo,$org,$publicId,(int)$user['id'],$review);
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

    if(str_starts_with($action,'drift.')){
        $incident=glasses_vision_drift_incident_row($pdo,$org,$publicId);
        glasses_vision_require_rollout_scope($managementScope,(int)$incident['location_id']);
        $incident=glasses_vision_drift_recovery_action(
            $pdo,$org,(string)($in['publicId']??''),substr($action,6),$in,(int)$user['id']
        );
        app_json_response(['ok'=>true,'incident'=>$incident]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported vision model action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-vision-models] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Vision model request could not be completed.'],500);
}
