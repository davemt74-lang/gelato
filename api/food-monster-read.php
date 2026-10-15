<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-read-tools.php';
$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$in=app_json_input();app_verify_request_csrf($in);
if(($in['action']??'')!=='ask')app_json_response(['ok'=>false,'message'=>'Unknown read-only action.'],422);
try{
    $message=trim((string)($in['message']??''));$threadId=trim((string)($in['threadId']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2000||$threadId===''||strlen($threadId)>100)
        throw new InvalidArgumentException('Invalid read-only Agent Chat request.');
    $intent=fm_read_intent($message);
    if(!$intent)throw new InvalidArgumentException('This question is not a supported read-only Agent skill.');
    if(!fm_read_allowed($user,$intent))app_json_response(['ok'=>false,'message'=>'Permission required for this Gelato read-only skill.'],403);
    $pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if(!gaw_ready($pdo)||!gaw_thread($pdo,$org,$uid,$threadId))app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
    $records=gaw_messages($pdo,$org,$uid,$threadId,1);
    $latest=$records[0]??null;
    if(!$latest||($latest['role']??'')!=='user'||trim((string)($latest['content']??''))!==$message)
        app_json_response(['ok'=>false,'message'=>'Send this request from your authenticated Gelato Agent Chat.'],409);
    $result=fm_read_execute($pdo,$user,$intent,$message);
    app_audit($pdo,$org,$uid,'food_monster.read_tool_used','agent_conversation',$threadId,null,['skill'=>$result['skill'],'intent'=>$intent]);
    app_json_response(['ok'=>true]+$result);
}catch(InvalidArgumentException $error){app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);}
catch(DomainException $error){app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);}
catch(Throwable $error){error_log('Food Monster governed read: '.get_class($error));app_json_response(['ok'=>false,'message'=>'This Gelato data source is temporarily unavailable. No result was invented.'],503);}
