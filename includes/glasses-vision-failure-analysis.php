<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-feedback.php';

const GLASSES_VISION_FAILURE_ANALYSIS_SCHEMA='gelato.vision_failure_analysis.v1';

function glasses_vision_failure_analysis_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_failure_analyses'");
    $q->execute();
    return (int)$q->fetchColumn()===1&&glasses_vision_feedback_ready($pdo);
}

function glasses_vision_failure_analysis_filters(array $input): array
{
    $filters=[
      'modelPackagePublicId'=>trim((string)($input['modelPackagePublicId']??''))?:null,
      'errorType'=>trim((string)($input['errorType']??''))?:null,
      'locationId'=>isset($input['locationId'])&&(int)$input['locationId']>0?(int)$input['locationId']:null,
      'stationPublicId'=>trim((string)($input['stationPublicId']??''))?:null,
      'menuItemId'=>isset($input['menuItemId'])&&(int)$input['menuItemId']>0?(int)$input['menuItemId']:null,
      'from'=>trim((string)($input['from']??''))?:null,
      'to'=>trim((string)($input['to']??''))?:null,
    ];
    if($filters['errorType']!==null)glasses_vision_feedback_error_type($filters['errorType']);
    foreach(['from','to'] as $key){
        if($filters[$key]!==null){
            $dt=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$filters[$key])?:DateTimeImmutable::createFromFormat('Y-m-d',$filters[$key]);
            if(!$dt)throw new InvalidArgumentException('Failure-analysis date filter is invalid.');
            $filters[$key]=$dt->format('Y-m-d H:i:s');
        }
    }
    if($filters['from']!==null&&$filters['to']!==null&&$filters['from']>$filters['to'])throw new InvalidArgumentException('Failure-analysis date range is invalid.');
    return $filters;
}

function glasses_vision_failure_analysis_events(PDO $pdo,int $org,array $filters): array
{
    $sql="SELECT e.*,s.public_id build_session_public_id,d.public_id device_public_id,d.display_name device_name,
      l.name location_name,ks.public_id station_public_id,ks.name station_name,mi.name menu_item_name,
      p.public_id model_package_public_id,p.model_name,p.model_version,p.artifact_sha256 model_artifact_sha256,
      r.public_id rollout_public_id
      FROM glasses_vision_production_errors e
      LEFT JOIN glasses_build_sessions s ON s.id=e.build_session_id
      LEFT JOIN glasses_devices d ON d.id=e.device_id
      LEFT JOIN locations l ON l.id=e.location_id
      LEFT JOIN kds_stations ks ON ks.id=e.station_id
      LEFT JOIN menu_items mi ON mi.id=e.menu_item_id
      LEFT JOIN glasses_vision_model_packages p ON p.id=e.model_package_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=e.rollout_id
      WHERE e.organization_id=?";
    $args=[$org];
    if($filters['modelPackagePublicId']!==null){$sql.=" AND p.public_id=?";$args[]=$filters['modelPackagePublicId'];}
    if($filters['errorType']!==null){$sql.=" AND e.error_type=?";$args[]=$filters['errorType'];}
    if($filters['locationId']!==null){$sql.=" AND e.location_id=?";$args[]=$filters['locationId'];}
    if($filters['stationPublicId']!==null){$sql.=" AND ks.public_id=?";$args[]=$filters['stationPublicId'];}
    if($filters['menuItemId']!==null){$sql.=" AND e.menu_item_id=?";$args[]=$filters['menuItemId'];}
    if($filters['from']!==null){$sql.=" AND e.occurred_at>=?";$args[]=$filters['from'];}
    if($filters['to']!==null){$sql.=" AND e.occurred_at<=?";$args[]=$filters['to'];}
    $sql.=" ORDER BY e.occurred_at,e.id";
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}

function glasses_vision_failure_analysis_bucket(?float $confidence): string
{
    if($confidence===null)return 'unknown';
    if($confidence<0.50)return 'lt_0_50';
    if($confidence<0.75)return '0_50_0_74';
    if($confidence<0.90)return '0_75_0_89';
    return 'gte_0_90';
}

function glasses_vision_failure_analysis_context_value(array $snapshot,array $keys): ?string
{
    $extra=is_array($snapshot['context']??null)?$snapshot['context']:[];
    foreach($keys as $key){
        $v=$extra[$key]??null;
        if(is_scalar($v)&&trim((string)$v)!=='')return mb_substr(trim((string)$v),0,160,'UTF-8');
    }
    return null;
}

function glasses_vision_failure_analysis_add(array &$group,string $key,?string $value): void
{
    $value=$value!==null&&trim($value)!==''?$value:'unknown';
    $group[$key][$value]=($group[$key][$value]??0)+1;
}

function glasses_vision_failure_analysis_rank(array $values,int $total): array
{
    arsort($values,SORT_NUMERIC);$out=[];
    foreach($values as $label=>$count)$out[]=['label'=>(string)$label,'count'=>(int)$count,'share'=>$total>0?round((int)$count/$total,6):0.0];
    return $out;
}

function glasses_vision_failure_analysis_ancestry(PDO $pdo,int $org,string $modelPublic): ?array
{
    $q=$pdo->prepare("SELECT r.public_id release_public_id,r.release_hash,r.manifest_json,d.public_id dataset_public_id,d.dataset_hash
      FROM glasses_vision_lineage_edges e
      JOIN glasses_vision_training_releases r ON r.organization_id=e.organization_id AND r.public_id=e.from_public_id
      JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id AND d.organization_id=r.organization_id
      WHERE e.organization_id=? AND e.from_kind='training_release' AND e.relation='trained_model'
        AND e.to_kind='model_package' AND e.to_public_id=?
      ORDER BY e.id DESC LIMIT 1");
    $q->execute([$org,$modelPublic]);$row=$q->fetch();
    if(!$row)return null;
    $m=json_decode((string)$row['manifest_json'],true)?:[];
    return [
      'modelPackagePublicId'=>$modelPublic,
      'trainingReleasePublicId'=>$row['release_public_id'],'trainingReleaseHash'=>$row['release_hash'],
      'datasetPublicId'=>$row['dataset_public_id'],'datasetHash'=>$row['dataset_hash'],
      'splitPlanPublicId'=>$m['splitPlan']['publicId']??null,'splitPlanHash'=>$m['splitPlan']['planHash']??null,
      'trainingProfile'=>$m['trainingProfile']['name']??null,
    ];
}

function glasses_vision_failure_analysis_build(PDO $pdo,int $org,array $filters): array
{
    $events=glasses_vision_failure_analysis_events($pdo,$org,$filters);$total=count($events);
    $groups=[
      'errorType'=>[],'outcome'=>[],'model'=>[],'predictedClass'=>[],'expectedClass'=>[],'location'=>[],'station'=>[],
      'device'=>[],'menuItem'=>[],'confidence'=>[],'lighting'=>[],'pose'=>[],'buildStep'=>[],'captureEnvironment'=>[]
    ];
    $modelCounts=[];$timeline=[];$sourceEvents=[];
    foreach($events as $e){
        $snapshot=json_decode((string)$e['context_json'],true)?:[];
        $sourceEvents[]=['publicId'=>(string)$e['public_id'],'eventHash'=>(string)$e['event_hash'],'modelPackagePublicId'=>$e['model_package_public_id']?:null];
        glasses_vision_failure_analysis_add($groups,'errorType',(string)$e['error_type']);
        glasses_vision_failure_analysis_add($groups,'outcome',(string)$e['outcome']);
        $modelLabel=$e['model_package_public_id']?((string)$e['model_name'].' '.(string)$e['model_version'].' · '.(string)$e['model_package_public_id']):'unattributed';
        glasses_vision_failure_analysis_add($groups,'model',$modelLabel);
        glasses_vision_failure_analysis_add($groups,'predictedClass',$e['predicted_component_key']);
        glasses_vision_failure_analysis_add($groups,'expectedClass',$e['expected_component_key']);
        glasses_vision_failure_analysis_add($groups,'location',$e['location_name']??($e['location_id']!==null?'location:'.$e['location_id']:null));
        glasses_vision_failure_analysis_add($groups,'station',$e['station_name']??$e['station_public_id']);
        glasses_vision_failure_analysis_add($groups,'device',$e['device_name']??$e['device_public_id']);
        glasses_vision_failure_analysis_add($groups,'menuItem',$e['menu_item_name']??($e['menu_item_id']!==null?'menu:'.$e['menu_item_id']:null));
        glasses_vision_failure_analysis_add($groups,'confidence',glasses_vision_failure_analysis_bucket($e['confidence']!==null?(float)$e['confidence']:null));
        glasses_vision_failure_analysis_add($groups,'lighting',glasses_vision_failure_analysis_context_value($snapshot,['lighting','lightingBucket','exposure']));
        glasses_vision_failure_analysis_add($groups,'pose',glasses_vision_failure_analysis_context_value($snapshot,['pose','poseBucket','cameraPose']));
        glasses_vision_failure_analysis_add($groups,'buildStep',glasses_vision_failure_analysis_context_value($snapshot,['buildStep','buildStepKey','step']));
        glasses_vision_failure_analysis_add($groups,'captureEnvironment',glasses_vision_failure_analysis_context_value($snapshot,['captureEnvironment','environment','environmentKey']));
        if(!empty($e['model_package_public_id']))$modelCounts[(string)$e['model_package_public_id']]=($modelCounts[(string)$e['model_package_public_id']]??0)+1;
        $day=substr((string)$e['occurred_at'],0,10);$timeline[$day]=($timeline[$day]??0)+1;
    }
    ksort($timeline,SORT_STRING);usort($sourceEvents,static fn($a,$b)=>strcmp($a['publicId'],$b['publicId']));
    $ranked=[];foreach($groups as $name=>$values)$ranked[$name]=glasses_vision_failure_analysis_rank($values,$total);
    $ancestry=[];foreach($modelCounts as $modelPublic=>$count){$a=glasses_vision_failure_analysis_ancestry($pdo,$org,$modelPublic);$ancestry[]=['modelPackagePublicId'=>$modelPublic,'failureCount'=>$count,'share'=>$total?round($count/$total,6):0.0,'lineage'=>$a];}
    usort($ancestry,static fn($a,$b)=>$b['failureCount']<=>$a['failureCount']);
    return [
      'schema'=>GLASSES_VISION_FAILURE_ANALYSIS_SCHEMA,'filters'=>$filters,'eventCount'=>$total,
      'sourceEvents'=>$sourceEvents,'sourceFingerprint'=>hash('sha256',glasses_vision_training_release_json($sourceEvents)),
      'dimensions'=>$ranked,'timeline'=>array_map(static fn($day,$count)=>['day'=>$day,'count'=>$count],array_keys($timeline),array_values($timeline)),
      'modelAncestry'=>$ancestry,
      'notes'=>['metric'=>'failure_count_and_share','denominatorAvailable'=>false,'rateClaimed'=>false],
    ];
}

function glasses_vision_failure_analysis_run(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_failure_analysis_ready($pdo))throw new RuntimeException('Vision Lab V7 failure-analysis migration is not installed.');
    $filters=glasses_vision_failure_analysis_filters($input);
    $result=glasses_vision_failure_analysis_build($pdo,$org,$filters);
    $hash=hash('sha256',glasses_vision_training_release_json($result));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_failure_analyses WHERE organization_id=? AND analysis_hash=? LIMIT 1");
    $q->execute([$org,$hash]);$existing=$q->fetchColumn();
    if($existing!==false)return glasses_vision_failure_analysis_row($pdo,$org,(string)$existing);
    $public=glasses_public_id('vision-failure-analysis');
    $pdo->prepare("INSERT INTO glasses_vision_failure_analyses (organization_id,public_id,analysis_hash,event_count,filter_json,result_json,created_by) VALUES (?,?,?,?,?,?,?)")
      ->execute([$org,$public,$hash,(int)$result['eventCount'],json_encode($filters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
    return glasses_vision_failure_analysis_row($pdo,$org,$public);
}

function glasses_vision_failure_analysis_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_failure_analyses WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Failure analysis was not found.');
    return ['publicId'=>$r['public_id'],'analysisHash'=>$r['analysis_hash'],'eventCount'=>(int)$r['event_count'],
      'filters'=>json_decode((string)$r['filter_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at']];
}

function glasses_vision_failure_analysis_list(PDO $pdo,int $org,int $limit=20): array
{
    if(!glasses_vision_failure_analysis_ready($pdo))return [];
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_failure_analyses WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$org]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_failure_analysis_row($pdo,$org,(string)$p);return $out;
}

function glasses_vision_failure_analysis_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_failure_analysis_ready($pdo),'schema'=>GLASSES_VISION_FAILURE_ANALYSIS_SCHEMA,'analyses'=>glasses_vision_failure_analysis_list($pdo,$org,20)];
}
