<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-production-evidence.php';
require_once __DIR__.'/glasses-vision-annotation-qa.php';

const GLASSES_V11_ANNOTATION_CORRECTION_SCHEMA='gelato.glasses_annotation_correction.v1';

function glasses_v11_annotation_ready(PDO $pdo): bool {
  foreach(['glasses_vision_annotation_corrections','glasses_vision_annotation_correction_events','glasses_vision_sample_reviews'] as $t){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if((int)$q->fetchColumn()!==1)return false;
  } return glasses_v11_evidence_ready($pdo);
}
function glasses_v11_annotation_event(PDO $pdo,int $org,int $id,string $type,?int $actor,array $evidence=[]): void {
  $pdo->prepare("INSERT INTO glasses_vision_annotation_correction_events (organization_id,correction_id,event_type,actor_user_id,evidence_json) VALUES (?,?,?,?,?)")
    ->execute([$org,$id,$type,$actor,json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}
function glasses_v11_annotation_row(PDO $pdo,int $org,string $public,bool $forUpdate=false): array {
  $q=$pdo->prepare("SELECT c.*,m.public_id media_public_id,s.public_id sample_public_id,u.display_name submitter_name,rv.display_name reviewer_name,ad.display_name adjudicator_name
    FROM glasses_vision_annotation_corrections c
    JOIN glasses_vision_training_media m ON m.id=c.media_id
    JOIN glasses_vision_training_samples s ON s.id=c.sample_id
    JOIN users u ON u.id=c.submitted_by
    LEFT JOIN users rv ON rv.id=c.reviewed_by
    LEFT JOIN users ad ON ad.id=c.adjudicated_by
    WHERE c.organization_id=? AND c.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Annotation correction not found.');return $r;
}
function glasses_v11_annotation_public(array $r): array {
  return [
    'schema'=>GLASSES_V11_ANNOTATION_CORRECTION_SCHEMA,'publicId'=>$r['public_id'],'correctionKey'=>$r['correction_key'],
    'mediaPublicId'=>$r['media_public_id'],'samplePublicId'=>$r['sample_public_id'],'correctionType'=>$r['correction_type'],
    'previousLabel'=>$r['previous_label'],'proposedLabel'=>$r['proposed_label'],
    'previousAnnotations'=>json_decode((string)($r['previous_annotation_json']??'[]'),true)?:[],
    'proposedAnnotations'=>json_decode((string)($r['proposed_annotation_json']??'[]'),true)?:[],
    'previousRecipeStepKey'=>$r['previous_recipe_step_key'],'proposedRecipeStepKey'=>$r['proposed_recipe_step_key'],
    'reason'=>$r['reason'],'status'=>$r['status'],'submitterName'=>$r['submitter_name'],
    'reviewerName'=>$r['reviewer_name'],'adjudicatorName'=>$r['adjudicator_name'],'immutableHash'=>$r['immutable_hash'],
    'submittedAt'=>$r['submitted_at'],'reviewedAt'=>$r['reviewed_at'],'adjudicatedAt'=>$r['adjudicated_at']
  ];
}
function glasses_v11_annotation_validate_annotations(array $annotations): array {
  return glasses_vision_training_media_annotation_check($annotations)['annotations'];
}
function glasses_v11_annotation_submit(PDO $pdo,int $org,array $in,int $actor): array {
  if(!glasses_v11_annotation_ready($pdo))throw new RuntimeException('V11 annotation correction migration is not installed.');
  $evidence=glasses_v11_production_evidence_get($pdo,$org,(string)($in['evidencePublicId']??''));
  $q=$pdo->prepare("SELECT m.id media_id,m.sample_id,m.recipe_step_key,s.canonical_label,s.annotation_json FROM glasses_vision_training_media m JOIN glasses_vision_training_samples s ON s.id=m.sample_id WHERE m.organization_id=? AND m.public_id=? LIMIT 1");
  $q->execute([$org,$evidence['publicId']]);$base=$q->fetch();if(!$base)throw new InvalidArgumentException('Evidence sample is unavailable.');
  $type=(string)($in['correctionType']??'combined');
  if(!in_array($type,['label','bbox','false_positive','false_negative','step','ingredient','combined'],true))throw new InvalidArgumentException('Correction type is invalid.');
  $reason=mb_substr(trim((string)($in['reason']??'')),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Correction reason is required.');
  $label=mb_substr(trim((string)($in['proposedLabel']??'')),0,190,'UTF-8')?:null;
  $annotations=glasses_v11_annotation_validate_annotations(is_array($in['proposedAnnotations']??null)?$in['proposedAnnotations']:[]);
  if($type==='false_positive'){$label='__negative__';$annotations=[];}
  if($type==='false_negative'&&$label===null)throw new InvalidArgumentException('False-negative correction requires the missed label.');
  $step=mb_substr(trim((string)($in['proposedRecipeStepKey']??'')),0,160,'UTF-8')?:null;
  $snapshot=[
    'schema'=>GLASSES_V11_ANNOTATION_CORRECTION_SCHEMA,'mediaPublicId'=>$evidence['publicId'],'samplePublicId'=>$evidence['samplePublicId'],
    'type'=>$type,'previousLabel'=>$base['canonical_label'],'proposedLabel'=>$label,
    'previousAnnotations'=>json_decode((string)($base['annotation_json']??'[]'),true)?:[],'proposedAnnotations'=>$annotations,
    'previousRecipeStepKey'=>$base['recipe_step_key'],'proposedRecipeStepKey'=>$step,'reason'=>$reason,'submittedBy'=>$actor
  ];
  $hash=hash('sha256',glasses_vision_training_release_json($snapshot));
  $key=mb_substr(trim((string)($in['correctionKey']??'')),0,190,'UTF-8')?:('v11-correction-'.$hash);
  return glasses_transaction($pdo,function()use($pdo,$org,$actor,$base,$snapshot,$hash,$key,$type,$label,$annotations,$step,$reason):array{
    $q=$pdo->prepare("SELECT public_id,immutable_hash FROM glasses_vision_annotation_corrections WHERE organization_id=? AND correction_key=? LIMIT 1 FOR UPDATE");
    $q->execute([$org,$key]);$existing=$q->fetch();
    if($existing){if(!hash_equals((string)$existing['immutable_hash'],$hash))throw new InvalidArgumentException('Correction key already exists with different immutable evidence.');return glasses_v11_annotation_public(glasses_v11_annotation_row($pdo,$org,(string)$existing['public_id']));}
    $public=glasses_public_id('vision-correction');
    $pdo->prepare("INSERT INTO glasses_vision_annotation_corrections
      (organization_id,public_id,media_id,sample_id,correction_key,correction_type,previous_label,proposed_label,previous_annotation_json,proposed_annotation_json,previous_recipe_step_key,proposed_recipe_step_key,reason,status,submitted_by,immutable_hash)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'submitted',?,?)")->execute([
        $org,$public,(int)$base['media_id'],(int)$base['sample_id'],$key,$type,$base['canonical_label'],$label,$base['annotation_json'],
        json_encode($annotations,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$base['recipe_step_key'],$step,$reason,$actor,$hash
      ]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE glasses_vision_training_samples SET review_status='pending',annotator_user_id=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$base['sample_id']]);
    glasses_v11_annotation_event($pdo,$org,$id,'submitted',$actor,$snapshot);
    return glasses_v11_annotation_public(glasses_v11_annotation_row($pdo,$org,$public));
  });
}
function glasses_v11_annotation_review(PDO $pdo,int $org,string $public,string $decision,string $notes,int $actor): array {
  if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Review decision is invalid.');
  return glasses_transaction($pdo,function()use($pdo,$org,$public,$decision,$notes,$actor):array{
    $c=glasses_v11_annotation_row($pdo,$org,$public,true);
    if(!in_array($c['status'],['submitted','needs_adjudication'],true))throw new InvalidArgumentException('Correction is not reviewable.');
    if((int)$c['submitted_by']===$actor)throw new InvalidArgumentException('Submitter cannot review their own correction.');
    $pdo->prepare("INSERT INTO glasses_vision_sample_reviews (organization_id,sample_id,correction_id,reviewer_user_id,decision,canonical_label,notes)
      VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision),canonical_label=VALUES(canonical_label),notes=VALUES(notes),created_at=NOW(6)")
      ->execute([$org,(int)$c['sample_id'],(int)$c['id'],$actor,$decision,$c['proposed_label'],mb_substr(trim($notes),0,1000,'UTF-8')?:null]);
    $q=$pdo->prepare("SELECT decision,COUNT(*) c FROM glasses_vision_sample_reviews WHERE organization_id=? AND sample_id=? AND correction_id=? GROUP BY decision");$q->execute([$org,(int)$c['sample_id'],(int)$c['id']]);
    $counts=[];foreach($q->fetchAll() as $r)$counts[$r['decision']]=(int)$r['c'];
    $approve=$counts['approve']??0;$reject=$counts['reject']??0;$reviewCount=$approve+$reject;
    if($approve>0&&$reject>0)$next='needs_adjudication';
    elseif($reviewCount>=2&&$approve===$reviewCount)$next='approved';
    elseif($reviewCount>=2&&$reject===$reviewCount)$next='rejected';
    else $next='submitted';
    $pdo->prepare("UPDATE glasses_vision_annotation_corrections SET status=?,reviewed_by=?,reviewed_at=NOW(6) WHERE organization_id=? AND id=?")
      ->execute([$next,$actor,$org,(int)$c['id']]);
    if($next==='approved')glasses_v11_annotation_apply($pdo,$org,(int)$c['id'],$actor,false);
    elseif($next==='rejected')$pdo->prepare("UPDATE glasses_vision_training_samples SET reviewer_user_id=?,review_status='rejected',review_outcome='rejected',reviewed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$c['sample_id']]);
    elseif($next==='needs_adjudication')$pdo->prepare("UPDATE glasses_vision_training_samples SET review_status='needs_adjudication',updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$c['sample_id']]);
    else $pdo->prepare("UPDATE glasses_vision_training_samples SET reviewer_user_id=?,review_status='pending',updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$c['sample_id']]);
    glasses_v11_annotation_event($pdo,$org,(int)$c['id'],'reviewed',$actor,['decision'=>$decision,'status'=>$next,'notes'=>$notes]);
    return glasses_v11_annotation_public(glasses_v11_annotation_row($pdo,$org,$public));
  });
}
function glasses_v11_annotation_apply(PDO $pdo,int $org,int $id,int $actor,bool $adjudicated): void {
  $q=$pdo->prepare("SELECT * FROM glasses_vision_annotation_corrections WHERE organization_id=? AND id=? LIMIT 1");$q->execute([$org,$id]);$c=$q->fetch();if(!$c)throw new InvalidArgumentException('Correction not found.');
  $annotations=json_decode((string)($c['proposed_annotation_json']??'[]'),true)?:[];
  $pdo->prepare("UPDATE glasses_vision_training_samples SET canonical_label=?,annotation_json=?,review_status='approved',review_outcome='corrected',reviewer_user_id=COALESCE(reviewer_user_id,?),adjudicator_user_id=?,reviewed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
    ->execute([$c['proposed_label'],json_encode($annotations,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$adjudicated?$actor:null,$org,(int)$c['sample_id']]);
  if($c['proposed_recipe_step_key']!==null)$pdo->prepare("UPDATE glasses_vision_training_media SET recipe_step_key=? WHERE organization_id=? AND id=?")->execute([$c['proposed_recipe_step_key'],$org,(int)$c['media_id']]);
}
function glasses_v11_annotation_adjudicate(PDO $pdo,int $org,string $public,string $decision,string $notes,int $actor): array {
  if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Adjudication decision is invalid.');
  return glasses_transaction($pdo,function()use($pdo,$org,$public,$decision,$notes,$actor):array{
    $c=glasses_v11_annotation_row($pdo,$org,$public,true);if($c['status']!=='needs_adjudication')throw new InvalidArgumentException('Correction does not require adjudication.');
    if((int)$c['submitted_by']===$actor||(int)($c['reviewed_by']??0)===$actor)throw new InvalidArgumentException('Adjudicator must be independent.');
    $next=$decision==='approve'?'approved':'rejected';
    $pdo->prepare("UPDATE glasses_vision_annotation_corrections SET status=?,adjudicated_by=?,adjudicated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$next,$actor,$org,(int)$c['id']]);
    if($next==='approved')glasses_v11_annotation_apply($pdo,$org,(int)$c['id'],$actor,true);
    else $pdo->prepare("UPDATE glasses_vision_training_samples SET adjudicator_user_id=?,review_status='rejected',review_outcome='rejected',reviewed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$c['sample_id']]);
    glasses_v11_annotation_event($pdo,$org,(int)$c['id'],'adjudicated',$actor,['decision'=>$decision,'notes'=>$notes]);
    return glasses_v11_annotation_public(glasses_v11_annotation_row($pdo,$org,$public));
  });
}
function glasses_v11_annotation_catalog(PDO $pdo,int $org,int $limit=100): array {
  if(!glasses_v11_annotation_ready($pdo))return ['corrections'=>[]];
  $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT public_id FROM glasses_vision_annotation_corrections WHERE organization_id=? ORDER BY id DESC LIMIT ".$limit);$q->execute([$org]);
  return ['corrections'=>array_map(fn($p)=>glasses_v11_annotation_public(glasses_v11_annotation_row($pdo,$org,(string)$p)),$q->fetchAll(PDO::FETCH_COLUMN))];
}
