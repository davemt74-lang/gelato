<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
 if(!$ok){fwrite(STDERR,"FM-G8 RESET FAIL: ".$label."\n");exit(1);}
 $checks++;
};
$page=$read('food-monster.php');
$actor=$read('js/food-monster.js');
$agent=$read('js/global-agent.js');
$voice=$read('api/voice-identity.php');
foreach(['food-monster-participants.js','food-monster-onboarding.js','food-monster-fast-enroll.js','food-monster-participant-history.js','food-monster-camera.js'] as $file){
 $check(!str_contains($page,$file)&&!str_contains($actor,$file),'Retired script not loaded: '.$file);
 $check(!is_file($root.'/js/'.$file),'Retired JS removed: '.$file);
}
$check(!str_contains($page,'fm-stage-help')&&!str_contains($page,'fm-stage-brand'),'No extra participant/setup navigation');
$check(str_contains($page,'js/global-agent.js')&&str_contains($page,'js/food-monster.js'),'Main Agent and mascot retained');
foreach(['api/food-monster-onboarding.php','api/food-monster-participants.php','api/food-monster-speaker.php','api/food-monster-participant-history.php'] as $p)
 $check(!is_file($root.'/'.$p),'Legacy API retired: '.$p);
$check(!str_contains($voice,'food-monster-participant-history')&&!str_contains($voice,'fmph_voice_enrollment_event'),'Time Clock Voice decoupled from retired participant history');
$check(str_contains($voice,'tv_voice_profile')&&str_contains($voice,'voice_identity_profiles'),'Existing voice profiles retained');
$check(is_file($root.'/api/timeclock.php')&&is_file($root.'/includes/timeclock-voice-core.php'),'Time clock preserved');
$check(!str_contains($agent,'await verifyVoice();'),'Microphone never blocked by biometric sample');
$check(!str_contains($agent,'Checking the enrolled voice sample'),'No enrollment interruption in Agent Chat');
$check(str_contains($agent,'startContinuous()')&&str_contains($agent,'speechOnce()'),'Existing STT paths preserved');
echo "FM-G8 reset contract PASS ($checks checks)\n";
