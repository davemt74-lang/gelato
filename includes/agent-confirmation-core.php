<?php
declare(strict_types=1);

function gac_key(int $organizationId,int $userId): string
{
    return $organizationId.':'.$userId;
}

function gac_pending_get(string $node,int $organizationId,int $userId): ?array
{
    $key=gac_key($organizationId,$userId);
    $row=$_SESSION['agent_node_pending'][$node][$key]??null;
    if(!is_array($row)||((int)($row['expires']??0))<time()){
        unset($_SESSION['agent_node_pending'][$node][$key]);
        return null;
    }
    return $row;
}

function gac_pending_store(string $node,int $organizationId,int $userId,string $type,array $payload,string $summary,int $ttl=600): array
{
    $row=[
        'id'=>'gap-'.bin2hex(random_bytes(8)),
        'node'=>$node,
        'organizationId'=>$organizationId,
        'userId'=>$userId,
        'type'=>$type,
        'payload'=>$payload,
        'summary'=>$summary,
        'created'=>time(),
        'expires'=>time()+max(60,min(1800,$ttl)),
    ];
    $_SESSION['agent_node_pending'][$node][gac_key($organizationId,$userId)]=$row;
    return $row;
}

function gac_pending_clear(string $node,int $organizationId,int $userId): void
{
    unset($_SESSION['agent_node_pending'][$node][gac_key($organizationId,$userId)]);
}

function gac_proposal_result(array $proposal,string $skill,array $sources=[]): array
{
    return [
        'ok'=>true,
        'skill'=>$skill,
        'answer'=>(string)$proposal['summary']."\n\nReply Confirm to execute this change, or Cancel to discard it.",
        'data'=>[
            'requiresConfirmation'=>true,
            'proposal'=>[
                'id'=>$proposal['id'],
                'node'=>$proposal['node'],
                'type'=>$proposal['type'],
                'summary'=>$proposal['summary'],
                'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires']),
            ],
        ],
        'sources'=>$sources,
    ];
}

function gac_is_confirm(string $message): bool
{
    return preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply)(?:\s+(?:it|that|change|action))?[.!]?$/i',trim($message))===1;
}

function gac_is_cancel(string $message): bool
{
    return preg_match('/^(?:cancel|cancel it|discard|never mind|nevermind|stop)(?:\s+(?:it|that|change|action))?[.!]?$/i',trim($message))===1;
}
