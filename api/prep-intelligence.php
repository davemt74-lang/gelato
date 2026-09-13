<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/prep-intelligence-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$userId=(int)$user['id'];
if(!prep_intelligence_ready($pdo))app_json_response(['ok'=>false,'message'=>'Prep + Inventory Intelligence migration is not installed. Run upgrade.php.'],503);
$canView=app_has_permission('prep.intelligence.view',$user);
$canManage=app_has_permission('prep.intelligence.manage',$user);
$canForecast=app_has_permission('inventory.forecast.view',$user)||app_has_permission('inventory.view',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Prep Intelligence view permission required.'],403);

function prep_api_date(string $value): string
{
    $value=trim($value);if($value==='')return date('Y-m-d');
    $dt=DateTimeImmutable::createFromFormat('Y-m-d',$value);
    if(!$dt||$dt->format('Y-m-d')!==$value)app_json_response(['ok'=>false,'message'=>'Invalid prep date.'],422);
    return $value;
}
function prep_api_plan(PDO $pdo,int $org,array $input): array
{
    $id=trim((string)($input['planId']??$input['id']??''));
    if($id==='')app_json_response(['ok'=>false,'message'=>'Prep plan ID is required.'],422);
    $plan=prep_plan($pdo,$org,$id);
    if(!$plan)app_json_response(['ok'=>false,'message'=>'Prep plan not found.'],404);
    return $plan;
}
function prep_api_visible_detail(array $detail,bool $canForecast): array
{
    if(!$canForecast)$detail['forecasts']=[];
    return $detail;
}
function prep_api_task_history(PDO $pdo,int $organizationId,string $date,string $query=''): array
{
    $tasks=prep_task_history_for_date($pdo,$organizationId,$date,$query);
    if(!$tasks)return [];
    $publicIds=array_values(array_unique(array_filter(array_map(static fn(array $row):string=>(string)($row['public_id']??''),$tasks))));
    if(!$publicIds)return $tasks;
    $marks=implode(',',array_fill(0,count($publicIds),'?'));
    $stmt=$pdo->prepare("SELECT t.public_id task_public_id,e.event_type,e.summary,e.metadata_json,e.created_at,u.display_name actor_name FROM restaurant_tasks t INNER JOIN restaurant_task_events e ON e.task_id=t.id LEFT JOIN users u ON u.id=e.actor_user_id WHERE t.organization_id=? AND t.public_id IN ({$marks}) ORDER BY e.created_at,e.id");
    $stmt->execute(array_merge([$organizationId],$publicIds));
    $eventsByTask=[];
    foreach($stmt->fetchAll() as $event){
        $taskId=(string)$event['task_public_id'];
        $eventsByTask[$taskId]??=[];
        $eventsByTask[$taskId][]=[
            'eventType'=>(string)$event['event_type'],
            'summary'=>(string)$event['summary'],
            'actorName'=>$event['actor_name']!==null?(string)$event['actor_name']:null,
            'createdAt'=>(string)$event['created_at'],
            'metadata'=>$event['metadata_json']?json_decode((string)$event['metadata_json'],true):null,
        ];
    }
    foreach($tasks as &$task)$task['events']=$eventsByTask[(string)$task['public_id']]??[];
    unset($task);
    return $tasks;
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');
    if($action==='bootstrap'){
        $date=prep_api_date((string)($_GET['date']??date('Y-m-d')));
        $service=prep_service_period((string)($_GET['service']??'all'));
        $plan=prep_plan_for_period($pdo,$organizationId,$date,$service);
        $historyFrom=(new DateTimeImmutable($date))->modify('-28 days')->format('Y-m-d');
        $detail=$plan?prep_api_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast):null;
        app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'canManage'=>$canManage,'canForecast'=>$canForecast,'date'=>$date,'service'=>$service,'detail'=>$detail,'history'=>prep_history_rows($pdo,$organizationId,$historyFrom,$date)]);
    }
    if($action==='plan'){
        $plan=prep_api_plan($pdo,$organizationId,$_GET);
        app_json_response(['ok'=>true,'detail'=>prep_api_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast)]);
    }
    if($action==='history'){
        $to=prep_api_date((string)($_GET['to']??date('Y-m-d')));
        $from=prep_api_date((string)($_GET['from']??(new DateTimeImmutable($to))->modify('-60 days')->format('Y-m-d')));
        app_json_response(['ok'=>true,'history'=>prep_history_rows($pdo,$organizationId,$from,$to,(string)($_GET['q']??''))]);
    }
    if($action==='task_history'){
        $date=prep_api_date((string)($_GET['date']??date('Y-m-d')));
        app_json_response(['ok'=>true,'date'=>$date,'tasks'=>prep_api_task_history($pdo,$organizationId,$date,(string)($_GET['q']??''))]);
    }
    if($action==='normal'){
        $query=trim((string)($_GET['q']??''));if($query==='')app_json_response(['ok'=>false,'message'=>'Enter a prep item to analyze.'],422);
        $weekday=isset($_GET['weekday'])&&$_GET['weekday']!==''?(int)$_GET['weekday']:null;if($weekday!==null&&($weekday<1||$weekday>7))$weekday=null;
        app_json_response(['ok'=>true,'history'=>prep_item_history_summary($pdo,$organizationId,$query,$weekday)]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Prep Intelligence action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);
if(!$canManage)app_json_response(['ok'=>false,'message'=>'Prep Intelligence management permission required.'],403);
$action=(string)($input['action']??'');

try{
    if($action==='ensure_plan'){
        $date=prep_api_date((string)($input['date']??date('Y-m-d')));
        $plan=prep_get_or_create_plan($pdo,$organizationId,$date,(string)($input['service']??'all'),$userId);
        app_audit($pdo,$organizationId,$userId,'prep.plan_ensured','prep_plan',(string)$plan['public_id']);
        app_json_response(['ok'=>true,'detail'=>prep_api_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast)]);
    }
    if($action==='save_plan'){
        $plan=prep_api_plan($pdo,$organizationId,$input);$saved=prep_save_plan($pdo,$organizationId,$plan,$input,$userId);
        app_audit($pdo,$organizationId,$userId,'prep.plan_updated','prep_plan',(string)$saved['public_id'],null,['projectedCovers'=>$saved['projected_covers'],'demandMultiplier'=>$saved['demand_multiplier']]);
        app_json_response(['ok'=>true,'plan'=>$saved,'message'=>'Prep plan settings saved.']);
    }
    if($action==='generate'){
        $plan=prep_api_plan($pdo,$organizationId,$input);$detail=prep_generate_recommendations($pdo,$organizationId,$plan,$userId);
        app_audit($pdo,$organizationId,$userId,'prep.recommendations_generated','prep_plan',(string)$plan['public_id'],null,['count'=>count($detail['recommendations'])]);
        app_json_response(['ok'=>true,'detail'=>prep_api_visible_detail($detail,$canForecast),'message'=>count($detail['recommendations']).' prep recommendation(s) generated from restaurant history.']);
    }
    if($action==='recommendation_update'){
        $id=trim((string)($input['recommendationId']??''));if($id==='')app_json_response(['ok'=>false,'message'=>'Recommendation ID is required.'],422);
        $row=prep_update_recommendation($pdo,$organizationId,$id,$input,$userId);
        app_audit($pdo,$organizationId,$userId,'prep.recommendation_updated','prep_recommendation',$id,null,['status'=>$row['status'],'quantity'=>$row['recommended_quantity']]);
        app_json_response(['ok'=>true,'recommendation'=>$row,'message'=>'Prep recommendation updated.']);
    }
    if($action==='publish'){
        $plan=prep_api_plan($pdo,$organizationId,$input);$detail=prep_publish_plan($pdo,$organizationId,$plan,$userId);
        app_audit($pdo,$organizationId,$userId,'prep.plan_published','prep_plan',(string)$plan['public_id'],null,['tasks'=>count($detail['tasks'])]);
        app_json_response(['ok'=>true,'detail'=>prep_api_visible_detail($detail,$canForecast),'message'=>'Prep plan published to restaurant tasks.']);
    }
    if($action==='close'){
        $plan=prep_api_plan($pdo,$organizationId,$input);$closed=prep_close_plan($pdo,$organizationId,$plan,$userId);
        app_audit($pdo,$organizationId,$userId,'prep.plan_closed','prep_plan',(string)$plan['public_id']);
        app_json_response(['ok'=>true,'plan'=>$closed,'message'=>'Prep plan closed.']);
    }
    if($action==='signal_add'){
        $signal=prep_add_demand_signal($pdo,$organizationId,$input,$userId);$plan=prep_plan_for_period($pdo,$organizationId,(string)$signal['signal_date'],(string)$signal['service_period']);
        if($plan)prep_event($pdo,$organizationId,(int)$plan['id'],'demand_signal_added','Demand signal added to the prep plan.',$userId,['signalPublicId'=>$signal['public_id'],'type'=>$signal['signal_type']]);
        app_audit($pdo,$organizationId,$userId,'prep.demand_signal_added','prep_demand_signal',(string)$signal['public_id']);
        app_json_response(['ok'=>true,'signal'=>$signal,'message'=>'Demand signal added.']);
    }
    if($action==='task_add'){
        $plan=prep_api_plan($pdo,$organizationId,$input);$title=trim((string)($input['title']??''));if($title==='')app_json_response(['ok'=>false,'message'=>'Prep item title is required.'],422);
        $task=prep_attach_manual_task($pdo,$organizationId,$plan,$input,$userId,'manual');
        app_audit($pdo,$organizationId,$userId,'prep.task_added','restaurant_task',(string)$task['public_id'],null,['planId'=>$plan['public_id']]);
        app_json_response(['ok'=>true,'task'=>$task,'detail'=>prep_api_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast),'message'=>'Prep item added to the plan and canonical task list.'],201);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Prep Intelligence action.'],422);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
