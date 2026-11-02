<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/daily-quicklist-core.php';
function dq_assert(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
$pdo=app_pdo();
dq_assert(daily_quicklist_ready($pdo),'new migration must provide items and append-only events');
dq_assert(daily_quicklist_can_view(['permissions'=>['tasks.self']]),'staff can use daily lists');
dq_assert(!daily_quicklist_can_view(['permissions'=>['schedule.self']]),'unrelated accounts cannot view operational lists');
dq_assert(!daily_quicklist_can_manage(['permissions'=>['tasks.self']]),'staff may not manage notes or statuses');
dq_assert(daily_quicklist_can_manage(['permissions'=>['tasks.manage']]),'managers may update notes and statuses');
dq_assert(daily_quicklist_date('2026-11-02')==='2026-11-02','valid working date');
try{daily_quicklist_date('2026-02-30');throw new RuntimeException('invalid date accepted');}catch(InvalidArgumentException){}
try{daily_quicklist_title(str_repeat('x',221));throw new RuntimeException('unbounded item accepted');}catch(InvalidArgumentException){}
$orgName='Quicklist contract '.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$orgName]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute(['quicklist-'.bin2hex(random_bytes(8)).'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Daily','Staff','Daily Staff']);
$actor=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$actor]);
$recipeId='recipe-daily-test';
$pdo->prepare("INSERT INTO recipes(organization_id,public_id,name,status,yield_quantity,yield_unit,ingredients_json,instructions_json,notes,created_by)
    VALUES (?,?,?,'active',?,?,?,?,'Keep covered',?)")->execute([$org,$recipeId,'Pizza Dough',8,'balls',
        json_encode([['quantity'=>'2','unit'=>'kg','ingredient'=>'Flour']],JSON_THROW_ON_ERROR),
        json_encode(['Mix ingredients','Let rise'],JSON_THROW_ON_ERROR),$actor]);
$recipes=daily_quicklist_recipes($pdo,$org);
dq_assert(count($recipes)===1&&$recipes[0]['yieldQuantity']===8.0,'recipe yield retained');
dq_assert(($recipes[0]['ingredients'][0]['ingredient']??'')==='Flour','recipe ingredient quantity and name retained');
dq_assert(($recipes[0]['instructions'][1]??'')==='Let rise','recipe method retained');
$day='2026-11-02';
$prepId=daily_quicklist_add($pdo,$org,$actor,['type'=>'prep','date'=>$day,'title'=>'Prepare Pizza Dough','recipeId'=>$recipeId],true);
$items=daily_quicklist_items($pdo,$org,$day);
dq_assert(count($items)===1&&$items[0]['recipeId']===$recipeId,'canonical linked recipe stored on dated prep item');
dq_assert($items[0]['status']==='todo','initial prep status');
dq_assert(!daily_quicklist_items($pdo,$org,'2026-11-03'),'prep list is date-scoped');
try{daily_quicklist_add($pdo,$org,$actor,['type'=>'prep','date'=>$day,'title'=>'Unapproved recipe','recipeId'=>$recipeId],false);
    throw new RuntimeException('staff without Recipes View linked private recipe');}catch(InvalidArgumentException){}
try{daily_quicklist_add($pdo,$org,$actor,['type'=>'prep','date'=>$day,'title'=>'Foreign recipe','recipeId'=>'recipe-outside-tenant'],true);
    throw new RuntimeException('invalid recipe linked');}catch(InvalidArgumentException){}
daily_quicklist_mutate($pdo,$org,$actor,'timer',['id'=>$prepId,'mode'=>'start'],false);
$pdo->prepare("UPDATE daily_quicklist_items SET timer_started_at=UTC_TIMESTAMP(6)-INTERVAL 92 SECOND WHERE organization_id=? AND public_id=?")->execute([$org,$prepId]);
$paused=daily_quicklist_mutate($pdo,$org,$actor,'timer',['id'=>$prepId,'mode'=>'pause'],false);
$items=daily_quicklist_items($pdo,$org,$day);
dq_assert($paused['status']==='paused'&&!$items[0]['timerRunning']&&$items[0]['elapsedSeconds']>=90,'pause persists elapsed time');
daily_quicklist_mutate($pdo,$org,$actor,'timer',['id'=>$prepId,'mode'=>'resume'],false);
$pdo->prepare("UPDATE daily_quicklist_items SET timer_started_at=UTC_TIMESTAMP(6)-INTERVAL 35 SECOND WHERE organization_id=? AND public_id=?")->execute([$org,$prepId]);
daily_quicklist_mutate($pdo,$org,$actor,'toggle',['id'=>$prepId],false);
$items=daily_quicklist_items($pdo,$org,$day);
dq_assert($items[0]['status']==='done'&&!$items[0]['timerRunning']&&$items[0]['elapsedSeconds']>=125,'completion stops a running timer and retains elapsed time');
daily_quicklist_mutate($pdo,$org,$actor,'toggle',['id'=>$prepId],false);
dq_assert(daily_quicklist_items($pdo,$org,$day)[0]['status']==='todo','staff can undo completion');
try{daily_quicklist_mutate($pdo,$org,$actor,'update',['id'=>$prepId,'status'=>'done','adminNote'=>'Do not allow'],false);
    throw new RuntimeException('staff changed manager note');}catch(InvalidArgumentException){}
daily_quicklist_mutate($pdo,$org,$actor,'update',['id'=>$prepId,'status'=>'in_progress','adminNote'=>'Check gluten development'],true);
dq_assert(daily_quicklist_items($pdo,$org,$day)[0]['adminNote']==='Check gluten development','manager note persisted');
$stockId='stock-daily-test';
$pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,created_by)
    VALUES (?,?,?,?,?,?,?)")->execute([$org,$stockId,'daily-flour','Flour','kg',2,$actor]);
$stock=daily_quicklist_inventory($pdo,$org);
dq_assert(count($stock)===1&&$stock[0]['onHand']===2.0,'inventory directory is a read-only stock snapshot');
$needId=daily_quicklist_add($pdo,$org,$actor,['type'=>'inventory','title'=>'Ignored client title','inventoryId'=>$stockId,'quantityNote'=>'5 kg'],false);
$need=daily_quicklist_items($pdo,$org,$day);
dq_assert(count($need)===2&&$need[0]['type']==='inventory'&&$need[0]['title']==='Flour','linked inventory name comes from server');
try{daily_quicklist_add($pdo,$org,$actor,['type'=>'inventory','title'=>'Flour','inventoryId'=>$stockId],false);
    throw new RuntimeException('duplicate Need More item accepted');}catch(InvalidArgumentException){}
daily_quicklist_mutate($pdo,$org,$actor,'update',['id'=>$needId,'status'=>'ordered','adminNote'=>'Vendor contacted'],true);
dq_assert(daily_quicklist_items($pdo,$org,$day)[0]['status']==='ordered','admin status transitions');
daily_quicklist_mutate($pdo,$org,$actor,'update',['id'=>$needId,'status'=>'received','adminNote'=>'Received at back door'],true);
$q=$pdo->prepare('SELECT on_hand_quantity FROM inventory_items WHERE organization_id=? AND public_id=?');
$q->execute([$org,$stockId]);dq_assert((float)$q->fetchColumn()===2.0,'Need More receipt must not alter physical stock');
$needIdAgain=daily_quicklist_add($pdo,$org,$actor,['type'=>'inventory','title'=>'Flour','inventoryId'=>$stockId],false);
dq_assert($needIdAgain!==$needId,'received request does not block a later shortage');
$history=daily_quicklist_history($pdo,$org,$prepId);
dq_assert(count($history)>=6&&$history[0]['event']==='update','audit history has prep status and timer events');
daily_quicklist_mutate($pdo,$org,$actor,'archive',['id'=>$prepId],false);
dq_assert(count(daily_quicklist_items($pdo,$org,$day))===2,'staff removal is a soft archive');
dq_assert(count(daily_quicklist_history($pdo,$org,$prepId))>=7,'history survives staff removal');
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES ('Quicklist other tenant','active','America/Phoenix')")->execute();$other=(int)$pdo->lastInsertId();
dq_assert(!daily_quicklist_items($pdo,$other,$day),'no cross-organization list leakage');
try{daily_quicklist_mutate($pdo,$other,$actor,'archive',['id'=>$needId],false);
    throw new RuntimeException('cross-tenant removal accepted');}catch(InvalidArgumentException){}
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute(['quicklist-manager-'.bin2hex(random_bytes(8)).'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Daily','Manager','Daily Manager']);
$manager=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$manager]);$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO roles(organization_id,name,slug,is_owner_role) VALUES (?,?,?,1)")->execute([$org,'Quicklist Manager','daily-owner']);
$role=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles(membership_id,role_id) VALUES (?,?)")->execute([$membership,$role]);
$notifications=daily_quicklist_notify_managers($pdo,$org,$actor,'Daily checklist','Please check prep.');
dq_assert($notifications===1,'manager notifications target eligible recipients only');
$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE organization_id=? AND user_id=? AND notification_type='daily_quicklist.update'");
$q->execute([$org,$manager]);dq_assert((int)$q->fetchColumn()>=1,'notification is recorded in existing inbox');
dq_assert(is_file(__DIR__.'/../daily-prep.php')&&is_file(__DIR__.'/../js/daily-prep.js'),'staff UI and timer code bundled');
echo "daily-prep-quicklist contract passed\n";
