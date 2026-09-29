<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-retraining.php';

function v74_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v74_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}
$pdo=app_pdo();
v74_assert(glasses_vision_retraining_ready($pdo),'V7 retraining candidate migration must be installed.');

$slug='vl74-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Retraining '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Retraining','Reviewer','Retraining Reviewer']);$actor=(int)$pdo->lastInsertId();

$runPublic='vision-mining-run-'.$slug;$runHash=hash('sha256','run-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_mining_runs (organization_id,public_id,source_fingerprint,policy_json,result_json,run_hash,created_by) VALUES (?,?,?,'{}','{}',?,?)")
 ->execute([$org,$runPublic,hash('sha256','source-'.$slug),$runHash,$actor]);$runId=(int)$pdo->lastInsertId();

$root=glasses_vision_training_media_storage_root();$dir=$root.'/'.$org.'/fixture';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Could not create retraining fixture storage.');

$makeSample=function(string $suffix,?string $label,string $capture,string $bytes,bool $withMedia=true,string $quality='good')use($pdo,$org,$actor,$slug,$dir):array{
 $samplePublic='vision-sample-'.$slug.'-'.$suffix;
 $pdo->prepare("INSERT INTO glasses_vision_training_samples (organization_id,public_id,source_type,source_reference,review_status,review_outcome,canonical_label,annotation_json,created_by,reviewer_user_id,reviewed_at) VALUES (?,?,'governed_training_media',?,'approved','approve',?,'[]',?,?,NOW(6))")
  ->execute([$org,$samplePublic,'fixture-'.$suffix,$label,$actor,$actor]);$sampleId=(int)$pdo->lastInsertId();
 if(!$withMedia)return ['sampleId'=>$sampleId,'samplePublic'=>$samplePublic,'mediaId'=>null,'mediaPublic'=>null,'sha'=>null,'path'=>null];
 $mediaPublic='vision-media-'.$slug.'-'.$suffix;$path=$dir.'/'.$mediaPublic.'.bin';file_put_contents($path,$bytes);$sha=hash('sha256',$bytes);
 $relative=$org.'/fixture/'.$mediaPublic.'.bin';
 $pdo->prepare("INSERT INTO glasses_vision_training_media
  (organization_id,public_id,sample_id,capture_group,source_type,storage_relative_path,mime_type,byte_size,width,height,sha256,quality_state,quality_flags_json,annotation_json,metadata_json,consent_basis,status,created_by)
  VALUES (?,?,?,?,'fixture',?,'image/png',?,64,64,?,?,'[]','[]','{}','training_media_opt_in','active',?)")
  ->execute([$org,$mediaPublic,$sampleId,$capture,$relative,strlen($bytes),$sha,$quality,$actor]);$mediaId=(int)$pdo->lastInsertId();
 return ['sampleId'=>$sampleId,'samplePublic'=>$samplePublic,'mediaId'=>$mediaId,'mediaPublic'=>$mediaPublic,'sha'=>$sha,'path'=>$path];
};

$a=$makeSample('a','ingredient:pepperoni','capture-1','fixture-image-A-'.$slug);
$b=$makeSample('b','ingredient:pepperoni','capture-2','fixture-image-A-'.$slug);
$c=$makeSample('c','ingredient:pepperoni','capture-1','fixture-image-C-'.$slug);
$d=$makeSample('d','ingredient:sausage','capture-4','fixture-image-D-'.$slug);
$e=$makeSample('e','ingredient:pepperoni','capture-5','fixture-image-E-'.$slug,false);

$insertError=$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))");
$insertCandidate=$pdo->prepare("INSERT INTO glasses_vision_mined_candidates
 (organization_id,public_id,mining_run_id,production_error_id,candidate_key,candidate_hash,candidate_type,score,status,cluster_key,predicted_component_key,expected_component_key,reasons_json)
 VALUES (?,?,?,?,?,?,?,?, 'open',?,?,?,'{}')");
$fixtures=[
 ['a',$a,'misclassification','ingredient:sausage','ingredient:pepperoni',99],
 ['b',$b,'misclassification','ingredient:sausage','ingredient:pepperoni',98],
 ['c',$c,'misclassification','ingredient:sausage','ingredient:pepperoni',97],
 ['d',$d,'misclassification','ingredient:sausage','ingredient:pepperoni',96],
 ['e',$e,'false_negative',null,'ingredient:pepperoni',95],
];
foreach($fixtures as [$suffix,$s,$type,$pred,$expected,$score]){
 $ep='vision-prod-error-'.$slug.'-'.$suffix;$eh=hash('sha256','error-'.$slug.'-'.$suffix);
 $ctx=['schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA,'context'=>['samplePublicId'=>$s['samplePublic'],'mediaPublicId'=>$s['mediaPublic']]];
 $insertError->execute([$org,$ep,'event-'.$slug.'-'.$suffix,$eh,'fixture',$type,$type==='false_negative'?'missed':'reclassified',$pred,$expected,.9,json_encode($ctx,JSON_UNESCAPED_SLASHES),$actor]);$errorId=(int)$pdo->lastInsertId();
 $candidatePublic='vision-mined-'.$slug.'-'.$suffix;$candidateHash=hash('sha256','candidate-'.$slug.'-'.$suffix);
 $insertCandidate->execute([$org,$candidatePublic,$runId,$errorId,hash('sha256','key-'.$slug.'-'.$suffix),$candidateHash,$type==='false_negative'?'missed_positive':'misclassification',$score,hash('sha256','cluster-'.$suffix),$pred,$expected]);
}

$batch=glasses_vision_retraining_prepare($pdo,$org,$runPublic,[],$actor);
v74_assert($batch['counts']['eligible']===1&&$batch['counts']['ineligible']===4,'Builder must admit only traceable, deduplicated, ground-truth-compatible retained media.');
$reasons=array_column($batch['items'],'eligibilityReason');
v74_assert(in_array('duplicate_media_sha256',$reasons,true),'Exact media SHA duplicates must be suppressed.');
v74_assert(in_array('duplicate_capture_group',$reasons,true),'Capture-group duplicates must be suppressed.');
v74_assert(in_array('ground_truth_does_not_match_expected_component',$reasons,true),'Mismatched approved ground truth must be ineligible.');
v74_assert(in_array('missing_active_training_media',$reasons,true),'Unretained production evidence must not enter retraining.');
v74_assert(!str_contains(json_encode($batch['manifest'],JSON_UNESCAPED_SLASHES),(string)$org.'/fixture/'),'Retraining manifest must not expose private storage paths.');

$blocked=false;try{glasses_vision_retraining_build_dataset($pdo,$org,$batch['publicId'],'Retraining Corpus','v1',$actor);}catch(InvalidArgumentException){$blocked=true;}
v74_assert($blocked,'Dataset build must be blocked until every eligible candidate is explicitly reviewed.');

$eligible=array_values(array_filter($batch['items'],static fn($i)=>$i['eligibility']==='eligible'))[0];
$batch=glasses_vision_retraining_review($pdo,$org,$batch['publicId'],$eligible['publicId'],'include','Confirmed production correction for retraining.',$actor);
v74_assert($batch['counts']['include']===1&&$batch['counts']['pending']===0,'Explicit include review must be persisted.');

$original=file_get_contents($a['path']);file_put_contents($a['path'],'tampered');
$tampered=false;try{glasses_vision_retraining_build_dataset($pdo,$org,$batch['publicId'],'Retraining Corpus','v1',$actor);}catch(InvalidArgumentException){$tampered=true;}
v74_assert($tampered,'Dataset build must fail closed when retained media bytes no longer match the governed SHA-256.');
file_put_contents($a['path'],$original);

$result=glasses_vision_retraining_build_dataset($pdo,$org,$batch['publicId'],'Retraining Corpus','v1',$actor);
$dataset=$result['dataset'];
v74_assert($dataset['status']==='draft'&&$dataset['itemCount']===1,'Retraining builder must create only a normal draft dataset containing explicitly included samples.');
v74_assert((int)v74_one($pdo,"SELECT COUNT(*) FROM glasses_vision_dataset_items di JOIN glasses_vision_training_samples s ON s.id=di.sample_id WHERE di.organization_id=? AND di.dataset_id=(SELECT id FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=?) AND s.public_id=?",[$org,$org,$dataset['publicId'],$a['samplePublic']])===1,'Draft candidate dataset must contain the exact reviewed sample.');
v74_assert((int)v74_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='mining_run' AND from_public_id=? AND relation='built_candidate_dataset' AND to_kind='dataset' AND to_public_id=?",[$org,$runPublic,$dataset['publicId']])===1,'Candidate dataset must retain lineage to the exact mining run.');
v74_assert((int)v74_one($pdo,"SELECT COUNT(*) FROM glasses_vision_dataset_split_plans WHERE organization_id=? AND dataset_id=(SELECT id FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=?)",[$org,$org,$dataset['publicId']])===0,'Retraining builder must not fabricate or bypass the V6 governed split plan.');

$again=glasses_vision_retraining_build_dataset($pdo,$org,$batch['publicId'],'Ignored Name','ignored',$actor);
v74_assert($again['dataset']['publicId']===$dataset['publicId'],'Built batch must be idempotent and return the original candidate dataset.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-retraining.php');
foreach(['retraining_candidate.prepare','retraining_candidate.review','retraining_candidate.build_dataset'] as $action)v74_assert(str_contains($api,$action),'V7 retraining API missing '.$action);
v74_assert(str_contains($page,'Retraining Candidate Builder'),'Vision Lab must expose Section 4 retraining candidate controls.');
v74_assert(!str_contains($source,'kds_transition')&&!str_contains($source,'rollout.activate')&&!str_contains($source,"status='frozen'"),'Retraining candidate builder must not mutate kitchen truth, rollouts, or freeze datasets.');

foreach([$a,$b,$c,$d] as $s)if($s['path']&&is_file($s['path']))@unlink($s['path']);
@rmdir($dir);
echo "vision-lab-v7-retraining-candidate-builder-ok\n";
