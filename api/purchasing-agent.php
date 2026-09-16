<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/purchasing-agent-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!app_has_permission('purchasing.agent',$user)||!app_has_permission('purchasing.view',$user))app_json_response(['ok'=>false,'message'=>'Purchasing Agent permission required.'],403);
if(!purchasing_ready($pdo))app_json_response(['ok'=>false,'message'=>'Purchasing + Receiving migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET')app_json_response(['ok'=>true,'skill'=>'purchasing.summary','summary'=>purchasing_summary($pdo,$org),'alerts'=>array_slice(purchasing_suggestions($pdo,$org),0,8)]);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}

$input=app_json_input();app_verify_request_csrf($input);
$message=trim((string)($input['message']??''));
$receivingIntent=preg_match('/\b(receiv|receipt|invoice|delivery histor|everything arrived|full shipment)\b/iu',$message)===1;
if($receivingIntent&&!app_has_permission('receiving.view',$user)&&!app_has_permission('receiving.manage',$user))app_json_response(['ok'=>false,'message'=>'Receiving view permission is required for receiving intelligence.'],403);
try{
    $result=purchasing_agent_handle($pdo,$user,$input);
    $pageContext=is_array($input['pageContext']??null)?$input['pageContext']:[];
    app_audit($pdo,$org,$uid,'purchasing.agent_skill_used','agent_node',(string)($result['skill']??'purchasing.unknown'),null,['message'=>mb_substr($message,0,1800,'UTF-8'),'contextModule'=>(string)($pageContext['module']??'')]);
    app_json_response($result);
}catch(PurchasingAgentPermissionException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>'Gelato could not complete that purchasing request.'],500);}
