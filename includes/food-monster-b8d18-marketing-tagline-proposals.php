<?php
declare(strict_types=1);

/** B8D18J6D: model-selected public tagline proposal, existing Marketing Agent confirmation. */

function fm8d18j6d_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&(app_has_permission('public_pages.edit',$actor)
           ||app_has_permission('settings.organization_edit',$actor));
}
function fm8d18j6d_fields(array $args):array
{
    $tagline=$args['tagline']??null;
    if(!is_string($tagline)||trim($tagline)===''
       ||mb_strlen(trim($tagline),'UTF-8')>255
       ||preg_match('/[<>\x00-\x1F]/u',$tagline))return ['tagline'];
    return [];
}
function fm8d18j6d_propose(array $args,array $actor):array
{
    if(!fm8d18j6d_can($actor))throw new DomainException('Public-site edit permission is required.');
    if($missing=fm8d18j6d_fields($args))
        throw new InvalidArgumentException('Specify a plain-text public tagline no longer than 255 characters.');
    require_once __DIR__.'/marketing-agent-core.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $tagline=trim((string)$args['tagline']);$version=mac_site_version($pdo,$org);
    if($tagline===(string)$version['effectiveTagline'])
        throw new InvalidArgumentException('The public-site tagline is already set to that value.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure marketing confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('marketing',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it first.');
        $summary='Proposed public-site tagline change from “'.mb_substr((string)$version['effectiveTagline'],0,255,'UTF-8').'” to “'.$tagline.'”. This is a visible public-site setting after confirmation.';
        $pending=gac_pending_store('marketing',$org,$uid,'public_tagline',[
            'tagline'=>$tagline,'expectedTagline'=>(string)$version['effectiveTagline'],
            'expectedUpdatedAt'=>$version['updatedAt'],
        ],$summary);
        app_audit($pdo,$org,$uid,'marketing.agent_action_proposed','marketing_agent_proposal',
            (string)$pending['id'],null,['type'=>'public_tagline','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the Marketing Agent, or Cancel. The public site has not been changed.",
            'source'=>'Gelato Marketing Agent (server-verified pending proposal)',
            'requiresConfirmation'=>true,'proposal'=>['id'=>$pending['id'],'type'=>'public_tagline'],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j6d_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j6d_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_public_site_tagline'=>[
        'description'=>'ONLY when the authorized public-site editor explicitly dictates or types one new tagline for Gelato public website. Provide the exact new tagline string; do not invent marketing copy, accept a question as a request, or touch other site settings. This tool stages ONLY a Marketing Agent pending Confirm/Cancel proposal. Existing specialist rechecks public edit permission and the unchanged prior tagline and settings version at confirmation. The public-site tagline is not updated at selection. Never send marketing messages or modify consent.',
        'parameters'=>['type'=>'object','properties'=>[
            'tagline'=>['type'=>'string','description'=>'Exact new public-site tagline supplied by the authorized editor, up to 255 plain-text characters'],
        ],'required'=>['tagline'],'additionalProperties'=>false],
        'allowedArguments'=>['tagline'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j6d_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j6d_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j6d_propose($args,$user),
    ]];
}
