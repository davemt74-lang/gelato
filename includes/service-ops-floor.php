<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-hardening.php';

function service_ops_plan(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare('SELECT public_id,name,width_ft,depth_ft FROM floor_plans WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $q->execute([$org,$publicId]);$plan=$q->fetch();
    if(!$plan)throw new InvalidArgumentException('Active floor plan was not found.');
    return ['publicId'=>(string)$plan['public_id'],'name'=>(string)$plan['name'],'widthFt'=>(float)$plan['width_ft'],'depthFt'=>(float)$plan['depth_ft']];
}

function service_ops_footprint(float $widthInches,float $depthInches,float $rotationDeg): array
{
    $theta=deg2rad(fmod(($rotationDeg+360.0),360.0));
    $w=max(1.0,$widthInches)/12.0;$d=max(1.0,$depthInches)/12.0;
    return [
        'halfX'=>(abs(cos($theta))*$w+abs(sin($theta))*$d)/2.0,
        'halfY'=>(abs(sin($theta))*$w+abs(cos($theta))*$d)/2.0,
    ];
}

function service_ops_place_table(PDO $pdo,int $org,int $locationId,string $tablePublicId,array $input,int $userId): array
{
    if(!empty($input['unplace']))return host_place_table($pdo,$org,$locationId,$tablePublicId,$input,$userId);
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$input,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);
        if($table['equipment_asset_id']===null){host_sync_table_asset($pdo,$org,$locationId,$tablePublicId,$userId);$table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);}
        $plan=service_ops_plan($pdo,$org,trim((string)($input['floorPlanId']??'')));
        $q=$pdo->prepare('SELECT width_inches,depth_inches FROM equipment_assets WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,(int)$table['equipment_asset_id']]);$asset=$q->fetch()?:[];
        $rotation=(float)($input['rotationDeg']??0);$foot=service_ops_footprint((float)($asset['width_inches']??36),(float)($asset['depth_inches']??36),$rotation);
        $x=round((float)($input['xFt']??0),3);$y=round((float)($input['yFt']??0),3);
        if($x-$foot['halfX']<0||$y-$foot['halfY']<0||$x+$foot['halfX']>$plan['widthFt']||$y+$foot['halfY']>$plan['depthFt'])throw new InvalidArgumentException('The full table footprint must fit inside the floor plan.');
        return host_place_table($pdo,$org,$locationId,$tablePublicId,$input,$userId);
    });
}

function service_ops_sync_table_asset(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$userId){
        $legacy=service_ops_table_row($pdo,$org,$locationId,$tablePublicId,true);
        $row=host_sync_table_asset($pdo,$org,$locationId,$tablePublicId,$userId);
        if(!empty($row['asset']['floorPlanId']))return $row;
        $plans=host_floor_plans($pdo,$org);if(!$plans)return $row;$plan=$plans[0];
        $width=(float)($row['asset']['widthInches']??36);$depth=(float)($row['asset']['depthInches']??36);$foot=service_ops_footprint($width,$depth,0);
        $x=((float)$legacy['x_percent']/100.0)*(float)$plan['widthFt'];$y=((float)$legacy['y_percent']/100.0)*(float)$plan['depthFt'];
        $x=max($foot['halfX'],min((float)$plan['widthFt']-$foot['halfX'],$x));$y=max($foot['halfY'],min((float)$plan['depthFt']-$foot['halfY'],$y));
        return service_ops_place_table($pdo,$org,$locationId,$tablePublicId,['floorPlanId'=>$plan['publicId'],'xFt'=>$x,'yFt'=>$y,'rotationDeg'=>0],$userId);
    });
}

function service_ops_sync_all_table_assets(PDO $pdo,int $org,int $locationId,int $userId): int
{
    $q=$pdo->prepare("SELECT public_id FROM service_tables WHERE organization_id=? AND location_id=? AND status='active' AND equipment_asset_id IS NULL ORDER BY id");$q->execute([$org,$locationId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach($ids as $id)service_ops_sync_table_asset($pdo,$org,$locationId,(string)$id,$userId);
    return count($ids);
}

function service_ops_managed_table_create_safe(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){
        $floor=trim((string)($input['floorPlanId']??''));$tag=trim((string)($input['assetTag']??''));
        if($tag!==''){$q=$pdo->prepare('SELECT COUNT(*) FROM equipment_assets WHERE organization_id=? AND asset_tag=? AND archived_at IS NULL');$q->execute([$org,$tag]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('That asset tag is already in use.');}
        $create=$input;unset($create['floorPlanId'],$create['xFt'],$create['yFt'],$create['rotationDeg']);
        $table=service_ops_managed_table_create($pdo,$org,$locationId,$create,$userId);
        if($floor!=='')$table=service_ops_place_table($pdo,$org,$locationId,(string)$table['publicId'],['floorPlanId'=>$floor,'xFt'=>(float)($input['xFt']??0),'yFt'=>(float)($input['yFt']??0),'rotationDeg'=>(float)($input['rotationDeg']??0)],$userId);
        return $table;
    });
}
