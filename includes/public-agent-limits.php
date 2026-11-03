<?php
declare(strict_types=1);

final class PublicAgentLimitException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $retryAfter)
    {
        parent::__construct($reason==='concurrency'
            ? 'The public assistant is busy. Please try again shortly.'
            : 'The public assistant has reached its temporary usage limit. Please try again later.');
    }
}
final class PublicAgentLimitsUnavailable extends RuntimeException {}

const PUBLIC_AGENT_PROVIDER_OUTPUT_TOKENS=500;
const PUBLIC_AGENT_PROVIDER_PAYLOAD_BYTES=131072;
const PUBLIC_AGENT_PROVIDER_LEASE_SECONDS=60;

/** Operator configuration only. Zero never silently means an unlimited budget. */
function public_agent_limits_config(?array $settings=null): array
{
    if($settings===null){
        $config=app_config();$settings=array_key_exists('public_agent_limits',$config)?$config['public_agent_limits']:[];
        if(!is_array($settings))throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
    }
    $spec=[
        'network_requests_per_10_minutes'=>[20,1,1000],
        'organization_requests_per_10_minutes'=>[100,1,10000],
        'organization_token_units_per_day'=>[1000000,1,1000000000],
        'organization_concurrent_requests'=>[4,1,32],
        'provider_timeout_seconds'=>[30,1,30],
    ];
    $out=[];
    foreach($spec as $key=>[$default,$min,$max]){
        $value=array_key_exists($key,$settings)?$settings[$key]:$default;
        if(!is_int($value)||$value<$min||$value>$max)
            throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
        $out[$key]=$value;
    }
    return $out;
}

function public_agent_limits_ready(PDO $pdo): bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name IN ('public_agent_usage_buckets','public_agent_request_leases')");
    return (int)$q->fetchColumn()===2;
}

/** Only the web server's REMOTE_ADDR is accepted by the HTTP controller. */
function public_agent_network_identity(int $organizationId,string $address,?string $secret=null): string
{
    $packed=@inet_pton(trim($address));
    if(is_string($packed)&&strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")$packed=substr($packed,12);
    $network=!is_string($packed)?'unknown':(strlen($packed)===4?'v4:'.bin2hex($packed):'v6-prefix64:'.bin2hex(substr($packed,0,8)));
    if($secret===null){
        try{$secret=app_secret_key();}
        catch(Throwable){throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');}
    }
    if($organizationId<1||strlen($secret)<32)throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
    return hash_hmac('sha256','public-agent-network-v1'."\0".$organizationId."\0".$network,$secret);
}

function public_agent_limit_date(string $raw): DateTimeImmutable
{
    if(!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D',$raw,$parts))
        throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
    $normalized=$parts[1].'.'.str_pad($parts[2]??'',6,'0');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$normalized,new DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d H:i:s.u')!==$normalized)
        throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
    return $date;
}

function public_agent_limit_retry(DateTimeImmutable $until,DateTimeImmutable $now): int
{
    return max(1,min(86400,(int)ceil((float)$until->format('U.u')-(float)$now->format('U.u'))));
}

/** Durable admission must commit before a provider call; a caller cannot roll it back. */
function public_agent_limits_transaction(PDO $pdo,int $org,callable $work): mixed
{
    if($pdo->inTransaction())throw new LogicException('Public assistant admission requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");$q->execute([$org]);
        if(!$q->fetchColumn())throw new PublicAgentLimitsUnavailable('The public assistant is unavailable.');
        // Read time after the lock wait, using the database clock for every worker.
        $now=public_agent_limit_date((string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn());
        $result=$work($now);$pdo->commit();return $result;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function public_agent_limit_take(PDO $pdo,int $org,string $type,string $hash,int $amount,int $limit,int $seconds,DateTimeImmutable $now): void
{
    if(!$pdo->inTransaction()||!preg_match('/^[a-f0-9]{64}$/D',$hash)||$amount<1||$limit<1)
        throw new LogicException('Invalid public assistant admission.');
    $q=$pdo->prepare('SELECT window_started_at,window_ends_at,used_units FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type=? AND bucket_hash=? FOR UPDATE');
    $q->execute([$org,$type,$hash]);$row=$q->fetch();$used=0;$start=$now;$end=$now->modify('+'.$seconds.' seconds');
    if($row){
        $savedStart=public_agent_limit_date((string)$row['window_started_at']);$savedEnd=public_agent_limit_date((string)$row['window_ends_at']);
        if($savedStart>$now||$savedEnd<=$savedStart||$savedEnd>$savedStart->modify('+'.$seconds.' seconds')||!is_numeric($row['used_units'])||(float)$row['used_units']<0)
            throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
        if($savedEnd>$now){$used=(int)$row['used_units'];$start=$savedStart;$end=$savedEnd;}
    }
    if($amount>$limit||$used>$limit-$amount)throw new PublicAgentLimitException($type,public_agent_limit_retry($end,$now));
    $q=$pdo->prepare('INSERT INTO public_agent_usage_buckets(organization_id,bucket_type,bucket_hash,window_started_at,window_ends_at,used_units)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE window_started_at=VALUES(window_started_at),window_ends_at=VALUES(window_ends_at),used_units=VALUES(used_units)');
    $q->execute([$org,$type,$hash,$start->format('Y-m-d H:i:s.u'),$end->format('Y-m-d H:i:s.u'),$used+$amount]);
}

/** Intake protects knowledge lookups too; it is separate from the paid-provider budget. */
function public_agent_network_gate(PDO $pdo,int $org,string $address,array $limits): void
{
    $hash=public_agent_network_identity($org,$address);
    public_agent_limits_transaction($pdo,$org,static function(DateTimeImmutable $now)use($pdo,$org,$hash,$limits):void{
        $q=$pdo->prepare("DELETE FROM public_agent_usage_buckets WHERE organization_id=? AND bucket_type='network'
            AND window_ends_at<=? ORDER BY window_ends_at,bucket_hash LIMIT 100");$q->execute([$org,$now->format('Y-m-d H:i:s.u')]);
        public_agent_limit_take($pdo,$org,'network',$hash,1,$limits['network_requests_per_10_minutes'],600,$now);
    });
}

/** A conservative allocation ceiling, not a tokenizer or dollar-price estimate. */
function public_agent_payload_units(array $payload): int
{
    $bytes=strlen(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if($bytes>PUBLIC_AGENT_PROVIDER_PAYLOAD_BYTES)throw new PublicAgentLimitsUnavailable('The public assistant request is too large.');
    return $bytes+PUBLIC_AGENT_PROVIDER_OUTPUT_TOKENS+1024;
}

function public_agent_validate_lease(array $row,DateTimeImmutable $now): DateTimeImmutable
{
    $start=public_agent_limit_date((string)($row['created_at']??''));$end=public_agent_limit_date((string)($row['expires_at']??''));
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($row['lease_id']??''))||$start>$now||$end<=$start||$end>$start->modify('+'.PUBLIC_AGENT_PROVIDER_LEASE_SECONDS.' seconds')
        ||(int)($row['reserved_token_units']??0)<1||(int)$row['reserved_token_units']>PUBLIC_AGENT_PROVIDER_PAYLOAD_BYTES+PUBLIC_AGENT_PROVIDER_OUTPUT_TOKENS+1024)
        throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
    return $end;
}

function public_agent_provider_reserve(PDO $pdo,int $org,int $units,array $limits): string
{
    if($units<1||$units>PUBLIC_AGENT_PROVIDER_PAYLOAD_BYTES+PUBLIC_AGENT_PROVIDER_OUTPUT_TOKENS+1024)throw new LogicException('Invalid provider reservation.');
    return public_agent_limits_transaction($pdo,$org,static function(DateTimeImmutable $now)use($pdo,$org,$units,$limits):string{
        $q=$pdo->prepare('SELECT * FROM public_agent_request_leases WHERE organization_id=? ORDER BY expires_at,lease_id LIMIT 129 FOR UPDATE');$q->execute([$org]);$rows=$q->fetchAll();
        if(count($rows)>128)throw new PublicAgentLimitsUnavailable('Public assistant usage limits are unavailable.');
        $active=[];
        foreach($rows as $row){
            $end=public_agent_validate_lease($row,$now);
            if($end<=$now){$q=$pdo->prepare('DELETE FROM public_agent_request_leases WHERE organization_id=? AND lease_id=?');$q->execute([$org,$row['lease_id']]);}
            else $active[]=$end;
        }
        if(count($active)>=$limits['organization_concurrent_requests'])throw new PublicAgentLimitException('concurrency',public_agent_limit_retry($active[0],$now));
        $organizationHash=hash('sha256','public-agent-organization-v1');
        public_agent_limit_take($pdo,$org,'organization_requests',$organizationHash,1,$limits['organization_requests_per_10_minutes'],600,$now);
        public_agent_limit_take($pdo,$org,'organization_token_units',$organizationHash,$units,$limits['organization_token_units_per_day'],86400,$now);
        $lease=bin2hex(random_bytes(32));
        $q=$pdo->prepare('INSERT INTO public_agent_request_leases(organization_id,lease_id,reserved_token_units,created_at,expires_at) VALUES (?,?,?,?,?)');
        $q->execute([$org,$lease,$units,$now->format('Y-m-d H:i:s.u'),$now->modify('+'.PUBLIC_AGENT_PROVIDER_LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s.u')]);
        return $lease;
    });
}

function public_agent_provider_release(PDO $pdo,int $org,string $lease): void
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$lease))throw new LogicException('Invalid provider lease.');
    $q=$pdo->prepare('DELETE FROM public_agent_request_leases WHERE organization_id=? AND lease_id=?');$q->execute([$org,$lease]);
}

function public_agent_with_provider_budget(PDO $pdo,int $org,array $payload,array $limits,callable $request): mixed
{
    $lease=public_agent_provider_reserve($pdo,$org,public_agent_payload_units($payload),$limits);
    try{
        $q=$pdo->prepare('SELECT *,UTC_TIMESTAMP(6) checked_at FROM public_agent_request_leases WHERE organization_id=? AND lease_id=?');$q->execute([$org,$lease]);$row=$q->fetch();
        if(!$row)throw new PublicAgentLimitsUnavailable('The public assistant reservation expired. Please try again.');
        $now=public_agent_limit_date((string)$row['checked_at']);$end=public_agent_validate_lease($row,$now);
        if($end<=$now->modify('+'.$limits['provider_timeout_seconds'].' seconds'))throw new PublicAgentLimitsUnavailable('The public assistant reservation expired. Please try again.');
        return $request();
    }finally{
        // Failed/unknown provider usage keeps its allocation charged. A failed
        // deletion keeps the slot occupied until the independently bounded lease.
        try{public_agent_provider_release($pdo,$org,$lease);}
        catch(Throwable){error_log('[gelato-public-agent] Provider slot cleanup is pending lease expiry.');}
    }
}
