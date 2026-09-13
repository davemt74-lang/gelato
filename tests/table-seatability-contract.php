<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-seatability.php';

$pdo=app_pdo();
function tsc(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function tsc_table(array $tables,string $publicId): ?array {foreach($tables as $table)if(($table['publicId']??null)===$publicId)return $table;return null;}
function tsc_has(array $availability,string $publicId): bool {foreach($availability as $candidate)if(in_array($publicId,(array)($candidate['tables']??[]),true))return true;return false;}

$slug='seat-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Seatability '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Seat','Manager','Seat Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,service_ops_business_date($pdo,$org,$location),$user);
$available=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Available 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$dirty=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Dirty 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$blocked=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Blocked 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$source=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Source 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$pdo->prepare("UPDATE service_tables SET state='dirty' WHERE organization_id=? AND public_id=?")->execute([$org,$dirty['publicId']]);
$pdo->prepare("UPDATE service_tables SET state='blocked' WHERE organization_id=? AND public_id=?")->execute([$org,$blocked['publicId']]);

$today=service_ops_business_date($pdo,$org,$location);
$dashboard=service_seatability_dashboard($pdo,$org,$location,$today,$user);
$a=tsc_table($dashboard['tables'],$available['publicId']);$d=tsc_table($dashboard['tables'],$dirty['publicId']);$b=tsc_table($dashboard['tables'],$blocked['publicId']);
tsc($a!==null&&!empty($a['reservableNow']),'Available table must be reservable now.');
tsc($d!==null&&empty($d['reservableNow']),'Dirty table must not be reservable now.');
tsc($b!==null&&empty($b['reservableNow']),'Blocked table must not be reservable now.');

$now=pos_clock($pdo,$org,$location);$near=$now->modify('+5 minutes')->format('Y-m-d H:i:s');$future=$now->modify('+30 minutes')->format('Y-m-d H:i:s');
$nearAvailability=service_seatability_availability($pdo,$org,$location,$near,2,90);
tsc(tsc_has($nearAvailability,$available['publicId']),'Available table must remain a near-term reservation candidate.');
tsc(!tsc_has($nearAvailability,$dirty['publicId']),'Dirty table must be excluded during cleanup lead time.');
tsc(!tsc_has($nearAvailability,$blocked['publicId']),'Blocked table must never be a reservation candidate.');
$futureAvailability=service_seatability_availability($pdo,$org,$location,$future,2,90);
tsc(tsc_has($futureAvailability,$dirty['publicId']),'Dirty table may be offered for a sufficiently future reservation after cleanup lead time.');
tsc(!tsc_has($futureAvailability,$blocked['publicId']),'Blocked table must remain unavailable for future reservations.');

echo "table-seatability-part1-ok\n";
