<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/prep-intelligence-core.php';
require __DIR__ . '/../includes/agent-workspace-core.php';

$pdo=app_pdo();
function pit_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }

pit_assert(operations_core_ready($pdo),'Operations Core must be installed.');
pit_assert(prep_intelligence_ready($pdo),'Prep Intelligence must be installed.');

$orgId=(int)$pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn();
if(!$orgId){
    $pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Prep Intelligence CI','active','America/Phoenix')");
    $orgId=(int)$pdo->lastInsertId();
}
$userId=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if(!$userId){
    $stmt=$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')");
    $stmt->execute(['prep-ci@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Prep','CI','Prep CI']);
    $userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$orgId,$userId]);
}

operations_ensure_default_categories($pdo,$orgId,$userId);
$prepCategory=operations_category_by_slug($pdo,$orgId,'prep');
pit_assert((bool)$prepCategory,'Prep category is required.');

$target='2026-09-19';
foreach(['2026-08-29'=>10.0,'2026-09-05'=>12.0,'2026-09-12'=>14.0] as $date=>$qty){
    $public='ci-hist-'.str_replace('-','',$date);
    $existing=operations_task_by_public_id($pdo,$orgId,$public);
    if(!$existing){
        $stmt=$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,title,quantity,unit,station,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,?,?,'batch','Dough','normal','completed',?,?,?)");
        $stmt->execute([$orgId,$public,(int)$prepCategory['id'],'Pizza dough',$qty,$date.' 10:00:00',$userId,$userId]);
        $taskId=(int)$pdo->lastInsertId();
        operations_task_event($pdo,$orgId,$taskId,'completed','Pizza dough completed.',$userId,['contract'=>true]);
    }
}

$inventory=operations_inventory_upsert($pdo,$orgId,'mozzarella',$userId);
$pdo->prepare("UPDATE inventory_items SET base_unit='lb',on_hand_quantity=2,par_level=10,reorder_point=4,updated_by=? WHERE id=? AND organization_id=?")
    ->execute([$userId,(int)$inventory['id'],$orgId]);

$plan=prep_get_or_create_plan($pdo,$orgId,$target,'all',$userId);
$plan=prep_save_plan($pdo,$orgId,$plan,['demandMultiplier'=>1.10,'projectedCovers'=>150,'notes'=>'Saturday CI forecast'],$userId);
pit_assert(abs((float)$plan['demand_multiplier']-1.10)<0.001,'Demand multiplier was not saved.');

$detail=prep_generate_recommendations($pdo,$orgId,$plan,$userId);
$rec=null;
foreach($detail['recommendations'] as $row){if(prep_normalized_key((string)$row['title'])==='pizza dough'){$rec=$row;break;}}
pit_assert(is_array($rec),'Historical Pizza dough recommendation was not generated.');
pit_assert((int)$rec['sample_count']===3,'Same-weekday sample count should be 3.');
pit_assert(abs((float)$rec['recommended_quantity']-13.2)<0.001,'Expected 13.2 batch recommendation from history and multiplier.');
pit_assert((float)$rec['confidence']>=0.70,'Same-weekday recommendation confidence is unexpectedly low.');

$forecast=null;
foreach($detail['forecasts'] as $row){if((string)$row['inventory_name']==='mozzarella'){$forecast=$row;break;}}
pit_assert(is_array($forecast),'Low-stock inventory item was not included in forecast.');
pit_assert((float)$forecast['restock_quantity']>=8.0,'Restock-to-par forecast should flag at least 8 lb mozzarella.');

$updated=prep_update_recommendation($pdo,$orgId,(string)$rec['public_id'],['quantity'=>15,'unit'=>'batch','station'=>'Dough','status'=>'accepted'],$userId);
pit_assert((float)$updated['recommended_quantity']===15.0,'Human recommendation override was not retained.');
pit_assert((string)$updated['status']==='accepted','Recommendation acceptance was not retained.');

$published=prep_publish_plan($pdo,$orgId,$plan,$userId);
$generated=array_values(array_filter($published['tasks'],static fn(array $row):bool=>(string)($row['source_type']??'')==='prep_plan'));
pit_assert(count($generated)>=1,'Publishing did not create canonical prep tasks.');
$taskCount1=(int)$pdo->prepare("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND source_type='prep_plan'")->execute([$orgId]);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND source_type='prep_plan'");$stmt->execute([$orgId]);$before=(int)$stmt->fetchColumn();
$publishedAgain=prep_publish_plan($pdo,$orgId,prep_plan($pdo,$orgId,(string)$plan['public_id'])??$plan,$userId);
$stmt->execute([$orgId]);$after=(int)$stmt->fetchColumn();
pit_assert($before===$after,'Republishing duplicated canonical prep tasks.');
pit_assert(count($publishedAgain['tasks'])>=1,'Published prep plan lost task links.');

$history=prep_item_history_summary($pdo,$orgId,'Pizza dough',6,12);
pit_assert((int)$history['sampleCount']>=3,'Prep history summary did not find Saturday samples.');
pit_assert(abs((float)$history['average']-12.0)<0.001,'Prep history average should be 12 batches.');

$dayHistory=prep_task_history_for_date($pdo,$orgId,'2026-09-12','Pizza dough');
pit_assert(count($dayHistory)===1,'Historical date lookup should return the completed Pizza dough task.');

$routeUser=['role_slug'=>'manager','permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.view','tasks.agent','inventory.view','inventory.agent']];
$route=gaw_route($routeUser,'Hey Gelato, what should we prep today?');
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php','Prep planning should route to the dedicated Prep Intelligence Agent.');
$route=gaw_route($routeUser,'Hey Gelato, show prep history from last Saturday');
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php','Prep history should route to the dedicated Prep Intelligence Agent.');
$route=gaw_route($routeUser,'Hey Gelato, count inventory');
pit_assert(($route['route']??null)==='api/operations-agent.php','Generic inventory should remain on the Operations Agent.');

$events=$pdo->prepare('SELECT COUNT(*) FROM prep_plan_events WHERE organization_id=? AND plan_id=?');$events->execute([$orgId,(int)$plan['id']);
pit_assert((int)$events->fetchColumn()>=4,'Prep plan append-only event history is incomplete.');

$closed=prep_close_plan($pdo,$orgId,prep_plan($pdo,$orgId,(string)$plan['public_id'])??$plan,$userId);
pit_assert((string)$closed['status']==='closed','Prep plan did not close.');
$blocked=false;
try{prep_save_plan($pdo,$orgId,$closed,['notes'=>'must not edit'],$userId);}catch(RuntimeException){$blocked=true;}
pit_assert($blocked,'Closed prep plan must reject edits.');

echo "prep-inventory-intelligence contract passed\n";
