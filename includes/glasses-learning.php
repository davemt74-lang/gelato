<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/kds-core.php';

function glasses_learning_ready(PDO $pdo): bool
{
    foreach(['glasses_build_observations','glasses_observation_corrections','glasses_build_sessions'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_learning_window_days(int $days): int
{
    return max(1,min(365,$days));
}

function glasses_learning_confidence_band(float $confidence): string
{
    if($confidence<0.50)return '0.00–0.49';
    if($confidence<0.75)return '0.50–0.74';
    if($confidence<0.85)return '0.75–0.84';
    if($confidence<0.95)return '0.85–0.94';
    return '0.95–1.00';
}

function glasses_learning_outcome(array $row): string
{
    $resolution=(string)($row['correction_resolution']??'');
    if($resolution==='')return 'uncorrected';
    if($resolution==='reject')return 'rejected';
    if($resolution!=='replace')return 'reviewed';

    $source=(string)$row['component_key'];
    $target=trim((string)($row['target_component_key']??''));
    if($target!==''&&$target!==$source)return 'reclassified';

    if($row['corrected_quantity']!==null
        && abs((float)$row['corrected_quantity']-(float)$row['quantity'])>0.0001)
        return 'quantity_corrected';

    return 'reviewed';
}

function glasses_learning_rows(
    PDO $pdo,
    int $org,
    int $locationId,
    ?string $stationPublicId,
    int $days,
    int $limit=5000
): array {
    if(!glasses_learning_ready($pdo))throw new RuntimeException('Glasses learning migration is not installed.');
    if($locationId<1)throw new InvalidArgumentException('Choose a restaurant location.');

    $days=glasses_learning_window_days($days);
    $limit=max(1,min(20000,$limit));
    $stationPublicId=trim((string)$stationPublicId);

    $sql="SELECT
            o.id observation_id,o.observation_key,o.component_key,o.action,o.quantity,o.confidence,o.tracking_id,o.metadata_json,o.created_at,
            s.public_id build_session_public_id,s.menu_item_id,k.location_id,
            mi.name menu_item_name,
            k.public_id kds_item_public_id,
            ks.public_id station_public_id,ks.name station_name,
            d.public_id device_public_id,d.platform device_platform,d.sdk_version,d.app_version,
            bc.display_name source_component_name,
            c.id correction_id,c.correction_key,c.resolution correction_resolution,c.target_component_key,c.corrected_quantity,c.reason correction_reason,c.metadata_json correction_metadata_json,c.created_at correction_created_at,
            tbc.display_name target_component_name
        FROM glasses_build_observations o
        JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
        JOIN menu_items mi ON mi.id=s.menu_item_id AND mi.organization_id=s.organization_id
        JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
        LEFT JOIN kds_stations ks ON ks.id=k.station_id AND ks.organization_id=k.organization_id
        JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
        LEFT JOIN glasses_build_components bc ON bc.build_session_id=s.id AND bc.organization_id=s.organization_id AND bc.component_key=o.component_key
        LEFT JOIN glasses_observation_corrections c ON c.id=(
            SELECT MAX(c2.id)
            FROM glasses_observation_corrections c2
            WHERE c2.organization_id=o.organization_id AND c2.observation_id=o.id
        )
        LEFT JOIN glasses_build_components tbc ON tbc.build_session_id=s.id AND tbc.organization_id=s.organization_id AND tbc.component_key=c.target_component_key
        WHERE o.organization_id=? AND k.location_id=?
          AND o.created_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY)";
    $args=[$org,$locationId];

    if($stationPublicId!==''){
        $sql.=" AND ks.public_id=?";
        $args[]=$stationPublicId;
    }
    $sql.=" ORDER BY o.id DESC LIMIT {$limit}";

    $q=$pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchAll();
}

function glasses_learning_public_row(array $row): array
{
    $metadata=json_decode((string)($row['metadata_json']??'null'),true);
    if(!is_array($metadata))$metadata=[];
    $correctionMetadata=json_decode((string)($row['correction_metadata_json']??'null'),true);
    if(!is_array($correctionMetadata))$correctionMetadata=[];

    $outcome=glasses_learning_outcome($row);
    $target=trim((string)($row['target_component_key']??''));
    $effectiveKey=$outcome==='rejected'?null:($target!==''?$target:(string)$row['component_key']);
    $effectiveName=$outcome==='rejected'?null:(
        $target!==''?(string)($row['target_component_name']??$target):(string)($row['source_component_name']??$row['component_key'])
    );
    $effectiveQuantity=$outcome==='rejected'?0.0:(
        $row['corrected_quantity']!==null?(float)$row['corrected_quantity']:(float)$row['quantity']
    );

    return [
        'schema'=>'gelato.ar_learning.v1',
        'observationKey'=>(string)$row['observation_key'],
        'capturedAt'=>$row['created_at'],
        'buildSessionPublicId'=>(string)$row['build_session_public_id'],
        'kdsItemPublicId'=>(string)$row['kds_item_public_id'],
        'locationId'=>(int)$row['location_id'],
        'stationPublicId'=>$row['station_public_id'],
        'stationName'=>$row['station_name'],
        'menuItemId'=>(int)$row['menu_item_id'],
        'menuItemName'=>(string)$row['menu_item_name'],
        'device'=>[
            'publicId'=>(string)$row['device_public_id'],
            'platform'=>(string)($row['device_platform']??''),
            'sdkVersion'=>(string)($row['sdk_version']??''),
            'appVersion'=>(string)($row['app_version']??''),
        ],
        'source'=>[
            'componentKey'=>(string)$row['component_key'],
            'componentName'=>(string)($row['source_component_name']??$row['component_key']),
            'action'=>(string)$row['action'],
            'quantity'=>(float)$row['quantity'],
            'confidence'=>$row['confidence']!==null?(float)$row['confidence']:null,
            'confidenceBand'=>glasses_learning_confidence_band((float)($row['confidence']??0)),
            'trackingId'=>(string)($row['tracking_id']??''),
            'evidenceKind'=>(string)($metadata['evidenceKind']??''),
            'sourceZoneKey'=>(string)($metadata['sourceZoneKey']??''),
            'destinationRegionKey'=>(string)($metadata['destinationRegionKey']??''),
            'sequenceSupported'=>!empty($metadata['sequenceSupported']),
        ],
        'humanReview'=>[
            'reviewed'=>$row['correction_id']!==null,
            'outcome'=>$outcome,
            'correctionKey'=>$row['correction_key'],
            'resolution'=>$row['correction_resolution'],
            'reason'=>(string)($row['correction_reason']??''),
            'correctedAt'=>$row['correction_created_at'],
            'metadata'=>$correctionMetadata,
        ],
        'effective'=>[
            'componentKey'=>$effectiveKey,
            'componentName'=>$effectiveName,
            'quantity'=>$effectiveQuantity,
        ],
    ];
}

function glasses_learning_dataset(
    PDO $pdo,
    int $org,
    int $locationId,
    ?string $stationPublicId,
    int $days,
    bool $correctedOnly=true,
    int $limit=5000
): array {
    $rows=glasses_learning_rows($pdo,$org,$locationId,$stationPublicId,$days,$limit);
    $items=[];
    foreach($rows as $row){
        if($correctedOnly&&$row['correction_id']===null)continue;
        $items[]=glasses_learning_public_row($row);
    }

    $material=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return [
        'schema'=>'gelato.ar_learning_dataset.v1',
        'correctedOnly'=>$correctedOnly,
        'rowCount'=>count($items),
        'datasetHash'=>hash('sha256',$material),
        'rows'=>$items,
    ];
}

function glasses_learning_analytics(
    PDO $pdo,
    int $org,
    int $locationId,
    ?string $stationPublicId,
    int $days
): array {
    $days=glasses_learning_window_days($days);
    $rows=glasses_learning_rows($pdo,$org,$locationId,$stationPublicId,$days,20000);

    $total=count($rows);
    $corrected=0;$rejected=0;$reclassified=0;$quantityCorrected=0;$reviewed=0;
    $confidenceSum=0.0;$confidenceCount=0;
    $bands=[];$components=[];$evidenceKinds=[];

    foreach(['0.00–0.49','0.50–0.74','0.75–0.84','0.85–0.94','0.95–1.00'] as $band)
        $bands[$band]=['band'=>$band,'observations'=>0,'corrected'=>0,'rejected'=>0,'reclassified'=>0,'correctionRate'=>0.0];

    foreach($rows as $row){
        $outcome=glasses_learning_outcome($row);
        $isCorrected=$row['correction_id']!==null;
        if($isCorrected)$corrected++;
        if($outcome==='rejected')$rejected++;
        elseif($outcome==='reclassified')$reclassified++;
        elseif($outcome==='quantity_corrected')$quantityCorrected++;
        elseif($outcome==='reviewed')$reviewed++;

        $confidence=(float)($row['confidence']??0);
        if($row['confidence']!==null){$confidenceSum+=$confidence;$confidenceCount++;}
        $band=glasses_learning_confidence_band($confidence);
        $bands[$band]['observations']++;
        if($isCorrected)$bands[$band]['corrected']++;
        if($outcome==='rejected')$bands[$band]['rejected']++;
        if($outcome==='reclassified')$bands[$band]['reclassified']++;

        $key=(string)$row['component_key'];
        if(!isset($components[$key]))$components[$key]=[
            'componentKey'=>$key,
            'componentName'=>(string)($row['source_component_name']??$key),
            'observations'=>0,'corrected'=>0,'rejected'=>0,'reclassified'=>0,'quantityCorrected'=>0,'correctionRate'=>0.0
        ];
        $components[$key]['observations']++;
        if($isCorrected)$components[$key]['corrected']++;
        if($outcome==='rejected')$components[$key]['rejected']++;
        if($outcome==='reclassified')$components[$key]['reclassified']++;
        if($outcome==='quantity_corrected')$components[$key]['quantityCorrected']++;

        $metadata=json_decode((string)($row['metadata_json']??'null'),true);
        $kind=is_array($metadata)?trim((string)($metadata['evidenceKind']??'')):'';
        if($kind==='')$kind='unspecified';
        if(!isset($evidenceKinds[$kind]))$evidenceKinds[$kind]=[
            'evidenceKind'=>$kind,'observations'=>0,'corrected'=>0,'correctionRate'=>0.0
        ];
        $evidenceKinds[$kind]['observations']++;
        if($isCorrected)$evidenceKinds[$kind]['corrected']++;
    }

    foreach($bands as &$band){
        $band['correctionRate']=$band['observations']>0?round($band['corrected']/$band['observations'],4):0.0;
    }unset($band);
    foreach($components as &$component){
        $component['correctionRate']=$component['observations']>0?round($component['corrected']/$component['observations'],4):0.0;
    }unset($component);
    foreach($evidenceKinds as &$kind){
        $kind['correctionRate']=$kind['observations']>0?round($kind['corrected']/$kind['observations'],4):0.0;
    }unset($kind);

    $components=array_values($components);
    usort($components,static function(array $a,array $b):int{
        $rate=$b['correctionRate']<=>$a['correctionRate'];
        return $rate!==0?$rate:($b['observations']<=>$a['observations']);
    });
    $evidenceKinds=array_values($evidenceKinds);
    usort($evidenceKinds,static fn(array $a,array $b):int=>$b['observations']<=>$a['observations']);

    return [
        'window'=>['days'=>$days],
        'scope'=>['locationId'=>$locationId,'stationPublicId'=>$stationPublicId?:null],
        'totals'=>[
            'observations'=>$total,
            'uncorrected'=>$total-$corrected,
            'humanCorrected'=>$corrected,
            'humanCorrectionRate'=>$total>0?round($corrected/$total,4):0.0,
            'rejected'=>$rejected,
            'reclassified'=>$reclassified,
            'quantityCorrected'=>$quantityCorrected,
            'reviewedNoChange'=>$reviewed,
            'meanModelConfidence'=>$confidenceCount>0?round($confidenceSum/$confidenceCount,4):null,
        ],
        'confidenceBands'=>array_values($bands),
        'components'=>$components,
        'evidenceKinds'=>$evidenceKinds,
        'interpretation'=>[
            'uncorrectedDoesNotMeanVerifiedCorrect'=>true,
            'correctionRateIsHumanInterventionRate'=>true,
        ],
    ];
}

function glasses_learning_catalog(PDO $pdo,array $user): array
{
    $org=(int)($user['organization_id']??0);
    if($org<1)throw new InvalidArgumentException('Organization context is required.');

    require_once __DIR__.'/operational-access.php';
    $q=$pdo->prepare("SELECT id,name,is_primary,sort_order FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");
    $q->execute([$org]);
    $locations=operational_filter_locations($pdo,$user,'glasses.view',$q->fetchAll());

    $stations=[];
    foreach($locations as $location){
        $locationId=(int)$location['id'];
        $stations[(string)$locationId]=array_map(
            static fn(array $station):array=>[
                'publicId'=>(string)$station['public_id'],
                'name'=>(string)$station['name'],
            ],
            kds_stations($pdo,$org,$locationId,true)
        );
    }

    return [
        'locations'=>array_map(static fn(array $location):array=>[
            'id'=>(int)$location['id'],
            'name'=>(string)$location['name'],
            'primary'=>(bool)$location['is_primary'],
        ],$locations),
        'stationsByLocation'=>$stations,
        'canExport'=>app_has_permission('glasses.manage',$user),
        'ready'=>glasses_learning_ready($pdo),
    ];
}
