<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-employee-clock.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$user=app_current_user();
if(!$user||!fm9a_is_super_admin_session($user))
    app_json_response(['ok'=>false,'message'=>'A signed-in Super Admin is required for employee shift clock.'],403);
$pdo=app_pdo();
if(!fm9b_ready($pdo)||!tv_ready($pdo))
    app_json_response(['ok'=>false,'message'=>'Employee shift clock is not installed. Run upgrade.php.'],503);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'roster')!=='roster')
            throw new InvalidArgumentException('Unsupported shift clock action.');
        app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'employees'=>fm9b_roster($pdo,$user)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();app_verify_request_csrf($in);
    $action=(string)($in['action']??'');
    $personId=filter_var($in['personId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($personId===false||$personId===null)throw new InvalidArgumentException('Choose an employee.');
    if($action==='set_pin'){
        fm9b_set_pin($pdo,$user,(int)$personId,(string)($in['pin']??''),(string)($in['ownerPassword']??''));
        app_json_response(['ok'=>true,'message'=>'Employee shift PIN configured.']);
    }
    if(in_array($action,['clock_in','clock_out'],true)){
        $result=fm9b_clock($pdo,$user,(int)$personId,(string)($in['pin']??''),$action);
        app_json_response(['ok'=>true,'result'=>$result,
            'message'=>$result['displayName'].($action==='clock_in'?' clocked in.':' clocked out.')]);
    }
    throw new InvalidArgumentException('Unsupported shift clock action.');
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    error_log('FM-G9B shift clock: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Shift clock is temporarily unavailable.'],503);
}
