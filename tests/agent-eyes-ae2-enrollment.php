<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-room-presence.php';
$pdo=app_pdo();$n=0;
function ae2check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);++$n;}
ae2check(fmeyes_ready($pdo),'AE2 migration missing');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$oldSession=$_SESSION;$cookieName=fmpin_cookie();$oldCookie=$_COOKIE[$cookieName]??null;
$pdo->beginTransaction();
try{
 $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('AE2 CI','America/Phoenix')");
 $org=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main']);
 $location=(int)$pdo->lastInsertId();
 $create=static function(string $name)use($pdo,$org,$location):array{
  $email=strtolower($name).bin2hex(random_bytes(4)).'@example.test';
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$email,'x',$name,'Test',$name]);
  $id=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Staff','active')")
    ->execute([$org,$id,$location]);
  return ['id'=>$id,'email'=>$email,'membership'=>(int)$pdo->lastInsertId()];
 };
 $owner=$create('Owner');$worker=$create('Worker');
 $pdo->prepare("INSERT INTO roles (organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,'Super Admin','super_admin',1,1)")
  ->execute([$org]);$role=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)')->execute([$owner['membership'],$role]);
 $principal=['id'=>$owner['id'],'organization_id'=>$org,'is_owner_role'=>1];
 $outsider=['id'=>$worker['id'],'organization_id'=>$org,'is_owner_role'=>0];
 $secret=bin2hex(random_bytes(32));$_COOKIE[$cookieName]=$secret;
 $pdo->prepare("INSERT INTO fm_pin_devices (organization_id,secret_hash,label,created_by,expires_at) VALUES (?,?,?, ?,DATE_ADD(NOW(6),INTERVAL 30 DAY))")
  ->execute([$org,hash('sha256',$secret),'AE2 Terminal',$owner['id']]);
 $device=(int)$pdo->lastInsertId();
 ae2check(fm9c_local_staff($pdo,$org,$owner['email'])===null,'owner initially unknown');
 try{fmeyes_commit($pdo,$principal,$worker['id'],false);ae2check(false,'consent absent');}
 catch(InvalidArgumentException){ae2check(true,'consent required');}
 try{fmeyes_commit($pdo,$outsider,$worker['id'],true);ae2check(false,'nonowner accepted');}
 catch(DomainException){ae2check(true,'only owner can enroll');}
 try{fmeyes_commit($pdo,$principal,999999999,true);ae2check(false,'unknown user accepted');}
 catch(DomainException){ae2check(true,'cross-org/unavailable denied');}
 $people=fmeyes_candidates($pdo,$principal);
 ae2check(count($people)>=2,'candidate roster available');
 $saved=fmeyes_commit($pdo,$principal,$owner['id'],true);
 ae2check($saved['id']===$owner['id'],'owner consented enrollment');
 $person=fm9c_local_staff($pdo,$org,$owner['email']);
 ae2check((int)($person['id']??0)===$owner['id'],'provisional owner recognized');
 $obs=[['trackId'=>'r-1234567890abcdef','box'=>[.1,.1,.3,.3],'localProfileEmail'=>$owner['email']]];
 $room=fm9c_room_update($pdo,$principal,$obs);
 ae2check($room['people'][0]['identityAssurance']==='provisional_visual_match','never grants verified identity');
 ae2check($room['people'][0]['agentAdministration']===false,'no Agent administration');
 $revoked=fmeyes_revoke($pdo,$principal,$owner['id']);
 ae2check($revoked['email']===$owner['email'],'revocation identifies local cleanup');
 ae2check(fm9c_local_staff($pdo,$org,$owner['email'])===null,'revoked enrollment no longer recognized');
 $pdo->prepare('UPDATE fm_pin_devices SET revoked_at=NOW(6) WHERE id=?')->execute([$device]);
 try{fmeyes_commit($pdo,$principal,$worker['id'],true);ae2check(false,'revoked device accepted');}
 catch(DomainException){ae2check(true,'terminal revocation enforced');}
}finally{
 if($pdo->inTransaction())$pdo->rollBack();
 $_SESSION=$oldSession;
 if($oldCookie===null)unset($_COOKIE[$cookieName]);else $_COOKIE[$cookieName]=$oldCookie;
}
echo "Agent Eyes AE2 enrollment PASS ($n assertions)\n";
