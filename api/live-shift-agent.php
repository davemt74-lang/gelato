<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/live-shift-agent-hardening.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

if(!live_shift_can_view($user))app_json_response(['ok'=>false,'message'=>'Live Shift requires Table Service, POS, or KDS access.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
if((string)($input['action']??'ask')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported Live Shift Agent action.'],422);
$message=trim((string)($input['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>1600)app_json_response(['ok'=>false,'message'=>'Enter a Live Shift request no longer than 1,600 characters.'],422);
$pageContext=is_array($input['pageContext']??null)?$input['pageContext']:[];
try{
    $result=live_shift_hardened_handle($pdo,$user,$pageContext,$message);
    app_audit($pdo,$org,$uid,'agent.live_shift_used','agent_node',(string)($result['node']??'live_shift'),null,['locationId'=>$result['locationId']??null,'module'=>$pageContext['module']??null,'mutation'=>isset($result['check'])||isset($result['result'])||isset($result['customer'])]);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Live Shift Agent failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Live Shift could not complete that request.'],500);}
