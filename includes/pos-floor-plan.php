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

function pos_floor_plan_lock_publication(PDO $pdo,int $org): void
{
    if(!pos_floor_plan_ready($pdo))return;
    if(!$pdo->inTransaction())throw new LogicException('Floor plan publication requires a transaction.');
    $pdo->prepare('INSERT INTO pos_floor_plan_publication_locks (organization_id) VALUES (?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id)')->execute([$org]);
    $q=$pdo->prepare('SELECT organization_id FROM pos_floor_plan_publication_locks WHERE organization_id=? FOR UPDATE');
    $q->execute([$org]);$q->fetchColumn();
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

function pos_floor_plan_assignment(PDO $pdo,int $org,int $locationId): array
{
    if(!pos_floor_plan_ready($pdo))return ['configured'=>false,'publicId'=>null];
    $q=$pdo->prepare('SELECT id,floor_plan_public_id FROM pos_floor_plan_settings WHERE organization_id=? AND location_id=? LIMIT 1');
    $q->execute([$org,$locationId]);$row=$q->fetch();
    if(!$row)return ['configured'=>false,'publicId'=>null];
    $public=$row['floor_plan_public_id']!==null&&trim((string)$row['floor_plan_public_id'])!==''?(string)$row['floor_plan_public_id']:null;
    return ['configured'=>true,'publicId'=>$public];
}

function pos_floor_plan_setting(PDO $pdo,int $org,int $locationId): ?string
{
    $assignment=pos_floor_plan_assignment($pdo,$org,$locationId);
    return $assignment['configured']?$assignment['publicId']:null;
}

function pos_floor_plan_selected_id(PDO $pdo,int $org,int $locationId): ?string
{
    $row=pos_floor_plan_selected_row($pdo,$org,$locationId);
    return $row===null?null:(string)$row['public_id'];
}

function pos_floor_plan_default_id(PDO $pdo,int $org,?string $includeArchivedId=null): ?string
{
    $q=$pdo->prepare('SELECT public_id FROM floor_plans WHERE organization_id=? AND (archived_at IS NULL OR public_id=?) ORDER BY is_default DESC,id ASC LIMIT 1');
    $q->execute([$org,$includeArchivedId]);$public=$q->fetchColumn();return $public===false?null:(string)$public;
}

function pos_floor_plan_selected_row(PDO $pdo,int $org,int $locationId): ?array
{
    $q=$pdo->prepare("SELECT p.* FROM floor_plans p LEFT JOIN pos_floor_plan_settings f
        ON f.organization_id=p.organization_id AND f.location_id=?
        WHERE p.organization_id=? AND p.archived_at IS NULL AND (f.id IS NULL OR f.floor_plan_public_id=p.public_id)
        ORDER BY p.is_default DESC,p.id ASC LIMIT 1");
    $q->execute([$locationId,$org]);$row=$q->fetch();return $row?:null;
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

function pos_floor_plan_is_bar_chair(array $item): bool
{
    return ($item['type']??null)==='chair'&&mb_strtolower(trim((string)($item['seatZone']??'')),'UTF-8')==='bar'&&(int)($item['seats']??0)>0;
}

function pos_floor_plan_service_points(array $structures): array
{
    $points=[];$hasExplicitBarSeats=false;
    foreach($structures as $item){
        if(($item['type']??null)==='table'){$item['servicePointKind']='table';$points[]=$item;continue;}
        if(pos_floor_plan_is_bar_chair($item)){$item['servicePointKind']='bar_seat';$item['synthetic']=false;$points[]=$item;$hasExplicitBarSeats=true;}
    }
    if($hasExplicitBarSeats)return $points;

    $globalSeat=0;
    foreach($structures as $bar){
        if(($bar['type']??null)!=='bar'||mb_strtolower(trim((string)($bar['seatZone']??'')),'UTF-8')!=='bar')continue;
        $seatCount=max(0,min(99,(int)($bar['seats']??0)));if($seatCount===0)continue;
        $horizontal=(float)$bar['widthPercent']>=(float)$bar['heightPercent'];
        $seatW=$horizontal?max(1.6,min(4.0,(float)$bar['heightPercent']*.45)):max(1.6,min(4.0,(float)$bar['widthPercent']*.85));
        $seatH=$horizontal?max(2.4,min(5.5,(float)$bar['heightPercent']*.70)):max(2.4,min(5.5,(float)$bar['widthPercent']*.55));
        $barLabel=trim((string)($bar['label']??''));$generic=$barLabel===''||mb_strtoupper($barLabel,'UTF-8')==='BAR';
        for($index=0;$index<$seatCount;$index++){
            $globalSeat++;$fraction=($index+.5)/$seatCount;
            if($horizontal){
                $x=(float)$bar['xPercent']+$fraction*(float)$bar['widthPercent']-$seatW/2;
                $y=(float)$bar['yPercent']+(float)$bar['heightPercent']+.55;
            }else{
                $x=(float)$bar['xPercent']+(float)$bar['widthPercent']+.55;
                $y=(float)$bar['yPercent']+$fraction*(float)$bar['heightPercent']-$seatH/2;
            }
            $componentBase=mb_substr((string)$bar['id'],0,104,'UTF-8');
            $points[]=[
                'id'=>$componentBase.':seat:'.($index+1),'type'=>'chair','label'=>$generic?'Bar Seat '.$globalSeat:mb_substr($barLabel.' Seat '.($index+1),0,120,'UTF-8'),
                'seats'=>1,'seatZone'=>'bar','rotation'=>(float)($bar['rotation']??0),'xPercent'=>round(max(0,min(100-$seatW,$x)),3),'yPercent'=>round(max(0,min(100-$seatH,$y)),3),
                'widthPercent'=>round($seatW,3),'heightPercent'=>round($seatH,3),'servicePointKind'=>'bar_seat','synthetic'=>true,'parentComponentId'=>(string)$bar['id'],
            ];
        }
    }
    return $points;
}

function pos_floor_plan_current_points(PDO $pdo,int $org,int $locationId): array
{
    $row=pos_floor_plan_selected_row($pdo,$org,$locationId);
    $points=$row===null?[]:pos_floor_plan_service_points(pos_floor_plan_structure_items($row));
    return ['plan'=>$row===null?null:(string)$row['public_id'],'points'=>array_fill_keys(array_column($points,'id'),true)];
}

function pos_floor_plan_table_is_retired(PDO $pdo,int $org,int $locationId,array $table,?array $mapping=null): bool
{
    if(($table['status']??null)==='retired')return true;
    if(($table['floor_plan_public_id']??null)===null)return false;
    $mapping??=pos_floor_plan_current_points($pdo,$org,$locationId);
    return $table['floor_plan_public_id']!==$mapping['plan']
        || !isset($mapping['points'][(string)($table['floor_plan_component_id']??'')]);
}

function pos_floor_plan_assert_table_current(PDO $pdo,int $org,int $locationId,array $table): void
{
    if(pos_floor_plan_table_is_retired($pdo,$org,$locationId,$table))
        throw new InvalidArgumentException('This service point is retired from the current floor plan. Choose an available table.');
}

function pos_floor_plan_tables(PDO $pdo,int $org,int $locationId,?array $mapping=null): array
{
    if(!pos_floor_plan_ready($pdo))return [];
    $mapping??=pos_floor_plan_current_points($pdo,$org,$locationId);
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.capacity,t.shape,t.x_percent,t.y_percent,t.width_percent,t.height_percent,t.state,t.status,t.floor_plan_public_id,t.floor_plan_component_id,t.floor_plan_synced_at,t.active_check_id,t.seated_at,s.name section_name,u.display_name assigned_user_name,c.public_id check_public_id,c.check_number,c.guest_count,c.total_amount
        FROM service_tables t LEFT JOIN service_sections s ON s.id=t.section_id AND s.organization_id=t.organization_id LEFT JOIN users u ON u.id=t.assigned_user_id
        LEFT JOIN pos_checks c ON c.organization_id=t.organization_id AND c.location_id=t.location_id AND c.id=COALESCE(
            (SELECT direct.id FROM pos_checks direct WHERE direct.id=t.active_check_id AND direct.organization_id=t.organization_id AND direct.status='open'),
            (SELECT cx.check_id FROM service_check_contexts cx JOIN pos_checks open_check ON open_check.id=cx.check_id AND open_check.organization_id=cx.organization_id AND open_check.status='open'
                WHERE cx.organization_id=t.organization_id AND cx.location_id=t.location_id AND cx.table_id=t.id AND cx.status='active' ORDER BY cx.check_id LIMIT 1))
        WHERE t.organization_id=? AND t.location_id=? AND (t.status='active' OR (t.floor_plan_public_id IS NOT NULL AND c.id IS NOT NULL)) ORDER BY COALESCE(s.sort_order,9999),s.name,t.name,t.id");
    $q->execute([$org,$locationId]);
    $tables=[];
    foreach($q->fetchAll() as $r){
        $retired=pos_floor_plan_table_is_retired($pdo,$org,$locationId,$r,$mapping);
        if(($retired||$r['status']!=='active')&&$r['check_public_id']===null)continue;
        $tables[]=['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'capacity'=>(int)$r['capacity'],'shape'=>(string)$r['shape'],'xPercent'=>(float)$r['x_percent'],'yPercent'=>(float)$r['y_percent'],'widthPercent'=>(float)$r['width_percent'],'heightPercent'=>(float)$r['height_percent'],'state'=>(string)$r['state'],'status'=>$retired?'retired':(string)$r['status'],'retired'=>$retired,'seatAllowed'=>!$retired&&$r['status']==='active'&&$r['active_check_id']===null&&$r['check_public_id']===null&&$r['state']==='available','sectionName'=>$r['section_name'],'assignedUserName'=>$r['assigned_user_name'],'checkPublicId'=>$r['check_public_id'],'checkNumber'=>$r['check_number'],'guestCount'=>$r['guest_count']!==null?(int)$r['guest_count']:null,'totalAmount'=>$r['total_amount']!==null?(float)$r['total_amount']:null,'floorPlanPublicId'=>$r['floor_plan_public_id'],'floorPlanComponentId'=>$r['floor_plan_component_id'],'floorPlanSyncedAt'=>$r['floor_plan_synced_at'],'seatedAt'=>$r['seated_at']];
    }
    return $tables;
}

function pos_floor_plan_bootstrap(PDO $pdo,int $org,int $locationId): array
{
    if(!pos_floor_plan_ready($pdo))return ['ready'=>false,'configured'=>false,'mode'=>'unavailable','plans'=>[],'selectedPlan'=>null,'structures'=>[],'tables'=>[],'componentTableCount'=>0,'componentServicePointCount'=>0,'mappedCount'=>0,'unmappedCount'=>0,'selectedBy'=>'unavailable'];
    table_service_location($pdo,$org,$locationId);$plans=pos_floor_plan_plans($pdo,$org);$assignment=pos_floor_plan_assignment($pdo,$org,$locationId);$row=pos_floor_plan_selected_row($pdo,$org,$locationId);$selectedId=null;$selected=null;$structures=[];$servicePoints=[];
    if($row!==null){$selectedId=(string)$row['public_id'];$selected=['publicId'=>$selectedId,'name'=>(string)$row['name'],'widthFt'=>(float)$row['width_ft'],'depthFt'=>(float)$row['depth_ft'],'scale'=>(float)$row['scale_px_per_ft'],'version'=>(int)$row['version'],'isDefault'=>(bool)$row['is_default']];$structures=pos_floor_plan_structure_items($row);$servicePoints=pos_floor_plan_service_points($structures);}
    $componentTableCount=count(array_filter($structures,static fn(array $i):bool=>$i['type']==='table'));$servicePointIds=array_fill_keys(array_column($servicePoints,'id'),true);
    $tables=pos_floor_plan_tables($pdo,$org,$locationId,['plan'=>$selectedId,'points'=>$servicePointIds]);$mapped=0;$mappedComponentIds=[];
    foreach($tables as $t){if($t['retired']||$t['status']!=='active'||$selectedId===null||$t['floorPlanPublicId']!==$selectedId||$t['floorPlanComponentId']===null)continue;$component=(string)$t['floorPlanComponentId'];$mappedComponentIds[$component]=true;if(isset($servicePointIds[$component]))$mapped++;}
    if($mappedComponentIds)$structures=array_values(array_filter($structures,static fn(array $i):bool=>!(pos_floor_plan_is_bar_chair($i)&&isset($mappedComponentIds[(string)$i['id']]))));
    $configured=$selected!==null&&$mapped>0;$mode=$selected!==null?'floor_plan':($tables?'service_map':'unconfigured');$selectedBy=$assignment['configured']?($assignment['publicId']===null?'none':'location_setting'):'default';
    return ['ready'=>true,'configured'=>$configured,'mode'=>$mode,'plans'=>$plans,'selectedPlan'=>$selected,'structures'=>$structures,'tables'=>$tables,'componentTableCount'=>$componentTableCount,'componentServicePointCount'=>count($servicePoints),'mappedCount'=>$mapped,'unmappedCount'=>max(0,count($servicePoints)-$mapped),'selectedBy'=>$selectedBy];
}

function pos_floor_plan_select(PDO $pdo,int $org,int $locationId,?string $publicId,int $userId): void
{
    if(!pos_floor_plan_ready($pdo))throw new RuntimeException('POS floor-plan integration migration is not installed. Run upgrade.php.');
    table_service_transaction($pdo,function()use($pdo,$org,$locationId,$publicId,$userId):void{
        pos_floor_plan_lock_publication($pdo,$org);
        table_service_location($pdo,$org,$locationId);$publicId=trim((string)$publicId);
        if($publicId!=='')pos_floor_plan_row($pdo,$org,$publicId);
        $pdo->prepare('INSERT INTO pos_floor_plan_settings (organization_id,location_id,floor_plan_public_id,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE floor_plan_public_id=VALUES(floor_plan_public_id),updated_by=VALUES(updated_by),updated_at=NOW(6)')->execute([$org,$locationId,$publicId!==''?$publicId:null,$userId]);
        pos_floor_plan_retire_unmapped($pdo,$org,$locationId,$userId);
    });
}

function pos_floor_plan_retire_unmapped(PDO $pdo,int $org,int $locationId,int $userId,?array $mapping=null): array
{
    $mapping??=pos_floor_plan_current_points($pdo,$org,$locationId);
    $q=$pdo->prepare("SELECT id,floor_plan_public_id,floor_plan_component_id,status FROM service_tables
        WHERE organization_id=? AND location_id=? AND floor_plan_public_id IS NOT NULL AND status<>'retired' ORDER BY id");
    $q->execute([$org,$locationId]);$ids=[];$orphaned=0;
    foreach($q->fetchAll() as $table){
        if(!pos_floor_plan_table_is_retired($pdo,$org,$locationId,$table,$mapping))continue;
        $ids[]=(int)$table['id'];
        if($table['floor_plan_public_id']===$mapping['plan'])$orphaned++;
    }
    $retired=0;
    foreach(array_chunk($ids,500) as $batch){
        $ph=implode(',',array_fill(0,count($batch),'?'));
        $q=$pdo->prepare("UPDATE service_tables SET status='retired',updated_by=?,updated_at=NOW(6)
            WHERE organization_id=? AND location_id=? AND id IN ($ph) AND status<>'retired'");
        $q->execute(array_merge([$userId,$org,$locationId],$batch));$retired+=$q->rowCount();
    }
    return ['orphaned'=>$orphaned,'retired'=>$retired];
}

function pos_floor_plan_after_save(PDO $pdo,int $org,string $publicId,int $userId): void
{
    if(!pos_floor_plan_ready($pdo))return;
    $q=$pdo->prepare("SELECT DISTINCT location_id FROM service_tables
        WHERE organization_id=? AND floor_plan_public_id=? AND status<>'retired' ORDER BY location_id");
    $q->execute([$org,$publicId]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $locationId){
        $mapping=pos_floor_plan_current_points($pdo,$org,(int)$locationId);
        if($mapping['plan']===$publicId)pos_floor_plan_retire_unmapped($pdo,$org,(int)$locationId,$userId,$mapping);
    }
}

function pos_floor_plan_after_archive(PDO $pdo,int $org,string $publicId,int $userId): void
{
    if(!pos_floor_plan_ready($pdo))return;
    $wasDefault=pos_floor_plan_default_id($pdo,$org,$publicId)===$publicId;
    $q=$pdo->prepare("SELECT DISTINCT t.location_id FROM service_tables t LEFT JOIN pos_floor_plan_settings f
        ON f.organization_id=t.organization_id AND f.location_id=t.location_id
        WHERE t.organization_id=? AND t.floor_plan_public_id IS NOT NULL AND t.status<>'retired'
          AND (f.floor_plan_public_id=? OR (f.id IS NULL AND ?=1)) ORDER BY t.location_id");
    $q->execute([$org,$publicId,(int)$wasDefault]);$locations=$q->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('UPDATE pos_floor_plan_settings SET floor_plan_public_id=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND floor_plan_public_id=?')->execute([$userId,$org,$publicId]);
    foreach($locations as $locationId)pos_floor_plan_retire_unmapped($pdo,$org,(int)$locationId,$userId);
}

function pos_floor_plan_unique_table_name(PDO $pdo,int $org,int $locationId,string $base,?int $excludeId=null): string
{
    $base=mb_substr(trim($base),0,70,'UTF-8');if($base==='')$base='Table';$candidate=$base;$n=2;
    while(true){$sql='SELECT id FROM service_tables WHERE organization_id=? AND location_id=? AND name=?';$args=[$org,$locationId,$candidate];if($excludeId!==null){$sql.=' AND id<>?';$args[]=$excludeId;}$sql.=' LIMIT 1';$q=$pdo->prepare($sql);$q->execute($args);if(!$q->fetchColumn())return $candidate;$suffix=' '.$n++;$candidate=mb_substr($base,0,max(1,80-mb_strlen($suffix,'UTF-8')),'UTF-8').$suffix;if($n>999)throw new RuntimeException('Could not generate a unique table name.');}
}

function pos_floor_plan_sync_tables(PDO $pdo,int $org,int $locationId,int $userId): array
{
    if(!pos_floor_plan_ready($pdo))throw new RuntimeException('POS floor-plan integration migration is not installed. Run upgrade.php.');
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$userId):array{
        pos_floor_plan_lock_publication($pdo,$org);
        table_service_location($pdo,$org,$locationId);
        $row=pos_floor_plan_selected_row($pdo,$org,$locationId);
        if($row===null)throw new InvalidArgumentException('Choose or create a floor plan before syncing tables.');
        $selectedId=(string)$row['public_id'];$structures=pos_floor_plan_service_points(pos_floor_plan_structure_items($row));
        $componentIds=array_fill_keys(array_column($structures,'id'),true);
        $created=0;$updated=0;$tableNumber=0;$barSeatNumber=0;

        // POS changes lock a check before its service table. Take known open
        // check locks in the same order before touching table labels.
        $q=$pdo->prepare("SELECT c.id FROM pos_checks c JOIN service_tables t ON t.active_check_id=c.id AND t.organization_id=c.organization_id AND t.location_id=c.location_id
            WHERE c.organization_id=? AND c.location_id=? AND t.floor_plan_public_id=? ORDER BY c.id");
        $q->execute([$org,$locationId,$selectedId]);$checkIds=$q->fetchAll(PDO::FETCH_COLUMN);
        $lockedChecks=array_fill_keys($checkIds,true);
        $checkLock=$pdo->prepare('SELECT id FROM pos_checks WHERE id=? AND organization_id=? FOR UPDATE');
        foreach($checkIds as $checkId){$checkLock->execute([(int)$checkId,$org]);$checkLock->fetchColumn();}

        foreach($structures as $item){
            $component=(string)$item['id'];$q=$pdo->prepare('SELECT id,name,active_check_id FROM service_tables WHERE organization_id=? AND location_id=? AND floor_plan_public_id=? AND floor_plan_component_id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$locationId,$selectedId,$component]);$existing=$q->fetch();$id=$existing?(int)$existing['id']:null;$label=trim((string)$item['label']);$isBarSeat=($item['servicePointKind']??'')==='bar_seat';
            if($isBarSeat){$barSeatNumber++;if($label===''||mb_strtoupper($label,'UTF-8')==='CHAIR')$label='Bar Seat '.$barSeatNumber;$capacity=max(1,(int)($item['seats']?:1));$shape='round';}
            else{$tableNumber++;if($label===''||mb_strtoupper($label,'UTF-8')==='4-TOP')$label='Table '.$tableNumber;$capacity=max(1,(int)($item['seats']?:4));$ratio=(float)$item['widthPercent']/max(.001,(float)$item['heightPercent']);$shape=$ratio>1.25||$ratio<.8?'rectangle':'square';}
            $name=pos_floor_plan_unique_table_name($pdo,$org,$locationId,$label,$id);$w=max($isBarSeat?1.5:2,min($isBarSeat?8:40,(float)$item['widthPercent']));$h=max($isBarSeat?2:2,min($isBarSeat?8:40,(float)$item['heightPercent']));$x=max(0,min(100-$w,(float)$item['xPercent']));$y=max(0,min(100-$h,(float)$item['yPercent']));
            if($existing){
                $renameCheck=$existing['active_check_id']!==null&&$existing['name']!==$name;
                if($renameCheck&&!isset($lockedChecks[$existing['active_check_id']]))
                    throw new InvalidArgumentException('A table was seated while syncing the floor plan. Retry the sync.');
                $pdo->prepare("UPDATE service_tables SET name=?,capacity=?,shape=?,x_percent=?,y_percent=?,width_percent=?,height_percent=?,status='active',floor_plan_synced_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$name,$capacity,$shape,$x,$y,$w,$h,$userId,$org,$id]);
                if($renameCheck)$pdo->prepare("UPDATE pos_checks SET table_name=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND location_id=? AND id=? AND status='open'")->execute([$name,$org,$locationId,(int)$existing['active_check_id']]);
                $updated++;
            }else{
                $public=table_service_public_id('table');$pdo->prepare("INSERT INTO service_tables (organization_id,location_id,section_id,floor_plan_public_id,floor_plan_component_id,floor_plan_synced_at,public_id,name,capacity,shape,x_percent,y_percent,width_percent,height_percent,state,status,created_by,updated_by) VALUES (?,?,NULL,?,?,NOW(6),?,?,?,?,?,?,?,?, 'available','active',?,?)")->execute([$org,$locationId,$selectedId,$component,$public,$name,$capacity,$shape,$x,$y,$w,$h,$userId,$userId]);$created++;
            }
        }
        $retirement=pos_floor_plan_retire_unmapped($pdo,$org,$locationId,$userId,['plan'=>$selectedId,'points'=>$componentIds]);
        return ['floorPlanPublicId'=>$selectedId,'componentTables'=>count($structures),'componentServicePoints'=>count($structures),'created'=>$created,'updated'=>$updated]+$retirement+['bootstrap'=>pos_floor_plan_bootstrap($pdo,$org,$locationId)];
    });
}
