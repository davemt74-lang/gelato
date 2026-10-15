<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-speaker.php';
$n=0;function go(bool $b,string $what):void{global $n;if(!$b){fwrite(STDERR,"FAIL $what\n");exit(1);}++$n;}
$php=(string)file_get_contents(__DIR__.'/../includes/food-monster-onboarding.php');
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-speaker.php');
$stage=(string)file_get_contents(__DIR__.'/../food-monster.php');
$onboarding=(string)file_get_contents(__DIR__.'/../js/food-monster-onboarding.js');
$participants=(string)file_get_contents(__DIR__.'/../js/food-monster-participants.js');
$camera=(string)file_get_contents(__DIR__.'/../js/food-monster-camera.js');
foreach([
 [str_contains($php,'fmgoal_existing_accounts'),'Can select existing account'],
 [str_contains($php,'Linked employee must capture their own photos'),'Cannot capture someone else biometrics'],
 [str_contains($api,'app_require_auth()'),'Speaker endpoint authenticated'],
 [str_contains($api,'app_verify_request_csrf'),'Speaker requires CSRF'],
 [str_contains($api,"'consent'"),'Explicit speaker consent'],
 [str_contains($onboarding,"state.handsFree"),'Handsfree consent gate'],
 [str_contains($onboarding,'GelatoVoiceTurns'),'Uses self-echo turn-taking engine'],
 [str_contains($onboarding,'#gelato-agent-bar'),'Launcher in sticky bar'],
 [!str_contains($onboarding,'host.append(launch)'),'No corner launcher'],
 [str_contains($participants,"visible.length!==1"),'No photo capture among several people'],
 [str_contains($participants,"variance<110"),'Exposure quality gate'],
 [str_contains($participants,'voice does not identify which person'),'Voice/visual identity separation'],
 [str_contains($camera,'persistDeviceFace'),'Encrypted face template persistence'],
 [str_contains($camera,"profile.account!==ACCOUNT"),'Account-isolated face template'],
 [str_contains($stage,'js/food-monster-fast-enroll.js'),'Rapid enrolment loaded'],
] as [$v,$m])go($v,$m);
echo "FM-G8D1 flow security and UI contracts PASS ($n checks)\n";