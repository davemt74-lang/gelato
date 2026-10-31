<?php
declare(strict_types=1);

/** FM-B8D18D: model-selected, confirmation-only CRM notes and tags. */
require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/agent-confirmation-core.php';
require_once __DIR__.'/agent-node-registry.php';

function fm8d18d_can(array $user):bool
{
    if((int)($user['id']??0)<1||(int)($user['organization_id']??0)<1)return false;
    foreach(['crm.agent','crm.view','crm.manage'] as $permission)
        if(!app_has_permission($permission,$user))return false;
    return true;
}

function fm8d18d_customer_fields(array $args):array
{
    $name=$args['customer_name']??null;
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[;\r\n]/u',$name))return ['customer_name'];
    return [];
}

function fm8d18d_note_fields(array $args):array
{
    $missing=fm8d18d_customer_fields($args);
    $note=$args['note']??null;
    if(!is_string($note)||trim($note)===''||mb_strlen(trim($note),'UTF-8')>2000
       ||str_contains($note,"\0"))$missing[]='note';
    return $missing;
}

function fm8d18d_tag_fields(array $args):array
{
    $missing=fm8d18d_customer_fields($args);
    $tag=$args['tag']??null;
    if(!is_string($tag)||trim($tag)===''||mb_strlen(trim($tag),'UTF-8')>120
       ||preg_match('/[;\r\n]/u',$tag)||crm_tag_slug((string)$tag)==='')$missing[]='tag';
    if(!in_array($args['action']??null,['add','remove'],true))$missing[]='action';
    return $missing;
}

function fm8d18d_exact_customer(PDO $pdo,int $org,string $name):array
{
    $stmt=$pdo->prepare("SELECT id,public_id,display_name,status,updated_at FROM crm_customers WHERE organization_id=? AND status='active' AND display_name=? LIMIT 2");
    $stmt->execute([$org,trim($name)]);
    $rows=$stmt->fetchAll();
    if(count($rows)!==1)
        throw new InvalidArgumentException('No unique exact active CRM customer match. Use the saved full display name or select the profile.');
    return $rows[0];
}

function fm8d18d_propose(array $user,string $type,array $payload,string $summary):array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure CRM confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('crm',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it before requesting another.');
        $pending=gac_pending_store('crm',$org,$uid,$type,$payload,$summary);
        app_audit(app_pdo(),$org,$uid,'crm.agent_action_proposed','crm_agent_proposal',
            (string)$pending['id'],null,['type'=>$type,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the CRM Agent, or Cancel. No CRM record has been changed yet.",
            'source'=>'Gelato Customer CRM (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$type],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}

function fm8d18d_catalogue(array $user):array
{
    if(!fm8d18d_can($user))return [];
    return [
        'propose_crm_customer_note'=>[
            'description'=>'ONLY when the user explicitly requests one internal CRM note on one EXACT saved active customer name. The note is NOT a consent change and does not send marketing messages. Do not infer note text or customer identity. This stages a separate confirmed CRM Agent action, never a direct write.',
            'parameters'=>['type'=>'object','properties'=>[
                'customer_name'=>['type'=>'string','description'=>'Exact saved full display name of one CRM customer'],
                'note'=>['type'=>'string','description'=>'Internal note text explicitly dictated or typed by the user'],
            ],'required'=>['customer_name','note'],'additionalProperties'=>false],
            'allowedArguments'=>['customer_name','note'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18d_can($actor),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18d_note_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18d_can($actor))throw new DomainException('CRM management permission is required.');
                if($missing=fm8d18d_note_fields($args))
                    throw new InvalidArgumentException('Specify an exact customer name and note: '.implode(', ',$missing).'.');
                $customer=fm8d18d_exact_customer(app_pdo(),(int)$actor['organization_id'],(string)$args['customer_name']);
                $note=trim((string)$args['note']);
                return fm8d18d_propose($actor,'note_add',[
                    'customerPublicId'=>$customer['public_id'],'note'=>$note,
                    'expected'=>['status'=>(string)$customer['status'],'updatedAt'=>(string)$customer['updated_at']]],
                    'Proposed internal note for '.$customer['display_name'].': “'.mb_substr($note,0,240,'UTF-8').'”.');
            },
        ],
        'propose_crm_customer_tag'=>[
            'description'=>'ONLY to add or remove one explicitly named internal CRM tag for one EXACT saved active customer name. No customer consent, email, SMS, or marketing preferences may be changed by this tool. This only stages the existing CRM Agent confirmation.',
            'parameters'=>['type'=>'object','properties'=>[
                'customer_name'=>['type'=>'string','description'=>'Exact saved full display name'],
                'tag'=>['type'=>'string','description'=>'Explicit CRM tag name'],
                'action'=>['type'=>'string','enum'=>['add','remove']],
            ],'required'=>['customer_name','tag','action'],'additionalProperties'=>false],
            'allowedArguments'=>['customer_name','tag','action'],'impact'=>'read',
            'authorize'=>static fn(array $actor,array $args):bool=>fm8d18d_can($actor),
            'validate'=>static fn(array $args,array $actor):array=>fm8d18d_tag_fields($args),
            'execute'=>static function(array $args,array $actor):array{
                if(!fm8d18d_can($actor))throw new DomainException('CRM management permission is required.');
                if($missing=fm8d18d_tag_fields($args))
                    throw new InvalidArgumentException('Specify an exact customer, tag and add/remove action: '.implode(', ',$missing).'.');
                $pdo=app_pdo();$customer=fm8d18d_exact_customer($pdo,(int)$actor['organization_id'],(string)$args['customer_name']);
                $type=$args['action']==='add'?'tag_add':'tag_remove';$tag=trim((string)$args['tag']);
                if($type==='tag_remove'){
                    $matched=false;
                    foreach(crm_tags($pdo,(int)$actor['organization_id'],(int)$customer['id']) as $existing)
                        if((string)$existing['slug']===crm_tag_slug($tag)){$matched=true;break;}
                    if(!$matched)throw new InvalidArgumentException('The requested tag is not currently assigned to that customer.');
                }
                return fm8d18d_propose($actor,$type,[
                    'customerPublicId'=>$customer['public_id'],'tag'=>$tag,
                    'expected'=>['status'=>(string)$customer['status'],'updatedAt'=>(string)$customer['updated_at']]],
                    'Proposed CRM tag '.$args['action'].' “'.$tag.'” for '.$customer['display_name'].'.');
            },
        ],
    ];
}
