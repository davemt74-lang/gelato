<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/employee-performance-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canView=app_has_permission('employee.performance.view',$user)||app_has_permission('employee.manage',$user)||app_has_permission('staff.manage',$user);
$canManage=app_has_permission('employee.performance.manage',$user)||app_has_permission('employee.manage',$user)||app_has_permission('staff.manage',$user);
$canRecognize=app_has_permission('employee.recognition.manage',$user)||$canManage;
if(!$canView)app_json_response(['ok'=>false,'message'=>'Employee development permission required.'],403);
if(!employee_performance_ready($pdo))app_json_response(['ok'=>false,'message'=>'Employee development migration is not installed. Run upgrade.php.'],503);
function ed_error(Throwable $e): never{$status=$e instanceof InvalidArgumentException?422:500;app_json_response(['ok'=>false,'message'=>$e->getMessage()],$status);}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'team');
    if($action==='team')app_json_response(['ok'=>true,'team'=>employee_home_team($pdo,$org),'permissions'=>['view'=>$canView,'manage'=>$canManage,'recognize'=>$canRecognize]]);
    if($action==='employee'){
        $target=(int)($_GET['userId']??0);$days=(int)($_GET['days']??90);if($target<=0)app_json_response(['ok'=>false,'message'=>'Employee is required.'],422);
        try{$brief=employee_performance_brief($pdo,$org,$target,$days);app_audit($pdo,$org,$uid,'employee.development_viewed','user',(string)$target,null,['days'=>$days]);app_json_response(['ok'=>true,'brief'=>$brief,'permissions'=>['view'=>$canView,'manage'=>$canManage,'recognize'=>$canRecognize]]);}catch(Throwable $e){ed_error($e);}
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported development action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');$target=(int)($in['userId']??0);
try{
    if($action==='coaching_note_save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee development management permission required.'],403);$row=employee_performance_note_save($pdo,$org,$target,$in,$uid);app_audit($pdo,$org,$uid,'employee.coaching_note_saved','employee_coaching_note',(string)$row['public_id'],null,['employeeUserId'=>$target,'noteType'=>$row['note_type']]);app_json_response(['ok'=>true,'note'=>$row,'message'=>'Private coaching note saved.']);
    }
    if($action==='recognition_save'){
        if(!$canRecognize)app_json_response(['ok'=>false,'message'=>'Employee recognition permission required.'],403);$row=employee_performance_recognition_save($pdo,$org,$target,$in,$uid);app_audit($pdo,$org,$uid,'employee.recognition_saved','employee_recognition',(string)$row['public_id'],null,['employeeUserId'=>$target,'visibility'=>$row['visibility']]);app_json_response(['ok'=>true,'recognition'=>$row,'message'=>'Recognition saved.']);
    }
    if($action==='development_goal_save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee development management permission required.'],403);$row=employee_performance_goal_save($pdo,$org,$target,$in,$uid);app_audit($pdo,$org,$uid,'employee.development_goal_saved','employee_development_goal',(string)$row['public_id'],null,['employeeUserId'=>$target,'status'=>$row['status']]);app_json_response(['ok'=>true,'goal'=>$row,'message'=>'Development goal saved.']);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported development action.'],422);
}catch(Throwable $e){ed_error($e);}
