<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-participants.php';
$n=0;function fm8_ok(bool $yes,string $label):void{global $n;if(!$yes){fwrite(STDERR,'FM-G8A FAIL: '.$label."\n");exit(1);}$n++;}
function app_has_permission(string $permission,?array $user=null):bool{return in_array($permission,$user['permissions']??[],true);}
foreach([
 [true,true,true,'Scheduled · clocked in'],
 [false,true,false,'Clocked in · not scheduled today'],
 [true,false,false,'Scheduled today · clock-in not confirmed'],
 [false,false,true,'Signed in · schedule not confirmed'],
 [false,false,false,'Staff · not scheduled today'],
] as [$schedule,$clock,$login,$expected])fm8_ok(fmpt_classify($schedule,$clock,$login)===$expected,'schedule/clock classification');
$permissions=fmpt_access(['permissions'=>[]]);fm8_ok(!$permissions['schedule']&&!$permissions['clock'],'deny by default');
$permissions=fmpt_access(['permissions'=>['schedule.self']]);fm8_ok($permissions['schedule']&&!$permissions['scheduleTeam'],'self schedule not public staff roster');
$permissions=fmpt_access(['permissions'=>['schedule.view','timeclock.agent','timeclock.view']]);
fm8_ok($permissions['scheduleTeam']&&$permissions['clock'],'authorized manager context');
fm8_ok(!fmpt_access(['permissions'=>['timeclock.view']])['clock'],'attendance needs agent permission');
$api=file_get_contents(__DIR__.'/../api/food-monster-participants.php');
fm8_ok(str_contains($api,'app_require_auth()'),'session required');
fm8_ok(str_contains($api,"'GET'"),'read-only endpoint');
fm8_ok(str_contains($api,'no-store'),'private response is not cached');
$camera=file_get_contents(__DIR__.'/../js/food-monster-camera.js');
fm8_ok(str_contains($camera,"publishFaces(faces"),'real camera tracking publishes geometry');
fm8_ok(str_contains($camera,'ownVerified:!!ownVerified&&faces.length===1'),'face match only if one face');
fm8_ok(str_contains($camera,'audio:false'),'camera never grants microphone implicitly');
fm8_ok(str_contains($camera,'publishCamera(false)'),'camera shutdown broadcast');
$stage=file_get_contents(__DIR__.'/../js/food-monster-participants.js');
fm8_ok(str_contains($stage,'getUserMedia({audio:{echoCancellation:true'),'shared voice meter must be activated manually');
fm8_ok(str_contains($stage,'state.thumbnails'),'photos are opt-in');
fm8_ok(!str_contains($stage,'localStorage'),'thumbnails and tracks are not persisted');
fm8_ok(!str_contains($stage,'food-monster-llm.php'),'face observations not posted to LLM');
fm8_ok(str_contains($stage,'track.verified=ownVerified&&boxes.length===1'),'identity revoked for ambiguous faces');
fm8_ok(str_contains($stage,'stopMeter();clearTracks();'),'pagehide cleanup');
fm8_ok(str_contains(file_get_contents(__DIR__.'/../food-monster.php'),'js/food-monster-participants.js'),'participant interface on Food Monster page');
echo "FM-G8A participant privacy and roster contracts: PASS ($n gates)\n";
