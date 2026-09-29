<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-v11-training-runs.php';

const GLASSES_V11_MODEL_EVALUATION_SCHEMA='gelato.vision_v11_model_benchmark.v1';

function glasses_v11_model_evaluation_ready(PDO $pdo): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_model_benchmarks'");
  $q->execute();
  return (int)$q->fetchColumn()===1&&glasses_v11_training_run_ready($pdo);
}

function glasses_v11_model_evaluation_policy(array $in=[]): array {
  return [
    'minimumTestCases'=>max(1,(int)($in['minimumTestCases']??20)),
    'minimumMap50Delta'=>(float)($in['minimumMap50Delta']??0.0),
    'minimumRecallDelta'=>(float)($in['minimumRecallDelta']??0.0),
    'minimumPrecisionDelta'=>(float)($in['minimumPrecisionDelta']??0.0),
    'maximumCriticalFalseNegatives'=>max(0,(int)($in['maximumCriticalFalseNegatives']??0)),
    'maximumClassRegressions'=>max(0,(int)($in['maximumClassRegressions']??0)),
    'maximumSliceRegressions'=>max(0,(int)($in['maximumSliceRegressions']??0)),
    'minimumSliceCases'=>max(1,(int)($in['minimumSliceCases']??5)),
    'requiredSliceKinds'=>['operator','device','location','station'],
  ];
}

function glasses_v11_model_evaluation_number(mixed $v,string $label): float {
  if(!is_numeric($v)||!is_finite((float)$v))throw new InvalidArgumentException($label.' must be finite numeric.');
  return round((float)$v,8);
}

function glasses_v11_model_evaluation_global(array $in): array {
  $total=max(0,(int)($in['totalCases']??0));
  $champ=is_array($in['champion']??null)?$in['champion']:[];
  $chall=is_array($in['challenger']??null)?$in['challenger']:[];
  $metric=function(array $m,string $k,string $label):float{return glasses_v11_model_evaluation_number($m[$k]??null,$label);};
  return [
    'totalCases'=>$total,
    'champion'=>['map50'=>$metric($champ,'map50','Champion mAP50'),'recall'=>$metric($champ,'recall','Champion recall'),'precision'=>$metric($champ,'precision','Champion precision')],
    'challenger'=>['map50'=>$metric($chall,'map50','Challenger mAP50'),'recall'=>$metric($chall,'recall','Challenger recall'),'precision'=>$metric($chall,'precision','Challenger precision')],
    'criticalFalseNegatives'=>max(0,(int)($chall['criticalFalseNegatives']??0)),
  ];
}

function glasses_v11_model_evaluation_slices(array $rows): array {
  $out=[];
  foreach($rows as $r){
    if(!is_array($r))throw new InvalidArgumentException('Benchmark slice is invalid.');
    $kind=strtolower(trim((string)($r['kind']??'')));if(!in_array($kind,['operator','device','location','station','class','hard_example'],true))throw new InvalidArgumentException('Benchmark slice kind is invalid.');
    $key=mb_substr(trim((string)($r['key']??'')),0,190,'UTF-8');if($key==='')throw new InvalidArgumentException('Benchmark slice key is required.');
    $cases=max(0,(int)($r['cases']??0));
    $champ=glasses_v11_model_evaluation_number($r['championScore']??null,'Champion slice score');
    $chall=glasses_v11_model_evaluation_number($r['challengerScore']??null,'Challenger slice score');
    $out[]=['kind'=>$kind,'key'=>$key,'cases'=>$cases,'championScore'=>$champ,'challengerScore'=>$chall,'delta'=>round($chall-$champ,8)];
  }
  usort($out,static fn($a,$b)=>[$a['kind'],$a['key']]<=>[$b['kind'],$b['key']]);
  return $out;
}

function glasses_v11_model_evaluation_confusion(array $in): array {
  $classes=[];
  foreach($in as $label=>$row){
    if(!is_array($row))continue;
    $key=mb_substr(trim((string)$label),0,190,'UTF-8');if($key==='')continue;
    $classes[$key]=[
      'tp'=>max(0,(int)($row['tp']??0)),'fp'=>max(0,(int)($row['fp']??0)),
      'fn'=>max(0,(int)($row['fn']??0)),'support'=>max(0,(int)($row['support']??0)),
    ];
  }
  ksort($classes,SORT_STRING);return $classes;
}

function glasses_v11_model_evaluation_check(string $key,string $label,bool $passed,mixed $actual,mixed $required): array {
  return ['key'=>$key,'label'=>$label,'passed'=>$passed,'actual'=>$actual,'required'=>$required];
}

function glasses_v11_model_evaluation_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT b.*,x.public_id experiment_public_id,x.experiment_hash,tr.public_id training_run_public_id,tr.run_hash,
    cp.public_id champion_public_id,cp.artifact_sha256 champion_sha,xp.public_id challenger_public_id,xp.artifact_sha256 challenger_sha
    FROM glasses_vision_model_benchmarks b
    JOIN glasses_vision_model_experiments x ON x.id=b.experiment_id AND x.organization_id=b.organization_id
    JOIN glasses_vision_training_runs tr ON tr.id=b.training_run_id AND tr.organization_id=b.organization_id
    JOIN glasses_vision_model_packages cp ON cp.id=b.champion_package_id AND cp.organization_id=b.organization_id
    JOIN glasses_vision_model_packages xp ON xp.id=b.challenger_package_id AND xp.organization_id=b.organization_id
    WHERE b.organization_id=? AND b.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 model benchmark was not found.');
  return [
    'schema'=>GLASSES_V11_MODEL_EVALUATION_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'score'=>(int)$r['score'],'benchmarkHash'=>$r['benchmark_hash'],
    'experiment'=>['publicId'=>$r['experiment_public_id'],'experimentHash'=>$r['experiment_hash']],
    'trainingRun'=>['publicId'=>$r['training_run_public_id'],'runHash'=>$r['run_hash']],
    'champion'=>['publicId'=>$r['champion_public_id'],'artifactSha256'=>$r['champion_sha']],
    'challenger'=>['publicId'=>$r['challenger_public_id'],'artifactSha256'=>$r['challenger_sha']],
    'policy'=>json_decode((string)$r['policy_json'],true)?:[],'datasetSnapshot'=>json_decode((string)$r['dataset_snapshot_json'],true)?:[],
    'globalMetrics'=>json_decode((string)$r['global_metrics_json'],true)?:[],'slices'=>json_decode((string)$r['slice_metrics_json'],true)?:[],
    'confusion'=>json_decode((string)$r['confusion_json'],true)?:[],'regressions'=>json_decode((string)$r['regressions_json'],true)?:[],
    'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at'],
  ];
}

function glasses_v11_model_evaluation_run(PDO $pdo,int $org,string $experimentPublic,array $input,int $actor): array {
  if(!glasses_v11_model_evaluation_ready($pdo))throw new RuntimeException('V11 model-evaluation migration is not installed.');
  $x=glasses_vision_experiment_row($pdo,$org,$experimentPublic,false);
  if((string)$x['status']!=='completed'||$x['challenger_package_id']===null||$x['training_run_id']===null)throw new InvalidArgumentException('Benchmark requires a completed experiment with challenger and training run.');
  $run=glasses_v11_training_run_row($pdo,$org,(string)$x['training_run_public_id'],false);
  if((string)$run['status']!=='completed'||empty($run['run_hash'])||empty($run['output_sha256']))throw new InvalidArgumentException('Benchmark requires a completed V11 training run.');
  if(!hash_equals((string)$run['output_sha256'],(string)$x['challenger_sha']))throw new InvalidArgumentException('Benchmark challenger artifact must equal the completed training output.');

  $testSetHash=glasses_v11_training_run_sha((string)($input['testSetHash']??''),'Benchmark test-set hash');
  $hardExampleHash=glasses_v11_training_run_sha((string)($input['hardExampleSetHash']??''),'Benchmark hard-example-set hash');
  $policy=glasses_v11_model_evaluation_policy(is_array($input['policy']??null)?$input['policy']:[]);
  $global=glasses_v11_model_evaluation_global(is_array($input['globalMetrics']??null)?$input['globalMetrics']:[]);
  $slices=glasses_v11_model_evaluation_slices(is_array($input['slices']??null)?$input['slices']:[]);
  $confusion=glasses_v11_model_evaluation_confusion(is_array($input['confusion']??null)?$input['confusion']:[]);

  $mapDelta=$global['challenger']['map50']-$global['champion']['map50'];
  $recallDelta=$global['challenger']['recall']-$global['champion']['recall'];
  $precisionDelta=$global['challenger']['precision']-$global['champion']['precision'];
  $classRegressions=[];foreach($confusion as $label=>$m)if($m['support']>0&&$m['fn']>$m['tp'])$classRegressions[]=$label;
  $sliceRegressions=array_values(array_filter($slices,static fn($s)=>$s['cases']>0&&$s['delta']<0));
  $sliceKinds=[];foreach($slices as $s)if($s['cases']>=$policy['minimumSliceCases'])$sliceKinds[$s['kind']]=true;
  $missingKinds=array_values(array_filter($policy['requiredSliceKinds'],static fn($k)=>empty($sliceKinds[$k])));

  $checks=[
    glasses_v11_model_evaluation_check('test_cases','Held-out test case count',$global['totalCases']>=$policy['minimumTestCases'],$global['totalCases'],'>= '.$policy['minimumTestCases']),
    glasses_v11_model_evaluation_check('map50_delta','mAP50 delta',$mapDelta>=$policy['minimumMap50Delta'],round($mapDelta,8),'>= '.$policy['minimumMap50Delta']),
    glasses_v11_model_evaluation_check('recall_delta','Recall delta',$recallDelta>=$policy['minimumRecallDelta'],round($recallDelta,8),'>= '.$policy['minimumRecallDelta']),
    glasses_v11_model_evaluation_check('precision_delta','Precision delta',$precisionDelta>=$policy['minimumPrecisionDelta'],round($precisionDelta,8),'>= '.$policy['minimumPrecisionDelta']),
    glasses_v11_model_evaluation_check('critical_false_negatives','Critical false negatives',$global['criticalFalseNegatives']<=$policy['maximumCriticalFalseNegatives'],$global['criticalFalseNegatives'],$policy['maximumCriticalFalseNegatives']),
    glasses_v11_model_evaluation_check('class_regressions','Class regressions',count($classRegressions)<=$policy['maximumClassRegressions'],count($classRegressions),$policy['maximumClassRegressions']),
    glasses_v11_model_evaluation_check('slice_regressions','Slice regressions',count($sliceRegressions)<=$policy['maximumSliceRegressions'],count($sliceRegressions),$policy['maximumSliceRegressions']),
    glasses_v11_model_evaluation_check('slice_coverage','Required slice coverage',count($missingKinds)===0,$missingKinds,[]),
  ];
  $passedCount=count(array_filter($checks,static fn($c)=>$c['passed']));$passed=$passedCount===count($checks);$score=(int)round(100*$passedCount/max(1,count($checks)));
  $datasetSnapshot=['datasetPublicId'=>$run['dataset_public_id'],'datasetHash'=>$run['dataset_hash_snapshot'],'assemblyHash'=>$run['assembly_hash_snapshot'],
    'releaseHash'=>$run['release_hash_snapshot'],'qualificationHash'=>$run['qualification_hash_snapshot'],'testSetHash'=>$testSetHash,'hardExampleSetHash'=>$hardExampleHash];
  $regressions=['classRegressions'=>$classRegressions,'sliceRegressions'=>$sliceRegressions,'missingSliceKinds'=>$missingKinds];
  $result=['passed'=>$passed,'benchmarkEligible'=>$passed,'score'=>$score,'checks'=>$checks,'deltas'=>['map50'=>round($mapDelta,8),'recall'=>round($recallDelta,8),'precision'=>round($precisionDelta,8)]];
  $identity=['schema'=>GLASSES_V11_MODEL_EVALUATION_SCHEMA,'experimentHash'=>$x['experiment_hash'],'runHash'=>$run['run_hash'],
    'championSha256'=>$x['champion_sha'],'challengerSha256'=>$x['challenger_sha'],'datasetSnapshot'=>$datasetSnapshot,
    'policy'=>$policy,'globalMetrics'=>$global,'slices'=>$slices,'confusion'=>$confusion,'regressions'=>$regressions,'result'=>$result];
  $hash=hash('sha256',glasses_vision_training_release_json($identity));
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_benchmarks WHERE organization_id=? AND benchmark_hash=? LIMIT 1");$q->execute([$org,$hash]);$existing=$q->fetchColumn();
  if($existing!==false)return glasses_v11_model_evaluation_row($pdo,$org,(string)$existing);

  $public=glasses_public_id('vision-benchmark');
  $pdo->prepare("INSERT INTO glasses_vision_model_benchmarks
    (organization_id,public_id,experiment_id,training_run_id,champion_package_id,challenger_package_id,status,score,policy_json,dataset_snapshot_json,global_metrics_json,slice_metrics_json,confusion_json,regressions_json,result_json,benchmark_hash,evaluated_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$org,$public,(int)$x['id'],(int)$run['id'],(int)$x['champion_package_id'],(int)$x['challenger_package_id'],$passed?'passed':'failed',$score,
      glasses_vision_training_release_json($policy),glasses_vision_training_release_json($datasetSnapshot),glasses_vision_training_release_json($global),glasses_vision_training_release_json($slices),
      glasses_vision_training_release_json($confusion),glasses_vision_training_release_json($regressions),glasses_vision_training_release_json($result),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'training_run',(string)$run['public_id'],(string)$run['run_hash'],'benchmarked_as','model_benchmark',$public,$hash,['passed'=>$passed,'score'=>$score],$actor);
  glasses_vision_lineage_edge($pdo,$org,'model_experiment',(string)$x['public_id'],(string)$x['experiment_hash'],'validated_by','model_benchmark',$public,$hash,['passed'=>$passed,'score'=>$score],$actor);
  return glasses_v11_model_evaluation_row($pdo,$org,$public);
}

function glasses_v11_model_evaluation_latest_pass(PDO $pdo,int $org,int $experimentId): ?array {
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_benchmarks WHERE organization_id=? AND experiment_id=? AND status='passed' ORDER BY id DESC LIMIT 1");
  $q->execute([$org,$experimentId]);$p=$q->fetchColumn();return $p!==false?glasses_v11_model_evaluation_row($pdo,$org,(string)$p):null;
}

function glasses_v11_model_evaluation_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_model_evaluation_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_MODEL_EVALUATION_SCHEMA,'benchmarks'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_benchmarks WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_MODEL_EVALUATION_SCHEMA,'benchmarks'=>array_map(fn($p)=>glasses_v11_model_evaluation_row($pdo,$org,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN))];
}
