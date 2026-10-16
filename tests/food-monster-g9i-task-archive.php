<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';
$pdo=app_pdo();$checks=0;
function g9i_ok(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "PASS $name\n";}
g9i_ok(operations_core_ready($pdo),'canonical Operations Core tables ready');
$pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9I archive CI','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
  VALUES (?,?,'CI','Admin','CI Admin','active')")->execute(['fm-g9i-'.bin2hex(random_bytes(8)).'@example.test','hash']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")
  ->execute([$org,$uid]);
$owner=['id'=>$uid,'organization_id'=>$org,'is_owner_role'=>1,'permissions'=>['*']];
$staff=$owner;$staff['is_owner_role']=0;
$thread=gaw_create_thread($pdo,$org,$uid,'text','FM-G9I task archive');
$tid=$thread['public_id'];
$task=operations_create_task($pdo,$org,['title'=>'Example test task','category'=>'general'],$uid);
$id=$task['public_id'];
$summary="task {$id}";
g9i_ok((bool)preg_match('/^task-[a-f0-9]{20}$/D',$id),'real task identifier');
try{fm9i_prepare($pdo,$staff,$summary);g9i_ok(false,'staff denied');}
catch(DomainException){g9i_ok(true,'staff denied');}
$other=$owner;$other['organization_id']=$org+222;
try{fm9i_prepare($pdo,$other,$summary);g9i_ok(false,'cross tenant denied');}
catch(InvalidArgumentException){g9i_ok(true,'cross tenant denied');}
$source=operations_create_task($pdo,$org,[
    'title'=>'Linked report task','category'=>'general',
    'sourceType'=>'food_monster_report','sourcePublicId'=>'msg-'.str_repeat('a',20)],$uid);
try{fm9i_prepare($pdo,$owner,'task '.$source['public_id']);g9i_ok(false,'source linked denied');}
catch(DomainException){g9i_ok(true,'source linked denied');}
$pending=fm9f_approval_propose($owner,'record_deletion',$summary,$tid,$pdo);
g9i_ok(!$pending['executed'],'proposal did not archive task');
$decision=fm9f_approval_decide($owner,$pending['proposalId'],'approve');
g9i_ok(!$decision['executed']&&$decision['handoff']['taskId']===$id,'approval bound exact task');
g9i_ok(operations_task_by_public_id($pdo,$org,$id)!==null,'approved but not yet executed');
try{fm9i_execute($pdo,$staff,$tid);g9i_ok(false,'staff execute denied');}
catch(DomainException){g9i_ok(true,'staff execute denied');}
try{fm9i_execute($pdo,$owner,'agent-'.str_repeat('f',20));g9i_ok(false,'other thread denied');}
catch(DomainException){g9i_ok(true,'other thread denied');}
$result=fm9i_execute($pdo,$owner,$tid);
g9i_ok($result['executed']&&$result['archived'],'final owner Confirm archived task');
g9i_ok(operations_task_by_public_id($pdo,$org,$id)===null,'task excluded from active task results');
$rows=$pdo->prepare('SELECT archived_at,status FROM restaurant_tasks WHERE organization_id=? AND public_id=?');
$rows->execute([$org,$id]);$archived=$rows->fetch(PDO::FETCH_ASSOC);
g9i_ok($archived&&$archived['archived_at']!==null&&$archived['status']==='cancelled',
    'record preserved with cancelled + archived status');
$events=$pdo->prepare("SELECT COUNT(*) FROM agent_messages
 WHERE organization_id=? AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9i_high_impact_execution'
 AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.taskId'))=?");
$events->execute([$org,$id]);
g9i_ok((int)$events->fetchColumn()===1,'one durable Agent Brain execution receipt');
try{fm9i_execute($pdo,$owner,$tid);g9i_ok(false,'replay denied');}
catch(DomainException){g9i_ok(true,'replay denied');}
$stale=operations_create_task($pdo,$org,['title'=>'Stale test task','category'=>'general'],$uid);
$new=fm9f_approval_propose($owner,'record_deletion','task '.$stale['public_id'],$tid,$pdo);
fm9f_approval_decide($owner,$new['proposalId'],'approve');
$pdo->prepare("UPDATE restaurant_tasks SET updated_at=DATE_ADD(NOW(6), INTERVAL 60 SECOND)
 WHERE organization_id=? AND public_id=?")->execute([$org,$stale['public_id']]);
try{fm9i_execute($pdo,$owner,$tid);g9i_ok(false,'stale state denied');}
catch(DomainException){g9i_ok(true,'stale state denied');}
g9i_ok(operations_task_by_public_id($pdo,$org,$stale['public_id'])!==null,
    'stale denial did not archive task');
$api=file_get_contents(__DIR__.'/../api/operations.php');
g9i_ok(str_contains($api,'operations_archive_task('),'native Operations Core API reuses archiver');
echo "FM-G9I MariaDB task archive PASS ({$checks} assertions)\n";
