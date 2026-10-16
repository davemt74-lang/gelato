<?php
declare(strict_types=1);
$db=in_array('--db',$argv??[],true);
if($db){require __DIR__.'/../includes/bootstrap.php';}
else{
 function app_role_has_owner_authority(array $r): bool{return (int)($r['is_owner_role']??0)===1;}
 function app_has_permission(string $p,array $r):bool{return in_array('*',$r['permissions']??[],true)||in_array($p,$r['permissions']??[],true);}
}
require __DIR__.'/../includes/food-monster-person-conversations.php';
$count=0;
function fm9d_ok(bool $v,string $desc):void{global $count;if(!$v){fwrite(STDERR,"FM-G9D FAIL: ".$desc."\n");exit(1);}++$count;}
$owner=['id'=>1,'organization_id'=>7,'is_owner_role'=>1,'permissions'=>['*']];
$staff=['id'=>2,'organization_id'=>7,'is_owner_role'=>0,'permissions'=>['voice.agent']];
fm9d_ok(fm9d_day_title('2026-10-16')==='Food Monster · 2026-10-16','stable daily collection title');
fm9d_ok(fm9a_is_super_admin_session($owner),'owner authority');
fm9d_ok(!fm9a_is_super_admin_session($staff),'staff lacks administration');
$api=(string)file_get_contents(__DIR__.'/../api/agent-workspace.php');
$global=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-person-conversations.php');
fm9d_ok(str_contains($api,'fm9d_resolve_speaker')&&str_contains($api,'fm9d_mirror_user_turn')&&str_contains($api,'fm9d_mirror_reply'),'workspace mirrors verified turn and response');
fm9d_ok(str_contains($api,"'person_conversations'")&&str_contains($api,"'person_conversation_page'"),'owner can request scoped collections');
fm9d_ok(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf($in)'),'existing login and CSRF maintained');
fm9d_ok(str_contains($core,'voice_identity_events')&&str_contains($core,"vp.status='active'")&&str_contains($core,'vp.consented_at IS NOT NULL'),'existing consenting voice profile required');
fm9d_ok(str_contains($core,'INTERVAL 60 SECOND'),'only fresh event can associate speech');
fm9d_ok(str_contains($core,'m.voice_event_public_id=e.public_id'),'voice event cannot be reused to associate another turn');
fm9d_ok(str_contains($core,'gaw_create_thread')&&str_contains($core,'gaw_append'),'reuse canonical Agent Brain history, no shadow database');
fm9d_ok(str_contains($core,"'identityAssurance'=>'provisional_voice_match'"),'speaker association explicitly provisional');
fm9d_ok(!str_contains($core,'$_POST')&&!str_contains($core,'session_regenerate_id'),'no browser user IDs or new website session');
fm9d_ok(str_contains($global,'identifySpeaker({allowRoster:true,force:true})'),'explicit recognize voice command available without onboarding');
fm9d_ok(str_contains($global,'freshSpeakerEvent'),'voice events do not persist across distant turns');
fm9d_ok(str_contains($api,'unset($_SESSION[$pendingKey])'),'no previous speaker carried to another turn');
fm9d_ok(!str_contains($core,'CREATE TABLE'),'no new shadow employee tables');
if($db){
 $pdo=app_pdo();fm9d_ok(gaw_ready($pdo),'canonical GAW tables exist');
 $pdo->beginTransaction();
 try{
  $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('FM-G9D CI','America/Phoenix')");$org=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);$loc=(int)$pdo->lastInsertId();
  $make=function(string $name)use($pdo,$org,$loc):array{
   $email='fm9d-'.bin2hex(random_bytes(6)).'@example.test';
   $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,'A','Employee',?,'active')")->execute([$email,'hash',$name]);
   $person=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Cook','active')")->execute([$org,$person,$loc]);
   return ['id'=>$person,'membership'=>(int)$pdo->lastInsertId()];
  };
  $admin=$make('Owner');$employee=$make('Cook');$other=$make('Other');
  $o=$owner;$o['id']=$admin['id'];$o['organization_id']=$org;
  $bad=$staff;$bad['id']=$employee['id'];$bad['organization_id']=$org;
  $pdo->prepare("INSERT INTO voice_identity_profiles (organization_id,user_id,status,consent_version,consented_at,enrolled_at,voiceprint_json,sample_count) VALUES (?,?,'active','v1',NOW(6),NOW(6),'[0.1,0.2]',1)")->execute([$org,$employee['id']]);
  $event='voice-'.bin2hex(random_bytes(9));
  $pdo->prepare("INSERT INTO voice_identity_events (organization_id,public_id,session_user_id,matched_user_id,event_type,confidence,threshold_used,context) VALUES (?,?,?,?,'match',0.9500,0.8600,'agent_listening')")
      ->execute([$org,$event,$admin['id'],$employee['id']]);
  fm9d_ok(fm9d_resolve_speaker($pdo,$o,$event)['personId']===$employee['id'],'fresh server-matched enrolled speaker');
  fm9d_ok(fm9d_resolve_speaker($pdo,$bad,$event)===null,'employee cannot request cross-user identity');
  $nonOwner=$o;$nonOwner['is_owner_role']=0;
  fm9d_ok(fm9d_resolve_speaker($pdo,$nonOwner,$event)===null,'non-owner cannot claim event');
  fm9d_ok(fm9d_resolve_speaker($pdo,$o,'user-20')===null,'browser supplied user ID cannot claim voice');
  $otherOrg=$o;$otherOrg['organization_id']=$org+283;
  fm9d_ok(fm9d_resolve_speaker($pdo,$otherOrg,$event)===null,'cannot read other tenant event');
  $shared=gaw_create_thread($pdo,$org,$admin['id'],'voice','Room conversation');
  gaw_append($pdo,$org,$admin['id'],$shared['public_id'],'user','How do I stretch dough?',['channel'=>'voice','voiceEventId'=>$event]);
  $resolved=fm9d_resolve_speaker($pdo,$o,$event);
  $pending=fm9d_mirror_user_turn($pdo,$o,$resolved,'How do I stretch dough?',$shared['public_id']);
  fm9d_ok($pending['personId']===$employee['id'],'voice turn mirrored to employee collection');
  fm9d_ok(fm9d_resolve_speaker($pdo,$o,$event)===null,'same voice event cannot be replayed');
  fm9d_ok(fm9d_mirror_reply($pdo,$o,$pending,$shared['public_id'],'Use the bench and work from the center.'),'reply saved in same employee history');
  fm9d_ok(!fm9d_mirror_reply($pdo,$o,$pending,'wrong-thread','Wrong context'),'response thread must match');
  $day=fm9d_person_collections($pdo,$o,$employee['id']);
  fm9d_ok(count($day['collections'])===1,'single canonical per-person daily collection');
  fm9d_ok(str_starts_with($day['collections'][0]['title'],'Food Monster · '),'daily collection named');
  $page=gaw_history_page($pdo,$org,$employee['id'],$pending['threadId'],0,20);
  fm9d_ok(count($page['messages'])===2,'employee collection holds question and reply');
  fm9d_ok($page['messages'][0]['role']==='user'&&$page['messages'][1]['role']==='agent','chronological user/agent turns');
  fm9d_ok(($page['messages'][0]['structured']['actorUserId']??null)===$admin['id'],'owner remains authority source in provenance');
  fm9d_ok(($page['messages'][0]['structured']['identityAssurance']??null)==='provisional_voice_match','stored identity assurance not login');
  fm9d_ok(count(gaw_messages($pdo,$org,$admin['id'],$shared['public_id']))===1,'room history remains separate');
  fm9d_ok(fm9d_person_collections($pdo,$o,$other['id'])['collections']===[],'unknown employee not misattributed');
  try{fm9d_person_collections($pdo,$bad,$employee['id']);fm9d_ok(false,'staff cannot browse others');}
  catch(DomainException){fm9d_ok(true,'staff cannot browse others');}
  try{fm9d_person_collections($pdo,$otherOrg,$employee['id']);fm9d_ok(false,'tenant isolation');}
  catch(InvalidArgumentException){fm9d_ok(true,'person collections tenant isolated');}
  $pdo->prepare("UPDATE voice_identity_profiles SET status='disabled' WHERE organization_id=? AND user_id=?")->execute([$org,$employee['id']]);
  $second='voice-'.bin2hex(random_bytes(9));
  $pdo->prepare("INSERT INTO voice_identity_events (organization_id,public_id,session_user_id,matched_user_id,event_type,confidence,threshold_used) VALUES (?,?,?,?,'match',0.95,0.86)")->execute([$org,$second,$admin['id'],$employee['id']]);
  fm9d_ok(fm9d_resolve_speaker($pdo,$o,$second)===null,'disabled profile cannot trigger attribution');
 }finally{if($pdo->inTransaction())$pdo->rollBack();}
}
echo "FM-G9D per-person conversation acceptance PASS ($count assertions".($db?', database-backed':'').")\n";
