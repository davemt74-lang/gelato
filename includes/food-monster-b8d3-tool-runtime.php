<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-b8d2-execution-loop.php';
require_once __DIR__.'/food-monster-b8d3-timecard.php';
require_once __DIR__.'/food-monster-b8d4-scheduling.php';
require_once __DIR__.'/food-monster-b8d5-menu-recipes.php';
require_once __DIR__.'/food-monster-b8d6-training.php';
require_once __DIR__.'/food-monster-b8d7-vision.php';
require_once __DIR__.'/food-monster-b8d8-vision-actions.php';
require_once __DIR__.'/food-monster-b8d9-review-governance.php';
require_once __DIR__.'/food-monster-b8d10-approvals.php';
require_once __DIR__.'/food-monster-b8d11-domain-routing.php';
require_once __DIR__.'/food-monster-b8d12-pending-actions.php';
require_once __DIR__.'/food-monster-b8d13-preflight.php';
require_once __DIR__.'/food-monster-b8d13b-task-journal.php';
require_once __DIR__.'/food-monster-b8d13c-step-checkpoints.php';
require_once __DIR__.'/food-monster-b8d13d-task-controls.php';
require_once __DIR__.'/food-monster-b8d14-grounded-fallback.php';

/**
 * FM-B8D3: model-selected tools, executed exclusively by the signed-in
 * Gelato server. Tool responses containing personal or business records are
 * terminal local answers, never round-tripped to the external LLM provider.
 */
function fm8d3_catalogue(array $principal,array $context=[]):array
{
    $base=[
        'get_current_user'=>[
            'description'=>'Identify the person authenticated to the current Gelato website session. Use when asked who is logged in, who am I, or which account is active. No input parameters. The server derives identity; never infer it from browser text or speech.',
            'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            'allowedArguments'=>[],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0,
            'validate'=>static fn(array $args,array $actor):array=>[],
            'execute'=>static function(array $args,array $actor):array{
                $name=trim((string)($actor['display_name']??''))?:'your account';
                return ['ok'=>true,'data'=>[
                    'answer'=>'You are signed in to Gelato as '.$name.'.',
                    'source'=>'Gelato authenticated session',
                ]];
            },
        ],
    ];
    if(app_has_permission('timeclock.self',$principal)||app_has_permission('timeclock.view',$principal)){
        $base['get_my_hours']=[
            'description'=>'Read ACTUAL historical time-clock hours for the signed-in employee, subtract recorded breaks, and return completed hours plus any still-open hours. Use for questions such as my hours last two weeks, how long have I worked, timecard total this week, or total clocked hours for dates. For a request about the past two weeks use period last_14_days, which includes today. For specific dates use period custom and start_date/end_date YYYY-MM-DD. Never provide employee ID; the server binds the authenticated account. Does not calculate payroll wages, salary or other employees hours.',
            'parameters'=>['type'=>'object','properties'=>[
                'period'=>['type'=>'string','enum'=>['last_14_days','this_week','last_week','custom']],
                'start_date'=>['type'=>'string','description'=>'First date for custom range, YYYY-MM-DD'],
                'end_date'=>['type'=>'string','description'=>'Last date for custom range, YYYY-MM-DD'],
            ],'additionalProperties'=>false],
            'allowedArguments'=>['period','start_date','end_date'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>
                app_has_permission('timeclock.self',$actor)||app_has_permission('timeclock.view',$actor),
            'validate'=>static function(array $args,array $actor):array{
                $period=(string)($args['period']??'last_14_days');
                if($period!=='custom')return [];
                $missing=[];
                foreach(['start_date','end_date'] as $name)
                    if(empty($args[$name]))$missing[]=$name;
                return $missing;
            },
            'execute'=>static fn(array $args,array $actor):array=>
                fm8d3_hours_read(app_pdo(),$actor,$args),
        ];
    }
    return array_merge($base,fm8d4_schedule_catalogue($principal),fm8d5_catalogue($principal),fm8d6_training_catalogue($principal,$context),fm8d7_vision_catalogue($principal),fm8d8_vision_catalogue($principal,$context),fm8d9_catalogue($principal),fm8d10_catalogue($principal,$context),fm8d11_catalogue($principal,$context),fm8d12_pending_catalogue($principal,$context));
}
function fm8d3_provider_tools(string $provider,array $catalogue):array
{
    $list=[];
    foreach($catalogue as $name=>$tool){
        if($provider==='openai')$list[]=[
            'type'=>'function','name'=>$name,'description'=>$tool['description'],
            'parameters'=>$tool['parameters'],'strict'=>false];
        else $list[]=[
            'name'=>$name,'description'=>$tool['description'],
            'input_schema'=>$tool['parameters']];
    }
    return $list;
}
function fm8d3_decode_calls(string $provider,array $response):array
{
    $calls=[];
    if($provider==='openai'){
        foreach((array)($response['output']??[]) as $part){
            if(($part['type']??'')!=='function_call')continue;
            $raw=$part['arguments']??null;
            $args=is_string($raw)?json_decode($raw,true):null;
            $calls[]=['name'=>$part['name']??null,'arguments'=>$args,
                'callId'=>$part['call_id']??null];
        }
    }else{
        foreach((array)($response['content']??[]) as $part)
            if(($part['type']??'')==='tool_use')
                $calls[]=['name'=>$part['name']??null,
                    'arguments'=>$part['input']??null,'callId'=>$part['id']??null];
    }
    return $calls;
}
function fm8d3_provider_request(string $provider,string $key,array $payload,?callable $transport=null):array
{
    $endpoint=$provider==='openai'?'https://api.openai.com/v1/responses':
        'https://api.anthropic.com/v1/messages';
    $headers=$provider==='openai'?
        ['Authorization: Bearer '.$key,'Content-Type: application/json']:
        ['x-api-key: '.$key,'anthropic-version: 2023-06-01','Content-Type: application/json'];
    if($transport!==null)$reply=$transport($endpoint,$headers,$payload);
    else{
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is not installed.');
        $curl=curl_init($endpoint);
        if($curl===false)throw new RuntimeException('Could not prepare tool-selection request.');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Gelato-FoodMonster/B8D3',
        ]);
        $body=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $errno=(int)curl_errno($curl);
        curl_close($curl);
        if($errno!==0)throw fmllm_provider_failure($provider,0,$errno);
        if(!is_string($body)||$status<200||$status>=300)
            throw fmllm_provider_failure($provider,$status);
        $reply=json_decode($body,true);
    }
    if(!is_array($reply))throw new RuntimeException('AI tool-selection response is invalid.');
    return $reply;
}
function fm8d3_turn(
    PDO $pdo,array $principal,string $provider,string $key,array $history,string $memory='',
    ?callable $transport=null,?array $catalogueOverride=null,?callable $refreshOverride=null,
    ?callable $auditOverride=null,array $context=[]
):array{
    $provider=fmllm_allowed_provider($provider);
    if($provider==='auto')throw new InvalidArgumentException('Choose configured provider.');
    $catalogue=$catalogueOverride??fm8d3_catalogue($principal,$context);
    $refresh=$refreshOverride??static fn():?array=>fm8d2_refresh_operator($pdo,$principal);
    $prior=fm8d13b_prior($pdo,$principal,$context,$catalogue,$refresh);
    if(($prior['kind']??'')==='response')return $prior['result'];
    $resumePlan=(($prior['kind']??'')==='resume')?$prior['plan']:null;
    // Confirm/cancel is explicit user consent for an existing server proposal,
    // never a phrase-based dispatch of a new business intent.
    if($catalogueOverride===null){
        $confirmed=fm8d4_confirm_pending($pdo,$principal,$history);
        if($confirmed!==null)return $confirmed;
    }
    // Explicit menu overviews and named-item questions must resolve against
    // the tenant's active Menu Manager records before any model delegation.
    // Model tool selection previously missed named items and produced a
    // generic "I can't verify" refusal. No menu records reach the provider.
    $latest=end($history);
    $groundedQuestion=is_array($latest)&&($latest['role']??'')==='user'
        ?trim((string)($latest['content']??'')):'';
    $verifiedRead=($context['channel']??'text')!=='voice'
        ||($context['verifiedSameUser']??false)===true;
    $menuOverview=fmuk_menu_overview_question($groundedQuestion);
    // Catalogue overrides are used by explicitly model-driven tool tests.
    // Production calls have no override and always ground named menu facts.
    $namedMenuItem=$catalogueOverride===null
        &&fmuk_named_menu_item_question($groundedQuestion);
    if($verifiedRead&&$groundedQuestion!==''&&($menuOverview||$namedMenuItem)){
        $grounded=fmuk_answer($pdo,$principal,$groundedQuestion,
            ($context['channel']??'text')==='voice',
            ($context['verifiedSameUser']??false)===true);
        $grounded['ok']=true;
        $grounded['data']=array_merge((array)($grounded['data']??[]),
            ['toolCalls'=>0,'groundedReadFallback'=>true,
                'groundedMenuOverview'=>$menuOverview,'groundedNamedMenuItem'=>$namedMenuItem]);
        return $grounded;
    }
    $payload=fmllm_payload($provider,$history,$memory);
    $payload['tools']=fm8d3_provider_tools($provider,$catalogue);
    $payload['tool_choice']=$provider==='openai'?'auto':['type'=>'auto'];
    $today=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d l');
    $instruction=" Today at the restaurant in America/Phoenix is ".$today.". Interpret relative dates such as Friday against that date; when time is missing, ask rather than inventing it. Select an appropriate available Gelato skill for a user’s account, hours, staff schedule, roster, availability, shift creation, menu item ingredient, price, or in-house recipe request. For a named menu item first consult get_menu_item rather than claiming menu data is missing. For generic cooking science questions such as why honey helps pizza dough, answer conversationally without requiring private recipe access. Shift creation selects propose_staff_shift and requires a separate explicit human Confirm before any database change. Do not say live data is unavailable without attempting the authorized tool. Use model-selected get_my_training_progress or run_training_step for the employee's saved training and training dialogue; do not require exact command phrases. Never invent training steps; use recorded procedures only. For multi-part questions, select up to four independent authorized read skills in one tool-selection response; their verified results are combined locally in Agent Chat, never transmitted back to the AI provider. Never batch routine changes with reads or with other writes, never depend on an unread result to choose a write, and use the existing pending proposal confirmation for changes. For questions on Vision Lab, its camera device health, recorded observations, models, mission statuses, and datasets, select the authorized Vision Lab read skill and cite recorded history, never assume live perception. For explicit authorized requests to create a Vision Lab collection mission or an empty DRAFT dataset version, select the corresponding routine creation tool with a clear name/title; do not infer creation from a question. For Vision Lab training-sample review queues, annotation-quality reports and model release candidates, select the authorized read-only review/governance skill. Samples may be pending, disputed, approved or rejected; never claim a review decision was executed. Approval, dataset freeze, model release and device changes require the dedicated Gelato governance workflow. When a manager explicitly requests a Vision Lab training-sample review decision, use request_vision_sample_review to prepare a durable proposal; do not claim a sample was changed until the signed-in user clicks Approve in Agent Chat and the server verifies the original sample is unchanged. The proposal never executes a review. Never activate vision models, modify device configuration, approve samples, or freeze datasets through routine model-selected tools. For requests not covered by a native tool, call delegate_restaurant_domain to choose the best accessible existing Gelato operations agent. Select the domain by intent, not fixed words, and let the destination enforce its permission and confirmation contract. When an existing Gelato Agent has a server-recorded pending proposal, use continue_pending_restaurant_action ONLY for the user's explicit, unambiguous confirmation or cancellation; do not infer consent from a vague acknowledgement. This tool sends a canonical single-use decision to that specific pending Agent and never bypasses its separate approval gate. Do not use generic delegation for confirmation if this native pending tool is available. Follow-ups that ask questions (without approval) should use the same domain. Do not delegate general chat or generic cooking science; answer it directly. Do not invent records.";
    if($provider==='openai')$payload['instructions'].=$instruction;
    else $payload['system'].=$instruction;
    // An interrupted read-only task can resume from its server-owned plan;
    // no new provider selection or hidden observation is required.
    if($resumePlan!==null){
        $calls=array_map(static fn(array $step):array=>[
            'name'=>$step['tool'],'arguments'=>$step['arguments']??[]],$resumePlan);
        $response=[];
    }else{
        $response=fm8d3_provider_request($provider,$key,$payload,$transport);
        $calls=fm8d3_decode_calls($provider,$response);
    }
    if(!$calls){
        // The model may opt to answer in prose despite an explicit request
        // for Gelato's recorded facts. Resolve an authorized, read-only
        // source locally before displaying any ungrounded disclaimer.
        $grounded=fm8d14_no_tool_read($pdo,$principal,$history,$context);
        if($grounded!==null)return $grounded;
        $answer=fmllm_parse($provider,$response);
        if($answer==='')throw new RuntimeException('The AI provider returned no answer.');
        return ['ok'=>true,'skill'=>'agent.llm.conversation',
            'answer'=>mb_substr($answer,0,6000,'UTF-8'),
            'sources'=>[],'data'=>['toolCalls'=>0]];
    }
    if(count($calls)>4)return ['ok'=>true,'skill'=>'agent.tool_limit',
        'answer'=>'This request includes too many tool actions. Please split it into smaller steps.',
        'sources'=>[],'data'=>['toolCalls'=>0,'status'=>'budget_exhausted']];
    // Delegation is a single destination handoff: do not mix it with other
    // operations, or one user turn might cause multiple downstream effects.
    if(count($calls)>1){
        foreach($calls as $selectedCall){
            if(in_array(($selectedCall['name']??''),['delegate_restaurant_domain','continue_pending_restaurant_action'],true))
                return ['ok'=>true,'skill'=>'agent.delegation_needs_separate_turn',
                    'answer'=>'Please make one restaurant-agent request at a time so I can verify its result.',
                    'sources'=>[],'data'=>['status'=>'needs_input','toolCalls'=>0]];
        }
    }
    $plan=[];
    foreach($calls as $call){
        $name=$call['name'];
        if(!is_string($name)||!isset($catalogue[$name])||!is_array($call['arguments']))
            return ['ok'=>true,'skill'=>'agent.invalid_tool',
                'answer'=>'I could not validate the requested skill. Please try again.',
                'sources'=>[],'data'=>['status'=>'denied']];
        $step=['tool'=>$name,'arguments'=>$call['arguments']];
        if(($catalogue[$name]['impact']??'')==='routine'){
            $messageId=(string)($context['messageId']??'');
            if(!preg_match('/^msg-[a-f0-9]{20}$/D',$messageId))return ['ok'=>true,'skill'=>'agent.missing_canonical_turn','answer'=>'Save this message to Agent Chat before I can update training.','sources'=>[],'data'=>['status'=>'needs_input']];
            $step['idempotencyKey']='fm-b8d6-'.substr(hash('sha256',$messageId.'|'.$name.'|'.count($plan)),0,32);
        }
        $plan[]=$step;
    }
    $org=(int)$principal['organization_id'];$uid=(int)$principal['id'];
    $audit=$auditOverride??static function(array $event)use($pdo,$org,$uid):void{
        app_audit($pdo,$org,$uid,'agent.model_selected_tool','agent_skill',$event['tool'],null,$event);
    };
    // FM-B8D13A: examine every requested step before any data read or
    // routine effect. Failed plans have executed zero tools.
    $preflight=fm8d13_preflight($plan,$principal,$catalogue,$refresh);
    if($preflight['status']!=='ready'){
        $explanation=match($preflight['status']){
            'needs_input'=>$preflight['reason'],
            'approval_required'=>'This operation needs the existing verified Gelato approval workflow.',
            'budget_exhausted'=>'This request has too many steps. Please split it into smaller tasks.',
            default=>'The proposed task could not be authorized. No action was executed.',
        };
        return ['ok'=>true,'skill'=>'agent.plan_preflight_stopped',
            'answer'=>$explanation,'sources'=>[],
            'data'=>['status'=>$preflight['status'],'steps'=>0,
                'planned'=>$preflight['planned'],'blockedAt'=>$preflight['blockedAt']]];
    }
    // FM-B8D13B: one persistent lease per canonical signed-in chat turn.
    // A failed/uncertain write can never be replayed as a fresh effect.
    $claim=fm8d13b_claim($pdo,$principal,$context,$plan,$catalogue);
    if(($claim['kind']??'')==='response')return $claim['result'];
    $save=static function(array $response,int $completed)use($pdo,$claim,$plan):array{
        if(($claim['kind']??'')==='acquired'
            &&!fm8d13b_finish($pdo,$claim['identity'],$claim['lease'],
                $response,$completed,count($plan)))
            return fm8d13b_stop('The task receipt changed before completion. Verify the outcome before retrying.','execution_unverified');
        return $response;
    };
    // FM-B8D13C: resume exact saved step observations after fresh full
    // preflight; only still-authorized read steps can be skipped.
    $priorTrace=fm8d13c_trace($pdo,$claim,$plan);
    $checkpoint=static function(array $step)use($pdo,$claim,$plan):void{
        fm8d13c_checkpoint($pdo,$claim,$plan,$step);
    };
    $result=fm8d2_run($plan,$principal,$catalogue,[
        'refreshActor'=>$refresh,'audit'=>$audit,'maxSteps'=>4,'maxMs'=>9000,
        'priorTrace'=>$priorTrace,'onStep'=>$checkpoint,
        'shouldStop'=>static fn():bool=>fm8d13d_should_stop($pdo,$claim),
    ]);
    if($result['status']!=='completed'){
        $stop=match($result['status']){
            'needs_input'=>'I need one more detail to complete this request: '.$result['reason'],
            'approval_required'=>'This operation needs the existing Gelato approval workflow.',
            'denied'=>'That information or action is not authorized for this signed-in account.',
            'cancelled'=>'You stopped this task. Completed steps remain in the audit history; no next step was started.',
            default=>'I stopped safely because the requested tool could not be verified.',
        };
        return $save(['ok'=>true,'skill'=>'agent.tool_stopped','answer'=>$stop,
            'sources'=>[],'data'=>['status'=>$result['status'],'steps'=>$result['completed']]],
            (int)$result['completed']);
    }
    $answers=[];$sources=[];$pendingApproval=null;$delegation=null;
    foreach($result['steps'] as $step){
        $obs=$step['observation']??[];
        if(($obs['approval']['type']??'')==='vision_sample_review'
           &&($obs['approval']['state']??'')==='pending')
            $pendingApproval=$obs['approval'];
        if(isset($obs['delegation'])&&is_array($obs['delegation']))
            $delegation=$obs['delegation'];
        $answer=trim((string)($obs['answer']??''));
        if($answer!=='')$answers[]=$answer;
        $source=trim((string)($obs['source']??''));
        if($source!=='')$sources[]=$source;
    }
    if(!$answers)return $save(['ok'=>true,'skill'=>'agent.tool_stopped',
        'answer'=>'The skill completed without a verified answer. I will not invent a result.',
        'sources'=>[],'data'=>['status'=>'unverified']],(int)$result['completed']);
    // Terminal local response: personal timecard facts never go back to OpenAI/Claude.
    $data=['status'=>'completed','toolCalls'=>count($calls),
        'tools'=>array_values(array_map(static fn($c)=>$c['name'],$calls))];
    if($pendingApproval!==null)$data['approval']=$pendingApproval;
    if($delegation!==null)$data['delegation']=$delegation;
    return $save(['ok'=>true,'skill'=>'agent.model_selected_tools',
        'answer'=>implode("\n\n",$answers),
        'sources'=>array_values(array_unique($sources)),
        'data'=>$data],(int)$result['completed']);
}
