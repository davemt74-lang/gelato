<?php
declare(strict_types=1);

/** B8D18J5C: explicit CRM archive proposal, never direct archival by a model. */
require_once __DIR__.'/food-monster-b8d18-crm-proposals.php';

function fm8d18j5c_can(array $actor):bool
{
    return fm8d18d_can($actor);
}
function fm8d18j5c_fields(array $args):array
{
    $missing=[];$id=$args['customer_public_id']??null;$name=$args['customer_name']??null;
    if(!is_string($id)||strlen($id)<2||strlen($id)>120
       ||!preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='customer_public_id';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[;\r\n]/u',$name))$missing[]='customer_name';
    return $missing;
}
function fm8d18j5c_propose(array $args,array $actor):array
{
    if(!fm8d18j5c_can($actor))throw new DomainException('CRM Agent management permission is required.');
    if($missing=fm8d18j5c_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $org=(int)$actor['organization_id'];$pdo=app_pdo();
    if(!crm_ready($pdo))throw new RuntimeException('Customer CRM is unavailable. Complete upgrade.php first.');
    $customer=crm_customer_row($pdo,$org,(string)$args['customer_public_id']);
    if((string)$customer['status']!=='active')throw new InvalidArgumentException('Only active CRM customer profiles can be archived.');
    if((string)$customer['display_name']!==trim((string)$args['customer_name']))
        throw new InvalidArgumentException('The supplied customer name does not match the exact saved profile. Select the correct profile first.');
    $publicId=(string)$customer['public_id'];$name=(string)$customer['display_name'];
    return fm8d18d_propose($actor,'archive_customer',[
        'customerPublicId'=>$publicId,
        'expected'=>['status'=>(string)$customer['status'],'updatedAt'=>(string)$customer['updated_at']],
    ],'Proposed action: archive the CRM profile for '.$name.' ('.$publicId.'). The profile will leave the active list, but historical records are retained. This requires your separate Confirm.');
}
function fm8d18j5c_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j5c_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_crm_customer_archive'=>[
        'description'=>'ONLY when the authorized manager explicitly requests to ARCHIVE a specific saved ACTIVE CRM customer profile. Require its exact public ID and exact saved full display name, both already verified by the user or selected CRM profile. Never guess a customer or infer archival from a CRM question. The tool ONLY stages an expiring server CRM Agent Confirm/Cancel action. The CRM Agent rechecks current permission, organization and exact record version at confirmation. Archiving removes the profile from active lists; it does not purge history. Never change marketing consent or archive immediately.',
        'parameters'=>['type'=>'object','properties'=>[
            'customer_public_id'=>['type'=>'string','description'=>'Exact existing CRM profile public ID'],
            'customer_name'=>['type'=>'string','description'=>'Matching saved full display name of that profile'],
        ],'required'=>['customer_public_id','customer_name'],'additionalProperties'=>false],
        'allowedArguments'=>['customer_public_id','customer_name'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j5c_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j5c_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j5c_propose($args,$user),
    ]];
}
