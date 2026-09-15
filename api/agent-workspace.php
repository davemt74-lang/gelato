<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/admin-control-core.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!gaw_ready($pdo))app_json_response(['ok'=>false,'message'=>'Agent Workspace migration is not installed. Run upgrade.php.'],503);

function gaw_api_route(array $route,array $user,array $context): void
{
    app_json_response(['ok'=>true]+$route+[
        'pageContext'=>$context,
        'contextPrompt'=>gaw_page_context_prompt($context),
        'toolArgs'=>gaw_page_context_tool_args($context),
        'contextActions'=>gaw_context_actions($user,$context),
    ]);
}

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
        $context=gaw_sanitize_page_context($in['pageContext']??[]);
        $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
        $dashboardIntent=preg_match('/\b(command center|command centre|dashboard|restaurant overview|operating snapshot|operations snapshot|location performance|compare locations?|active tables?|open (?:pos )?(?:tickets?|checks?)|ready tickets?|kds ready|online orders?|what needs attention|what is happening right now|what\x27s happening right now|how is wholesale doing|wholesale (?:status|overview|pipeline|orders?|receivables?|accounts?|sales)|catering (?:status|overview|readiness|events?))\b/u',$text)===1;
        $dashboardAccess=admin_control_allowed($user);
        if($dashboardIntent&&$dashboardAccess)gaw_api_route(['route'=>'api/admin-dashboard-agent.php','domain'=>'admin_dashboard'],$user,$context);
        $managerIntent=preg_match('/\b(gm brief|manager brief|daily brief|opening brief|morning brief|closing brief|manager recap|daily manager|restaurant status|how is (?:the )?restaurant doing|how are we doing today|what needs manager attention)\b/u',$text)===1;
        if($managerIntent&&app_has_permission('manager.brief.view',$user)&&app_has_permission('manager.brief.agent',$user))gaw_api_route(['route'=>'api/daily-manager-agent.php','domain'=>'daily_manager_brief'],$user,$context);
        $costIntent=preg_match('/\b(food cost|food costs|cogs|cost of goods|gross margin|gross profit|item margin|item margins|profitability|most profitable|best margin|waste cost|purchase spend|purchase receipts|vendor price|price drift|cost drift|cost coverage)\b/u',$text)===1;
        if($costIntent&&(app_has_permission('sales.costs.view',$user)||app_has_permission('sales.view',$user))&&app_has_permission('sales.agent',$user))gaw_api_route(['route'=>'api/sales-cost-agent.php','domain'=>'sales_cost_intelligence'],$user,$context);
        $salesIntent=preg_match('/\b(sales|revenue|average check|avg check|tickets|covers|item mix|best.?selling|top items|labor percent|labor percentage|sales per labor hour|sales forecast|demand forecast|projected sales|projected covers|staffing capacity|how busy)\b/u',$text)===1;
        if($salesIntent&&app_has_permission('sales.view',$user)&&app_has_permission('sales.agent',$user))gaw_api_route(['route'=>'api/sales-agent.php','domain'=>'sales_intelligence'],$user,$context);
        $developmentIntent=preg_match('/\b(employee development|development brief|performance brief|coaching|coaching notes?|recognition|training progress|task completion|attendance reliability|development goals?)\b/u',$text)===1;
        if($developmentIntent&&(app_has_permission('employee.performance.view',$user)||app_has_permission('employee.manage',$user)||app_has_permission('staff.manage',$user)))gaw_api_route(['route'=>'api/employee-development-agent.php','domain'=>'employee_development'],$user,$context);
        $handoffIntent=preg_match('/\b(handoff|handoffs|shift note|station note|arrival brief|what happened before i got here|what happened before my shift|anything i should know|tell (?:the )?next shift|leave .*next shift|note .*next shift)\b/u',$text)===1;
        if($handoffIntent&&(app_has_permission('employee.handoffs.view',$user)||app_has_permission('employee.handoffs.create',$user)||app_has_permission('employee.handoffs.manage',$user)||app_has_permission('employee.self',$user)||app_has_permission('agent.employee_view',$user)))gaw_api_route(['route'=>'api/employee-agent.php','domain'=>'employee_handoff'],$user,$context);
        gaw_api_route(gaw_route($user,$message,$context),$user,$context);
    }
    if($action==='record_action'){$thread=trim((string)($in['threadId']??''));$public=gaw_record_action($pdo,$org,$uid,$thread,['messageDatabaseId'=>isset($in['messageDatabaseId'])?(int)$in['messageDatabaseId']:null,'skill'=>$in['skill']??'unknown','route'=>$in['route']??'unknown','status'=>$in['status']??'completed','request'=>$in['request']??null,'result'=>$in['result']??null]);app_json_response(['ok'=>true,'actionId'=>$public]);}
    if($action==='archive'){$id=trim((string)($in['threadId']??''));$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare("UPDATE agent_conversations SET status='archived',archived_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([(int)$thread['id']]);app_audit($pdo,$org,$uid,'agent.thread_archived','agent_conversation',$id);app_json_response(['ok'=>true]);}
    if($action==='rename'){$id=trim((string)($in['threadId']??''));$title=trim((string)($in['title']??''));if($title==='')throw new InvalidArgumentException('Conversation title is required.');$thread=gaw_thread($pdo,$org,$uid,$id);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');$pdo->prepare('UPDATE agent_conversations SET title=?,updated_at=NOW(6) WHERE id=?')->execute([mb_substr($title,0,220,'UTF-8'),(int)$thread['id']]);app_json_response(['ok'=>true]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent Workspace action.'],422);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$e instanceof InvalidArgumentException?422:500);}
