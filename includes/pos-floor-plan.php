<?php
declare(strict_types=1);

require_once __DIR__.'/table-service-core.php';

function pos_floor_plan_ready(PDO $pdo): bool
{
    try {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('floor_plans','pos_floor_plan_settings','service_tables')");
        if((int)$q->fetchColumn()!==3)return false;
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='service_tables' AND column_name IN ('floor_plan_public_id','floor_plan_component_id','floor_plan_synced_at')");
        return (int)$q->fetchColumn()===3 && table_service_ready($pdo);
    } catch(Throwable){return false;}
}

function pos_floor_plan_plans(PDO $pdo,int $org): array
{
    if(!pos_floor_plan_ready($pdo))return [];
    $q=$pdo->prepare("SELECT public_id,name,width_ft,depth_ft,scale_px_per_ft,version,is_default,updated_at FROM floor_plans WHERE organization_id=? AND archived_at IS NULL ORDER BY is_default DESC,updated_at DESC,id DESC");
    $q->execute([$org]);
    return array_map(static fn(array $r):array=>[
        'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'widthFt'=>(float)$r['width_ft'],'depthFt'=>(float)$r['depth_ft'],
        'scale'=>(float)$r['scale_px_per_ft'],'version'=>(int)$r['version'],'isDefault'=>(bool)$r['is_default'],'updatedAt'=>(string)$r['updated_at'],
    ],$q->fetchAll());
}

function pos_floor_plan_setting(PDO $pdo,int $org,int $locationId): ?string
{
    if(!pos_floor_plan_ready($pdo))return null;
    $q=$pdo->prepare('SELECT floor_plan_public_id FROM pos_floor_plan_settings WHERE organization_id=? AND location_id=? LIMIT 1');
    $q->execute([$org,$locationId]);$v=$q->fetchColumn();return is_string($v)&&trim($v)!==''?(string)$v:null;
}

function pos_floor_plan_selected_id(PDO $pdo,int $org,int $locationId): ?string
{
    $selected=pos_floor_plan_setting($pdo,$org,$locationId);
    if($selected!==null){$q=$pdo->prepare('SELECT public_id FROM floor_plans WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$q->execute([$org,$selected]);if($q->fetchColumn())return $selected;}
    $q=$pdo->prepare("SELECT public_id FROM floor_plans WHERE organization_id=? AND archived_at IS NULL ORDER BY is_default DESC,updated_at DESC,id DESC LIMIT 1");$q->execute([$org]);$v=$q->fetchColumn();return $v!==false?(string)$v:null;
}

function pos_floor_plan_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare('SELECT * FROM floor_plans WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Floor plan was not found.');return $row;
}

function pos_floor_plan_structure_items(array $row): array
{
    $data=json_decode((string)$row['plan_json'],true);if(!is_array($data))$data=[];$items=$data['items']??[];if(!is_array($items))$items=[];
    $planW=max(1.0,(float)($data['planWft']??$row['width_ft']));$planH=max(1.0,(float)($data['planHft']??$row['depth_ft']));$scale=max(1.0,(float)($data['scale']??$row['scale_px_per_ft']));
    $widthPx=$planW*$scale;$heightPx=$planH*$scale;$allowed=['table','chair','bar','host','zone','aisle','wall','door'];$out=[];
    foreach($items as $item){if(!is_array($item))continue;$type=(string)($item['type']??'');if(!in_array($type,$allowed,true))continue;$id=trim((string)($item['id']??''));if($id==='')continue;
        $x=(float)($item['xPx']??$item['x']??0);$y=(float)($item['yPx']??$item['y']??0);$w=max(1.0,(float)($item['wPx']??$item['w']??($scale*3)));$h=max(1.0,(float)($item['hPx']??$item['h']??($scale*3)));
        $out[]=['id'=>$id,'type'=>$type,'label'=>mb_substr((string)($item['label']??''),0,120,'UTF-8'),'seats'=>max(0,min(99,(int)($item['seats']??0))),'seatZone'=>mb_substr((string)($item['seatZone']??$item['zone']??'none'),0,40,'UTF-8'),'rotation'=>(float)($item['r']??$item['rot']??0),
            'xPercent'=>round(max(0,min(100,$x/$widthPx*100)),3),'yPercent'=>round(max(0,min(100,$y/$heightPx*100)),3),'widthPercent'=>round(max(.25,min(100,$w/$widthPx*100)),3),'heightPercent'=>round(max(.25,min(100,$h/$heightPx*100)),3)];
    }
    return $out;
}

function pos_floor_plan_tables(PDO $pdo,int $org,int $locationId): array
{
    if(!pos_floor_plan_ready($pdo))return [];
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.capacity,t.shape,t.x_percent,t.y_percent,t.width_percent,t.height_percent,t.state,t.status,t.floor_plan_public_id,t.floor_plan_component_id,t.floor_plan_synced_at,t.seated_at,s.name section_name,u.display_name assigned_user_name,c.public_id check_public_id,c.check_number,c.guest_count,c.total_amount
        FROM service_tables t LEFT JOIN service_sections s ON s.id=t.section_id AND s.organization_id=t.organization_id LEFT JOIN users u ON u.id=t.assigned_user_id LEFT JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id
        WHERE t.organization_id=? AND t.location_id=? AND t.status='active' ORDER BY COALESCE(s.sort_order,9999),s.name,t.name,t.id");
    $q->execute([$org,$locationId]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'shape'=>(string)$r['shape'],'xPercent'=>(float)$r['x_percent'],'yPercent'=>(float)$r['y_percent'],'widthPercent'=>(float)$r['width_percent'],'heightPercent'=>(float)$r['height_percent'],'state'=>(string)$r['state'],'sectionName'=>$r['section_name'],'assignedUserName'=>$r['assigned_user_name'],'checkPublicId'=>$r['check_public_id'],'checkNumber'=>$r['check_number'],'guestCount'=>$r['guest_count']!==null?(int)$r['guest_count']:null,'totalAmount'=>$r['total_amount']!==null?(float)$r['total_amount']:null,'floorPlanPublicId'=>$r['floor_plan_public_id'],'floorPlanComponentId'=>$r['floor_plan_component_id'],'floorPlanSyncedAt'=>$r['floor_plan_synced_at'],'seatedAt'=>$r['seated_at']],$q->fetchAll());
}

function pos_floor_plan_bootstrap(PDO $pdo,int $org,int $locationId): array
{
    if(!pos_floor_plan_ready($pdo))return ['ready'=>false,'configured'=>false,'mode'=>'unavailable','plans'=>[],'selectedPlan'=>null,'structures'=>[],'tables'=>[],'componentTableCount'=>0,'mappedCount'=>0,'unmappedCount'=>0];
    table_service_location($pdo,$org,$locationId);$plans=pos_floor_plan_plans($pdo,$org);$selectedId=pos_floor_plan_selected_id($pdo,$org,$locationId);$selected=null;$structures=[];
    if($selectedId!==null){$row=pos_floor_plan_row($pdo,$org,$selectedId);$selected=['publicId'=>(string)$row['public_id'],'name'=>(string)$row['name'],'widthFt'=>(float)$row['width_ft'],'depthFt'=>(float)$row['depth_ft'],'scale'=>(float)$row['scale_px_per_ft'],'version'=>(int)$row['version'],'isDefault'=>(bool)$row['is_default']];$structures=pos_floor_plan_structure_items($row);}
    $tables=pos_floor_plan_tables($pdo,$org,$locationId);$componentTableCount=count(array_filter($structures,static fn(array $i):bool=>$i['type']==='table'));$mapped=0;foreach($tables as $t)if($selectedId!==null&&$t['floorPlanPublicId']===$selectedId&&$t['floorPlanComponentId']!==null)$mapped++;
    $configured=$selected!==null&&$mapped>0;$mode=$selected!==null?'floor_plan':($tables?'service_map':'unconfigured');
    return ['ready'=>true,'configured'=>$configured,'mode'=>$mode,'plans'=>$plans,'selectedPlan'=>$selected,'structures'=>$structures,'tables'=>$tables,'componentTableCount'=>$componentTableCount,'mappedCount'=>$mapped,'unmappedCount'=>max(0,count($tables)-$mapped),'selectedBy'=>pos_floor_plan_setting($pdo,$org,$locationId)!==null?'location_setting':'default'];
}

function pos_floor_plan_select(PDO $pdo,int $org,int $locationId,?string $publicId,int $userId): void
{
    if(!pos_floor_plan_ready($pdo))throw new RuntimeException('POS floor-plan integration migration is not installed. Run upgrade.php.');table_service_location($pdo,$org,$locationId);$publicId=trim((string)$publicId);
    if($publicId!=='')pos_floor_plan_row($pdo,$org,$publicId);
    $pdo->prepare('INSERT INTO pos_floor_plan_settings (organization_id,location_id,floor_plan_public_id,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE floor_plan_public_id=VALUES(floor_plan_public_id),updated_by=VALUES(updated_by),updated_at=NOW(6)')->execute([$org,$locationId,$publicId!==''?$publicId:null,$userId]);
}

function pos_floor_plan_unique_table_name(PDO $pdo,int $org,int $locationId,string $base,?int $excludeId=null): string
{
    $base=mb_substr(trim($base),0,70,'UTF-8');if($base==='')$base='Table';$candidate=$base;$n=2;
    while(true){$sql='SELECT id FROM service_tables WHERE organization_id=? AND location_id=? AND name=?';$args=[$org,$locationId,$candidate];if($excludeId!==null){$sql.=' AND id<>?';$args[]=$excludeId;}$sql.=' LIMIT 1';$q=$pdo->prepare($sql);$q->execute($args);if(!$q->fetchColumn())return $candidate;$suffix=' '.$n++;$candidate=mb_substr($base,0,max(1,80-mb_strlen($suffix,'UTF-8')),'UTF-8').$suffix;if($n>999)throw new RuntimeException('Could not generate a unique table name.');}
}

function pos_floor_plan_sync_tables(PDO $pdo,int $org,int $locationId,int $userId): array
{
    if(!pos_floor_plan_ready($pdo))throw new RuntimeException('POS floor-plan integration migration is not installed. Run upgrade.php.');$selectedId=pos_floor_plan_selected_id($pdo,$org,$locationId);if($selectedId===null)throw new InvalidArgumentException('Choose or create a floor plan before syncing tables.');$row=pos_floor_plan_row($pdo,$org,$selectedId);$structures=array_values(array_filter(pos_floor_plan_structure_items($row),static fn(array $i):bool=>$i['type']==='table'));
    $created=0;$updated=0;$componentIds=[];$pdo->beginTransaction();
    try{
        foreach($structures as $index=>$item){$component=(string)$item['id'];$componentIds[$component]=true;$q=$pdo->prepare('SELECT id,public_id FROM service_tables WHERE organization_id=? AND location_id=? AND floor_plan_public_id=? AND floor_plan_component_id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$locationId,$selectedId,$component]);$existing=$q->fetch();$id=$existing?(int)$existing['id']:null;$label=trim((string)$item['label']);if($label===''||strtoupper($label)==='4-TOP')$label='Table '.($index+1);$name=pos_floor_plan_unique_table_name($pdo,$org,$locationId,$label,$id);$capacity=max(1,(int)($item['seats']?:4));$ratio=(float)$item['widthPercent']/max(.001,(float)$item['heightPercent']);$shape=$ratio>1.25||$ratio<.8?'rectangle':'square';$x=max(0,min(97,(float)$item['xPercent']));$y=max(0,min(97,(float)$item['yPercent']));$w=max(2,min(40,(float)$item['widthPercent']));$h=max(2,min(40,(float)$item['heightPercent']));
            if($existing){$pdo->prepare('UPDATE service_tables SET name=?,capacity=?,shape=?,x_percent=?,y_percent=?,width_percent=?,height_percent=?,status=\'active\',floor_plan_synced_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$name,$capacity,$shape,$x,$y,$w,$h,$userId,$org,$id]);$updated++;}
            else{$public=table_service_public_id('table');$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,section_id,floor_plan_public_id,floor_plan_component_id,floor_plan_synced_at,public_id,name,capacity,shape,x_percent,y_percent,width_percent,height_percent,state,status,created_by,updated_by) VALUES (?,?,NULL,?,?,NOW(6),?,?,?,?,?,?,?,?, 'available','active',?,?)")->execute([$org,$locationId,$selectedId,$component,$public,$name,$capacity,$shape,$x,$y,$w,$h,$userId,$userId]);$created++;}
        }
        $q=$pdo->prepare('SELECT floor_plan_component_id FROM service_tables WHERE organization_id=? AND location_id=? AND floor_plan_public_id=? AND status=\'active\'');$q->execute([$org,$locationId,$selectedId]);$orphaned=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $component)if($component!==null&&!isset($componentIds[(string)$component]))$orphaned++;
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['floorPlanPublicId'=>$selectedId,'componentTables'=>count($structures),'created'=>$created,'updated'=>$updated,'orphaned'=>$orphaned,'bootstrap'=>pos_floor_plan_bootstrap($pdo,$org,$locationId)];
}
