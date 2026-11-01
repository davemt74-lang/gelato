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
require_once __DIR__.'/food-monster-b8d17a-capability-truth.php';
require_once __DIR__.'/food-monster-b8d17b-navigation.php';
require_once __DIR__.'/food-monster-b8d17c-record-reads.php';
require_once __DIR__.'/food-monster-b8d12-pending-actions.php';
require_once __DIR__.'/food-monster-b8d18-operations-proposals.php';
require_once __DIR__.'/food-monster-b8d18-purchasing-proposals.php';
require_once __DIR__.'/food-monster-b8d18-crm-proposals.php';
require_once __DIR__.'/food-monster-b8d18-crm-search.php';
require_once __DIR__.'/food-monster-b8d18-crm-profile-read.php';
require_once __DIR__.'/food-monster-b8d18-crm-archive-proposals.php';
require_once __DIR__.'/food-monster-b8d18-marketing-search.php';
require_once __DIR__.'/food-monster-b8d18-marketing-status-proposals.php';
require_once __DIR__.'/food-monster-b8d18-marketing-feature-proposals.php';
require_once __DIR__.'/food-monster-b8d18-marketing-tagline-proposals.php';
require_once __DIR__.'/food-monster-b8d18-catering-reads.php';
require_once __DIR__.'/food-monster-b8d18-catering-task-proposals.php';
require_once __DIR__.'/food-monster-b8d18-catering-ingredient-status.php';
require_once __DIR__.'/food-monster-b8d18-timeclock-proposals.php';
require_once __DIR__.'/food-monster-b8d18-scheduling-proposals.php';
require_once __DIR__.'/food-monster-b8d18-shift-swap-proposals.php';
require_once __DIR__.'/food-monster-b8d18-schedule-publish-proposals.php';
require_once __DIR__.'/food-monster-b8d18-prep-list-proposals.php';
require_once __DIR__.'/food-monster-b8d18-prep-items-proposals.php';
require_once __DIR__.'/food-monster-b8d18-prep-shortages-read.php';
require_once __DIR__.'/food-monster-b8d18-timeoff-review-proposals.php';
require_once __DIR__.'/food-monster-b8d18-shift-review-proposals.php';
require_once __DIR__.'/food-monster-b8d18-availability-read.php';
require_once __DIR__.'/food-monster-b8d18-availability-proposals.php';
require_once __DIR__.'/food-monster-b8d18-availability-exception-proposals.php';
require_once __DIR__.'/food-monster-b8d18-availability-review-proposals.php';
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
    $base['list_my_gelato_capabilities']=[
        'description'=>'Show what this authenticated user can actually request through registered Agent Chat tools, specialist agent handoffs and prompt-only suggestions. Clearly distinguish registered access from successful execution, proposed skill contracts and private voice approval. Use when asked what tools, skills or Gelato sections the agent can access. No action is performed.',
        'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
        'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>static fn(array $actor,array $args):bool=>
            (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0,
        'validate'=>static fn(array $args,array $actor):array=>[],
        'execute'=>static function(array $args,array $actor)use($context):array{
            $report=fm8d17a_snapshot($actor,$context);
            return ['ok'=>true,'data'=>[
                'answer'=>fm8d17a_summary($report),
                'source'=>'Gelato permission-scoped registered tools and agent handoffs',
                'nativeTools'=>array_column($report['nativeTools'],'id'),
                'agentDomains'=>array_column($report['delegatedAgents'],'id'),
                'capabilityTruth'=>$report,
            ]];
        },
    ];
    return array_merge($base,fm8d17b_catalogue($principal),fm8d17c_catalogue($principal,$context),fm8d4_schedule_catalogue($principal),fm8d5_catalogue($principal),fm8d6_training_catalogue($principal,$context),fm8d7_vision_catalogue($principal),fm8d8_vision_catalogue($principal,$context),fm8d9_catalogue($principal),fm8d10_catalogue($principal,$context),fm8d11_catalogue($principal,$context),fm8d12_pending_catalogue($principal,$context),fm8d18_catalogue($principal),fm8d18p_catalogue($principal),fm8d18d_catalogue($principal),fm8d18f_catalogue($principal,$context),fm8d18g_catalogue($principal,$context),fm8d18h_catalogue($principal,$context),fm8d18i_catalogue($principal,$context),fm8d18j2a_catalogue($principal,$context),fm8d18j2b_catalogue($principal,$context),fm8d18j2c_catalogue($principal),fm8d18j3a_catalogue($principal,$context),fm8d18j3b_catalogue($principal,$context),fm8d18j4a_catalogue($principal,$context),fm8d18j4b_catalogue($principal,$context),fm8d18j4c_catalogue($principal,$context),fm8d18j4d_catalogue($principal,$context),fm8d18j5a_catalogue($principal,$context),fm8d18j5b_catalogue($principal,$context),fm8d18j5c_catalogue($principal,$context),fm8d18j6a_catalogue($principal,$context),fm8d18j6b_catalogue($principal,$context),fm8d18j6c_catalogue($principal,$context),fm8d18j6d_catalogue($principal,$context),fm8d18j7a_catalogue($principal,$context),fm8d18j7b_catalogue($principal,$context),fm8d18j7c_catalogue($principal,$context));
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
    $payload=fmllm_payload($provider,$history,$memory);
    $payload['tools']=fm8d3_provider_tools($provider,$catalogue);
    $payload['tool_choice']=$provider==='openai'?'auto':['type'=>'auto'];
    $today=(new DateTimeImmutable('now',new DateTimeZone('America/Phoenix')))->format('Y-m-d l');
    $instruction=" Today at the restaurant in America/Phoenix is ".$today.". Interpret relative dates such as Friday against that date; when time is missing, ask rather than inventing it. Select an appropriate available Gelato skill for a user’s account, hours, staff schedule, roster, availability, shift creation, menu item ingredient, price, or in-house recipe request. For a named menu item first consult get_menu_item rather than claiming menu data is missing. For generic cooking science questions such as why honey helps pizza dough, answer conversationally without requiring private recipe access. Shift creation selects propose_staff_shift and requires a separate explicit human Confirm before any database change. Do not say live data is unavailable without attempting the authorized tool. Use model-selected get_my_training_progress or run_training_step for the employee's saved training and training dialogue; do not require exact command phrases. Never invent training steps; use recorded procedures only. For multi-part questions, select up to four independent authorized read skills in one tool-selection response; their verified results are combined locally in Agent Chat, never transmitted back to the AI provider. Never batch routine changes with reads or with other writes, never depend on an unread result to choose a write, and use the existing pending proposal confirmation for changes. For questions on Vision Lab, its camera device health, recorded observations, models, mission statuses, and datasets, select the authorized Vision Lab read skill and cite recorded history, never assume live perception. For explicit authorized requests to create a Vision Lab collection mission or an empty DRAFT dataset version, select the corresponding routine creation tool with a clear name/title; do not infer creation from a question. For Vision Lab training-sample review queues, annotation-quality reports and model release candidates, select the authorized read-only review/governance skill. Samples may be pending, disputed, approved or rejected; never claim a review decision was executed. Approval, dataset freeze, model release and device changes require the dedicated Gelato governance workflow. When a manager explicitly requests a Vision Lab training-sample review decision, use request_vision_sample_review to prepare a durable proposal; do not claim a sample was changed until the signed-in user clicks Approve in Agent Chat and the server verifies the original sample is unchanged. The proposal never executes a review. Never activate vision models, modify device configuration, approve samples, or freeze datasets through routine model-selected tools. For requests not covered by a native tool, call delegate_restaurant_domain to choose the best accessible existing Gelato operations agent. Select the domain by intent, not fixed words, and let the destination enforce its permission and confirmation contract. When an existing Gelato Agent has a server-recorded pending proposal, use continue_pending_restaurant_action ONLY for the user's explicit, unambiguous confirmation or cancellation; do not infer consent from a vague acknowledgement. This tool sends a canonical single-use decision to that specific pending Agent and never bypasses its separate approval gate. Do not use generic delegation for confirmation if this native pending tool is available. Follow-ups that ask questions (without approval) should use the same domain. Do not delegate general chat or generic cooking science; answer it directly. Do not invent records.";
    $instruction.=' For an explicit inventory receive/use/waste/count request with a specified exact existing item, quantity and saved base unit, select propose_inventory_stock_change. For an explicit list of restaurant prep tasks to add, select propose_prep_tasks. These model-selected native tools prepare only; they never write inventory or tasks until the authenticated user separately confirms the Operations Agent proposal. If an employee assignment is requested, delegate to Operations instead. Do not combine a proposal with other model tools in one request; ask to handle them separately. Never infer a stock adjustment from a question, and do not invent stock units, stock names, quantities, or prep tasks.';
    $instruction.=' For an explicit request to create a NEW inventory catalog record with a known name, base unit and starting quantity, select propose_inventory_item_creation; never mistake receiving stock for creating a new catalog entry. For an explicit status update of one EXACT existing task, select propose_task_status_change with an approved status; if the task requires proof, do not bypass its required workflow. Both tools stage only an Operations proposal and require subsequent confirmed execution.';
    $instruction.=' For an explicit request to DRAFT a purchase order for one exact saved vendor from CURRENT purchasing suggestions, select propose_purchase_order_draft. For an explicit request to submit or cancel one existing exact-numbered PO, select propose_purchase_order_status; submitting still requires the separate Super Admin high-impact purchase approval. For an explicit full-delivery receipt ONLY when the user says every outstanding item was received in full with no exceptions, select propose_purchase_order_full_receipt. These tools ONLY stage the existing Purchasing Agent confirmation proposal; they cannot create, submit, cancel, receive or alter inventory directly. If a user requests custom order lines or partial/damaged receiving, hand off to the Purchasing Agent, where the original workflow decides whether it is supported. Never invent vendor names, PO numbers, received quantities, or user consent; select only one proposal tool in a turn.';
    $instruction.=' For an explicit request to add an internal note to one exact saved active CRM customer, select propose_crm_customer_note. For an explicit request to add or remove one CRM tag for an exact saved active customer, select propose_crm_customer_tag. Both merely stage a confirmation with the existing CRM Agent. If the name is incomplete or ambiguous, ask for the exact saved display name, not a guessed customer identity. CRM notes and tags must NEVER change SMS/email marketing consent, send a message, archive a customer, or expose private customer details through a model response. For other CRM actions use the authorized Customer CRM Agent. Do not combine CRM proposals with any other tools in the same turn.';
    $instruction.=' For CRM customer lookup, select search_crm_customers only with a specific query. For a request about an already selected exact saved CRM profile ID, select read_crm_customer_profile and the single desired section: overview, recent_visits, favorites, notes, tags or consents. Search results do not authorize guessing a profile; ask the user to choose an exact ID before reading history. CRM history and consent status are local verified reads, not permission to contact anyone or change consent.';
    $instruction.=' Only for an EXPLICIT manager request to archive one exact selected CRM profile, select propose_crm_customer_archive with the exact saved public ID and matching full display name. This prepares a pending CRM Agent action and never changes the record until the signed-in user separately Confirms. The Agent checks current manager permission and unchanged active state. Never archive based on a partial name, a search result, or a question; do not confuse archival with changing consent.';
    $instruction.=' For an explicit search of saved package deals or promotions, select search_marketing_packages with a specific promotion name or saved public ID. Read results are scoped to the signed-in restaurant and shown locally. If several packages match, ask the user to select an exact saved package ID before proposing any marketing change. Never claim a promotion was activated, featured, ended or archived merely because it was found.';
    $instruction.=' For an explicit request by an authorized package manager to activate, pause, resume, end or archive one exact saved promotion, select propose_marketing_package_status with the exact saved package public ID, matching name and requested action. This only stages a Marketing Agent Confirm/Cancel proposal; status does not change until a separate verified confirmation. Never infer a status action from lookup or from a question, never change discounts or contact customers, and do not mix marketing proposals with other tools.';
    $instruction.=' For an explicit authorized request to feature or unfeature one exact saved Package Deal on the public Specials experience, select propose_marketing_package_feature with the exact public ID, saved name and strict desired Featured boolean. This creates only a pending Marketing Agent proposal; it cannot publish or change anything until the user separately Confirms and the package version is unchanged. Never infer a Featured change from a marketing question or recommendation.';
    $instruction.=' For an explicit request to change the Gelato public-site tagline to exact supplied plain-text copy, select propose_public_site_tagline only for an authorized public-site editor. It stages a Marketing Agent Confirm/Cancel proposal and never modifies the public website before separate confirmation and a fresh settings version check. Never invent the replacement text, auto-publish from a suggestion, send marketing outreach, or change customer consent.';
    $instruction.=' For a specific catering event or operation lookup, select search_catering_operations. For a previously selected exact saved catering operation public ID, select read_catering_operation with one section: readiness, tasks, ingredients, staff, or menu. Results are tenant-scoped and read locally. Readiness must use the non-persisting mode. Never guess a catering event identity, expose lead contact details, or treat a read as authorization to change tasks, ingredients or staffing.';
    $instruction.=' For an explicit authorized request to add exactly one catering execution task to an exact selected saved catering operation, select propose_catering_task_add. For an explicit status update to one existing catering task, select propose_catering_task_status with the event public ID/title, task numeric ID/title and requested allowed status. Both only stage a pending Catering Agent Confirm/Cancel action; no task is created or changed until separate confirmation and fresh specialist checks. Never invent event identity, task ID, assignment or user consent; do not batch these proposals.';
    $instruction.=' For an explicit authorized request to change one existing catering ingredient requirement status, select propose_catering_ingredient_status with exact saved event ID/title and requirement ID/ingredient name, plus planned, ordered, ready or unavailable. This only stages the Catering Agent Confirm/Cancel action; the specialist checks saved requirement version at confirmation. It does not adjust inventory quantities, rebuild the ingredient list, or modify recipes. Never infer a status change from a question or readiness report.';
    $instruction.=' For an explicit request by the currently signed-in employee to clock themselves in/out or start/end their own break, select propose_my_timeclock_action. This ONLY stages an expiring Time Clock Agent proposal with a separate Confirm; it never records a punch immediately. Spoken requests additionally require a fresh matching voice identity event and one-use private voice approval. Never change another employee clock, infer clock actions from schedule questions, or combine this proposal with another tool.';
    $instruction.=' For an explicit personal time-off request by the currently signed-in employee, select propose_my_time_off_request with exact calendar dates. One day or up to 31 consecutive full days can be requested. Only one day may specify partial-day 24-hour start/end times. It stages a pending Scheduling Agent proposal, not approval or a schedule change, and needs a separate Confirm. Do not infer dates or request time off for someone else. For an explicit personal shift swap, select propose_my_shift_swap with the exact verified shift public ID and verified target staff ID; never invent either. It stages a pending manager-review request after a separate Confirm and does not immediately reassign anyone. For an explicit manager instruction to publish a specified draft schedule, select propose_publish_schedule_week with the exact Monday date; this stages a separate confirmation and cannot publish an empty or changed schedule. Use the Scheduling Agent for managerial request reviews. For an explicit empty dated prep-list creation by an authorized manager, select propose_create_prep_list with exact date and service, then wait for separate Confirm. For explicitly specified ingredients or tasks to add to an existing dated prep list, select propose_add_prep_items with exact date, service, name, quantity, and unit for every item. If list does not exist, ask to create it first. Never silently create an empty list or omit specified items. Do not combine this proposal with another tool. For an explicit read of saved prep shortages, select get_prep_shortages with exact date and service; explain that saved forecast figures are not real-time inventory. For an explicit manager decision about one exact pending time-off request ID, select propose_review_time_off_request and require separate Confirm; never guess the request ID or approve in bulk. For an explicit manager decision about one exact pending shift-release or swap request ID, select propose_review_shift_request and require separate Confirm; approval may change assignments, so never infer consent or IDs. For an explicit signed-in employee question about recurring availability or their pending/approved exception requests, select get_my_availability; this reads saved records only and does not schedule or change any shift. For an explicit signed-in employee request to replace ONE weekday recurring availability with ONE exact time range, select propose_set_my_weekday_availability; this replaces all saved windows on that weekday only after a separate Confirm, preserving other days and assigned shifts. Never infer an unspoken replacement. For a signed-in employee request for a SINGLE dated available/unavailable exception, select propose_my_availability_exception with exact date, full-day flag or start/end times; separate Confirm submits it for manager review, never auto-approval. Use the existing personal time-off request for time off. For an explicit manager approval/rejection of ONE pending available/unavailable exception by exact public ID, select propose_review_availability_exception; require separate Confirm and do not apply to time-off records. For an explicit CRM customer lookup, choose search_crm_customers with a specific supplied query and present exact public IDs if multiple customers match. Never guess the intended profile or disclose contact details.';
    $instruction.=' When the user explicitly asks to open or switch to a Gelato screen, select navigate_to_gelato_page with an authorized page_id. This prepares a verified internal navigation and does not retrieve business records. Do not invent URLs, claim access to unlisted pages, or confuse navigation with permission to perform actions on that page. To answer a genuine business-record question outside the specific native read tools, select read_gelato_records and its authorized topic. The broker reads the current saved user question on the Gelato server; do not invent a different search query or claim a result without executing the tool.';
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)
        $instruction.=' This is an unverified room-voice turn. Only the published menu read tool is offered intentionally to protect private employee, schedule and restaurant records. The other Gelato tools do exist; they are not missing. If the question needs private data, explain that the logged-in operator must explicitly approve this spoken request in Agent Chat or type the request. Acoustic speaker recognition alone never unlocks private tools. Do not imply private tools are absent, and never guess private facts.';
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
    $cognitiveRetry=false;
    if(!$calls&&$resumePlan===null){
        // A model abstention is not a PHP classifier. Give the same model one
        // final structured decision among authorized READ tools or normal
        // conversation, without reusing the initial prose as a tool grant.
        try{
            $calls=fm8d14_cognitive_read_retry($provider,$key,$payload,$catalogue,$transport);
            $cognitiveRetry=$calls!==[];
        }catch(Throwable $e){
            error_log('Food Monster cognitive read retry failed: '.get_class($e));
        }
    }
    if(!$calls){
        $answer=fmllm_parse($provider,$response);
        if($answer==='')throw new RuntimeException('The AI provider returned no answer.');
        return ['ok'=>true,'skill'=>'agent.llm.conversation',
            'answer'=>mb_substr($answer,0,6000,'UTF-8'),
            'sources'=>[],'data'=>['toolCalls'=>0,'cognitiveRetrySelectedRead'=>false]];
    }
    if(count($calls)>4)return ['ok'=>true,'skill'=>'agent.tool_limit',
        'answer'=>'This request includes too many tool actions. Please split it into smaller steps.',
        'sources'=>[],'data'=>['toolCalls'=>0,'status'=>'budget_exhausted']];
    // Delegation is a single destination handoff: do not mix it with other
    // operations, or one user turn might cause multiple downstream effects.
    if(count($calls)>1){
        foreach($calls as $selectedCall){
            if(in_array(($selectedCall['name']??''),['delegate_restaurant_domain','continue_pending_restaurant_action'],true)
               ||in_array(($selectedCall['name']??''),['propose_inventory_stock_change','propose_prep_tasks','propose_inventory_item_creation','propose_task_status_change'],true)
               ||in_array(($selectedCall['name']??''),['propose_purchase_order_draft','propose_purchase_order_status','propose_purchase_order_full_receipt'],true)
               ||in_array(($selectedCall['name']??''),['propose_crm_customer_note','propose_crm_customer_tag','propose_crm_customer_archive','propose_marketing_package_status','propose_marketing_package_feature','propose_public_site_tagline','propose_catering_task_add','propose_catering_task_status','propose_catering_ingredient_status'],true)
               ||in_array(($selectedCall['name']??''),['propose_my_timeclock_action','propose_my_time_off_request','propose_my_shift_swap','propose_publish_schedule_week','propose_create_prep_list','propose_add_prep_items'],true))
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
    $answers=[];$sources=[];$pendingApproval=null;$delegation=null;$navigation=null;
    foreach($result['steps'] as $step){
        $obs=$step['observation']??[];
        if(($obs['approval']['type']??'')==='vision_sample_review'
           &&($obs['approval']['state']??'')==='pending')
            $pendingApproval=$obs['approval'];
        if(isset($obs['delegation'])&&is_array($obs['delegation']))
            $delegation=$obs['delegation'];
        if(($obs['navigation']['kind']??'')==='verified_internal_page'
           &&is_array($obs['navigation']))$navigation=$obs['navigation'];
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
        'tools'=>array_values(array_map(static fn($c)=>$c['name'],$calls)),
        'cognitiveRetrySelectedRead'=>$cognitiveRetry];
    if($pendingApproval!==null)$data['approval']=$pendingApproval;
    if($delegation!==null)$data['delegation']=$delegation;
    if($navigation!==null)$data['navigation']=$navigation;
    return $save(['ok'=>true,'skill'=>'agent.model_selected_tools',
        'answer'=>implode("\n\n",$answers),
        'sources'=>array_values(array_unique($sources)),
        'data'=>$data],(int)$result['completed']);
}
