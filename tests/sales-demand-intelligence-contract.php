<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/sales-intelligence-core.php';
require __DIR__.'/../includes/sales-pos-connectors.php';
require __DIR__.'/../includes/sales-location-rollup.php';

$pdo=app_pdo();
function sdi_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function sdi_count(PDO $pdo,string $sql,array $args=[]): int { $q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn(); }

sdi_assert(sales_intelligence_ready($pdo),'Sales Intelligence must be installed.');
sdi_assert(prep_intelligence_ready($pdo),'Prep Intelligence must be installed for demand-signal contract.');
sdi_assert(scheduling_core_ready($pdo),'Scheduling must be installed for labor-capacity contract.');

$slug='sales-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Sales CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Sales','Manager','Sales Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO staff_profiles (organization_id,user_id,employment_type,hourly_labor_cost,created_by,updated_by) VALUES (?,?,'hourly',20,?,?)")->execute([$org,$user,$user,$user]);
$pdo->prepare("INSERT INTO positions (organization_id,name,slug,status) VALUES (?,'Cook','cook','active')")->execute([$org]);$position=(int)$pdo->lastInsertId();
$sectionSlug='sales-ci-section-'.$slug;$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status) VALUES (?,'Pizza',?,'active')")->execute([$org,$sectionSlug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$section,'margherita-'.$slug]);$menuItem=(int)$pdo->lastInsertId();

sales_ensure_csv_integration($pdo,$org,$user);
$csv=tempnam(sys_get_temp_dir(),'gelato-sales-');sdi_assert(is_string($csv),'Temp CSV could not be created.');
$rows=[
 ['date','period_end','granularity','service_period','row_type','tickets','covers','gross_sales','net_sales','item_id','item_name','category','quantity','item_sales','hour','customer_email'],
 ['2026-08-29','','daily','all','summary','100','150','3900','3600','','','','','','','guest@example.test'],
 ['2026-08-29','','daily','all','item','','','','','MARG','Margherita Pizza','Pizza','30','600','',''],
 ['2026-09-05','','daily','all','summary','110','165','4200','3900','','','','','','',''],
 ['2026-09-05','','daily','all','item','','','','','MARG','Margherita Pizza','Pizza','34','680','',''],
 ['2026-09-12','','daily','all','summary','120','180','4500','4200','','','','','','',''],
 ['2026-09-12','','daily','all','item','','','','','MARG','Margherita Pizza','Pizza','38','760','',''],
 ['2026-09-19','','daily','all','summary','130','195','4800','4500','','','','','','',''],
 ['2026-09-19','','daily','all','item','','','','','MARG','Margherita Pizza','Pizza','42','840','',''],
 ['2026-09-19','','daily','dinner','summary','15','20','550','500','','','','','','17',''],
 ['2026-09-19','','daily','dinner','summary','20','30','760','700','','','','','','18',''],
 ['2026-09-14','2026-09-20','weekly','all','summary','800','1200','30000','28000','','','','','','',''],
 ['2026-08','2026-08-31','monthly','all','summary','3400','5100','125000','118000','','','','','','',''],
];
$fh=fopen($csv,'wb');sdi_assert((bool)$fh,'Temp CSV could not be opened.');foreach($rows as $r)fputcsv($fh,$r);fclose($fh);
$import=sales_import_csv($pdo,$org,$user,$csv,'sales-ci.csv',$location,false);sales_rebuild_all_location_rollups($pdo,$org,'csv');sdi_assert($import['accepted']===12,'All twelve sales rows should be accepted.');sdi_assert($import['rejected']===0,'No sales rows should be rejected.');
sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key=? AND granularity='daily' AND service_period='all'",[$org,'id:'.$location])===4,'Four location-specific daily all-day periods expected.');
sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key='all' AND granularity='daily' AND service_period='all'",[$org])===4,'All-location rollup must contain four daily periods.');
sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key=? AND granularity='weekly'",[$org,'id:'.$location])===1,'Weekly total must remain a weekly record.');
sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key=? AND granularity='monthly'",[$org,'id:'.$location])===1,'Monthly total must remain a monthly record.');
$q=$pdo->prepare("SELECT net_sales,tickets,covers FROM sales_periods WHERE organization_id=? AND location_key=? AND period_start='2026-09-19' AND service_period='dinner'");$q->execute([$org,'id:'.$location]);$dinner=$q->fetch();sdi_assert(abs((float)$dinner['net_sales']-1200.0)<0.01&&(int)$dinner['tickets']===35&&(int)$dinner['covers']===50,'Hourly dinner rows must aggregate instead of last-row overwrite.');sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_hourly WHERE organization_id=? AND location_key=? AND business_date='2026-09-19'",[$org,'id:'.$location])===2,'Two location hourly sales rows should be retained.');sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_hourly WHERE organization_id=? AND location_key='all' AND business_date='2026-09-19'",[$org])===2,'All-location hourly rollup should retain two hourly buckets.');
$q=$pdo->prepare("SELECT menu_item_id,quantity FROM sales_item_periods WHERE organization_id=? AND sales_period_id IN (SELECT id FROM sales_periods WHERE organization_id=? AND location_key=?) ORDER BY id LIMIT 1");$q->execute([$org,$org,'id:'.$location]);$item=$q->fetch();sdi_assert((int)$item['menu_item_id']===$menuItem,'Exact menu-name match should link imported item to canonical menu item.');sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM sales_item_periods sip JOIN sales_periods sp ON sp.id=sip.sales_period_id WHERE sip.organization_id=? AND sp.location_key='all'",[$org])>=4,'All-location item mix should be rolled up for forecasting and dashboard use.');
$q=$pdo->prepare("SELECT raw_json FROM sales_import_rows WHERE organization_id=? AND import_batch_id=(SELECT id FROM sales_import_batches WHERE organization_id=? AND public_id=?) ORDER BY source_row_number LIMIT 1");$q->execute([$org,$org,$import['publicId']]);$raw=(string)$q->fetchColumn();sdi_assert(!str_contains($raw,'customer_email')&&!str_contains($raw,'guest@example.test'),'Unknown POS/customer columns must not be persisted in sales row audit data.');
$duplicateBlocked=false;try{sales_import_csv($pdo,$org,$user,$csv,'sales-ci.csv',$location,false);}catch(InvalidArgumentException){$duplicateBlocked=true;}sdi_assert($duplicateBlocked,'Exact duplicate CSV import must be blocked by checksum.');

$historyDates=['2026-08-29','2026-09-05','2026-09-12','2026-09-19'];
foreach($historyDates as $date){$weekStart=(new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');$week=scheduling_ensure_week($pdo,$org,$weekStart,$user);$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,position_id,user_id,title,starts_at,ends_at,status,created_by,updated_by) VALUES (?,?,?,?,?,?,'Cook',?,?,'scheduled',?,?)")->execute([$org,sales_public_id('shift'),(int)$week['id'],$location,$position,$user,$date.' 12:00:00',$date.' 20:00:00',$user,$user]);$pdo->prepare("INSERT INTO time_clock_entries (organization_id,public_id,user_id,clocked_in_at,clocked_out_at,clock_in_source,clock_out_source,status,created_by,updated_by) VALUES (?,?,?,?,?,'manual','manual','closed',?,?)")->execute([$org,sales_public_id('clock'),$user,$date.' 12:00:00',$date.' 20:00:00',$user,$user]);}
$week=scheduling_ensure_week($pdo,$org,'2026-09-21',$user);$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,position_id,user_id,title,starts_at,ends_at,status,created_by,updated_by) VALUES (?,?,?,?,?,?,'Cook','2026-09-26 16:00:00','2026-09-26 22:00:00','scheduled',?,?)")->execute([$org,sales_public_id('shift'),(int)$week['id'],$location,$position,$user,$user,$user]);
$forecast=sales_forecast($pdo,$org,'2026-09-26','all',$location,$user,8);$f=$forecast['forecast'];sdi_assert((int)$f['sample_count']===4,'Forecast should use four same-weekday daily samples.');sdi_assert((float)$f['projected_net_sales']>3900&&(float)$f['projected_net_sales']<4300,'Projected sales should reflect same-weekday history.');sdi_assert((float)$f['projected_labor_hours']>6.0,'Demand-based labor estimate should exceed the six scheduled hours in this fixture.');sdi_assert(abs((float)$f['scheduled_labor_hours']-6.0)<0.01,'Scheduled labor should equal the six-hour target shift.');sdi_assert((float)$f['confidence']>=0.4,'Four consistent samples should produce usable confidence.');
sdi_assert(count($forecast['items'])>=1,'Item mix forecast should be generated from item history.');sdi_assert(count($forecast['positionCapacity'])>=1,'Position-level staffing capacity should be returned.');$encoded=json_encode($forecast['positionCapacity']);sdi_assert(is_string($encoded)&&!str_contains($encoded,'display_name')&&!str_contains($encoded,'email')&&!str_contains($encoded,'userId'),'Capacity output must not select or rank individual employees.');
sdi_assert(sdi_count($pdo,"SELECT COUNT(*) FROM prep_demand_signals WHERE organization_id=? AND source_type='sales_forecast' AND source_public_id=?",[$org,$f['public_id']])>=2,'Sales forecast should feed covers/item demand context into Prep Intelligence.');
$globalForecast=sales_forecast($pdo,$org,'2026-09-26','all',null,$user,8);sdi_assert(abs((float)$globalForecast['forecast']['projected_net_sales']-(float)$f['projected_net_sales'])<0.01,'All-location forecast should aggregate location-tagged sales evidence.');

$dash=sales_dashboard($pdo,$org,'2026-08-29','2026-09-19',$location);sdi_assert(abs((float)$dash['totals']['netSales']-16200.0)<0.01,'Dashboard daily net sales total is incorrect.');sdi_assert(abs((float)$dash['totals']['actualLaborHours']-32.0)<0.01,'Dashboard should calculate 32 actual labor hours.');sdi_assert(abs((float)$dash['totals']['actualLaborCost']-640.0)<0.01,'Dashboard labor cost should use staff hourly labor cost.');sdi_assert(abs((float)$dash['totals']['laborPercent']-3.95)<0.1,'Labor percent calculation is incorrect.');
$globalDash=sales_dashboard($pdo,$org,'2026-08-29','2026-09-19',null);sdi_assert(abs((float)$globalDash['totals']['netSales']-16200.0)<0.01,'Default All locations dashboard must include location-tagged sales.');sdi_assert(abs((float)$globalDash['totals']['actualLaborHours']-32.0)<0.01,'Default All locations labor must include location-tagged timeclock records.');

$primaryBlocked=false;try{sales_integration_save($pdo,$org,['provider'=>'toast','displayName'=>'Toast','restaurantExternalId'=>'ci-guid','isPrimary'=>true],$user);}catch(InvalidArgumentException){$primaryBlocked=true;}sdi_assert($primaryBlocked,'Unsynced POS providers must not replace the canonical CSV source.');sdi_assert(sales_primary_provider($pdo,$org)==='csv','CSV must remain canonical until a provider has successfully synced sales into the ledger.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Sales Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$other=sales_dashboard($pdo,$otherOrg,'2026-08-01','2026-09-30',null);sdi_assert((float)$other['totals']['netSales']===0.0,'Cross-organization sales data leaked.');

$status=sales_toast_status();sdi_assert(array_key_exists('configured',$status),'Toast connector status contract is missing.');sdi_assert($status['credentialsStoredInDatabase']===false,'Toast connector must not store credentials in the database.');
@unlink($csv);
echo "sales-demand-intelligence contract passed\n";
