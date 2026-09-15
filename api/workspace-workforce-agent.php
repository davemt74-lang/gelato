<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-control-core.php';
require_once __DIR__.'/../includes/workspace-workforce-core.php';

$user=app_require_auth();
if(!admin_control_allowed($user))app_json_response(['ok'=>false,'message'=>'Workforce command-center Agent access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);
if((string)($input['action']??'')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported workforce Agent action.'],422);

try{
    $message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>1600)throw new InvalidArgumentException('Enter a workforce question no longer than 1,600 characters.');
    $snapshot=workspace_workforce_snapshot(app_pdo(),$user);
    $result=workspace_workforce_agent_answer($snapshot,$message);
    app_json_response(['ok'=>true,'skill'=>'workspace.workforce','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources']]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Workspace workforce Agent failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Gelato could not load workforce context.'],500);
}
