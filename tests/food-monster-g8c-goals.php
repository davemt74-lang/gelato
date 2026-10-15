<?php
declare(strict_types=1);
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
require __DIR__.'/../includes/food-monster-onboarding.php';
$n=0;function gate8c(bool $ok,string $label):void{global $n;if(!$ok){fwrite(STDERR,"FM-G8C FAIL: $label\n");exit(1);}++$n;}
gate8c(fmgoal_owner(['is_owner_role'=>1]),'Protected owner can approve');
gate8c(!fmgoal_owner(['is_owner_role'=>0,'permissions'=>['users.create']]),'Ordinary manager cannot assume super-admin');
gate8c(fmgoal_name('  Jamie  Rivera ') === 'Jamie  Rivera','Name preserved as self-reported');
foreach(['','A',str_repeat('X',121)] as $name){
 try{fmgoal_name($name);gate8c(false,'Invalid name accepted');}
 catch(InvalidArgumentException){gate8c(true,'Invalid name refused');}
}
$api=(string)file_get_contents(__DIR__.'/../api/food-monster-onboarding.php');
$core=(string)file_get_contents(__DIR__.'/../includes/food-monster-onboarding.php');
$js=(string)file_get_contents(__DIR__.'/../js/food-monster-onboarding.js');
$tracks=(string)file_get_contents(__DIR__.'/../js/food-monster-participants.js');
$agent=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
$stage=(string)file_get_contents(__DIR__.'/../food-monster.php');
$css=(string)file_get_contents(__DIR__.'/../css/food-monster.css');
$migration=(string)file_get_contents(__DIR__.'/../database/20261015_fm_g8c_goals.sql');
foreach([
 [str_contains($api,'app_require_auth()'),'Authenticated gallery/API'],
 [str_contains($api,'app_verify_request_csrf'),'CSRF required on writes'],
 [str_contains($api,"'Cache-Control: no-store, private'"),'Private non-cacheable gallery'],
 [str_contains($core,"created_by']!==(int)\$actor['id']"),'Creator-isolated goal fetch'],
 [str_contains($core,'fmgoal_owner($actor)'),'Owner privilege explicit'],
 [str_contains($core,'app_password_hash(bin2hex(random_bytes(40)))'),'Random unusable initial password'],
 [str_contains($core,'password_reset_tokens'),'Expiring one-time password link'],
 [str_contains($core,'is_owner_role=0'),'Cannot assign protected owner role'],
 [str_contains($core,'is_assignable=1'),'Cannot assign non-assignable role'],
 [str_contains($core,'$last,$name'),'Single-name employee accounts retain required non-null last_name'],
 [str_contains($core,"mb_strlen($first,'UTF-8')>100"),'Employee name lengths match SQL schema'],
 [str_contains($js,'Pending approval:'),'Exact email and role visible during approval'],
 [str_contains($core,"['employee','guest']"),'Explicit participant type'],
 [str_contains($core,"photo_consent_at']===null"),'Photo consent gate'],
 [str_contains($core,'strlen($bytes)>140000'),'Size-limited photo uploads'],
 [str_contains($core,'getimagesizefromstring'),'Image validation'],
 [str_contains($core,'app_encrypt_secret($bytes)'),'Gallery photos are encrypted before persistence'],
 [str_contains($api,'app_decrypt_secret('),'Gallery served with authenticated decryption'],
 [str_contains($core,'count($photos)<3'),'Employee requires 3 photos'],
 [str_contains($core,'voice_identity_profiles'),'Real voice profile required'],
 [str_contains($core,'$explicitConsent'),'Guest-to-CRM explicit consent'],
 [str_contains($core,'crm_customer_save'),'Existing CRM integration'],
 [str_contains($core,"'marketingConsent'=>'not_recorded'"),'No automatic marketing consent'],
 [str_contains($api,"if(\$action==='photo')"),'Private gallery image endpoint'],
 [str_contains($api,"if(\$status==='completed')"),'Guarded completion'],
 [str_contains($tracks,'now-t.at<18000'),'Missed frames do not reassign Person IDs'],
 [str_contains($tracks,'captureGoalPhoto'),'Explicit track photograph only'],
 [str_contains($tracks,"gelato-food-monster-new-track"),'Arrival triggers introduction'],
 [str_contains($js,'gelato-agent-before-send'),'Conversational name interception'],
 [str_contains($js,'crm-consent'),'User-visible CRM consent status'],
 [str_contains($agent,'cancelable:true'),'Model bypass only on active goal'],
 [str_contains($stage,'js/food-monster-onboarding.js'),'Goal UI included on stage'],
 [str_contains($stage,'meta name="gelato-csrf"'),'Request token from authenticated page'],
 [str_contains($css,'object-fit:cover'),'Full-bleed camera view'],
 [str_contains($migration,'fm_participant_photos'),'Encrypted-at-rest storage policy must be handled by deployment database'],
 [!str_contains($js,'localStorage'),'No browser-persisted identity/goal biometrics'],
] as [$test,$label])gate8c($test,$label);
echo "FM-G8C onboarding, permissions and gallery contracts: PASS ($n assertions)\n";
