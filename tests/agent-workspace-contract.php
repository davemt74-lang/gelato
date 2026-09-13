<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-workspace-core.php';
function gaw_test(bool $ok,string $message,int $code):void{if(!$ok){fwrite(STDERR,"FAIL {$code}: {$message}\n");exit($code);}}
$pdo=app_pdo();gaw_test(gaw_ready($pdo),'Agent workspace schema unavailable',2);
$pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('Agent Workspace CI','America/Phoenix')");$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);$location=(int)$pdo->lastInsertId();
$users=[];foreach([['agent1@example.test','Agent','One'],['agent2@example.test','Agent','Two']] as $i=>$spec){$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$spec[0],'x',$spec[1],$spec[2],$spec[1].' '.$spec[2]]);$uid=(int)$pdo->lastInsertId();$users[]=$uid;$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Crew','active')")->execute([$org,$uid,$location]);}
[$u1,$u2]=$users;
$thread=gaw_create_thread($pdo,$org,$u1,'text');gaw_test(!empty($thread['public_id']),'thread was not created',3);$threadId=(string)$thread['public_id'];
$userMessage=gaw_append($pdo,$org,$u1,$threadId,'user','What is my prep list?',['channel'=>'voice','voiceEventId'=>'voice-test']);gaw_test(($userMessage['channel']??'')==='voice','voice channel was not persisted',4);
$agentMessage=gaw_append($pdo,$org,$u1,$threadId,'agent','You have two prep tasks.',['channel'=>'voice','skill'=>'employee.prep','tool'=>'api/employee-agent.php','structured'=>[['title'=>'Pizza dough','status'=>'assigned'],['title'=>'Pistachio base','status'=>'queued']],'sources'=>['task-1','task-2']]);
$messages=gaw_messages($pdo,$org,$u1,$threadId);gaw_test(count($messages)===2,'message history count mismatch',5);gaw_test(($messages[1]['structured'][0]['title']??'')==='Pizza dough','structured result did not round-trip',6);gaw_test(($messages[1]['sources'][1]??'')==='task-2','source list did not round-trip',7);
$loaded=gaw_thread($pdo,$org,$u1,$threadId);gaw_test($loaded!==null&&($loaded['title']??'')==='What is my prep list?','conversation title was not derived from first message',8);gaw_test(gaw_thread($pdo,$org,$u2,$threadId)===null,'another employee could read a private thread',9);
$route=gaw_route(['permissions'=>['schedule.self'],'role_slug'=>'employee'],'When do I work next?');gaw_test(($route['route']??'')==='api/timeclock-agent.php','next-shift intent did not route to employee time context',10);
$route=gaw_route(['permissions'=>['schedule.self'],'role_slug'=>'employee'],'Show my schedule this week');gaw_test(($route['route']??'')==='api/scheduling-agent.php','schedule intent did not route to scheduling',11);
$route=gaw_route(['permissions'=>['tasks.self'],'role_slug'=>'employee'],'What prep tasks are assigned to me?');gaw_test(($route['route']??'')==='api/employee-agent.php','employee prep intent was not self-scoped',12);
$route=gaw_route(['permissions'=>['tasks.agent','tasks.view'],'role_slug'=>'manager'],'Show all overdue prep tasks');gaw_test(($route['route']??'')==='api/operations-agent.php','manager task intent did not route to operations',13);
$route=gaw_route(['permissions'=>['wholesale_portal.agent'],'role_slug'=>'wholesale_customer'],'Where is my latest order?');gaw_test(($route['route']??'')==='api/wholesale-portal-agent.php','wholesale customer was not routed to customer-scoped Agent',14);
$actionId=gaw_record_action($pdo,$org,$u1,$threadId,['messageDatabaseId'=>$agentMessage['databaseId'],'skill'=>'employee.prep','route'=>'api/employee-agent.php','request'=>['message'=>'What is my prep list?'],'result'=>['answer'=>'You have two prep tasks.']]);gaw_test(str_starts_with($actionId,'act-'),'action receipt was not created',15);$count=(int)$pdo->query("SELECT COUNT(*) FROM restaurant_agent_actions WHERE organization_id={$org} AND user_id={$u1}")->fetchColumn();gaw_test($count===1,'action receipt count mismatch',16);
$threads=gaw_threads($pdo,$org,$u1);gaw_test(count($threads)===1&&(int)$threads[0]['message_count']===2,'thread summary did not include messages',17);
echo "thread={$threadId} messages=".count($messages)." actions={$count}\n";
