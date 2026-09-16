<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/front-of-house-agent-core.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';

$pdo=app_pdo();
function fohci(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function fohci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

fohci(host_ready($pdo),'Host Stand must be installed.');
fohci(service_visit_ready($pdo),'Service visit lifecycle must be installed.');
fohci(table_cleaning_ready($pdo),'Table cleaning lifecycle must be installed.');
fohci(table_turn_policy_ready($pdo),'Table turn readiness must be installed.');

$slug='foh-agent-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['FOH Agent CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Dining Room','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Host','Agent','Host Agent']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Host Manager','active')")->execute([$org,$uid,$location]);$membership=(int)$pdo->lastInsertId();
$user=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['*'],'display_name'=>'Host Agent','first_name'=>'Host','role_slug'=>'owner'];
$viewer=['id'=>$uid,'organization_id'=>$org,'membership_id'=>$membership,'permissions'=>['host.view','table_service.view'],'display_name'=>'Host Viewer','first_name'=>'Host','role_slug'=>'staff'];

$pdo->prepare("INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,JSON_OBJECT(),40,30,24,1,1,?,?)")->execute([$org,'floor-'.$slug,'Dining Floor',$uid,$uid]);$floor='floor-'.$slug;
$section=table_service_section_save($pdo,$org,$location,['name'=>'Main Dining','sortOrder'=>1],$uid);
$t1=host_create_managed_table($pdo,$org,$location,['name'=>'T1','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'floorPlanId'=>$floor,'xFt'=>5,'yFt'=>6],$uid);
$t2=host_create_managed_table($pdo,$org,$location,['name'=>'T2','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'floorPlanId'=>$floor,'xFt'=>10,'yFt'=>6],$uid);

$start=pos_clock($pdo,$org,$location)->modify('+2 hours');$date=$start->format('Y-m-d');
$res=service_reservation_protection_reservation_create($pdo,$org,$location,[
    'type'=>'reservation','guestName'=>'Jamie Guest','partySize'=>2,'scheduledAt'=>$start->format('Y-m-d H:i:s'),'durationMinutes'=>90,'tablePublicIds'=>[$t1['publicId']],'source'=>'agent',
],$uid);
$context=['module'=>'host_stand','locationId'=>$location,'date'=>$date,'selectedReservationPublicId'=>$res['publicId']];

$read=front_of_house_agent_handle($pdo,$user,['message'=>'What is going on with this reservation?','pageContext'=>$context]);
fohci(($read['skill']??'')==='front_of_house.reservation','Selected Host Stand reservation must resolve through FOH context.');
fohci(str_contains((string)$read['answer'],'Jamie Guest'),'Reservation answer must use the selected server-side reservation.');

$proposal=front_of_house_agent_handle($pdo,$user,['message'=>'Mark this reservation confirmed','pageContext'=>$context]);
fohci(($proposal['skill']??'')==='front_of_house.action_proposal','Reservation status write must be proposed first.');
fohci((string)fohci_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])==='booked','Proposal must not mutate reservation status.');
fohci(gaw_pending_action_node($org,$uid)==='front_of_house','Main Agent confirmation resolver must follow the FOH proposal.');
$confirmed=front_of_house_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
fohci(($confirmed['skill']??'')==='front_of_house.action_confirmed','Confirm must execute the pending reservation action.');
fohci((string)fohci_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])==='confirmed','Confirmed proposal must persist reservation status.');

$beforeChecks=(int)fohci_one($pdo,'SELECT COUNT(*) FROM pos_checks WHERE organization_id=?',[$org]);
$seatProposal=front_of_house_agent_handle($pdo,$user,['message'=>'Seat this reservation','pageContext'=>$context]);
fohci(($seatProposal['skill']??'')==='front_of_house.action_proposal','Seating must require confirmation.');
fohci((int)fohci_one($pdo,'SELECT COUNT(*) FROM pos_checks WHERE organization_id=?',[$org])===$beforeChecks,'Seat proposal must not create a POS check.');
$seated=front_of_house_agent_handle($pdo,$user,['message'=>'Yes','pageContext'=>$context]);
fohci(($seated['data']['reservation']['status']??'')==='seated','Confirmed seating must move the reservation to seated.');
fohci((int)fohci_one($pdo,'SELECT COUNT(*) FROM pos_checks WHERE organization_id=?',[$org])===$beforeChecks+1,'Confirmed seating must create exactly one canonical POS check.');
fohci((string)fohci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])==='seated','Confirmed seating must mark the assigned table seated.');

$pdo->prepare("UPDATE service_tables SET state='dirty',active_check_id=NULL,dirty_at=NOW(6),cleaning_started_at=NULL,ready_at=NULL,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$org,$t2['publicId']]);
$tableContext=['module'=>'host_stand','locationId'=>$location,'date'=>$date,'selectedTablePublicId'=>$t2['publicId']];
$cleanProposal=front_of_house_agent_handle($pdo,$user,['message'=>'Start cleaning this table','pageContext'=>$tableContext]);
fohci(($cleanProposal['skill']??'')==='front_of_house.action_proposal','Start cleaning must be proposed first.');
fohci((string)fohci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])==='dirty','Cleaning proposal must leave table dirty.');
front_of_house_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$tableContext]);
fohci((string)fohci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])==='cleaning','Confirmed cleaning must enter cleaning state.');
$readyProposal=front_of_house_agent_handle($pdo,$user,['message'=>'Mark this table ready','pageContext'=>$tableContext]);
fohci(($readyProposal['skill']??'')==='front_of_house.action_proposal','Table ready must be proposed first.');
front_of_house_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$tableContext]);
fohci((string)fohci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])==='available','Confirmed ready action must return table to available.');

$staleAt=$start->modify('+4 hours');
$stale=service_reservation_protection_reservation_create($pdo,$org,$location,[
    'type'=>'reservation','guestName'=>'Stale Guest','partySize'=>2,'scheduledAt'=>$staleAt->format('Y-m-d H:i:s'),'durationMinutes'=>90,'tablePublicIds'=>[$t2['publicId']],'source'=>'agent',
],$uid);
$staleContext=['module'=>'host_stand','locationId'=>$location,'date'=>$staleAt->format('Y-m-d'),'selectedReservationPublicId'=>$stale['publicId']];
front_of_house_agent_handle($pdo,$user,['message'=>'Mark this reservation arrived','pageContext'=>$staleContext]);
$pdo->prepare('UPDATE guest_reservations SET notes=?,updated_at=DATE_ADD(NOW(6),INTERVAL 2 SECOND) WHERE organization_id=? AND public_id=?')->execute(['Changed after proposal',$org,$stale['publicId']]);
$blocked=false;try{front_of_house_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$staleContext]);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'changed after I proposed');}
fohci($blocked,'Stale reservation proposal must be rejected at confirmation.');
fohci((string)fohci_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$stale['publicId']])==='booked','Stale confirmation must not mutate reservation state.');

$viewerRead=front_of_house_agent_handle($pdo,$viewer,['message'=>'How many available tables do we have?','pageContext'=>['module'=>'host_stand','locationId'=>$location,'date'=>$date]]);
fohci(($viewerRead['skill']??'')==='front_of_house.availability','Host viewer must retain FOH read access.');
$writeBlocked=false;try{front_of_house_agent_handle($pdo,$viewer,['message'=>'Mark this reservation arrived','pageContext'=>$staleContext]);}catch(FrontOfHouseAgentPermissionException){$writeBlocked=true;}
fohci($writeBlocked,'Host viewer without host.use must not propose FOH writes.');

$route=gaw_route($user,'What reservations do we have today?');
fohci(($route['domain']??'')==='front_of_house'&&($route['route']??'')==='api/front-of-house-agent.php','Reservation language must route to Front of House.');
$scheduleRoute=gaw_route($user,'Who is working the next shift?');
fohci(($scheduleRoute['domain']??'')==='scheduling','FOH routing must not steal employee scheduling questions.');
$availabilityRoute=gaw_route($user,'What tables are available right now?');
fohci(($availabilityRoute['domain']??'')==='front_of_house','Table availability must route to FOH before generic scheduling availability.');

fohci((int)fohci_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='front_of_house.agent_action_confirmed'",[$org])>=4,'Confirmed FOH actions must be audited.');
fohci((int)fohci_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='front_of_house.agent_reservation_seated'",[$org])===1,'FOH seating must write a concrete audit event.');

$registry=gaw_agent_node('front_of_house');
fohci(($registry['route']??'')==='api/front-of-house-agent.php'&&($registry['mode']??'')==='read_confirmed_write','FOH must be a first-class confirmed-write Agent node.');

$hostContext=(string)file_get_contents(__DIR__.'/../js/host-stand-agent-context.js');
fohci(str_contains($hostContext,"module:'host_stand'")&&str_contains($hostContext,'selectedReservationPublicId')&&str_contains($hostContext,'selectedTablePublicId'),'Host Stand context adapter must carry only selected public identifiers.');
fohci(!str_contains($hostContext,'guestEmail')&&!str_contains($hostContext,'guestPhone')&&!str_contains($hostContext,'notes:'),'Host Stand browser context must not transport guest PII or notes.');
$cleaning=(string)file_get_contents(__DIR__.'/../js/table-cleaning-controls.js');
fohci(str_contains($cleaning,'bootHostStandAgent')&&str_contains($cleaning,'host-stand-agent-context.js'),'Host Stand specialized workstation must boot the shared Main Agent experience.');

echo "front-of-house-agent=ok\n";
