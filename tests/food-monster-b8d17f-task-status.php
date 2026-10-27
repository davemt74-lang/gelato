<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/operations-agent-task-actions.php';
require dirname(__DIR__).'/includes/operations-core.php';
$checks=0;
function f_assert(bool $ok,string $name):void{global $checks;if(!$ok){fwrite(STDERR,"B8D17F FAIL: $name\n");exit(1);}$checks++;}
function f_error(callable $fn,string $needle):void{
    try{$fn();}catch(InvalidArgumentException|RuntimeException $e){f_assert(str_contains($e->getMessage(),$needle),'error '.$needle);return;}
    f_assert(false,'expected '.$needle);
}
f_assert(ops_agent_task_status_intent('Hey Gelato, complete task Prep Dough')['status']==='completed','spoken complete');
f_assert(ops_agent_task_status_intent('please start the task Prep Dough')['status']==='in_progress','spoken start');
f_assert(ops_agent_task_status_intent('mark Prep Dough as blocked')['status']==='blocked','spoken block');
f_assert(ops_agent_task_status_intent('set task Prep Dough to in progress')['status']==='in_progress','status phrase');
f_assert(ops_agent_task_status_intent('show me the prep list')===null,'read request cannot write');
f_error(static fn()=>ops_agent_task_status_intent('complete Prep Dough and start Closing'),'exactly one');
$sqlite=new PDO('sqlite::memory:');$sqlite->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$sqlite->exec('CREATE TABLE restaurant_tasks (public_id TEXT,organization_id INTEGER,title TEXT,status TEXT,requires_photo INTEGER,requires_verification INTEGER,archived_at TEXT)');
$insert=$sqlite->prepare('INSERT INTO restaurant_tasks VALUES (?,?,?,?,?,?,NULL)');
$insert->execute(['task-1',7,'Prep Dough','assigned',0,0]);
$insert->execute(['task-2',8,'Prep Dough','queued',0,0]);
$insert->execute(['task-3',7,'Clean Oven','in_progress',1,0]);
$prepared=ops_agent_task_status_prepare($sqlite,7,ops_agent_task_status_intent('complete Prep Dough'));
f_assert($prepared['publicId']==='task-1'&&$prepared['expectedStatus']==='assigned','scoped exact match');
f_error(static fn()=>ops_agent_task_status_prepare($sqlite,9,['name'=>'Prep Dough','status'=>'completed']),'No exact task');
f_error(static fn()=>ops_agent_task_status_prepare($sqlite,7,['name'=>'Dough','status'=>'completed']),'No exact task');
f_error(static fn()=>ops_agent_task_status_prepare($sqlite,7,['name'=>'Clean Oven','status'=>'completed']),'proof');
$insert->execute(['task-4',7,'prep dough','queued',0,0]);
f_error(static fn()=>ops_agent_task_status_prepare($sqlite,7,['name'=>'Prep Dough','status'=>'completed']),'Several tasks');
$api=file_get_contents(dirname(__DIR__).'/api/operations-agent.php');
f_assert(str_contains($api,"'task_status_change'")&&str_contains($api,"'tasks.manage'")&&str_contains($api,"'taskId'"),'approval and receipt');
f_assert(str_contains($api,"['inventory_adjust','task_status_change']"),'one-use approval consumed');
if(getenv('B8D17D_DSN')){
    $db=new PDO((string)getenv('B8D17D_DSN'),(string)getenv('B8D17D_USER'),(string)getenv('B8D17D_PASS'));
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE task_categories (id BIGINT PRIMARY KEY AUTO_INCREMENT,organization_id BIGINT,public_id VARCHAR(80),name VARCHAR(120),slug VARCHAR(120),archived_at DATETIME(6)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE restaurant_tasks (id BIGINT PRIMARY KEY AUTO_INCREMENT,organization_id BIGINT,public_id VARCHAR(80),category_id BIGINT,title VARCHAR(240),status VARCHAR(40),source_type VARCHAR(80),source_public_id VARCHAR(80),requires_photo TINYINT DEFAULT 0,requires_verification TINYINT DEFAULT 0,verified_at DATETIME(6),verified_by BIGINT,updated_by BIGINT,updated_at DATETIME(6),archived_at DATETIME(6)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE restaurant_task_events (id BIGINT PRIMARY KEY AUTO_INCREMENT,organization_id BIGINT,task_id BIGINT,event_type VARCHAR(80),summary VARCHAR(500),metadata_json TEXT,actor_user_id BIGINT,created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE restaurant_task_assignments (id BIGINT PRIMARY KEY AUTO_INCREMENT,task_id BIGINT,user_id BIGINT,completed_at DATETIME(6)) ENGINE=InnoDB');
    $db->exec("INSERT INTO restaurant_tasks(organization_id,public_id,title,status) VALUES(7,'task-1','Prep Dough','assigned'),(8,'task-2','Prep Dough','queued')");
    $one=ops_agent_task_status_confirm($db,7,$prepared,101);
    f_assert($one['status']==='completed','confirmed status update');
    f_error(static fn()=>ops_agent_task_status_confirm($db,7,$prepared,101),'Task changed');
    f_error(static fn()=>ops_agent_task_status_confirm($db,8,$prepared,101),'Task no longer exists');
    $events=(int)$db->query('SELECT COUNT(*) FROM restaurant_task_events')->fetchColumn();
    f_assert($events===1,'replay and cross-organization attempts create no extra events');
}
echo "B8D17F task status: $checks assertions passed\n";
