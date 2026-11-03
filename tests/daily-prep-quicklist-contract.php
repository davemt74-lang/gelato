<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/hardening-agent-confirmation-test-support.php';
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

// Section 3K2A: batch yield projections use saved recipes and preserve qualitative amounts.
$source=['yieldQuantity'=>8.0,'yieldUnit'=>'portions','ingredients'=>[
    ['quantity'=>'1 1/2','unit'=>'kg','name'=>'Mozzarella'],
    ['quantity'=>'½','unit'=>'cup','name'=>'Olive oil'],
    ['quantity'=>'to taste','unit'=>'','name'=>'Salt'],
    ['quantity'=>'1-2','unit'=>'pinches','name'=>'Herbs'],
    'Additional garnish as desired',
]];
$sourceBefore=json_encode($source,JSON_THROW_ON_ERROR);
$projected=daily_quicklist_scale_recipe($source,16.0,'portions');
dq_assert($projected['scalable']&&$projected['factor']===2.0,'double batch factor');
dq_assert($projected['ingredients'][0]['quantity']==='3','mixed fraction scales to accurate numeric quantity');
dq_assert($projected['ingredients'][1]['quantity']==='1','unicode fraction scales accurately');
dq_assert($projected['ingredients'][2]['quantity']==='to taste','qualitative seasoning is never invented');
dq_assert($projected['ingredients'][3]['quantity']==='1-2','range never silently scaled');
dq_assert($projected['unscaledCount']===3,'unstructured amounts explicitly flagged');
dq_assert(json_encode($source,JSON_THROW_ON_ERROR)===$sourceBefore,'projection does not edit saved source');
dq_assert(!daily_quicklist_scale_recipe($source,16.0,'kg')['scalable'],'unit drift prevents unsafe scaling');
dq_assert(!daily_quicklist_scale_recipe($source,16.0,null)['scalable'],'missing stored unit cannot silently override recipe unit');
dq_assert(!daily_quicklist_scale_recipe(['yieldQuantity'=>null,'yieldUnit'=>'','ingredients'=>[]],4.0,null)['scalable'],
    'missing canonical yield cannot be scaled');
try{daily_quicklist_scale_recipe($source,10001.0,'portions');throw new RuntimeException('unbounded scaling accepted');}
catch(InvalidArgumentException){}
dq_assert(daily_quicklist_numeric_quantity('1/0')===null,'zero denominator safely rejected');
dq_assert(daily_quicklist_numeric_quantity('as needed')===null,'qualitative amounts are not parsed');
$targetItem=daily_quicklist_add($pdo,$org,$actor,
    ['type'=>'prep','date'=>$day,'title'=>'Scaled dough batch','recipeId'=>$recipeId],true);
try{daily_quicklist_mutate($pdo,$org,$actor,'set_target',
    ['id'=>$targetItem,'targetYieldQuantity'=>16],false,false);
    throw new RuntimeException('Recipe access check bypassed');}catch(InvalidArgumentException){}
try{daily_quicklist_mutate($pdo,$org,$actor,'set_target',
    ['id'=>$targetItem,'targetYieldQuantity'=>0],false,true);
    throw new RuntimeException('zero batch target accepted');}catch(InvalidArgumentException){}
try{daily_quicklist_mutate($pdo,$org,$actor,'set_target',
    ['id'=>$needId,'targetYieldQuantity'=>16],false,true);
    throw new RuntimeException('inventory item accepted recipe yield');}catch(InvalidArgumentException){}
$targetResult=daily_quicklist_mutate($pdo,$org,$actor,'set_target',
    ['id'=>$targetItem,'targetYieldQuantity'=>16],false,true);
dq_assert($targetResult['id']===$targetItem,'staff can persist an authorized target');
$items=daily_quicklist_items($pdo,$org,$day);
$targetRow=current(array_values(array_filter($items,static fn(array $r):bool=>$r['id']===$targetItem)));
dq_assert($targetRow['targetYieldQuantity']===16.0&&$targetRow['targetYieldUnit']==='balls',
    'target and canonical unit persisted on shared checklist');
$projections=daily_quicklist_recipe_projections($items,$recipes);
dq_assert($projections[$targetItem]['factor']===2.0,'shared list projects double recipe yield');
dq_assert($projections[$targetItem]['ingredients'][0]['quantity']==='4','stored batch target scales recipe flour');
$q=$pdo->prepare('SELECT yield_quantity,ingredients_json FROM recipes WHERE organization_id=? AND public_id=?');
$q->execute([$org,$recipeId]);$canonical=$q->fetch();
dq_assert((float)$canonical['yield_quantity']===8.0,'batch target does not change saved recipe yield');
dq_assert(json_decode($canonical['ingredients_json'],true)[0]['quantity']==='2',
    'batch target does not change saved recipe ingredient amounts');
$history=daily_quicklist_history($pdo,$org,$targetItem);
dq_assert($history[0]['event']==='target_set'&&(float)($history[0]['details']['after']['targetYieldQuantity']??0)===16.0,
    'target edits recorded in the quicklist event history');
try{daily_quicklist_mutate($pdo,$other,$actor,'set_target',
    ['id'=>$targetItem,'targetYieldQuantity'=>20],false,true);
    throw new RuntimeException('cross-tenant target mutation accepted');}catch(InvalidArgumentException){}
dq_assert(daily_quicklist_items($pdo,$other,$day)===[],'target update does not leak across tenants');


// Section 3K2B: menu suggestions use published source records, never derived sales quantities.
$menuExample=[['name'=>'Pizza','items'=>[
    ['name'=>'Gelato Pizza','ingredients'=>['Pizza Dough','Basil']],
    ['name'=>'Calzone','ingredients'=>['Pizza Dough','Mozzarella']],
]]];
$hist=[['title'=>'Prep Pizza Dough','recommendedBase'=>8.5,'unit'=>'balls','sampleCount'=>4,'confidence'=>0.75]];
$preview=daily_quicklist_suggestions_from_menu($menuExample,$recipes,$hist);
$doughRows=array_values(array_filter($preview,static fn(array $r):bool=>$r['title']==='Prep Pizza Dough'));
dq_assert(count($doughRows)===1,'repeated menu ingredients collapse into one suggested prep item');
$dough=$doughRows[0];
dq_assert($dough['menuCount']===2&&count($dough['menuDishes'])===2,'menu references count distinct published dishes');
dq_assert($dough['recipeId']===$recipeId,'only an exact verified saved recipe is linked');
dq_assert($dough['historicalQuantity']===8.5&&$dough['historySamples']===4,'sufficient comparable completed prep history is advisory');
dq_assert(str_starts_with($dough['key'],'ing-'),'menu candidate has a deterministic server-side key');
$untrusted=daily_quicklist_suggestions_from_menu($menuExample,$recipes,
    [['title'=>'Prep Pizza Dough','recommendedBase'=>999,'unit'=>'balls','sampleCount'=>1,'confidence'=>1]]);
dq_assert($untrusted[0]['historicalQuantity']===null,'one prior task is insufficient to infer demand');
$dupRecipes=[$recipes[0],array_merge($recipes[0],['id'=>'recipe-duplicate'])];
$ambiguous=daily_quicklist_suggestions_from_menu($menuExample,$dupRecipes,[]);
dq_assert($ambiguous[0]['recipeId']===null,'ambiguous recipe names cannot be guessed');
$fingerprint=daily_quicklist_selection_fingerprint([$dough]);
$changed=$dough;$changed['menuCount']=3;
dq_assert(!hash_equals($fingerprint,daily_quicklist_selection_fingerprint([$changed])),
    'menu snapshot fingerprint rejects changed source evidence');

require_once __DIR__.'/../includes/prep-agent-core.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
dq_assert(menu_manager_ready($pdo),'menu manager schema must be installed for generation');
$cat=pos_transaction($pdo,fn():array=>menu_manager_category_save($pdo,$org,null,['name'=>'Gelato CI Pizza','sortOrder'=>10,'status'=>'active']));
$menuItem=menu_manager_save_item($pdo,$org,null,[
    'name'=>'Gelato CI Pizza','categoryId'=>$cat['id'],
    'sizes'=>[['clientKey'=>'standard','label'=>'Standard','amount'=>15.00]],
    'ingredients'=>[['name'=>'Pizza Dough'],['name'=>'Basil']],
    'distribution'=>['publicMenu'=>true],
],$actor);
$menuItem=menu_manager_set_status($pdo,$org,(int)$menuItem['id'],'publish',$actor);
$live=daily_quicklist_menu_suggestions($pdo,$org,$recipes,$day);
dq_assert(count($live)>=2,'published menu provides real ingredient checklist suggestions');
$doughLive=array_values(array_filter($live,static fn(array $r):bool=>$r['title']==='Prep Pizza Dough'));
dq_assert(count($doughLive)===1&&$doughLive[0]['recipeId']===$recipeId,
    'real published menu ingredient connects to the exact saved recipe');
$selectedKeys=array_column($live,'key');
try{daily_quicklist_apply_menu_selection($pdo,$org,$actor,$day,$selectedKeys,true,'old-menu-snapshot');
    throw new RuntimeException('stale menu snapshot accepted');}catch(InvalidArgumentException){}
$staffAgent=['id'=>$actor,'organization_id'=>$org,
    'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.self','recipes.view','menu.view']];
$route=gaw_route($staffAgent,'Generate daily prep checklist from the menu');
dq_assert($route['route']===null&&$route['domain']==='daily_prep_model_tools','menu-backed prep routes to registered native model tools');
$route=gaw_route($staffAgent,'What is on our Need More list?');
dq_assert($route['route']===null&&$route['domain']==='daily_prep_model_tools','Need More quicklist reads use native model tools');
$route=gaw_route($staffAgent,'Show the published menu');
dq_assert($route['route']==='api/agent-brain.php','plain menu lookups keep their canonical Brain route');
app_boot_session();
$read=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Show daily prep checklist for '.$day]));
dq_assert($read['skill']==='prep.daily_quicklist'&&($read['data']['proof']['url']??'')==='daily-prep.php'
    &&str_contains($read['answer'],'Need More'),
    'official agent reads the shared quicklist and returns a real UI link');
$voiceProposal=prep_agent_handle($pdo,$staffAgent,
    hac_test_input($pdo,$staffAgent,'prep',['message'=>'Generate daily prep checklist for '.$day.' from menu','voice'=>true]));
dq_assert($voiceProposal['skill']==='prep.action_proposal','authorized voice and typed requests use the same proposal path');
$cancelled=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Cancel','voice'=>true]));
dq_assert($cancelled['skill']==='prep.action_cancelled','spoken cancellation does not create tasks');
$proposal=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Generate daily prep checklist for '.$day.' from menu']));
dq_assert($proposal['skill']==='prep.action_proposal','agent generation is confirmation-only');
$pending=gac_pending_get('prep',$org,$actor);
dq_assert($pending!==null&&$pending['type']==='quicklist_generate'&&count($pending['payload']['keys'])>=1,
    'official Prep Agent stores server-selected menu keys, not arbitrary titles');
$confirmed=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
dq_assert($confirmed['skill']==='prep.action_confirmed'
    &&($confirmed['data']['proof']['url']??'')==='daily-prep.php',
    'confirmation writes the same shared Daily Prep checklist as the UI');
$created=$confirmed['data']['created'];
dq_assert(count($created)>=1,'confirmed generation creates menu-backed prep items');
$recipeLinked=array_values(array_filter($created,static fn(array $i):bool=>$i['recipeId']===$recipeId));
dq_assert(count($recipeLinked)===1,'menu-generated item retains canonical saved recipe reference');
$retry=daily_quicklist_apply_menu_selection($pdo,$org,$actor,$day,$selectedKeys,true);
dq_assert(count($retry['created'])===0&&count($retry['skipped'])===count($selectedKeys),
    'repeated UI/agent generation skips existing items instead of duplicating them');
$generatedHistory=daily_quicklist_history($pdo,$org,$created[0]['id']);
dq_assert($generatedHistory[0]['event']==='menu_generated','menu generation records append-only source evidence');
try{daily_quicklist_apply_menu_selection($pdo,$other,$actor,$day,$selectedKeys,true);
    throw new RuntimeException('cross-tenant menu keys accepted');}catch(InvalidArgumentException){}
dq_assert(!gac_pending_get('prep',$org,$actor),'successful confirmation consumes the pending action');


// Section 3K2C: official Agent/Brain/Chat proposals for each staff and manager action.
$agentDay=prep_parse_date_phrase('Daily Prep');
dq_assert(pqa_parse_request('Mark Agent Batch done on Daily Prep')['op']==='toggle','typed prep check-off intent');
dq_assert(pqa_parse_request('Start timer for Agent Batch on Daily Prep')['op']==='timer','typed timer start intent');
dq_assert(pqa_parse_request('Pause Agent Batch timer on Daily Prep')['mode']==='pause','typed timer pause intent');
dq_assert(pqa_parse_request('Set Agent Batch batch target to 16 balls on Daily Prep')['op']==='set_target','typed recipe batch target');
dq_assert(pqa_parse_request('Add Pizza Boxes to Need More: 2 cases')['op']==='add_need_more','typed Need More request');
dq_assert(pqa_parse_request('Mark Flour as ordered on Need More')['op']==='update','manager status intent');
dq_assert(pqa_parse_request('Set manager note for Flour on Need More: Vendor contacted')['op']==='update','manager notes intent');
dq_assert(pqa_parse_request('Notify managers about Flour on Need More')['op']==='notify','manager notification intent');
dq_assert(pqa_parse_request('What do we need to prep for dinner?')===null,'formal prep plan requests are not stolen');
dq_assert(pqa_parse_request('Start my break')===null,'Time Clock action is never stolen');
dq_assert(gaw_route($staffAgent,'Start timer for Agent Batch on Daily Prep')['route']===null,
    'timer requests route through the official Prep Agent');
dq_assert(gaw_route($staffAgent,'Add Pizza Boxes to Need More')['route']===null,
    'Need More mutations route to the official Prep Agent ahead of inventory tools');

$agentItem=daily_quicklist_add($pdo,$org,$actor,
    ['type'=>'prep','date'=>$agentDay,'title'=>'Agent Batch','recipeId'=>$recipeId],true);
// Ordinal follow-ups need two items on the agent's current working date. Earlier
// fixtures deliberately use 2026-11-02 and must not be counted as today's items.
daily_quicklist_add($pdo,$org,$actor,
    ['type'=>'prep','date'=>$agentDay,'title'=>'Agent Numbered Companion'],true);
$voiceStart=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Start timer for Agent Batch on Daily Prep','voice'=>true]));
dq_assert($voiceStart['skill']==='prep.action_proposal'
    &&($voiceStart['data']['requiresConfirmation']??false),'voice timer request only proposes');
dq_assert(!current(array_values(array_filter(daily_quicklist_items($pdo,$org,$agentDay),
    static fn(array $r):bool=>$r['id']===$agentItem)))['timerRunning'],'voice proposal did not start a timer');
$voiceStartDone=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm','voice'=>true]));
dq_assert($voiceStartDone['skill']==='prep.action_confirmed'
    &&($voiceStartDone['data']['proof']['url']??'')==='daily-prep.php','confirmed spoken timer uses shared checklist');
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['timerRunning']&&$agentRow['status']==='in_progress','confirmed timer actually starts');
$pause=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Pause Agent Batch timer on Daily Prep']));
dq_assert($pause['skill']==='prep.action_proposal','pause requires confirmation');
$cancel=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Cancel']));
dq_assert($cancel['skill']==='prep.action_cancelled','cancel must not pause timer');
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['timerRunning'],'cancel leaves timer running');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Pause Agent Batch timer on Daily Prep']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert(!$agentRow['timerRunning']&&$agentRow['status']==='paused','pause persists through official agent');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Resume Agent Batch timer on Daily Prep']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['timerRunning'],'resume restarts same timer');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Stop Agent Batch timer on Daily Prep']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert(!$agentRow['timerRunning']&&$agentRow['status']==='todo','stop leaves item open');

prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Mark Agent Batch done on Daily Prep']));
$done=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
dq_assert($done['skill']==='prep.action_confirmed','agent can check off daily prep');
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['status']==='done','confirmed prep check-off stored');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Reopen Agent Batch on Daily Prep']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['status']==='todo','undo path is reversible');

$proposedTarget=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Set Agent Batch batch target to 16 balls on Daily Prep']));
dq_assert($proposedTarget['skill']==='prep.action_proposal','batch target is confirmation-only');
$noRecipeAgent=$staffAgent;$noRecipeAgent['permissions']=array_values(array_diff($staffAgent['permissions'],['recipes.view']));
try{prep_agent_handle($pdo,$noRecipeAgent,hac_test_input($pdo,$noRecipeAgent,'prep',['message'=>'Confirm']));throw new RuntimeException('revoked recipe permission accepted');}
catch(PrepAgentPermissionException){}
dq_assert(!gac_pending_get('prep',$org,$actor),'failed confirmation clears pending target');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Set Agent Batch batch target to 16 balls on Daily Prep']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$agentRows=daily_quicklist_items($pdo,$org,$agentDay);
$agentRow=current(array_values(array_filter($agentRows,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($agentRow['targetYieldQuantity']===16.0&&$agentRow['targetYieldUnit']==='balls',
    'agent target uses canonical recipe unit, never changes physical inventory');
try{prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Set Agent Batch batch target to 16 kg on Daily Prep']));
    throw new RuntimeException('wrong recipe unit accepted');}catch(InvalidArgumentException){}

$stale=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Mark Agent Batch done on Daily Prep']));
dq_assert($stale['skill']==='prep.action_proposal','stale mutation setup');
daily_quicklist_mutate($pdo,$org,$actor,'timer',['id'=>$agentItem,'mode'=>'start'],false,true);
try{prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));throw new RuntimeException('stale checklist action accepted');}
catch(InvalidArgumentException){}
dq_assert(!gac_pending_get('prep',$org,$actor),'stale proposal is consumed without changing task');

$managerAgent=['id'=>$manager,'organization_id'=>$org,
    'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.manage','inventory.view','recipes.view']];
try{prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Mark Flour as ordered on Need More']));
    throw new RuntimeException('staff manager action was proposed');}catch(PrepAgentPermissionException){}
$manageProposal=prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Mark Flour as ordered on Need More']));
dq_assert($manageProposal['skill']==='prep.action_proposal','manager can propose status update');
$notManager=$managerAgent;$notManager['permissions']=array_values(array_diff($managerAgent['permissions'],['tasks.manage']));
try{prep_agent_handle($pdo,$notManager,hac_test_input($pdo,$notManager,'prep',['message'=>'Confirm']));
    throw new RuntimeException('manager permission revocation bypassed');}catch(PrepAgentPermissionException){}
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Mark Flour as ordered on Need More']));
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
$needRows=daily_quicklist_items($pdo,$org,$agentDay);
$flourRow=current(array_values(array_filter($needRows,static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($flourRow['status']==='ordered','manager can update Need More status via agent');
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Set manager note for Flour on Need More: Vendor contacted']));
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
$needRows=daily_quicklist_items($pdo,$org,$agentDay);
$flourRow=current(array_values(array_filter($needRows,static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($flourRow['adminNote']==='Vendor contacted','agent manager note persists');
$notify=prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Notify managers about Flour on Need More']));
dq_assert($notify['skill']==='prep.action_proposal','notification must be confirmed');
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
dq_assert(daily_quicklist_history($pdo,$org,$needIdAgain)[0]['event']==='notified',
    'manager notice recorded in existing notification/history systems');

$stockBefore=$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE public_id='stock-daily-test'")->fetchColumn();
$newNeed=prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Add Pizza Boxes to Need More: 2 cases']));
dq_assert($newNeed['skill']==='prep.action_proposal','ad hoc Need More requires confirmation');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$needRows=daily_quicklist_items($pdo,$org,$agentDay);
$boxes=array_values(array_filter($needRows,static fn(array $r):bool=>$r['title']==='Pizza Boxes'));
dq_assert(count($boxes)===1&&$boxes[0]['quantityNote']==='2 cases','ad hoc Need More created in shared UI');
try{prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Add Pizza Boxes to Need More']));
    throw new RuntimeException('duplicate Need More proposal accepted');}catch(InvalidArgumentException){}
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Remove Pizza Boxes from Need More']));
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$needRows=daily_quicklist_items($pdo,$org,$agentDay);
dq_assert(!array_filter($needRows,static fn(array $r):bool=>$r['title']==='Pizza Boxes'),
    'staff removal is a recoverable archive');
dq_assert(count(daily_quicklist_history($pdo,$org,$boxes[0]['id']))>=2,
    'archived Need More item keeps audit history');
$stockAfter=$pdo->query('SELECT on_hand_quantity FROM inventory_items WHERE public_id="stock-daily-test"')->fetchColumn();
dq_assert((float)$stockAfter===(float)$stockBefore,'agent Need More actions never change inventory on hand');
try{pqa_resolve_item($pdo,$other,$agentDay,'inventory','Flour');
    throw new RuntimeException('agent lookup crossed tenant boundary');}catch(InvalidArgumentException){}
dq_assert(!gac_pending_get('prep',$org,$actor)&&!gac_pending_get('prep',$org,$manager),
    'all successful agent actions consume pending proposals');


// Section 3K2D: native Food Monster model-selected Daily Prep tools and
// server-owned conversation references (not fabricated tool text or row IDs).
require_once __DIR__.'/../includes/food-monster-llm.php';
require_once __DIR__.'/../includes/food-monster-b8d3-tool-runtime.php';
$chatThread='agent-'.str_repeat('b',20);
$chatContext=['threadId'=>$chatThread,'channel'=>'text'];
$managerChatThread='agent-'.str_repeat('d',20);
$managerChatContext=['threadId'=>$managerChatThread,'channel'=>'text'];
$nativeCatalogue=fm8d18j11_catalogue($staffAgent,$chatContext);
dq_assert(isset($nativeCatalogue['get_daily_prep_checklist'])
    &&isset($nativeCatalogue['propose_daily_prep_action']),
    'authorized signed-in staff have registered native model-selected Daily Prep tools');
dq_assert(!fm8d18j11_catalogue(['id'=>$actor,'organization_id'=>$org,'permissions'=>[]],$chatContext),
    'unprivileged accounts cannot discover private checklist tools');
dq_assert(!fm8d18j11_catalogue($staffAgent,
    ['threadId'=>$chatThread,'channel'=>'voice','privateVoiceApproved'=>false]),
    'unverified voice cannot select private Daily Prep tools');
try{fm8d18j11_read([],$staffAgent,['channel'=>'voice','privateVoiceApproved'=>false]);
    throw new RuntimeException('unverified speaker read private checklist');}catch(DomainException){}
$managerNative=fm8d18j11_catalogue($managerAgent,$managerChatContext);
dq_assert(!in_array('set_manager_note',
    $nativeCatalogue['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true)
    &&in_array('set_manager_note',
        $managerNative['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'manager-only model actions are absent from staff schema');
$badArguments=['action'=>'mark_done','list'=>'prep','reference_mode'=>'ordinal','ordinal'=>999];
dq_assert(fm8d18j11_action_fields($badArguments,$staffAgent)===['ordinal'],
    'out-of-range ordinals blocked before model tool execution');
$foreignActor=['id'=>$actor,'organization_id'=>$other,
    'permissions'=>$staffAgent['permissions']];
dq_assert(pqa_context_get($other,$actor,$chatThread)===null,
    'conversation selections are tenant scoped');
$nativeRead=fm8d18j11_read(['date'=>$agentDay],$staffAgent,$chatContext);
dq_assert(str_contains($nativeRead['data']['answer'],'Daily Prep for '.$agentDay)
    &&str_contains($nativeRead['data']['answer'],'1. ')
    &&str_contains($nativeRead['data']['answer'],'Need More'),
    'native model read returns real indexed prep and Need More records');
$selection=pqa_context_get($org,$actor,$chatThread);
dq_assert($selection!==null&&count($selection['lists']['prep'])>=2,
    'server stores numbered snapshot for follow-up selection');
dq_assert(gaw_route($staffAgent,'mark the second item done',$chatThread)['domain']==='daily_prep_model_tools',
    'ambiguous-looking short follow-up reaches the model with an existing numbered context');
dq_assert(gaw_route($staffAgent,'mark the second item done','agent-'.str_repeat('c',20))['domain']!=='daily_prep_model_tools',
    'context from another chat thread does not hijack an unrelated request');
$second=$selection['lists']['prep'][1];
$currentRows=daily_quicklist_items($pdo,$org,$agentDay);
$secondRow=current(array_values(array_filter($currentRows,static fn(array $r):bool=>$r['id']===$second['id'])));
$ordinalAction=$secondRow['status']==='done'?'reopen':'mark_done';
$ordinalProposal=fm8d18j11_propose([
    'action'=>$ordinalAction,'list'=>'prep','reference_mode'=>'ordinal','ordinal'=>2,'date'=>$agentDay,
],$staffAgent,hac_test_model_context($pdo,$staffAgent,$chatContext));
dq_assert(($ordinalProposal['data']['requiresConfirmation']??false)===true,
    'model-selected second item stages a confirmation, no immediate write');
$afterProposal=daily_quicklist_items($pdo,$org,$agentDay);
$secondBeforeConfirm=current(array_values(array_filter($afterProposal,static fn(array $r):bool=>$r['id']===$second['id'])));
dq_assert($secondBeforeConfirm['status']===$secondRow['status'],'ordinal proposal made no mutation');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$afterOrdinal=daily_quicklist_items($pdo,$org,$agentDay);
$secondAfter=current(array_values(array_filter($afterOrdinal,static fn(array $r):bool=>$r['id']===$second['id'])));
dq_assert($secondAfter['status']!==$secondRow['status'],'server-bound ordinal modified precisely the selected item');
try{fm8d18j11_propose(['action'=>'reopen','list'=>'prep',
    'reference_mode'=>'ordinal','ordinal'=>2,'date'=>$agentDay],$staffAgent,hac_test_model_context($pdo,$staffAgent,$chatContext));
    throw new RuntimeException('stale ordinal reused');}catch(InvalidArgumentException){}
dq_assert(fm8d18j11_action_fields(['action'=>'timer_pause','list'=>'prep',
    'reference_mode'=>'focused'],$staffAgent)===[],
    'focused timer action has a complete canonical schema');
$namedPause=fm8d18j11_propose([
    'action'=>'timer_pause','list'=>'prep','reference_mode'=>'name','name'=>'Agent Batch','date'=>$agentDay,
],$staffAgent,hac_test_model_context($pdo,$staffAgent,$chatContext));
dq_assert(($namedPause['data']['requiresConfirmation']??false)===true,'native selected timer pause stages proposal');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$focusedResume=fm8d18j11_propose([
    'action'=>'timer_resume','list'=>'prep','reference_mode'=>'focused','date'=>$agentDay,
],$staffAgent,hac_test_model_context($pdo,$staffAgent,$chatContext));
dq_assert(($focusedResume['data']['requiresConfirmation']??false)===true,
    '"that timer" resolves only to a previously selected, tenant-scoped task');
prep_agent_handle($pdo,$staffAgent,hac_test_input($pdo,$staffAgent,'prep',['message'=>'Confirm']));
$afterFocus=daily_quicklist_items($pdo,$org,$agentDay);
$focusedRow=current(array_values(array_filter($afterFocus,static fn(array $r):bool=>$r['id']===$agentItem)));
dq_assert($focusedRow['timerRunning'],'focused model action resumed only Agent Batch');
try{fm8d18j11_propose([
    'action'=>'timer_stop','list'=>'prep','reference_mode'=>'focused','date'=>$agentDay,
],$foreignActor,hac_test_model_context($pdo,$foreignActor,$chatContext));throw new RuntimeException('foreign tenant used conversation focus');}
catch(InvalidArgumentException|DomainException){}

$staffTools=fm8d3_catalogue($staffAgent,$chatContext);
dq_assert(isset($staffTools['get_daily_prep_checklist'])&&isset($staffTools['propose_daily_prep_action']),
    'Gelato real tool catalogue exposes both native Daily Prep functions');
$openaiTools=fm8d3_provider_tools('openai',$nativeCatalogue);
$anthropicTools=fm8d3_provider_tools('anthropic',$nativeCatalogue);
dq_assert(count($openaiTools)===2&&count($anthropicTools)===2
    &&in_array('propose_daily_prep_action',array_column($anthropicTools,'name'),true),
    'OpenAI and Claude receive the real parameter schemas');
$mockSelect=static fn()=>['output'=>[['type'=>'function_call','call_id'=>'daily01',
    'name'=>'get_daily_prep_checklist','arguments'=>'{"date":"'.$agentDay.'"}']]];
$mockAudit=static function(array $e):void{};
$mockRefresh=static fn():array=>$staffAgent;
$modelResponse=fm8d3_turn($pdo,$staffAgent,'openai','fake-key',
    [['role'=>'user','content'=>'Show today\'s Daily Prep']],
    '',$mockSelect,['get_daily_prep_checklist'=>$nativeCatalogue['get_daily_prep_checklist']],
    $mockRefresh,$mockAudit,$chatContext);
dq_assert($modelResponse['skill']==='agent.model_selected_tools'
    &&str_contains($modelResponse['answer'],'Daily Prep for '.$agentDay),
    'mocked provider model-selection executes registered native read through official runtime');
$mockClaude=static fn()=>['content'=>[['type'=>'tool_use','id'=>'toolu_daily',
    'name'=>'get_daily_prep_checklist','input'=>['date'=>$agentDay]]]];
$claudeResponse=fm8d3_turn($pdo,$staffAgent,'anthropic','fake-key',
    [['role'=>'user','content'=>'Show prep checklist']],
    '',$mockClaude,['get_daily_prep_checklist'=>$nativeCatalogue['get_daily_prep_checklist']],
    $mockRefresh,$mockAudit,$chatContext);
dq_assert($claudeResponse['skill']==='agent.model_selected_tools',
    'Claude model selection calls identical server-scoped read tool');
$mockDouble=static fn()=>['content'=>[
    ['type'=>'tool_use','id'=>'a','name'=>'get_daily_prep_checklist','input'=>[]],
    ['type'=>'tool_use','id'=>'b','name'=>'propose_daily_prep_action','input'=>[
        'action'=>'timer_stop','list'=>'prep','reference_mode'=>'name','name'=>'Agent Batch']],
]];
$blockedBundle=fm8d3_turn($pdo,$staffAgent,'anthropic','fake-key',
    [['role'=>'user','content'=>'Show prep and stop timer']],
    '',$mockDouble,$nativeCatalogue,$mockRefresh,$mockAudit,$chatContext);
dq_assert($blockedBundle['skill']==='agent.delegation_needs_separate_turn',
    'one model turn cannot combine prep changes with reads or other tools');

// Section 3K2E: real reorder-point alerts, recipe context, and confirmed
// manager-only changes to a Need More requested amount (NOT stock on hand).
$pdo->prepare('UPDATE inventory_items SET reorder_point=? WHERE organization_id=? AND public_id=?')
    ->execute([3,$org,$stockId]);
$inventory3k2e=daily_quicklist_inventory($pdo,$org);
$items3k2e=daily_quicklist_items($pdo,$org,$agentDay);
$signals=daily_quicklist_stock_alerts($inventory3k2e,daily_quicklist_recipes($pdo,$org),$items3k2e);
dq_assert(count($signals)===1&&$signals[0]['inventoryId']===$stockId
    &&$signals[0]['onHand']===2.0&&$signals[0]['reorderPoint']===3.0
    &&$signals[0]['differenceToPoint']===1.0,
    'stock alerts come from real saved reorder points, not generated order quantities');
dq_assert($signals[0]['alreadyRequested']===true
    &&in_array('Pizza Dough',$signals[0]['prepRecipes'],true),
    'Need More and incomplete prep recipe ingredients connect by exact saved stock name');
$withoutRecipes=daily_quicklist_stock_alerts($inventory3k2e,[],$items3k2e);
dq_assert($withoutRecipes[0]['prepRecipes']===[],'recipe names are not inferred without recipe read permission');
$abovePoint=$inventory3k2e;$abovePoint[0]['onHand']=5;
dq_assert(daily_quicklist_stock_alerts($abovePoint,daily_quicklist_recipes($pdo,$org),$items3k2e)===[],
    'inventory above threshold must not be called low stock');
$unsetPoint=$inventory3k2e;$unsetPoint[0]['reorderPoint']=null;
dq_assert(daily_quicklist_stock_alerts($unsetPoint,[],$items3k2e)===[],
    'no saved reorder point cannot manufacture a shortage');
dq_assert(daily_quicklist_stock_alerts(daily_quicklist_inventory($pdo,$other),[],[])===[],
    'alerts cannot borrow another restaurant inventory records');

$managerStockTools=fm8d18j11_catalogue($managerAgent,$managerChatContext);
dq_assert(isset($managerStockTools['get_need_more_stock_insights'])
    &&in_array('set_requested_amount',
        $managerStockTools['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'manager receives native stock intelligence and confirmation-only requested amount tools');
dq_assert(!isset($nativeCatalogue['get_need_more_stock_insights'])
    &&!in_array('set_requested_amount',
        $nativeCatalogue['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'staff without inventory view or manager permissions cannot discover private actions');
try{fm8d18j11_stock_read(['date'=>$agentDay],$staffAgent,$chatContext);
    throw new RuntimeException('unprivileged stock intelligence accepted');}catch(DomainException){}
try{fm8d18j11_stock_read(['date'=>$agentDay],$managerAgent,
        ['channel'=>'voice','privateVoiceApproved'=>false]);
    throw new RuntimeException('unverified voice saw stock intelligence');}catch(DomainException){}
$stockRead=fm8d18j11_stock_read(['date'=>$agentDay],$managerAgent,$managerChatContext);
dq_assert(str_contains($stockRead['data']['answer'],'Flour')
    &&str_contains($stockRead['data']['answer'],'Pizza Dough')
    &&str_contains($stockRead['data']['answer'],'not calculated recipe shortages'),
    'official read grounds stock and recipe context with explicit limitations');
$managerWithoutRecipes=$managerAgent;
$managerWithoutRecipes['permissions']=array_values(array_diff($managerAgent['permissions'],['recipes.view']));
$limitedRead=fm8d18j11_stock_read(['date'=>$agentDay],$managerWithoutRecipes,$managerChatContext);
dq_assert($limitedRead['data']['alerts'][0]['prepRecipes']===[],
    'authorized stock reads never leak recipe content after permission revocation');
dq_assert(gaw_route($managerAgent,'Which low stock items affect prep recipes?')['domain']==='daily_prep_model_tools',
    'low stock prep intent routes to native model selection');
$stockMock=static fn()=>['output'=>[['type'=>'function_call','call_id'=>'needmore01',
    'name'=>'get_need_more_stock_insights','arguments'=>'{"date":"'.$agentDay.'"}']]];
$modelStock=fm8d3_turn($pdo,$managerAgent,'openai','fake-key',
    [['role'=>'user','content'=>'What is low in stock for prep?']],
    '',$stockMock,['get_need_more_stock_insights'=>$managerStockTools['get_need_more_stock_insights']],
    static fn():array=>$managerAgent,$mockAudit,$chatContext);
dq_assert($modelStock['skill']==='agent.model_selected_tools'
    &&str_contains($modelStock['answer'],'Flour'),
    'mocked model-selected stock tool reads real server-owned data');

dq_assert(fm8d18j11_action_fields(['action'=>'set_requested_amount','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','note'=>'3 cases'],$managerAgent)===[],
    'explicit manager requested amount validates');
dq_assert(fm8d18j11_action_fields(['action'=>'set_requested_amount','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','note'=>str_repeat('z',121)],$managerAgent)===['note'],
    'oversized requested amount blocked');
try{daily_quicklist_mutate($pdo,$org,$actor,'update',[
        'id'=>$needIdAgain,'quantityNote'=>'200 cases'],false);
    throw new RuntimeException('staff modified requested amount');}catch(InvalidArgumentException){}
$preAmount=$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE public_id='stock-daily-test'")->fetchColumn();
$managerProposal=fm8d18j11_propose(['action'=>'set_requested_amount','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','note'=>'3 cases','date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
dq_assert(($managerProposal['data']['requiresConfirmation']??false)===true,
    'model-selected requested amount is only a proposal');
$preConfirmRows=daily_quicklist_items($pdo,$org,$agentDay);
$preConfirm=current(array_values(array_filter($preConfirmRows,
    static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($preConfirm['quantityNote']==='',
    'unconfirmed manager request does not write the amount');
daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$needIdAgain,'status'=>'ordered','quantityNote'=>'2 cases'],true);
try{prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
    throw new RuntimeException('stale requested amount was applied');}catch(InvalidArgumentException){}
dq_assert(!gac_pending_get('prep',$org,$manager),'stale amount proposal is consumed safely');
$managerProposal=fm8d18j11_propose(['action'=>'set_requested_amount','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','note'=>'3 cases','date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
$newAmountRows=daily_quicklist_items($pdo,$org,$agentDay);
$newAmount=current(array_values(array_filter($newAmountRows,
    static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($newAmount['quantityNote']==='3 cases'
    &&daily_quicklist_history($pdo,$org,$needIdAgain)[0]['event']==='update',
    'confirmed manager requested amount appears in the same UI and audit trail');
$postAmount=$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE public_id='stock-daily-test'")->fetchColumn();
dq_assert((float)$postAmount===(float)$preAmount,'Need More amount never alters actual stock ledger');

// Section 3K2F: reconcile an ALREADY-RECORDED inventory receipt once, without
// receiving it twice. Model numbers are bound to the manager's chat session.
dq_assert(daily_quicklist_receipts_ready($pdo),'upgrade installs the receipt link table');
$managerReceipts=fm8d18j11_catalogue($managerAgent,$managerChatContext);
dq_assert(isset($managerReceipts['get_need_more_receipts'])
    &&in_array('link_confirmed_receipt',
        $managerReceipts['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'manager has registered native recorded-receipt read and proposal tools');
dq_assert(!isset($nativeCatalogue['get_need_more_receipts'])
    &&!in_array('link_confirmed_receipt',
        $nativeCatalogue['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'staff cannot discover manager-only receipt actions');
try{fm8d18j11_receipts_read(['name'=>'Flour','date'=>$agentDay],$staffAgent,$chatContext);
    throw new RuntimeException('staff read private recorded receipts');}catch(DomainException){}
try{fm8d18j11_receipts_read(['name'=>'Flour','date'=>$agentDay],$managerAgent,
        ['channel'=>'voice','privateVoiceApproved'=>false]);
    throw new RuntimeException('unverified room voice read inventory receipts');}catch(DomainException){}
try{fm8d18j11_propose(['action'=>'link_confirmed_receipt','list'=>'inventory',
        'reference_mode'=>'name','name'=>'Flour','receipt_number'=>1,'date'=>$agentDay],
        $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
    throw new RuntimeException('receipt proposal allowed without a prior numbered read');}
catch(InvalidArgumentException){}
$received=operations_inventory_adjust($pdo,$org,$stockId,2.0,'receive',$actor,'CI recorded physical receipt');
$receiptTxn=(int)$received['last_transaction_id'];
$afterPhysicalReceipt=(float)$received['on_hand_quantity'];
$txnCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$candidates=daily_quicklist_receipt_candidates($pdo,$org,$needIdAgain);
dq_assert(count($candidates)===1&&$candidates[0]['id']===$receiptTxn
    &&$candidates[0]['quantity']===2.0&&$candidates[0]['unit']==='kg',
    'eligible receipt is exact saved tenant inventory ledger evidence');
try{daily_quicklist_receipt_candidates($pdo,$other,$needIdAgain);
    throw new RuntimeException('foreign organization enumerated stock receipts');}catch(InvalidArgumentException){}
$readReceipts=fm8d18j11_receipts_read(['name'=>'Flour','date'=>$agentDay],$managerAgent,$managerChatContext);
dq_assert(str_contains($readReceipts['data']['answer'],'1. 2 kg received')
    &&!str_contains($readReceipts['data']['answer'],(string)$receiptTxn.' transaction ID'),
    'model reads numbered receipt evidence, not raw transaction IDs');
$unrelatedThread='agent-'.str_repeat('d',20);
try{pqa_receipt_context_resolve($org,$manager,$unrelatedThread,$needIdAgain,1);
    throw new RuntimeException('receipt selection leaked across conversations');}catch(InvalidArgumentException){}
try{pqa_receipt_context_resolve($other,$manager,$managerChatThread,$needIdAgain,1);
    throw new RuntimeException('receipt selection leaked to another tenant');}catch(InvalidArgumentException){}
dq_assert(fm8d18j11_action_fields(['action'=>'link_confirmed_receipt','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','receipt_number'=>21],$managerAgent)===['receipt_number'],
    'unknown receipt ordinal rejected before proposal');
$proposalReceipt=fm8d18j11_propose(['action'=>'link_confirmed_receipt','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','receipt_number'=>1,'date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
dq_assert(($proposalReceipt['data']['requiresConfirmation']??false)===true,
    'linking recorded stock receipt stages a separate confirmation');
$rowsBeforeLink=daily_quicklist_items($pdo,$org,$agentDay);
$needBeforeLink=current(array_values(array_filter($rowsBeforeLink,static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($needBeforeLink['status']==='ordered'&&daily_quicklist_receipt_links($pdo,$org)===[],
    'unconfirmed receipt never resolves Need More');
daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$needIdAgain,'status'=>'ordered','quantityNote'=>'4 cases'],true);
try{prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
    throw new RuntimeException('stale receipt link proposal was applied');}catch(InvalidArgumentException){}
dq_assert(!gac_pending_get('prep',$org,$manager),'stale receipt proposal was consumed');
$readReceipts=fm8d18j11_receipts_read(['name'=>'Flour','date'=>$agentDay],$managerAgent,$managerChatContext);
$proposed=fm8d18j11_propose(['action'=>'link_confirmed_receipt','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','receipt_number'=>1,'date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
$completeReceipt=prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
dq_assert($completeReceipt['skill']==='prep.action_confirmed','agent confirmation resolved Need More');
$receiptLinks=daily_quicklist_receipt_links($pdo,$org);
dq_assert(($receiptLinks[$needIdAgain]['receiptId']??null)===$receiptTxn,
    'one immutable receipt link saved for the exact Need More request');
$linkedRows=daily_quicklist_items($pdo,$org,$agentDay);
$linkedNeed=current(array_values(array_filter($linkedRows,static fn(array $r):bool=>$r['id']===$needIdAgain)));
dq_assert($linkedNeed['status']==='received'
    &&daily_quicklist_history($pdo,$org,$needIdAgain)[0]['event']==='receipt_reconciled',
    'receipt reconciliation changed only the Need More status and appended evidence');
$stockAfterLink=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE public_id='stock-daily-test'")->fetchColumn();
$txnCountAfter=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
dq_assert($stockAfterLink===$afterPhysicalReceipt&&$txnCountAfter===$txnCountBefore,
    'reconciliation never receives stock twice or appends an inventory transaction');
try{daily_quicklist_mutate($pdo,$org,$manager,'update',
        ['id'=>$needIdAgain,'status'=>'need_more'],true);
    throw new RuntimeException('reconciled request was reopened');}catch(InvalidArgumentException){}
$newRequest=daily_quicklist_add($pdo,$org,$actor,
    ['type'=>'inventory','title'=>'Flour','inventoryId'=>$stockId],false);
dq_assert(daily_quicklist_receipt_candidates($pdo,$org,$newRequest)===[],
    'already-linked inventory receipt cannot satisfy a new shortage');
try{daily_quicklist_reconcile_receipt($pdo,$org,$manager,$managerAgent,$newRequest,$receiptTxn);
    throw new RuntimeException('same receipt linked to another shortage');}catch(InvalidArgumentException){}
try{daily_quicklist_reconcile_receipt($pdo,$other,$manager,$managerAgent,$newRequest,$receiptTxn);
    throw new RuntimeException('receipt crossed organization boundary');}catch(InvalidArgumentException|DomainException){}
$allHistory=daily_quicklist_history($pdo,$org,$needIdAgain);
dq_assert(($allHistory[0]['details']['inventoryTransactionId']??null)===$receiptTxn,
    'immutable transaction evidence remains available in admin history');

// Section 3K2G: a numeric Need More target is intentionally separate from
// ambiguous free-form notes; actual recorded receipts can be allocated in parts.
dq_assert(daily_quicklist_receipts_ready($pdo),'partial-receipt migration is installed');
$partialProgress=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($partialProgress['requestedQuantity']===null
    &&$partialProgress['receivedQuantity']===0.0
    &&$partialProgress['unit']==='kg','legacy Need More has no invented numeric target');
$managerPartialCatalogue=fm8d18j11_catalogue($managerAgent,$managerChatContext);
dq_assert(in_array('set_requested_stock_quantity',
        $managerPartialCatalogue['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true)
    &&!in_array('set_requested_stock_quantity',
        $nativeCatalogue['propose_daily_prep_action']['parameters']['properties']['action']['enum'],true),
    'official model-selected numeric quantity tool is manager/Inventory View only');
dq_assert(fm8d18j11_action_fields(['action'=>'set_requested_stock_quantity','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','quantity'=>5.0,'unit'=>'kg'],$managerAgent)===[],
    'native model validates numeric target in saved stock unit');
try{fm8d18j11_propose(['action'=>'set_requested_stock_quantity','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','quantity'=>5.0,'date'=>$agentDay],
    $staffAgent,hac_test_model_context($pdo,$staffAgent,$chatContext));
    throw new RuntimeException('staff proposed manager-only numeric stock target');}catch(InvalidArgumentException){}
try{fm8d18j11_propose(['action'=>'set_requested_stock_quantity','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','quantity'=>5.0,'unit'=>'boxes','date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
    throw new RuntimeException('model invented stock unit');}catch(InvalidArgumentException){}
$proposedTarget=fm8d18j11_propose(['action'=>'set_requested_stock_quantity','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','quantity'=>5.0,'unit'=>'kg','date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
dq_assert(($proposedTarget['data']['requiresConfirmation']??false)===true,
    'numeric target is not saved before explicit Confirm');
$pendingTarget=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($pendingTarget['requestedQuantity']===null,'proposal cannot mutate target by itself');
$confirmedTarget=prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
dq_assert($confirmedTarget['skill']==='prep.action_confirmed',
    'typed/authorized voice confirmation uses existing Prep Agent action gate');
$partialProgress=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($partialProgress['requestedQuantity']===5.0
    &&$partialProgress['remainingQuantity']===5.0,'confirmed target is canonical and initially outstanding');
$partialItem=current(array_values(array_filter(daily_quicklist_items($pdo,$org,$agentDay),
    static fn(array $x):bool=>$x['id']===$newRequest)));
dq_assert($partialItem['requestedStockQuantity']===5.0,'UI exposes numeric target without rewriting free-form note');
try{daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$newRequest,'status'=>'received'],true);
    throw new RuntimeException('manager closed unfulfilled numeric request');}catch(InvalidArgumentException){}
$firstStock=operations_inventory_adjust($pdo,$org,$stockId,2.0,'receive',$actor,'First physical partial delivery');
$firstTxn=(int)$firstStock['last_transaction_id'];
$beforeAlloc=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$firstLink=daily_quicklist_reconcile_receipt($pdo,$org,$manager,$managerAgent,$newRequest,$firstTxn);
dq_assert($firstLink['status']==='ordered'
    &&$firstLink['receipt']['remainingQuantity']===3.0
    &&$firstLink['receipt']['quantity']===2.0,
    'first recorded physical receipt leaves 3 kg outstanding');
$partialProgress=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($partialProgress['receivedQuantity']===2.0
    &&$partialProgress['remainingQuantity']===3.0,'outstanding total is derived from immutable receipt allocations');
try{daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$newRequest,'requestedStockQuantity'=>1.0],true);
    throw new RuntimeException('manager lowered target below allocated evidence');}catch(InvalidArgumentException){}
try{daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$newRequest,'status'=>'received'],true);
    throw new RuntimeException('manager bypassed partial outstanding state');}catch(InvalidArgumentException){}
dq_assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()
    ===$beforeAlloc,'partial reconciliation never adds a physical inventory transaction');
$secondStock=operations_inventory_adjust($pdo,$org,$stockId,4.0,'receive',$actor,'Second physical partial delivery');
$secondTxn=(int)$secondStock['last_transaction_id'];
$afterActualReceiving=(float)$secondStock['on_hand_quantity'];
$beforeFinalAlloc=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$readSecond=fm8d18j11_receipts_read(['name'=>'Flour','date'=>$agentDay],$managerAgent,$managerChatContext);
dq_assert(str_contains($readSecond['data']['answer'],'still outstanding: 3 kg')
    &&str_contains($readSecond['data']['answer'],'1. 4 kg received'),
    'official Agent/Brain/Chat read reports genuine stock receipt and remaining quantity');
$proposalFinal=fm8d18j11_propose(['action'=>'link_confirmed_receipt','list'=>'inventory',
    'reference_mode'=>'name','name'=>'Flour','receipt_number'=>1,'quantity'=>3.0,'date'=>$agentDay],
    $managerAgent,hac_test_model_context($pdo,$managerAgent,$managerChatContext));
dq_assert(($proposalFinal['data']['requiresConfirmation']??false)===true,
    'partial receipt allocation is confirmation-only');
$beforeFinal=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($beforeFinal['remainingQuantity']===3.0,'proposal has not allocated any stock');
$finalConfirmed=prep_agent_handle($pdo,$managerAgent,hac_test_input($pdo,$managerAgent,'prep',['message'=>'Confirm']));
dq_assert($finalConfirmed['skill']==='prep.action_confirmed',
    'manager confirms partial quantity from numbered immutable ledger receipt');
$finalProgress=daily_quicklist_receipt_progress($pdo,$org,$newRequest);
dq_assert($finalProgress['receivedQuantity']===5.0&&$finalProgress['remainingQuantity']===0.0,
    '2 kg plus 3 kg allocated from separate receipts meets 5 kg target: '.json_encode($finalProgress,JSON_THROW_ON_ERROR));
$links=daily_quicklist_receipt_links($pdo,$org);
dq_assert(count($links[$newRequest]['receipts']??[])===2
    &&$links[$newRequest]['quantity']===5.0
    &&$links[$newRequest]['requestedQuantity']===5.0,
    'UI exposes two receipt lines with cumulative and remaining progress');
$finalRows=daily_quicklist_items($pdo,$org,$agentDay);
$finalNeed=current(array_values(array_filter($finalRows,static fn(array $x):bool=>$x['id']===$newRequest)));
dq_assert($finalNeed['status']==='received','only final allocation closes Need More');
$afterFinalCount=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$afterFinalStock=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE public_id='stock-daily-test'")->fetchColumn();
dq_assert($beforeFinalAlloc===$afterFinalCount&&$afterFinalStock===$afterActualReceiving,
    'two allocations never double-receive the real 6 kg from Operations');
$afterShortage=daily_quicklist_add($pdo,$org,$actor,
    ['type'=>'inventory','title'=>'Flour','inventoryId'=>$stockId],false);
try{daily_quicklist_reconcile_receipt($pdo,$org,$manager,$managerAgent,$afterShortage,$secondTxn);
    throw new RuntimeException('same recorded receipt allocated twice');}catch(InvalidArgumentException){}
try{daily_quicklist_reconcile_receipt($pdo,$other,$manager,$managerAgent,$afterShortage,$firstTxn);
    throw new RuntimeException('tenant boundary crossed for partial receipt');}catch(InvalidArgumentException|DomainException){}
dq_assert(daily_quicklist_history($pdo,$org,$newRequest)[0]['event']==='receipt_reconciled',
    'final allocation remains in append-only history with original receipt evidence');
dq_assert((daily_quicklist_receipt_links($pdo,$org)[$needIdAgain]['quantity']??null)===2.0,
    'old one-to-one receipt is backfilled at its full original quantity');

require_once __DIR__.'/../includes/daily-quicklist-purchasing-board.php';
$boardBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$board=daily_quicklist_purchasing_board($pdo,$org,$agentDay);
dq_assert($board['date']===$agentDay&&$board['totals']['requests']>=1,
    'manager purchasing board reads active Need More requests');
dq_assert(count(array_filter($board['requests'],
    static fn(array $r):bool=>$r['id']===$afterShortage))===1,
    'board retains the exact Need More identity');
dq_assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()===$boardBefore,
    'purchasing board does not write a physical stock transaction');
$otherBoard=daily_quicklist_purchasing_board($pdo,$other,$agentDay);
dq_assert(count($otherBoard['requests'])===0,
    'purchasing dashboard never exposes another restaurant tenant');

/* 3K2I-A: exact Need More -> draft only; no new inventory transaction. */
require_once __DIR__.'/../includes/daily-quicklist-purchase-drafts.php';
$draftActor=['id'=>$manager,'organization_id'=>$org,
    'permissions'=>['tasks.manage','inventory.view','purchasing.view','purchasing.manage']];
$readOnlyActor=$draftActor;$readOnlyActor['permissions']=['tasks.manage','inventory.view','purchasing.view'];
dq_assert(!dqpo_can_draft($readOnlyActor),'read-only purchasing cannot create draft');
dq_assert(dqpo_can_draft($draftActor),'manager with purchasing.manage can create drafts');
try{dqpo_quote($pdo,$other,$afterShortage);throw new RuntimeException('Cross-tenant preview succeeded');}
catch(InvalidArgumentException){}
try{dqpo_quote($pdo,$org,$afterShortage);throw new RuntimeException('Missing numeric target accepted');}
catch(InvalidArgumentException){}
daily_quicklist_mutate($pdo,$org,$manager,'update',
    ['id'=>$afterShortage,'requestedStockQuantity'=>5.0],true);
try{dqpo_quote($pdo,$org,$afterShortage);throw new RuntimeException('Unmapped supplier accepted');}
catch(InvalidArgumentException){}
$draftVendor=purchasing_save_vendor($pdo,$org,['name'=>'CI Needs Supplier'],$manager);
purchasing_save_vendor_item($pdo,$org,[
    'vendorId'=>$draftVendor['public_id'],'inventoryId'=>$stockId,
    'packSize'=>2,'packUnit'=>'kg','pricePerPack'=>15,'preferred'=>1
],$manager);
$draftQuote=dqpo_quote($pdo,$org,$afterShortage);
dq_assert($draftQuote['packs']===3&&$draftQuote['orderedQuantity']===6.0
    &&$draftQuote['overageQuantity']===1.0&&$draftQuote['estimatedSubtotal']===45.0,
    'explicit 5 kg shortage rounds to three vendor packs, with 1 kg disclosed overage');
try{dqpo_create_draft($pdo,$org,$manager,$readOnlyActor,$afterShortage,$draftQuote['quoteHash']);
    throw new RuntimeException('Unauthorized creation accepted');
}catch(DomainException){}
try{dqpo_create_draft($pdo,$other,$manager,$draftActor,$afterShortage,$draftQuote['quoteHash']);
    throw new RuntimeException('Cross-tenant creation accepted');
}catch(DomainException){}
try{dqpo_create_draft($pdo,$org,$manager,$draftActor,$afterShortage,str_repeat('0',64));
    throw new RuntimeException('Stale quote accepted');
}catch(InvalidArgumentException){}
$noStockWrite=(int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn();
$createdDraft=dqpo_create_draft($pdo,$org,$manager,$draftActor,$afterShortage,$draftQuote['quoteHash']);
dq_assert($createdDraft['status']==='draft'&&$createdDraft['orderedQuantity']===6.0,
    'Need More creates a canonical draft purchase order, not a submitted order');
$orderRow=purchasing_order($pdo,$org,$createdDraft['orderId']);
dq_assert((string)$orderRow['status']==='draft'&&count($orderRow['items'])===1
    &&(float)$orderRow['items'][0]['ordered_base_quantity']===6.0,
    'canonical purchasing order line retains exact saved inventory identity and quantity');
dq_assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id='.$org)->fetchColumn()===$noStockWrite,
    'draft creation never changes physical inventory');
dq_assert(daily_quicklist_history($pdo,$org,$afterShortage)[0]['event']==='po_draft_created',
    'append-only Need More audit links the draft purchase order');
try{dqpo_quote($pdo,$org,$afterShortage);throw new RuntimeException('Open draft did not block duplicate');}
catch(InvalidArgumentException){}
try{dqpo_create_draft($pdo,$org,$manager,$draftActor,$afterShortage,$draftQuote['quoteHash']);
    throw new RuntimeException('Duplicate purchase order accepted');}
catch(InvalidArgumentException){}
echo "daily-prep-quicklist contract passed\n";
