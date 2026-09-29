<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-validation.php';
require_once __DIR__.'/../includes/glasses-handoff.php';
require_once __DIR__.'/../includes/glasses-calibration.php';
require_once __DIR__.'/../includes/glasses-vision-profiles.php';
require_once __DIR__.'/../includes/glasses-vision-models.php';
require_once __DIR__.'/../includes/glasses-vision-confidence-policy.php';
require_once __DIR__.'/../includes/glasses-vision-active-perception.php';
require_once __DIR__.'/../includes/glasses-vision-scene.php';
require_once __DIR__.'/../includes/glasses-vision-step-recognition.php';
require_once __DIR__.'/../includes/glasses-vision-ingredient-prevention.php';
require_once __DIR__.'/../includes/glasses-vision-quantity-verification.php';
require_once __DIR__.'/../includes/glasses-vision-quality-verification.php';

$pdo=app_pdo();
if(!glasses_ready($pdo))app_json_response(['ok'=>false,'message'=>'Glasses plugin migration is not installed.'],503);

function glasses_device_bearer(): string
{
    $header='';
    if(isset($_SERVER['HTTP_AUTHORIZATION']))$header=(string)$_SERVER['HTTP_AUTHORIZATION'];
    elseif(function_exists('getallheaders')){
        foreach((array)getallheaders() as $name=>$value){
            if(strtolower((string)$name)==='authorization'){$header=(string)$value;break;}
        }
    }
    return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$m)?strtolower($m[1]):'';
}

try{
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    $action=(string)($in['action']??'');

    if($action==='pair'){
        $result=glasses_pair_device($pdo,(string)($in['pairingCode']??''),[
            'hardwareIdentifier'=>$in['hardwareIdentifier']??null,
            'displayName'=>$in['displayName']??'INMO AIR3',
            'platform'=>$in['platform']??'inmo_air3',
            'sdkVersion'=>$in['sdkVersion']??null,
            'appVersion'=>$in['appVersion']??null,
            'systemVersion'=>$in['systemVersion']??null,
            'capabilities'=>$in['capabilities']??null,
        ]);
        app_json_response(['ok'=>true]+$result,201);
    }

    $token=glasses_device_bearer();
    if($token==='')app_json_response(['ok'=>false,'message'=>'Device authentication required.'],401);
    $device=glasses_authenticate_token($pdo,$token);

    if($action==='heartbeat'){
        $public=glasses_heartbeat($pdo,$device,$in);
        app_json_response(['ok'=>true,'device'=>$public,'serverTime'=>(new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    if($action==='current_work'){
        $work=glasses_current_work($pdo,$device);
        app_json_response(['ok'=>true,'work'=>$work,'serverTime'=>(new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    if($action==='calibration.get'){
        $runtime=[
            'platform'=>(string)($device['platform']??''),
            'frameWidth'=>(int)($in['frameWidth']??0),
            'frameHeight'=>(int)($in['frameHeight']??0),
            'pixelFormat'=>(string)($in['pixelFormat']??''),
        ];
        $calibration=glasses_station_calibration_active(
            $pdo,
            (int)$device['organization_id'],
            (int)$device['location_id'],
            $device['station_id']!==null?(int)$device['station_id']:null,
            $runtime
        );
        app_json_response([
            'ok'=>true,
            'assignmentRequired'=>$device['station_id']===null,
            'calibration'=>$calibration,
        ]);
    }

    if($action==='vision.model_assignment'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $assignment=glasses_vision_model_assignment(
            $pdo,$device,$sessionPublicId,(string)($in['detectorName']??'')
        );
        app_json_response(['ok'=>true,'modelAssignment'=>$assignment]);
    }

    if($action==='vision.model_report'){
        $report=glasses_vision_model_report($pdo,$device,$in);
        app_json_response(['ok'=>true,'modelReport'=>$report]);
    }

    if($action==='vision.canary_sample'){
        $sample=glasses_vision_canary_sample($pdo,$device,$in);
        app_json_response(['ok'=>true,'canarySample'=>$sample]);
    }

    if($action==='vision.drift_sample'){
        $sample=glasses_vision_drift_sample($pdo,$device,$in);
        app_json_response(['ok'=>true,'driftSample'=>$sample]);
    }

    if($action==='vision.confidence_decision'){
        $in['devicePublicId']=(string)$device['public_id'];
        $decision=glasses_vision_confidence_decide($pdo,(int)$device['organization_id'],$in,null);
        app_json_response(['ok'=>true,'confidenceDecision'=>$decision],201);
    }

    if($action==='vision.active_perception.plan'){
        $record=glasses_vision_active_perception_plan($pdo,(int)$device['organization_id'],(string)($in['confidenceDecisionPublicId']??''),$device,null);
        app_json_response(['ok'=>true,'activePerception'=>$record],201);
    }

    if($action==='vision.active_perception.acknowledge'){
        $record=glasses_vision_active_perception_acknowledge($pdo,(int)$device['organization_id'],(string)($in['publicId']??''),$device);
        app_json_response(['ok'=>true,'activePerception'=>$record]);
    }

    if($action==='vision.active_perception.complete'){
        $record=glasses_vision_active_perception_complete($pdo,(int)$device['organization_id'],(string)($in['publicId']??''),$device,(string)($in['outcome']??''),is_array($in['result']??null)?$in['result']:[]);
        app_json_response(['ok'=>true,'activePerception'=>$record]);
    }

    if($action==='vision.scene.capture'){
        $scene=glasses_vision_scene_capture($pdo,$device,$in);
        app_json_response(['ok'=>true,'scene'=>$scene],201);
    }

    if($action==='vision.scene.verify'){
        $scenePublicId=trim((string)($in['publicId']??''));
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],$scenePublicId);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Scene does not belong to this device.');
        app_json_response(['ok'=>true,'verification'=>glasses_vision_scene_verify($pdo,(int)$device['organization_id'],$scenePublicId)]);
    }

    if($action==='vision.step.recognize'){
        $scenePublicId=trim((string)($in['scenePublicId']??''));
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],$scenePublicId);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Scene does not belong to this device.');
        $recognition=glasses_vision_step_recognize($pdo,(int)$device['organization_id'],$scenePublicId);
        app_json_response(['ok'=>true,'stepRecognition'=>$recognition],201);
    }

    if($action==='vision.step.verify'){
        $publicId=trim((string)($in['publicId']??''));
        $recognition=glasses_vision_step_row($pdo,(int)$device['organization_id'],$publicId);
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],(string)$recognition['scenePublicId']);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Step recognition does not belong to this device.');
        app_json_response(['ok'=>true,'verification'=>glasses_vision_step_verify($pdo,(int)$device['organization_id'],$publicId)]);
    }

    if($action==='vision.ingredient_guard.assess'){
        $scenePublicId=trim((string)($in['scenePublicId']??''));
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],$scenePublicId);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Scene does not belong to this device.');
        $assessment=glasses_vision_ingredient_guard_assess($pdo,(int)$device['organization_id'],$scenePublicId);
        app_json_response(['ok'=>true,'ingredientPrevention'=>$assessment],201);
    }

    if($action==='vision.ingredient_guard.verify'){
        $publicId=trim((string)($in['publicId']??''));
        $assessment=glasses_vision_ingredient_guard_row($pdo,(int)$device['organization_id'],$publicId);
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],(string)$assessment['scenePublicId']);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Ingredient-prevention assessment does not belong to this device.');
        app_json_response(['ok'=>true,'verification'=>glasses_vision_ingredient_guard_verify($pdo,(int)$device['organization_id'],$publicId)]);
    }

    if($action==='vision.quantity.verify_scene'){
        $scenePublicId=trim((string)($in['scenePublicId']??''));
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],$scenePublicId);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Scene does not belong to this device.');
        $verification=glasses_vision_quantity_verify_scene($pdo,(int)$device['organization_id'],$scenePublicId);
        app_json_response(['ok'=>true,'quantityVerification'=>$verification],201);
    }

    if($action==='vision.quantity.verify'){
        $publicId=trim((string)($in['publicId']??''));
        $verification=glasses_vision_quantity_row($pdo,(int)$device['organization_id'],$publicId);
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],(string)$verification['scenePublicId']);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Quantity verification does not belong to this device.');
        app_json_response(['ok'=>true,'verification'=>glasses_vision_quantity_verify($pdo,(int)$device['organization_id'],$publicId)]);
    }

    if($action==='vision.quality.verify_scene'){
        $scenePublicId=trim((string)($in['scenePublicId']??''));
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],$scenePublicId);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Scene does not belong to this device.');
        app_json_response(['ok'=>true,'qualityVerification'=>glasses_vision_quality_verify_scene($pdo,(int)$device['organization_id'],$scenePublicId)],201);
    }

    if($action==='vision.quality.verify'){
        $publicId=trim((string)($in['publicId']??''));
        $row=glasses_vision_quality_row($pdo,(int)$device['organization_id'],$publicId);
        $scene=glasses_vision_scene_row($pdo,(int)$device['organization_id'],(string)$row['scenePublicId']);
        if(!hash_equals((string)$scene['devicePublicId'],(string)$device['public_id']))throw new InvalidArgumentException('Quality verification does not belong to this device.');
        app_json_response(['ok'=>true,'verification'=>glasses_vision_quality_verify($pdo,(int)$device['organization_id'],$publicId)]);
    }

    if($action==='vision.profile'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $profile=glasses_vision_profile_for_build(
            $pdo,
            $device,
            $sessionPublicId,
            (string)($in['detectorName']??'')
        );
        app_json_response(['ok'=>true,'visionProfile'=>$profile]);
    }

    if($action==='build.start'){
        $kdsItemPublicId=trim((string)($in['kdsItemPublicId']??''));
        if($kdsItemPublicId==='')throw new InvalidArgumentException('Kitchen item is required.');
        $sourceRevision=trim((string)($in['sourceRevision']??''))?:null;
        if($sourceRevision!==null&&!preg_match('/^[a-f0-9]{64}$/i',$sourceRevision))throw new InvalidArgumentException('Source revision is invalid.');
        $session=glasses_build_start($pdo,$device,$kdsItemPublicId,$sourceRevision);
        app_json_response(['ok'=>true,'buildSession'=>$session],201);
    }

    if($action==='build.get'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $session=glasses_build_session_row($pdo,(int)$device['organization_id'],$sessionPublicId,false);
        glasses_build_assert_device_session($device,$session);
        app_json_response(['ok'=>true,'buildSession'=>glasses_build_payload($pdo,(int)$device['organization_id'],$sessionPublicId)]);
    }

    if($action==='build.observe'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $session=glasses_build_observe($pdo,$device,$sessionPublicId,$in);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
    }

    if($action==='build.confirm'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $componentKey=trim((string)($in['componentKey']??''));
        if($componentKey==='')throw new InvalidArgumentException('Build component is required.');
        $session=glasses_build_confirm($pdo,$device,$sessionPublicId,$componentKey);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
    }

    if($action==='build.resolve_unexpected'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $componentKey=trim((string)($in['componentKey']??''));
        if($componentKey==='')throw new InvalidArgumentException('Unexpected component is required.');
        $session=glasses_build_resolve_unexpected($pdo,$device,$sessionPublicId,$componentKey);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
    }

    if($action==='build.correct_observation'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $result=glasses_build_correct_observation($pdo,$device,$sessionPublicId,$in);
        app_json_response(['ok'=>true]+$result);
    }

    if($action==='build.evidence'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $items=glasses_build_evidence($pdo,$device,$sessionPublicId,(int)($in['limit']??50));
        app_json_response(['ok'=>true,'evidence'=>$items]);
    }

    if($action==='validation.evaluate'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $validation=glasses_validation_evaluate($pdo,$device,$sessionPublicId);
        app_json_response(['ok'=>true,'validation'=>$validation]);
    }

    if($action==='validation.get'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $validation=glasses_validation_get($pdo,$device,$sessionPublicId);
        app_json_response(['ok'=>true,'validation'=>$validation]);
    }

    if($action==='handoff.expo'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        if($sessionPublicId==='')throw new InvalidArgumentException('Build session is required.');
        $handoff=glasses_handoff_to_expo($pdo,$device,$sessionPublicId);
        app_json_response(['ok'=>true,'handoff'=>$handoff]);
    }

    if($action==='self'){
        app_json_response(['ok'=>true,'device'=>glasses_device_public($device)]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported glasses device action.'],422);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-device] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Glasses device request could not be completed.'],500);
}
