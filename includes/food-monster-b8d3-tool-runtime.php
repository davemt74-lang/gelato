<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-b8d2-execution-loop.php';
require_once __DIR__.'/food-monster-b8d3-timecard.php';
require_once __DIR__.'/food-monster-b8d4-scheduling.php';
require_once __DIR__.'/food-monster-b8d5-menu-recipes.php';

/**
 * FM-B8D3: model-selected tools, executed exclusively by the signed-in
 * Gelato server. Tool responses containing personal or business records are
 * terminal local answers, never round-tripped to the external LLM provider.
 */
function fm8d3_catalogue(array $principal):array
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
    return array_merge($base,fm8d4_schedule_catalogue($principal),fm8d5_catalogue($principal));
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
    ?callable $auditOverride=null
):array{
    $provider=fmllm_allowed_provider($provider);
    if($provider==='auto')throw new InvalidArgumentException('Choose configured provider.');
    $catalogue=$catalogueOverride??fm8d3_catalogue($principal);
    // Confirm/cancel is explicit user consent for an existing server proposal,
    // never a phrase-based dispatch of a new business intent.
    if($catalogueOverride===null){
        $confirmed=fm8d4_confirm_pending($pdo,$principal,$history);
        if($confirmed!==null)return $confirmed;
    }
    $payload=fmllm_payload($provider,$history,$memory);
    $payload['tools']=fm8d3_provider_tools($provider,$catalogue);
    $payload['tool_choice']=$provider==='openai'?'auto':['type'=>'auto'];
    $today=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d l');
    $instruction=" Today at the restaurant in America/Phoenix is ".$today.". Interpret relative dates such as Friday against that date; when time is missing, ask rather than inventing it. Select an appropriate available Gelato skill for a user’s account, hours, staff schedule, roster, availability, shift creation, menu item ingredient, price, or in-house recipe request. For a named menu item first consult get_menu_item rather than claiming menu data is missing. For generic cooking science questions such as why honey helps pizza dough, answer conversationally without requiring private recipe access. Shift creation selects propose_staff_shift and requires a separate explicit human Confirm before any database change. Do not say live data is unavailable without attempting the authorized tool. Do not invent records or change business data.";
    if($provider==='openai')$payload['instructions'].=$instruction;
    else $payload['system'].=$instruction;
    $response=fm8d3_provider_request($provider,$key,$payload,$transport);
    $calls=fm8d3_decode_calls($provider,$response);
    if(!$calls){
        $answer=fmllm_parse($provider,$response);
        if($answer==='')throw new RuntimeException('The AI provider returned no answer.');
        return ['ok'=>true,'skill'=>'agent.llm.conversation',
            'answer'=>mb_substr($answer,0,6000,'UTF-8'),
            'sources'=>[],'data'=>['toolCalls'=>0]];
    }
    if(count($calls)>4)return ['ok'=>true,'skill'=>'agent.tool_limit',
        'answer'=>'This request includes too many tool actions. Please split it into smaller steps.',
        'sources'=>[],'data'=>['toolCalls'=>0,'status'=>'budget_exhausted']];
    $plan=[];
    foreach($calls as $call){
        $name=$call['name'];
        if(!is_string($name)||!isset($catalogue[$name])||!is_array($call['arguments']))
            return ['ok'=>true,'skill'=>'agent.invalid_tool',
                'answer'=>'I could not validate the requested skill. Please try again.',
                'sources'=>[],'data'=>['status'=>'denied']];
        $plan[]=['tool'=>$name,'arguments'=>$call['arguments']];
    }
    $org=(int)$principal['organization_id'];$uid=(int)$principal['id'];
    $refresh=$refreshOverride??static fn():?array=>fm8d2_refresh_operator($pdo,$principal);
    $audit=$auditOverride??static function(array $event)use($pdo,$org,$uid):void{
        app_audit($pdo,$org,$uid,'agent.model_selected_tool','agent_skill',$event['tool'],null,$event);
    };
    $result=fm8d2_run($plan,$principal,$catalogue,[
        'refreshActor'=>$refresh,'audit'=>$audit,'maxSteps'=>4,'maxMs'=>9000,
    ]);
    if($result['status']!=='completed'){
        $stop=match($result['status']){
            'needs_input'=>'I need one more detail to complete this request: '.$result['reason'],
            'approval_required'=>'This operation needs the existing Gelato approval workflow.',
            'denied'=>'That information or action is not authorized for this signed-in account.',
            default=>'I stopped safely because the requested tool could not be verified.',
        };
        return ['ok'=>true,'skill'=>'agent.tool_stopped','answer'=>$stop,
            'sources'=>[],'data'=>['status'=>$result['status'],'steps'=>$result['completed']]];
    }
    $answers=[];$sources=[];
    foreach($result['steps'] as $step){
        $obs=$step['observation']??[];
        $answer=trim((string)($obs['answer']??''));
        if($answer!=='')$answers[]=$answer;
        $source=trim((string)($obs['source']??''));
        if($source!=='')$sources[]=$source;
    }
    if(!$answers)return ['ok'=>true,'skill'=>'agent.tool_stopped',
        'answer'=>'The skill completed without a verified answer. I will not invent a result.',
        'sources'=>[],'data'=>['status'=>'unverified']];
    // Terminal local response: personal timecard facts never go back to OpenAI/Claude.
    return ['ok'=>true,'skill'=>'agent.model_selected_tools',
        'answer'=>implode("\n\n",$answers),
        'sources'=>array_values(array_unique($sources)),
        'data'=>['status'=>'completed','toolCalls'=>count($calls),
            'tools'=>array_values(array_map(static fn($c)=>$c['name'],$calls))]];
}
