<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/employee-home-core.php';
require __DIR__.'/../includes/employee-shift-communications.php';

$pdo=app_pdo();
function sh_must(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Shift Handoff CI','active','America/Phoenix'),('Shift Handoff Other','active','America/Phoenix')");
$org=(int)$pdo->query("SELECT id FROM organizations WHERE name='Shift Handoff CI'")->fetchColumn();$otherOrg=(int)$pdo->query("SELECT id FROM organizations WHERE name='Shift Handoff Other'")->fetchColumn();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,'Downtown','active'),(?,'North','active'),(?,'Other Store','active')")->execute([$org,$org,$otherOrg]);
$loc1=(int)$pdo->query("SELECT id FROM locations WHERE organization_id={$org} AND name='Downtown'")->fetchColumn();$loc2=(int)$pdo->query("SELECT id FROM locations WHERE organization_id={$org} AND name='North'")->fetchColumn();$otherLoc=(int)$pdo->query("SELECT id FROM locations WHERE organization_id={$otherOrg} AND name='Other Store'")->fetchColumn();
$pdo->prepare("INSERT INTO positions (organization_id,name,slug,status) VALUES (?,'Server','server','active'),(?,'Cook','cook','active'),(?,'Other Server','other-server','active')")->execute([$org,$org,$otherOrg]);
$server=(int)$pdo->query("SELECT id FROM positions WHERE organization_id={$org} AND slug='server'")->fetchColumn();$cook=(int)$pdo->query("SELECT id FROM positions WHERE organization_id={$org} AND slug='cook'")->fetchColumn();
$hash=password_hash('Shift-Handoff-CI-2026',PASSWORD_DEFAULT);$u=$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')");
$u->execute(['first@example.test',$hash,'First','Server','First Server']);$first=(int)$pdo->lastInsertId();$u->execute(['next@example.test',$hash,'Next','Server','Next Server']);$next=(int)$pdo->lastInsertId();$u->execute(['north@example.test',$hash,'North','Server','North Server']);$north=(int)$pdo->lastInsertId();$u->execute(['manager@example.test',$hash,'CI','Manager','CI Manager']);$manager=(int)$pdo->lastInsertId();
$m=$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,?,'active')");$m->execute([$org,$first,$loc1,'Server']);$firstMem=(int)$pdo->lastInsertId();$m->execute([$org,$next,$loc1,'Server']);$nextMem=(int)$pdo->lastInsertId();$m->execute([$org,$north,$loc2,'Server']);$northMem=(int)$pdo->lastInsertId();$m->execute([$org,$manager,$loc1,'Manager']);$managerMem=(int)$pdo->lastInsertId();
$up=$pdo->prepare("INSERT INTO user_positions (membership_id,position_id,is_primary,status,assigned_by) VALUES (?,?,1,'active',?)");$up->execute([$firstMem,$server,$manager]);$up->execute([$nextMem,$server,$manager]);$up->execute([$northMem,$server,$manager]);$up->execute([$managerMem,$cook,$manager]);
foreach([[$first,'First'],[$next,'Next'],[$north,'North'],[$manager,'Manager']] as [$id,$name])$pdo->prepare("INSERT INTO staff_profiles (organization_id,user_id,preferred_name,employment_type,created_by,updated_by) VALUES (?,?,?,'hourly',?,?)")->execute([$org,$id,$name,$manager,$manager]);

$week=scheduling_week_start();$pdo->prepare("INSERT INTO schedule_weeks (organization_id,week_start,status,published_at,published_by,created_by,updated_by) VALUES (?,?,'published',NOW(6),?,?,?)")->execute([$org,$week,$manager,$manager,$manager]);$weekId=(int)$pdo->lastInsertId();
$now=new DateTimeImmutable('now');$shift=$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,position_id,user_id,title,starts_at,ends_at,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,'scheduled',?,?)");
$shift->execute([$org,'shift-first',$weekId,$loc1,$server,$first,'Earlier server shift',$now->modify('-90 minutes')->format('Y-m-d H:i:s'),$now->modify('+60 minutes')->format('Y-m-d H:i:s'),$manager,$manager]);$firstShift=(int)$pdo->lastInsertId();
$shift->execute([$org,'shift-next',$weekId,$loc1,$server,$next,'Next server shift',$now->modify('+30 minutes')->format('Y-m-d H:i:s'),$now->modify('+390 minutes')->format('Y-m-d H:i:s'),$manager,$manager]);$nextShift=(int)$pdo->lastInsertId();
$shift->execute([$org,'shift-north',$weekId,$loc2,$server,$north,'North shift',$now->modify('+30 minutes')->format('Y-m-d H:i:s'),$now->modify('+390 minutes')->format('Y-m-d H:i:s'),$manager,$manager]);$northShift=(int)$pdo->lastInsertId();

sh_must(employee_shift_comms_ready($pdo),'Shift communication schema is not ready.');
$handoff=employee_shift_message_save($pdo,$org,['title'=>'Server handoff','body'=>'Table 12 is celebrating a birthday and dessert is already comped.','station'=>'Dining room','priority'=>'high'],$first,false);
sh_must((int)$handoff['location_id']===$loc1,'Employee handoff did not inherit shift location.');sh_must((int)$handoff['position_id']===$server,'Employee handoff did not inherit shift position.');
$nextRows=employee_shift_messages_for_user($pdo,$org,$next);sh_must(count($nextRows)===1&&$nextRows[0]['public_id']===$handoff['public_id'],'Next matching shift did not receive handoff.');sh_must(employee_shift_messages_for_user($pdo,$org,$north)===[],'Location isolation failed for employee handoff.');
employee_shift_message_read($pdo,$org,$next,(string)$handoff['public_id']);$nextRows=employee_shift_messages_for_user($pdo,$org,$next);sh_must(!empty($nextRows[0]['read_at']),'Handoff read tracking failed.');

$targeted=employee_shift_message_save($pdo,$org,['messageType'=>'announcement','title'=>'Downtown update','body'=>'Side entrance is closed tonight.','priority'=>'urgent','targetType'=>'location','targetId'=>$loc1],$manager,true);
$nextRows=employee_shift_messages_for_user($pdo,$org,$next);sh_must(count(array_filter($nextRows,static fn(array $r):bool=>$r['public_id']===$targeted['public_id']))===1,'Location-targeted manager update missing.');sh_must(employee_shift_messages_for_user($pdo,$org,$north)===[],'Location-targeted manager update leaked to another location.');

$shiftOnly=employee_shift_message_save($pdo,$org,['messageType'=>'note','title'=>'Specific shift note','body'=>'Meet the trainer at the host stand.','targetType'=>'shift','targetId'=>$nextShift],$manager,true);
$nextRows=employee_shift_messages_for_user($pdo,$org,$next);sh_must(count(array_filter($nextRows,static fn(array $r):bool=>$r['public_id']===$shiftOnly['public_id']))===1,'Specific shift note missing.');$firstRows=employee_shift_messages_for_user($pdo,$org,$first);sh_must(count(array_filter($firstRows,static fn(array $r):bool=>$r['public_id']===$shiftOnly['public_id']))===0,'Specific shift note leaked to another shift.');

$blocked=false;try{employee_shift_validate_target($pdo,$org,'location',$otherLoc);}catch(InvalidArgumentException){$blocked=true;}sh_must($blocked,'Cross-organization location target was accepted.');
$brief=employee_shift_arrival_brief($pdo,$org,$next);sh_must(!empty($brief['active']),'Upcoming employee shift did not activate arrival brief.');sh_must((int)$brief['summary']['unreadHandoffs']>=1,'Arrival brief did not include unread handoff communications.');
$aud=employee_shift_audiences($pdo,$org);sh_must(count($aud['locations'])===2&&count($aud['positions'])===2,'Manager audience catalog is incomplete.');sh_must(count($aud['shifts'])>=3&&count($aud['users'])===4,'Manager shift/user audience catalog is incomplete.');

// B8D17G2G: live prep readiness follows the next published shift and
// never reveals another employee's assigned tasks or old blocker reasons.
sh_must(prep_intelligence_ready($pdo),'Prep Intelligence tables are required for live prep handoff.');
$prepDate=(new DateTimeImmutable((string)$brief['shift']['starts_at']))->format('Y-m-d');
$prepPlan=prep_get_or_create_plan($pdo,$org,$prepDate,'all',$manager);
$nextPrep=prep_attach_manual_task($pdo,$org,$prepPlan,
    ['title'=>'Pizza dough','quantity'=>2,'unit'=>'tray','assigneeIds'=>[$next]],$manager,'contract');
$northPrep=prep_attach_manual_task($pdo,$org,$prepPlan,
    ['title'=>'North-only sauce','quantity'=>1,'unit'=>'pan','assigneeIds'=>[$north]],$manager,'contract');
$unassignedPrep=prep_attach_manual_task($pdo,$org,$prepPlan,
    ['title'=>'Manager-only garlic knots','quantity'=>3,'unit'=>'tray'],$manager,'contract');
$pdo->prepare("UPDATE restaurant_tasks SET status='blocked' WHERE organization_id=? AND public_id=?")
    ->execute([$org,$nextPrep['public_id']]);
$blockedId=(int)operations_task_by_public_id($pdo,$org,(string)$nextPrep['public_id'])['id'];
operations_task_event($pdo,$org,$blockedId,'prep_blocker_reported','Mixer needs service.',$next,
    ['reason'=>'mixer overheated']);
$staffViewer=['id'=>$next,'organization_id'=>$org,
    'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.self','employee.self']];
$northViewer=['id'=>$north,'organization_id'=>$org,
    'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.self','employee.self']];
$managerViewer=['id'=>$manager,'organization_id'=>$org,
    'permissions'=>['prep.intelligence.view','prep.intelligence.agent','prep.intelligence.manage','tasks.manage','employee.self']];
$nextBrief=employee_shift_arrival_brief($pdo,$org,$next,$staffViewer);
$prep=$nextBrief['prep']??null;
sh_must(is_array($prep)&&($prep['counts']['total']??-1)===1&&($prep['counts']['blocked']??-1)===1,
    'Incoming employee receives only their blocked assigned prep task.');
sh_must(($nextBrief['summary']['prepBlocked']??-1)===1
    &&str_contains((string)$prep['summary'],'mixer overheated')
    &&!str_contains((string)$prep['summary'],'North-only sauce')
    &&!str_contains((string)$prep['summary'],'Manager-only garlic knots'),
    'Live arrival briefing contains current blocker reason without exposing other employees or unassigned work.');
$northBrief=employee_shift_arrival_brief($pdo,$org,$north,$northViewer);
sh_must(($northBrief['prep']['counts']['total']??-1)===1
    &&!str_contains((string)($northBrief['prep']['summary']??''),'Pizza dough'),
    'Second location cannot read another employee\'s prep blocker.');
$noPrepViewer=['id'=>$next,'organization_id'=>$org,'permissions'=>['employee.self']];
$hidden=employee_shift_arrival_brief($pdo,$org,$next,$noPrepViewer);
sh_must(($hidden['prep']??null)===null&&($hidden['summary']['prepBlocked']??-1)===0,
    'Employees without Prep Intelligence permission receive no prep detail in arrival briefs.');
$managerPrep=pac_shift_handoff($pdo,$org,$manager,$managerViewer,null,
    'Prep handoff for dinner on '.$prepDate);
sh_must(($managerPrep['data']['counts']['total']??-1)===3
    &&($managerPrep['data']['counts']['unassigned']??-1)===1
    &&str_contains($managerPrep['answer'],'Manager-only garlic knots'),
    'Manager receives complete organization-scoped service handoff with unassigned tasks.');
$foreignPrep=pac_shift_handoff($pdo,$otherOrg,$manager,$managerViewer,null,
    'Prep handoff for dinner on '.$prepDate);
sh_must(($foreignPrep['data']['counts']['total']??-1)===0
    &&!str_contains($foreignPrep['answer'],'Pizza dough'),
    'Cross-organization prep handoff cannot read original restaurant tasks.');
$pdo->prepare("UPDATE restaurant_tasks SET status='completed' WHERE organization_id=? AND public_id=?")
    ->execute([$org,$nextPrep['public_id']]);
$updatedBrief=employee_shift_arrival_brief($pdo,$org,$next,$staffViewer);
sh_must(($updatedBrief['prep']['counts']['blocked']??-1)===0
    &&($updatedBrief['prep']['counts']['completed']??-1)===1
    &&!str_contains((string)$updatedBrief['prep']['summary'],'mixer overheated'),
    'Resolved/completed prep immediately updates the handoff without stale blocker history.');
$handoffSource=(string)file_get_contents(__DIR__.'/../api/proactive-agent.php');
sh_must(str_contains($handoffSource,'prepBlocked')
    &&str_contains($handoffSource,'employee_shift_arrival_brief($pdo,$org,$uid,$user)'),
    'Proactive arrival events include live scoped prep risk.');
$voiceSource=(string)file_get_contents(__DIR__.'/../includes/prep-agent-core.php');
sh_must(str_contains($voiceSource,"'prep.shift_handoff_unavailable'"),
    'Conversational prep handoff is routed through the canonical Prep Agent.');

employee_shift_message_resolve($pdo,$org,(string)$handoff['public_id'],$manager);$nextRows=employee_shift_messages_for_user($pdo,$org,$next);sh_must(count(array_filter($nextRows,static fn(array $r):bool=>$r['public_id']===$handoff['public_id']))===0,'Resolved handoff remained active.');$history=employee_shift_messages_for_user($pdo,$org,$manager,true,true);sh_must(count(array_filter($history,static fn(array $r):bool=>$r['public_id']===$handoff['public_id']&&$r['status']==='resolved'))===1,'Resolved handoff missing from manager history.');

echo "shift-handoff-ok\n";
