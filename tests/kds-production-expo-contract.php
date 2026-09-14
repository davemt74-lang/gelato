<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/table-service-core.php';

$pdo=app_pdo();
function kdsp_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function kdsp_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

kdsp_assert(pos_ready($pdo),'Native POS must be installed.');
kdsp_assert(kds_ready($pdo),'Kitchen Display must be installed.');
kdsp_assert(table_service_ready($pdo),'Table Service must be installed.');
kdsp_assert(kds_production_service_context_ready($pdo),'KDS production context must detect Table Service seat/course fields.');

$slug='kdsp-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['KDS Production '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Expo','Lead','Expo Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Expo Lead','active')")->execute([$org,$user,$location]);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Kitchen',?,'active',1)")->execute([$org,'kitchen-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$pizza=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'12 inch','12',18.00,'USD',1)")->execute([$pizza]);$pizzaPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Caesar Salad',?,1)")->execute([$org,$menuSection,'salad-'.$slug]);$salad=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10.00,'USD',1)")->execute([$salad]);$saladPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Gelato Sundae',?,1)")->execute([$org,$menuSection,'sundae-'.$slug]);$dessert=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',8.00,'USD',1)")->execute([$dessert]);$dessertPrice=(int)$pdo->lastInsertId();

$oven=kds_station_save($pdo,$org,$location,['name'=>'Pizza Oven','slug'=>'pizza-oven','targetSeconds'=>100,'sortOrder'=>10],$user);
$cold=kds_station_save($pdo,$org,$location,['name'=>'Cold Line','slug'=>'cold-line','targetSeconds'=>100,'sortOrder'=>20],$user);
kds_route_save($pdo,$org,$location,$pizza,(string)$oven['public_id'],$user);
kds_route_save($pdo,$org,$location,$salad,(string)$cold['public_id'],$user);

$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining Room','sortOrder'=>10],$user);
table_service_section_assign($pdo,$org,$location,(string)$section['publicId'],$user,pos_clock($pdo,$org,$location)->format('Y-m-d'),$user);
$table=table_service_table_save($pdo,$org,$location,['name'=>'Table 12','sectionPublicId'=>$section['publicId'],'capacity'=>4,'shape'=>'round','xPercent'=>20,'yPercent'=>20,'widthPercent'=>12,'heightPercent'=>12],$user);
$service=table_service_seat($pdo,$org,$location,(string)$table['publicId'],2,$user,'KDS production test',$user);$checkPublic=(string)$service['publicId'];
$check=pos_add_item($pdo,$org,$checkPublic,$pizzaPrice,2,'One well done',$user);$pizzaLine=(int)$check['items'][0]['id'];
$check=pos_add_item($pdo,$org,$checkPublic,$saladPrice,1,'Dressing on side',$user);$saladLine=(int)$check['items'][1]['id'];
table_service_item_course($pdo,$org,$checkPublic,$pizzaLine,1,'mains',$user);
table_service_item_course($pdo,$org,$checkPublic,$saladLine,2,'starters',$user);
table_service_kds_send_lines($pdo,$org,$checkPublic,[$pizzaLine],$user,false);
table_service_kds_send_lines($pdo,$org,$checkPublic,[$saladLine],$user,true);

$board=kds_production_board($pdo,$org,$location,null,false);
kdsp_assert((int)$board['metrics']['tickets']===1,'Production board must group kitchen items into one check ticket.');
kdsp_assert((int)$board['metrics']['queued']===1&&(int)$board['metrics']['held']===1,'Production board must distinguish queued and held work.');
kdsp_assert(count($board['tickets'])===1&&count($board['tickets'][0]['items'])===2,'Ticket card must contain both kitchen lines.');
$ticket=$board['tickets'][0];
kdsp_assert((string)$ticket['serverName']==='Expo Lead','Production ticket must expose the Table Service server.');
$pizzaBoard=array_values(array_filter($ticket['items'],static fn(array $r):bool=>(int)$r['pos_check_item_id']===$pizzaLine))[0]??null;
$saladBoard=array_values(array_filter($ticket['items'],static fn(array $r):bool=>(int)$r['pos_check_item_id']===$saladLine))[0]??null;
kdsp_assert(is_array($pizzaBoard)&&(int)$pizzaBoard['seat_number']===1&&(string)$pizzaBoard['course_key']==='mains','KDS must expose seat and course context for pizza.');
kdsp_assert(is_array($saladBoard)&&(int)$saladBoard['seat_number']===2&&(string)$saladBoard['course_key']==='starters','KDS must expose seat and course context for salad.');
kdsp_assert(count($board['allDay'])===1&&(string)$board['allDay'][0]['name']==='Margherita Pizza'&&(float)$board['allDay'][0]['quantity']===2.0,'All Day must count fired active production and exclude held future-course work.');
kdsp_assert((int)$board['stationCounts']['all']===2&&(int)$board['stationCounts'][(string)$oven['public_id']]===1&&(int)$board['stationCounts'][(string)$cold['public_id']]===1,'Station tabs must expose global active counts.');

$scope=kds_production_board($pdo,$org,$location,(string)$oven['public_id'],false);
kdsp_assert(count($scope['tickets'])===1&&count($scope['tickets'][0]['items'])===1,'Station board must scope ticket lines to that station.');

kds_production_ticket_action($pdo,$org,$location,$checkPublic,'fire',null,$user);
$board=kds_production_board($pdo,$org,$location,null,false);
kdsp_assert((int)$board['metrics']['queued']===2&&(int)$board['metrics']['held']===0,'Ticket Fire must release held items into the queue.');
kds_production_ticket_action($pdo,$org,$location,$checkPublic,'start',null,$user);
$board=kds_production_board($pdo,$org,$location,null,false);
kdsp_assert((int)$board['metrics']['inProgress']===2,'Start Batch must atomically start all queued ticket items.');
kds_production_ticket_action($pdo,$org,$location,$checkPublic,'ready',null,$user);
$board=kds_production_board($pdo,$org,$location,null,false);
kdsp_assert((int)$board['metrics']['ready']===2&&(int)$board['metrics']['readyTickets']===1&&$board['tickets'][0]['readyToBump']===true,'Whole-ticket Expo readiness must require all fired lines to be Ready.');

$pdo->prepare("UPDATE kds_order_items SET fired_at=DATE_SUB(NOW(6),INTERVAL 90 SECOND) WHERE organization_id=? AND pos_check_item_id=?")->execute([$org,$pizzaLine]);
$warning=kds_production_board($pdo,$org,$location,null,false);$warningPizza=array_values(array_filter($warning['items'],static fn(array $r):bool=>(int)$r['pos_check_item_id']===$pizzaLine))[0]??null;
kdsp_assert(is_array($warningPizza)&&$warningPizza['warning']===true&&$warningPizza['late']===false,'KDS must warn at 80 percent of station SLA.');
$pdo->prepare("UPDATE kds_order_items SET fired_at=DATE_SUB(NOW(6),INTERVAL 120 SECOND) WHERE organization_id=? AND pos_check_item_id=?")->execute([$org,$pizzaLine]);
$late=kds_production_board($pdo,$org,$location,null,false);$latePizza=array_values(array_filter($late['items'],static fn(array $r):bool=>(int)$r['pos_check_item_id']===$pizzaLine))[0]??null;
kdsp_assert(is_array($latePizza)&&$latePizza['late']===true,'KDS must mark work Late after station SLA is exceeded.');

$bumped=kds_production_ticket_action($pdo,$org,$location,$checkPublic,'bump',null,$user);
kdsp_assert((int)$bumped['affected']===2,'Expo bump must complete every ready fired line in the ticket.');
kdsp_assert((int)kdsp_one($pdo,"SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?) AND status='completed'",[$org,$org,$checkPublic])===2,'Expo bump must persist completed status for the whole fired ticket.');
$history=kds_production_board($pdo,$org,$location,null,true);
kdsp_assert(count($history['tickets'])===1&&(int)$history['tickets'][0]['recallableCount']===2,'Recent completed ticket must be visible and recallable in history mode.');
$firstCompleted=(string)$history['tickets'][0]['items'][0]['public_id'];
$recalled=kds_production_recall_item($pdo,$org,$firstCompleted,$user);
kdsp_assert((string)$recalled['status']==='ready'&&$recalled['completed_at']===null,'Item recall must restore a recently bumped line to Expo Ready.');
kdsp_assert((int)kdsp_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND event_type='recalled'",[$org])===1,'Item recall must be audited.');
$recallTicket=kds_production_ticket_action($pdo,$org,$location,$checkPublic,'recall',null,$user);
kdsp_assert((int)$recallTicket['affected']===1,'Ticket recall must restore remaining recently completed lines without duplicating already recalled work.');

$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND check_id=(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?) ORDER BY id LIMIT 1");$q->execute([$org,$org,$checkPublic]);$expiredPublic=(string)$q->fetchColumn();
$pdo->prepare("UPDATE kds_order_items SET status='completed',completed_at=DATE_SUB(NOW(6),INTERVAL 10 MINUTE) WHERE organization_id=? AND public_id=?")->execute([$org,$expiredPublic]);
$expiredBlocked=false;try{kds_production_recall_item($pdo,$org,$expiredPublic,$user);}catch(InvalidArgumentException){$expiredBlocked=true;}kdsp_assert($expiredBlocked,'Controlled recall must reject completed items outside the recall window.');

$bad=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','tableName'=>'Pickup 9','guestCount'=>1],$user);$badPublic=(string)$bad['publicId'];
$bad=pos_add_item($pdo,$org,$badPublic,$pizzaPrice,1,'',$user);$badPizza=(int)$bad['items'][0]['id'];
$bad=pos_add_item($pdo,$org,$badPublic,$dessertPrice,1,'',$user);$badDessert=(int)$bad['items'][1]['id'];
kds_send_check($pdo,$org,$badPublic,$user,false);
$startBlocked=false;try{kds_production_ticket_action($pdo,$org,$location,$badPublic,'start',null,$user);}catch(InvalidArgumentException){$startBlocked=true;}kdsp_assert($startBlocked,'Ticket batch start must reject an unrouted active line.');
kdsp_assert((string)kdsp_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$badPizza])==='queued','Failed ticket batch action must roll back already-routed lines.');
kdsp_assert((string)kdsp_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$badDessert])==='queued','Failed ticket batch action must preserve unrouted line state.');
$badBoard=kds_production_board($pdo,$org,$location,null,false);kdsp_assert((int)$badBoard['metrics']['unrouted']===1,'Production board must surface unrouted active work.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['KDS Production Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$isolated=false;try{kds_production_recall_item($pdo,$otherOrg,$expiredPublic,$user);}catch(InvalidArgumentException){$isolated=true;}kdsp_assert($isolated,'KDS production actions must remain organization-isolated.');

kdsp_assert((int)kdsp_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND event_type IN ('ticket_fired','ticket_started','ticket_ready','ticket_bumped')",[$org])>=8,'Ticket production actions must append KDS audit events.');
echo "kds-production-expo-ok\n";
