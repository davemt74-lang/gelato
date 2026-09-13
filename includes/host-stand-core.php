<?php
declare(strict_types=1);

require_once __DIR__.'/table-service-core.php';
require_once __DIR__.'/equipment-brain.php';
require_once __DIR__.'/floor-equipment-brain.php';
require_once __DIR__.'/customer-crm-core.php';

function host_ready(PDO $pdo): bool
{
    foreach(['guest_reservations','guest_reservation_tables','guest_reservation_events','table_combinations','table_combination_members','service_tables','equipment_assets','floor_plans'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='service_tables' AND column_name='equipment_asset_id'");
    return (int)$q->fetchColumn()===1 && table_service_ready($pdo);
}

function host_transaction(PDO $pdo,callable $callback): mixed
{
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{$result=$callback();if($owns)$pdo->commit();return $result;}catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function host_public_id(string $prefix): string{return $prefix.'-'.bin2hex(random_bytes(12));}
function host_nullable(string $value,int $max): ?string{$value=mb_substr(trim($value),0,$max,'UTF-8');return $value===''?null:$value;}

function host_floor_plans(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT public_id,name,width_ft,depth_ft,scale_px_per_ft,is_default,updated_at FROM floor_plans WHERE organization_id=? AND archived_at IS NULL ORDER BY is_default DESC,name,id");$q->execute([$org]);
    return array_map(static fn(array $r):array=>['publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'widthFt'=>(float)$r['width_ft'],'depthFt'=>(float)$r['depth_ft'],'scale'=>(float)$r['scale_px_per_ft'],'isDefault'=>(bool)$r['is_default'],'updatedAt'=>(string)$r['updated_at']],$q->fetchAll());
}

function host_table_defaults(string $shape): array
{
    return match($shape){'rectangle'=>[48.0,30.0],'bar'=>[72.0,24.0],default=>[36.0,36.0]};
}

function host_asset_available(array $row): bool
{
    return !empty($row['equipment_asset_id']) && empty($row['asset_archived_at']) && (string)$row['asset_operational_status']==='active' && !in_array((string)$row['asset_condition_status'],['poor'],true) && (string)$row['status']==='active';
}

function host_table_rows(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    table_service_location($pdo,$org,$locationId);
    $sql="SELECT t.*,s.public_id section_public_id,s.name section_name,u.display_name assigned_user_name,
                 a.public_id asset_public_id,a.asset_tag,a.asset_type,a.operational_status asset_operational_status,a.condition_status asset_condition_status,a.purchase_price,a.replacement_cost,a.width_inches,a.depth_inches,a.height_inches,a.floor_plan_public_id,a.floor_plan_x_ft,a.floor_plan_y_ft,a.floor_plan_rotation_deg,a.floor_plan_locked,a.archived_at asset_archived_at,
                 p.name floor_plan_name,p.width_ft plan_width_ft,p.depth_ft plan_depth_ft,
                 c.public_id check_public_id,c.check_number,c.guest_count,c.opened_at
          FROM service_tables t
          LEFT JOIN service_sections s ON s.id=t.section_id AND s.organization_id=t.organization_id
          LEFT JOIN users u ON u.id=t.assigned_user_id
          LEFT JOIN equipment_assets a ON a.id=t.equipment_asset_id AND a.organization_id=t.organization_id
          LEFT JOIN floor_plans p ON p.organization_id=t.organization_id AND p.public_id=a.floor_plan_public_id AND p.archived_at IS NULL
          LEFT JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id
          WHERE t.organization_id=? AND t.location_id=?";
    if($activeOnly)$sql.=" AND t.status='active'";$sql.=' ORDER BY COALESCE(s.sort_order,9999),s.name,t.name,t.id';$q=$pdo->prepare($sql);$q->execute([$org,$locationId]);$rows=[];
    foreach($q->fetchAll() as $r){$assetReady=host_asset_available($r);$rows[]=[
        'id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'shape'=>(string)$r['shape'],'state'=>(string)$r['state'],'status'=>(string)$r['status'],
        'sectionPublicId'=>$r['section_public_id'],'sectionName'=>$r['section_name'],'assignedUserId'=>$r['assigned_user_id']!==null?(int)$r['assigned_user_id']:null,'assignedUserName'=>$r['assigned_user_name'],
        'activeCheckId'=>$r['active_check_id']!==null?(int)$r['active_check_id']:null,'checkPublicId'=>$r['check_public_id'],'checkNumber'=>$r['check_number'],'guestCount'=>$r['guest_count']!==null?(int)$r['guest_count']:null,'openedAt'=>$r['opened_at'],
        'asset'=>['id'=>$r['asset_public_id'],'assetTag'=>$r['asset_tag'],'assetType'=>$r['asset_type'],'operationalStatus'=>$r['asset_operational_status'],'conditionStatus'=>$r['asset_condition_status'],'purchasePrice'=>$r['purchase_price']!==null?(float)$r['purchase_price']:null,'replacementCost'=>$r['replacement_cost']!==null?(float)$r['replacement_cost']:null,'widthInches'=>$r['width_inches']!==null?(float)$r['width_inches']:null,'depthInches'=>$r['depth_inches']!==null?(float)$r['depth_inches']:null,'heightInches'=>$r['height_inches']!==null?(float)$r['height_inches']:null,'floorPlanId'=>$r['floor_plan_public_id'],'floorPlanName'=>$r['floor_plan_name'],'xFt'=>$r['floor_plan_x_ft']!==null?(float)$r['floor_plan_x_ft']:null,'yFt'=>$r['floor_plan_y_ft']!==null?(float)$r['floor_plan_y_ft']:null,'rotationDeg'=>$r['floor_plan_rotation_deg']!==null?(float)$r['floor_plan_rotation_deg']:0.0,'locked'=>(bool)($r['floor_plan_locked']??0)],
        'managedAsset'=>$r['equipment_asset_id']!==null,'physicalReady'=>$assetReady,'reservableNow'=>$assetReady&&$r['active_check_id']===null&&!in_array((string)$r['state'],['out_of_service'],true),
        'projection'=>['xPercent'=>(float)$r['x_percent'],'yPercent'=>(float)$r['y_percent'],'widthPercent'=>(float)$r['width_percent'],'heightPercent'=>(float)$r['height_percent']],
    ];}
    return $rows;
}

function host_table_row(PDO $pdo,int $org,int $locationId,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT t.*,a.public_id asset_public_id,a.operational_status asset_operational_status,a.condition_status asset_condition_status,a.archived_at asset_archived_at FROM service_tables t LEFT JOIN equipment_assets a ON a.id=t.equipment_asset_id AND a.organization_id=t.organization_id WHERE t.organization_id=? AND t.location_id=? AND t.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));$q->execute([$org,$locationId,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Service table was not found.');return $r;
}

function host_sync_table_asset(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);if($table['equipment_asset_id']!==null)return host_table_rows($pdo,$org,$locationId,false)[array_search($tablePublicId,array_column(host_table_rows($pdo,$org,$locationId,false),'publicId'),true)];
        $location=table_service_location($pdo,$org,$locationId);[$width,$depth]=host_table_defaults((string)$table['shape']);$assetPublic=host_public_id('asset');$assetTag='TABLE-'.preg_replace('/[^A-Z0-9]+/','-',strtoupper((string)$table['name']));
        $pdo->prepare("INSERT INTO equipment_assets (organization_id,public_id,name,asset_type,purpose,asset_tag,operational_status,condition_status,criticality,location_name,width_inches,depth_inches,height_inches,capacity_text,maintenance_required,cleaning_notes,created_by,updated_by) VALUES (?,?,?,'dining_table','Guest dining table',?,'active','good','low',?,?,?,?,?,0,'Inspect stability, surface and floor contact during routine cleaning.',?,?)")
            ->execute([$org,$assetPublic,(string)$table['name'],$assetTag,(string)$location['name'],$width,$depth,30.0,(string)$table['capacity'].' seats',$userId,$userId]);$assetId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE service_tables SET equipment_asset_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$assetId,$userId,$org,(int)$table['id']]);equipment_brain_sync_asset($pdo,$org,$assetId,$userId);
        table_service_event($pdo,$org,$locationId,(int)$table['id'],$table['active_check_id']!==null?(int)$table['active_check_id']:null,null,'table_asset_linked','Physical dining-table asset created and linked.',['assetPublicId'=>$assetPublic],$userId);
        foreach(host_table_rows($pdo,$org,$locationId,false) as $row)if($row['publicId']===$tablePublicId)return $row;throw new RuntimeException('Linked table could not be reloaded.');
    });
}

function host_sync_all_table_assets(PDO $pdo,int $org,int $locationId,int $userId): int
{
    $q=$pdo->prepare('SELECT public_id FROM service_tables WHERE organization_id=? AND location_id=? AND status=\'active\' AND equipment_asset_id IS NULL ORDER BY id');$q->execute([$org,$locationId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);foreach($ids as $id)host_sync_table_asset($pdo,$org,$locationId,(string)$id,$userId);return count($ids);
}

function host_create_managed_table(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){
        $location=table_service_location($pdo,$org,$locationId);$name=mb_substr(trim((string)($input['name']??'')),0,80,'UTF-8');if($name==='')throw new InvalidArgumentException('Table name is required.');
        $capacity=max(1,min(99,(int)($input['capacity']??2)));$shape=(string)($input['shape']??'round');if(!in_array($shape,['round','square','rectangle','bar'],true))$shape='round';[$dw,$dd]=host_table_defaults($shape);
        $width=max(12,min(240,(float)($input['widthInches']??$dw)));$depth=max(12,min(240,(float)($input['depthInches']??$dd)));$height=max(12,min(60,(float)($input['heightInches']??30)));
        $sectionId=null;$sectionPublic=trim((string)($input['sectionPublicId']??''));if($sectionPublic!==''){$q=$pdo->prepare("SELECT id FROM service_sections WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");$q->execute([$org,$locationId,$sectionPublic]);$sectionId=(int)$q->fetchColumn();if(!$sectionId)throw new InvalidArgumentException('Active section was not found.');}
        $assetPublic=host_public_id('asset');$assetTag=host_nullable((string)($input['assetTag']??''),120)?:'TABLE-'.preg_replace('/[^A-Z0-9]+/','-',strtoupper($name));$purchase=isset($input['purchasePrice'])&&$input['purchasePrice']!==''?round(max(0,(float)$input['purchasePrice']),2):null;$replacement=isset($input['replacementCost'])&&$input['replacementCost']!==''?round(max(0,(float)$input['replacementCost']),2):null;
        $pdo->prepare("INSERT INTO equipment_assets (organization_id,public_id,name,asset_type,purpose,asset_tag,purchase_price,replacement_cost,operational_status,condition_status,criticality,location_name,width_inches,depth_inches,height_inches,capacity_text,maintenance_required,cleaning_notes,created_by,updated_by) VALUES (?,?,?,'dining_table','Guest dining table',?,?,?,'active','good','low',?,?,?,?,?,0,'Inspect stability, surface and floor contact during routine cleaning.',?,?)")
            ->execute([$org,$assetPublic,$name,$assetTag,$purchase,$replacement,(string)$location['name'],$width,$depth,$height,$capacity.' seats',$userId,$userId]);$assetId=(int)$pdo->lastInsertId();
        $tablePublic=host_public_id('table');$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,section_id,equipment_asset_id,public_id,name,capacity,shape,state,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,'available','active',?,?)")
            ->execute([$org,$locationId,$sectionId,$assetId,$tablePublic,$name,$capacity,$shape,$userId,$userId]);$tableId=(int)$pdo->lastInsertId();equipment_brain_sync_asset($pdo,$org,$assetId,$userId);table_service_event($pdo,$org,$locationId,$tableId,null,null,'managed_table_created','Managed physical table created.',['assetPublicId'=>$assetPublic],$userId);
        if(!empty($input['floorPlanId']))host_place_table($pdo,$org,$locationId,$tablePublic,$input,$userId);
        foreach(host_table_rows($pdo,$org,$locationId,false) as $row)if($row['publicId']===$tablePublic)return $row;throw new RuntimeException('Managed table could not be loaded.');
    });
}

function host_update_table_asset(PDO $pdo,int $org,int $locationId,string $tablePublicId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$input,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);if($table['equipment_asset_id']===null){host_sync_table_asset($pdo,$org,$locationId,$tablePublicId,$userId);$table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);}if($table['active_check_id']!==null&&in_array((string)($input['operationalStatus']??'active'),['out_of_service','retired'],true))throw new InvalidArgumentException('An occupied table cannot be taken out of service.');
        $status=(string)($input['operationalStatus']??'active');if(!in_array($status,['active','maintenance','out_of_service','retired'],true))$status='active';$condition=(string)($input['conditionStatus']??'good');if(!in_array($condition,['excellent','good','fair','poor','unknown'],true))$condition='good';
        $width=max(12,min(240,(float)($input['widthInches']??36)));$depth=max(12,min(240,(float)($input['depthInches']??36)));$height=max(12,min(60,(float)($input['heightInches']??30)));$purchase=isset($input['purchasePrice'])&&$input['purchasePrice']!==''?round(max(0,(float)$input['purchasePrice']),2):null;$replacement=isset($input['replacementCost'])&&$input['replacementCost']!==''?round(max(0,(float)$input['replacementCost']),2):null;$tag=host_nullable((string)($input['assetTag']??''),120);
        $pdo->prepare('UPDATE equipment_assets SET asset_tag=?,purchase_price=?,replacement_cost=?,operational_status=?,condition_status=?,width_inches=?,depth_inches=?,height_inches=?,capacity_text=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$tag,$purchase,$replacement,$status,$condition,$width,$depth,$height,(int)$table['capacity'].' seats',$userId,$org,(int)$table['equipment_asset_id']]);
        if($status!=='active'||$condition==='poor')$pdo->prepare("UPDATE service_tables SET state=CASE WHEN active_check_id IS NULL THEN 'out_of_service' ELSE state END,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$table['id']]);elseif((string)$table['state']==='out_of_service')$pdo->prepare("UPDATE service_tables SET state='available',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id IS NULL")->execute([$userId,$org,(int)$table['id']]);
        equipment_brain_sync_asset($pdo,$org,(int)$table['equipment_asset_id'],$userId);foreach(host_table_rows($pdo,$org,$locationId,false) as $row)if($row['publicId']===$tablePublicId)return $row;throw new RuntimeException('Managed table could not be reloaded.');
    });
}

function host_place_table(PDO $pdo,int $org,int $locationId,string $tablePublicId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$input,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);if($table['equipment_asset_id']===null){host_sync_table_asset($pdo,$org,$locationId,$tablePublicId,$userId);$table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);}if(!empty($input['unplace'])){$pdo->exec('SET @gelato_allow_floor_placement=1');try{$pdo->prepare("UPDATE equipment_assets SET floor_plan_public_id=NULL,floor_plan_component_id=NULL,floor_plan_x_ft=NULL,floor_plan_y_ft=NULL,floor_plan_rotation_deg=0,floor_plan_z_index=1,floor_plan_locked=0,floor_plan_placed_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$table['equipment_asset_id']]);}finally{$pdo->exec('SET @gelato_allow_floor_placement=NULL');}}
        else{$planPublic=trim((string)($input['floorPlanId']??''));$q=$pdo->prepare('SELECT width_ft,depth_ft FROM floor_plans WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$q->execute([$org,$planPublic]);$plan=$q->fetch();if(!$plan)throw new InvalidArgumentException('Active floor plan was not found.');$x=round((float)($input['xFt']??0),3);$y=round((float)($input['yFt']??0),3);if($x<0||$y<0||$x>(float)$plan['width_ft']||$y>(float)$plan['depth_ft'])throw new InvalidArgumentException('Table position is outside the floor plan.');$rot=fmod((float)($input['rotationDeg']??0),360);if($rot<0)$rot+=360;$assetPublic=(string)$table['asset_public_id'];$pdo->exec('SET @gelato_allow_floor_placement=1');try{$pdo->prepare("UPDATE equipment_assets SET floor_plan_public_id=?,floor_plan_component_id=?,floor_plan_x_ft=?,floor_plan_y_ft=?,floor_plan_rotation_deg=?,floor_plan_placed_at=COALESCE(floor_plan_placed_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$planPublic,'asset:'.$assetPublic,$x,$y,$rot,$userId,$org,(int)$table['equipment_asset_id']]);}finally{$pdo->exec('SET @gelato_allow_floor_placement=NULL');}}
        $a=equipment_brain_asset_row($pdo,$org,(int)$table['equipment_asset_id']);if($a){equipment_brain_sync_asset($pdo,$org,(int)$a['id'],$userId);floor_equipment_brain_sync_placement($pdo,$org,(string)$a['public_id'],$userId);}foreach(host_table_rows($pdo,$org,$locationId,false) as $row)if($row['publicId']===$tablePublicId)return $row;throw new RuntimeException('Placed table could not be reloaded.');
    });
}

function host_combinations(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare("SELECT * FROM table_combinations WHERE organization_id=? AND location_id=? AND status='active' ORDER BY name,id");$q->execute([$org,$locationId]);$out=[];foreach($q->fetchAll() as $c){$m=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.capacity,m.is_primary,m.sort_order FROM table_combination_members m JOIN service_tables t ON t.id=m.service_table_id AND t.organization_id=? WHERE m.combination_id=? ORDER BY m.is_primary DESC,m.sort_order,t.name");$m->execute([$org,(int)$c['id']]);$members=$m->fetchAll();$out[]=['id'=>(int)$c['id'],'publicId'=>(string)$c['public_id'],'name'=>(string)$c['name'],'capacity'=>(int)$c['capacity'],'tables'=>array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'primary'=>(bool)$r['is_primary']],$members)];}return $out;
}

function host_combination_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){$name=mb_substr(trim((string)($input['name']??'')),0,120,'UTF-8');if($name==='')throw new InvalidArgumentException('Combination name is required.');$ids=array_values(array_unique(array_filter(array_map('strval',(array)($input['tablePublicIds']??[])))));if(count($ids)<2)throw new InvalidArgumentException('Choose at least two tables for a combination.');$tables=[];$capacity=0;foreach($ids as $id){$t=host_table_row($pdo,$org,$locationId,$id,true);if($t['status']!=='active')throw new InvalidArgumentException('Only active tables can be combined.');$tables[]=$t;$capacity+=(int)$t['capacity'];}$public=trim((string)($input['publicId']??''));if($public===''){$public=host_public_id('combo');$pdo->prepare("INSERT INTO table_combinations (organization_id,location_id,public_id,name,capacity,status,created_by,updated_by) VALUES (?,?,?,?,?,'active',?,?)")->execute([$org,$locationId,$public,$name,$capacity,$userId,$userId]);$comboId=(int)$pdo->lastInsertId();}else{$q=$pdo->prepare('SELECT id FROM table_combinations WHERE organization_id=? AND location_id=? AND public_id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$locationId,$public]);$comboId=(int)$q->fetchColumn();if(!$comboId)throw new InvalidArgumentException('Table combination was not found.');$pdo->prepare('UPDATE table_combinations SET name=?,capacity=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$name,$capacity,$userId,$org,$comboId]);$pdo->prepare('DELETE FROM table_combination_members WHERE combination_id=?')->execute([$comboId]);}foreach($tables as $i=>$t)$pdo->prepare('INSERT INTO table_combination_members (combination_id,service_table_id,sort_order,is_primary) VALUES (?,?,?,?)')->execute([$comboId,(int)$t['id'],$i,$i===0?1:0]);foreach(host_combinations($pdo,$org,$locationId) as $c)if($c['publicId']===$public)return $c;throw new RuntimeException('Combination could not be loaded.');});
}

function host_reservation_event(PDO $pdo,int $org,int $reservationId,string $type,string $note,?array $meta,int $userId): void
{
    $json=$meta===null?null:json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare('INSERT INTO guest_reservation_events (organization_id,reservation_id,event_type,note,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)')->execute([$org,$reservationId,$type,host_nullable($note,1000),$json,$userId]);
}

function host_reservation_tables(PDO $pdo,int $org,int $reservationId): array
{
    $q=$pdo->prepare('SELECT t.id,t.public_id,t.name,t.capacity,rt.is_primary FROM guest_reservation_tables rt JOIN service_tables t ON t.id=rt.service_table_id AND t.organization_id=? WHERE rt.reservation_id=? ORDER BY rt.is_primary DESC,t.name,t.id');$q->execute([$org,$reservationId]);return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'primary'=>(bool)$r['is_primary']],$q->fetchAll());
}

function host_reservation_payload(PDO $pdo,int $org,array $r): array
{
    return ['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'type'=>(string)$r['reservation_type'],'status'=>(string)$r['status'],'customerPublicId'=>$r['customer_public_id']??null,'guestName'=>(string)$r['guest_name'],'guestEmail'=>$r['guest_email'],'guestPhone'=>$r['guest_phone'],'partySize'=>(int)$r['party_size'],'scheduledAt'=>$r['scheduled_at'],'durationMinutes'=>(int)$r['duration_minutes'],'joinedWaitlistAt'=>$r['joined_waitlist_at'],'quotedWaitMinutes'=>$r['quoted_wait_minutes']!==null?(int)$r['quoted_wait_minutes']:null,'arrivedAt'=>$r['arrived_at'],'seatedAt'=>$r['seated_at'],'completedAt'=>$r['completed_at'],'cancelledAt'=>$r['cancelled_at'],'noShowAt'=>$r['no_show_at'],'checkPublicId'=>$r['check_public_id']??null,'notes'=>$r['notes'],'source'=>(string)$r['source'],'tables'=>host_reservation_tables($pdo,$org,(int)$r['id'])];
}

function host_reservations(PDO $pdo,int $org,int $locationId,string $date): array
{
    $q=$pdo->prepare("SELECT r.*,cu.public_id customer_public_id,c.public_id check_public_id FROM guest_reservations r LEFT JOIN crm_customers cu ON cu.id=r.customer_id AND cu.organization_id=r.organization_id LEFT JOIN pos_checks c ON c.id=r.seated_check_id AND c.organization_id=r.organization_id WHERE r.organization_id=? AND r.location_id=? AND ((r.reservation_type='reservation' AND DATE(r.scheduled_at)=?) OR (r.reservation_type='waitlist' AND r.status IN ('waiting','arrived'))) ORDER BY CASE WHEN r.reservation_type='waitlist' THEN 0 ELSE 1 END,COALESCE(r.joined_waitlist_at,r.scheduled_at),r.id");$q->execute([$org,$locationId,$date]);return array_map(static fn(array $r):array=>host_reservation_payload($pdo,$org,$r),$q->fetchAll());
}

function host_reservation_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT r.*,cu.public_id customer_public_id,c.public_id check_public_id FROM guest_reservations r LEFT JOIN crm_customers cu ON cu.id=r.customer_id AND cu.organization_id=r.organization_id LEFT JOIN pos_checks c ON c.id=r.seated_check_id AND c.organization_id=r.organization_id WHERE r.organization_id=? AND r.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Reservation or waitlist entry was not found.');return $r;
}

function host_validate_assignment(PDO $pdo,int $org,int $locationId,array $tablePublicIds,int $partySize,?string $scheduledAt,int $duration,?int $excludeReservationId=null): array
{
    $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));if(!$ids)throw new InvalidArgumentException('Choose at least one table.');$rows=[];$capacity=0;foreach($ids as $public){$t=host_table_row($pdo,$org,$locationId,$public,true);if(!host_asset_available($t))throw new InvalidArgumentException((string)$t['name'].' is not available as a physical table asset.');$rows[]=$t;$capacity+=(int)$t['capacity'];}if($capacity<$partySize)throw new InvalidArgumentException('Selected tables do not have enough seating capacity.');
    if($scheduledAt!==null){$start=new DateTimeImmutable($scheduledAt);$end=$start->modify('+'.max(15,$duration).' minutes');foreach($rows as $t){$sql="SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations r ON r.id=rt.reservation_id WHERE rt.service_table_id=? AND r.organization_id=? AND r.status IN ('booked','confirmed','arrived') AND r.scheduled_at IS NOT NULL AND r.scheduled_at < ? AND DATE_ADD(r.scheduled_at,INTERVAL r.duration_minutes MINUTE) > ?";$args=[(int)$t['id'],$org,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')];if($excludeReservationId){$sql.=' AND r.id<>?';$args[]=$excludeReservationId;}$q=$pdo->prepare($sql);$q->execute($args);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException((string)$t['name'].' conflicts with another reservation.');}}
    return $rows;
}

function host_assign_reservation_tables(PDO $pdo,int $org,int $reservationId,array $tables): void
{
    $pdo->prepare('DELETE FROM guest_reservation_tables WHERE reservation_id=?')->execute([$reservationId]);foreach($tables as $i=>$t)$pdo->prepare('INSERT INTO guest_reservation_tables (reservation_id,service_table_id,is_primary) VALUES (?,?,?)')->execute([$reservationId,(int)$t['id'],$i===0?1:0]);
}

function host_reservation_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){table_service_location($pdo,$org,$locationId);$type=(string)($input['type']??'reservation');if(!in_array($type,['reservation','waitlist'],true))$type='reservation';$party=max(1,min(99,(int)($input['partySize']??1)));$duration=max(15,min(480,(int)($input['durationMinutes']??90)));$customerId=null;$customerPublic=trim((string)($input['customerPublicId']??''));$guest=mb_substr(trim((string)($input['guestName']??'')),0,180,'UTF-8');$email=host_nullable((string)($input['guestEmail']??''),320);$phone=host_nullable((string)($input['guestPhone']??''),64);
        if($customerPublic!==''){$c=crm_customer_row($pdo,$org,$customerPublic);if($c['status']!=='active')throw new InvalidArgumentException('Archived CRM customer cannot be reserved.');$customerId=(int)$c['id'];if($guest==='')$guest=(string)$c['display_name'];if($email===null)$email=$c['email'];if($phone===null)$phone=$c['phone'];}if($guest==='')throw new InvalidArgumentException('Guest name is required.');if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Guest email address is invalid.');
        $scheduled=null;if($type==='reservation'){$raw=trim((string)($input['scheduledAt']??''));if($raw==='')throw new InvalidArgumentException('Reservation date and time are required.');$d=new DateTimeImmutable($raw);$scheduled=$d->format('Y-m-d H:i:s');}$tables=[];$tableIds=(array)($input['tablePublicIds']??[]);$comboPublic=trim((string)($input['combinationPublicId']??''));if($comboPublic!==''){$q=$pdo->prepare("SELECT t.public_id FROM table_combinations c JOIN table_combination_members m ON m.combination_id=c.id JOIN service_tables t ON t.id=m.service_table_id WHERE c.organization_id=? AND c.location_id=? AND c.public_id=? AND c.status='active' ORDER BY m.is_primary DESC,m.sort_order");$q->execute([$org,$locationId,$comboPublic]);$tableIds=$q->fetchAll(PDO::FETCH_COLUMN);if(!$tableIds)throw new InvalidArgumentException('Active table combination was not found.');}if($tableIds)$tables=host_validate_assignment($pdo,$org,$locationId,$tableIds,$party,$scheduled,$duration);
        $public=host_public_id($type==='waitlist'?'wait':'reservation');$status=$type==='waitlist'?'waiting':'booked';$quoted=$type==='waitlist'&&isset($input['quotedWaitMinutes'])&&$input['quotedWaitMinutes']!==''?max(0,min(360,(int)$input['quotedWaitMinutes'])):null;$notes=host_nullable((string)($input['notes']??''),8000);$source=in_array((string)($input['source']??'host'),['host','phone','web','walk_in','agent'],true)?(string)($input['source']??'host'):'host';
        $pdo->prepare("INSERT INTO guest_reservations (organization_id,location_id,public_id,reservation_type,status,customer_id,guest_name,guest_email,guest_phone,party_size,scheduled_at,duration_minutes,joined_waitlist_at,quoted_wait_minutes,notes,source,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$org,$locationId,$public,$type,$status,$customerId,$guest,$email,$phone,$party,$scheduled,$duration,$type==='waitlist'?date('Y-m-d H:i:s'):null,$quoted,$notes,$source,$userId,$userId]);$id=(int)$pdo->lastInsertId();if($tables)host_assign_reservation_tables($pdo,$org,$id,$tables);host_reservation_event($pdo,$org,$id,'created',$type==='waitlist'?'Added to waitlist.':'Reservation created.',['partySize'=>$party,'scheduledAt'=>$scheduled,'tables'=>array_column($tables,'public_id')],$userId);return host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$public,false));
    });
}

function host_reservation_status(PDO $pdo,int $org,string $publicId,string $status,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$status,$userId){$r=host_reservation_row($pdo,$org,$publicId,true);$allowed=['booked','confirmed','arrived','waiting','cancelled','no_show'];if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Unsupported reservation status.');if(in_array((string)$r['status'],['seated','completed','cancelled','no_show'],true)&&$status!==(string)$r['status'])throw new InvalidArgumentException('This reservation is already terminal.');$sets=['status=?','updated_by=?','updated_at=NOW(6)'];$args=[$status,$userId];if($status==='arrived')$sets[]='arrived_at=COALESCE(arrived_at,NOW(6))';if($status==='cancelled')$sets[]='cancelled_at=COALESCE(cancelled_at,NOW(6))';if($status==='no_show')$sets[]='no_show_at=COALESCE(no_show_at,NOW(6))';$args[]=$org;$args[]=(int)$r['id'];$pdo->prepare('UPDATE guest_reservations SET '.implode(',',$sets).' WHERE organization_id=? AND id=?')->execute($args);host_reservation_event($pdo,$org,(int)$r['id'],'status_changed','Reservation status changed.',['from'=>$r['status'],'to'=>$status],$userId);return host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$publicId,false));});
}

function host_reservation_assign(PDO $pdo,int $org,string $publicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$tablePublicIds,$userId){$r=host_reservation_row($pdo,$org,$publicId,true);if(in_array((string)$r['status'],['seated','completed','cancelled','no_show'],true))throw new InvalidArgumentException('Tables cannot be changed on a terminal reservation.');$tables=host_validate_assignment($pdo,$org,(int)$r['location_id'],$tablePublicIds,(int)$r['party_size'],$r['scheduled_at'],(int)$r['duration_minutes'],(int)$r['id']);host_assign_reservation_tables($pdo,$org,(int)$r['id'],$tables);host_reservation_event($pdo,$org,(int)$r['id'],'tables_assigned','Tables assigned.',['tables'=>array_column($tables,'public_id')],$userId);return host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$publicId,false));});
}

function host_seat(PDO $pdo,int $org,string $publicId,?int $serverUserId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$serverUserId,$userId){$r=host_reservation_row($pdo,$org,$publicId,true);if(!in_array((string)$r['status'],['booked','confirmed','arrived','waiting'],true))throw new InvalidArgumentException('This reservation cannot be seated from its current status.');$tables=host_reservation_tables($pdo,$org,(int)$r['id']);if(!$tables)throw new InvalidArgumentException('Assign a table before seating this party.');$locked=[];foreach($tables as $t){$row=host_table_row($pdo,$org,(int)$r['location_id'],(string)$t['publicId'],true);if(!host_asset_available($row))throw new InvalidArgumentException((string)$row['name'].' is not physically available.');if($row['active_check_id']!==null)throw new InvalidArgumentException((string)$row['name'].' already has an active check.');$locked[]=$row;}$primary=$locked[0];$check=table_service_seat($pdo,$org,(int)$r['location_id'],(string)$primary['public_id'],(int)$r['party_size'],$serverUserId,(string)($r['notes']??''),$userId);$checkId=(int)$check['id'];$ctx=table_service_context($pdo,$org,$checkId,false);$server=$ctx&&$ctx['server_user_id']!==null?(int)$ctx['server_user_id']:$serverUserId;
        foreach(array_slice($locked,1) as $t){$pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=?,state='seated',seated_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$checkId,$server,$userId,$org,(int)$t['id']]);table_service_event($pdo,$org,(int)$r['location_id'],(int)$t['id'],$checkId,null,'party_seated_combination','Table joined to seated party.',['reservationPublicId'=>$publicId,'primaryTablePublicId'=>$primary['public_id']],$userId);}if($r['customer_id']!==null)$pdo->prepare('UPDATE pos_checks SET customer_id=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([(int)$r['customer_id'],$org,$checkId]);$pdo->prepare("UPDATE guest_reservations SET status='seated',seated_at=NOW(6),seated_check_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$checkId,$userId,$org,(int)$r['id']]);host_reservation_event($pdo,$org,(int)$r['id'],'seated','Party seated into canonical POS check.',['checkPublicId'=>$check['publicId'],'tables'=>array_column($tables,'publicId')],$userId);return ['reservation'=>host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$publicId,false)),'check'=>table_service_detail($pdo,$org,(string)$check['publicId'])];});
}

function host_reconcile(PDO $pdo,int $org,int $locationId,int $userId): int
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$userId){$q=$pdo->prepare("SELECT r.id,r.status,c.status check_status FROM guest_reservations r JOIN pos_checks c ON c.id=r.seated_check_id AND c.organization_id=r.organization_id WHERE r.organization_id=? AND r.location_id=? AND r.status='seated' AND c.status IN ('paid','cancelled') FOR UPDATE");$q->execute([$org,$locationId]);$rows=$q->fetchAll();foreach($rows as $r){$status=$r['check_status']==='paid'?'completed':'cancelled';$field=$status==='completed'?'completed_at':'cancelled_at';$pdo->prepare("UPDATE guest_reservations SET status=?,{$field}=COALESCE({$field},NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$status,$userId,$org,(int)$r['id']]);host_reservation_event($pdo,$org,(int)$r['id'],'pos_reconciled','Reservation reconciled from POS close.',['checkStatus'=>$r['check_status'],'reservationStatus'=>$status],$userId);}return count($rows);});
}

function host_availability(PDO $pdo,int $org,int $locationId,string $startAt,int $partySize,int $duration=90): array
{
    $start=new DateTimeImmutable($startAt);$end=$start->modify('+'.max(15,min(480,$duration)).' minutes');$party=max(1,min(99,$partySize));$available=[];foreach(host_table_rows($pdo,$org,$locationId,true) as $t){if(!$t['physicalReady']||$t['activeCheckId']!==null||(int)$t['capacity']<$party)continue;$q=$pdo->prepare("SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations r ON r.id=rt.reservation_id WHERE rt.service_table_id=? AND r.organization_id=? AND r.status IN ('booked','confirmed','arrived') AND r.scheduled_at < ? AND DATE_ADD(r.scheduled_at,INTERVAL r.duration_minutes MINUTE) > ?");$q->execute([(int)$t['id'],$org,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);if((int)$q->fetchColumn()===0)$available[]=['type'=>'table','publicId'=>$t['publicId'],'name'=>$t['name'],'capacity'=>$t['capacity'],'tables'=>[$t['publicId']]];}
    foreach(host_combinations($pdo,$org,$locationId) as $c){if($c['capacity']<$party)continue;$ok=true;foreach($c['tables'] as $member){$row=host_table_row($pdo,$org,$locationId,(string)$member['publicId'],false);if(!host_asset_available($row)||$row['active_check_id']!==null){$ok=false;break;}$q=$pdo->prepare("SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations r ON r.id=rt.reservation_id WHERE rt.service_table_id=? AND r.organization_id=? AND r.status IN ('booked','confirmed','arrived') AND r.scheduled_at < ? AND DATE_ADD(r.scheduled_at,INTERVAL r.duration_minutes MINUTE) > ?");$q->execute([(int)$member['id'],$org,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);if((int)$q->fetchColumn()>0){$ok=false;break;}}if($ok)$available[]=['type'=>'combination','publicId'=>$c['publicId'],'name'=>$c['name'],'capacity'=>$c['capacity'],'tables'=>array_column($c['tables'],'publicId')];}
    usort($available,static fn(array $a,array $b):int=>($a['capacity']<=>$b['capacity'])?:strcmp($a['name'],$b['name']));return $available;
}

function host_dashboard(PDO $pdo,int $org,int $locationId,string $date,int $userId): array
{
    host_reconcile($pdo,$org,$locationId,$userId);return ['location'=>table_service_location($pdo,$org,$locationId),'floorPlans'=>host_floor_plans($pdo,$org),'sections'=>table_service_sections($pdo,$org,$locationId,true),'staff'=>table_service_staff($pdo,$org,$locationId),'tables'=>host_table_rows($pdo,$org,$locationId,true),'combinations'=>host_combinations($pdo,$org,$locationId),'reservations'=>host_reservations($pdo,$org,$locationId,$date)];
}
