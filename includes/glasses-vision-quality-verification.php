<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-vision-quantity-verification.php';

const GLASSES_VISION_QUALITY_SCHEMA='gelato.vision_quality_verification.v1';

function glasses_vision_quality_policy():array{return [
 'minimumConfidence'=>0.75,'defaultMinCoverage'=>0.65,'defaultMinDistribution'=>0.70,'defaultMaxEdgeOverflow'=>0.12
];}

function glasses_vision_quality_ready(PDO $pdo):bool{
 $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_quality_verifications'");$q->execute();
 return (int)$q->fetchColumn()===1&&glasses_vision_quantity_ready($pdo);
}

function glasses_vision_quality_rule(array $scene,string $componentKey):array{
 $p=glasses_vision_quality_policy();
 foreach((array)($scene['context']['calibration']['regions']??[]) as $r){
   if((string)($r['regionType']??'')!=='build_surface')continue;
   $rules=(array)($r['metadata']['qualityRules']??[]);
   $raw=(array)($rules[$componentKey]??$rules['default']??[]);
   return [
    'sourceRegionKey'=>(string)($r['regionKey']??''),'minCoverage'=>(float)($raw['minCoverage']??$p['defaultMinCoverage']),
    'minDistribution'=>(float)($raw['minDistribution']??$p['defaultMinDistribution']),
    'maxEdgeOverflow'=>(float)($raw['maxEdgeOverflow']??$p['defaultMaxEdgeOverflow'])
   ];
 }
 return ['sourceRegionKey'=>null,'minCoverage'=>$p['defaultMinCoverage'],'minDistribution'=>$p['defaultMinDistribution'],'maxEdgeOverflow'=>$p['defaultMaxEdgeOverflow']];
}

function glasses_vision_quality_assess_scene(array $scene):array{
 $p=glasses_vision_quality_policy();$targets=glasses_vision_ingredient_guard_targets($scene);
 $key=(string)($scene['context']['recipePlan']['currentExpectedComponentKey']??'');
 if(!$targets)return ['state'=>'insufficient','componentKey'=>$key?:null,'score'=>null,'confidence'=>0.0,'reason'=>'product_not_visible','rule'=>null,'measurements'=>[],'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
 if($key==='')return ['state'=>'insufficient','componentKey'=>null,'score'=>null,'confidence'=>0.0,'reason'=>'no_current_component','rule'=>null,'measurements'=>[],'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
 $rule=glasses_vision_quality_rule($scene,$key);$measurements=[];
 foreach((array)($scene['entities']??[]) as $e){
   if((string)($e['kind']??'')!=='ingredient'||(string)($e['componentKey']??'')!==$key)continue;
   $m=(array)($e['attributes']['placementEstimate']??[]);
   if(!$m)continue;
   $confidence=(float)($m['confidence']??0);
   if($confidence<(float)$p['minimumConfidence']||$confidence>1)continue;
   $coverage=(float)($m['coverage']??-1);$distribution=(float)($m['distribution']??-1);$edge=(float)($m['edgeOverflow']??-1);
   if($coverage<0||$coverage>1||$distribution<0||$distribution>1||$edge<0||$edge>1)continue;
   $measurements[]=['entityKey'=>$e['entityKey'],'coverage'=>$coverage,'distribution'=>$distribution,'edgeOverflow'=>$edge,'confidence'=>$confidence,'entityHash'=>$e['entityHash']];
 }
 if(!$measurements)return ['state'=>'insufficient','componentKey'=>$key,'score'=>null,'confidence'=>0.0,'reason'=>'no_quality_evidence','rule'=>$rule,'measurements'=>[],'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
 usort($measurements,static fn($a,$b)=>$b['confidence']<=>$a['confidence']);$m=$measurements[0];
 $fail=[];if($m['coverage']<$rule['minCoverage'])$fail[]='coverage';if($m['distribution']<$rule['minDistribution'])$fail[]='distribution';if($m['edgeOverflow']>$rule['maxEdgeOverflow'])$fail[]='edge_overflow';
 $score=max(0.0,min(1.0,($m['coverage']+$m['distribution']+(1-$m['edgeOverflow']))/3));
 return ['state'=>$fail?'needs_correction':'within_standard','componentKey'=>$key,'score'=>round($score,6),'confidence'=>$m['confidence'],
   'reason'=>$fail?'quality_rule_failed':'quality_rule_passed','failedChecks'=>$fail,'rule'=>$rule,'measurements'=>$measurements,
   'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
}

function glasses_vision_quality_verify_scene(PDO $pdo,int $org,string $scenePublicId):array{
 if(!glasses_vision_quality_ready($pdo))throw new RuntimeException('V9 quality-verification migration is not installed.');
 $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
 if(!glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed'])throw new InvalidArgumentException('Quality verification requires an intact V9 scene.');
 $build=glasses_build_payload($pdo,$org,(string)$scene['buildSessionPublicId']);
 if((string)$build['status']!=='active')throw new InvalidArgumentException('Quality verification requires an active build session.');
 $sceneHash=(string)($scene['context']['buildContextHash']??'');$liveHash=glasses_vision_step_build_context_hash($build);
 if($sceneHash===''||!hash_equals($sceneHash,$liveHash))throw new InvalidArgumentException('Quality verification requires a scene captured from the current canonical build state.');
 $a=glasses_vision_quality_assess_scene($scene);$e=['scene'=>['publicId'=>$scene['publicId'],'sceneHash'=>$scene['sceneHash']],'buildContextHash'=>$sceneHash,'rule'=>$a['rule'],'measurementEntityHashes'=>array_map(static fn($m)=>[$m['entityKey'],$m['entityHash']],$a['measurements'])];
 $r=$a;$material=['evidence'=>$e,'result'=>$r];$hash=hash('sha256',glasses_vision_training_release_json($material));$key=hash('sha256',glasses_vision_training_release_json(['sceneHash'=>$scene['sceneHash'],'policy'=>glasses_vision_quality_policy()]));
 return glasses_transaction($pdo,function()use($pdo,$org,$scene,$a,$e,$r,$hash,$key){
   $q=$pdo->prepare("SELECT public_id,verification_hash FROM glasses_vision_quality_verifications WHERE organization_id=? AND verification_key=? LIMIT 1 FOR UPDATE");$q->execute([$org,$key]);$x=$q->fetch();
   if($x){if(!hash_equals((string)$x['verification_hash'],$hash))throw new InvalidArgumentException('Quality verification identity conflict.');return glasses_vision_quality_row($pdo,$org,(string)$x['public_id']);}
   $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=?");$sq->execute([$org,$scene['publicId']]);$sid=(int)$sq->fetchColumn();
   $bq=$pdo->prepare("SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?");$bq->execute([$org,$scene['buildSessionPublicId']]);$bid=(int)$bq->fetchColumn();
   $public=glasses_public_id('vision-quality');
   $pdo->prepare("INSERT INTO glasses_vision_quality_verifications(organization_id,public_id,scene_snapshot_id,build_session_id,verification_key,state,component_key,score,confidence,evidence_json,result_json,verification_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$org,$public,$sid,$bid,$key,$a['state'],$a['componentKey'],$a['score'],$a['confidence'],glasses_vision_training_release_json($e),glasses_vision_training_release_json($r),$hash]);
   glasses_vision_lineage_edge($pdo,$org,'vision_scene',$scene['publicId'],$scene['sceneHash'],'verified_build_quality_as','quality_verification',$public,$hash,['state'=>$a['state'],'componentKey'=>$a['componentKey'],'score'=>$a['score']],null);
   return glasses_vision_quality_row($pdo,$org,$public);
 });
}
function glasses_vision_quality_row(PDO $pdo,int $org,string $publicId):array{
 $q=$pdo->prepare("SELECT v.*,s.public_id scene_public_id,b.public_id build_public_id FROM glasses_vision_quality_verifications v JOIN glasses_vision_scene_snapshots s ON s.id=v.scene_snapshot_id JOIN glasses_build_sessions b ON b.id=v.build_session_id WHERE v.organization_id=? AND v.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Quality verification not found.');
 return ['publicId'=>$r['public_id'],'scenePublicId'=>$r['scene_public_id'],'buildSessionPublicId'=>$r['build_public_id'],'state'=>$r['state'],'componentKey'=>$r['component_key'],'score'=>$r['score']!==null?(float)$r['score']:null,'confidence'=>(float)$r['confidence'],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'verificationHash'=>$r['verification_hash'],'advisoryOnly'=>true,'createdAt'=>$r['created_at']];
}
function glasses_vision_quality_verify(PDO $pdo,int $org,string $publicId):array{$r=glasses_vision_quality_row($pdo,$org,$publicId);$c=hash('sha256',glasses_vision_training_release_json(['evidence'=>$r['evidence'],'result'=>$r['result']]));return ['passed'=>hash_equals($r['verificationHash'],$c),'publicId'=>$r['publicId'],'verificationHash'=>$r['verificationHash'],'calculatedHash'=>$c];}
function glasses_vision_quality_recent(PDO $pdo,int $org,int $limit=50):array{$q=$pdo->prepare("SELECT public_id FROM glasses_vision_quality_verifications WHERE organization_id=? ORDER BY id DESC LIMIT ".max(1,min(100,$limit)));$q->execute([$org]);$o=[];foreach($q->fetchAll() as $r)$o[]=glasses_vision_quality_row($pdo,$org,(string)$r['public_id']);return $o;}
