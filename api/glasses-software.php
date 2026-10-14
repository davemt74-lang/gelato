<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-software.php';

$user=app_require_auth();
$pdo=app_pdo();
if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'Glasses view permission required.'],403);
header('Cache-Control: private, no-store, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=trim((string)($_GET['action']??'fleet'));
        if($action==='fleet')
            app_json_response(['ok'=>true]+glasses_software_fleet($pdo,$user));
        if($action==='diagnostics')
            app_json_response(['ok'=>true,'diagnostics'=>glasses_software_diagnostics(
                $pdo,$user,(string)($_GET['devicePublicId']??''))]);
        app_json_response(['ok'=>false,'message'=>'Unsupported glasses software action.'],422);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    app_verify_request_csrf($in);
    if((string)($in['action']??'')!=='policy.save')
        app_json_response(['ok'=>false,'message'=>'Unsupported glasses software action.'],422);
    $policy=glasses_software_policy_save($pdo,$user,
        (string)($in['devicePublicId']??''),
        isset($in['minimumVersion'])?(string)$in['minimumVersion']:null,
        isset($in['targetVersion'])?(string)$in['targetVersion']:null,
        (string)($in['notes']??''),
        (int)($in['expectedRevision']??-1));
    app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],
        'glasses.software_policy_updated','glasses_software_policy',
        (string)($in['devicePublicId']??''),null,
        ['revision'=>$policy['revision']]);
    app_json_response(['ok'=>true,'policy'=>$policy]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[glasses-software] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Glasses software request could not be completed.'],503);
}
