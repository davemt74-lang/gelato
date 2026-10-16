<?php
declare(strict_types=1);
function app_role_has_owner_authority(array $user):bool{return ($user['is_owner_role']??0)===1;}
require __DIR__.'/../includes/food-monster-room-presence.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$original=$_SESSION;
$n=0;
function check11(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException('FAIL: '.$label);++$n;}
$owner=['id'=>4,'organization_id'=>8,'is_owner_role'=>1];
$worker=['id'=>5,'organization_id'=>8,'is_owner_role'=>0];
$first=['trackId'=>'r-'.str_repeat('a',16),'personId'=>12,
    'identityAssurance'=>'provisional_visual_match'];
$second=['trackId'=>'r-'.str_repeat('b',16),'personId'=>13,
    'identityAssurance'=>'provisional_visual_match'];
$now=time();
$_SESSION['fm9c_room']=['org'=>8,'operator'=>4,'seen'=>$now,'people'=>[$first,$second]];
$event=['personId'=>12,'voiceEventId'=>'voice-'.str_repeat('a',20),
    'identityAssurance'=>'provisional_voice_match'];
$match=fm11a_room_voice_turn($owner,$event,$now);
check11($match!==null&&$match['trackId']===$first['trackId'],'verified voice correlates one visual track');
check11(!$match['websiteAuthenticated']&&!$match['agentAdministration'],'presence never grants authority');
check11(fm9c_room_context($owner,null,$now)['currentSpeaker']['personId']===12,'speaker is visible in room');
check11(fm9c_room_context($owner,null,$now+6)['currentSpeaker']===null,'speaker expires without new speech');
check11(fm11a_room_voice_turn($worker,$event,$now)===null,'employee cannot set current speaker');
check11(fm9c_room_context($worker,null,$now)['status']==='not_connected','employee cannot view owner room');
$_SESSION['fm9c_room']['people']=[$first,$second];
check11(fm11a_room_voice_turn($owner,['personId'=>99]+$event,$now)===null,'voice-only person remains uncorrelated');
check11(fm9c_room_context($owner,null,$now)['currentSpeaker']===null,'unknown visual match clears speaker');
$_SESSION['fm9c_room']['people']=[$first,$first];
check11(fm11a_room_voice_turn($owner,$event,$now)===null,'duplicate track identity never accepted');
$_SESSION['fm9c_room']['people']=[$first,$second];
check11(fm11a_room_voice_turn($owner,['personId'=>13,'voiceEventId'=>'voice-'.str_repeat('b',20),
    'identityAssurance'=>'provisional_voice_match'],$now)['personId']===13,'verified speaker switch');
check11(fm11a_room_voice_turn($owner,null,$now)===null,'unknown speech clears former speaker');
check11(fm9c_room_context($owner,null,$now)['currentSpeaker']===null,'no last speaker leakage');
check11(fm11a_room_voice_turn($owner,['personId'=>12,'voiceEventId'=>'user-12',
    'identityAssurance'=>'provisional_voice_match'],$now)===null,'forged non-event denied');
$_SESSION['fm9c_room']['seen']=$now-9;
check11(fm11a_room_voice_turn($owner,$event,$now)===null,'stale visual observation denied');
$_SESSION['fm9c_room']['seen']=$now;
fm11a_room_voice_turn($owner,$event,$now);
$_SESSION['fm9c_room']['people']=[$second];
check11(fm9c_room_context($owner,null,$now)['currentSpeaker']===null,'person exiting view clears correlation');
$_SESSION['fm9c_room']['people']=[$first,$second];
fm11a_room_voice_turn($owner,$event,$now);
fm9c_room_stop($owner);
check11(fm9c_room_context($owner)['status']==='not_connected','camera stop clears speaker and presence');
check11(!isset($_SESSION['fm9c_room']),'room session no longer retains participant');
$api=file_get_contents(__DIR__.'/../api/agent-workspace.php');
check11(str_contains($api,"$"."roomSpeaker=$"."role==='user'")&&str_contains($api,'fm11a_room_voice_turn($user,$personSpeaker)'),
  'workspace records only committed voice speaker on user turn');
check11(str_contains($api,"'roomSpeaker'=>$"."roomSpeaker"),'attribution has an explicit non-authority receipt');
$_SESSION=$original;
echo "FM-G11A room speaker attribution PASS ($n assertions)\n";
