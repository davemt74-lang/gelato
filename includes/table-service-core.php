<?php
declare(strict_types=1);

require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/kds-core.php';

function table_service_ready(PDO $pdo): bool
{
    foreach(['service_sections','service_tables','service_check_contexts','service_section_assignments','service_events','pos_checks','pos_check_items'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_check_items' AND column_name IN ('seat_number','course_key','course_sequence')");
    $q->execute();
    return (int)$q->fetchColumn()===3;
}

function table_service_public_id(string $prefix): string {return $prefix.'-'.bin2hex(random_bytes(12));}

function table_service_transaction(PDO $pdo,callable $callback): mixed
{
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{$result=$callback();if($owns)$pdo->commit();return $result;}
    catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function table_service_courses(): array
{
    return [
        ['key'=>'drinks','name'=>'Drinks','sequence'=>10],
        ['key'=>'starters','name'=>'Starters','sequence'=>20],
        ['key'=>'mains','name'=>'Mains','sequence'=>30],
        ['key'=>'dessert','name'=>'Dessert','sequence'=>40],
        ['key'=>'other','name'=>'Other','sequence'=>50],
    ];
}

function table_service_course(string $key): array
{
    foreach(table_service_courses() as $course)if($course['key']===$key)return $course;
    throw new InvalidArgumentException('Choose a valid course.');
}

function table_service_location(PDO $pdo,int $org,int $locationId): array
{
    return pos_location($pdo,$org,$locationId);
}

function table_service_business_date(PDO $pdo,int $org,int $locationId): string
{
    table_service_location($pdo,$org,$locationId);
    return pos_clock($pdo,$org,$locationId)->format('Y-m-d');
}

function table_service_user(PDO $pdo,int $org,int $userId): array
{
    $q=$pdo->prepare("SELECT u.id,u.display_name,m.job_title FROM organization_memberships m JOIN users u ON u.id=m.user_id WHERE m.organization_id=? AND u.id=? AND m.status='active' AND u.status<>'archived' LIMIT 1");
    $q->execute([$org,$userId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Active staff member was not found.');
    return ['id'=>(int)$row['id'],'name'=>(string)$row['display_name'],'jobTitle'=>$row['job_title']];
}

function table_service_staff(PDO $pdo,int $org,int $locationId): array
{
    table_service_location($pdo,$org,$locationId);
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.display_name,m.job_title FROM organization_memberships m JOIN users u ON u.id=m.user_id LEFT JOIN user_roles ur ON ur.membership_id=m.id AND ur.revoked_at IS NULL WHERE m.organization_id=? AND m.status='active' AND u.status<>'archived' AND (m.primary_location_id IS NULL OR m.primary_location_id=? OR ur.location_id=? OR ur.location_id IS NULL) ORDER BY u.display_name,u.id");
    $q->execute([$org,$locationId,$locationId]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['display_name'],'jobTitle'=>$r['job_title']],$q->fetchAll());
}

function table_service_event(PDO $pdo,int $org,int $locationId,?int $tableId,?int $checkId,?int $itemId,string $type,string $note,?array $metadata,int $userId): void
{
    $note=mb_substr(trim($note),0,1000,'UTF-8')?:null;
    $json=$metadata===null?null:json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO service_events (organization_id,location_id,table_id,check_id,pos_check_item_id,event_type,note,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$locationId,$tableId,$checkId,$itemId,$type,$note,$json,$userId]);
}

function table_service_sections(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    $businessDate=table_service_business_date($pdo,$org,$locationId);
    $sql='SELECT s.id,s.public_id,s.name,s.sort_order,s.status,a.assigned_user_id,u.display_name assigned_user_name FROM service_sections s LEFT JOIN service_section_assignments a ON a.section_id=s.id AND a.organization_id=s.organization_id AND a.business_date=? LEFT JOIN users u ON u.id=a.assigned_user_id WHERE s.organization_id=? AND s.location_id=?';
    if($activeOnly)$sql.=" AND s.status='active'";
    $sql.=' ORDER BY s.sort_order,s.name,s.id';$q=$pdo->prepare($sql);$q->execute([$businessDate,$org,$locationId]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'sortOrder'=>(int)$r['sort_order'],'status'=>(string)$r['status'],'assignedUserId'=>$r['assigned_user_id']!==null?(int)$r['assigned_user_id']:null,'assignedUserName'=>$r['assigned_user_name']],$q->fetchAll());
}

function table_service_section_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    table_service_location($pdo,$org,$locationId);$public=trim((string)($input['publicId']??''));$name=mb_substr(trim((string)($input['name']??'')),0,120,'UTF-8');
    if($name==='')throw new InvalidArgumentException('Section name is required.');$sort=(int)($input['sortOrder']??0);$status=(string)($input['status']??'active');if(!in_array($status,['active','inactive'],true))$status='active';
    if($public===''){$public=table_service_public_id('section');$pdo->prepare('INSERT INTO service_sections (organization_id,location_id,public_id,name,sort_order,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$org,$locationId,$public,$name,$sort,$status,$userId,$userId]);}
    else{$q=$pdo->prepare('SELECT id FROM service_sections WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$public]);$id=(int)$q->fetchColumn();if(!$id)throw new InvalidArgumentException('Section was not found.');$pdo->prepare('UPDATE service_sections SET name=?,sort_order=?,status=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$name,$sort,$status,$userId,$org,$id]);}
    foreach(table_service_sections($pdo,$org,$locationId,false) as $section)if($section['publicId']===$public)return $section;
    throw new RuntimeException('Section could not be loaded after save.');
}

function table_service_section_assign(PDO $pdo,int $org,int $locationId,string $sectionPublicId,?int $assignedUserId,string $businessDate,int $userId): array
{
    table_service_location($pdo,$org,$locationId);$date=DateTimeImmutable::createFromFormat('!Y-m-d',$businessDate);if(!$date||$date->format('Y-m-d')!==$businessDate)throw new InvalidArgumentException('Business date is invalid.');
    $q=$pdo->prepare('SELECT id FROM service_sections WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$sectionPublicId]);$sectionId=(int)$q->fetchColumn();if(!$sectionId)throw new InvalidArgumentException('Section was not found.');
    if(!$assignedUserId){$pdo->prepare('DELETE FROM service_section_assignments WHERE organization_id=? AND section_id=? AND business_date=?')->execute([$org,$sectionId,$businessDate]);return ['sectionPublicId'=>$sectionPublicId,'assignedUser'=>null,'businessDate'=>$businessDate];}
    $staff=table_service_user($pdo,$org,$assignedUserId);
    $pdo->prepare('INSERT INTO service_section_assignments (organization_id,location_id,section_id,business_date,assigned_user_id,assigned_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=VALUES(assigned_by),updated_at=NOW(6)')->execute([$org,$locationId,$sectionId,$businessDate,$assignedUserId,$userId]);
    return ['sectionPublicId'=>$sectionPublicId,'assignedUser'=>$staff,'businessDate'=>$businessDate];
}

function table_service_table_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    table_service_location($pdo,$org,$locationId);$public=trim((string)($input['publicId']??''));$name=mb_substr(trim((string)($input['name']??'')),0,80,'UTF-8');if($name==='')throw new InvalidArgumentException('Table name is required.');
    $sectionId=null;$sectionPublic=trim((string)($input['sectionPublicId']??''));if($sectionPublic!==''){$q=$pdo->prepare("SELECT id FROM service_sections WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");$q->execute([$org,$locationId,$sectionPublic]);$sectionId=(int)$q->fetchColumn();if(!$sectionId)throw new InvalidArgumentException('Active section was not found.');}
    $capacity=max(1,min(99,(int)($input['capacity']??2)));$shape=(string)($input['shape']??'round');if(!in_array($shape,['round','square','rectangle','bar'],true))$shape='round';$status=(string)($input['status']??'active');if(!in_array($status,['active','inactive'],true))$status='active';
    $x=max(0,min(95,(float)($input['xPercent']??0)));$y=max(0,min(95,(float)($input['yPercent']??0)));$w=max(5,min(40,(float)($input['widthPercent']??12)));$h=max(5,min(40,(float)($input['heightPercent']??12)));
    if($public===''){$public=table_service_public_id('table');$pdo->prepare('INSERT INTO service_tables (organization_id,location_id,section_id,public_id,name,capacity,shape,x_percent,y_percent,width_percent,height_percent,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$org,$locationId,$sectionId,$public,$name,$capacity,$shape,$x,$y,$w,$h,$status,$userId,$userId]);}
    else{$q=$pdo->prepare('SELECT id,active_check_id FROM service_tables WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1');$q->execute([$org,$locationId,$public]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Table was not found.');if($status==='inactive'&&$row['active_check_id']!==null)throw new InvalidArgumentException('An occupied table cannot be deactivated.');$pdo->prepare('UPDATE service_tables SET section_id=?,name=?,capacity=?,shape=?,x_percent=?,y_percent=?,width_percent=?,height_percent=?,status=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$sectionId,$name,$capacity,$shape,$x,$y,$w,$h,$status,$userId,$org,(int)$row['id']]);}
    $map=table_service_map($pdo,$org,$locationId,false);foreach($map['tables'] as $table)if($table['publicId']===$public)return $table;throw new RuntimeException('Table could not be loaded after save.');
}

function table_service_map(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    $location=table_service_location($pdo,$org,$locationId);$sql="SELECT t.id,t.public_id,t.name,t.capacity,t.shape,t.x_percent,t.y_percent,t.width_percent,t.height_percent,t.state,t.status,t.seated_at,t.active_check_id,t.assigned_user_id,s.public_id section_public_id,s.name section_name,u.display_name assigned_user_name,c.public_id check_public_id,c.check_number,c.guest_count,c.subtotal,c.total_amount,c.opened_at,(SELECT COUNT(*) FROM service_check_contexts cx WHERE cx.organization_id=t.organization_id AND cx.table_id=t.id AND cx.status='active') active_check_count FROM service_tables t LEFT JOIN service_sections s ON s.id=t.section_id AND s.organization_id=t.organization_id LEFT JOIN users u ON u.id=t.assigned_user_id LEFT JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id WHERE t.organization_id=? AND t.location_id=?";$args=[$org,$locationId];if($activeOnly)$sql.=" AND t.status='active'";$sql.=' ORDER BY COALESCE(s.sort_order,9999),s.name,t.name,t.id';$q=$pdo->prepare($sql);$q->execute($args);$tables=[];
    foreach($q->fetchAll() as $r)$tables[]=['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'shape'=>(string)$r['shape'],'xPercent'=>(float)$r['x_percent'],'yPercent'=>(float)$r['y_percent'],'widthPercent'=>(float)$r['width_percent'],'heightPercent'=>(float)$r['height_percent'],'state'=>(string)$r['state'],'status'=>(string)$r['status'],'seatedAt'=>$r['seated_at'],'sectionPublicId'=>$r['section_public_id'],'sectionName'=>$r['section_name'],'assignedUserId'=>$r['assigned_user_id']!==null?(int)$r['assigned_user_id']:null,'assignedUserName'=>$r['assigned_user_name'],'activeCheckId'=>$r['active_check_id']!==null?(int)$r['active_check_id']:null,'checkPublicId'=>$r['check_public_id'],'checkNumber'=>$r['check_number'],'guestCount'=>$r['guest_count']!==null?(int)$r['guest_count']:null,'subtotal'=>$r['subtotal']!==null?(float)$r['subtotal']:null,'totalAmount'=>$r['total_amount']!==null?(float)$r['total_amount']:null,'openedAt'=>$r['opened_at'],'activeCheckCount'=>(int)$r['active_check_count']];
    return ['location'=>$location,'sections'=>table_service_sections($pdo,$org,$locationId,$activeOnly),'tables'=>$tables,'staff'=>table_service_staff($pdo,$org,$locationId),'courses'=>table_service_courses()];
}

function table_service_payment_guard(PDO $pdo,int $org,int $checkId): void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");$q->execute([$org,$checkId]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Check transfer, split and merge are locked after the first captured tender.');
}

function table_service_check_row(PDO $pdo,int $org,string $checkPublicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT * FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$q->execute([$org,$checkPublicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('POS check was not found.');return $row;
}

function table_service_context(PDO $pdo,int $org,int $checkId,bool $forUpdate=false): ?array
{
    $q=$pdo->prepare('SELECT cx.*,t.public_id table_public_id,t.name table_name,u.display_name server_name FROM service_check_contexts cx LEFT JOIN service_tables t ON t.id=cx.table_id AND t.organization_id=cx.organization_id LEFT JOIN users u ON u.id=cx.server_user_id WHERE cx.organization_id=? AND cx.check_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$q->execute([$org,$checkId]);$row=$q->fetch();return $row?:null;
}

function table_service_detail(PDO $pdo,int $org,string $checkPublicId): array
{
    $check=pos_check_details($pdo,$org,$checkPublicId);$context=table_service_context($pdo,$org,(int)$check['id']);
    $q=$pdo->prepare('SELECT id,seat_number,course_key,course_sequence FROM pos_check_items WHERE organization_id=? AND check_id=?');$q->execute([$org,(int)$check['id']]);$extra=[];foreach($q->fetchAll() as $r)$extra[(int)$r['id']]=$r;
    foreach($check['items'] as &$item){$x=$extra[(int)$item['id']]??null;$item['seatNumber']=$x&&$x['seat_number']!==null?(int)$x['seat_number']:null;$item['courseKey']=$x?(string)$x['course_key']:'main';$item['courseSequence']=$x?(int)$x['course_sequence']:30;$item['kitchen']=kds_sent_line($pdo,$org,(int)$item['id']);}unset($item);
    $check['serviceContext']=$context?['tablePublicId'=>$context['table_public_id'],'tableName'=>$context['table_name'],'serverUserId'=>$context['server_user_id']!==null?(int)$context['server_user_id']:null,'serverName'=>$context['server_name'],'partySize'=>(int)$context['party_size'],'currentCourseKey'=>(string)$context['current_course_key'],'status'=>(string)$context['status'],'seatedAt'=>$context['seated_at']]:null;
    $check['kitchenSummary']=kds_check_summary($pdo,$org,$checkPublicId);return $check;
}

function table_service_default_server(PDO $pdo,int $org,int $locationId,?int $sectionId,int $actorUserId): int
{
    if($sectionId){$businessDate=table_service_business_date($pdo,$org,$locationId);$q=$pdo->prepare('SELECT assigned_user_id FROM service_section_assignments WHERE organization_id=? AND location_id=? AND section_id=? AND business_date=? LIMIT 1');$q->execute([$org,$locationId,$sectionId,$businessDate]);$assigned=(int)($q->fetchColumn()?:0);if($assigned>0)return $assigned;}
    return $actorUserId;
}

function table_service_seat(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $partySize,?int $serverUserId,string $notes,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId){
        $q=$pdo->prepare("SELECT * FROM service_tables WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1 FOR UPDATE");$q->execute([$org,$locationId,$tablePublicId]);$table=$q->fetch();if(!$table)throw new InvalidArgumentException('Active table was not found.');if($table['active_check_id']!==null)throw new InvalidArgumentException('This table already has an active check.');
        $partySize=max(1,min(99,$partySize));if($serverUserId)table_service_user($pdo,$org,$serverUserId);$serverUserId=$serverUserId?:table_service_default_server($pdo,$org,$locationId,$table['section_id']!==null?(int)$table['section_id']:null,$userId);
        $check=pos_create_check($pdo,$org,$locationId,['serviceMode'=>'dine_in','tableName'=>(string)$table['name'],'guestCount'=>$partySize,'notes'=>$notes],$userId);$checkId=(int)$check['id'];
        $pdo->prepare("INSERT INTO service_check_contexts (organization_id,location_id,check_id,table_id,server_user_id,party_size,current_course_key,status,seated_at,created_by,updated_by) VALUES (?,?,?,?,?,?,'drinks','active',NOW(6),?,?)")->execute([$org,$locationId,$checkId,(int)$table['id'],$serverUserId,$partySize,$userId,$userId]);
        $pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=?,state='seated',seated_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$checkId,$serverUserId,$userId,$org,(int)$table['id']]);
        table_service_event($pdo,$org,$locationId,(int)$table['id'],$checkId,null,'party_seated','Party seated.',['partySize'=>$partySize,'serverUserId'=>$serverUserId],$userId);return table_service_detail($pdo,$org,(string)$check['publicId']);
    });
}

function table_service_assign_server(PDO $pdo,int $org,string $checkPublicId,int $serverUserId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$serverUserId,$userId){$staff=table_service_user($pdo,$org,$serverUserId);$check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can be reassigned.');$context=table_service_context($pdo,$org,(int)$check['id'],true);if(!$context)throw new InvalidArgumentException('Table-service context was not found.');$pdo->prepare('UPDATE service_check_contexts SET server_user_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$serverUserId,$userId,$org,(int)$check['id']]);if($context['table_id']!==null)$pdo->prepare('UPDATE service_tables SET assigned_user_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$serverUserId,$userId,$org,(int)$context['table_id']]);table_service_event($pdo,$org,(int)$check['location_id'],$context['table_id']!==null?(int)$context['table_id']:null,(int)$check['id'],null,'server_assigned','Server assigned.',['server'=>$staff],$userId);return table_service_detail($pdo,$org,$checkPublicId);});
}

function table_service_item_course(PDO $pdo,int $org,string $checkPublicId,int $itemId,?int $seatNumber,string $courseKey,int $userId): array
{
    $course=table_service_course($courseKey);if($seatNumber!==null)$seatNumber=max(1,min(99,$seatNumber));return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$itemId,$seatNumber,$course,$userId){$check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('This check is no longer open.');$q=$pdo->prepare("SELECT id,status FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? LIMIT 1 FOR UPDATE");$q->execute([$org,(int)$check['id'],$itemId]);$item=$q->fetch();if(!$item||$item['status']!=='active')throw new InvalidArgumentException('Active check item was not found.');kds_assert_pos_line_mutable($pdo,$org,$itemId);$pdo->prepare('UPDATE pos_check_items SET seat_number=?,course_key=?,course_sequence=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$seatNumber,$course['key'],$course['sequence'],$org,$itemId]);table_service_event($pdo,$org,(int)$check['location_id'],null,(int)$check['id'],$itemId,'seat_course_updated','Seat/course updated.',['seatNumber'=>$seatNumber,'courseKey'=>$course['key']],$userId);return table_service_detail($pdo,$org,$checkPublicId);});
}

function table_service_kds_send_lines(PDO $pdo,int $org,string $checkPublicId,array $posItemIds,int $userId,bool $hold=false): array
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$posItemIds),static fn(int $v):bool=>$v>0)));if(!$ids)return kds_check_summary($pdo,$org,$checkPublicId);
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$ids,$userId,$hold){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Only an open POS check can send items to the kitchen.');$ph=implode(',',array_fill(0,count($ids),'?'));$args=array_merge([$org,(int)$check['id']],$ids);
        $q=$pdo->prepare("SELECT i.id,i.menu_item_id FROM pos_check_items i LEFT JOIN kds_order_items k ON k.organization_id=i.organization_id AND k.pos_check_item_id=i.id WHERE i.organization_id=? AND i.check_id=? AND i.id IN ($ph) AND i.status='active' AND k.id IS NULL ORDER BY i.id FOR UPDATE");$q->execute($args);$lines=$q->fetchAll();$now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        foreach($lines as $line){$route=$pdo->prepare("SELECT r.station_id FROM kds_menu_routes r JOIN kds_stations s ON s.id=r.station_id AND s.organization_id=r.organization_id AND s.location_id=r.location_id AND s.status='active' WHERE r.organization_id=? AND r.location_id=? AND r.menu_item_id=? LIMIT 1");$route->execute([$org,(int)$check['location_id'],(int)$line['menu_item_id']]);$station=$route->fetchColumn();$status=$hold?'held':'queued';$fired=$hold?null:$now;$public=kds_public_id('kds-item');$pdo->prepare('INSERT INTO kds_order_items (organization_id,location_id,check_id,pos_check_item_id,station_id,public_id,status,sent_at,fired_at,last_action_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,(int)$check['location_id'],(int)$check['id'],(int)$line['id'],$station!==false?(int)$station:null,$public,$status,$now,$fired,$userId]);$kid=(int)$pdo->lastInsertId();kds_event($pdo,$org,$kid,'course_sent',null,$status,$station===false?'No active station route was configured.':null,$userId);}
        return kds_check_summary($pdo,$org,$checkPublicId);
    });
}

function table_service_fire_course(PDO $pdo,int $org,string $checkPublicId,string $courseKey,int $userId,bool $hold=false): array
{
    $course=table_service_course($courseKey);return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$courseKey,$course,$userId,$hold){$check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('This check is no longer open.');$q=$pdo->prepare("SELECT i.id FROM pos_check_items i LEFT JOIN kds_order_items k ON k.organization_id=i.organization_id AND k.pos_check_item_id=i.id WHERE i.organization_id=? AND i.check_id=? AND i.status='active' AND i.course_key=? AND k.id IS NULL ORDER BY i.id");$q->execute([$org,(int)$check['id'],$courseKey]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if(!$ids)throw new InvalidArgumentException('This course has no unsent active items.');$summary=table_service_kds_send_lines($pdo,$org,$checkPublicId,$ids,$userId,$hold);$context=table_service_context($pdo,$org,(int)$check['id'],true);if($context){$pdo->prepare('UPDATE service_check_contexts SET current_course_key=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$courseKey,$userId,$org,(int)$check['id']]);if($context['table_id']!==null)$pdo->prepare("UPDATE service_tables SET state='ordered',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$context['table_id']]);}table_service_event($pdo,$org,(int)$check['location_id'],$context&&$context['table_id']!==null?(int)$context['table_id']:null,(int)$check['id'],null,$hold?'course_held':'course_fired',($hold?'Held ':'Fired ').$course['name'].'.',['courseKey'=>$courseKey,'itemIds'=>$ids],$userId);return ['check'=>table_service_detail($pdo,$org,$checkPublicId),'kitchen'=>$summary];});
}

function table_service_table_state(PDO $pdo,int $org,string $tablePublicId,string $state,int $userId): array
{
    $valid=['available','seated','ordering','ordered','check_presented','dirty','blocked'];if(!in_array($state,$valid,true))throw new InvalidArgumentException('Table state is invalid.');return table_service_transaction($pdo,function()use($pdo,$org,$tablePublicId,$state,$userId){$q=$pdo->prepare('SELECT * FROM service_tables WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$tablePublicId]);$table=$q->fetch();if(!$table)throw new InvalidArgumentException('Table was not found.');if($state==='available'&&$table['active_check_id']!==null)throw new InvalidArgumentException('Close or transfer the active check before marking the table available.');$pdo->prepare('UPDATE service_tables SET state=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$state,$userId,$org,(int)$table['id']]);table_service_event($pdo,$org,(int)$table['location_id'],(int)$table['id'],$table['active_check_id']!==null?(int)$table['active_check_id']:null,null,'table_state_changed','Table state changed.',['state'=>$state],$userId);return table_service_map($pdo,$org,(int)$table['location_id']);});
}

function table_service_transfer(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){$check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can transfer tables.');table_service_payment_guard($pdo,$org,(int)$check['id']);$context=table_service_context($pdo,$org,(int)$check['id'],true);if(!$context)throw new InvalidArgumentException('Table-service context was not found.');$q=$pdo->prepare("SELECT * FROM service_tables WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1 FOR UPDATE");$q->execute([$org,(int)$check['location_id'],$destinationTablePublicId]);$dest=$q->fetch();if(!$dest)throw new InvalidArgumentException('Destination table was not found.');if($dest['active_check_id']!==null&&(int)$dest['active_check_id']!==(int)$check['id'])throw new InvalidArgumentException('Destination table already has an active check.');$sourceId=$context['table_id']!==null?(int)$context['table_id']:null;if($sourceId&&$sourceId===(int)$dest['id'])return table_service_detail($pdo,$org,$checkPublicId);if($sourceId)$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='available',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id=?")->execute([$userId,$org,$sourceId,(int)$check['id']]);$pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=?,state='seated',seated_at=COALESCE(seated_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([(int)$check['id'],$context['server_user_id'],$userId,$org,(int)$dest['id']]);$pdo->prepare('UPDATE service_check_contexts SET table_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([(int)$dest['id'],$userId,$org,(int)$check['id']]);$pdo->prepare('UPDATE pos_checks SET table_name=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([(string)$dest['name'],$org,(int)$check['id']]);table_service_event($pdo,$org,(int)$check['location_id'],(int)$dest['id'],(int)$check['id'],null,'table_transferred','Check transferred tables.',['fromTableId'=>$sourceId,'toTableId'=>(int)$dest['id']],$userId);return table_service_detail($pdo,$org,$checkPublicId);});
}

function table_service_move_items(PDO $pdo,int $org,int $sourceCheckId,int $targetCheckId,array $itemIds,int $userId,string $eventType): int
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$itemIds),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Choose at least one check item.');$ph=implode(',',array_fill(0,count($ids),'?'));$args=array_merge([$org,$sourceCheckId],$ids);$q=$pdo->prepare("SELECT id FROM pos_check_items WHERE organization_id=? AND check_id=? AND id IN ($ph) FOR UPDATE");$q->execute($args);$found=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if(count($found)!==count($ids))throw new InvalidArgumentException('One or more selected items do not belong to the source check.');
    $args=array_merge([$targetCheckId,$org,$sourceCheckId],$ids);$pdo->prepare("UPDATE pos_check_items SET check_id=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND id IN ($ph)")->execute($args);$args=array_merge([$targetCheckId,$org,$sourceCheckId],$ids);$pdo->prepare("UPDATE kds_order_items SET check_id=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND pos_check_item_id IN ($ph)")->execute($args);
    foreach($ids as $itemId){$k=kds_sent_line($pdo,$org,$itemId);if($k)kds_event($pdo,$org,(int)$k['id'],$eventType,(string)$k['status'],(string)$k['status'],'POS line moved to another open check.',$userId);}pos_recalculate_check($pdo,$org,$sourceCheckId);pos_recalculate_check($pdo,$org,$targetCheckId);return count($ids);
}

function table_service_split(PDO $pdo,int $org,string $sourceCheckPublicId,array $itemIds,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$itemIds,$userId){$source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);if($source['status']!=='open')throw new InvalidArgumentException('Only an open check can be split.');table_service_payment_guard($pdo,$org,(int)$source['id']);$context=table_service_context($pdo,$org,(int)$source['id'],true);if(!$context)throw new InvalidArgumentException('Table-service context was not found.');$new=pos_create_check($pdo,$org,(int)$source['location_id'],['serviceMode'=>(string)$source['service_mode'],'tableName'=>(string)($source['table_name']??''),'guestCount'=>(int)$source['guest_count'],'notes'=>'Split from '.(string)$source['check_number']],$userId);$newId=(int)$new['id'];$pdo->prepare('INSERT INTO service_check_contexts (organization_id,location_id,check_id,table_id,server_user_id,party_size,current_course_key,status,seated_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,\'active\',?,?,?)')->execute([$org,(int)$source['location_id'],$newId,$context['table_id'],$context['server_user_id'],(int)$context['party_size'],(string)$context['current_course_key'],$context['seated_at'],$userId,$userId]);$moved=table_service_move_items($pdo,$org,(int)$source['id'],$newId,$itemIds,$userId,'check_split');table_service_event($pdo,$org,(int)$source['location_id'],$context['table_id']!==null?(int)$context['table_id']:null,(int)$source['id'],null,'check_split_source','Items split to a new check.',['newCheckPublicId'=>$new['publicId'],'movedItems'=>$moved],$userId);table_service_event($pdo,$org,(int)$source['location_id'],$context['table_id']!==null?(int)$context['table_id']:null,$newId,null,'check_split_created','Check created from split.',['sourceCheckPublicId'=>$sourceCheckPublicId,'movedItems'=>$moved],$userId);return ['source'=>table_service_detail($pdo,$org,$sourceCheckPublicId),'created'=>table_service_detail($pdo,$org,(string)$new['publicId'])];});
}

function table_service_merge(PDO $pdo,int $org,string $sourceCheckPublicId,string $targetCheckPublicId,int $userId): array
{
    if($sourceCheckPublicId===$targetCheckPublicId)throw new InvalidArgumentException('Choose two different checks to merge.');return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$targetCheckPublicId,$userId){$source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);$target=table_service_check_row($pdo,$org,$targetCheckPublicId,true);if($source['status']!=='open'||$target['status']!=='open')throw new InvalidArgumentException('Both checks must be open.');if((int)$source['location_id']!==(int)$target['location_id'])throw new InvalidArgumentException('Checks at different locations cannot be merged.');table_service_payment_guard($pdo,$org,(int)$source['id']);table_service_payment_guard($pdo,$org,(int)$target['id']);$q=$pdo->prepare('SELECT id FROM pos_check_items WHERE organization_id=? AND check_id=? ORDER BY id');$q->execute([$org,(int)$source['id']]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if($ids)table_service_move_items($pdo,$org,(int)$source['id'],(int)$target['id'],$ids,$userId,'check_merged');$sourceContext=table_service_context($pdo,$org,(int)$source['id'],true);$targetContext=table_service_context($pdo,$org,(int)$target['id'],true);$hour=(int)pos_clock($pdo,$org,(int)$source['location_id'])->format('G');$pdo->prepare("UPDATE pos_checks SET status='merged',cancel_reason=?,closed_by=?,closed_at=NOW(6),closed_hour=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute(['Merged into '.(string)$target['check_number'],$userId,$hour,$org,(int)$source['id']]);if($sourceContext)$pdo->prepare("UPDATE service_check_contexts SET status='merged',closed_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?")->execute([$userId,$org,(int)$source['id']]);if($sourceContext&&$sourceContext['table_id']!==null&&(!$targetContext||(int)$sourceContext['table_id']!==(int)($targetContext['table_id']??0)))$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='available',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id=?")->execute([$userId,$org,(int)$sourceContext['table_id'],(int)$source['id']]);table_service_event($pdo,$org,(int)$source['location_id'],$targetContext&&$targetContext['table_id']!==null?(int)$targetContext['table_id']:null,(int)$target['id'],null,'checks_merged','Open checks merged.',['sourceCheckPublicId'=>$sourceCheckPublicId,'targetCheckPublicId'=>$targetCheckPublicId,'movedItems'=>count($ids)],$userId);return table_service_detail($pdo,$org,$targetCheckPublicId);});
}

function table_service_release_closed_check(PDO $pdo,int $org,string $checkPublicId,int $userId): void
{
    if(!table_service_ready($pdo))return;table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$userId){$check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']==='open')return;$context=table_service_context($pdo,$org,(int)$check['id'],true);if(!$context)return;if($context['table_id']!==null)$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id=?")->execute([$userId,$org,(int)$context['table_id'],(int)$check['id']]);$pdo->prepare("UPDATE service_check_contexts SET status='closed',closed_at=COALESCE(closed_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?")->execute([$userId,$org,(int)$check['id']]);table_service_event($pdo,$org,(int)$check['location_id'],$context['table_id']!==null?(int)$context['table_id']:null,(int)$check['id'],null,'check_released','Closed check released table.',['checkStatus'=>$check['status']],$userId);});
}
