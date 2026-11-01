<?php
declare(strict_types=1);

/** B8D18J6B: model-selected Marketing Agent status proposals, never direct package writes. */

function fm8d18j6b_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('packages.view',$actor)
        &&app_has_permission('packages.manage',$actor);
}
function fm8d18j6b_fields(array $args):array
{
    $missing=[];$id=$args['package_public_id']??null;$name=$args['package_name']??null;
    if(!is_string($id)||strlen($id)<2||strlen($id)>120
       ||!preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='package_public_id';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[;\r\n\x00]/u',$name))$missing[]='package_name';
    if(!in_array($args['action']??null,['activate','pause','resume','end','archive'],true))
        $missing[]='action';
    return $missing;
}
function fm8d18j6b_state_allows(string $status,string $action):bool
{
    return match($status){
        'draft'=>in_array($action,['activate','end','archive'],true),
        'active'=>in_array($action,['pause','end','archive'],true),
        'paused'=>in_array($action,['activate','resume','end','archive'],true),
        'ended'=>$action==='archive',
        default=>false,
    };
}
function fm8d18j6b_propose(array $args,array $actor):array
{
    if(!fm8d18j6b_can($actor))throw new DomainException('Package Deals management permission is required.');
    if($missing=fm8d18j6b_fields($args))
        throw new InvalidArgumentException('Specify exact saved package identity and action: '.implode(', ',$missing).'.');
    require_once __DIR__.'/marketing-agent-core.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(!package_deals_ready($pdo))throw new RuntimeException('Package Deals is unavailable. Complete upgrade.php first.');
    $q=$pdo->prepare('SELECT id,public_id,name,status,updated_at FROM package_deals WHERE organization_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,(string)$args['package_public_id']]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('The exact saved promotion was not found.');
    if((string)$row['name']!==trim((string)$args['package_name']))
        throw new InvalidArgumentException('The saved promotion name does not match the selected public ID.');
    $action=(string)$args['action'];$status=(string)$row['status'];
    if(!fm8d18j6b_state_allows($status,$action))
        throw new InvalidArgumentException('The requested promotion action '.$action.' is not allowed from its current '.$status.' state.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure marketing confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('marketing',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it first.');
        $summary='Proposed action: '.$action.' the Package Deal “'.$row['name'].'” ('.$row['public_id'].'), currently '.$status.'. Public promotions can change visibility or availability after confirmation.';
        $pending=gac_pending_store('marketing',$org,$uid,'package_status',[
            'packagePublicId'=>(string)$row['public_id'],'action'=>$action,
            'expectedStatus'=>$status,'expectedUpdatedAt'=>(string)$row['updated_at'],
        ],$summary);
        app_audit($pdo,$org,$uid,'marketing.agent_action_proposed','marketing_agent_proposal',
            (string)$pending['id'],null,['type'=>'package_status','action'=>$action,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the Marketing Agent, or Cancel. Nothing has changed yet.",
            'source'=>'Gelato Marketing Agent (server-verified pending proposal)',
            'requiresConfirmation'=>true,'proposal'=>['id'=>$pending['id'],'type'=>'package_status'],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j6b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j6b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_marketing_package_status'=>[
        'description'=>'ONLY when the manager explicitly asks to activate, pause, resume, end or archive ONE exact saved Package Deal with verified public ID and matching saved name. NEVER infer an action from a lookup or question; never invent identity. This creates only a Marketing Agent pending Confirm/Cancel proposal; no immediate status change. The specialist rechecks permission and unchanged state at confirmation, and enforces business transition rules. May change public availability only after confirmation. No email, SMS or consent action.',
        'parameters'=>['type'=>'object','properties'=>[
            'package_public_id'=>['type'=>'string','description'=>'Exact saved Package Deal public ID'],
            'package_name'=>['type'=>'string','description'=>'Matching saved promotion name'],
            'action'=>['type'=>'string','enum'=>['activate','pause','resume','end','archive']],
        ],'required'=>['package_public_id','package_name','action'],'additionalProperties'=>false],
        'allowedArguments'=>['package_public_id','package_name','action'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j6b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j6b_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j6b_propose($args,$user),
    ]];
}
