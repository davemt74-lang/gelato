<?php
declare(strict_types=1);

/**
 * FM-B8D17A: describe what this authenticated account can discover *now*.
 * This is a side-effect-free registry snapshot, NOT a tool execution test or
 * an alternate authorization path. Every later call rechecks its own policy.
 */
require_once __DIR__.'/food-monster-skill-registry.php';

function fm8d17a_candidates():array
{
    $raw=@file_get_contents(__DIR__.'/../config/food-monster-capability-map.json');
    if(!is_string($raw))return ['status'=>'unavailable','items'=>[]];
    try{$map=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    catch(JsonException){return ['status'=>'unavailable','items'=>[]];}
    if(!is_array($map)||($map['executionGrant']??true)!==false
       ||!is_array($map['skillContractCandidates']??null))
        return ['status'=>'unavailable','items'=>[]];
    $items=[];
    foreach($map['skillContractCandidates'] as $candidate){
        if(!is_array($candidate)||($candidate['execution']??null)!=='contract_candidate_not_granted')
            return ['status'=>'unavailable','items'=>[]];
        $items[]=[
            'id'=>(string)($candidate['id']??''),
            'label'=>(string)($candidate['label']??''),
            'feature'=>(string)($candidate['feature']??''),
            'status'=>'proposed_not_executable',
            'executionVerified'=>false,
        ];
    }
    return ['status'=>'source_inventory_only','items'=>$items];
}

/**
 * Optional injected registries keep this report deterministic in tests.
 * A registered tool may still fail at execution time because of current
 * records, provider availability or a fresh permission check.
 */
function fm8d17a_snapshot(
    array $actor,array $context=[],?array $tools=null,?array $domains=null,
    ?array $prompts=null,?array $candidateInventory=null
):array{
    if((int)($actor['id']??0)<1||(int)($actor['organization_id']??0)<1)
        throw new DomainException('A current authenticated Gelato session is required.');
    $tools=$tools??fm8d3_catalogue($actor,$context);
    $domains=$domains??fm8d11_available_domains($actor);
    $prompts=$prompts??fm_skill_registry_for_user($actor);

    $native=[];
    foreach($tools as $name=>$tool){
        if(!is_string($name)||!is_array($tool))continue;
        $impact=(string)($tool['impact']??'unknown');
        $native[]=[
            'id'=>$name,
            'impact'=>$impact,
            'status'=>'registered_for_current_account',
            'executionVerified'=>false,
            'permissionRecheckedOnUse'=>true,
            'approval'=>'existing_gelato_policy',
        ];
    }
    $nodes=[];
    foreach($domains as $id=>$domain){
        if(!is_string($id)||!is_array($domain))continue;
        $nodes[]=[
            'id'=>$id,'label'=>(string)($domain['label']??$id),
            'mode'=>(string)($domain['mode']??'unknown'),
            'status'=>'registered_delegation_only',
            'executionVerified'=>false,
            'destinationPolicyRequired'=>true,
        ];
    }
    $templates=[];
    foreach($prompts as $prompt){
        if(!is_array($prompt))continue;
        $templates[]=[
            'id'=>(string)($prompt['id']??''),
            'label'=>(string)($prompt['label']??''),
            'status'=>'prompt_template_only',
            'executionVerified'=>false,
        ];
    }

    // Do not expose the privileged administration action inventory to a
    // clocked-in employee merely because the source file is on the server.
    $owner=($actor['role_slug']??'')==='super_admin';
    $inventory=$owner?($candidateInventory??fm8d17a_candidates()):null;
    $planned=$owner?(array)($inventory['items']??[]):[];
    return [
        'schemaVersion'=>'1.0.0','section'=>'FM-B8D17A',
        'source'=>'authenticated runtime registries and nonexecuting source inventory',
        'claim'=>'Registry presence is not a successful end-to-end execution test.',
        'counts'=>[
            'nativeRegistered'=>count($native),'delegationRegistered'=>count($nodes),
            'promptTemplates'=>count($templates),
            'plannedNotExecutable'=>$owner?count($planned):null,
        ],
        'nativeTools'=>$native,
        'delegatedAgents'=>$nodes,
        'chatPrompts'=>$templates,
        'planned'=> $owner?$planned:[],
        'inventoryVisibility'=>$owner?'owner_only':'withheld_for_this_account',
        'inventoryStatus'=>$owner?(string)($inventory['status']??'unavailable'):'not_requested',
        'readiness'=>[
            'currentSession'=>'authenticated',
            'database'=>'workspace_ready_not_business_query_verified',
            'provider'=>'not_tested_by_capability_snapshot',
            'realRecordReads'=>'not_tested_by_capability_snapshot',
            'writes'=>'not_tested_by_capability_snapshot',
            'voiceBargeIn'=>'requires_device_acceptance',
        ],
    ];
}

function fm8d17a_summary(array $report):string
{
    $native=array_map(static fn(array $t):string=>$t['id'],(array)$report['nativeTools']);
    $domains=array_map(static fn(array $d):string=>$d['label'],(array)$report['delegatedAgents']);
    return 'Registered Gelato tools for this account: '.($native?implode(', ',$native):'none').
        '. Available specialist handoffs: '.($domains?implode(', ',$domains):'none').
        '. These are registry capabilities, not proof of successful execution. '.
        'The chat prompt picker does not run tools, and proposed skill contracts are not execution grants.';
}
