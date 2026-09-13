<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-reservation-protection.php';

$pdo=app_pdo();
function tcc(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function tcc_spawn_pair(array $left,array $right): array
{
    $gate=sys_get_temp_dir().'/gelato-concurrency-'.bin2hex(random_bytes(8));
    @unlink($gate);
    $spawn=static function(array $cmd)use($gate): array {
        array_splice($cmd,2,0,[$gate]);
        $spec=[1=>['pipe','w'],2=>['pipe','w']];
        $pipes=[];$proc=proc_open($cmd,$spec,$pipes,__DIR__.'/..');
        if(!is_resource($proc))throw new RuntimeException('Could not start concurrency worker.');
        return [$proc,$pipes];
    };
    [$lp,$lpipe]=$spawn($left);[$rp,$rpipe]=$spawn($right);
    usleep(150000);touch($gate);
    $lo=stream_get_contents($lpipe[1]);$le=stream_get_contents($lpipe[2]);fclose($lpipe[1]);fclose($lpipe[2]);
    $ro=stream_get_contents($rpipe[1]);$re=stream_get_contents($rpipe[2]);fclose($rpipe[1]);fclose($rpipe[2]);
    $lc=proc_close($lp);$rc=proc_close($rp);@unlink($gate);
    $decode=static function(string $out,string $err,int $code): array {
        $lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$out)?:[])));$last=$lines?end($lines):'';
        $json=$last!==''?json_decode($last,true):null;
        return ['exit'=>$code,'json'=>is_array($json)?$json:null,'stdout'=>$out,'stderr'=>$err];
    };
    return [$decode($lo,$le,$lc),$decode($ro,$re,$rc)];
}
function tcc_ok_count(array $pair): int {return count(array_filter($pair,static fn(array $r):bool=>!empty($r['json']['ok'])));}
function tcc_error_messages(array $pair): array {return array_values(array_filter(array_map(static fn(array $r):string=>(string)($r['json']['message']??''),$pair)));}

$slug='concurrency-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Concurrency '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Concurrency','Manager','Concurrency Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,service_ops_business_date($pdo,$org,$location),$user);

$seat=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Seat Race','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$a=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Lock A','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$b=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Lock B','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$futureRace=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Future Race','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$waitRace=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Wait Race','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);

$forward=service_ops_order_table_ids($pdo,$org,$location,[$a['publicId'],$b['publicId']]);
$reverse=service_ops_order_table_ids($pdo,$org,$location,[$b['publicId'],$a['publicId']]);
tcc($forward===$reverse,'Canonical table lock order must be independent of caller order.');
tcc($forward===[$a['publicId'],$b['publicId']],'Canonical table lock order must follow immutable service-table ID order.');

$php=PHP_BINARY;$worker=__DIR__.'/table-concurrency-worker.php';$base=[$php,$worker];
$pair=tcc_spawn_pair(array_merge($base,['seat',(string)$org,(string)$location,(string)$user,$seat['publicId'],'2']),array_merge($base,['seat',(string)$org,(string)$location,(string)$user,$seat['publicId'],'2']));
tcc(tcc_ok_count($pair)===1,'Exactly one simultaneous seat attempt may create the active table visit.');
$seatErrors=tcc_error_messages($pair);tcc(count($seatErrors)===1&&str_contains($seatErrors[0],'active check'),'Losing simultaneous seat attempt must fail as an ordinary occupied-table conflict, not a database deadlock.');
$q=$pdo->prepare('SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?');$q->execute([$org,$seat['publicId']]);$active=(int)$q->fetchColumn();tcc($active>0,'Winning simultaneous seat must leave one active check on the physical table.');
$q=$pdo->prepare("SELECT COUNT(*) FROM pos_checks WHERE organization_id=? AND id=? AND status='open'");$q->execute([$org,$active]);tcc((int)$q->fetchColumn()===1,'Simultaneous seat race must leave exactly one canonical open POS check.');

$now=pos_clock($pdo,$org,$location);$r1At=$now->modify('+180 minutes')->format('Y-m-d H:i:s');$r2At=$now->modify('+360 minutes')->format('Y-m-d H:i:s');
$r1=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Opposite Order One','partySize'=>6,'scheduledAt'=>$r1At,'durationMinutes'=>90],$user);
$r2=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Opposite Order Two','partySize'=>6,'scheduledAt'=>$r2At,'durationMinutes'=>90],$user);
$pair=tcc_spawn_pair(array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$r1['publicId'],$a['publicId'].','.$b['publicId']]),array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$r2['publicId'],$b['publicId'].','.$a['publicId']]));
tcc(tcc_ok_count($pair)===2,'Opposite-order non-overlapping multi-table assignments must both complete without deadlock.');
$r1row=host_reservation_row($pdo,$org,$r1['publicId'],false);$r2row=host_reservation_row($pdo,$org,$r2['publicId'],false);$r1tables=host_reservation_tables($pdo,$org,(int)$r1row['id']);$r2tables=host_reservation_tables($pdo,$org,(int)$r2row['id']);
tcc(count($r1tables)===2&&$r1tables[0]['publicId']===$a['publicId'],'Canonical lock order must not change reservation one primary-table semantics.');
tcc(count($r2tables)===2&&$r2tables[0]['publicId']===$b['publicId'],'Canonical lock order must not change reservation two primary-table semantics.');

$conflictAt=$now->modify('+540 minutes')->format('Y-m-d H:i:s');
$c1=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Conflict One','partySize'=>2,'scheduledAt'=>$conflictAt,'durationMinutes'=>90],$user);
$c2=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Conflict Two','partySize'=>2,'scheduledAt'=>$conflictAt,'durationMinutes'=>90],$user);
$pair=tcc_spawn_pair(array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$c1['publicId'],$futureRace['publicId']]),array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$c2['publicId'],$futureRace['publicId']]));
tcc(tcc_ok_count($pair)===1,'Exactly one simultaneous overlapping future reservation may claim a physical table.');
$futureErrors=tcc_error_messages($pair);tcc(count($futureErrors)===1&&str_contains($futureErrors[0],'conflicts with another reservation'),'Losing future assignment must fail as a reservation conflict, not a deadlock.');
$q=$pdo->prepare('SELECT COUNT(*) FROM guest_reservation_tables WHERE service_table_id=(SELECT id FROM service_tables WHERE organization_id=? AND public_id=?) AND reservation_id IN (?,?)');$q->execute([$org,$futureRace['publicId'],(int)$c1['id'],(int)$c2['id']]);tcc((int)$q->fetchColumn()===1,'Future assignment race must leave only one reservation-table link.');

$w1=service_seatability_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Wait One','partySize'=>2],$user);
$w2=service_seatability_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Wait Two','partySize'=>2],$user);
$pair=tcc_spawn_pair(array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$w1['publicId'],$waitRace['publicId']]),array_merge($base,['assign',(string)$org,(string)$location,(string)$user,$w2['publicId'],$waitRace['publicId']]));
tcc(tcc_ok_count($pair)===1,'Exactly one active waitlist party may concurrently claim a physical table.');
$waitErrors=tcc_error_messages($pair);tcc(count($waitErrors)===1&&str_contains($waitErrors[0],'another active waitlist party'),'Losing waitlist assignment must fail as an ordinary assignment conflict.');

$pair=tcc_spawn_pair(array_merge($base,['combination',(string)$org,(string)$location,(string)$user,'Combo AB',$a['publicId'].','.$b['publicId']]),array_merge($base,['combination',(string)$org,(string)$location,(string)$user,'Combo BA',$b['publicId'].','.$a['publicId']]));
tcc(tcc_ok_count($pair)===2,'Opposite-order concurrent combination saves must both complete without deadlock.');
$comboResults=array_values(array_map(static fn(array $r):array=>$r['json'],$pair));
foreach($comboResults as $combo){tcc(count($combo['tables']??[])===2,'Concurrent saved combination must retain both physical members.');}
$comboAB=array_values(array_filter($comboResults,static fn(array $r):bool=>($r['tables'][0]??null)===$a['publicId']));$comboBA=array_values(array_filter($comboResults,static fn(array $r):bool=>($r['tables'][0]??null)===$b['publicId']));
tcc(count($comboAB)===1&&count($comboBA)===1,'Canonical locking must preserve each concurrent combination primary member.');

echo "table-concurrency-ok\n";
