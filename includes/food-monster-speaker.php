<?php
declare(strict_types=1);
/** FM-G8D1: permissioned voice-to-profile similarity, never a login or time-clock credential. */
require_once __DIR__.'/timeclock-voice-core.php';
function fmspeaker_can_roster(array $actor):bool {return app_has_permission('voice.manage',$actor);}
function fmspeaker_scoring(array $samples,array $features,float $floor=.94,float $margin=.12):array {
 $probe=tv_voice_vector($features);$rank=[];
 foreach($samples as $row){
  if(($row['status']??'')!=='active')continue;
  $vector=json_decode((string)($row['voiceprint_json']??''),true);
  if(!is_array($vector)||count($vector)!==count($probe))continue;
  $score=tv_cosine($probe,$vector);
  $rank[]=['userId'=>(int)$row['user_id'],'name'=>(string)$row['display_name'],
   'score'=>$score,'threshold'=>max($floor,(float)($row['match_threshold']??.86))];
 }
 usort($rank,static fn($a,$b)=>$b['score']<=>$a['score']);
 $top=$rank[0]??null;$second=$rank[1]??null;
 if(!$top)return ['matched'=>false,'reason'=>'no_active_profiles','compared'=>0];
 if($top['score']<$top['threshold'])return ['matched'=>false,'reason'=>'below_threshold','compared'=>count($rank)];
 if($second&&$top['score']-$second['score']<$margin)return ['matched'=>false,'reason'=>'ambiguous','compared'=>count($rank)];
 return ['matched'=>true,'speaker'=>['userId'=>$top['userId'],'name'=>$top['name']],
  'score'=>round($top['score'],3),'compared'=>count($rank),'reason'=>'possible_speaker'];
}
function fmspeaker_identify(PDO $pdo,array $actor,array $features):array {
 if(!fmspeaker_can_roster($actor))throw new DomainException('Voice roster permission required.');
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 $q=$pdo->prepare("SELECT DISTINCT vp.user_id,vp.voiceprint_json,vp.match_threshold,vp.status,u.display_name
 FROM voice_identity_profiles vp
 INNER JOIN users u ON u.id=vp.user_id AND u.status='active' AND u.archived_at IS NULL
 INNER JOIN organization_memberships om ON om.user_id=vp.user_id AND om.organization_id=vp.organization_id AND om.status='active'
 WHERE vp.organization_id=? AND vp.status='active' AND vp.voiceprint_json IS NOT NULL
 AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
             WHERE ur.membership_id=om.id AND ur.revoked_at IS NULL
             AND r.organization_id=vp.organization_id AND r.slug NOT IN ('customer','wholesale_customer'))
 ORDER BY vp.user_id LIMIT 200");
 $q->execute([$org]);$result=fmspeaker_scoring($q->fetchAll(PDO::FETCH_ASSOC),$features);
 app_audit($pdo,$org,$uid,'agent.voice_speaker_checked','voice_profile',null,null,
  ['result'=>$result['reason'],'candidateId'=>($result['matched']??false)?$result['speaker']['userId']:null,'compared'=>$result['compared']??0]);
 return $result;
}
