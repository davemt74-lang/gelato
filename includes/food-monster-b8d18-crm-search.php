<?php
declare(strict_types=1);

/** B8D18J5A: tenant-scoped, model-selected CRM customer lookup; no direct write. */
require_once __DIR__.'/customer-crm-core.php';

function fm8d18j5a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('crm.view',$actor);
}
function fm8d18j5a_fields(array $args):array
{
    $query=$args['query']??null;
    return is_string($query)&&mb_strlen(trim($query),'UTF-8')>=2&&
        mb_strlen(trim($query),'UTF-8')<=120&&
        !preg_match('/[%_;\r\n\x00]/u',$query)?[]:['query'];
}
function fm8d18j5a_search(array $args,array $actor):array
{
    if(!fm8d18j5a_can($actor))throw new DomainException('CRM view permission is required.');
    if(fm8d18j5a_fields($args))throw new InvalidArgumentException('Specify a specific customer search term of 2 to 120 characters.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!crm_ready($pdo))throw new RuntimeException('Customer CRM is unavailable. Complete upgrade.php first.');
    $query=trim($args['query']);$rows=crm_search($pdo,$org,$query,8,false);
    $matches=array_map(static fn(array $row):array=>[
        'publicId'=>(string)$row['publicId'],
        'displayName'=>(string)$row['display_name'],
    ],$rows);
    if(!$matches)$answer='No active CRM customers matched that search. Try a more specific saved name.';
    elseif(count($matches)===1)$answer='Found one active CRM customer: '.$matches[0]['displayName'].' ('.$matches[0]['publicId'].').';
    else{
        $parts=array_map(static fn(array $row):string=>$row['displayName'].' ('.$row['publicId'].')',$matches);
        $answer='Multiple active CRM matches: '.implode('; ',$parts).
            '. Select the exact profile ID before asking for history or actions.';
    }
    if(count($matches)===8)$answer.=' More matches may exist; refine the search.';
    return ['ok'=>true,'data'=>['answer'=>$answer,
        'source'=>'Gelato Customer CRM (authenticated server lookup)',
        'readOnly'=>true,'matches'=>$matches,
        'truncated'=>count($matches)===8]];
}
function fm8d18j5a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j5a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['search_crm_customers'=>[
        'description'=>'Read-only authorized lookup of actual active Gelato CRM customers by specific supplied name, email fragment or phone fragment. Require a specific 2–120 character query. Return saved public profile IDs and names; no contact fields, notes, or transaction history. Multiple matches are ambiguous: ask the user to select the exact profile before future actions. Never claim a unique customer when there are multiple matches.',
        'parameters'=>['type'=>'object','properties'=>[
            'query'=>['type'=>'string','description'=>'Explicit customer search phrase; no wildcard syntax'],
        ],'required'=>['query'],'additionalProperties'=>false],
        'allowedArguments'=>['query'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j5a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j5a_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j5a_search($args,$user),
    ]];
}
