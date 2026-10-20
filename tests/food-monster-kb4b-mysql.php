<?php
declare(strict_types=1);
// Reuse the real isolated MySQL KB2 setup and assertions. This script must
// run only on the throwaway kb2_test database; the included fixture enforces
// that guard before creating tables.
require __DIR__.'/food-monster-kb2-mysql.php';

function kb4b_assert(bool $ok,string $message):void
{
    global $n;
    if(!$ok)throw new RuntimeException('KB4B MYSQL FAIL: '.$message);
    $n++;
}
function kb4b_denied(callable $fn):bool
{
    try{$fn();}catch(DomainException $e){return true;}
    return false;
}

// Different organization and owner: previous KB2 fixture already added this
// protected owner but did not give it any connections.
$first=fm_kb2_create($pdo,$other,'HomeServer Alpha',['menu']);
$second=fm_kb2_create($pdo,$other,'HomeServer Beta',['recipe']);
$one=fm_kb2_redeem($pdo,$first['pairingCode']);
$two=fm_kb2_redeem($pdo,$second['pairingCode']);
kb4b_assert(fm_kb2_grant($pdo,$one['accessToken'])['scopes']===['menu'],'first bearer grants only menu');
kb4b_assert(fm_kb2_grant($pdo,$two['accessToken'])['scopes']===['recipe'],'second bearer grants only recipes');
$revoked=fm_kb4b_revoke_self($pdo,$one['accessToken']);
kb4b_assert($revoked['revoked']===true,'self revocation confirms success');
kb4b_assert($revoked['connectionId']===$one['connectionId'],'self revocation returns own connection only');
kb4b_assert($revoked['instanceId']===fm_kb2_instance($pdo),'revocation has pinned source identity');
kb4b_assert(kb4b_denied(fn()=>fm_kb2_grant($pdo,$one['accessToken'])),'revoked bearer immediately cannot read');
kb4b_assert(kb4b_denied(fn()=>fm_kb4b_revoke_self($pdo,$one['accessToken'])),'replay of self revocation rejected');
kb4b_assert(fm_kb2_grant($pdo,$two['accessToken'])['scopes']===['recipe'],'other grant unaffected by self revocation');
$inspect=$pdo->prepare('SELECT status,revoked_at,access_token_sha256,pairing_code_sha256 FROM fm_vp3_connections WHERE public_id=?');
$inspect->execute([$one['connectionId']]);$row=$inspect->fetch(PDO::FETCH_ASSOC);
kb4b_assert($row['status']==='revoked'&&$row['revoked_at']!==null,'source marked revoked');
kb4b_assert($row['access_token_sha256']===null&&$row['pairing_code_sha256']===null,'both credential digests destroyed');
kb4b_assert(kb4b_denied(fn()=>fm_kb4b_revoke_self($pdo,'fm2_'.str_repeat('Z',43))),'unknown bearer cannot revoke');
kb4b_assert(kb4b_denied(fn()=>fm_kb4b_revoke_self($pdo,'bad')),'malformed bearer cannot revoke');
$pdo->exec("UPDATE users SET status='inactive' WHERE id=10");
kb4b_assert(kb4b_denied(fn()=>fm_kb2_grant($pdo,$two['accessToken'])),'inactive owner cannot read even before revocation');
$revoked2=fm_kb4b_revoke_self($pdo,$two['accessToken']);
kb4b_assert($revoked2['connectionId']===$two['connectionId'],'suspended owner bearer can still be destroyed');
kb4b_assert(kb4b_denied(fn()=>fm_kb2_grant($pdo,$two['accessToken'])),'destroyed suspended owner bearer cannot read');
$api=file_get_contents(__DIR__.'/../api/food-monster-vp3.php');
kb4b_assert(str_contains($api,"if(\$action==='revoke')"),'revoke API registered');
kb4b_assert(str_contains($api,'fm_kb4b_revoke_self(app_pdo(),fm_kb2_bearer($_SERVER))'),'revoke requires actual Authorization bearer');
kb4b_assert(!str_contains($api,"\$_GET['token']"),'credential never read from URL');
echo "FM-KB4B source revocation PASS (".($n-26)." additional assertions; total={$n})\n";
