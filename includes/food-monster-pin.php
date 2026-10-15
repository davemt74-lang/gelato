<?php
declare(strict_types=1);
/** Face detection selects an account on an owner-registered device; PIN is independently verified. */
function fmpin_valid(string $pin):bool {
 return preg_match('/^[0-9]{6}$/D',$pin)===1&&count(array_unique(str_split($pin)))>=3
 &&!in_array($pin,['123456','654321','000000','111111','121212','112233'],true);
}
function fmpin_hash(string $pin):string{
 if(!fmpin_valid($pin))throw new InvalidArgumentException('Choose a six-digit PIN with at least three different digits; avoid common patterns.');
 return app_password_hash($pin);
}
function fmpin_ready(PDO $pdo):bool{
 try{return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('fm_pin_credentials','fm_pin_devices','fm_pin_device_accounts')")->fetchColumn()===3;}catch(Throwable){return false;}
}
function fmpin_has(PDO $pdo,int $org,int $uid):bool{
 $q=$pdo->prepare('SELECT COUNT(*) FROM fm_pin_credentials WHERE organization_id=? AND user_id=?');$q->execute([$org,$uid]);return (int)$q->fetchColumn()>0;
}
function fmpin_set(PDO $pdo,int $org,int $uid,string $pin):void{
 $hash=fmpin_hash($pin);
 $pdo->prepare('INSERT INTO fm_pin_credentials (organization_id,user_id,pin_hash,failures,locked_until) VALUES (?,?,?,0,NULL) ON DUPLICATE KEY UPDATE pin_hash=VALUES(pin_hash),failures=0,locked_until=NULL')->execute([$org,$uid,$hash]);
}
function fmpin_cookie():string{return 'gelato_fm_trusted_terminal';}
function fmpin_cookie_set(string $value,int $expiry):void{
 setcookie(fmpin_cookie(),$value,['expires'=>$expiry,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
}
function fmpin_device(PDO $pdo):?array{
 $secret=(string)($_COOKIE[fmpin_cookie()]??'');
 if(!preg_match('/^[a-f0-9]{64}$/D',$secret))return null;
 $q=$pdo->prepare('SELECT id,organization_id,label,failures,locked_until FROM fm_pin_devices WHERE secret_hash=? AND revoked_at IS NULL AND expires_at>NOW(6) LIMIT 1');
 $q->execute([hash('sha256',$secret)]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function fmpin_trust(PDO $pdo,array $actor,string $password,string $label):array{
 if((int)($actor['is_owner_role']??0)!==1)throw new RuntimeException('Protected Super Admin permission required.');
 $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$actor['id']]);
 if(!password_verify($password,(string)$q->fetchColumn()))throw new RuntimeException('Owner password verification failed.');
 $label=mb_substr(trim($label),0,100);if($label==='')throw new InvalidArgumentException('Device name required.');
 $secret=bin2hex(random_bytes(32));$expiry=time()+180*86400;
 $pdo->prepare('INSERT INTO fm_pin_devices (organization_id,secret_hash,label,created_by,expires_at) VALUES (?,?,?,?,FROM_UNIXTIME(?))')->execute([(int)$actor['organization_id'],hash('sha256',$secret),$label,(int)$actor['id'],$expiry]);
 fmpin_cookie_set($secret,$expiry);
 app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'authentication.pin_terminal_trusted','user',(string)$actor['id'],null,['deviceLabel'=>$label]);
 return ['label'=>$label,'expiresAt'=>date(DATE_ATOM,$expiry)];
}
function fmpin_register(PDO $pdo,array $actor):void{
 $device=fmpin_device($pdo);$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 if(!$device||(int)$device['organization_id']!==$org)throw new RuntimeException('Super Admin must register this terminal first.');
 if((int)($actor['is_owner_role']??0)===1)throw new RuntimeException('Super Admin accounts must use password sign-in.');
 $q=$pdo->prepare("SELECT COUNT(*) FROM organization_memberships om JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id WHERE om.organization_id=? AND om.user_id=? AND r.is_owner_role=0 AND r.slug NOT IN ('super_admin','customer','wholesale_customer')");
 $q->execute([$org,$uid]);if((int)$q->fetchColumn()===0)throw new RuntimeException('An active employee role is required for face/PIN enrollment.');
 if(!fmpin_has($pdo,$org,$uid))throw new RuntimeException('Set your six-digit PIN before face enrollment.');
 $pdo->prepare('INSERT INTO fm_pin_device_accounts (device_id,organization_id,user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE consented_at=NOW(6)')->execute([$device['id'],$org,$uid]);
 app_audit($pdo,$org,$uid,'authentication.face_pin_enrolled','user',(string)$uid,null,['deviceId'=>$device['id']]);
}
function fmpin_signin(PDO $pdo,string $email,string $pin):array{
 $device=fmpin_device($pdo);$generic='Face/PIN sign-in was not accepted. Check the PIN or use your password.';
 if(!$device)throw new RuntimeException('This browser is not registered as a trusted terminal. Use password sign-in.');
 if($device['locked_until']&&strtotime((string)$device['locked_until'])>time())throw new RuntimeException('This terminal is temporarily locked due to repeated failed PINs.');
 if(!preg_match('/^[0-9]{6}$/D',$pin))throw new RuntimeException($generic);
 $q=$pdo->prepare("SELECT u.id,u.status,u.archived_at,u.locked_until,c.pin_hash,c.failures,c.locked_until pin_locked FROM users u JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active' JOIN fm_pin_device_accounts e ON e.user_id=u.id AND e.organization_id=om.organization_id AND e.device_id=? JOIN fm_pin_credentials c ON c.user_id=u.id AND c.organization_id=om.organization_id WHERE u.email=? LIMIT 1");
 $q->execute([(int)$device['organization_id'],(int)$device['id'],strtolower(trim($email))]);$row=$q->fetch(PDO::FETCH_ASSOC);
 $active=false;
 if($row){
  $role=$pdo->prepare("SELECT COUNT(*) FROM organization_memberships om JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id WHERE om.organization_id=? AND om.user_id=? AND r.is_owner_role=0 AND r.slug NOT IN ('super_admin','customer','wholesale_customer')");
  $role->execute([(int)$device['organization_id'],(int)$row['id']]);$active=(int)$role->fetchColumn()>0;
  $owner=$pdo->prepare("SELECT COUNT(*) FROM organization_memberships om JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL JOIN roles r ON r.id=ur.role_id WHERE om.organization_id=? AND om.user_id=? AND (r.is_owner_role=1 OR (r.slug='super_admin' AND r.is_system_role=1))");
  $owner->execute([(int)$device['organization_id'],(int)$row['id']]);if((int)$owner->fetchColumn()>0)$active=false;
 }
 $locked=$row&&(($row['locked_until']&&strtotime((string)$row['locked_until'])>time())||($row['pin_locked']&&strtotime((string)$row['pin_locked'])>time()));
 $valid=$row&&$active&&!$locked&&$row['status']==='active'&&$row['archived_at']===null&&password_verify($pin,(string)$row['pin_hash']);
 if(!$valid){
  if($row&&!$locked){$n=(int)$row['failures']+1;$pdo->prepare('UPDATE fm_pin_credentials SET failures=?,locked_until=? WHERE organization_id=? AND user_id=?')->execute([$n>=5?0:$n,$n>=5?date('Y-m-d H:i:s',time()+900):null,(int)$device['organization_id'],(int)$row['id']]);}
  $n=(int)$device['failures']+1;$pdo->prepare('UPDATE fm_pin_devices SET failures=?,locked_until=? WHERE id=?')->execute([$n>=10?0:$n,$n>=10?date('Y-m-d H:i:s',time()+900):null,(int)$device['id']]);
  usleep(random_int(160000,360000));throw new RuntimeException($generic);
 }
 $org=(int)$device['organization_id'];$uid=(int)$row['id'];
 $pdo->prepare('UPDATE fm_pin_devices SET failures=0,locked_until=NULL WHERE id=?')->execute([(int)$device['id']]);
 $pdo->prepare('UPDATE fm_pin_credentials SET failures=0,locked_until=NULL WHERE organization_id=? AND user_id=?')->execute([$org,$uid]);
 $pdo->prepare('UPDATE users SET last_login_at=NOW(6) WHERE id=?')->execute([$uid]);
 session_regenerate_id(true);$_SESSION=['user_id'=>$uid,'organization_id'=>$org,'authenticated_at'=>time(),'auth_method'=>'trusted_terminal_pin'];
 app_audit($pdo,$org,$uid,'authentication.pin_login','user',(string)$uid,null,['terminalId'=>$device['id']]);
 return ['ok'=>true,'redirect'=>'workspace.php'];
}
