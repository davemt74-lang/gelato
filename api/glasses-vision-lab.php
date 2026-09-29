<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-lab.php';
require_once __DIR__.'/../includes/glasses-vision-active-learning.php';
require_once __DIR__.'/../includes/glasses-vision-dataset-intelligence.php';
require_once __DIR__.'/../includes/glasses-vision-training-media.php';
require_once __DIR__.'/../includes/glasses-vision-curation.php';
require_once __DIR__.'/../includes/glasses-vision-balance.php';
require_once __DIR__.'/../includes/glasses-vision-annotation-qa.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!app_has_permission('glasses.view',$user))app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){$catalog=glasses_vision_lab_catalog($pdo,$user);$catalog['activeLearning']=glasses_vision_active_learning_catalog($pdo,$org);$catalog['datasetIntelligence']=glasses_vision_dataset_intelligence_catalog($pdo,$org);$catalog['trainingMedia']=glasses_vision_training_media_catalog($pdo,$org);$catalog['curation']=glasses_vision_curation_catalog($pdo,$org);$catalog['balance']=glasses_vision_balance_catalog($pdo,$org);$catalog['annotationQa']=glasses_vision_annotation_qa_catalog($pdo,$org);$catalog['trainingRelease']=glasses_vision_training_release_catalog($pdo,$org);app_json_response(['ok'=>true,'catalog'=>$catalog]);}
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
 if(!app_has_permission('glasses.manage',$user))app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
 $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');$actor=(int)$user['id'];
 if($action==='assignment.create')app_json_response(['ok'=>true,'assignment'=>glasses_vision_lab_assign_device($pdo,$org,(string)($in['devicePublicId']??''),(int)($in['userId']??0),(string)($in['role']??'operator'),(string)($in['source']??'manual'),$actor)],201);
 if($action==='assignment.release')app_json_response(['ok'=>true,'assignment'=>glasses_vision_lab_release_device($pdo,$org,(string)($in['publicId']??''),(string)($in['reason']??''),$actor)]);
 if($action==='mission.create')app_json_response(['ok'=>true,'mission'=>glasses_vision_lab_create_mission($pdo,$org,$in,$actor)],201);
 if($action==='sample.queue_observation')app_json_response(['ok'=>true,'sample'=>glasses_vision_lab_queue_observation($pdo,$org,(string)($in['observationKey']??''),!empty($in['missionPublicId'])?(string)$in['missionPublicId']:null,$actor)],201);
 if($action==='sample.import_corrected')app_json_response(['ok'=>true,'result'=>glasses_vision_lab_import_corrected($pdo,$org,!empty($in['missionPublicId'])?(string)$in['missionPublicId']:null,$actor,(int)($in['limit']??500))]);
 if($action==='sample.review'){$sample=glasses_vision_lab_review_sample($pdo,$org,(string)($in['publicId']??''),$in,$actor);$multi=glasses_vision_dataset_intelligence_record_review($pdo,$org,(string)($in['publicId']??''),$actor,(string)($in['decision']??''),isset($in['canonicalLabel'])?(string)$in['canonicalLabel']:null,(string)($in['notes']??''));app_json_response(['ok'=>true,'sample'=>$sample,'reviewConsensus'=>$multi]);}
 if($action==='dataset.create')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_create_dataset($pdo,$org,$in,$actor)],201);
 if($action==='dataset.add_sample')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_add_dataset_sample($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['samplePublicId']??''),(string)($in['split']??'train'))]);
 if($action==='dataset.freeze')app_json_response(['ok'=>true,'dataset'=>glasses_vision_lab_freeze_dataset($pdo,$org,(string)($in['publicId']??''),$actor)]);
 if($action==='active_learning.scan')app_json_response(['ok'=>true,'scan'=>glasses_vision_active_learning_scan($pdo,$org,$actor,(string)($in['triggerSource']??'manual'))]);
 if($action==='active_learning.create_mission')app_json_response(['ok'=>true,'mission'=>glasses_vision_active_learning_create_mission($pdo,$org,(string)($in['publicId']??''),$actor)],201);
 if($action==='active_learning.dismiss')app_json_response(['ok'=>true,'candidate'=>glasses_vision_active_learning_dismiss($pdo,$org,(string)($in['publicId']??''),(string)($in['reason']??''),$actor)]);
 if($action==='dataset_intelligence.analyze')app_json_response(['ok'=>true,'analysis'=>glasses_vision_dataset_intelligence_analyze($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null,$actor,(int)($in['minimumPerClass']??20))]);
 if($action==='dataset_intelligence.plan')app_json_response(['ok'=>true,'plan'=>glasses_vision_dataset_intelligence_collection_plan($pdo,$org,(string)($in['snapshotPublicId']??''),(string)($in['title']??'Vision collection plan'),$actor)],201);
 if($action==='dataset_intelligence.accept_plan')app_json_response(['ok'=>true,'plan'=>glasses_vision_dataset_intelligence_accept_plan($pdo,$org,(string)($in['publicId']??''),$actor)]);
 if($action==='dataset_intelligence.set_split')app_json_response(['ok'=>true,'coverage'=>glasses_vision_dataset_intelligence_set_split($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['samplePublicId']??''),(string)($in['split']??''))]);
 if($action==='dataset_intelligence.consolidate_build_split')app_json_response(['ok'=>true,'result'=>glasses_vision_dataset_intelligence_consolidate_build_split($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['buildPublicId']??''),(string)($in['split']??''))]);
 if($action==='curation.rows')app_json_response(['ok'=>true,'rows'=>glasses_vision_curation_rows($pdo,$org,(string)($in['datasetPublicId']??'')),'plans'=>glasses_vision_curation_split_plans($pdo,$org,(string)($in['datasetPublicId']??''))]);
 if($action==='curation.decide')app_json_response(['ok'=>true,'decision'=>glasses_vision_curation_decide($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['samplePublicId']??''),(string)($in['decision']??''),(string)($in['reason']??''),$actor)]);
 if($action==='curation.plan_split')app_json_response(['ok'=>true,'plan'=>glasses_vision_curation_create_split_plan($pdo,$org,(string)($in['datasetPublicId']??''),$in,$actor)],201);
 if($action==='curation.apply_split')app_json_response(['ok'=>true,'plan'=>glasses_vision_curation_apply_split_plan($pdo,$org,(string)($in['publicId']??''),$actor)]);
 if($action==='balance.analyze')app_json_response(['ok'=>true,'analysis'=>glasses_vision_balance_analyze($pdo,$org,(string)($in['datasetPublicId']??''),$in)]);
 if($action==='annotation_qa.analyze')app_json_response(['ok'=>true,'analysis'=>glasses_vision_annotation_qa_analyze($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null)]);
 if($action==='training_release.build')app_json_response(['ok'=>true,'release'=>glasses_vision_training_release_build($pdo,$org,(string)($in['datasetPublicId']??''),(string)($in['profileKey']??'yolo_detection_v1'),$actor)],201);
 app_json_response(['ok'=>false,'message'=>'Unsupported Vision Lab action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[gelato-vision-lab] '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Vision Lab request could not be completed.'],500);}
