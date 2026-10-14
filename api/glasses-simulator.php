<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-simulator.php';
require_once __DIR__.'/../includes/glasses-employee-runtimes.php';

$user=app_require_auth();
$pdo=app_pdo();

if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_ready($pdo))
    app_json_response(['ok'=>false,'message'=>'Glasses plugin migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        header('Cache-Control: no-store, max-age=0');
        $action=trim((string)($_GET['action']??'devices'));
        if($action==='runtimes'){
            app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token()]+glasses_employee_runtime_catalog($pdo,$user));
        }
        if($action==='work'){
            $device=glasses_simulator_device($pdo,$user,(string)($_GET['devicePublicId']??''),false);
            $runtimePublicId=trim((string)($_GET['runtimePublicId']??''));
            if($runtimePublicId!=='')glasses_employee_runtime_assert_binding($pdo,$user,$runtimePublicId,$device);
            app_json_response([
                'ok'=>true,
                'work'=>glasses_simulator_work_with_notes($pdo,$device),
                'sync'=>[
                    'serverTime'=>gmdate('c'),
                    'devicePublicId'=>(string)$device['public_id'],
                ],
            ]);
        }
        app_json_response([
            'ok'=>true,
            'csrfToken'=>app_csrf_token(),
            'devices'=>glasses_simulator_devices($pdo,$user),
            'permissions'=>[
                'view'=>true,
                'liveWrite'=>glasses_simulator_can_write($user),
            ],
            'hardwareRuntime'=>glasses_hardware_runtime_contract('simulator.v1'),
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=trim((string)($in['action']??''));
    if($action==='runtime.create'){
        $result=['runtime'=>glasses_employee_runtime_create($pdo,$user,(string)($in['devicePublicId']??''),(int)($in['employeeUserId']??0),(string)($in['stationPublicId']??''))];
    }elseif($action==='runtime.employee_update'){
        $result=['runtime'=>glasses_employee_runtime_employee_update($pdo,$user,
          (string)($in['runtimePublicId']??''),(int)($in['employeeUserId']??0))];
    }elseif($action==='runtime.profile_confirm'){
        $result=['runtime'=>glasses_employee_runtime_profile_confirm($pdo,$user,(string)($in['runtimePublicId']??''))];
    }elseif($action==='runtime.station_update'){
        $result=['runtime'=>glasses_employee_runtime_station_update($pdo,$user,
          (string)($in['runtimePublicId']??''),(string)($in['stationPublicId']??''))];
    }elseif($action==='runtime.end'){
        $result=['runtime'=>glasses_employee_runtime_end($pdo,$user,(string)($in['runtimePublicId']??''))];
    }elseif($action==='runtime.training_start'){
        $result=['session'=>glasses_employee_runtime_training_start($pdo,$user,(string)($in['runtimePublicId']??''),(string)($in['assignmentPublicId']??''))];
    }elseif($action==='runtime.training_end'){
        $result=['session'=>glasses_employee_runtime_training_end($pdo,$user,(string)($in['runtimePublicId']??''),(string)($in['sessionPublicId']??''),(string)($in['status']??''))];
    }elseif($action==='runtime.heartbeat'){
        $result=['runtime'=>glasses_employee_runtime_heartbeat($pdo,$user,(string)($in['runtimePublicId']??''),(string)($in['devicePublicId']??''))];
    }else{
        $result=glasses_simulator_dispatch($pdo,$user,$in);
    }
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-web-glasses-simulator] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Web glasses simulator request could not be completed.'],500);
}
