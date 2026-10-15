<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-llm.php';

$user=app_require_auth();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$pdo=app_pdo();
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Run the Gelato Agent Workspace migration.'],503);
try{
    $credentials=fmllm_providers($pdo,$org);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'status')!=='status')app_json_response(['ok'=>false,'message'=>'Unknown action.'],422);
        // Expose configuration status only, never key prefixes, last four or decryptable material.
        app_json_response(['ok'=>true,'providers'=>[
            'anthropic'=>is_array($credentials['anthropic']??null),
            'openai'=>is_array($credentials['openai']??null),
        ],'defaultProvider'=>'auto']);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();app_verify_request_csrf($in);
    if(($in['action']??'')!=='chat')app_json_response(['ok'=>false,'message'=>'Unknown action.'],422);
    $provider=fmllm_allowed_provider((string)($in['provider']??'auto'));
    $threadId=trim((string)($in['threadId']??''));
    $message=trim((string)($in['message']??''));
    if($threadId===''||strlen($threadId)>100||$message===''||mb_strlen($message,'UTF-8')>4500)
        throw new InvalidArgumentException('Enter a valid conversation and a message under 4,500 characters.');
    // Do not trust browser conversation arrays; the canonical per-user thread is authoritative.
    if(!gaw_thread($pdo,$org,$uid,$threadId))app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
    $records=gaw_messages($pdo,$org,$uid,$threadId,14);
    $last=end($records);
    if(!$last||($last['role']??'')!=='user'||trim((string)($last['content']??''))!==$message)
        app_json_response(['ok'=>false,'message'=>'Send this message through the Gelato Agent chat.'],409);
    $selected=fmllm_choose($credentials,$provider);
    if($selected===null)app_json_response(['ok'=>false,'message'=>'Selected AI provider is not configured. Ask a Gelato administrator to configure an OpenAI or Anthropic API key.'],503);
    fmllm_rate_limit($org,$uid);
    $history=fmllm_history($records,12);
    if(!$history||end($history)['role']!=='user')throw new InvalidArgumentException('A user message is required.');
    $key=app_decrypt_secret((string)$selected['credential']['encrypted_key'],(string)$selected['credential']['nonce'],(string)$selected['credential']['encryption_method']);
    // Release session lock while the external API responds so unrelated app requests stay responsive.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{$answer=fmllm_exchange($selected['provider'],$key,$history);}
    finally{if(function_exists('sodium_memzero'))sodium_memzero($key);}
    app_audit($pdo,$org,$uid,'agent.llm_conversation','agent_conversation',$threadId,null,['provider'=>$selected['provider'],'messageCharacters'=>mb_strlen($message,'UTF-8')]);
    app_json_response(['ok'=>true,'skill'=>'agent.llm.conversation','answer'=>$answer,
        'provider'=>$selected['provider'],'model'=>$selected['provider']==='openai'?'gpt-5-mini':'claude-sonnet-4-20250514',
        'sources'=>[],'data'=>null]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Food Monster LLM failure: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Food Monster could not reach the selected AI provider. Check the Gelato LLM settings and try again.'],503);
}
