<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/table-service-core.php';

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
$lineId=(int)$check['items'][0]['id'];
$course=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Course Timing'],$user);
$course=pos_add_item($pdo,$org,(string)$course['publicId'],$price,1,'',$user);
$courseLineId=(int)$course['items'][0]['id'];

$previousPhpTimezone=date_default_timezone_get();
$previousDbTimezone=(string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
date_default_timezone_set('UTC');
try{
    $pdo->exec("SET SESSION time_zone='+11:00'");
    $pdo->exec('SET timestamp=UNIX_TIMESTAMP(NOW(6))');
    kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
    table_service_kds_send_lines($pdo,$org,(string)$course['publicId'],[$courseLineId],$user,false);

    $fresh=$pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,sent_at,NOW(6)) sent_age,fired_at=sent_at fired_on_send FROM kds_order_items WHERE organization_id=? AND pos_check_item_id IN (?,?) ORDER BY pos_check_item_id');
    $fresh->execute([$org,$lineId,$courseLineId]);
    $freshRows=$fresh->fetchAll();

    $basicItem=static function () use ($pdo,$org,$location,$lineId): array {
        foreach(kds_board($pdo,$org,$location)['items'] as $row){
            if((int)$row['pos_check_item_id']===$lineId)return $row;
        }
        throw new RuntimeException('Basic KDS must show the timing ticket.');
    };
    $setAge=$pdo->prepare('UPDATE kds_order_items SET fired_at=TIMESTAMPADD(MICROSECOND,?,NOW(6)) WHERE organization_id=? AND pos_check_item_id=?');
    $setAge->execute([-90_000_000,$org,$lineId]);
    $row=$basicItem();
    kdsta_assert($row['ageSeconds']===90&&$row['late']===false,'Basic KDS must read a 90-second age when PHP and MariaDB use different timezones.');

    $productionRows=kds_production_board($pdo,$org,$location)['items'];
    $row=array_values(array_filter($productionRows,static fn(array $row): bool => (int)$row['pos_check_item_id']===$lineId))[0]??null;
    kdsta_assert(is_array($row)&&$row['ageSeconds']===90,'Production KDS age must remain database-authoritative.');
    kdsta_assert($row['warning']===true&&$row['late']===false,'A 90-second item on a 100-second station target must be an SLA warning.');

    kdsta_assert(count($freshRows)===2,'Both kitchen send paths must create timing rows.');
    foreach($freshRows as $row){
        kdsta_assert((int)$row['sent_age']===0&&(int)$row['fired_on_send']===1,'Both send paths must use the database clock for initial send and fire.');
    }

    $setAge->execute([-100_000_000,$org,$lineId]);
    kdsta_assert($basicItem()['late']===false,'An item exactly at its station target must have no late flag.');
    $setAge->execute([-100_000_001,$org,$lineId]);
    $row=$basicItem();
    kdsta_assert($row['ageSeconds']===100&&$row['late']===true,'An item above its target must be late even when its rounded age is unchanged.');
    $setAge->execute([1_000_000,$org,$lineId]);
    $row=$basicItem();
    kdsta_assert($row['ageSeconds']===0&&$row['late']===false,'A future timestamp must produce a zero age.');

    $pdo->prepare("UPDATE kds_order_items SET status='held',fired_at=NULL,sent_at=DATE_SUB(NOW(6),INTERVAL 120 SECOND) WHERE organization_id=? AND pos_check_item_id=?")->execute([$org,$lineId]);
    $row=$basicItem();
    kdsta_assert($row['ageSeconds']===120&&$row['late']===false,'Held items must retain their send age with no late flag.');
}finally{
    $pdo->exec('SET timestamp=0');
    $pdo->exec('SET SESSION time_zone='.$pdo->quote($previousDbTimezone));
    date_default_timezone_set($previousPhpTimezone);
}

echo "kds-timing-audit-ok\n";
