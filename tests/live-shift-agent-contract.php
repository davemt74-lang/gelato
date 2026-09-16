<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/live-shift-agent-hardening.php';
require_once __DIR__.'/../includes/table-service-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';
require_once __DIR__.'/../includes/table-cleaning-lifecycle.php';
require_once __DIR__.'/../includes/pos-floor-plan.php';

$pdo=app_pdo();
function lsa(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function lsa_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function lsa_text(string $path): string {$v=@file_get_contents($path);if($v===false)throw new RuntimeException('Unable to read '.$path);return $v;}

$root=dirname(__DIR__);
$nodes=lsa_text($root.'/includes/agent-node-registry.php');
$router=lsa_text($root.'/api/agent-workspace.php');
$core=lsa_text($root.'/includes/live-shift-agent-core.php');
$hardening=lsa_text($root.'/includes/live-shift-agent-hardening.php');
$api=lsa_text($root.'/api/live-shift-agent.php');
$context=lsa_text($root.'/js/table-service-agent-context.js');
$loader=lsa_text($root.'/js/table-cleaning-controls.js');
$tablePage=lsa_text($root.'/table-service.php');
$brain=lsa_text($root.'/api/brain-orchestrator.php');
$events=lsa_text($root.'/api/brain-events.php');
$removeApi=lsa_text($root.'/api/pos-item-remove.php');
$removeCore=lsa_text($root.'/includes/pos-item-actions.php');
$atomic=lsa_text($root.'/includes/service-ops-atomic.php');

$static=[
    'live shift node is registered'=>str_contains($nodes,"'live_shift'=>[")&&str_contains($nodes,"'route'=>'api/live-shift-agent.php'")&&str_contains($nodes,"'mode'=>'read_write'"),
    'router exposes live shift action node'=>str_contains($router,"gaw_node_route('live_shift')")&&str_contains($router,'$liveShiftIntent'),
    'POS knowledge remains a distinct node'=>str_contains($router,"gaw_node_route('pos')")&&str_contains($router,'|cook|cooking|'),
    'Table Service local context routes into live shift'=>str_contains($router,"$isTableServiceContext")&&str_contains($router,"gaw_node_route('live_shift','live_shift_context')"),
    'POS action context routes into live shift'=>str_contains($router,"$posActionIntent")&&str_contains($router,"gaw_node_route('live_shift','live_shift_pos_context')"),
    'protected financial actions do not execute in live shift'=>str_contains($core,"void|discount|comp|refund")&&str_contains($core,'protected POS action'),
    'shared unsent removal core is reused'=>str_contains($removeApi,'pos_item_remove_unsent')&&str_contains($core,'pos_item_remove_unsent')&&str_contains($removeCore,'kds_assert_pos_line_mutable'),
    'captured tender guard is shared'=>str_contains($removeCore,'pos_item_action_assert_unpaid')&&str_contains($hardening,'live_shift_check_unpaid'),
    'server assignment preserves location-staff validation'=>str_contains($hardening,'service_ops_assign_server')&&str_contains($atomic,'service_ops_staff_at_location')&&str_contains($atomic,'table_service_assign_server'),
    'broad manager questions delegate to main Brain'=>str_contains($hardening,'agent_brain_orchestration_snapshot')&&str_contains($hardening,"'node'=>'brain'"),
    'Brain snapshot merges live shift signals'=>str_contains($brain,'live_shift_merge_brain_snapshot'),
    'proactive Brain includes live shift events'=>str_contains($events,'live_shift_proactive_events'),
    'Table Service context transports identifiers only'=>str_contains($context,"module:'table_service'")&&str_contains($context,'tablePublicId:c.tablePublicId')&&str_contains($context,'checkPublicId:c.checkPublicId')&&!str_contains($context,'item_name_snapshot'),
    'Table Service workstation loads shared Agent'=>str_contains($loader,'table-service-agent-context.js')&&str_contains($loader,'global-agent.js')&&str_contains($loader,'dynamic-agent-canvas.js'),
    'Table Service cache key is current'=>str_contains($tablePage,'js/table-cleaning-controls.js?v=20260916-live-shift1'),
    'Live Shift endpoint is POST and CSRF protected'=>str_contains($api,"REQUEST_METHOD']!=='POST'")&&str_contains($api,'app_verify_request_csrf'),
];
foreach($static as $label=>$ok)lsa($ok,$label);

lsa(pos_ready($pdo),'Native POS must be installed.');
lsa(kds_ready($pdo),'KDS must be installed.');
lsa(table_service_ready($pdo),'Table Service must be installed.');
lsa(crm_ready($pdo),'CRM must be installed.');

$slug='live-shift-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Live Shift CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-manager@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Live','Manager','Live Manager']);$manager=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$manager,$location]);$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-server@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Alex','Server','Alex Server']);$server=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Server','active')")->execute([$org,$server,$location]);
$user=['id'=>$manager,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['*'],'display_name'=>'Live Manager','first_name'=>'Live','role_slug'=>'owner'];

$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining Room','sortOrder'=>1],$manager);
$businessDate=pos_clock($pdo,$org,$location)->format('Y-m-d');
table_service_section_assign($pdo,$org,$location,(string)$section['publicId'],$server,$businessDate,$manager);
$t1=table_service_table_save($pdo,$org,$location,['name'=>'Table 1','sectionPublicId'=>$section['publicId'],'capacity'=>4,'shape'=>'round','xPercent'=>20,'yPercent'=>30],$manager);
$t2=table_service_table_save($pdo,$org,$location,['name'=>'Bar Seat 1','sectionPublicId'=>$section['publicId'],'capacity'=>1,'shape'=>'round','xPercent'=>55,'yPercent'=>20],$manager);
host_sync_all_table_assets($pdo,$org,$location,$manager);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$menuItem]);
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza','slug'=>'pizza','targetSeconds'=>600],$manager);kds_route_save($pdo,$org,$location,$menuItem,(string)$station['public_id'],$manager);
$customer=crm_customer_save($pdo,$org,['displayName'=>'Taylor Guest','email'=>'taylor-'.$slug.'@example.test'],$manager,'manual');

$baseContext=['module'=>'table_service','locationId'=>$location];
$initial=live_shift_snapshot($pdo,$user,$location);lsa(count($initial['tables'])===2,'Live Shift must see canonical tables and bar seats.');
$seat=live_shift_hardened_handle($pdo,$user,$baseContext,'Seat 2 at Table 1');lsa(isset($seat['check']['publicId']),'Agent must seat a party and open a canonical POS check.');$checkPublic=(string)$seat['check']['publicId'];
$page=$baseContext+['tablePublicId'=>$t1['publicId'],'checkPublicId'=>$checkPublic];

$assign=live_shift_hardened_handle($pdo,$user,$page,'Assign server to Alex Server');lsa(str_contains($assign['answer'],'Alex Server'),'Agent must assign the canonical Table Service server.');
$attached=live_shift_hardened_handle($pdo,$user,$page,'Attach customer Taylor Guest to this check');lsa(($attached['customer']['displayName']??'')==='Taylor Guest','Agent must attach the CRM customer to the dining visit.');
$added=live_shift_hardened_handle($pdo,$user,$page,'Add Margherita Pizza to this check');lsa(count($added['check']['items'])===1,'Agent must add an active menu item to the selected check.');$lineId=(int)$added['check']['items'][0]['id'];
$page['focusedLineId']=$lineId;
$noted=live_shift_hardened_handle($pdo,$user,$page,'Add item note: extra crisp');lsa((string)$noted['check']['items'][0]['special_instructions']==='extra crisp','Agent must update an unsent focused-line note.');
$removed=live_shift_hardened_handle($pdo,$user,$page,'Remove Margherita Pizza from this check');lsa(count($removed['check']['items'])===0,'Agent must remove an unsent line immediately without a confirmation proposal.');

$added=live_shift_hardened_handle($pdo,$user,$page,'Add Margherita Pizza to this check');$lineId=(int)$added['check']['items'][0]['id'];$page['focusedLineId']=$lineId;
$sent=live_shift_hardened_handle($pdo,$user,$page,'Send this check to kitchen');lsa((int)$sent['kitchen']['sent']===1&&(int)$sent['kitchen']['unsent']===0,'Agent must send unsent items through canonical KDS.');
$sentRemovalBlocked=false;try{live_shift_hardened_handle($pdo,$user,$page,'Remove Margherita Pizza from this check');}catch(InvalidArgumentException){$sentRemovalBlocked=true;}lsa($sentRemovalBlocked,'Agent must not directly remove a kitchen-sent item.');

$krow=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=? LIMIT 1');$krow->execute([$org,$lineId]);$kdsPublic=(string)$krow->fetchColumn();kds_transition($pdo,$org,$kdsPublic,'in_progress',$manager);kds_transition($pdo,$org,$kdsPublic,'ready',$manager);
$ready=live_shift_snapshot($pdo,$user,$location);lsa((int)$ready['summary']['readyItems']===1&&count(array_filter($ready['nextMoves'],static fn(array $m):bool=>str_starts_with((string)$m['key'],'ready:')))===1,'READY KDS work must become a Live Shift Next Move.');

$moved=live_shift_hardened_handle($pdo,$user,$page,'Move this check to Bar Seat 1');lsa(str_contains($moved['answer'],'Bar Seat 1'),'Agent must transfer an active dining visit to a bar seat.');
lsa((string)lsa_one($pdo,'SELECT table_name FROM pos_checks WHERE organization_id=? AND public_id=?',[$org,$checkPublic])==='Bar Seat 1','Transferred POS check must carry the bar-seat name.');
$protected=live_shift_hardened_handle($pdo,$user,$page,'Discount this check 20 percent');lsa(($protected['protected']??false)===true,'Discount must stay in the protected POS flow.');

$guard=table_service_seat($pdo,$org,$location,(string)$t1['publicId'],1,null,'Payment lock',$manager);$guardPublic=(string)$guard['publicId'];
$pdo->prepare("INSERT INTO pos_tenders (organization_id,check_id,public_id,tender_type,amount,tip_amount,change_amount,status,processed_by) VALUES (?,?,?,'cash',1.00,0.00,0.00,'captured',?)")->execute([$org,(int)$guard['id'],table_service_public_id('tender'),$manager]);
$guardPage=$baseContext+['tablePublicId'=>$t1['publicId'],'checkPublicId'=>$guardPublic];
$paymentBlocked=false;try{live_shift_hardened_handle($pdo,$user,$guardPage,'Add Margherita Pizza to this check');}catch(InvalidArgumentException $e){$paymentBlocked=str_contains($e->getMessage(),'captured tender');}lsa($paymentBlocked,'Agent item mutations must be blocked after captured payment.');

lsa((int)lsa_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action LIKE 'agent.live_shift.%'",[$org])>=5,'Live Shift mutations must create Agent audit records.');
$brainSignals=live_shift_brain_signal_rows($pdo,$user);lsa(count(array_filter($brainSignals,static fn(array $s):bool=>(string)$s['node']==='live_shift'))>=1,'Live Shift exceptions must feed the Main Agent Brain.');

echo "live-shift-agent contract passed\n";
