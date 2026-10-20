<?php
declare(strict_types=1);
// Creates a safe isolated kb2_test database fixture with real MySQL 8.
require __DIR__.'/food-monster-kb2-mysql.php';
require_once __DIR__.'/../includes/food-monster-knowledge-manifest.php';

function kb5_real(bool $pass,string $message):void
{
    global $n;
    if(!$pass)throw new RuntimeException('KB5A MYSQL FAIL: '.$message);
    $n++;
}
function kb5_unauthorized(callable $fn):bool
{
    try{$fn();}catch(DomainException $e){return true;}
    return false;
}

$pdo->exec("CREATE TABLE recipes (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    public_id VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL,
    archived_at DATETIME(6) NULL,
    description TEXT NULL,
    category VARCHAR(40) NULL,
    ingredients_json TEXT NULL,
    instructions_json TEXT NULL,
    notes TEXT NULL,
    updated_at DATETIME(6) NULL,
    INDEX idx_recipe_org_cursor (organization_id,id)
)");
$pdo->exec("INSERT INTO recipes (id,organization_id,public_id,name,status,description,ingredients_json,updated_at) VALUES
    (1,23,'tenant-a','Restaurant A Secret Dough','active','Private','[{\"quantity\":650,\"ingredient\":\"flour\"}]','2026-10-20 09:00:00'),
    (2,24,'owner-b-1','Restaurant B Dough','active','Authorized','[{\"quantity\":750,\"ingredient\":\"flour\"}]','2026-10-20 09:00:00'),
    (3,24,'owner-b-2','Second Recipe','active','Authorized','[]','2026-10-20 09:00:00'),
    (4,24,'owner-b-draft','Draft Recipe','draft','Private draft','[]','2026-10-20 09:00:00'),
    (5,24,'owner-b-inactive','Retired Recipe','inactive','Private','[]','2026-10-20 09:00:00'),
    (6,24,'owner-b-archived','Archived Recipe','active','Private','[]','2026-10-20 09:00:00'),
    (7,24,'owner-b-3','Third Recipe','active','Authorized','[]','2026-10-20 09:00:00')");
$pdo->exec("UPDATE recipes SET archived_at='2026-10-20 10:00:00' WHERE id=6");
$secondOwner=['id'=>10,'organization_id'=>24,'is_owner_role'=>1,'permissions'=>['*']];
$link=fm_kb2_create($pdo,$secondOwner,'KB5A Integration',['recipe']);
$bearer=fm_kb2_redeem($pdo,$link['pairingCode'])['accessToken'];
$grant=fm_kb2_grant($pdo,$bearer);
kb5_real($grant['user']['organization_id']===24,'source token tied to restaurant B only');
kb5_real($grant['scopes']===['recipe'],'source token has explicit recipe-only scope');
$first=fm_kb5a_page($pdo,$grant['user'],'recipe',0,1);
kb5_real($first['count']===1 && $first['complete']===false,'first page incomplete');
kb5_real($first['results'][0]['id']==='gelato:recipe:owner-b-1','first ID belongs to approved restaurant');
kb5_real($first['nextAfterId']===2,'first cursor source ID');
kb5_real(!str_contains(json_encode($first), 'Restaurant A Secret Dough'),'foreign tenant recipe absent');
$second=fm_kb5a_page($pdo,$grant['user'],'recipe',$first['nextAfterId'],1);
kb5_real($second['results'][0]['id']==='gelato:recipe:owner-b-2','second page correctly advances');
kb5_real($second['complete']===false&&$second['nextAfterId']===3,'cursor advances over active records');
$third=fm_kb5a_page($pdo,$grant['user'],'recipe',$second['nextAfterId'],1);
kb5_real($third['count']===1&&$third['results'][0]['id']==='gelato:recipe:owner-b-3','inactive/draft/archived excluded');
kb5_real($third['complete']===true&&$third['nextAfterId']===null,'complete only after last active source');
kb5_real(!str_contains(json_encode([$first,$second,$third]),'Draft Recipe'),'draft excluded');
kb5_real(!str_contains(json_encode([$first,$second,$third]),'Archived Recipe'),'archived excluded');
kb5_real(kb5_unauthorized(fn()=>fm_kb5a_page($pdo,$grant['user'],'menu',0,1)),'menu enumeration denied to recipe-only grant');
$oldRevision=$third['results'][0]['revision'];
$pdo->exec("UPDATE recipes SET notes='Adjusted batch procedure',updated_at='2026-10-20 11:00:00' WHERE id=7");
$newRevision=fm_kb5a_page($pdo,$grant['user'],'recipe',3,20)['results'][0]['revision'];
kb5_real($newRevision!==$oldRevision,'content and timestamp revisions detect modifications');
$pdo->exec("UPDATE recipes SET archived_at='2026-10-20 12:00:00' WHERE id=3");
$sweep=fm_kb5a_page($pdo,$grant['user'],'recipe',0,20);
kb5_real($sweep['complete']===true&&$sweep['count']===2,'deleted/archived source missing on completed sweep');
kb5_real(!str_contains(json_encode($sweep),'owner-b-2'),'archived source omitted from current indexable collection');
fm_kb4b_revoke_self($pdo,$bearer);
kb5_real(kb5_unauthorized(fn()=>fm_kb2_grant($pdo,$bearer)),'source revoke prevents future manifest authorization');
echo "FM-KB5A MySQL manifest PASS (".($n-26)." additional assertions; total={$n})\n";
