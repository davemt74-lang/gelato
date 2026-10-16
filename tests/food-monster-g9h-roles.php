<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';
$pdo=app_pdo();$checks=0;
function fm9h_ok(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "PASS ".$name.PHP_EOL;}
$pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9H roles CI','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,'Agent','Owner','Owner','active')")
    ->execute(['fm-g9h-'.bin2hex(random_bytes(8)).'@example.test','hash']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")
    ->execute([$org,$uid]);
$owner=['id'=>$uid,'organization_id'=>$org,'is_owner_role'=>1,'permissions'=>['*']];
$unprivileged=$owner;$unprivileged['is_owner_role']=0;
$pdo->prepare("INSERT INTO roles (organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable)
  VALUES (?,'CI Prep Team',?,NULL,0,0,1)")->execute([$org,'fm-g9h-'.bin2hex(random_bytes(4))]);
$roleId=(int)$pdo->lastInsertId();
$thread=gaw_create_thread($pdo,$org,$uid,'text','FM-G9H role update');
$tid=$thread['public_id'];
$permission='roles.view';
$check=$pdo->prepare("SELECT id FROM permissions WHERE permission_key=?");$check->execute([$permission]);
fm9h_ok((int)$check->fetchColumn()>0,'canonical role permission exists');
$summary="role db-role-{$roleId} grant {$permission}";
try{fm9h_role_state($pdo,$org+444,$roleId);fm9h_ok(false,'tenant isolation');}
catch(DomainException){fm9h_ok(true,'tenant isolation');}
$pdo->prepare("INSERT INTO roles (organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable)
  VALUES (?,'Protected','owner_protected_g9h',NULL,1,1,0)")->execute([$org]);
$protected=(int)$pdo->lastInsertId();
try{fm9h_prepare_role($pdo,$owner,"role db-role-{$protected} grant {$permission}");fm9h_ok(false,'protected owner role');}
catch(DomainException){fm9h_ok(true,'protected owner role');}

$binding=fm9h_prepare_role($pdo,$owner,$summary);
fm9h_ok($binding['roleId']===$roleId&&$binding['operation']==='grant','exact role and permission parsed');
try{fm9h_prepare_role($pdo,$unprivileged,$summary);fm9h_ok(false,'employee denied');}
catch(DomainException){fm9h_ok(true,'employee denied');}
try{fm9h_prepare_role($pdo,$owner,"role db-role-{$roleId} grant arbitrary.elevate");fm9h_ok(false,'unknown permission denied');}
catch(InvalidArgumentException){fm9h_ok(true,'unknown permission denied');}
$pending=fm9f_approval_propose($owner,'permission_change',$summary,$tid,$pdo);
fm9h_ok(!$pending['executed']&&$pending['requiresApproval'],'request staged not executed');
$first=fm9f_approval_decide($owner,$pending['proposalId'],'approve');
fm9h_ok(!$first['executed']&&$first['handoff']['roleId']==='db-role-'.$roleId,'approval staged exact role');
try{fm9h_execute_role($pdo,$unprivileged,$tid);fm9h_ok(false,'unauthorized execute blocked');}
catch(DomainException){fm9h_ok(true,'unauthorized execute blocked');}
try{fm9h_execute_role($pdo,$owner,'agent-'.str_repeat('f',20));fm9h_ok(false,'other thread blocked');}
catch(DomainException){fm9h_ok(true,'other thread blocked');}
$apply=fm9h_execute_role($pdo,$owner,$tid);
fm9h_ok($apply['executed']===true&&$apply['roleId']==='db-role-'.$roleId,'domain permission write executed');
fm9h_ok(in_array($permission,fm9h_role_state($pdo,$org,$roleId)['keys'],true),'permission granted in actual role_permissions');
try{fm9h_execute_role($pdo,$owner,$tid);fm9h_ok(false,'replay blocked');}
catch(DomainException){fm9h_ok(true,'replay blocked');}
$events=$pdo->prepare("SELECT COUNT(*) FROM agent_messages
  WHERE organization_id=? AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.collection'))='fm9h_high_impact_execution'
  AND JSON_UNQUOTE(JSON_EXTRACT(structured_json,'$.approvalId'))=?");
$events->execute([$org,$pending['proposalId']]);
fm9h_ok((int)$events->fetchColumn()===1,'exactly one durable Agent Brain execution receipt');
$pending=fm9f_approval_propose($owner,'permission_change',"role db-role-{$roleId} revoke {$permission}",$tid,$pdo);
$approved=fm9f_approval_decide($owner,$pending['proposalId'],'approve');
$revoked=fm9h_execute_role($pdo,$owner,$tid);
fm9h_ok($revoked['operation']==='revoke'&&!in_array($permission,fm9h_role_state($pdo,$org,$roleId)['keys'],true),'revoke persisted');
$pending=fm9f_approval_propose($owner,'permission_change',$summary,$tid,$pdo);
$approved=fm9f_approval_decide($owner,$pending['proposalId'],'approve');
$q=$pdo->prepare("SELECT id FROM permissions WHERE permission_key='roles.edit'");$q->execute();$other=(int)$q->fetchColumn();
if($other>0)$pdo->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)")->execute([$roleId,$other]);
try{fm9h_execute_role($pdo,$owner,$tid);fm9h_ok(false,'changed role blocked');}
catch(DomainException){fm9h_ok(true,'changed role blocked');}
fm9h_ok(!in_array($permission,fm9h_role_state($pdo,$org,$roleId)['keys'],true),'stale grant caused no target change');
echo "FM-G9H MariaDB role permission execution PASS ({$checks} assertions)\n";
