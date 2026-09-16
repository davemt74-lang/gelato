<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/admin-control-core.php';
require_once __DIR__.'/../includes/menu-training-knowledge.php';

// Compatibility route markers for older CI/contracts. Canonical routing now lives in includes/agent-node-registry.php.
// api/admin-dashboard-agent.php api/daily-manager-agent.php api/sales-cost-agent.php api/sales-agent.php
// api/employee-development-agent.php api/employee-agent.php api/purchasing-agent.php api/scheduling-agent.php api/pos-agent.php
// api/customer-crm-agent.php api/prep-intelligence-agent.php api/operations-agent.php api/kds-agent.php

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Agent Workspace migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');
    if($action==='bootstrap'){
        $threads=gaw_threads($pdo,$org,$uid,40);$active=$threads[0]['public_id']??null;
        if(!$active){$thread=gaw_create_thread($pdo,$org,$uid);$threads=gaw_threads($pdo,$org,$uid,40);$active=$thread['public_id']??null;}
        app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'threads'=>$threads,'activeThread'=>$active,'messages'=>$active?gaw_messages($pdo,$org,$uid,(string)$active,120):[],'user'=>['id'=>$uid,'name'=>$user['display_name'],'firstName'=>$user['first_name'],'role'=>$user['role_slug']],'permissions'=>$user['permissions']]);
    }
    if($action==='thread'){$id=trim((string)($_GET['id']??''));app_json_response(['ok'=>true,'thread'=>gaw_thread($pdo,$org,$uid,$id),'messages'=>gaw_messages($pdo,$org,$uid,$id,220)]);}
    if($action==='threads')app_json_response(['ok'=>true,'threads'=>gaw_threads($pdo,$org,$uid,80)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);} 
$in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
try{
    if($action==='new_thread'){$thread=gaw_create_thread($pdo,$org,$uid,(string)($in['channel']??'text'));app_audit($pdo,$org,$uid,'agent.thread_created','agent_conversation',(string)$thread['public_id']);app_json_response(['ok'=>true,'thread'=>$thread]);}
    if($action==='append'){$thread=trim((string)($in['threadId']??''));$role=(string)($in['role']??'user');$message=gaw_append($pdo,$org,$uid,$thread,$role,(string)($in['content']??''),['channel'=>$in['channel']??'text','skill'=>$in['skill']??null,'tool'=>$in['tool']??null,'voiceEventId'=>$in['voiceEventId']??null,'structured'=>$in['structured']??null,'sources'=>is_array($in['sources']??null)?$in['sources']:[]]);app_json_response(['ok'=>true,'message'=>$message]);}
    if($action==='route'){
        $message=trim((string)($in['message']??''));if($message==='')throw new InvalidArgumentException('Enter an Agent request.');
        $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
        $pageContext=is_array($in['pageContext']??null)?$in['pageContext']:[];
        $module=(string)($pageContext['module']??'');
        $isPosContext=$module==='pos'&&app_has_permission('pos.use',$user);
        $isSchedulingContext=$module==='scheduling'&&app_has_permission('schedule.agent',$user);
        $isPurchasingContext=$module==='purchasing'&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user);
        $isCrmContext=$module==='crm'&&app_has_permission('crm.view',$user);
        $isPrepContext=$module==='prep'&&app_has_permission('prep.intelligence.view',$user)&&app_has_permission('prep.intelligence.agent',$user);
        $isOperationsContext=$module==='operations'&&((app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user))||(app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user)));
        $isKdsContext=$module==='kds'&&app_has_permission('kds.view',$user);
        $confirmationIntent=preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply|cancel|cancel it|discard|never mind|nevermind|stop)(?:\s+(?:it|that|change|action))?[.!]?$/u',$text)===1;
        if($confirmationIntent){
            $pendingNode=gaw_pending_action_node($org,$uid);
            if($pendingNode==='crm'&&app_has_permission('crm.view',$user))app_json_response(['ok'=>true]+gaw_node_route('crm','crm_confirmation'));
            if($pendingNode==='prep'&&app_has_permission('prep.intelligence.view',$user)&&app_has_permission('prep.intelligence.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('prep','prep_confirmation'));
            if($pendingNode==='operations'&&((app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user))||(app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user))))app_json_response(['ok'=>true]+gaw_node_route('operations','operations_confirmation'));
            if($pendingNode==='purchasing'&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))app_json_response(['ok'=>true]+gaw_node_route('purchasing','purchasing_confirmation'));
            if($pendingNode==='scheduling'&&app_has_permission('schedule.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('scheduling','scheduling_confirmation'));
        }

        $kdsIntent=preg_match('/\b(kds|kitchen display|kitchen tickets?|kitchen orders?|expo|all day|ready to bump|unrouted kitchen|station load|late kitchen|late tickets?|held tickets?|order history.*kitchen|kitchen.*order history)\b/u',$text)===1;
        if($kdsIntent&&app_has_permission('kds.view',$user))app_json_response(['ok'=>true]+gaw_node_route('kds'));

        $dashboardIntent=preg_match('/\b(command center|command centre|dashboard|restaurant overview|operating snapshot|operations snapshot|location performance|compare locations?|active tables?|open (?:pos )?(?:tickets?|checks?)|online orders?|what needs attention|what is happening right now|what\x27s happening right now|how is wholesale doing|wholesale (?:status|overview|pipeline|orders?|receivables?|accounts?|sales)|catering (?:status|overview|readiness|events?))\b/u',$text)===1;
        if($dashboardIntent&&admin_control_allowed($user))app_json_response(['ok'=>true]+gaw_node_route('command_center'));

        $managerIntent=preg_match('/\b(gm brief|manager brief|daily brief|opening brief|morning brief|closing brief|manager recap|daily manager|restaurant status|how is (?:the )?restaurant doing|how are we doing today|what needs manager attention)\b/u',$text)===1;
        if($managerIntent&&app_has_permission('manager.brief.view',$user)&&app_has_permission('manager.brief.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('daily_manager','daily_manager_brief'));

        $crmIntent=preg_match('/\b(customer crm|crm customer|customer profile|guest profile|customer history|customer notes?|customer tags?|find customer|look up customer|lifetime spend|customer favorites?|customer favourites?|relationship history)\b/u',$text)===1;
        if($crmIntent&&!$isPosContext&&app_has_permission('crm.view',$user))app_json_response(['ok'=>true]+gaw_node_route('crm'));

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
