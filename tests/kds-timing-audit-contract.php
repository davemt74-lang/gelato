<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';

$pdo=app_pdo();
function kdsta_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$slug='kds-time-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['KDS Timing '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Timing Kitchen','Phoenix','AZ','active')")->execute([$org]);
$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Timing','Cook','Timing Cook']);
$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Timing',?,'active',1)")->execute([$org,'timing-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Timing Pizza',?,1)")->execute([$org,$section,'timing-pizza-'.$slug]);
$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',12.00,'USD',1)")->execute([$item]);
$price=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Timing Oven','targetSeconds'=>100],$user);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','tableName'=>'Timing'],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$user);
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$lineId=(int)$check['items'][0]['id'];
$pdo->prepare("UPDATE kds_order_items SET fired_at=DATE_SUB(NOW(6),INTERVAL 90 SECOND) WHERE organization_id=? AND pos_check_item_id=?")->execute([$org,$lineId]);

$previous=date_default_timezone_get();
date_default_timezone_set('Pacific/Honolulu');
try{
    $board=kds_production_board($pdo,$org,$location,null,false);
}finally{
    date_default_timezone_set($previous);
}
$row=$board['items'][0]??null;
kdsta_assert(is_array($row),'Timing ticket must be present.');
kdsta_assert((int)$row['ageSeconds']>=85&&(int)$row['ageSeconds']<=100,'KDS age must be database-authoritative and independent of the PHP timezone.');
kdsta_assert($row['warning']===true&&$row['late']===false,'A 90-second item on a 100-second station target must be an SLA warning.');

echo "kds-timing-audit-ok\n";
