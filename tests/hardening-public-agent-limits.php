<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';

$checks=0;
function hpal_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function hpal_value(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function hpal_org(PDO $pdo): int {$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Public assistant limits '.bin2hex(random_bytes(6))]);return (int)$pdo->lastInsertId();}
function hpal_reset(PDO $pdo,int $org): void {
    $pdo->prepare('DELETE FROM public_agent_request_leases WHERE organization_id=?')->execute([$org]);
    $pdo->prepare('DELETE FROM public_agent_usage_buckets WHERE organization_id=?')->execute([$org]);
}
function hpal_denied(callable $work,string $type,string $message): Throwable {
    try{$work();}catch(Throwable $error){hpal_check($error instanceof $type,$message.' (expected '.$type.', got '.get_class($error).')');return $error;}
    throw new RuntimeException($message.' must be denied.');
}

// Execute the exact original API limiter, with no external provider call. Its
// old function body is extracted by PHP tokens; no new helper is used in this mode.
if(($argv[1]??'')==='baseline'){
    $path=$argv[2]??'';if(!is_file($path))throw new RuntimeException('An immutable original public-agent.php path is required.');
    $tokens=token_get_all((string)file_get_contents($path));$source='';$collect=false;$depth=0;$entered=false;
    foreach($tokens as $index=>$token){
        if(is_array($token)&&$token[0]===T_FUNCTION){
            $next=$index+1;while(isset($tokens[$next])&&is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE)$next++;
            if(is_array($tokens[$next]??null)&&$tokens[$next][1]==='public_agent_rate_limit')$collect=true;
        }
        if(!$collect)continue;$text=is_array($token)?$token[1]:$token;$source.=$text;
        if($token==='{'){$depth++;$entered=true;}elseif($token==='}'){$depth--;if($entered&&$depth===0)break;}
    }
    if($source===''||!str_contains($source,'$_SESSION'))throw new RuntimeException('The original limiter function was not found.');
    eval($source);$admitted=0;
    for($i=0;$i<21;$i++){
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();session_id(bin2hex(random_bytes(16)));
        public_agent_rate_limit(1);$admitted++;session_destroy();
    }
    hpal_check($admitted<=20,'Fresh sessions must not obtain provider admission beyond the shared network limit.');
    exit;
}

require_once __DIR__.'/../includes/public-agent-core.php';
$pdo=app_pdo();hpal_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!(bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),'Limits use native MariaDB transactions.');
hpal_check(public_agent_limits_ready($pdo),'The additive public assistant limit migration must be installed.');
$org=hpal_org($pdo);$other=hpal_org($pdo);$limits=public_agent_limits_config();$secret=str_repeat('x',32);
$identity=public_agent_network_identity($org,'203.0.113.8',$secret);
hpal_check(strlen($identity)===64&&!str_contains($identity,'203.0.113.8'),'Network identity stores a keyed digest, not an IP.');
hpal_check($identity===public_agent_network_identity($org,'::ffff:203.0.113.8',$secret),'IPv4-mapped IPv6 cannot obtain another quota.');
hpal_check($identity!==public_agent_network_identity($other,'203.0.113.8',$secret),'The same network cannot be correlated across tenant digests.');
hpal_check($identity!==public_agent_network_identity($org,'203.0.113.8',str_repeat('y',32)),'A database-only attacker cannot enumerate unkeyed IP digests.');
hpal_check(public_agent_network_identity($org,'2001:db8:1:2::1',$secret)===public_agent_network_identity($org,'2001:db8:1:2::abcd',$secret),'IPv6 privacy addresses in a /64 share their quota.');
hpal_check(public_agent_network_identity($org,'2001:db8:1:2::1',$secret)!==public_agent_network_identity($org,'2001:db8:1:3::1',$secret),'Separate IPv6 networks have separate identities.');
hpal_check(public_agent_network_identity($org,'',$secret)===public_agent_network_identity($org,'invalid',$secret),'Unknown network evidence shares one restrictive bucket.');
hpal_denied(fn()=>public_agent_network_identity($org,'127.0.0.1','short'),PublicAgentLimitsUnavailable::class,'An invalid HMAC secret fails closed');
foreach(['network_requests_per_10_minutes','organization_requests_per_10_minutes','organization_token_units_per_day','organization_concurrent_requests','provider_timeout_seconds'] as $key){
    foreach([0,-1,'unlimited',true,null,1.5] as $value)hpal_denied(fn()=>public_agent_limits_config([$key=>$value]),PublicAgentLimitsUnavailable::class,'Invalid limits cannot disable admission');
}

$admitted=0;
for($i=0;$i<21;$i++){
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();session_id(bin2hex(random_bytes(16)));
    public_agent_rate_limit($org);
    hpal_check(session_status()!==PHP_SESSION_ACTIVE,'The secondary session lock closes before shared admission.');
    try{public_agent_network_gate($pdo,$org,'203.0.113.8',$limits);$admitted++;}
    catch(PublicAgentLimitException $error){hpal_check($error->reason==='network'&&$error->retryAfter>0&&$error->retryAfter<=600,'Shared network denial supplies a bounded retry.');}
}
hpal_check($admitted<=20,'Fresh sessions must not obtain provider admission beyond the shared network limit.');
hpal_check($admitted===20,'The configured network allowance is usable.');
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='network'",[$org])===20,'Denied admission does not increment the shared counter.');
public_agent_network_gate($pdo,$other,'203.0.113.8',$limits);
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='network'",[$other])===1,'One tenant exhaustion does not consume another tenant allowance.');
$pdo->prepare("UPDATE public_agent_usage_buckets SET window_started_at=UTC_TIMESTAMP(6)-INTERVAL 601 SECOND,window_ends_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=?")->execute([$org]);
public_agent_network_gate($pdo,$org,'203.0.113.8',$limits);
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='network'",[$org])===1,'Expired intake windows permit fresh admission.');

hpal_reset($pdo,$org);$small=array_replace($limits,['organization_requests_per_10_minutes'=>2,'organization_concurrent_requests'=>1]);
$payload=['input'=>'What pizza is available?','max_output_tokens'=>500];$units=public_agent_payload_units($payload);$calls=0;
$result=public_agent_with_provider_budget($pdo,$org,$payload,$small,function()use(&$calls){$calls++;return 'answer';});
hpal_check($result==='answer'&&$calls===1,'A reserved request executes its provider exactly once.');
hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===0,'Success releases the provider slot.');
hpal_denied(fn()=>public_agent_with_provider_budget($pdo,$org,$payload,$small,static function(){throw new RuntimeException('provider failed');}),RuntimeException::class,'Provider errors propagate');
hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===0,'Provider error releases the slot.');
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_token_units'",[$org])===2*$units,'Unknown/error provider usage remains conservatively charged.');
$error=hpal_denied(function()use($pdo,$org,$payload,$small,&$calls){return public_agent_with_provider_budget($pdo,$org,$payload,$small,function()use(&$calls){$calls++;});},PublicAgentLimitException::class,'Aggregate provider request limit holds');
hpal_check($error->reason==='organization_requests'&&$calls===1,'Rejected organization requests never reach the provider.');
hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===0,'Rejected organization requests allocate no slot.');

hpal_reset($pdo,$org);$tokens=array_replace($limits,['organization_token_units_per_day'=>$units]);
public_agent_with_provider_budget($pdo,$org,$payload,$tokens,static fn()=>'first');
$error=hpal_denied(fn()=>public_agent_with_provider_budget($pdo,$org,$payload,$tokens,static fn()=>'second'),PublicAgentLimitException::class,'Daily conservative allocation is enforced');
hpal_check($error->reason==='organization_token_units'&&$error->retryAfter<=86400,'Budget exhaustion supplies the daily reset boundary.');
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_requests'",[$org])===1,'A token-budget denial rolls back its provider request reservation.');
$pdo->prepare("UPDATE public_agent_usage_buckets SET window_started_at=UTC_TIMESTAMP(6)-INTERVAL 86401 SECOND,window_ends_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND bucket_type='organization_token_units'")->execute([$org]);
public_agent_with_provider_budget($pdo,$org,$payload,$tokens,static fn()=>'new window');
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_token_units'",[$org])===$units,'A daily reset renews only its budget window.');
hpal_denied(fn()=>public_agent_payload_units(['input'=>str_repeat('x',PUBLIC_AGENT_PROVIDER_PAYLOAD_BYTES+1)]),PublicAgentLimitsUnavailable::class,'Oversized serialized prompts cannot be sent');
hpal_check(public_agent_payload_units(['input'=>str_repeat('é',100)])>public_agent_payload_units(['input'=>str_repeat('e',100)]),'Unicode reservations count bytes, not undercounted characters.');

hpal_reset($pdo,$org);$lease=public_agent_provider_reserve($pdo,$org,$units,$small);
$error=hpal_denied(fn()=>public_agent_provider_reserve($pdo,$org,$units,$small),PublicAgentLimitException::class,'An active slot prevents oversubscribed provider work');
hpal_check($error->reason==='concurrency','Busy reservations are distinguished from budget exhaustion.');
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_requests'",[$org])===1,'Busy denial does not consume a second provider request.');
public_agent_provider_release($pdo,$other,$lease);
hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===1,'A foreign tenant cannot release a provider slot.');
$pdo->prepare('UPDATE public_agent_request_leases SET created_at=UTC_TIMESTAMP(6)-INTERVAL 61 SECOND,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE organization_id=? AND lease_id=?')->execute([$org,$lease]);
$newLease=public_agent_provider_reserve($pdo,$org,$units,$small);
hpal_check($newLease!==$lease&&(int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===1,'Expired crash leases are reclaimed before new admission.');
public_agent_provider_release($pdo,$org,$lease);
hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=? AND lease_id=?',[$org,$newLease])===1,'Late completion cannot free a replacement lease.');
$pdo->prepare('UPDATE public_agent_request_leases SET expires_at=DATE_ADD(created_at,INTERVAL 1 DAY) WHERE organization_id=?')->execute([$org]);
hpal_denied(fn()=>public_agent_provider_reserve($pdo,$org,$units,$small),PublicAgentLimitsUnavailable::class,'Malformed long leases fail closed instead of silently freeing capacity');
hpal_reset($pdo,$org);
$pdo->beginTransaction();$pdo->prepare("UPDATE organizations SET name='Caller change survives' WHERE id=?")->execute([$org]);
hpal_denied(fn()=>public_agent_provider_reserve($pdo,$org,$units,$small),LogicException::class,'Admission cannot hide inside a caller-owned transaction');
hpal_check($pdo->inTransaction()&&hpal_value($pdo,'SELECT name FROM organizations WHERE id=?',[$org])==='Caller change survives','Rejected nested admission preserves caller work.');$pdo->rollBack();

$trigger='hpal_fail_'.bin2hex(random_bytes(5));
try{
    $pdo->exec("CREATE TRIGGER ".$trigger." BEFORE INSERT ON public_agent_request_leases FOR EACH ROW BEGIN IF NEW.organization_id=".$org." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected admission failure'; END IF; END");
    $beforeFailure=$calls;hpal_denied(function()use($pdo,$org,$payload,$limits,&$calls){return public_agent_with_provider_budget($pdo,$org,$payload,$limits,function()use(&$calls){$calls++;});},PDOException::class,'Storage failure prevents provider work');
    hpal_check($calls===$beforeFailure,'Provider work cannot start after an admission storage failure.');
    hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_usage_buckets WHERE organization_id=?',[$org])===0,'Failed slot persistence rolls back all provider budget changes.');
    hpal_check((int)hpal_value($pdo,'SELECT COUNT(*) FROM public_agent_request_leases WHERE organization_id=?',[$org])===0,'Failed slot persistence leaves no phantom lease.');
}finally{$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);}

hpal_reset($pdo,$org);$originalTimezone=date_default_timezone_get();date_default_timezone_set('America/Phoenix');
public_agent_with_provider_budget($pdo,$org,$payload,$limits,static fn()=>'UTC remains authoritative');date_default_timezone_set($originalTimezone);
hpal_check((int)hpal_value($pdo,"SELECT used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='organization_requests'",[$org])===1,'Application timezone cannot alter the database UTC admission window.');
echo "Public assistant shared limits: {$checks} checks passed.\n";
