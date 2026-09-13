<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/daily-manager-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';

$pdo=app_pdo();
function dmci_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function dmci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

dmci_assert(manager_brief_ready($pdo),'Daily Manager Brief migration must be installed.');
$date=(new DateTimeImmutable('today'))->format('Y-m-d');$yesterday=(new DateTimeImmutable('yesterday'))->format('Y-m-d');$slug='dm-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Daily Manager CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Manager Test Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();$locationKey='id:'.$location;
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Test','Manager','Test Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'General Manager','active')")->execute([$org,$user,$location]);

// Canonical sales actual + deterministic forecast for the selected location.
$pdo->prepare("INSERT INTO sales_integrations (organization_id,location_id,provider,display_name,status,is_primary,sync_enabled,created_by,updated_by) VALUES (?,?,'csv','CI Sales','active',1,0,?,?)")->execute([$org,$location,$user,$user]);
$pdo->prepare("INSERT INTO sales_periods (organization_id,location_id,location_key,source_provider,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales,discounts_amount) VALUES (?,?,?,'csv','daily','all',?,?,12,20,525,500,25)")->execute([$org,$location,$locationKey,$date,$date]);
$pdo->prepare("INSERT INTO sales_forecasts (organization_id,location_id,location_key,public_id,forecast_date,service_period,source_provider,projected_tickets,projected_covers,projected_net_sales,projected_labor_hours,scheduled_labor_hours,confidence,sample_count) VALUES (?,?,?,?,?,'all','csv',14,24,620,10,6,0.8000,6)")->execute([$org,$location,$locationKey,'forecast-'.$slug,$date]);

// Published labor has one assigned and one unassigned shift.
$monday=(new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');$pdo->prepare("INSERT INTO schedule_weeks (organization_id,week_start,status,created_by,updated_by) VALUES (?,?,'published',?,?)")->execute([$org,$monday,$user,$user]);$week=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,user_id,title,starts_at,ends_at,break_minutes,status,created_by,updated_by) VALUES (?,?,?,?,?,'Manager',?,?,0,'scheduled',?,?)")->execute([$org,'shift-assigned-'.$slug,$week,$location,$user,$date.' 09:00:00',$date.' 13:00:00',$user,$user]);
$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,user_id,title,starts_at,ends_at,break_minutes,status,created_by,updated_by) VALUES (?,?,?,?,NULL,'Line Cook',?,?,0,'scheduled',?,?)")->execute([$org,'shift-open-'.$slug,$week,$location,$date.' 16:00:00',$date.' 20:00:00',$user,$user]);$openShift=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO schedule_shift_requests (organization_id,public_id,shift_id,requester_user_id,request_type,status) VALUES (?,?,?,?,'drop','pending')")->execute([$org,'request-'.$slug,$openShift,$user]);

// One blocked critical operating task due today.
$pdo->prepare("INSERT INTO task_categories (organization_id,public_id,name,slug,sort_order,status) VALUES (?,'cat-manager-ci','Manager','manager',1,'active')")->execute([$org]);$category=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,title,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,'Repair prep cooler','critical','blocked',?,?,?)")->execute([$org,'task-'.$slug,$category,$date.' 08:00:00',$user,$user]);

// Inventory and purchasing exceptions.
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,category,base_unit,on_hand_quantity,par_level,reorder_point,unit_cost,status,created_by,updated_by) VALUES (?,?,?,'Mozzarella','Dairy','lb',0,20,8,3.50,'active',?,?)")->execute([$org,'inventory-'.$slug,'mozzarella-'.$slug,$user,$user]);
$pdo->prepare("INSERT INTO vendors (organization_id,public_id,name,status,created_by,updated_by) VALUES (?,?,?,'active',?,?)")->execute([$org,'vendor-'.$slug,'CI Food Vendor '.$slug,$user,$user]);$vendor=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO purchase_orders (organization_id,public_id,vendor_id,order_number,status,expected_delivery_date,total_amount,created_by,updated_by) VALUES (?,?,?,?,'submitted',?,175.00,?,?)")->execute([$org,'po-'.$slug,$vendor,'PO-'.$slug,$yesterday,$user,$user]);

// Urgent manager-visible location handoff.
employee_shift_message_save($pdo,$org,['messageType'=>'announcement','targetType'=>'location','targetId'=>$location,'title'=>'Walk-in temperature check','body'=>'Verify the walk-in before dinner service.','priority'=>'urgent'],$user,true);

// CRM-linked paid guest plus one still-open POS check for closing detection.
$customer=crm_customer_save($pdo,$org,['displayName'=>'CI Regular','email'=>$slug.'.guest@example.test'],$user,'manual');$customerId=(int)$customer['id'];
$pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,customer_id,public_id,check_number,business_date,service_mode,guest_count,status,subtotal,total_amount,amount_paid,opened_by,closed_by,opened_at,closed_at,closed_hour) VALUES (?,?,?,?,?,?,'dine_in',2,'paid',50,55,55,?,?,NOW(6),NOW(6),12)")->execute([$org,$location,$customerId,'check-paid-'.$slug,'CI-PAID-'.$slug,$date,$user,$user]);
$pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,public_id,check_number,business_date,service_mode,guest_count,status,subtotal,total_amount,opened_by,opened_at) VALUES (?,?,?,?,?,'dine_in',2,'open',30,32,?,NOW(6))")->execute([$org,$location,'check-open-'.$slug,'CI-OPEN-'.$slug,$date,$user]);

$brief=manager_brief_snapshot($pdo,$org,$date,$location,'closing',$user);
dmci_assert(($brief['location']['key']??'')===$locationKey,'Brief must retain the selected organization-scoped location.');
dmci_assert(abs((float)($brief['sales']['actual']['netSales']??0)-500)<.01,'Brief must use canonical Sales Intelligence actual net sales.');
dmci_assert(abs((float)($brief['sales']['forecast']['projected_net_sales']??0)-620)<.01,'Brief must surface the canonical daily forecast.');
dmci_assert((int)$brief['labor']['unassignedShifts']===1,'Brief must surface aggregate unassigned published shifts.');
dmci_assert((int)$brief['labor']['pendingRequests']===1,'Brief must surface pending shift requests.');
dmci_assert((int)$brief['tasks']['blocked']===1&&(int)$brief['tasks']['critical']===1,'Brief must surface blocked critical operational tasks.');
dmci_assert((int)$brief['inventory']['lowCount']===1,'Brief must surface inventory at/below reorder point.');
dmci_assert((int)$brief['purchasing']['overdue']===1,'Brief must surface overdue purchase deliveries.');
dmci_assert((int)$brief['handoffs']['highPriority']===1,'Brief must surface urgent/high shift communications.');
dmci_assert((int)$brief['pos']['openChecks']===1&&(int)$brief['pos']['paidChecks']===1,'Brief must summarize native POS check state.');
dmci_assert((int)$brief['crm']['linkedCustomers']===1&&(int)$brief['crm']['newCustomers']===1,'Brief CRM signal must be a transaction-derived customer count without PII.');

$types=array_column($brief['exceptions'],'type');foreach(['task-blocked','inventory-low','po-overdue','handoff','labor-unassigned','labor-requests','labor-forecast-gap','pos-open-closing'] as $required)dmci_assert(in_array($required,$types,true),'Missing expected manager exception: '.$required);
$blocked=array_values(array_filter($brief['exceptions'],static fn(array $e):bool=>$e['type']==='task-blocked'))[0];dmci_assert(!$blocked['acknowledged'],'New manager exception must begin unacknowledged.');
$acks=manager_brief_ack_exception($pdo,$org,$brief['location'],$date,(string)$blocked['key'],'Manager contacted maintenance.',$user);dmci_assert(isset($acks[$blocked['key']]),'Exception acknowledgement must persist.');
$brief2=manager_brief_snapshot($pdo,$org,$date,$location,'closing',$user);$blocked2=array_values(array_filter($brief2['exceptions'],static fn(array $e):bool=>$e['type']==='task-blocked'))[0];dmci_assert($blocked2['acknowledged']&&($blocked2['acknowledgement']['note']??'')==='Manager contacted maintenance.','Acknowledgement must decorate, not remove, the live exception.');
manager_brief_ack_exception($pdo,$org,$brief['location'],$date,(string)$blocked['key'],'Maintenance ETA confirmed.',$user);dmci_assert((int)dmci_one($pdo,'SELECT COUNT(*) FROM manager_daily_exception_actions WHERE organization_id=? AND exception_key=?',[$org,$blocked['key']])===1,'Repeated acknowledgement must update one durable acknowledgement record.');

$opening=manager_brief_save_phase($pdo,$org,$brief['location'],$date,'opening','Opening walk completed.',true,$user);dmci_assert(($opening['opening_note']??'')==='Opening walk completed.'&&!empty($opening['opening_acknowledged_at']),'Opening manager note and review state must persist.');
$closing=manager_brief_save_phase($pdo,$org,$brief['location'],$date,'closing','Closing review complete.',true,$user);dmci_assert(($closing['closing_note']??'')==='Closing review complete.'&&!empty($closing['closing_acknowledged_at']),'Closing manager note and review state must persist.');

// Organization boundary: another organization cannot address this location/log.
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Other Daily Manager '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{manager_brief_location($pdo,$otherOrg,$location);}catch(InvalidArgumentException){$isolated=true;}dmci_assert($isolated,'Daily Manager Brief location access must be organization-isolated.');
dmci_assert(manager_brief_log($pdo,$otherOrg,$locationKey,$date)===null,'Daily manager logs must be organization-isolated.');

// The manager operations layer must not depend on employee performance/coaching evidence.
$core=(string)file_get_contents(__DIR__.'/../includes/daily-manager-core.php');dmci_assert(!preg_match('/employee_(?:performance|coaching)|coaching_notes|development_goals/i',$core),'GM operating brief must not consume employee performance/coaching data.');
dmci_assert(!preg_match('/email|phone|display_name.*crm/i',json_encode($brief['crm'])?:''),'GM CRM summary must expose aggregate activity only, not customer identity/contact data.');

echo "daily-manager contract passed\n";
