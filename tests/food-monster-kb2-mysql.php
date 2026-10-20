<?php
declare(strict_types=1);
function app_has_permission(string $key,?array $user=null):bool{
    return in_array('*',(array)($user['permissions']??[]),true)
      || in_array($key,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-vp3-link.php';
$dsn=(string)getenv('FM_KB2_TEST_DSN');
if($dsn===''||!str_contains($dsn,'dbname=kb2_test'))
    throw new RuntimeException('This integration test requires dedicated kb2_test MySQL only.');
$pdo=new PDO($dsn,(string)getenv('FM_KB2_TEST_USER'),(string)getenv('FM_KB2_TEST_PASSWORD'),
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL,
 archived_at DATETIME NULL)");
$pdo->exec("CREATE TABLE organization_memberships (id BIGINT UNSIGNED PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,organization_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL)");
$pdo->exec("CREATE TABLE roles (id BIGINT UNSIGNED PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,
 is_owner_role TINYINT(1) NOT NULL, slug VARCHAR(60) NOT NULL,is_system_role TINYINT(1) NOT NULL)");
$pdo->exec("CREATE TABLE user_roles (membership_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,revoked_at DATETIME NULL)");
$sql=file_get_contents(__DIR__.'/../database/20261020_fm_vp3_kb2_owner_pairing.sql');
foreach(explode(';',(string)$sql) as $part)if(trim($part)!=='')$pdo->exec($part);
$pdo->exec("INSERT INTO users (id,status) VALUES (9,'active'),(10,'active'),(11,'active')");
$pdo->exec("INSERT INTO organization_memberships (id,user_id,organization_id,status)
  VALUES (19,9,23,'active'),(20,10,24,'active'),(21,11,23,'active')");
$pdo->exec("INSERT INTO roles (id,organization_id,is_owner_role,slug,is_system_role)
 VALUES (29,23,1,'owner',1),(30,24,1,'owner',1),(31,23,0,'staff',0)");
$pdo->exec("INSERT INTO user_roles (membership_id,role_id) VALUES (19,29),(20,30),(21,31)");
$n=0;
function ok2(bool $pass,string $message):void{
    global $n;
    if(!$pass)throw new RuntimeException('KB2 MYSQL FAIL: '.$message);
    $n++;
}
function fail2(callable $fn):bool{try{$fn();}catch(DomainException $e){return true;}return false;}
$owner=['id'=>9,'organization_id'=>23,'is_owner_role'=>1,'permissions'=>['*']];
$other=['id'=>10,'organization_id'=>24,'is_owner_role'=>1,'permissions'=>['*']];
$staff=['id'=>11,'organization_id'=>23,'is_owner_role'=>0,'permissions'=>['menu.view']];
ok2(strlen(fm_kb2_instance($pdo))===36,'stable installed identity');
ok2(fm_kb2_instance($pdo)===fm_kb2_instance($pdo),'same instance identity each read');
ok2(fm_kb2_active_owner($pdo,23,9),'active owning admin');
ok2(!fm_kb2_active_owner($pdo,24,9),'owner cannot cross tenant');
ok2(!fm_kb2_active_owner($pdo,23,11),'staff role blocked');
$grant=fm_kb2_create($pdo,$owner,'Restaurant laptop',['menu']);
ok2(str_starts_with($grant['pairingCode'],'fmlink_'),'admin generated setup code');
$stored=$pdo->query("SELECT * FROM fm_vp3_connections LIMIT 1")->fetch();
ok2($stored['pairing_code_sha256']===hash('sha256',$grant['pairingCode']),'code stored only as a digest');
ok2(!str_contains(json_encode($stored),$grant['pairingCode']),'plaintext code not stored');
ok2(fail2(fn()=>fm_kb2_redeem($pdo,'fmlink_'.str_repeat('A',43))),'invalid code rejected');
$result=fm_kb2_redeem($pdo,$grant['pairingCode']);
ok2(($result['tokenType']??'')==='Bearer'&&str_starts_with($result['accessToken'],'fm2_'),'single redemption issues bearer');
ok2($result['instanceId']===fm_kb2_instance($pdo),'return correct instance identity');
ok2(fail2(fn()=>fm_kb2_redeem($pdo,$grant['pairingCode'])),'replay cannot redeem pairing code');
$stored=$pdo->query("SELECT * FROM fm_vp3_connections LIMIT 1")->fetch();
ok2($stored['pairing_code_sha256']===null&&$stored['access_token_sha256']===fm_kb2_hash($result['accessToken']),'code destroyed and access token hashed');
$auth=fm_kb2_grant($pdo,$result['accessToken']);
ok2($auth['user']['organization_id']===23,'grant bound to approved tenant');
ok2($auth['user']['permissions']===['menu.view'],'menu-only grant cannot read recipes');
ok2(fail2(fn()=>fm_kb2_grant($pdo,'fm2_'.str_repeat('B',43))),'unknown credential denied');
ok2(!fm_kb2_revoke($pdo,$other,$grant['id']),'other tenant cannot revoke');
ok2(fm_kb2_revoke($pdo,$owner,$grant['id']),'owning admin can revoke');
ok2(fail2(fn()=>fm_kb2_grant($pdo,$result['accessToken'])),'revoked credential immediately blocked');
$second=fm_kb2_create($pdo,$owner,'Revoked before acceptance',['recipe']);
ok2(fm_kb2_revoke($pdo,$owner,$second['id']),'pending code revocable');
ok2(fail2(fn()=>fm_kb2_redeem($pdo,$second['pairingCode'])),'revoked pending code unusable');
$third=fm_kb2_create($pdo,$owner,'Owner suspension',['recipe','menu']);
$thirdToken=fm_kb2_redeem($pdo,$third['pairingCode'])['accessToken'];
$thirdAuth=fm_kb2_grant($pdo,$thirdToken);
ok2(in_array('recipes.agent',$thirdAuth['user']['permissions'],true),'recipe permission only when granted');
$pdo->exec("UPDATE users SET status='inactive' WHERE id=9");
ok2(fail2(fn()=>fm_kb2_grant($pdo,$thirdToken)),'owner suspension revokes effective access');
$pdo->exec("UPDATE users SET status='active' WHERE id=9");
$pdo->exec("UPDATE user_roles SET revoked_at=NOW() WHERE membership_id=19");
ok2(fail2(fn()=>fm_kb2_grant($pdo,$thirdToken)),'role revocation fails closed');
$listed=fm_kb2_connections($pdo,$owner);
ok2(count($listed)===3,'owner sees own connection inventory');
ok2(!isset($listed[0]['access_token_sha256'])&&!isset($listed[0]['pairing_code_sha256']),'inventory hides credential digests');
ok2(count(fm_kb2_connections($pdo,$other))===0,'other organization inventory isolated');
ok2(fm_kb2_revoke($pdo,$owner,$third['id']),'owner can revoke after role change (underlying admin endpoint still requires owner login)');
echo "FM-KB2 MySQL integration PASS ({$n} assertions)\n";
