<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/admin-control-core.php';

$pdo=app_pdo();
function acc_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$customerUser=['is_owner_role'=>0,'permissions'=>['customer.portal','online_ordering.use']];
acc_assert(!admin_control_allowed($customerUser),'Customer portal users must never enter the restaurant admin control center.');
acc_assert(!admin_online_orders_allowed($customerUser),'Customer portal users must never enter staff online-order operations.');

$managerUser=['is_owner_role'=>0,'permissions'=>['locations.manage','crm.view','pos.use']];
acc_assert(admin_control_allowed($managerUser),'A restaurant manager permission must allow the admin control center.');
acc_assert(admin_online_orders_allowed($managerUser),'Authorized POS/CRM staff must be able to monitor online orders.');
$managerModules=array_column(admin_modules($managerUser),'name');
acc_assert(in_array('Locations',$managerModules,true),'Location managers must see Locations in Admin.');
acc_assert(in_array('Customer CRM',$managerModules,true),'CRM viewers must see Customer CRM in Admin.');
acc_assert(in_array('POS',$managerModules,true),'POS users must see POS in Admin.');
acc_assert(!in_array('Menu Import',$managerModules,true),'Menu Import must remain owner-only.');
$ownerModules=array_column(admin_modules(['is_owner_role'=>1,'permissions'=>['*']]),'name');
acc_assert(in_array('Menu Import',$ownerModules,true),'Owners must see the owner-only menu import tool.');

$slug='admin-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Admin CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,public_slug,city,state,status,is_primary,pickup_enabled,delivery_enabled,online_ordering_enabled,pickup_lead_minutes) VALUES (?,'Admin Main',?,'Phoenix','AZ','active',1,1,0,1,20)")->execute([$org,'admin-main-'.$slug]);
$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-staff@example.test',password_hash('Admin-CI-Staff!42',PASSWORD_DEFAULT),'Admin','Staff','Admin Staff']);
$staff=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-customer@example.test',password_hash('Admin-CI-Customer!42',PASSWORD_DEFAULT),'Online','Guest','Online Guest']);
$customerUserId=(int)$pdo->lastInsertId();
$customerPublic='admin-customer-'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO crm_customers (organization_id,user_id,public_id,first_name,last_name,display_name,email,email_normalized,status,source) VALUES (?,?,?,?,?,?,?,?,'active','web')")
    ->execute([$org,$customerUserId,$customerPublic,'Online','Guest','Online Guest',$slug.'-customer@example.test',$slug.'-customer@example.test']);
$customer=(int)$pdo->lastInsertId();
$checkPublic='admin-check-'.bin2hex(random_bytes(6));
$checkNumber='ADM-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
$pdo->prepare("INSERT INTO pos_checks (organization_id,location_id,customer_id,public_id,check_number,business_date,service_mode,table_name,guest_count,status,currency,subtotal,tax_rate,tax_amount,service_charge_rate,service_charge_amount,total_amount,amount_paid,notes,opened_by) VALUES (?,?,?,?,?,CURDATE(),'pickup','Online Pickup',1,'open','USD',25.00,0.080000,2.00,0.000000,0.00,27.00,0.00,'Admin CI online order',?)")
    ->execute([$org,$location,$customer,$checkPublic,$checkNumber,$staff]);
$check=(int)$pdo->lastInsertId();
$orderPublic='admin-order-'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO online_orders (organization_id,location_id,customer_id,user_id,pos_check_id,public_id,idempotency_key,service_mode,payment_mode,status,requested_ready_at,customer_note) VALUES (?,?,?,?,?,?,?,'pickup','pay_at_pickup','submitted',DATE_ADD(NOW(6),INTERVAL 20 MINUTE),'Admin CI note')")
    ->execute([$org,$location,$customer,$customerUserId,$check,$orderPublic,'admin_ci_'.bin2hex(random_bytes(12))]);

$metrics=admin_dashboard_metrics($pdo,$org);
acc_assert((int)$metrics['onlineOrdersToday']===1,'Admin dashboard must count today online orders.');
acc_assert(abs((float)$metrics['onlineOrderValueToday']-27.0)<0.01,'Admin dashboard online order value is incorrect.');
acc_assert((int)$metrics['openOnlineOrders']===1,'Admin dashboard must count the open online order.');
acc_assert((int)$metrics['activeCustomers']===1,'Admin dashboard active customer count is incorrect.');
acc_assert((int)$metrics['promotionReach']===1,'Signed-in customer with default Inbox preference must be promotion eligible.');
acc_assert((int)$metrics['activeLocations']===1&&(int)$metrics['onlineLocations']===1,'Admin dashboard location readiness metrics are incorrect.');

$orders=admin_online_orders($pdo,$org,null,20);
acc_assert(count($orders)===1,'Admin online order list must return the seeded order.');
acc_assert((string)$orders[0]['check_number']===$checkNumber,'Admin order list returned the wrong POS check.');
acc_assert((string)$orders[0]['customer_name']==='Online Guest','Admin order list must resolve the CRM customer.');
acc_assert((string)$orders[0]['displayStatus']==='Submitted','An open order with no KDS rows should display Submitted.');
acc_assert(count(admin_online_orders($pdo,$org,$location,20))===1,'Location filter must preserve matching online orders.');

$pdo->prepare("INSERT INTO customer_inbox_preferences (organization_id,customer_id,promotions_enabled,updated_by) VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE promotions_enabled=0,updated_by=VALUES(updated_by)")->execute([$org,$customer,$staff]);
$metrics=admin_dashboard_metrics($pdo,$org);
acc_assert((int)$metrics['promotionReach']===0,'Inbox promotion opt-out must reduce Admin promotion reach without affecting account access.');
$pdo->prepare("UPDATE pos_checks SET status='paid',amount_paid=27.00,closed_at=NOW(6) WHERE id=?")->execute([$check]);
$orders=admin_online_orders($pdo,$org,null,20);
acc_assert((string)$orders[0]['displayStatus']==='Completed','Paid online order must display Completed in Admin.');

echo "admin-control-center contract passed\n";
