<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-failure-analysis.php';

const GLASSES_VISION_MINING_SCHEMA='gelato.vision_hard_example_mining.v1';

function glasses_vision_mining_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_mining_runs','glasses_vision_mined_candidates'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_failure_analysis_ready($pdo);
}

function glasses_vision_mining_policy(array $requested=[]): array
{
    $defaults=[
      'minimumScore'=>60,
      'maxOpenPerCluster'=>3,
      'maxOpenPerModel'=>12,
      'maxOpenPerRun'=>100,
      'recurrenceBonusPerExtra'=>2,
      'maximumRecurrenceBonus'=>10,
      'maximumConfidenceBonus'=>8,
    ];
    foreach(['minimumScore','maxOpenPerCluster','maxOpenPerModel','maxOpenPerRun'] as $k){
        if(isset($requested[$k]))$defaults[$k]=max(1,(int)$requested[$k]);
    }
    $defaults['minimumScore']=min(100,$defaults['minimumScore']);
    $defaults['maxOpenPerCluster']=min(20,$defaults['maxOpenPerCluster']);
    $defaults['maxOpenPerModel']=min(100,$defaults['maxOpenPerModel']);
    $defaults['maxOpenPerRun']=min(1000,$defaults['maxOpenPerRun']);
    return $defaults;
}

function glasses_vision_mining_type(array $e): string
{
    return match((string)$e['error_type']){
      'false_negative'=>'missed_positive',
      'false_positive','unexpected_detection'=>'counterexample',
      'misclassification'=>'misclassification',
      'low_confidence','correction','validation_disagreement'=>'hard_positive',
      default=>'hard_positive',
    };
}

function glasses_vision_mining_base_score(string $type): int
{
    return match($type){
      'false_negative'=>95,
      'misclassification'=>92,
      'false_positive'=>88,
      'validation_disagreement'=>84,
      'unexpected_detection'=>80,
      'low_confidence'=>78,
      'correction'=>68,
      default=>65,
    };
}

function glasses_vision_mining_cluster(array $e): array
{
    $snapshot=json_decode((string)$e['context_json'],true)?:[];
    $extra=is_array($snapshot['context']??null)?$snapshot['context']:[];
    $parts=[
      'model'=>$e['model_package_public_id']??'unattributed',
      'error'=>(string)$e['error_type'],
      'predicted'=>$e['predicted_component_key']??'none',
      'expected'=>$e['expected_component_key']??'none',
      'station'=>$e['station_public_id']??'none',
      'menuItem'=>$e['menu_item_id']!==null?(string)$e['menu_item_id']:'none',
      'lighting'=>(string)($extra['lighting']??$extra['lightingBucket']??$extra['exposure']??'unknown'),
      'pose'=>(string)($extra['pose']??$extra['poseBucket']??$extra['cameraPose']??'unknown'),
      'buildStep'=>(string)($extra['buildStep']??$extra['buildStepKey']??$extra['step']??'unknown'),
      'environment'=>(string)($extra['captureEnvironment']??$extra['environment']??$extra['environmentKey']??'unknown'),
    ];
    return ['parts'=>$parts,'key'=>hash('sha256',glasses_vision_training_release_json($parts))];
}

function glasses_vision_mining_confidence_bonus(array $e,int $max): array
{
    $confidence=$e['confidence']!==null?(float)$e['confidence']:null;
    if($confidence===null)return ['bonus'=>in_array((string)$e['error_type'],['false_negative','validation_disagreement'],true)?4:0,'reason'=>'confidence_unknown'];
    $type=(string)$e['error_type'];
    if(in_array($type,['misclassification','false_positive','unexpected_detection'],true)){
        $bonus=(int)round($confidence*$max);
        return ['bonus'=>$bonus,'reason'=>'confident_wrong_prediction'];
    }
    $bonus=(int)round((1.0-$confidence)*$max);
    return ['bonus'=>$bonus,'reason'=>'low_confidence_difficulty'];
}

function glasses_vision_mining_event_rows(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT e.*,s.public_id build_session_public_id,d.public_id device_public_id,
      ks.public_id station_public_id,p.public_id model_package_public_id,p.artifact_sha256 model_artifact_sha256
      FROM glasses_vision_production_errors e
      LEFT JOIN glasses_build_sessions s ON s.id=e.build_session_id AND s.organization_id=e.organization_id
      LEFT JOIN glasses_devices d ON d.id=e.device_id AND d.organization_id=e.organization_id
      LEFT JOIN kds_stations ks ON ks.id=e.station_id AND ks.organization_id=e.organization_id
      LEFT JOIN glasses_vision_model_packages p ON p.id=e.model_package_id AND p.organization_id=e.organization_id
      WHERE e.organization_id=? ORDER BY e.occurred_at,e.id");
    $q->execute([$org]);return $q->fetchAll();
}

function glasses_vision_mining_lineage(PDO $pdo,int $org,?string $modelPublic): ?array
{
    if($modelPublic===null||$modelPublic==='')return null;
    return glasses_vision_failure_analysis_ancestry($pdo,$org,$modelPublic);
}

function glasses_vision_mining_build(PDO $pdo,int $org,array $policy): array
{
    $events=glasses_vision_mining_event_rows($pdo,$org);
    $sourceEvents=[];$clusterCounts=[];$prepared=[];
    foreach($events as $e){
        $cluster=glasses_vision_mining_cluster($e);
        $clusterCounts[$cluster['key']]=($clusterCounts[$cluster['key']]??0)+1;
        $sourceEvents[]=['publicId'=>(string)$e['public_id'],'eventHash'=>(string)$e['event_hash']];
        $prepared[]=['event'=>$e,'cluster'=>$cluster];
    }
    usort($sourceEvents,static fn($a,$b)=>strcmp($a['publicId'],$b['publicId']));
    $fingerprint=hash('sha256',glasses_vision_training_release_json($sourceEvents));
    $candidates=[];
    foreach($prepared as $x){
        $e=$x['event'];$cluster=$x['cluster'];$base=glasses_vision_mining_base_score((string)$e['error_type']);
        $repeat=max(0,($clusterCounts[$cluster['key']]??1)-1);
        $recurrence=min((int)$policy['maximumRecurrenceBonus'],$repeat*(int)$policy['recurrenceBonusPerExtra']);
        $conf=glasses_vision_mining_confidence_bonus($e,(int)$policy['maximumConfidenceBonus']);
        $score=min(100,$base+$recurrence+(int)$conf['bonus']);
        if($score<(int)$policy['minimumScore'])continue;
        $type=glasses_vision_mining_type($e);
        $identity=[
          'schema'=>GLASSES_VISION_MINING_SCHEMA,'productionErrorPublicId'=>$e['public_id'],'productionErrorHash'=>$e['event_hash'],
          'candidateType'=>$type,'clusterKey'=>$cluster['key'],'modelPackagePublicId'=>$e['model_package_public_id']?:null,
        ];
        $candidateKey=hash('sha256',glasses_vision_training_release_json($identity));
        $reasons=[
          'baseErrorType'=>$e['error_type'],'baseScore'=>$base,'recurrenceCount'=>$clusterCounts[$cluster['key']],
          'recurrenceBonus'=>$recurrence,'confidenceBonus'=>$conf['bonus'],'confidenceReason'=>$conf['reason'],
          'cluster'=>$cluster['parts'],
        ];
        $candidate=[
          'candidateKey'=>$candidateKey,'candidateType'=>$type,'score'=>$score,'status'=>'open',
          'productionErrorId'=>(int)$e['id'],'productionErrorPublicId'=>$e['public_id'],'productionErrorHash'=>$e['event_hash'],
          'modelPackageId'=>$e['model_package_id']!==null?(int)$e['model_package_id']:null,'modelPackagePublicId'=>$e['model_package_public_id']?:null,
          'predictedComponentKey'=>$e['predicted_component_key'],'expectedComponentKey'=>$e['expected_component_key'],
          'clusterKey'=>$cluster['key'],'reasons'=>$reasons,
          'lineage'=>glasses_vision_mining_lineage($pdo,$org,$e['model_package_public_id']?:null),
        ];
        $candidate['candidateHash']='';
        $candidates[]=$candidate;
    }
    usort($candidates,static fn($a,$b)=>$b['score']<=>$a['score']?:strcmp($a['candidateKey'],$b['candidateKey']));

    $clusterOpen=[];$modelOpen=[];$selected=0;$open=0;$suppressed=0;
    foreach($candidates as &$c){
        if($selected>=(int)$policy['maxOpenPerRun']){$c['status']='suppressed';$c['reasons']['suppressionReason']='run_limit';$suppressed++;continue;}
        $cluster=$c['clusterKey'];$model=$c['modelPackagePublicId']??'unattributed';
        if(($clusterOpen[$cluster]??0)>=(int)$policy['maxOpenPerCluster']){
            $c['status']='suppressed';$c['reasons']['suppressionReason']='cluster_dominance_cap';$suppressed++;continue;
        }
        if(($modelOpen[$model]??0)>=(int)$policy['maxOpenPerModel']){
            $c['status']='suppressed';$c['reasons']['suppressionReason']='model_dominance_cap';$suppressed++;continue;
        }
        $clusterOpen[$cluster]=($clusterOpen[$cluster]??0)+1;$modelOpen[$model]=($modelOpen[$model]??0)+1;
        $selected++;$open++;
    }
    unset($c);
    foreach($candidates as &$c){$hashBody=$c;unset($hashBody['candidateHash']);$c['candidateHash']=hash('sha256',glasses_vision_training_release_json($hashBody));}unset($c);
    return [
      'schema'=>GLASSES_VISION_MINING_SCHEMA,'policy'=>$policy,'sourceFingerprint'=>$fingerprint,'sourceEvents'=>$sourceEvents,
      'summary'=>['sourceEvents'=>count($events),'eligibleCandidates'=>count($candidates),'open'=>$open,'suppressed'=>$suppressed],
      'candidates'=>$candidates,
      'governance'=>['automaticDatasetInclusion'=>false,'automaticRetraining'=>false,'automaticRollout'=>false],
    ];
}

function glasses_vision_mining_run(PDO $pdo,int $org,array $requested,int $actor): array
{
    if(!glasses_vision_mining_ready($pdo))throw new RuntimeException('Vision Lab V7 mining migration is not installed.');
    $policy=glasses_vision_mining_policy($requested);
    $result=glasses_vision_mining_build($pdo,$org,$policy);
    $runHash=hash('sha256',glasses_vision_training_release_json($result));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_mining_runs WHERE organization_id=? AND run_hash=? LIMIT 1");
    $q->execute([$org,$runHash]);$existing=$q->fetchColumn();
    if($existing!==false)return glasses_vision_mining_run_row($pdo,$org,(string)$existing);

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$policy,$result,$runHash):array{
        $public=glasses_public_id('vision-mining-run');
        $pdo->prepare("INSERT INTO glasses_vision_mining_runs (organization_id,public_id,source_fingerprint,policy_json,result_json,run_hash,created_by) VALUES (?,?,?,?,?,?,?)")
          ->execute([$org,$public,$result['sourceFingerprint'],json_encode($policy,JSON_UNESCAPED_SLASHES),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$runHash,$actor]);
        $runId=(int)$pdo->lastInsertId();
        $insert=$pdo->prepare("INSERT INTO glasses_vision_mined_candidates
          (organization_id,public_id,mining_run_id,production_error_id,candidate_key,candidate_hash,candidate_type,score,status,cluster_key,model_package_id,predicted_component_key,expected_component_key,reasons_json,lineage_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($result['candidates'] as $c){
            $insert->execute([$org,glasses_public_id('vision-mined'),$runId,$c['productionErrorId'],$c['candidateKey'],$c['candidateHash'],$c['candidateType'],$c['score'],$c['status'],$c['clusterKey'],$c['modelPackageId'],$c['predictedComponentKey'],$c['expectedComponentKey'],json_encode($c['reasons'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$c['lineage']?json_encode($c['lineage'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
        }
        return glasses_vision_mining_run_row($pdo,$org,$public);
    });
}

function glasses_vision_mining_run_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_mining_runs WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Vision mining run was not found.');
    return ['publicId'=>$r['public_id'],'sourceFingerprint'=>$r['source_fingerprint'],'runHash'=>$r['run_hash'],
      'policy'=>json_decode((string)$r['policy_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at']];
}

function glasses_vision_mining_candidate_public(array $r): array
{
    return [
      'publicId'=>$r['public_id'],'candidateKey'=>$r['candidate_key'],'candidateHash'=>$r['candidate_hash'],
      'candidateType'=>$r['candidate_type'],'score'=>(int)$r['score'],'status'=>$r['status'],
      'productionErrorPublicId'=>$r['production_error_public_id'],'productionErrorHash'=>$r['event_hash'],
      'modelPackagePublicId'=>$r['model_package_public_id'],'predictedComponentKey'=>$r['predicted_component_key'],
      'expectedComponentKey'=>$r['expected_component_key'],'clusterKey'=>$r['cluster_key'],
      'reasons'=>json_decode((string)$r['reasons_json'],true)?:[],'lineage'=>json_decode((string)($r['lineage_json']??'null'),true),
      'dismissedReason'=>$r['dismissed_reason'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_mining_candidates(PDO $pdo,int $org,string $status='all',int $limit=200): array
{
    if(!glasses_vision_mining_ready($pdo))return [];
    $limit=max(1,min(1000,$limit));$args=[$org];
    $sql="SELECT c.*,e.public_id production_error_public_id,e.event_hash,p.public_id model_package_public_id
      FROM glasses_vision_mined_candidates c
      JOIN glasses_vision_production_errors e ON e.id=c.production_error_id AND e.organization_id=c.organization_id
      LEFT JOIN glasses_vision_model_packages p ON p.id=c.model_package_id AND p.organization_id=c.organization_id
      WHERE c.organization_id=?";
    if($status!=='all'){$allowed=['open','suppressed','dismissed'];if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Mined-candidate status is invalid.');$sql.=" AND c.status=?";$args[]=$status;}
    $sql.=" ORDER BY c.score DESC,c.id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute($args);return array_map('glasses_vision_mining_candidate_public',$q->fetchAll());
}

function glasses_vision_mining_dismiss(PDO $pdo,int $org,string $publicId,string $reason,int $actor): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');
    if($reason==='')throw new InvalidArgumentException('Dismissal reason is required.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$reason,$actor):array{
        $q=$pdo->prepare("SELECT status FROM glasses_vision_mined_candidates WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$status=$q->fetchColumn();if($status===false)throw new InvalidArgumentException('Mined candidate was not found.');
        if($status==='dismissed'){
            $rows=glasses_vision_mining_candidates($pdo,$org,'dismissed',1000);
            foreach($rows as $row)if($row['publicId']===$publicId)return $row;
        }
        if(!in_array($status,['open','suppressed'],true))throw new InvalidArgumentException('Mined candidate cannot be dismissed.');
        $pdo->prepare("UPDATE glasses_vision_mined_candidates SET status='dismissed',dismissed_by=?,dismissed_reason=?,dismissed_at=NOW(6) WHERE organization_id=? AND public_id=?")
          ->execute([$actor,$reason,$org,$publicId]);
        $rows=glasses_vision_mining_candidates($pdo,$org,'dismissed',1000);
        foreach($rows as $row)if($row['publicId']===$publicId)return $row;
        throw new RuntimeException('Dismissed candidate could not be reloaded.');
    });
}

function glasses_vision_mining_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_mining_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_MINING_SCHEMA,'candidates'=>[],'runs'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_mining_runs WHERE organization_id=? ORDER BY id DESC LIMIT 20");$q->execute([$org]);
    $runs=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$runs[]=glasses_vision_mining_run_row($pdo,$org,(string)$p);
    return ['ready'=>true,'schema'=>GLASSES_VISION_MINING_SCHEMA,'candidates'=>glasses_vision_mining_candidates($pdo,$org,'all',200),'runs'=>$runs];
}
