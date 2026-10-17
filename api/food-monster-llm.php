<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-llm.php';
require_once __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';

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
        ],'defaultProvider'=>'auto','models'=>['anthropic'=>fmllm_model('anthropic'),'openai'=>fmllm_model('openai')]]);
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
    $personMemory=fm9e_llm_memory($pdo,$user,$threadId,$last);
    // FM-G12E: a verified employee's model history must NEVER include the
    // operator's mixed-speaker room transcript. Authorize the person mirror
    // by canonical server evidence, not by browser names or person IDs.
    if(($last['channel']??'')==='voice'&&fm9a_is_super_admin_session($user)){
        $pendingKey='fm9d_pending_'.hash('sha256',$org.'|'.$uid.'|'.$threadId);
        $pending=(array)($_SESSION[$pendingKey]??[]);
        $personId=(int)($pending['personId']??0);
        $personThread=(string)($pending['threadId']??'');
        $verified=$personId>0
            &&($pending['operatorThreadId']??'')===$threadId
            &&(string)($pending['voiceEventId']??'')===(string)($last['voice_event_public_id']??'')
            &&(string)($pending['voiceEventId']??'')!==''
            &&time()-(int)($pending['createdAt']??0)<=120
            &&time()>=(int)($pending['createdAt']??0)
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$personThread)===1;
        $personHistory=[];
        if($verified){
            // Enforces current organization membership before person access.
            fm9d_person_collections($pdo,$user,$personId);
            $personRows=gaw_messages($pdo,$org,$personId,$personThread,12);
            $personHistory=fmllm_history($personRows,12);
        }
        $history=fm12e_isolate_voice_history($history,$personHistory,$last,$verified);
    }
    if(!$history||end($history)['role']!=='user')throw new InvalidArgumentException('A user message is required.');
    try{
        $key=app_decrypt_secret((string)$selected['credential']['encrypted_key'],(string)$selected['credential']['nonce'],(string)$selected['credential']['encryption_method']);
    }catch(Throwable $error){
        error_log('Food Monster credential decrypt failure: '.get_class($error));
        app_json_response(['ok'=>false,'code'=>'credential_decryption','message'=>'Gelato cannot decrypt the saved API key. Re-save it in Gelato LLM Settings and preserve storage/keys during deployment.'],503);
    }
    // Release session lock while the external API responds so unrelated app requests stay responsive.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{$answer=fmllm_exchange($selected['provider'],$key,$history,null,$personMemory);}
    finally{if(function_exists('sodium_memzero'))sodium_memzero($key);}
    app_audit($pdo,$org,$uid,'agent.llm_conversation','agent_conversation',$threadId,null,['provider'=>$selected['provider'],'messageCharacters'=>mb_strlen($message,'UTF-8')]);
    app_json_response(['ok'=>true,'skill'=>'agent.llm.conversation','answer'=>$answer,
        'provider'=>$selected['provider'],'model'=>fmllm_model($selected['provider']),
        'sources'=>[],'data'=>null]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(FmLlmProviderException $e){
    error_log('Food Monster provider failure: '.$e->reason);
    app_json_response(['ok'=>false,'code'=>$e->reason,'message'=>$e->getMessage()],503);
}catch(Throwable $e){
    error_log('Food Monster LLM failure: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Food Monster could not reach the selected AI provider. Check the Gelato LLM settings and try again.'],503);
}
