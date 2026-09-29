<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-experiments.php';
require_once __DIR__.'/glasses-v11-model-evaluation.php';

const GLASSES_VISION_EVIDENCE_REVIEW_SCHEMA='gelato.vision_champion_challenger_review.v1';

function glasses_vision_evidence_review_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_model_evidence_reviews'");
    $q->execute();
    return (int)$q->fetchColumn()===1&&glasses_vision_experiment_ready($pdo);
}

function glasses_vision_evidence_review_sha(string $value,string $label): string
{
    $value=strtolower(trim($value));
    if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new InvalidArgumentException($label.' SHA-256 is invalid.');
    return $value;
}

function glasses_vision_evidence_review_policy(): array
{
    return [
      'requireGoldenEligible'=>true,
      'requireDemonstratedGain'=>true,
      'minimumMap50Delta'=>0.0,
      'minimumRecallDelta'=>0.0,
      'minimumFailureCases'=>20,
      'minimumFailureAccuracyDelta'=>0.0,
      'maximumCriticalRegressions'=>0,
      'maximumClassRegressions'=>0,
      'maximumLatencyRegressionRatio'=>0.20,
      'maximumTimeoutRegression'=>0,
      'minimumRuntimeSamples'=>20,
    ];
}

function glasses_vision_evidence_review_normalize_failure(array $in): array
{
    $total=max(0,(int)($in['totalCases']??0));$champ=max(0,(int)($in['championCorrect']??0));$chall=max(0,(int)($in['challengerCorrect']??0));
    if($champ>$total||$chall>$total)throw new InvalidArgumentException('Failure-set correct counts cannot exceed total cases.');
    $classes=array_values(array_map(static fn($v)=>mb_substr(trim((string)$v),0,190,'UTF-8'),array_filter((array)($in['classRegressions']??[]),static fn($v)=>trim((string)$v)!=='')));
    sort($classes,SORT_STRING);
    return [
      'schema'=>'gelato.vision_failure_challenge_evaluation.v1',
      'evaluationHash'=>glasses_vision_evidence_review_sha((string)($in['evaluationHash']??''),'Failure evaluation'),
      'sourceFingerprint'=>glasses_vision_evidence_review_sha((string)($in['sourceFingerprint']??''),'Failure source fingerprint'),
      'totalCases'=>$total,'championCorrect'=>$champ,'challengerCorrect'=>$chall,
      'criticalRegressions'=>max(0,(int)($in['criticalRegressions']??0)),
      'classRegressions'=>$classes,
    ];
}

function glasses_vision_evidence_review_normalize_runtime(array $in): array
{
    $champ=(float)($in['championLatencyMs']??0);$chall=(float)($in['challengerLatencyMs']??0);
    if(!is_finite($champ)||!is_finite($chall)||$champ<=0||$chall<=0)throw new InvalidArgumentException('Runtime evidence requires positive finite champion/challenger latency.');
    return [
      'schema'=>'gelato.vision_runtime_comparison.v1',
      'evaluationHash'=>glasses_vision_evidence_review_sha((string)($in['evaluationHash']??''),'Runtime evaluation'),
      'championLatencyMs'=>round($champ,4),'challengerLatencyMs'=>round($chall,4),
      'championTimeouts'=>max(0,(int)($in['championTimeouts']??0)),
      'challengerTimeouts'=>max(0,(int)($in['challengerTimeouts']??0)),
      'sampleCount'=>max(0,(int)($in['sampleCount']??0)),
    ];
}

function glasses_vision_evidence_review_check(string $key,string $label,bool $passed,mixed $actual,mixed $required): array
{
    return ['key'=>$key,'label'=>$label,'passed'=>$passed,'actual'=>$actual,'required'=>$required];
}

function glasses_vision_evidence_review_run(PDO $pdo,int $org,string $experimentPublic,array $input,int $actor): array
{
    if(!glasses_vision_evidence_review_ready($pdo))throw new RuntimeException('Vision Lab V7 evidence-review migration is not installed.');
    $x=glasses_vision_experiment_row($pdo,$org,$experimentPublic,false);
    if((string)$x['status']!=='completed'||$x['challenger_package_id']===null)throw new InvalidArgumentException('Evidence review requires a completed model experiment.');
    $v11Benchmark=null;
    if($x['training_run_id']!==null){
      $rq=$pdo->prepare("SELECT run_hash FROM glasses_vision_training_runs WHERE organization_id=? AND id=? LIMIT 1");$rq->execute([$org,(int)$x['training_run_id']]);$runHash=$rq->fetchColumn();
      if(is_string($runHash)&&$runHash!==''){
        if(!glasses_v11_model_evaluation_ready($pdo))throw new InvalidArgumentException('V11 evidence review requires the model benchmark migration.');
        $v11Benchmark=glasses_v11_model_evaluation_latest_pass($pdo,$org,(int)$x['id']);
        if($v11Benchmark===null)throw new InvalidArgumentException('V11 evidence review requires a passing model benchmark.');
      }
    }

    $champion=glasses_vision_model_package_row($pdo,$org,(string)$x['champion_public_id'],false);
    $challenger=glasses_vision_model_package_row($pdo,$org,(string)$x['challenger_public_id'],false);
    $champMeta=json_decode((string)($champion['metadata_json']??'null'),true)?:[];
    $challMeta=json_decode((string)($challenger['metadata_json']??'null'),true)?:[];
    $comparison=glasses_vision_model_comparison_metadata($challMeta);
    if($comparison===null)throw new InvalidArgumentException('Challenger model is missing governed golden-test comparison metadata.');

    $failure=glasses_vision_evidence_review_normalize_failure(is_array($input['failureEvaluation']??null)?$input['failureEvaluation']:[]);
    $runtime=glasses_vision_evidence_review_normalize_runtime(is_array($input['runtimeEvaluation']??null)?$input['runtimeEvaluation']:[]);
    $policy=glasses_vision_evidence_review_policy();

    $champAcc=$failure['totalCases']>0?$failure['championCorrect']/$failure['totalCases']:0.0;
    $challAcc=$failure['totalCases']>0?$failure['challengerCorrect']/$failure['totalCases']:0.0;
    $failureDelta=$challAcc-$champAcc;
    $latencyRatio=($runtime['challengerLatencyMs']-$runtime['championLatencyMs'])/$runtime['championLatencyMs'];
    $timeoutDelta=$runtime['challengerTimeouts']-$runtime['championTimeouts'];

    $checks=[
      glasses_vision_evidence_review_check('golden_eligible','Golden comparison eligibility',!empty($comparison['eligible']),$comparison['eligible'],true),
      glasses_vision_evidence_review_check('golden_override','Golden comparison has no manual override',empty($comparison['override']),$comparison['override'],false),
      glasses_vision_evidence_review_check('golden_gain','Golden test demonstrated gain',!empty($comparison['summary']['demonstratedGain']),$comparison['summary']['demonstratedGain'],true),
      glasses_vision_evidence_review_check('map50_regression','mAP50 delta',(float)$comparison['summary']['map50Delta']>=$policy['minimumMap50Delta'],(float)$comparison['summary']['map50Delta'],'>= '.$policy['minimumMap50Delta']),
      glasses_vision_evidence_review_check('recall_regression','Recall delta',(float)$comparison['summary']['recallDelta']>=$policy['minimumRecallDelta'],(float)$comparison['summary']['recallDelta'],'>= '.$policy['minimumRecallDelta']),
      glasses_vision_evidence_review_check('golden_regressions','Golden regression list',count($comparison['regressions'])===0,count($comparison['regressions']),0),
      glasses_vision_evidence_review_check('failure_case_count','Production failure challenge-set size',$failure['totalCases']>=$policy['minimumFailureCases'],$failure['totalCases'],'>= '.$policy['minimumFailureCases']),
      glasses_vision_evidence_review_check('failure_accuracy','Production failure accuracy delta',$failureDelta>=$policy['minimumFailureAccuracyDelta'],round($failureDelta,6),'>= '.$policy['minimumFailureAccuracyDelta']),
      glasses_vision_evidence_review_check('critical_regressions','Safety-critical regressions',$failure['criticalRegressions']<=$policy['maximumCriticalRegressions'],$failure['criticalRegressions'],$policy['maximumCriticalRegressions']),
      glasses_vision_evidence_review_check('class_regressions','Class-level regressions',count($failure['classRegressions'])<=$policy['maximumClassRegressions'],count($failure['classRegressions']),$policy['maximumClassRegressions']),
      glasses_vision_evidence_review_check('runtime_samples','Runtime comparison sample count',$runtime['sampleCount']>=$policy['minimumRuntimeSamples'],$runtime['sampleCount'],'>= '.$policy['minimumRuntimeSamples']),
      glasses_vision_evidence_review_check('latency_regression','Latency regression ratio',$latencyRatio<=$policy['maximumLatencyRegressionRatio'],round($latencyRatio,6),'<= '.$policy['maximumLatencyRegressionRatio']),
      glasses_vision_evidence_review_check('timeout_regression','Timeout regression',$timeoutDelta<=$policy['maximumTimeoutRegression'],$timeoutDelta,'<= '.$policy['maximumTimeoutRegression']),
    ];
    $passedCount=count(array_filter($checks,static fn($c)=>$c['passed']));
    $passed=$passedCount===count($checks);$score=(int)round(100*$passedCount/max(1,count($checks)));

    $evidence=[
      'schema'=>GLASSES_VISION_EVIDENCE_REVIEW_SCHEMA,
      'experimentPublicId'=>$x['public_id'],'experimentHash'=>$x['experiment_hash'],
      'champion'=>['publicId'=>$champion['public_id'],'artifactSha256'=>$champion['artifact_sha256']],
      'challenger'=>['publicId'=>$challenger['public_id'],'artifactSha256'=>$challenger['artifact_sha256']],
      'goldenComparison'=>$comparison,'failureEvaluation'=>$failure,'runtimeEvaluation'=>$runtime,'v11Benchmark'=>$v11Benchmark,
    ];
    $result=['policy'=>$policy,'checks'=>$checks,'score'=>$score,'passed'=>$passed,'promotionEligible'=>$passed];
    $reviewHash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$evidence,'result'=>$result]));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_evidence_reviews WHERE organization_id=? AND review_hash=? LIMIT 1");
    $q->execute([$org,$reviewHash]);$existing=$q->fetchColumn();
    if($existing!==false)return glasses_vision_evidence_review_row($pdo,$org,(string)$existing);

    $public=glasses_public_id('vision-evidence-review');
    $pdo->prepare("INSERT INTO glasses_vision_model_evidence_reviews
      (organization_id,public_id,experiment_id,status,score,policy_json,evidence_json,result_json,review_hash,reviewed_by)
      VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$public,(int)$x['id'],$passed?'passed':'failed',$score,glasses_vision_training_release_json($policy),glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($result),$reviewHash,$actor]);
    glasses_vision_lineage_edge($pdo,$org,'model_experiment',(string)$x['public_id'],(string)$x['experiment_hash'],'evidence_reviewed_as','evidence_review',$public,$reviewHash,['passed'=>$passed,'score'=>$score],$actor);
    return glasses_vision_evidence_review_row($pdo,$org,$public);
}

function glasses_vision_evidence_review_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT r.*,x.public_id experiment_public_id,x.experiment_hash FROM glasses_vision_model_evidence_reviews r
      JOIN glasses_vision_model_experiments x ON x.id=r.experiment_id AND x.organization_id=r.organization_id
      WHERE r.organization_id=? AND r.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Model evidence review was not found.');
    return [
      'schema'=>GLASSES_VISION_EVIDENCE_REVIEW_SCHEMA,'publicId'=>$r['public_id'],'experimentPublicId'=>$r['experiment_public_id'],
      'experimentHash'=>$r['experiment_hash'],'status'=>$r['status'],'score'=>(int)$r['score'],'reviewHash'=>$r['review_hash'],
      'policy'=>json_decode((string)$r['policy_json'],true)?:[],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_evidence_review_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_evidence_review_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_EVIDENCE_REVIEW_SCHEMA,'reviews'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_evidence_reviews WHERE organization_id=? ORDER BY id DESC LIMIT 50");$q->execute([$org]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_evidence_review_row($pdo,$org,(string)$p);
    return ['ready'=>true,'schema'=>GLASSES_VISION_EVIDENCE_REVIEW_SCHEMA,'reviews'=>$out];
}
