<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-llm.php';
require_once __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';
require_once __DIR__.'/../includes/food-monster-g13a-engagement.php';
require_once __DIR__.'/../includes/agent-voice-conversation.php';
require_once __DIR__.'/../includes/agent-private-voice.php';
require_once __DIR__.'/../includes/food-monster-b8d3-tool-runtime.php';
require_once __DIR__.'/../includes/food-monster-menu-context.php';

$user=app_require_auth();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$pdo=app_pdo();
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Run the Gelato Agent Workspace migration.'],503);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'status')==='task_list'){
            $taskThread=(string)($_GET['threadId']??'');
            if(!gaw_thread($pdo,$org,$uid,$taskThread))
                app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
            if(!fm8d13d_ready($pdo))
                app_json_response(['ok'=>false,'message'=>'Run the Gelato B8D13D database upgrade.'],503);
            app_json_response(['ok'=>true,'tasks'=>fm8d13d_list($pdo,$user,$taskThread)]);
        }
        if(($_GET['action']??'status')==='task_status'){
            $taskThread=(string)($_GET['threadId']??'');
            $taskTurn=(string)($_GET['turnId']??'');
            if(!gaw_thread($pdo,$org,$uid,$taskThread))
                app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
            $task=fm8d13d_ready($pdo)
                ?fm8d13d_status($pdo,$user,$taskThread,$taskTurn)
                :fm8d13b_status($pdo,$user,$taskThread,$taskTurn);
            if($task===null)app_json_response(['ok'=>false,'message'=>'Task not found or migration required.'],404);
            app_json_response(['ok'=>true,'task'=>$task]);
        }
        if(($_GET['action']??'status')!=='status')app_json_response(['ok'=>false,'message'=>'Unknown action.'],422);
        $credentials=fmllm_providers($pdo,$org);
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
    if(($in['action']??'')==='task_cancel'){
        $taskThread=(string)($in['threadId']??'');
        $taskTurn=(string)($in['turnId']??'');
        // Authenticate the original conversation owner; a browser-selected
        // user or organization is never accepted for a cancellation.
        if(!gaw_thread($pdo,$org,$uid,$taskThread))
            app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
        $task=fm8d13d_request_cancel($pdo,$user,$taskThread,$taskTurn);
        if($task===null)
            app_json_response(['ok'=>false,'message'=>'Task not found or B8D13D upgrade required.'],404);
        app_audit($pdo,$org,$uid,'agent.task_cancel_requested','agent_task',$taskTurn,null,[
            'state'=>$task['state'],'impact'=>$task['impact'],
            'interrupted'=>$task['interrupted']??false,
        ]);
        app_json_response(['ok'=>true,'task'=>$task]);
    }
    if(($in['action']??'')!=='chat')app_json_response(['ok'=>false,'message'=>'Unknown action.'],422);
    $credentials=fmllm_providers($pdo,$org);
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
    $history=fmllm_history($records,12);
    $personMemory=fm9e_llm_memory($pdo,$user,$threadId,$last);
    $engagement=['mode'=>'ordinary','context'=>'','reply'=>null];
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
        $personRows=[];
        if($verified){
            // Enforces current organization membership before person access.
            fm9d_person_collections($pdo,$user,$personId);
            $personRows=gaw_messages($pdo,$org,$personId,$personThread,12);
            $personHistory=fmllm_history($personRows,12);
        }
        $history=fm12e_isolate_voice_history($history,$personHistory,$last,$verified);
        $engagement=fm13a_engagement_plan($personRows,$last,$threadId,$verified,time());
    }elseif(($last['channel']??'')==='voice'){
        // Non-owner voice also receives no mixed room context by default.
        $history=fm12e_isolate_voice_history($history,[],$last,false);
        $engagement=fm13a_engagement_plan([],$last,$threadId,false,time());
    }
    // An ambiguous unidentified voice turn cannot inherit another employee's
    // question. Ask an on-demand clarification without spending provider tokens.
    if($engagement['mode']==='clarify'){
        app_json_response(['ok'=>true,'skill'=>'agent.participant_clarification',
            'answer'=>$engagement['reply'],'sources'=>[],'data'=>null]);
    }
    if($engagement['mode']==='continue'){
        $personMemory=mb_substr((string)$engagement['context']."\n".$personMemory,0,1100,'UTF-8');
    }
    if(!$history||end($history)['role']!=='user')throw new InvalidArgumentException('A user message is required.');
    // Feed the model only a canonical published item NAME for a real follow-up.
    // The history was already isolated by speaker above; tool results stay redacted.
    $menuContext=fmmc_tenant_hint($pdo,$org,$history);
    if($menuContext!=='')$personMemory=mb_substr($menuContext."\n".$personMemory,0,1100,'UTF-8');
    $selected=fmllm_choose($credentials,$provider);
    if($selected===null)app_json_response(['ok'=>false,'code'=>'provider_not_configured','message'=>'Selected AI provider is not configured. Ask a Gelato administrator to configure an OpenAI or Anthropic API key.'],503);
    fmllm_rate_limit($org,$uid);
    try{
        $key=app_decrypt_secret((string)$selected['credential']['encrypted_key'],(string)$selected['credential']['nonce'],(string)$selected['credential']['encryption_method']);
    }catch(Throwable $error){
        error_log('Food Monster credential decrypt failure: '.get_class($error));
        app_json_response(['ok'=>false,'code'=>'credential_decryption','message'=>'Gelato cannot decrypt the saved API key. Re-save it in Gelato LLM Settings and preserve storage/keys during deployment.'],503);
    }
    // Authentication plus a deliberate, single-turn UI approval is needed
    // for private room-voice tools. Acoustic identity is provisional and can
    // NEVER authorize payroll, scheduling, recipes or employee data.
    $isVoice=($last['channel']??'text')==='voice';
    $allowTools=!$isVoice;
    if($isVoice){
        $privateToken=is_string($in['privateTurnToken']??null)
            ?(string)$in['privateTurnToken']:'';
        $allowTools=gavp_consume($org,$uid,$threadId,
            (string)$last['public_id'],$privateToken);
    }
    $publicVoiceCatalogue=[];
    if(!$allowTools){
        $menuCatalogue=fm8d5_catalogue($user);
        if(isset($menuCatalogue['list_available_menu_items']))
            $publicVoiceCatalogue['list_available_menu_items']=$menuCatalogue['list_available_menu_items'];
        // A room speaker who is not verified as this account also cannot
        // inherit the signed-in owner's personalized LLM memory.
        if(($last['channel']??'')==='voice')$personMemory='';
    }
    // Release session lock while the external API responds.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{
        $context=['threadId'=>$threadId,'messageId'=>(string)$last['public_id'],
            'userQuestion'=>(string)$last['content'],
            'channel'=>(string)($last['channel']??'text'),
            'voiceEventId'=>(string)($last['voice_event_public_id']??''),
            'privateVoiceApproved'=>$allowTools];
        if($allowTools){
            $result=fm8d3_turn($pdo,$user,$selected['provider'],$key,$history,$personMemory,
                context:$context);
        }elseif($publicVoiceCatalogue!==[]){
            $result=fm8d3_turn($pdo,$user,$selected['provider'],$key,$history,$personMemory,
                catalogueOverride:$publicVoiceCatalogue,context:$context);
        }else{
            $result=['ok'=>true,'skill'=>'agent.llm.conversation',
                'answer'=>fmllm_exchange($selected['provider'],$key,$history,null,$personMemory),
                'sources'=>[],'data'=>['toolCalls'=>0,'privateToolsAvailable'=>false]];
        }
    }finally{if(function_exists('sodium_memzero'))sodium_memzero($key);}
    app_audit($pdo,$org,$uid,'agent.llm_conversation','agent_conversation',$threadId,null,[
        'provider'=>$selected['provider'],
        'messageCharacters'=>mb_strlen($message,'UTF-8'),
        'toolCalls'=>(int)($result['data']['toolCalls']??0),
        'privateToolsAvailable'=>$allowTools,
        'voiceIdentityRequired'=>(($last['channel']??'')==='voice'&&!$allowTools),
    ]);
    app_json_response(['ok'=>true,'skill'=>$result['skill']??'agent.llm.conversation',
        'answer'=>$result['answer'],
        'provider'=>$selected['provider'],'model'=>fmllm_model($selected['provider']),
        'sources'=>$result['sources']??[],
        'data'=>array_replace((array)($result['data']??[]),[
            'privateToolsAvailable'=>$allowTools,
            'voiceIdentityRequired'=>(($last['channel']??'')==='voice'&&!$allowTools),
        ])]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(FmLlmProviderException $e){
    error_log('Food Monster provider failure: '.$e->reason);
    app_json_response(['ok'=>false,'code'=>$e->reason,'message'=>$e->getMessage()],503);
}catch(Throwable $e){
    error_log('Food Monster LLM failure: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Food Monster could not reach the selected AI provider. Check the Gelato LLM settings and try again.'],503);
}
