<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-pin.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$pdo=app_pdo();
if(!fmpin_ready($pdo))app_json_response(['ok'=>false,'message'=>'Employee PIN migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']==='GET'){
 $u=app_current_user();$d=fmpin_device($pdo);
 app_json_response(['ok'=>true,'csrf'=>app_csrf_token(),'trustedDevice'=>(bool)$d,'deviceLabel'=>$d['label']??null,
 'signedIn'=>(bool)$u,'email'=>$u?$u['email']:null,'canTrust'=>(int)($u['is_owner_role']??0)===1,
 'pinSet'=>$u?fmpin_has($pdo,(int)$u['organization_id'],(int)$u['id']):false]);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);
$action=(string)($in['action']??'');
try{
 if($action==='pin_login'){
  if(app_current_user())throw new RuntimeException('Already signed in.');
  app_json_response(fmpin_signin($pdo,(string)($in['email']??''),(string)($in['pin']??'')));
 }
 $u=app_require_auth();$org=(int)$u['organization_id'];$uid=(int)$u['id'];
 if($action==='pin_set'){
  $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$uid]);
  if(!password_verify((string)($in['currentPassword']??''),(string)$q->fetchColumn()))throw new RuntimeException('Your current password is required to set or change a PIN.');
  $pin=(string)($in['pin']??'');
  if($pin!==(string)($in['confirmPin']??''))throw new InvalidArgumentException('PIN entries do not match.');
  fmpin_set($pdo,$org,$uid,$pin);
  app_audit($pdo,$org,$uid,'authentication.pin_changed','user',(string)$uid);
  app_json_response(['ok'=>true,'message'=>'Six-digit PIN saved.']);
 }
 if($action==='trust_device')app_json_response(['ok'=>true,'device'=>fmpin_trust($pdo,$u,(string)($in['currentPassword']??''),(string)($in['label']??''))]);
 if($action==='face_enroll'){
  fmpin_register($pdo,$u);
  app_json_response(['ok'=>true,'message'=>'Face sign-in enabled on this trusted terminal for your account.','email'=>$u['email'],'label'=>$u['display_name']]);
 }
 if($action==='face_revoke'){
  $d=fmpin_device($pdo);if(!$d||(int)$d['organization_id']!==$org)throw new RuntimeException('No registered terminal.');
  $pdo->prepare('DELETE FROM fm_pin_device_accounts WHERE device_id=? AND user_id=?')->execute([$d['id'],$uid]);
  app_audit($pdo,$org,$uid,'authentication.face_pin_revoked','user',(string)$uid);
  app_json_response(['ok'=>true]);
 }
 if($action==='revoke_device'){
  if((int)($u['is_owner_role']??0)!==1)throw new RuntimeException('Super Admin required.');
  $d=fmpin_device($pdo);if(!$d||(int)$d['organization_id']!==$org)throw new RuntimeException('No registered terminal.');
  $pdo->prepare('UPDATE fm_pin_devices SET revoked_at=NOW(6) WHERE id=?')->execute([$d['id']]);
  fmpin_cookie_set('',time()-3600);
  app_audit($pdo,$org,$uid,'authentication.pin_terminal_revoked','user',(string)$uid);
  app_json_response(['ok'=>true]);
 }
 app_json_response(['ok'=>false,'message'=>'Unsupported action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$action==='pin_login'?401:403);}
