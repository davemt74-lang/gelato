<?php
declare(strict_types=1);

require_once __DIR__.'/pos-floor-plan.php';

/**
 * Runtime service points intentionally stay narrower than the design canvas:
 * - every table is actionable in POS
 * - only chairs explicitly assigned to the bar zone become one-seat bar tabs
 * Dining chairs remain visual furniture around their parent table.
 */
function pos_floor_operations_candidates(array $structures): array
{
    $items=[];
    foreach($structures as $item){
        if(!is_array($item))continue;
        $type=(string)($item['type']??'');
        $zone=(string)($item['seatZone']??'none');
        if($type!=='table'&&!($type==='chair'&&$zone==='bar'))continue;
        $item['servicePointType']=$type==='table'?'table':'bar_seat';
        $item['servicePointPrefix']=$type==='table'?'T':'B';
        $items[]=$item;
    }
    usort($items,static function(array $a,array $b): int {
        $type=strcmp((string)$a['servicePointPrefix'],(string)$b['servicePointPrefix']);
        if($type!==0)return $type;
        return ((float)$a['yPercent']<=> (float)$b['yPercent'])
            ?: ((float)$a['xPercent']<=> (float)$b['xPercent'])
            ?: strcmp((string)$a['id'],(string)$b['id']);
    });
    return $items;
}

function pos_floor_operations_next_code(string $prefix,array &$used): string
{
    for($n=1;$n<=999;$n++){
        $code=$prefix.str_pad((string)$n,2,'0',STR_PAD_LEFT);
        if(empty($used[$code])){$used[$code]=true;return $code;}
    }
    throw new RuntimeException('No available '.$prefix.' service-point IDs remain.');
}

function pos_floor_operations_valid_code(string $name,string $prefix): bool
{
    return preg_match('/^'.preg_quote($prefix,'/').'\d{2,3}$/',trim($name))===1;
}

function pos_floor_operations_sync(PDO $pdo,int $org,int $locationId,int $userId): array
{
    if(!pos_floor_plan_ready($pdo))throw new RuntimeException('POS floor-plan integration migration is not installed. Run upgrade.php.');
    table_service_location($pdo,$org,$locationId);
    $selectedId=pos_floor_plan_selected_id($pdo,$org,$locationId);
    if($selectedId===null)throw new InvalidArgumentException('Choose a floor plan for this POS location before syncing service points.');

    $row=pos_floor_plan_row($pdo,$org,$selectedId);
    $candidates=pos_floor_operations_candidates(pos_floor_plan_structure_items($row));
    if(!$candidates)throw new InvalidArgumentException('The selected floor plan has no table or bar-seat service points to sync.');

    $q=$pdo->prepare("SELECT id,public_id,name,active_check_id,floor_plan_component_id FROM service_tables WHERE organization_id=? AND location_id=? AND status='active'");
    $q->execute([$org,$locationId]);
    $all=$q->fetchAll();
    $existingByComponent=[];$used=[];
    foreach($all as $existing){
        $name=trim((string)$existing['name']);
        if($name!=='')$used[$name]=true;
        if((string)($existing['floor_plan_component_id']??'')!=='')$existingByComponent[(string)$existing['floor_plan_component_id']]=$existing;
    }

    // Existing canonical T## / B## IDs are stable. Release an old noncanonical
    // mapped name so the first enhanced sync can assign its systematic ID.
    foreach($candidates as $item){
        $component=(string)$item['id'];$prefix=(string)$item['servicePointPrefix'];
        $existing=$existingByComponent[$component]??null;
        if(!$existing)continue;
        $name=trim((string)$existing['name']);
        if(!pos_floor_operations_valid_code($name,$prefix))unset($used[$name]);
    }

    $created=0;$updated=0;$componentIds=[];$assignments=[];
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        foreach($candidates as $item){
            $component=(string)$item['id'];$componentIds[$component]=true;
            $prefix=(string)$item['servicePointPrefix'];
            $existing=$existingByComponent[$component]??null;
            $code=$existing&&pos_floor_operations_valid_code((string)$existing['name'],$prefix)
                ? trim((string)$existing['name'])
                : pos_floor_operations_next_code($prefix,$used);
            $used[$code]=true;

            $isBarSeat=(string)$item['servicePointType']==='bar_seat';
            $capacity=$isBarSeat?1:max(1,(int)($item['seats']?:4));
            $ratio=(float)$item['widthPercent']/max(.001,(float)$item['heightPercent']);
            $shape=$isBarSeat?'bar':(($ratio>1.25||$ratio<.8)?'rectangle':'square');
            $w=max(2,min(40,(float)$item['widthPercent']));
            $h=max(2,min(40,(float)$item['heightPercent']));
            $x=max(0,min(100-$w,(float)$item['xPercent']));
            $y=max(0,min(100-$h,(float)$item['yPercent']));

            if($existing){
                $pdo->prepare("UPDATE service_tables SET floor_plan_public_id=?,floor_plan_component_id=?,floor_plan_synced_at=NOW(6),name=?,capacity=?,shape=?,x_percent=?,y_percent=?,width_percent=?,height_percent=?,status='active',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
                    ->execute([$selectedId,$component,$code,$capacity,$shape,$x,$y,$w,$h,$userId,$org,(int)$existing['id']]);
                if($existing['active_check_id']!==null){
                    $pdo->prepare("UPDATE pos_checks SET table_name=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='open'")
                        ->execute([$code,$org,(int)$existing['active_check_id']]);
                }
                $updated++;
            }else{
                $public=table_service_public_id('table');
                $pdo->prepare("INSERT INTO service_tables (organization_id,location_id,section_id,floor_plan_public_id,floor_plan_component_id,floor_plan_synced_at,public_id,name,capacity,shape,x_percent,y_percent,width_percent,height_percent,state,status,created_by,updated_by) VALUES (?,?,NULL,?,?,NOW(6),?,?,?,?,?,?,?,?, 'available','active',?,?)")
                    ->execute([$org,$locationId,$selectedId,$component,$public,$code,$capacity,$shape,$x,$y,$w,$h,$userId,$userId]);
                $created++;
            }
            $assignments[]=['componentId'=>$component,'id'=>$code,'type'=>(string)$item['servicePointType']];
        }

        $q=$pdo->prepare("SELECT floor_plan_component_id FROM service_tables WHERE organization_id=? AND location_id=? AND floor_plan_public_id=? AND status='active'");
        $q->execute([$org,$locationId,$selectedId]);
        $orphaned=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $component)if($component!==null&&!isset($componentIds[(string)$component]))$orphaned++;
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    $bootstrap=pos_floor_plan_bootstrap($pdo,$org,$locationId);
    $bootstrap['servicePointCount']=count($candidates);
    $bootstrap['mappedServicePointCount']=count(array_filter($bootstrap['tables']??[],static fn(array $t):bool=>(string)($t['floorPlanPublicId']??'')===$selectedId&&isset($componentIds[(string)($t['floorPlanComponentId']??'')]));
    $bootstrap['servicePointAssignments']=$assignments;

    return [
        'floorPlanPublicId'=>$selectedId,
        'servicePoints'=>count($candidates),
        'created'=>$created,
        'updated'=>$updated,
        'orphaned'=>$orphaned,
        'assignments'=>$assignments,
        'bootstrap'=>$bootstrap,
    ];
}
