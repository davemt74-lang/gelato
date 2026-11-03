<?php
declare(strict_types=1);
require __DIR__.'/../includes/user-accounts.php';
$user=app_current_user();
if(!$user)app_json_response(['ok'=>false,'message'=>'Sign in to manage accounts.'],401);
$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!accounts_schema_ready($pdo))app_json_response(['ok'=>false,'message'=>'Run upgrade.php before managing accounts.'],503);
try {
    if($_SERVER['REQUEST_METHOD']==='GET') {
        if(($_GET['action']??'')==='options') {
            if(!app_has_permission('users.create',$user)&&!app_has_permission('users.edit',$user))
                throw new DomainException('You do not have permission to manage accounts.');
            app_json_response(['ok'=>true,'assignableRoles'=>accounts_assignable_roles($pdo,$org,$user)]);
        }
        accounts_require($user,'users.view');
        app_json_response(['ok'=>true,'users'=>accounts_list($pdo,$org,(int)$user['id']),
            'assignableRoles'=>accounts_assignable_roles($pdo,$org,$user)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') {
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $input=app_json_input();app_verify_request_csrf($input);
    $action=(string)($input['action']??'');
    if(!in_array($action,['invite','update','profile','status','resend'],true))throw new InvalidArgumentException('Unknown account action.');
    $result=$action==='invite'?accounts_invite($pdo,$user,$input):accounts_update($pdo,$user,$input);
    app_json_response(['ok'=>true]+$result);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    $status=$e instanceof DomainException?403:($e instanceof InvalidArgumentException?422:500);
    if($status===500)error_log('Account lifecycle error: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>$status===500?'The account action could not be completed. Refresh the account list before retrying.':$e->getMessage()],$status);
}
