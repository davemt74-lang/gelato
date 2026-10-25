<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-room-presence.php';
$pdo=app_pdo();$n=0;
function ae5check(bool $ok,string $msg):void{global $n;if(!$ok)throw new RuntimeException($msg);++$n;}
ae5check(fmeyes5_ready($pdo),'AE5 migration required');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$original=$_SESSION;$pdo->beginTransaction();
try{
 $pdo->exec("INSERT INTO organizations (name,timezone) VALUES ('AE5 CI','America/Phoenix')");
 $org=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
 VALUES (?,?,?,?,?,'active')")->execute(['ae5_'.bin2hex(random_bytes(5)).'@example.test','x','Taylor','Test','Taylor Kitchen']);
 $person=(int)$pdo->lastInsertId();
 $owner=['id'=>19001,'organization_id'=>$org,'is_owner_role'=>1];
 $worker=['id'=>19002,'organization_id'=>$org,'is_owner_role'=>0];
 $seen=['trackId'=>'r-'.str_repeat('a',16),'personId'=>$person,'displayName'=>'Taylor Kitchen',
   'identitySource'=>'local_face_profile','identityAssurance'=>'provisional_visual_match'];
 $now=time();
 $g=fmeyes5_observe($pdo,$owner,[$seen],$now,true);
 ae5check(count($g)===1&&str_contains($g[0]['text'],'Taylor'),'first arrival offered greeting');
 ae5check(($g[0]['provisional']??false)===true,'greeting never asserts authentication');
 $again=fmeyes5_observe($pdo,$owner,[$seen],$now+2,true);
 ae5check($again===[],'consecutive camera observations do not repeat greeting');
 $unknown=['trackId'=>'r-'.str_repeat('b',16),'personId'=>null,
   'identitySource'=>'unknown','identityAssurance'=>'none'];
 fmeyes5_observe($pdo,$owner,[$unknown],$now+3,true);
 ae5check(count(fmeyes5_timeline($pdo,$owner))===2,'unknown visitor causes no persisted event');
 fmeyes5_observe($pdo,$owner,[], $now+11,true);
 $rows=fmeyes5_timeline($pdo,$owner,$person);
 ae5check(count($rows)===3&&$rows[0]['type']==='departure','disappearance emits one departure');
 fmeyes5_observe($pdo,$owner,[], $now+12,true);
 ae5check(count(fmeyes5_timeline($pdo,$owner))===3,'no duplicate departure');
 $repeat=fmeyes5_observe($pdo,$owner,[$seen],$now+20,true);
 ae5check($repeat===[],'two-hour server greeting cooldown survives reentry');
 ae5check(count(fmeyes5_timeline($pdo,$owner))===4,'reentry is a new arrival');
 fmeyes5_stop($pdo,$owner,$now+21);
 ae5check(count(fmeyes5_timeline($pdo,$owner))===5,'camera stop records departure');
 ae5check(!isset($_SESSION['fmeyes5_presence']),'stop clears tracking session');
 fmeyes5_stop($pdo,$owner,$now+22);
 ae5check(count(fmeyes5_timeline($pdo,$owner))===5,'repeated stop is idempotent');
 try{fmeyes5_timeline($pdo,$worker);ae5check(false,'unauthorized timeline');}
 catch(DomainException){ae5check(true,'timeline limited to Super Admin');}
 $outside=['id'=>19003,'organization_id'=>$org+1000,'is_owner_role'=>1];
 ae5check(fmeyes5_timeline($pdo,$outside)===[],'tenant isolation enforced');
 $joined=implode('|',array_column($rows,'type'));
 ae5check(!str_contains($joined,'descriptor')&&!str_contains($joined,'face'),'no biometric timeline payloads');
}finally{$pdo->rollBack();$_SESSION=$original;}
echo "Agent Eyes AE5 presence timeline PASS ($n assertions)\n";
