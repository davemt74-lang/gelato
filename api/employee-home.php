<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/employee-home-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!employee_home_ready($pdo))app_json_response(['ok'=>false,'message'=>'Employee Home migration is not installed. Run upgrade.php.'],503);
function eh_can(array $u,string $p):bool{return app_has_permission($p,$u);}function eh_self_access(array $u):bool{return eh_can($u,'employee.self')||eh_can($u,'schedule.self')||eh_can($u,'timeclock.self')||eh_can($u,'training.self_view')||eh_can($u,'tasks.self')||eh_can($u,'agent.employee_view');}function eh_need(array $u,string $p):void{if(!eh_can($u,$p))app_json_response(['ok'=>false,'message'=>'Permission required: '.$p],403);}function eh_error(Throwable $e):never{$status=$e instanceof InvalidArgumentException?422:($e instanceof RuntimeException?403:500);app_json_response(['ok'=>false,'message'=>$e->getMessage()],$status);}
$canSelf=eh_self_access($user);$canManage=eh_can($user,'employee.manage')||eh_can($user,'staff.manage');if(!$canSelf&&!$canManage)app_json_response(['ok'=>false,'message'=>'Employee Home permission required.'],403);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');
    if($action==='bootstrap'){
        if(!$canSelf&&!$canManage)app_json_response(['ok'=>false,'message'=>'Employee self-service permission required.'],403);
        $dashboard=employee_home_dashboard($pdo,$org,$uid);
        app_json_response(['ok'=>true]+$dashboard+['team'=>$canManage?employee_home_team($pdo,$org):[],'permissions'=>['self'=>$canSelf,'manage'=>$canManage,'announcementsManage'=>eh_can($user,'employee.announcements.manage'),'policiesManage'=>eh_can($user,'employee.policies.manage'),'timeclockSelf'=>eh_can($user,'timeclock.self'),'scheduleSelf'=>eh_can($user,'schedule.self'),'tasksSelf'=>eh_can($user,'tasks.self'),'trainingSelf'=>eh_can($user,'training.self_view')]]);
    }
    if($action==='team'){if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee management permission required.'],403);app_json_response(['ok'=>true,'team'=>employee_home_team($pdo,$org)]);}
    if($action==='employee'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee management permission required.'],403);$target=(int)($_GET['userId']??0);if($target<=0)app_json_response(['ok'=>false,'message'=>'Employee is required.'],422);app_json_response(['ok'=>true,'profile'=>employee_home_profile($pdo,$org,$target),'checklist'=>employee_home_checklist($pdo,$org,$target),'training'=>employee_home_training($pdo,$org,$target),'shifts'=>employee_home_shifts($pdo,$org,$target,21),'clock'=>employee_home_clock($pdo,$org,$target)]);
    }
    if($action==='announcements'){if(!$canSelf&&!$canManage)app_json_response(['ok'=>false,'message'=>'Employee access required.'],403);app_json_response(['ok'=>true,'announcements'=>employee_home_announcements($pdo,$org,$uid,$canManage&&eh_can($user,'employee.announcements.manage'))]);}
    if($action==='policies'){if(!$canSelf&&!$canManage)app_json_response(['ok'=>false,'message'=>'Employee access required.'],403);app_json_response(['ok'=>true,'policies'=>employee_home_policies($pdo,$org,$uid,$canManage&&eh_can($user,'employee.policies.manage'))]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported employee action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
try{
    if($action==='profile_self_save'){
        if(!$canSelf)app_json_response(['ok'=>false,'message'=>'Employee self-service permission required.'],403);$row=employee_home_profile_self_save($pdo,$org,$uid,$in,$uid);app_audit($pdo,$org,$uid,'employee.profile_self_saved','user',(string)$uid,null,['fields'=>['emergency_contact']]);app_json_response(['ok'=>true,'profile'=>$row,'message'=>'Emergency contact saved.']);
    }
    if($action==='profile_manager_save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee management permission required.'],403);$target=(int)($in['userId']??0);$row=employee_home_profile_manager_save($pdo,$org,$target,$in,$uid);app_audit($pdo,$org,$uid,'employee.lifecycle_saved','user',(string)$target,null,['onboardingStatus'=>$row['onboarding_status']??null]);app_json_response(['ok'=>true,'profile'=>$row,'message'=>'Employee lifecycle profile saved.']);
    }
    if($action==='announcement_read'){
        if(!$canSelf)app_json_response(['ok'=>false,'message'=>'Employee self-service permission required.'],403);$id=trim((string)($in['id']??''));employee_home_announcement_read($pdo,$org,$uid,$id);app_json_response(['ok'=>true,'message'=>'Announcement marked read.']);
    }
    if($action==='announcement_save'){
        eh_need($user,'employee.announcements.manage');$row=employee_home_announcement_save($pdo,$org,$in,$uid);app_audit($pdo,$org,$uid,'employee.announcement_saved','employee_announcement',(string)$row['public_id'],null,['status'=>$row['status']]);app_json_response(['ok'=>true,'announcement'=>$row,'message'=>'Announcement saved.']);
    }
    if($action==='announcement_archive'){
        eh_need($user,'employee.announcements.manage');$id=trim((string)($in['id']??''));$q=$pdo->prepare('UPDATE employee_announcements SET archived_at=NOW(6),status=\'archived\',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$uid,$org,$id]);if($q->rowCount()!==1)throw new InvalidArgumentException('Announcement not found.');app_audit($pdo,$org,$uid,'employee.announcement_archived','employee_announcement',$id);app_json_response(['ok'=>true,'message'=>'Announcement archived.']);
    }
    if($action==='policy_acknowledge'){
        if(!$canSelf)app_json_response(['ok'=>false,'message'=>'Employee self-service permission required.'],403);$id=trim((string)($in['id']??''));employee_home_policy_acknowledge($pdo,$org,$uid,$id);app_audit($pdo,$org,$uid,'employee.policy_acknowledged','employee_policy',$id);app_json_response(['ok'=>true,'message'=>'Policy acknowledged.']);
    }
    if($action==='policy_save'){
        eh_need($user,'employee.policies.manage');$row=employee_home_policy_save($pdo,$org,$in,$uid);app_audit($pdo,$org,$uid,'employee.policy_saved','employee_policy',(string)$row['public_id'],null,['version'=>$row['version_label'],'status'=>$row['status']]);app_json_response(['ok'=>true,'policy'=>$row,'message'=>'Policy saved.']);
    }
    if($action==='policy_archive'){
        eh_need($user,'employee.policies.manage');$id=trim((string)($in['id']??''));$q=$pdo->prepare('UPDATE employee_policy_documents SET archived_at=NOW(6),status=\'archived\',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$uid,$org,$id]);if($q->rowCount()!==1)throw new InvalidArgumentException('Policy not found.');app_audit($pdo,$org,$uid,'employee.policy_archived','employee_policy',$id);app_json_response(['ok'=>true,'message'=>'Policy archived.']);
    }
    if($action==='checklist_save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee management permission required.'],403);$row=employee_home_checklist_save($pdo,$org,$in,$uid);app_audit($pdo,$org,$uid,'employee.checklist_saved','employee_checklist',(string)$row['public_id'],null,['employeeUserId'=>(int)$row['user_id'],'phase'=>$row['phase']]);app_json_response(['ok'=>true,'item'=>$row,'message'=>'Checklist item saved.']);
    }
    if($action==='checklist_complete'){
        $id=trim((string)($in['id']??''));employee_home_checklist_complete($pdo,$org,$uid,$id,$uid,$canManage);app_audit($pdo,$org,$uid,'employee.checklist_completed','employee_checklist',$id);app_json_response(['ok'=>true,'message'=>'Checklist item completed.']);
    }
    if($action==='checklist_cancel'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Employee management permission required.'],403);$id=trim((string)($in['id']??''));$q=$pdo->prepare("UPDATE employee_checklist_items SET status='cancelled',updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND archived_at IS NULL");$q->execute([$org,$id]);if($q->rowCount()!==1)throw new InvalidArgumentException('Checklist item not found.');app_audit($pdo,$org,$uid,'employee.checklist_cancelled','employee_checklist',$id);app_json_response(['ok'=>true,'message'=>'Checklist item cancelled.']);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported employee action.'],422);
}catch(Throwable $e){eh_error($e);}
