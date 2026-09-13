<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-floor.php';
require_once __DIR__.'/table-service-reconcile.php';

function service_ops_canonical_map(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    $map=service_ops_map($pdo,$org,$locationId,$activeOnly);$plans=[];
    foreach(host_floor_plans($pdo,$org) as $plan)$plans[(string)$plan['publicId']]=$plan;
    foreach($map['tables'] as &$table){
        $asset=$table['asset']??null;if(!$asset||empty($asset['floorPlanId'])||$asset['xFt']===null||$asset['yFt']===null)continue;
        $plan=$plans[(string)$asset['floorPlanId']]??null;if(!$plan||$plan['widthFt']<=0||$plan['depthFt']<=0)continue;
        $table['xPercent']=max(0,min(100,(float)$asset['xFt']/(float)$plan['widthFt']*100));
        $table['yPercent']=max(0,min(100,(float)$asset['yFt']/(float)$plan['depthFt']*100));
        $table['widthPercent']=max(2,min(100,(float)($asset['widthInches']??36)/12/(float)$plan['widthFt']*100));
        $table['heightPercent']=max(2,min(100,(float)($asset['depthInches']??36)/12/(float)$plan['depthFt']*100));
        $table['floorPlanId']=$asset['floorPlanId'];
    }unset($table);
    $map['floorPlans']=array_values($plans);
    return $map;
}
