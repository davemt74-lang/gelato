<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-lab.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!app_has_permission('glasses.view',$user))app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
try{
 if($_SERVER['REQUEST_METHOD']==='GET')app_json_response(['ok'=>true,'catalog'=>glasses_vision_lab_catalog($pdo,$user)]);
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
 if(!app_has_permission('glasses.manage',$user))app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
 $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');$actor=(int)$user['id'];
 if($action==='assignment.create')app_json_response(['ok'=>true,'assignment'=>glasses_vision_lab_assign_device($pdo,$org,(string)($in['devicePublicId']??''),(int)($in['userId']??0),(string)($in['role']??'operator'),(string)($in['source']??'manual'),$actor)],201);
 if($action==='assignment.release')app_json_response(['ok'=>true,'assignment'=>glasses_vision_lab_release_device($pdo,$org,(string)($in['publicId']??''),(string)($in['reason']??''),$actor)]);
 if($action==='mission.create')app_json_response(['ok'=>true,'mission'=>glasses_vision_lab_create_mission($pdo,$org,$in,$actor)],201);
 if($action==='sample.queue_observation')app_json_response(['ok'=>true,'sample'=>glasses_vision_lab_queue_observation($pdo,$org,(string)($in['observationKey']??''),!empty($in['missionPublicId'])?(string)$in['missionPublicId']:null,$actor)],201);
 if($action==='sample.import_corrected')app_json_response(['ok'=>true,'result'=>glasses_vision_lab_import_corrected($pdo,$org,!empty($in['missionPublicId'])?(string)$in['missionPublicId']:null,$actor,(int)($in['limit']??500))]);
 if($action==='sample.review')app_json_response(['ok'=>true,'sample'=>glasses_vision_lab_review_sample($pdo,$org,(string)($in['publicId']??''),$in,$actor)]);
 if($action==='dataset.create')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_create_dataset($pdo,$org,$in,$actor)],201);
 if($action==='dataset.add_sample')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_add_dataset_sample($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['samplePublicId']??''),(string)($in['split']??'train'))]);
 if($action==='dataset.freeze')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_freeze_dataset($pdo,$org,(string)($in['publicId']??''),$actor)]);
 app_json_response(['ok'=>false,'message'=>'Unsupported Vision Lab action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[gelato-vision-lab] '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Vision Lab request could not be completed.'],500);}
