<?php
declare(strict_types=1);

/**
 * FM-B8D12: natural-language continuation of an existing Gelato Agent proposal.
 * A model may select CONFIRM/CANCEL, but it cannot name a destination or create
 * a proposal. The server resolves one active owner-scoped pending Agent node.
 * The destination applies its own fresh permission and one-use confirmation.
 */
require_once __DIR__.'/agent-node-registry.php';

function fm8d12_pending_node(array $actor):?array
{
    $org=(int)($actor['organization_id']??0);
    $user=(int)($actor['id']??0);
    if($org<1||$user<1)return null;
    $name=gaw_pending_action_node($org,$user);
    if(!is_string($name)||$name===''||$name==='high_impact_approval')return null;
    $allowed=fm8d11_available_domains($actor);
    if(!isset($allowed[$name]))return null;
    $node=$allowed[$name];
    if(($node['mode']??'')==='human_approval_only'
       ||!preg_match('~^api/[a-z][a-z0-9-]{1,80}\.php$~D',(string)($node['route']??'')))
        return null;
    $key=$org.':'.$user;
    $row=$_SESSION['agent_node_pending'][$name][$key]??null;
    if(is_array($row)){
        if((int)($row['expires']??0)<time()
           ||(isset($row['organizationId'])&&(int)$row['organizationId']!==$org)
           ||(isset($row['userId'])&&(int)$row['userId']!==$user))
            return null;
    }elseif(!in_array($name,['purchasing','scheduling'],true)){
        return null;
    }
    return ['name'=>$name,'node'=>$node];
}

function fm8d12_pending_catalogue(array $principal,array $context=[]):array
{
    if(!preg_match('/^agent-[a-f0-9]{20}$/D',(string)($context['threadId']??''))
       ||!preg_match('/^msg-[a-f0-9]{20}$/D',(string)($context['messageId']??'')))
        return [];
    $pending=fm8d12_pending_node($principal);
    if($pending===null)return [];
    return ['continue_pending_restaurant_action'=>[
        'description'=>'The signed-in user already has one server-recorded pending '.$pending['node']['label'].' action awaiting explicit confirmation. Choose decision confirm ONLY if their latest message clearly authorizes that pending action; choose cancel ONLY if they clearly reject it. Ambiguous follow-ups must ask a clarifying question instead. This tool does not execute or bypass approvals: it forwards an explicit decision to the SAME existing authorized Agent. Never use for high-impact owner approvals or new business requests.',
        'parameters'=>['type'=>'object','properties'=>[
            'decision'=>['type'=>'string','enum'=>['confirm','cancel'],
                'description'=>'Explicit user consent for the already-pending action.'],
        ],'required'=>['decision'],'additionalProperties'=>false],
        'allowedArguments'=>['decision'],'impact'=>'read',
        'authorize'=>static function(array $actor,array $args):bool{
            return in_array($args['decision']??null,['confirm','cancel'],true)
                &&fm8d12_pending_node($actor)!==null;
        },
        'validate'=>static fn(array $args,array $actor):array=>
            in_array($args['decision']??null,['confirm','cancel'],true)?[]:['decision'],
        'execute'=>static function(array $args,array $actor)use($context):array{
            $pending=fm8d12_pending_node($actor);
            if($pending===null)throw new DomainException('Pending operation no longer authorized.');
            $node=$pending['node'];
            $forward=$args['decision']==='confirm'?'Confirm':'Cancel';
            return ['ok'=>true,'data'=>[
                'answer'=>'Handing your explicit '.strtolower($forward).' decision to Gelato '.$node['label'].' for final verification.',
                'source'=>'Gelato active account-scoped Agent proposal',
                'delegation'=>[
                    'route'=>(string)$node['route'],
                    'domain'=>(string)$node['domain'],
                    'node'=>(string)$pending['name'],
                    'threadId'=>(string)$context['threadId'],
                    'sourceMessageId'=>(string)$context['messageId'],
                    'mode'=>(string)$node['mode'],
                    'continuation'=>true,'forwardMessage'=>$forward,
                ],
            ]];
        },
    ]];
}
