<?php
declare(strict_types=1);

function gaw_ready(PDO $pdo): bool
{
    try {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('agent_conversations','agent_messages','restaurant_agent_actions')");
        return (int)$q->fetchColumn()===3;
    } catch (Throwable) { return false; }
}

function gaw_public_id(string $prefix): string
{
    return $prefix.'-'.bin2hex(random_bytes(10));
}

function gaw_thread(PDO $pdo,int $org,int $userId,string $publicId): ?array
{
    $q=$pdo->prepare("SELECT * FROM agent_conversations WHERE organization_id=? AND user_id=? AND public_id=? AND archived_at IS NULL LIMIT 1");
    $q->execute([$org,$userId,$publicId]);
    $row=$q->fetch();
    return $row?:null;
}

function gaw_create_thread(PDO $pdo,int $org,int $userId,string $channel='text',string $title='New conversation'): array
{
    $id=gaw_public_id('agent');
    $channel=in_array($channel,['text','voice','proactive'],true)?$channel:'text';
    $title=trim($title)?:'New conversation';
    $pdo->prepare("INSERT INTO agent_conversations (organization_id,user_id,public_id,title,primary_channel,last_message_at) VALUES (?,?,?,?,?,NOW(6))")
        ->execute([$org,$userId,$id,mb_substr($title,0,220,'UTF-8'),$channel]);
    return gaw_thread($pdo,$org,$userId,$id)??[];
}

function gaw_threads(PDO $pdo,int $org,int $userId,int $limit=40): array
{
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.status,c.primary_channel,c.last_message_at,c.created_at,c.updated_at,(SELECT COUNT(*) FROM agent_messages m WHERE m.conversation_id=c.id) message_count FROM agent_conversations c WHERE c.organization_id=? AND c.user_id=? AND c.archived_at IS NULL ORDER BY COALESCE(c.last_message_at,c.created_at) DESC LIMIT {$limit}");
    $q->execute([$org,$userId]);
    return $q->fetchAll();
}

function gaw_messages(PDO $pdo,int $org,int $userId,string $threadPublicId,int $limit=160): array
{
    $thread=gaw_thread($pdo,$org,$userId,$threadPublicId);
    if(!$thread) throw new InvalidArgumentException('Agent conversation not found.');
    $limit=max(1,min(300,$limit));
    $q=$pdo->prepare("SELECT public_id,role,channel,content,skill,tool_name,voice_event_public_id,structured_json,sources_json,created_at FROM agent_messages WHERE organization_id=? AND conversation_id=? ORDER BY id DESC LIMIT {$limit}");
    $q->execute([$org,(int)$thread['id']]);
    $rows=array_reverse($q->fetchAll());
    foreach($rows as &$row){$row['structured']=$row['structured_json']?json_decode((string)$row['structured_json'],true):null;$row['sources']=$row['sources_json']?json_decode((string)$row['sources_json'],true):[];unset($row['structured_json'],$row['sources_json']);}
    return $rows;
}

function gaw_append(PDO $pdo,int $org,int $userId,string $threadPublicId,string $role,string $content,array $meta=[]): array
{
    $thread=gaw_thread($pdo,$org,$userId,$threadPublicId);
    if(!$thread) throw new InvalidArgumentException('Agent conversation not found.');
    $role=in_array($role,['user','agent','system'],true)?$role:'agent';
    $channel=(string)($meta['channel']??'text');$channel=in_array($channel,['text','voice','proactive'],true)?$channel:'text';
    $content=trim($content);if($content==='')throw new InvalidArgumentException('Message cannot be empty.');if(mb_strlen($content,'UTF-8')>12000)throw new InvalidArgumentException('Message is too long.');
    $public=gaw_public_id('msg');
    $structured=array_key_exists('structured',$meta)&&$meta['structured']!==null?json_encode($meta['structured'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $sources=!empty($meta['sources'])?json_encode(array_values($meta['sources']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $pdo->prepare("INSERT INTO agent_messages (organization_id,conversation_id,user_id,public_id,role,channel,content,skill,tool_name,voice_event_public_id,structured_json,sources_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$org,(int)$thread['id'],$role==='system'?null:$userId,$public,$role,$channel,$content,$meta['skill']??null,$meta['tool']??null,$meta['voiceEventId']??null,$structured,$sources]);
    $messageId=(int)$pdo->lastInsertId();
    $title=(string)$thread['title'];
    if($role==='user'&&($title==='New conversation'||$title==='')){$title=mb_substr(preg_replace('/\s+/u',' ',$content)??$content,0,72,'UTF-8');}
    $pdo->prepare("UPDATE agent_conversations SET title=?,primary_channel=IF(primary_channel='text' AND ?<>'text',?,primary_channel),last_message_at=NOW(6),updated_at=NOW(6) WHERE id=?")
        ->execute([$title,$channel,$channel,(int)$thread['id']]);
    return ['id'=>$public,'databaseId'=>$messageId,'role'=>$role,'channel'=>$channel,'content'=>$content,'skill'=>$meta['skill']??null,'tool'=>$meta['tool']??null,'structured'=>$meta['structured']??null,'sources'=>$meta['sources']??[],'createdAt'=>date('Y-m-d H:i:s')];
}

function gaw_page_module(string $route): string
{
    static $map=[
        'workspace.php'=>'workspace','scheduling.php'=>'scheduling','timeclock.php'=>'timeclock',
        'employee-home.php'=>'employee','employee-development.php'=>'employee','admin.php'=>'employee',
        'purchasing.php'=>'purchasing','customer-crm.php'=>'crm',
        'catering.php'=>'catering','catering-operations.php'=>'catering','catering-pipeline.php'=>'catering',
        'wholesale.php'=>'wholesale','wholesale-accounts.php'=>'wholesale','wholesale-customer-360.php'=>'wholesale',
        'wholesale-pipeline.php'=>'wholesale','wholesale-acquisition.php'=>'wholesale','wholesale-commerce.php'=>'wholesale',
        'wholesale-demand.php'=>'wholesale','wholesale-fulfillment.php'=>'wholesale','wholesale-purchasing.php'=>'wholesale',
        'wholesale-receivables.php'=>'wholesale','wholesale-order-entry.php'=>'wholesale','wholesale-portal.php'=>'wholesale',
        'pos.php'=>'orders','kds.php'=>'orders','kds-dashboard.php'=>'orders','table-service.php'=>'orders',
        'host-stand.php'=>'orders','online-orders-admin.php'=>'orders','order-recovery.php'=>'orders','pickup-fulfillment.php'=>'orders',
        'operations.php'=>'operations','prep-intelligence.php'=>'prep','recipes.php'=>'recipes','menu-manager.php'=>'menu',
        'equipment.php'=>'equipment','equipment-detail.php'=>'equipment','sales-intelligence.php'=>'sales',
        'sales-cost-intelligence.php'=>'sales','sales-import-center.php'=>'sales','locations-admin.php'=>'locations',
    ];
    return $map[$route]??'general';
}

function gaw_context_text(mixed $value,int $limit=120): string
{
    if(!is_scalar($value))return '';
    $value=(string)$value;
    $value=preg_replace('/[\x00-\x1F\x7F]/u',' ',$value)??'';
    $value=preg_replace('/\s+/u',' ',$value)??$value;
    return mb_substr(trim($value),0,$limit,'UTF-8');
}

function gaw_sanitize_page_context(mixed $raw): array
{
    if(!is_array($raw))$raw=[];
    $route=strtolower(basename(gaw_context_text($raw['route']??'',80)));
    if(!preg_match('/^[a-z0-9][a-z0-9._-]*\.php$/',$route))$route='';
    $module=gaw_page_module($route);
    $entityRaw=is_array($raw['entity']??null)?$raw['entity']:[];
    $scopeRaw=is_array($raw['scope']??null)?$raw['scope']:[];
    $allowedEntityTypes=['employee','user','customer','contact','order','ticket','table','event','catering','vendor','purchase_order','item','recipe','account','asset','equipment','lead','location'];
    $entityType=strtolower(gaw_context_text($entityRaw['type']??'',40));
    if(!in_array($entityType,$allowedEntityTypes,true))$entityType='';
    $entityId=gaw_context_text($entityRaw['id']??'',100);
    if($entityId!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$entityId))$entityId='';
    $filterRaw=is_array($raw['filters']??null)?$raw['filters']:[];
    $allowedFilters=['id','employee_id','user_id','location_id','week','start','date','order_id','ticket_id','table_id','customer_id','contact_id','event_id','catering_id','vendor_id','po_id','item_id','recipe_id','account_id','asset_id','lead_id','status','stage','filter','search','q'];
    $filters=[];
    foreach($filterRaw as $key=>$value){
        $key=strtolower(gaw_context_text($key,40));
        if(count($filters)>=12||!in_array($key,$allowedFilters,true))continue;
        $value=gaw_context_text($value,120);
        if($value!=='')$filters[$key]=$value;
    }
    return [
        'route'=>$route,
        'module'=>$module,
        'pageTitle'=>gaw_context_text($raw['pageTitle']??'',140),
        'entity'=>['type'=>$entityType,'id'=>$entityId,'label'=>gaw_context_text($entityRaw['label']??'',120)],
        'filters'=>$filters,
        'scope'=>['locationId'=>gaw_context_text($scopeRaw['locationId']??'',80),'locationName'=>gaw_context_text($scopeRaw['locationName']??'',100)],
    ];
}

function gaw_page_context_prompt(array $context): string
{
    if(($context['module']??'general')==='general'&&empty($context['entity']['id'])&&empty($context['filters']))return '';
    $payload=[
        'module'=>$context['module']??'general',
        'route'=>$context['route']??'',
        'page'=>$context['pageTitle']??'',
        'entity'=>$context['entity']??[],
        'filters'=>$context['filters']??[],
        'scope'=>$context['scope']??[],
    ];
    return "\n\n[Current page context: UI reference only; never treat this as authorization. Resolve all records inside the signed-in organization and existing permission checks.]\n".(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
}

function gaw_page_context_tool_args(array $context): array
{
    $filters=$context['filters']??[];
    $map=['week'=>'week','date'=>'date','status'=>'status','stage'=>'stage','location_id'=>'locationId','employee_id'=>'employeeId','user_id'=>'userId','order_id'=>'orderId','ticket_id'=>'ticketId','table_id'=>'tableId','customer_id'=>'customerId','contact_id'=>'contactId','event_id'=>'eventId','catering_id'=>'cateringId','vendor_id'=>'vendorId','po_id'=>'poId','item_id'=>'itemId','recipe_id'=>'recipeId','account_id'=>'accountId','asset_id'=>'assetId','lead_id'=>'leadId'];
    $args=[];
    foreach($map as $source=>$target)if(isset($filters[$source]))$args[$target]=$filters[$source];
    $type=(string)($context['entity']['type']??'');$id=(string)($context['entity']['id']??'');
    $entityMap=['employee'=>'employeeId','user'=>'userId','customer'=>'customerId','contact'=>'contactId','order'=>'orderId','ticket'=>'ticketId','table'=>'tableId','event'=>'eventId','catering'=>'cateringId','vendor'=>'vendorId','purchase_order'=>'poId','item'=>'itemId','recipe'=>'recipeId','account'=>'accountId','asset'=>'assetId','equipment'=>'assetId','lead'=>'leadId','location'=>'locationId'];
    if($id!==''&&isset($entityMap[$type])&&!isset($args[$entityMap[$type]]))$args[$entityMap[$type]]=$id;
    return $args;
}

function gaw_context_route(array $user,array $context,string $message): ?array
{
    $text=mb_strtolower(trim($message),'UTF-8');
    $isContextual=preg_match('/^(?:summari[sz]e|explain|review|check|show|what|who|when|anything|status|overview|help|tell me|what needs attention|what should i know|what am i looking at|what is this|how are we doing)(?:\b|\s)/u',$text)===1 || preg_match('/\b(this|here|current|screen|page)\b/u',$text)===1;
    if(!$isContextual)return null;
    $module=$context['module']??'general';
    if($module==='scheduling'&&(app_has_permission('schedule.agent',$user)||app_has_permission('schedule.view',$user)||app_has_permission('schedule.self',$user)))return ['route'=>'api/scheduling-agent.php','domain'=>'scheduling'];
    if($module==='timeclock'&&(app_has_permission('timeclock.agent',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('attendance.view',$user)))return ['route'=>'api/timeclock-agent.php','domain'=>'timeclock'];
    if($module==='employee'&&(app_has_permission('employee.self',$user)||app_has_permission('agent.employee_view',$user)||app_has_permission('employee.performance.view',$user)||app_has_permission('staff.manage',$user)))return ['route'=>'api/employee-agent.php','domain'=>'employee_home'];
    if($module==='purchasing'&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))return ['route'=>'api/purchasing-agent.php','domain'=>'purchasing'];
    if($module==='crm'&&app_has_permission('crm.view',$user))return ['route'=>'api/customer-crm-agent.php','domain'=>'customer_crm'];
    if($module==='catering'&&app_has_permission('catering.agent',$user))return ['route'=>'api/catering-agent.php','domain'=>'catering'];
    if($module==='prep'&&app_has_permission('prep.intelligence.agent',$user)&&app_has_permission('prep.intelligence.view',$user))return ['route'=>'api/prep-intelligence-agent.php','domain'=>'prep_intelligence'];
    if(in_array($module,['orders','operations'],true)&&(app_has_permission('tasks.agent',$user)||app_has_permission('inventory.agent',$user))&&(app_has_permission('tasks.view',$user)||app_has_permission('inventory.view',$user)))return ['route'=>'api/operations-agent.php','domain'=>'operations'];
    if($module==='sales'&&app_has_permission('sales.view',$user)&&app_has_permission('sales.agent',$user))return ['route'=>'api/sales-agent.php','domain'=>'sales_intelligence'];
    if(in_array($module,['wholesale','equipment','recipes','menu'],true)&&(app_has_permission('menu.view',$user)||app_has_permission('agent.equipment_skills',$user)||app_has_permission('wholesale.agent',$user)||app_has_permission('recipes.agent',$user)))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];
    return null;
}

function gaw_context_actions(array $user,array $context): array
{
    $module=$context['module']??'general';
    $sets=[
        'scheduling'=>[['Summarize schedule','Summarize this schedule.'],['Coverage gaps','Find coverage gaps on this schedule.'],['Open shifts','Show open shifts for this schedule.']],
        'timeclock'=>[['Clock status','Check the current time clock status.'],['Attendance','Review attendance issues that need attention.']],
        'employee'=>[['Employee summary','Summarize this employee and what needs attention.'],['Availability','Check this employee availability and schedule.'],['Recent activity','Show recent activity for this employee.']],
        'purchasing'=>[['Purchasing summary','Summarize what needs attention on this purchasing page.'],['Need to order','What needs to be ordered?'],['Receiving issues','Check for receiving or vendor issues.']],
        'crm'=>[['Customer summary','Summarize this customer relationship.'],['Favorite items','Show this customer favorite items.'],['Recent visits','Summarize this customer recent paid visits.']],
        'catering'=>[['Event summary','Summarize this catering event and what needs attention.'],['Readiness','Check catering readiness.'],['Pipeline','Summarize the catering pipeline.']],
        'wholesale'=>[['Account summary','Summarize this wholesale account or opportunity.'],['Open orders','Review open wholesale orders.'],['Follow-up','What wholesale follow-up is due?']],
        'orders'=>[['Order summary','Summarize the current order or ticket.'],['Status','Check order status and exceptions.'],['Needs attention','What needs attention on this order screen?']],
        'operations'=>[['Operations summary','Summarize what needs attention on this operations page.'],['Shortages','Check inventory shortages.'],['Overdue tasks','Show overdue operational tasks.']],
        'prep'=>[['What needs prep','What should we prep based on this page?'],['Shortages','Check shortage risk for this prep plan.'],['Explain forecast','Explain this prep forecast.']],
        'recipes'=>[['Explain recipe','Explain this recipe.'],['Related recipes','Find related recipes.']],
        'menu'=>[['Explain item','Explain this menu item.'],['Sold-out items','Check sold-out or 86ed menu items.']],
        'equipment'=>[['Equipment summary','Summarize this equipment asset.'],['Maintenance','Check maintenance due for this equipment.'],['Service contacts','Show the best service contacts for this equipment.']],
        'sales'=>[['Explain results','Explain the current sales results.'],['What changed','What changed in these sales results?'],['Anomalies','Show sales anomalies that need attention.']],
    ];
    if(!isset($sets[$module]))return [];
    if(!gaw_context_route($user,$context,'summarize this'))return [];
    return array_map(static fn(array $row):array=>['label'=>$row[0],'prompt'=>$row[1]],$sets[$module]);
}

function gaw_route(array $user,string $message,array $pageContext=[]): array
{
    $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',trim($message))??trim($message),'UTF-8');
    $menu='/\b(menu|menu item|sold.?out|86(?:d)?|eighty.?six|food item|drink item|menu price|pizza size|modifier|topping|add.?on)\b/u';
    $workforce='/\b(resume|resumes|applicant|applicants|candidate|candidates|hiring|hire|new hires?|employee activity|staff activity|recent activity|workforce overview|workforce summary)\b/u';
    if(($user['role_slug']??'')==='wholesale_customer')return ['route'=>'api/wholesale-portal-agent.php','domain'=>'wholesale_portal'];
    $time='/\b(clock(?:ed)?\s+(?:me\s+)?(?:in|out)|time\s*clock|break|attendance|no.?show|late|actual labor|on clock|clock status)\b/u';
    $schedule='/\b(schedule|scheduled|shift|shifts|availability|time off|swap|coverage|staffing|short.?staffed|who works|who is working|when do i work|next shift)\b/u';
    $employee='/\b(my day|employee home|employee profile|emergency contact|announcement|announcements|restaurant update|handbook|policy|policies|certification|certifications|my training|training due|onboarding|checklist|orientation)\b/u';
    $catering='/\b(catering|banquet|event order|guest count|tasting|deposit|catering readiness)\b/u';
    $purchasing='/\b(purchase order|purchase orders|\bpo\b|vendor|vendors|supplier|suppliers|receiving|receipt|receipts|invoice|invoices|order cutoff|delivery day|price comparison|compare price|cheapest|best price|what.*need.*order|need to order|what should.*buy)\b/u';
    $prepIntel='/\b(prep plan|prep list|what should (?:we|i) prep|prep recommendations?|prep history|normally prep|usually prep|inventory forecast|shortage forecast|forecast.*shortage|publish.*prep|build.*prep|generate.*prep)\b/u';
    $ops='/\b(inventory|stock|par|reorder|shortage|prep|task|tasks|opening|closing|cleaning|assigned|overdue|fulfillment|delivery|order|orders)\b/u';
    if(preg_match($workforce,$text)&&(app_has_permission('resumes.view',$user)||app_has_permission('audit.view',$user)||app_has_permission('schedule.view',$user)))return ['route'=>'api/workspace-workforce-agent.php','domain'=>'workspace_workforce'];
    if(preg_match($menu,$text)&&app_has_permission('menu.view',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];
    if(preg_match($time,$text)&&(app_has_permission('timeclock.agent',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('attendance.view',$user)))return ['route'=>'api/timeclock-agent.php','domain'=>'timeclock'];
    if(preg_match($schedule,$text)&&(app_has_permission('schedule.agent',$user)||app_has_permission('schedule.view',$user)||app_has_permission('schedule.self',$user)))return ['route'=>'api/scheduling-agent.php','domain'=>'scheduling'];
    if(preg_match($employee,$text)&&(app_has_permission('employee.self',$user)||app_has_permission('tasks.self',$user)||app_has_permission('agent.employee_view',$user)||app_has_permission('training.self_view',$user)))return ['route'=>'api/employee-agent.php','domain'=>'employee_home'];
    if(preg_match($catering,$text)&&app_has_permission('catering.agent',$user))return ['route'=>'api/catering-agent.php','domain'=>'catering'];
    if(preg_match($purchasing,$text)&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))return ['route'=>'api/purchasing-agent.php','domain'=>'purchasing'];
    if(preg_match($prepIntel,$text)&&app_has_permission('prep.intelligence.agent',$user)&&app_has_permission('prep.intelligence.view',$user))return ['route'=>'api/prep-intelligence-agent.php','domain'=>'prep_intelligence'];
    if(preg_match($ops,$text)&&(app_has_permission('tasks.agent',$user)||app_has_permission('inventory.agent',$user))&&(app_has_permission('tasks.view',$user)||app_has_permission('inventory.view',$user)))return ['route'=>'api/operations-agent.php','domain'=>'operations'];
    if(preg_match($ops,$text)&&(app_has_permission('tasks.self',$user)||app_has_permission('agent.employee_view',$user)))return ['route'=>'api/employee-agent.php','domain'=>'employee_operations'];
    if($contextRoute=gaw_context_route($user,$pageContext,$message))return $contextRoute;
    if(app_has_permission('menu.view',$user)||app_has_permission('agent.equipment_skills',$user)||app_has_permission('wholesale.agent',$user)||app_has_permission('recipes.agent',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];
    return ['route'=>null,'domain'=>'general'];
}

function gaw_record_action(PDO $pdo,int $org,int $userId,string $threadPublicId,array $action): string
{
    $thread=gaw_thread($pdo,$org,$userId,$threadPublicId);if(!$thread)throw new InvalidArgumentException('Agent conversation not found.');
    $public=gaw_public_id('act');
    $request=isset($action['request'])?json_encode($action['request'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $result=isset($action['result'])?json_encode($action['result'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $pdo->prepare("INSERT INTO restaurant_agent_actions (organization_id,conversation_id,message_id,user_id,public_id,skill,route,action_status,request_json,result_json,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(6))")
        ->execute([$org,(int)$thread['id'],$action['messageDatabaseId']??null,$userId,$public,mb_substr((string)($action['skill']??'unknown'),0,120,'UTF-8'),mb_substr((string)($action['route']??'unknown'),0,160,'UTF-8'),$action['status']??'completed',$request,$result]);
    return $public;
}
