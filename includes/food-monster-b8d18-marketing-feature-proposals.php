<?php
declare(strict_types=1);

/** B8D18J6C: stage only a Marketing Agent Featured state change. */
require_once __DIR__.'/food-monster-b8d18-marketing-status-proposals.php';

function fm8d18j6c_fields(array $args):array
{
    $missing=[];$id=$args['package_public_id']??null;$name=$args['package_name']??null;
    if(!is_string($id)||strlen($id)<2||strlen($id)>120
       ||!preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='package_public_id';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[;\r\n\x00]/u',$name))$missing[]='package_name';
    if(!is_bool($args['featured']??null))$missing[]='featured';
    return $missing;
}
function fm8d18j6c_propose(array $args,array $actor):array
{
    if(!fm8d18j6b_can($actor))throw new DomainException('Package Deals management permission is required.');
    if($missing=fm8d18j6c_fields($args))
        throw new InvalidArgumentException('Specify exact saved package identity and Featured setting: '.implode(', ',$missing).'.');
    require_once __DIR__.'/marketing-agent-core.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(!package_deals_ready($pdo))throw new RuntimeException('Package Deals is unavailable. Complete upgrade.php first.');
    $q=$pdo->prepare('SELECT id,public_id,name,status,featured,updated_at FROM package_deals WHERE organization_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,(string)$args['package_public_id']]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('The selected saved promotion was not found.');
    if((string)$row['name']!==trim((string)$args['package_name']))
        throw new InvalidArgumentException('The saved promotion name does not match the selected public ID.');
    if((string)$row['status']==='archived')throw new InvalidArgumentException('Archived promotions cannot be featured; duplicate the archived package first.');
    $featured=(bool)$args['featured'];
    if((bool)$row['featured']===$featured)
        throw new InvalidArgumentException('The promotion already has that Featured setting. No action is needed.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure marketing confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('marketing',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it first.');
        $summary='Proposed action: '.($featured?'feature':'unfeature').' the Package Deal “'.$row['name'].'” ('.$row['public_id'].') on the public Specials experience. A Featured package is publicly eligible only while its status and configured dates permit it.';
        $pending=gac_pending_store('marketing',$org,$uid,'package_feature',[
            'packagePublicId'=>(string)$row['public_id'],'featured'=>$featured,
            'expectedStatus'=>(string)$row['status'],
            'expectedUpdatedAt'=>(string)$row['updated_at'],
        ],$summary);
        app_audit($pdo,$org,$uid,'marketing.agent_action_proposed','marketing_agent_proposal',
            (string)$pending['id'],null,['type'=>'package_feature','featured'=>$featured,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the Marketing Agent, or Cancel. Nothing has changed yet.",
            'source'=>'Gelato Marketing Agent (server-verified pending proposal)',
            'requiresConfirmation'=>true,'proposal'=>['id'=>$pending['id'],'type'=>'package_feature'],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j6c_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j6b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_marketing_package_feature'=>[
        'description'=>'ONLY when an authorized package manager explicitly requests to feature or unfeature ONE exact saved non-archived Package Deal. Require the exact saved public ID, matching saved name and a strict boolean Featured state. Do not infer selection or action. A Featured change may affect public Specials after separate Confirm, but this tool only stages the existing Marketing Agent pending proposal; it never edits the package itself. Existing specialist checks permission and exact version at confirmation. Never send outreach or change consent.',
        'parameters'=>['type'=>'object','properties'=>[
            'package_public_id'=>['type'=>'string','description'=>'Exact saved promotion public ID'],
            'package_name'=>['type'=>'string','description'=>'Matching saved promotion name'],
            'featured'=>['type'=>'boolean','description'=>'True to feature, false to unfeature'],
        ],'required'=>['package_public_id','package_name','featured'],'additionalProperties'=>false],
        'allowedArguments'=>['package_public_id','package_name','featured'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j6b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j6c_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j6c_propose($args,$user),
    ]];
}
