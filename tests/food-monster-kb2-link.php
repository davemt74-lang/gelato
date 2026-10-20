<?php
declare(strict_types=1);
function app_has_permission(string $key,?array $user=null):bool{
    return in_array('*',(array)($user['permissions']??[]),true)
      || in_array($key,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-vp3-link.php';
$n=0;
function must(bool $ok,string $why):void{
  global $n;if(!$ok)throw new RuntimeException("KB2 FAIL: ".$why);$n++;
}
function rejects(callable $f,string $type):bool{
  try{$f();}catch(Throwable $e){return $e instanceof $type;}return false;
}
$owner=['id'=>9,'organization_id'=>23,'is_owner_role'=>1,'permissions'=>['*']];
$staff=['id'=>2,'organization_id'=>23,'is_owner_role'=>0,'permissions'=>['menu.view']];
must(fm_kb2_owner($owner),'protected owner recognized');
must(!fm_kb2_owner($staff),'staff is not owner');
must(!fm_kb2_owner(['is_owner_role'=>1]),'identity cannot be empty');
must(fm_kb2_scopes($owner,['recipe','menu'])===['menu','recipe'],'sorted explicit scopes');
must(rejects(fn()=>fm_kb2_scopes($staff,['menu']),DomainException::class),'staff cannot approve pairing');
must(rejects(fn()=>fm_kb2_scopes($owner,[]),InvalidArgumentException::class),'no empty scopes');
must(rejects(fn()=>fm_kb2_scopes($owner,['inventory']),InvalidArgumentException::class),'unknown scope denied');
must(rejects(fn()=>fm_kb2_scopes($owner,['menu','menu']),InvalidArgumentException::class),'duplicate scopes denied');
must(rejects(fn()=>fm_kb2_scopes($owner,['menu','recipe','inventory']),InvalidArgumentException::class),'scope overflow denied');
$menuOwner=['id'=>5,'organization_id'=>23,'is_owner_role'=>1,'permissions'=>['menu.view']];
must(rejects(fn()=>fm_kb2_scopes($menuOwner,['recipe']),DomainException::class),'knowledge permissions constrain owner scope');
$secret=fm_kb2_secret('fm2_');$code=fm_kb2_secret('fmlink_');
must((bool)preg_match('/^fm2_[A-Za-z0-9_-]{43}$/D',$secret),'access credential 256-bit shape');
must((bool)preg_match('/^fmlink_[A-Za-z0-9_-]{43}$/D',$code),'single-use code 256-bit shape');
must($secret!==fm_kb2_secret('fm2_'),'secrets random each call');
must(fm_kb2_hash($secret)===hash('sha256',$secret),'hash stable');
must(fm_kb2_hash($secret)!==$secret,'clear credentials not stored');
must(fm_kb2_https(['HTTPS'=>'on']),'HTTPS request accepted');
must(fm_kb2_https(['SERVER_PORT'=>'443']),'direct TLS port accepted');
must(!fm_kb2_https(['HTTP_X_FORWARDED_PROTO'=>'https','HTTPS'=>'off','SERVER_PORT'=>'80']),'untrusted forwarded proto cannot forge TLS');
must(fm_kb2_bearer(['HTTP_AUTHORIZATION'=>'Bearer '.$secret])===$secret,'standard Authorization header');
must(fm_kb2_bearer(['REDIRECT_HTTP_AUTHORIZATION'=>'Bearer '.$secret])===$secret,'Apache redirected Authorization header');
must(rejects(fn()=>fm_kb2_bearer(['QUERY_STRING'=>'token='.$secret]),DomainException::class),'query credentials rejected');
must(rejects(fn()=>fm_kb2_bearer(['HTTP_AUTHORIZATION'=>'Bearer x']),DomainException::class),'invalid bearer rejected');
$api=file_get_contents(__DIR__.'/../api/food-monster-vp3.php');
$admin=file_get_contents(__DIR__.'/../vp3-connections.php');
$migration=file_get_contents(__DIR__.'/../database/20261020_fm_vp3_kb2_owner_pairing.sql');
must(str_contains($api,"fm_kb2_https(\$_SERVER)"),'API HTTPS gate');
must(str_contains($api,'fm_kb2_redeem(')&&str_contains($api,'fm_kb2_grant('),'exchange and grant paths');
must(str_contains($api,'fm_kb1_search('),'reuses KB1 read-only service');
must(str_contains($api,'app_json_input()'),'search query must use POST body');
must(!str_contains($api,'gelato.vp3.me')&&!str_contains($api,'foodmonster.xyz'),'no fixed domain');
must(str_contains($admin,'app_verify_csrf('),'admin POSTs CSRF protected');
must(str_contains($admin,'fm_kb2_owner('),'admin owner only');
must(str_contains($admin,'fm_kb2_revoke('),'one-click owner revocation');
must(str_contains($migration,'UNIQUE KEY uq_fm_vp3_connection_code'),'one-time code unique');
must(str_contains($migration,'UNIQUE KEY uq_fm_vp3_connection_token'),'credential unique');
must(str_contains($migration,'public_id CHAR(36)'),'stable per-install identity');
echo "FM-KB2 pure contract PASS ({$n} assertions)\n";
