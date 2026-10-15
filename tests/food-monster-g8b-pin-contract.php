<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/food-monster-pin.php';
$n=0;
function pin_check(bool $ok,string $name):void{global $n;if(!$ok){fwrite(STDERR,"FM-G8B PIN FAIL: $name\n");exit(1);}$n++;}
foreach(['928453','908178','147039'] as $p){pin_check(fmpin_valid($p),'acceptable PIN');pin_check(password_verify($p,fmpin_hash($p)),'hashed PIN');}
foreach(['123456','654321','000000','111111','111121','1234','1234567','ABCDEF','121212','112233',''] as $p)pin_check(!fmpin_valid($p),'reject weak PIN');
$js=file_get_contents(__DIR__.'/../js/fm-face-pin.js');
$api=file_get_contents(__DIR__.'/../api/fm-pin.php');
$core=file_get_contents(__DIR__.'/../includes/food-monster-pin.php');
$migration=file_get_contents(__DIR__.'/../database/20261015_fm_g8b_face_pin.sql');
$reset=file_get_contents(__DIR__.'/../reset-password.php');
pin_check(str_contains($js,'crypto.subtle.encrypt')&&str_contains($js,'crypto.subtle.decrypt'),'AES-GCM encryption');
pin_check(!str_contains($js,'localStorage'),'no plaintext biometric local storage');
pin_check(str_contains($js,'detectAllFaces')&&str_contains($js,'faces.length!==1'),'one face');
pin_check(str_contains($js,"ranking[1].d-ranking[0].d<.085"),'ambiguous match rejected');
pin_check(str_contains($js,"request('pin_login'")&&str_contains($js,"request('face_enroll'"),'separate login and enrollment');
pin_check(str_contains($js,'csrf_token:csrf'),'expected CSRF field');
pin_check(str_contains($api,'app_verify_request_csrf($in)'),'API CSRF');
pin_check(str_contains($api,'app_require_auth()')&&str_contains($api,'app_current_user()'),'login vs enrollment auth');
pin_check(str_contains($core,'session_regenerate_id(true)'),'rotate session');
pin_check(str_contains($core,'password_verify($pin'),'server PIN verify');
pin_check(str_contains($core,"'httponly'=>true")&&str_contains($core,"'secure'=>true")&&str_contains($core,"'samesite'=>'Strict'"),'secure device cookie');
pin_check(str_contains($core,'is_owner_role')&&str_contains($core,'Protected Super Admin'),'terminal manager');
pin_check(str_contains($core,'time()+900'),'15-minute lockout');
pin_check(str_contains($core,'authentication.pin_login')&&str_contains($core,'authentication.face_pin_enrolled'),'auditable');
pin_check(str_contains($core,"r.slug NOT IN ('super_admin','customer','wholesale_customer')"),'employee roles only');
pin_check(str_contains($reset,'fmpin_set($pdo'),'PIN created in password reset');
pin_check(str_contains(file_get_contents(__DIR__.'/../login.php'),'fm-face-pin-video'),'camera login interface');
pin_check(str_contains(file_get_contents(__DIR__.'/../profile-pin.php'),'fm-face-consent'),'explicit consent');
pin_check(str_contains($migration,'fm_pin_credentials')&&str_contains($migration,'fm_pin_device_accounts'),'schema');
pin_check(!str_contains($migration,'face_descriptor'),'no face data in SQL');
echo "FM-G8B-PIN credentials and privacy: PASS ($n checks)\n";
