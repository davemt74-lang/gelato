<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/agent-voice-conversation.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/admin-control-core.php';
require_once __DIR__.'/../includes/menu-training-knowledge.php';
require_once __DIR__.'/../includes/food-monster-multi-node.php';
require_once __DIR__.'/../includes/food-monster-skill-registry.php';
require_once __DIR__.'/../includes/food-monster-read-tools.php';
require_once __DIR__.'/../includes/food-monster-identity-authority.php';
require_once __DIR__.'/../includes/food-monster-person-conversations.php';
require_once __DIR__.'/../includes/food-monster-room-presence.php';
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';
require_once __DIR__.'/../includes/food-monster-g11d-followups.php';
require_once __DIR__.'/../includes/food-monster-g13e-intelligence.php';
require_once __DIR__.'/../includes/food-monster-g13f-procedure-review.php';
require_once __DIR__.'/../includes/food-monster-g13c-training.php';
require_once __DIR__.'/../includes/food-monster-g13b-knowledge.php';
require_once __DIR__.'/../includes/food-monster-operational-followup.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';

// Compatibility route markers for older CI/contracts. Canonical routing now lives in includes/agent-node-registry.php.
// api/admin-dashboard-agent.php api/daily-manager-agent.php api/sales-cost-agent.php api/sales-agent.php
// api/employee-development-agent.php api/employee-agent.php api/purchasing-agent.php api/scheduling-agent.php api/pos-agent.php
// api/customer-crm-agent.php api/catering-agent.php api/wholesale-agent.php api/prep-intelligence-agent.php api/operations-agent.php api/kds-agent.php api/live-shift-agent.php api/front-of-house-agent.php api/equipment-agent.php api/recipe-agent.php api/online-order-agent.php api/timeclock-agent.php

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Agent Workspace migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');
    if($action==='bootstrap'){
        $threads=gaw_threads($pdo,$org,$uid,40);$active=$threads[0]['public_id']??null;
        if(!$active){$thread=gaw_create_thread($pdo,$org,$uid);$threads=gaw_threads($pdo,$org,$uid,40);$active=$thread['public_id']??null;}
        app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'threads'=>$threads,'activeThread'=>$active,'messages'=>$active?gaw_messages($pdo,$org,$uid,(string)$active,120):[],'user'=>['id'=>$uid,'name'=>$user['display_name'],'firstName'=>$user['first_name'],'role'=>$user['role_slug']],'permissions'=>$user['permissions'],'foodMonsterContext'=>fm9a_context($pdo,$user)]);
    }
    if($action==='food_monster_context')app_json_response(['ok'=>true,'foodMonsterContext'=>fm9a_context($pdo,$user)]);
    if($action==='person_memory'){
        $personId=(int)($_GET['personId']??0);
        app_json_response(['ok'=>true,'memory'=>fm9e_person_memory($pdo,$user,$personId)]);
    }
    if($action==='person_conversations'){
        $personId=(int)($_GET['personId']??0);
        app_json_response(['ok'=>true]+fm9d_person_collections($pdo,$user,$personId));
    }
    if($action==='person_conversation_page'){
        $personId=(int)($_GET['personId']??0);
        $info=fm9d_person_collections($pdo,$user,$personId);
        $threadId=trim((string)($_GET['threadId']??''));
        $allowed=false;
        foreach($info['collections'] as $collection)if($collection['public_id']===$threadId)$allowed=true;
        if(!$allowed)throw new InvalidArgumentException('Person conversation not found.');
        $before=max(0,(int)($_GET['before']??0));
        app_json_response(['ok'=>true]+gaw_history_page($pdo,$org,$personId,$threadId,$before,80));
    }
    if($action==='food_monster_skills'){
        require_once __DIR__.'/../includes/admin-dashboard-core.php';
        app_json_response(['ok'=>true,'skills'=>fm_skill_registry_for_user($user),
            'mode'=>'chat_review','writes'=>'existing_approvals_only']);
    }
    if($action==='thread'){$id=trim((string)($_GET['id']??''));app_json_response(['ok'=>true,'thread'=>gaw_thread($pdo,$org,$uid,$id),'messages'=>gaw_messages($pdo,$org,$uid,$id,220)]);}
    if($action==='threads')app_json_response(['ok'=>true,'threads'=>gaw_threads($pdo,$org,$uid,80)]);
    if($action==='history_threads'){$offset=max(0,(int)($_GET['offset']??0));app_json_response(['ok'=>true]+gaw_history_threads($pdo,$org,$uid,$offset,80));}
    if($action==='history_page'){$id=trim((string)($_GET['id']??''));$before=max(0,(int)($_GET['before']??0));app_json_response(['ok'=>true]+gaw_history_page($pdo,$org,$uid,$id,$before,80));}
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
try{
    if($action==='resolve_person_report'){
        $personId=(int)($in['personId']??0);
        $messageId=trim((string)($in['reportMessageId']??''));
        $result=fm9e_resolve_report($pdo,$user,$personId,$messageId);
        app_json_response(['ok'=>true,'report'=>$result]);
    }
    if($action==='new_thread'){$thread=gaw_create_thread($pdo,$org,$uid,(string)($in['channel']??'text'));app_audit($pdo,$org,$uid,'agent.thread_created','agent_conversation',(string)$thread['public_id']);app_json_response(['ok'=>true,'thread'=>$thread]);}
    if($action==='append'){
        $thread=trim((string)($in['threadId']??''));
        $role=(string)($in['role']??'user');
        // The Agent training ledger uses private system records. A client
        // must never impersonate those records using the public chat API.
        if($role==='system')throw new DomainException('System records are reserved for server-side services.');
        $channel=(string)($in['channel']??'text');
        $voiceEvent=(string)($in['voiceEventId']??'');
        $greeting=null;
        $speaker=null;
        // Speaker event is server evidence, not an employee login or role grant.
        if($role==='user'&&$channel==='voice'){
            $speaker=avc_verified_speaker($pdo,$org,$user,$voiceEvent);
            if($speaker&&(int)$speaker['userId']===$uid)
                $greeting=avc_greeting($pdo,$org,$uid,(string)$speaker['firstName']);
        }
        $personSpeaker=$role==='user'&&$channel==='voice'
            ?fm9d_resolve_speaker($pdo,$user,$voiceEvent):null;
        $pendingKey='fm9d_pending_'.hash('sha256',$org.'|'.$uid.'|'.$thread);
        $pdo->beginTransaction();
        try{
            $message=gaw_append($pdo,$org,$uid,$thread,$role,(string)($in['content']??''),[
                'channel'=>$channel,'skill'=>$in['skill']??null,'tool'=>$in['tool']??null,
                'voiceEventId'=>$voiceEvent,'structured'=>$in['structured']??null,
                'sources'=>is_array($in['sources']??null)?$in['sources']:[]
            ]);
            if($role==='user'){
                // Never carry a previous voice's attribution across a new turn.
                unset($_SESSION[$pendingKey]);
                if($personSpeaker&&(int)$personSpeaker['personId']!==$uid){
                    $_SESSION[$pendingKey]=fm9d_mirror_user_turn(
                        $pdo,$user,$personSpeaker,(string)$in['content'],$thread);
                }
            }elseif($role==='agent'&&isset($_SESSION[$pendingKey])){
                fm9d_mirror_reply($pdo,$user,(array)$_SESSION[$pendingKey],$thread,(string)$in['content']);
                unset($_SESSION[$pendingKey]);
            }
            $pdo->commit();
        }catch(Throwable $error){
            if($pdo->inTransaction())$pdo->rollBack();
            unset($_SESSION[$pendingKey]);
            throw $error;
        }
        // Correlate only a committed user turn with its server-resolved voice.
        // Any unverified, typed, or unknown turn clears the previous speaker.
        // Visual presence never authenticates or authorizes tools.
        $roomSpeaker=$role==='user'
            ?fm11a_room_voice_turn($user,$personSpeaker):null;
        // A real, verified operational report can create a routine follow-up under
        // the authenticated owner's standing delegation. Never execute in a DB
        // transaction or let task failures invalidate the captured conversation.
        $followup=null;
        $reportId=(string)($_SESSION[$pendingKey]['reportMessageId']??'');
        if($role==='user'&&$personSpeaker&&$reportId!==''&&fm9a_agent_action_decision($user,'routine')==='delegated'
            &&app_has_permission('tasks.manage',$user)){
            try{
                $followup=fm9f_create_report_task($pdo,$user,(int)$personSpeaker['personId'],$reportId);
                app_audit($pdo,$org,$uid,'food_monster.report_task_auto_created','agent_message',$reportId,null,
                    ['taskId'=>$followup['taskId'],'alreadyCreated'=>$followup['alreadyCreated']]);
            }catch(Throwable $failure){
                error_log('Food Monster routine task follow-up failed: '.get_class($failure));
                $followup=['status'=>'unavailable'];
            }
        }
        $conversationalSuggestion=null;
        if($role==='user'&&$personSpeaker&&(int)$personSpeaker['personId']!==$uid
            &&isset($_SESSION[$pendingKey])){
            try{
                $conversationalSuggestion=fm11d_verified_turn_suggestion(
                    $pdo,$user,(array)$_SESSION[$pendingKey],(string)$in['content']);
            }catch(Throwable $hintError){
                error_log('FM-G11D conversational follow-up unavailable: '.get_class($hintError));
            }
        }
        app_json_response(['ok'=>true,'message'=>$message,'roomSpeaker'=>$roomSpeaker,
            'conversationalSuggestion'=>$conversationalSuggestion,'operationalFollowup'=>$followup,
            'greeting'=>$greeting?['text'=>$greeting,'verified'=>true]:null,
            'participant'=>($personSpeaker&&(int)$personSpeaker['personId']!==$uid)
                ?['personId'=>(int)$personSpeaker['personId'],'identityAssurance'=>'provisional_voice_match']:null]);
    }
    if($action==='route'){
        $message=trim((string)($in['message']??''));if($message==='')throw new InvalidArgumentException('Enter an Agent request.');
        // A room participant's speech cannot borrow the signed-in owner's
        // skill, purchase, CRM, approval, or write authority. Trust the most
        // recent canonical turn and a server-resolved owner voice event only.
        // Do not trust a client-supplied participant, role, or channel flag.
        $roomThread=trim((string)($in['threadId']??''));
        // Full menu-procedure publication requires typed Super Admin consent.
        // No shared-room voice, camera match, or LLM output can approve.
        $reviewIntent=fm13f_intent($message);
        if($reviewIntent!==null
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$roomThread)
            &&gaw_thread($pdo,$org,$uid,$roomThread)){
            $reviewLast=gaw_messages($pdo,$org,$uid,$roomThread,1)[0]??[];
            if(($reviewLast['role']??'')==='user'
                &&trim((string)($reviewLast['content']??''))===$message
                &&fm13f_authorized($user,(string)($reviewLast['channel']??'')))
                app_json_response(['ok'=>true,
                    'route'=>'api/food-monster-procedure-review.php?threadId='.rawurlencode($roomThread),
                    'domain'=>'food_monster_procedure_review','nodeMode'=>'approval_required']);
        }
        $insightIntent=fm13e_intent($message);
        if($insightIntent!==null
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$roomThread)
            &&gaw_thread($pdo,$org,$uid,$roomThread)){
            $insightLast=gaw_messages($pdo,$org,$uid,$roomThread,1)[0]??[];
            if(($insightLast['role']??'')==='user'
                &&trim((string)($insightLast['content']??''))===$message
                &&fm13e_scope($user,$insightIntent,
                    (string)($insightLast['channel']??''),
                    fm9a_is_super_admin_session($user))!==null)
                app_json_response(['ok'=>true,
                    'route'=>'api/food-monster-training-intelligence.php?threadId='.rawurlencode($roomThread),
                    'domain'=>'food_monster_training_intelligence','nodeMode'=>'read_only']);
        }
        $trainingCommand=fm13c_command($message);
        // Training is read-only guidance with person-scoped progress. This
        // route is selected ONLY from exact deliberate training utterances,
        // and the endpoint rechecks the canonical saved turn and speaker.
        if($trainingCommand!==null
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$roomThread)
            &&gaw_thread($pdo,$org,$uid,$roomThread)){
            $trainingLast=gaw_messages($pdo,$org,$uid,$roomThread,1)[0]??[];
            if(($trainingLast['role']??'')==='user'
                &&trim((string)($trainingLast['content']??''))===$message)
                app_json_response(['ok'=>true,
                    'route'=>'api/food-monster-training.php?threadId='.rawurlencode($roomThread),
                    'domain'=>'guided_employee_training','nodeMode'=>'read_only']);
        }
        $knowledgeIntent=fm13b_intent($message);
        if(fm9a_is_super_admin_session($user)
            &&preg_match('/^agent-[a-f0-9]{20}$/D',$roomThread)
            &&gaw_thread($pdo,$org,$uid,$roomThread)){
            require_once __DIR__.'/../includes/food-monster-g13a-engagement.php';
            $recent=gaw_messages($pdo,$org,$uid,$roomThread,1);
            $latest=(array)($recent[0]??[]);
            $ownerMatch=false;
            if(($latest['role']??'')==='user'&&($latest['channel']??'')==='voice'
                &&trim((string)($latest['content']??''))===$message){
                $voiceId=(string)($latest['voice_event_public_id']??'');
                $speaker=$voiceId!==''?avc_verified_speaker($pdo,$org,$user,$voiceId):null;
                $ownerMatch=is_array($speaker)&&(int)($speaker['userId']??0)===$uid;
            }
            if(fm13a_voice_route_mode($latest,$message,$ownerMatch)==='conversation_only'){
                // Food and allergen questions are safe read-only skills only.
                // The endpoint rechecks this exact canonical voice turn and
                // uses staff-facing menu data, never the owner's private tools.
                if($knowledgeIntent!==null)app_json_response(['ok'=>true,
                    'route'=>'api/food-monster-knowledge.php?threadId='.rawurlencode($roomThread),
                    'domain'=>'food_monster_grounded_knowledge','nodeMode'=>'read_only']);
                app_json_response(['ok'=>true,'route'=>null,
                    'domain'=>'participant_conversation','nodeMode'=>'conversation_only']);
            }
        }
        // Explicit staff-facing factual requests use the same read-only
        // evidence layer; mutating requests keep established governed routes.
        if($knowledgeIntent!==null)app_json_response(['ok'=>true,
            'route'=>'api/food-monster-knowledge.php?threadId='.rawurlencode($roomThread),
            'domain'=>'food_monster_grounded_knowledge','nodeMode'=>'read_only']);
        $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
        // Owner-authenticated high-impact staging uses a deterministic grammar,
        // never a model-selected endpoint. Only an owner may approve; an approval
        // records a decision but does not execute the underlying operation.
        $approvalThread=trim((string)($in['threadId']??''));
        if(fm9a_is_super_admin_session($user)&&preg_match('/^Confirm[.!]?$/i',$message)
            &&fm9i_active_grant($user,$approvalThread))
            app_json_response(['ok'=>true,'route'=>'api/food-monster-approvals.php',
                'domain'=>'food_monster_task_archival','node'=>'brain','nodeMode'=>'approved_domain_write']);
        if(fm9a_is_super_admin_session($user)&&preg_match('/^Confirm[.!]?$/i',$message)
            &&fm9h_active_grant($user,$approvalThread))
            app_json_response(['ok'=>true,'route'=>'api/food-monster-approvals.php',
                'domain'=>'food_monster_permission_change','node'=>'brain',
                'nodeMode'=>'approved_domain_write']);
        $approvalIntent=fm9f_approval_chat_intent($message);
        if($approvalIntent&&fm9a_is_super_admin_session($user)){
            $pending=$approvalIntent['action']==='decide'
                ?gac_pending_get('food_monster_high_impact',$org,$uid):null;
            $approvalThread=trim((string)($in['threadId']??''));
            if($approvalIntent['action']==='propose'
                ||($pending&&($pending['payload']['threadId']??'')===$approvalThread))
                app_json_response(['ok'=>true,'route'=>'api/food-monster-approvals.php',
                    'domain'=>'food_monster_high_impact_approval','node'=>'brain','nodeMode'=>'approval_only']);
        }
        // FM-G7B2: server-selected, audited evidence reads; never LLM endpoints.
        $readIntent=fm_read_intent($message);
        if($readIntent!==null)app_json_response(['ok'=>true,'route'=>'api/food-monster-read.php',
            'domain'=>'governed_verified_read','node'=>'brain','nodeMode'=>'read_only']);

        // FM-G7: only explicit read-only multi-domain manager requests use the
        // existing, permission-filtered Brain snapshot; writes stay with node routes.
        if(fm7_requested_domains($text)){
            require_once __DIR__.'/../includes/admin-dashboard-core.php';
            if(admin_dashboard_allowed($user))
                app_json_response(['ok'=>true,'route'=>'api/food-monster-orchestrate.php',
                    'domain'=>'multi_node_read','node'=>'brain','nodeMode'=>'read_orchestrator']);
        }
        $pageContext=is_array($in['pageContext']??null)?$in['pageContext']:[];
        $module=(string)($pageContext['module']??'');
        $isPosContext=$module==='pos'&&app_has_permission('pos.use',$user);
        $isTableServiceContext=$module==='table_service'&&app_has_permission('table_service.view',$user);
        $isHostStandContext=$module==='host_stand'&&app_has_permission('host.view',$user)&&(app_has_permission('table_service.view',$user)||app_has_permission('host.use',$user)||app_has_permission('host.manage',$user));
        $isSchedulingContext=$module==='scheduling'&&app_has_permission('schedule.agent',$user);
        $isPurchasingContext=$module==='purchasing'&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user);
        $isCrmContext=$module==='crm'&&app_has_permission('crm.view',$user);
        $isCateringContext=$module==='catering'&&app_has_permission('catering.view',$user)&&app_has_permission('catering.agent',$user);
        $isWholesaleContext=$module==='wholesale'&&app_has_permission('wholesale.view',$user)&&app_has_permission('wholesale.agent',$user);
        $isPrepContext=$module==='prep'&&app_has_permission('prep.intelligence.view',$user)&&app_has_permission('prep.intelligence.agent',$user);
        $isOperationsContext=$module==='operations'&&((app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user))||(app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user)));
        $isKdsContext=$module==='kds'&&app_has_permission('kds.view',$user);
        $isEquipmentContext=$module==='equipment'&&app_has_permission('equipment.view',$user)&&app_has_permission('agent.equipment_skills',$user);
        $isRecipesContext=$module==='recipes'&&app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user);
        $canOnlineOrders=app_has_permission('online_orders.fulfill',$user)||app_has_permission('order_recovery.view',$user)||app_has_permission('order_recovery.manage',$user)||app_has_permission('order_recovery.refund',$user)||app_has_permission('pos.use',$user)||app_has_permission('pos.manage',$user)||app_has_permission('kds.view',$user)||app_has_permission('crm.view',$user);
        $isOnlineOrdersContext=in_array($module,['online_orders','pickup_fulfillment','order_recovery'],true)&&$canOnlineOrders;
        $isTimeclockContext=$module==='timeclock'&&app_has_permission('timeclock.agent',$user);
        $canLiveShift=app_has_permission('table_service.view',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user);
        $confirmationIntent=preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply|cancel|cancel it|discard|never mind|nevermind|stop)(?:\s+(?:it|that|change|action))?[.!]?$/u',$text)===1;
        if($confirmationIntent){
            $pendingNode=gaw_pending_action_node($org,$uid);
            if($pendingNode==='front_of_house'&&app_has_permission('host.view',$user)&&(app_has_permission('table_service.view',$user)||app_has_permission('host.use',$user)||app_has_permission('host.manage',$user)))app_json_response(['ok'=>true]+gaw_node_route('front_of_house','front_of_house_confirmation'));
            if($pendingNode==='crm'&&app_has_permission('crm.view',$user))app_json_response(['ok'=>true]+gaw_node_route('crm','crm_confirmation'));
            if($pendingNode==='catering'&&app_has_permission('catering.view',$user)&&app_has_permission('catering.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('catering','catering_confirmation'));
            if($pendingNode==='wholesale'&&app_has_permission('wholesale.view',$user)&&app_has_permission('wholesale.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('wholesale','wholesale_confirmation'));
            if($pendingNode==='prep'&&app_has_permission('prep.intelligence.view',$user)&&app_has_permission('prep.intelligence.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('prep','prep_confirmation'));
            if($pendingNode==='operations'&&((app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user))||(app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user))))app_json_response(['ok'=>true]+gaw_node_route('operations','operations_confirmation'));
            if($pendingNode==='purchasing'&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))app_json_response(['ok'=>true]+gaw_node_route('purchasing','purchasing_confirmation'));
            if($pendingNode==='scheduling'&&app_has_permission('schedule.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('scheduling','scheduling_confirmation'));
            if($pendingNode==='equipment'&&app_has_permission('equipment.view',$user)&&app_has_permission('agent.equipment_skills',$user))app_json_response(['ok'=>true]+gaw_node_route('equipment','equipment_confirmation'));
            if($pendingNode==='recipes'&&app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('recipes','recipe_confirmation'));
            if($pendingNode==='online_orders'&&$canOnlineOrders)app_json_response(['ok'=>true]+gaw_node_route('online_orders','online_order_confirmation'));
            if($pendingNode==='timeclock'&&app_has_permission('timeclock.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('timeclock','timeclock_confirmation'));
        }

        if($isHostStandContext){
            $localIntent=preg_match('/\b(this reservation|selected reservation|this waitlist|selected party|this party|this table|selected table|reservation|waitlist|walk[ -]?in|arrival|arrived|no[ -]?show|cancel|confirm|assign|seat|available|availability|ready|clean|dirty|protected|what needs attention|what should we do|what(?:\x27s| is) going on)\b/u',$text)===1;
            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('front_of_house','front_of_house_context'));
        }
        if($isEquipmentContext){
            $localIntent=preg_match('/\b(this equipment|selected equipment|this asset|selected asset|service history|repair history|maintenance history|maintenance|service|repair|warranty|service contact|repair company|status|condition|criticality|out of service|offline|active|next service|last service|replacement|replace|record|log|schedule|what needs attention|what should we do|what(?:\x27s| is) going on)\b/u',$text)===1;
            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('equipment','equipment_context'));
        }
        if($isRecipesContext){
            $localIntent=preg_match('/\b(this recipe|selected recipe|ingredients?|instructions?|method|yield|scale|batch|allergen|allergens|production standards?|prep note|production note|add ingredient|add step|add instruction|active|inactive|what needs attention|what should we do|how do we make|how is this made)\b/u',$text)===1;
            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('recipes','recipe_context'));
        }
        if($isOnlineOrdersContext){
            $localIntent=preg_match('/\b(this order|selected order|this pickup|selected pickup|pickup|ready|readiness|payment due|paid|promise|promised|late|past promise|handed|hand off|handoff|picked up|fulfill|fulfillment|delay|recovery|exception|what needs attention|what should we do|what(?:\x27s| is) going on)\b/u',$text)===1;
            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('online_orders','online_order_context'));
        }

        if($isTimeclockContext){
            $localIntent=preg_match('/\b(this employee|selected employee|my clock|clock status|clocked in|clocked out|break|employee attendance|staff attendance|attendance exceptions?|attendance issues?|late arrivals?|attendance no[ -]?show|no[ -]?show attendance|missing shift|labor variance|labor hours|scheduled labor|actual labor|who is working|who is clocked in|what needs attention|what should we do|what(?:\x27s| is) going on)\b/u',$text)===1;
            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('timeclock','timeclock_context'));
        }

        $liveShiftIntent=preg_match('/\b(live shift|run the shift|shift status|service status|service priorities|floor status|ready food|food up|run food|what needs attention right now|what should (?:i|we) do right now|what is holding up (?:table|bar seat|check|ticket)|move .*\b(?:table|bar seat)\b|transfer .*\b(?:table|bar seat)\b|assign .*\bserver\b|change .*\bserver\b|seat .*\b(?:table|bar seat)\b|send .*\bkitchen\b|send .*\bheld\b|fire (?:drinks|starters|mains|dessert|other)|hold (?:drinks|starters|mains|dessert|other)|attach customer|remove .*\b(?:check|ticket|order)\b|add .*\b(?:check|ticket|order)\b|(?:void|discount|comp|refund) (?:this|the))\b/u',$text)===1;
        if($liveShiftIntent&&$canLiveShift)app_json_response(['ok'=>true]+gaw_node_route('live_shift'));

        $kdsIntent=preg_match('/\b(kds|kitchen display|kitchen tickets?|kitchen orders?|expo|all day (?:count|counts|items?|kitchen)|(?:kitchen|item|items) all day|ready to bump|unrouted kitchen|station load|late kitchen|late tickets?|held tickets?|order history.*kitchen|kitchen.*order history)\b/u',$text)===1;
        if($kdsIntent&&app_has_permission('kds.view',$user))app_json_response(['ok'=>true]+gaw_node_route('kds'));

        if($isCateringContext){
            $localIntent=preg_match('/\b(this event|selected event|this operation|selected operation|readiness|ready|ingredients?|requirements?|shortages?|tasks?|staff|staffing|add .*task|mark .*task|rebuild .*ingredient|regenerate .*ingredient|what needs attention|what(?:\x27s| is) missing|how are we looking)\b/u',$text)===1;
            if($localIntent)app_json_response(['ok'=>true]+gaw_node_route('catering','catering_context'));
        }
        if($isWholesaleContext){
            $localIntent=preg_match('/\b(this order|selected order|this wholesale order|this batch|selected batch|fulfillment|batch|batches|allocation|allocated|unallocated|shortage|shortages|available to promise|\batp\b|create .*batch|mark .*batch .*ready|dispatch .*batch|cancel .*batch|deliver .*batch|status|summary|what(?:\x27s| is) going on)\b/u',$text)===1;
            if($localIntent)app_json_response(['ok'=>true]+gaw_node_route('wholesale','wholesale_context'));
        }

        $cateringIntent=preg_match('/\b(catering operations?|catering event|event readiness|catering readiness|catering tasks?|event tasks?|rebuild .*ingredient|regenerate .*ingredient|ingredient requirements?.*event|event.*ingredient requirements?)\b/u',$text)===1;
        if($cateringIntent&&app_has_permission('catering.view',$user)&&app_has_permission('catering.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('catering'));

        $wholesaleIntent=preg_match('/\b(wholesale fulfillment|fulfillment batch|wholesale allocation|wholesale shortages?|create .*fulfillment batch|dispatch .*batch|deliver .*batch|mark .*batch .*ready|cancel .*batch)\b/u',$text)===1;
        if($wholesaleIntent&&app_has_permission('wholesale.view',$user)&&app_has_permission('wholesale.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('wholesale'));

        $onlineOrderIntent=preg_match('/\b(online orders?|pickup orders?|web orders?|pickup queue|pickup fulfillment|pickup handoff|ready for pickup|past promise|late pickup|pickup promise|order recovery|pickup recovery)\b/u',$text)===1;
        if($onlineOrderIntent&&$canOnlineOrders)app_json_response(['ok'=>true]+gaw_node_route('online_orders'));

        $dashboardIntent=preg_match('/\b(command center|command centre|dashboard|restaurant overview|operating snapshot|operations snapshot|location performance|compare locations?|active tables?|open (?:pos )?(?:tickets?|checks?)|online orders?|what needs attention|what is happening right now|what\x27s happening right now|how is wholesale doing|wholesale (?:status|overview|pipeline|orders?|receivables?|accounts?|sales)|catering (?:status|overview|readiness|events?))\b/u',$text)===1;
        if($dashboardIntent&&admin_control_allowed($user))app_json_response(['ok'=>true]+gaw_node_route('command_center'));

        $managerIntent=preg_match('/\b(gm brief|manager brief|daily brief|opening brief|morning brief|closing brief|manager recap|daily manager|restaurant status|how is (?:the )?restaurant doing|how are we doing today|what needs manager attention)\b/u',$text)===1;
        if($managerIntent&&app_has_permission('manager.brief.view',$user)&&app_has_permission('manager.brief.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('daily_manager','daily_manager_brief'));

        $crmIntent=preg_match('/\b(customer crm|crm customer|customer profile|guest profile|customer history|customer notes?|customer tags?|find customer|look up customer|lifetime spend|customer favorites?|customer favourites?|relationship history)\b/u',$text)===1;
        if($crmIntent&&!$isPosContext&&app_has_permission('crm.view',$user))app_json_response(['ok'=>true]+gaw_node_route('crm'));

        $recipeIntent=preg_match('/\b(recipe|recipes|recipe standards?|production standards?|recipe yield|recipe ingredients?|recipe instructions?|recipe method|batch formula|scale .*recipe|recipe .*scale|make .*recipe|what recipes? use|which recipes? use|recipe allergens?|allergens?.*recipe)\b/u',$text)===1;
        if($recipeIntent&&app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('recipes'));

        $prepIntent=preg_match('/\b(prep intelligence|prep plan|prep recommendations?|prep list|prep history|publish prep|generate prep|build prep|prep forecast|prep shortage)\b/u',$text)===1;
        if($prepIntent&&app_has_permission('prep.intelligence.view',$user)&&app_has_permission('prep.intelligence.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('prep'));

        $operationsIntent=preg_match('/\b(restaurant tasks?|operations tasks?|task list|overdue tasks?|low stock|inventory par|inventory count|inventory sources?|sync inventory|task categor(?:y|ies)|restaurant operations)\b/u',$text)===1;
        if($operationsIntent&&((app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user))||(app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user))))app_json_response(['ok'=>true]+gaw_node_route('operations'));

        $purchasingIntent=preg_match('/\b(purchase orders?|\bpo\b|vendor prices?|supplier prices?|receiving|goods receipts?|restock|inventory pressure|need to buy|need to order|should buy|purchasing suggestions?|draft .*\bpo\b|submit .*\bpo\b|cancel .*\bpo\b)\b/u',$text)===1;
        if($purchasingIntent&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))app_json_response(['ok'=>true]+gaw_node_route('purchasing'));

        $costIntent=preg_match('/\b(food cost|food costs|cogs|cost of goods|gross margin|gross profit|item margin|item margins|profitability|most profitable|best margin|waste cost|purchase spend|purchase receipts|price drift|cost drift|cost coverage)\b/u',$text)===1;
        if($costIntent&&(app_has_permission('sales.costs.view',$user)||app_has_permission('sales.view',$user))&&app_has_permission('sales.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('sales_cost'));
        $salesIntent=preg_match('/\b(sales|revenue|average check|avg check|tickets|covers|item mix|best.?selling|top items|labor percent|labor percentage|sales per labor hour|sales forecast|demand forecast|projected sales|projected covers|staffing capacity|how busy)\b/u',$text)===1;
        if($salesIntent&&app_has_permission('sales.view',$user)&&app_has_permission('sales.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('sales'));
        $developmentIntent=preg_match('/\b(employee development|development brief|performance brief|coaching|coaching notes?|recognition|training progress|task completion|attendance reliability|development goals?)\b/u',$text)===1;
        if($developmentIntent&&(app_has_permission('employee.performance.view',$user)||app_has_permission('employee.manage',$user)||app_has_permission('staff.manage',$user)))app_json_response(['ok'=>true]+gaw_node_route('employee_development'));
        $handoffIntent=preg_match('/\b(handoff|handoffs|shift note|station note|arrival brief|what happened before i got here|what happened before my shift|anything i should know|tell (?:the )?next shift|leave .*next shift|note .*next shift)\b/u',$text)===1;
        if($handoffIntent&&(app_has_permission('employee.handoffs.view',$user)||app_has_permission('employee.handoffs.create',$user)||app_has_permission('employee.handoffs.manage',$user)||app_has_permission('employee.self',$user)||app_has_permission('agent.employee_view',$user)))app_json_response(['ok'=>true]+gaw_node_route('employee_handoff'));

        $fallback=gaw_route($user,$message);
        if($isTableServiceContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this table|selected table|this bar seat|selected bar seat|this check|selected check|this order|server|seat|party|transfer|move|fire|hold|send|unsent|ready|late|food|kitchen|turn|reset|reservation|attach customer|add item|remove item|item note|what needs attention|what should we do|what(?:\x27s| is) going on)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','operations'],true)&&$localIntent)app_json_response(['ok'=>true]+gaw_node_route('live_shift','live_shift_context'));
        }
        if($isKdsContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this order|selected order|this ticket|selected ticket|this item|order number|ticket number|table|special instructions?|mods?|late|ready|held|unrouted|station|all day|kitchen|orders?|tickets?|what(?:\x27s| is) next|what just came in|newest|latest|history|status|summary|what(?:\x27s| is) going on)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','operations'],true)&&$localIntent)app_json_response(['ok'=>true]+gaw_node_route('kds','kds_context'));
        }
        if($isCrmContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this customer|selected customer|customer|guest|visits?|favorites?|favourites?|recent checks?|recent orders?|notes?|tags?|archive|consent|lifetime spend|average check|relationship)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('crm','crm_context'));
        }
        if($isCateringContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this event|selected event|this operation|selected operation|readiness|ready|ingredients?|requirements?|shortages?|tasks?|staff|staffing|add .*task|mark .*task|rebuild .*ingredient|regenerate .*ingredient|what needs attention|what(?:\x27s| is) missing|how are we looking)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','catering','operations'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('catering','catering_context'));
        }
        if($isWholesaleContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this order|selected order|this wholesale order|this batch|selected batch|fulfillment|batch|batches|allocation|allocated|unallocated|shortage|shortages|available to promise|\batp\b|create .*batch|mark .*batch .*ready|dispatch .*batch|cancel .*batch|deliver .*batch|status|summary|what(?:\x27s| is) going on)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','operations','wholesale'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('wholesale','wholesale_context'));
        }
        if($isPrepContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this prep|this plan|selected plan|recommendations?|prep list|forecast|shortage|history|publish|generate|add .*prep|what should we prep|what should i prep)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','operations'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('prep','prep_context'));
        }
        if($isOperationsContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this task|selected task|this inventory|selected inventory|tasks?|prep|inventory|stock|par|reorder|shortage|opening|closing|cleaning|overdue|task category|sync .*inventory|sync .*wholesale)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','operations'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('operations','operations_context'));
        }
        if($isPurchasingContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this po|selected po|this purchase order|selected purchase order|this vendor|this shipment|this delivery|outstanding|remaining|submit this|cancel this|receive this|received in full|everything arrived|all remaining|current suggestions?)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain','purchasing'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('purchasing','purchasing_context'));
        }
        if($isSchedulingContext){
            $fallbackDomain=(string)($fallback['domain']??'general');
            $localIntent=preg_match('/\b(this shift|selected shift|this employee|selected employee|them|their|they|him|her|cover this|coverage candidate|move this|change this|update this|cancel this|assign this|message them|tell them|notify them|publish this week|publish the week|visible week|this week|what about them|when do they work)\b/u',$text)===1;
            if(in_array($fallbackDomain,['general','restaurant_brain'],true)&&($localIntent||$confirmationIntent))app_json_response(['ok'=>true]+gaw_node_route('scheduling','scheduling_context'));
        }
        if($isPosContext){
            $posActionIntent=preg_match('/\b(move|transfer|assign server|change server|seat|send|fire|hold|attach customer|remove|delete|add item|item note|void|discount|comp|refund)\b/u',$text)===1;
            if($posActionIntent&&$canLiveShift)app_json_response(['ok'=>true]+gaw_node_route('live_shift','live_shift_pos_context'));
            $posIntent=preg_match('/\b(current check|this check|current order|this order|cart|guest|customer|regular|repeat customer|favorite|favourite|usual|promotion|promotions|promo|reward|rewards|offer|offers|coupon|coupons|deal|deals|previous orders?|recent orders?|order history|menu|menu item|pizza|gelato|ingredient|ingredients|allergen|allergens|allergy|allergies|gluten|dairy|milk|egg|nuts?|peanut|wheat|soy|sesame|shellfish|fish|prep|prepare|preparation|cook|cooking|service note|menu note|price|prices|how much|size|sizes|option|options|recommend|recommendation|suggest)\b/u',$text)===1;
            $fallbackDomain=(string)($fallback['domain']??'general');
            $otherSpecialized=!in_array($fallbackDomain,['general','restaurant_brain'],true);
            $naturalMenuMatch=false;
            if(!$otherSpecialized&&!$posIntent){$naturalMenuMatch=(bool)menu_training_search_items($pdo,$org,$message,1);}
            if(!$otherSpecialized&&($posIntent||$naturalMenuMatch))app_json_response(['ok'=>true]+gaw_node_route('pos'));
        }
        app_json_response(gaw_normalize_node_route($fallback));
    }
    if($action==='record_action'){$thread=trim((string)($in['threadId']??''));$public=gaw_record_action($pdo,$org,$uid,$thread,['messageDatabaseId'=>isset($in['messageDatabaseId'])?(int)$in['messageDatabaseId']:null,'skill'=>$in['skill']??'unknown','route'=>$in['route']??'unknown','status'=>$in['status']??'completed','request'=>$in['request']??null,'result'=>$in['result']??null]);app_json_response(['ok'=>true,'actionId'=>$public]);}
    if($action==='archive'){$id=trim((string)($in['threadId']??''));$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare("UPDATE agent_conversations SET status='archived',archived_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([(int)$thread['id']]);app_audit($pdo,$org,$uid,'agent.thread_archived','agent_conversation',$id);app_json_response(['ok'=>true]);}
    if($action==='rename'){$id=trim((string)($in['threadId']??''));$title=trim((string)($in['title']??''));if($title==='')throw new InvalidArgumentException('Conversation title is required.');$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare('UPDATE agent_conversations SET title=?,updated_at=NOW(6) WHERE id=?')->execute([mb_substr($title,0,220,'UTF-8'),(int)$thread['id']]);app_json_response(['ok'=>true]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$e instanceof InvalidArgumentException?422:500);}