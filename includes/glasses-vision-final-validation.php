<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-vision-quality-verification.php';

const GLASSES_VISION_FINAL_SCHEMA='gelato.vision_final_validation.v1';

function glasses_vision_final_policy():array{return [
 'minimumConfidence'=>0.80,'minimumPresentationScore'=>0.82,
 'blockingDefects'=>['foreign_object','contamination','burned','damaged','missing_assembly','wrong_packaging'],
 'warningDefects'=>['messy_edge','uneven_cut','poor_garnish','presentation_variance']
];}

function glasses_vision_final_ready(PDO $pdo):bool{
 $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_final_validations'");$q->execute();
 return (int)$q->fetchColumn()===1&&glasses_vision_quality_ready($pdo);
}

function glasses_vision_final_rule(array $scene):array{
 $p=glasses_vision_final_policy();
 foreach((array)($scene['context']['calibration']['regions']??[]) as $region){
   if((string)($region['regionType']??'')!=='build_surface')continue;
   $raw=(array)($region['metadata']['presentationRules']??[]);
   return [
    'sourceRegionKey'=>(string)($region['regionKey']??''),
    'minimumPresentationScore'=>(float)($raw['minimumPresentationScore']??$p['minimumPresentationScore']),
    'blockingDefects'=>array_values(array_unique(array_map('strval',(array)($raw['blockingDefects']??$p['blockingDefects'])))),
    'warningDefects'=>array_values(array_unique(array_map('strval',(array)($raw['warningDefects']??$p['warningDefects']))))
   ];
 }
 return ['sourceRegionKey'=>null,'minimumPresentationScore'=>$p['minimumPresentationScore'],'blockingDefects'=>$p['blockingDefects'],'warningDefects'=>$p['warningDefects']];
}

function glasses_vision_final_assess_scene(array $scene):array{
 $policy=glasses_vision_final_policy();
 $buildSummary=(array)($scene['context']['buildSession']['summary']??[]);
 $accounted=!empty($buildSummary['accounted']);
 if(!$accounted)return ['state'=>'not_ready_for_final_validation','presentationScore'=>null,'confidence'=>0.0,'reason'=>'canonical_build_not_accounted','defects'=>[],'rule'=>glasses_vision_final_rule($scene),'readyCandidate'=>false,'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
 $products=array_values(array_filter((array)($scene['entities']??[]),static fn($e)=>in_array((string)($e['kind']??''),['product','container'],true)));
 if(!$products)return ['state'=>'insufficient','presentationScore'=>null,'confidence'=>0.0,'reason'=>'product_not_visible','defects'=>[],'rule'=>glasses_vision_final_rule($scene),'readyCandidate'=>false,'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];

 usort($products,static fn($a,$b)=>(float)($b['confidence']??0)<=>(float)($a['confidence']??0));
 $product=$products[0];$estimate=(array)($product['attributes']['presentationEstimate']??[]);
 $confidence=(float)($estimate['confidence']??0);
 if($confidence<(float)$policy['minimumConfidence']||$confidence>1)return ['state'=>'insufficient','presentationScore'=>null,'confidence'=>max(0,min(1,$confidence)),'reason'=>'presentation_evidence_insufficient','defects'=>[],'rule'=>glasses_vision_final_rule($scene),'readyCandidate'=>false,'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
 $score=(float)($estimate['score']??-1);if($score<0||$score>1)return ['state'=>'insufficient','presentationScore'=>null,'confidence'=>$confidence,'reason'=>'presentation_score_missing','defects'=>[],'rule'=>glasses_vision_final_rule($scene),'readyCandidate'=>false,'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];

 $rule=glasses_vision_final_rule($scene);$defects=[];
 foreach((array)($estimate['defects']??[]) as $d){
   if(!is_array($d))continue;$type=mb_strtolower(trim((string)($d['type']??'')),'UTF-8');$dc=(float)($d['confidence']??0);
   if($type===''||$dc<(float)$policy['minimumConfidence']||$dc>1)continue;
   $severity=in_array($type,$rule['blockingDefects'],true)?'blocking':(in_array($type,$rule['warningDefects'],true)?'warning':'observed');
   $defects[]=['type'=>$type,'severity'=>$severity,'confidence'=>round($dc,6)];
 }
 usort($defects,static fn($a,$b)=>strcmp($a['type'],$b['type']));
 $blocking=array_values(array_filter($defects,static fn($d)=>$d['severity']==='blocking'));
 $failedScore=$score<(float)$rule['minimumPresentationScore'];
 $state=($blocking||$failedScore)?'needs_correction':'ready_candidate';
 $reason=$blocking?'blocking_visual_defect':($failedScore?'presentation_score_below_standard':'presentation_standard_met');
 return ['state'=>$state,'presentationScore'=>round($score,6),'confidence'=>round($confidence,6),'reason'=>$reason,'defects'=>$defects,'rule'=>$rule,'readyCandidate'=>$state==='ready_candidate','productEntityKey'=>(string)$product['entityKey'],'advisoryOnly'=>true,'changesBuildState'=>false,'changesKdsState'=>false,'changesPosState'=>false];
}

function glasses_vision_final_validate_scene(PDO $pdo,int $org,string $scenePublicId):array{
 if(!glasses_vision_final_ready($pdo))throw new RuntimeException('V9 final-validation migration is not installed.');
 $scene=glasses_vision_scene_row($pdo,$org,trim($scenePublicId));
 if(!glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed'])throw new InvalidArgumentException('Final validation requires an intact V9 scene.');
 $build=glasses_build_payload($pdo,$org,(string)$scene['buildSessionPublicId']);
 if((string)$build['status']!=='active')throw new InvalidArgumentException('Final validation requires an active build session.');
 $sceneHash=(string)($scene['context']['buildContextHash']??'');$liveHash=glasses_vision_step_build_context_hash($build);
 if($sceneHash===''||!hash_equals($sceneHash,$liveHash))throw new InvalidArgumentException('Final validation requires a scene captured from the current canonical build state.');
 $a=glasses_vision_final_assess_scene($scene);
 $e=['scene'=>['publicId'=>$scene['publicId'],'sceneHash'=>$scene['sceneHash'],'sourceFingerprint'=>$scene['sourceFingerprint']],'buildContextHash'=>$sceneHash,'buildSummary'=>$scene['context']['buildSession']['summary']??null,'rule'=>$a['rule'],'productEntityKey'=>$a['productEntityKey']??null];
 $material=['evidence'=>$e,'result'=>$a];$hash=hash('sha256',glasses_vision_training_release_json($material));$key=hash('sha256',glasses_vision_training_release_json(['sceneHash'=>$scene['sceneHash'],'policy'=>glasses_vision_final_policy()]));
 return glasses_transaction($pdo,function()use($pdo,$org,$scene,$a,$e,$hash,$key){
  $q=$pdo->prepare("SELECT public_id,validation_hash FROM glasses_vision_final_validations WHERE organization_id=? AND validation_key=? LIMIT 1 FOR UPDATE");$q->execute([$org,$key]);$x=$q->fetch();
  if($x){if(!hash_equals((string)$x['validation_hash'],$hash))throw new InvalidArgumentException('Final-validation identity conflict.');return glasses_vision_final_row($pdo,$org,(string)$x['public_id']);}
  $sq=$pdo->prepare("SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=?");$sq->execute([$org,$scene['publicId']]);$sid=(int)$sq->fetchColumn();
  $bq=$pdo->prepare("SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?");$bq->execute([$org,$scene['buildSessionPublicId']]);$bid=(int)$bq->fetchColumn();
  $public=glasses_public_id('vision-final');
  $pdo->prepare("INSERT INTO glasses_vision_final_validations(organization_id,public_id,scene_snapshot_id,build_session_id,validation_key,state,presentation_score,confidence,evidence_json,result_json,validation_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([$org,$public,$sid,$bid,$key,$a['state'],$a['presentationScore'],$a['confidence'],glasses_vision_training_release_json($e),glasses_vision_training_release_json($a),$hash]);
  glasses_vision_lineage_edge($pdo,$org,'vision_scene',$scene['publicId'],$scene['sceneHash'],'validated_final_product_as','final_product_validation',$public,$hash,['state'=>$a['state'],'presentationScore'=>$a['presentationScore'],'readyCandidate'=>$a['readyCandidate']],null);
  return glasses_vision_final_row($pdo,$org,$public);
 });
}
function glasses_vision_final_row(PDO $pdo,int $org,string $publicId):array{
 $q=$pdo->prepare("SELECT v.*,s.public_id scene_public_id,b.public_id build_public_id FROM glasses_vision_final_validations v JOIN glasses_vision_scene_snapshots s ON s.id=v.scene_snapshot_id JOIN glasses_build_sessions b ON b.id=v.build_session_id WHERE v.organization_id=? AND v.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Final product validation not found.');
 return ['publicId'=>$r['public_id'],'scenePublicId'=>$r['scene_public_id'],'buildSessionPublicId'=>$r['build_public_id'],'state'=>$r['state'],'presentationScore'=>$r['presentation_score']!==null?(float)$r['presentation_score']:null,'confidence'=>(float)$r['confidence'],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'validationHash'=>$r['validation_hash'],'readyCandidate'=>(bool)((json_decode((string)$r['result_json'],true)?:[])['readyCandidate']??false),'advisoryOnly'=>true,'createdAt'=>$r['created_at']];
}
function glasses_vision_final_verify(PDO $pdo,int $org,string $publicId):array{$r=glasses_vision_final_row($pdo,$org,$publicId);$c=hash('sha256',glasses_vision_training_release_json(['evidence'=>$r['evidence'],'result'=>$r['result']]));return ['passed'=>hash_equals($r['validationHash'],$c),'publicId'=>$r['publicId'],'validationHash'=>$r['validationHash'],'calculatedHash'=>$c];}
function glasses_vision_final_recent(PDO $pdo,int $org,int $limit=50):array{$q=$pdo->prepare("SELECT public_id FROM glasses_vision_final_validations WHERE organization_id=? ORDER BY id DESC LIMIT ".max(1,min(100,$limit)));$q->execute([$org]);$o=[];foreach($q->fetchAll() as $r)$o[]=glasses_vision_final_row($pdo,$org,(string)$r['public_id']);return $o;}
