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

function gaw_route(array $user,string $message): array
{
    $text=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',trim($message))??trim($message),'UTF-8');
    $menu='/\b(menu|menu item|sold.?out|86(?:d)?|eighty.?six|food item|drink item|menu price|pizza size|modifier|topping|add.?on)\b/u';
    $workforce='/\b(resume|resumes|applicant|applicants|candidate|candidates|hiring|hire|new hires?|employee activity|staff activity|recent activity|workforce overview|workforce summary)\b/u';
    if(($user['role_slug']??'')==='wholesale_customer')return ['route'=>'api/wholesale-portal-agent.php','domain'=>'wholesale_portal'];
    $time='/\b(clock(?:ed)?\s+(?:me\s+)?(?:in|out)|time\s*clock|break|attendance|no.?show|late|actual labor|on clock|clock status)\b/u';
    $frontOfHouse='/\b(front.?of.?house|host stand|reservations?|reservation status|waitlist|walk.?ins?|waiting parties|table availability|available tables?|tables? (?:are )?available|open tables?|selected reservation|this reservation|selected party|this waitlist|guest (?:has )?arrived|party (?:has )?arrived|mark .*no.?show|seat this (?:party|reservation)|assign .*reservation .*table|table cleaning|mark .*table .*ready|ready for service)\b/u';
    $equipment='/\b(equipment(?: asset)?|appliance|equipment maintenance|equipment service|equipment repair|equipment warranty|equipment service history|equipment repair history|equipment service contact|equipment repair company|equipment replacement cost|equipment .*out of service|out of service .*equipment|equipment maintenance mode|return .*equipment.*service|equipment back in service|record .*equipment.*repair|record .*equipment.*maintenance|schedule .*equipment.*maintenance|schedule .*equipment.*service|(?:oven|mixer|refrigeration|refrigerator|freezer|gelato machine|dishwasher|dish machine).*(?:maintenance|repair|service|warranty|down|offline|out of service)|(?:maintenance|repair|service|warranty).*(?:oven|mixer|refrigeration|refrigerator|freezer|gelato machine|dishwasher|dish machine))\b/u';
    $recipes='/\b(recipe|recipes|recipe standard|recipe standards|production standard|production standards|recipe yield|recipe ingredients?|recipe instructions?|recipe method|batch formula|scale .*recipe|recipe .*scale|make .*recipe|what recipes? use|which recipes? use|recipe allergens?|allergens?.*recipe)\b/u';
    $onlineOrders='/\b(online orders?|pickup orders?|web orders?|pickup queue|pickup fulfillment|pickup handoff|ready for pickup|past promise|late pickup|pickup promise|order recovery|pickup recovery)\b/u';
    $schedule='/\b(schedule|scheduled|shift|shifts|availability|time off|swap|coverage|staffing|short.?staffed|who works|who is working|when do i work|next shift)\b/u';
    $employee='/\b(my day|employee home|employee profile|emergency contact|announcement|announcements|restaurant update|handbook|policy|policies|certification|certifications|my training|training due|onboarding|checklist|orientation)\b/u';
    $catering='/\b(catering|banquet|event order|guest count|tasting|deposit|catering readiness)\b/u';
    $purchasing='/\b(purchase order|purchase orders|\bpo\b|vendor|vendors|supplier|suppliers|receiving|receipt|receipts|invoice|invoices|order cutoff|delivery day|price comparison|compare price|cheapest|best price|what.*need.*order|need to order|what should.*buy)\b/u';
    $prepIntel='/\b(prep plan|prep list|what should (?:we|i) prep|prep recommendations?|prep history|normally prep|usually prep|inventory forecast|shortage forecast|forecast.*shortage|publish.*prep|build.*prep|generate.*prep)\b/u';
    $crm='/\b(customer crm|crm customer|customer profile|guest profile|customer history|guest history|relationship history|this customer|this guest|regular|repeat customer|returning customer|customer favorites?|customer favourites?|usual order|recent customer orders?|last visit|lifetime spend|average check|vip|high value customer|top spend|customer birthday|birthday customer|lapsed customer|re.?engage customer|customer consent|email consent|sms consent|follow.?up task.*customer)\b/u';
    $marketing='/\b(marketing|promotions?|promote|package deals?|featured package|feature .*package|public site|website tagline|homepage tagline|home page tagline|specials page|family dinner specials?)\b/u';
    $ops='/\b(inventory|stock|par|reorder|shortage|prep|task|tasks|opening|closing|cleaning|assigned|overdue|fulfillment|delivery|order|orders)\b/u';

    $confirmationIntent=preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply|cancel|cancel it|discard|never mind|nevermind|stop)(?:\s+(?:it|that|change|action))?[.!]?$/u',$text)===1;
    if($confirmationIntent&&function_exists('gaw_pending_action_node')&&function_exists('gaw_node_route')){
        $pendingNode=gaw_pending_action_node((int)($user['organization_id']??0),(int)($user['id']??0));
        if($pendingNode==='front_of_house'&&app_has_permission('host.view',$user)&&(app_has_permission('table_service.view',$user)||app_has_permission('host.use',$user)||app_has_permission('host.manage',$user)))return gaw_node_route('front_of_house','front_of_house_confirmation');
        if($pendingNode==='marketing'&&(app_has_permission('packages.view',$user)||app_has_permission('public_pages.edit',$user)||app_has_permission('settings.organization_edit',$user)))return gaw_node_route('marketing','marketing_confirmation');
        if($pendingNode==='equipment'&&app_has_permission('equipment.view',$user)&&app_has_permission('agent.equipment_skills',$user))return gaw_node_route('equipment','equipment_confirmation');
        if($pendingNode==='recipes'&&app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user))return gaw_node_route('recipes','recipe_confirmation');
        if($pendingNode==='online_orders'&&(app_has_permission('online_orders.fulfill',$user)||app_has_permission('order_recovery.view',$user)||app_has_permission('order_recovery.manage',$user)||app_has_permission('order_recovery.refund',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user)||app_has_permission('crm.view',$user)))return gaw_node_route('online_orders','online_order_confirmation');
    }

    if(preg_match($workforce,$text)&&(app_has_permission('resumes.view',$user)||app_has_permission('audit.view',$user)||app_has_permission('schedule.view',$user)))return ['route'=>'api/workspace-workforce-agent.php','domain'=>'workspace_workforce'];
    if(preg_match($menu,$text)&&app_has_permission('menu.view',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];
    if(preg_match($time,$text)&&(app_has_permission('timeclock.agent',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('attendance.view',$user)))return ['route'=>'api/timeclock-agent.php','domain'=>'timeclock'];
    if(preg_match($frontOfHouse,$text)&&app_has_permission('host.view',$user)&&(app_has_permission('table_service.view',$user)||app_has_permission('host.use',$user)||app_has_permission('host.manage',$user)))return ['route'=>'api/front-of-house-agent.php','domain'=>'front_of_house'];
    if(preg_match($equipment,$text)&&app_has_permission('equipment.view',$user)&&app_has_permission('agent.equipment_skills',$user))return function_exists('gaw_node_route')?gaw_node_route('equipment'):['route'=>'api/equipment-agent.php','domain'=>'equipment_maintenance'];
    if(preg_match($recipes,$text)&&app_has_permission('recipes.view',$user)&&app_has_permission('recipes.agent',$user))return function_exists('gaw_node_route')?gaw_node_route('recipes'):['route'=>'api/recipe-agent.php','domain'=>'recipe_production_standards'];
    if(preg_match($onlineOrders,$text)&&(app_has_permission('online_orders.fulfill',$user)||app_has_permission('order_recovery.view',$user)||app_has_permission('order_recovery.manage',$user)||app_has_permission('order_recovery.refund',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user)||app_has_permission('crm.view',$user)))return function_exists('gaw_node_route')?gaw_node_route('online_orders'):['route'=>'api/online-order-agent.php','domain'=>'online_order_fulfillment'];
    if(preg_match($schedule,$text)&&(app_has_permission('schedule.agent',$user)||app_has_permission('schedule.view',$user)||app_has_permission('schedule.self',$user)))return ['route'=>'api/scheduling-agent.php','domain'=>'scheduling'];
    if(preg_match($employee,$text)&&(app_has_permission('employee.self',$user)||app_has_permission('tasks.self',$user)||app_has_permission('agent.employee_view',$user)||app_has_permission('training.self_view',$user)))return ['route'=>'api/employee-agent.php','domain'=>'employee_home'];
    if(preg_match($marketing,$text)&&(app_has_permission('packages.view',$user)||app_has_permission('public_pages.edit',$user)||app_has_permission('settings.organization_edit',$user)))return ['route'=>'api/marketing-agent.php','domain'=>'marketing'];
    if(preg_match($catering,$text)&&app_has_permission('catering.agent',$user))return ['route'=>'api/catering-agent.php','domain'=>'catering'];
    if(preg_match($purchasing,$text)&&app_has_permission('purchasing.agent',$user)&&app_has_permission('purchasing.view',$user))return ['route'=>'api/purchasing-agent.php','domain'=>'purchasing'];
    if(preg_match($prepIntel,$text)&&app_has_permission('prep.intelligence.agent',$user)&&app_has_permission('prep.intelligence.view',$user))return ['route'=>'api/prep-intelligence-agent.php','domain'=>'prep_intelligence'];
    if(preg_match($crm,$text)&&app_has_permission('crm.view',$user))return ['route'=>'api/customer-crm-agent.php','domain'=>'customer_crm'];
    if(preg_match($ops,$text)&&(app_has_permission('tasks.agent',$user)||app_has_permission('inventory.agent',$user))&&(app_has_permission('tasks.view',$user)||app_has_permission('inventory.view',$user)))return ['route'=>'api/operations-agent.php','domain'=>'operations'];
    if(preg_match($ops,$text)&&(app_has_permission('tasks.self',$user)||app_has_permission('agent.employee_view',$user)))return ['route'=>'api/employee-agent.php','domain'=>'employee_operations'];
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
