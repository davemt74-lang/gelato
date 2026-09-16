<?php
declare(strict_types=1);

require_once __DIR__.'/customer-crm-agent-core.php';
require_once __DIR__.'/customer-crm-intelligence.php';
require_once __DIR__.'/agent-confirmation-core.php';

function cae_relationship_intent(string $message): bool
{
    $text=mb_strtolower($message,'UTF-8');
    return preg_match('/\b(this customer|this guest|regular|repeat customer|returning customer|relationship|vip|high value|valuable|top spend|lapsed|re.?engage|inactive|last visit|when were they last|birthday|consent|opt.?in|can i (?:email|text|message)|favorite|favourite|usual(?:ly)?|recent (?:orders?|checks?|visits?)|customer history|guest history|lifetime spend|average check|what should (?:we|i) do for (?:this )?(?:customer|guest))\b/u',$text)===1;
}

function cae_global_intent(string $message): bool
{
    return preg_match('/\b(crm opportunities|customer opportunities|relationship opportunities|upcoming birthdays?|who should we re.?engage|re.?engagement opportunities|lapsed customers?|high value customers?|top customers?)\b/iu',$message)===1;
}

function cae_followup_intent(string $message): bool
{
    return preg_match('/\b(?:create|add|make|set up)\s+(?:a\s+)?(?:customer\s+)?follow.?up\s+task\b|\bfollow.?up\s+(?:with|on)\s+(?:this\s+)?(?:customer|guest)\b/iu',$message)===1;
}

function cae_followup_reason(string $message): string
{
    if(preg_match('/\b(?:about|for|because|regarding)\s+(.+)$/iu',$message,$m))return mb_substr(trim((string)$m[1]," .\t\n\r"),0,180,'UTF-8');
    return 'customer relationship follow-up';
}

function cae_context_customer(PDO $pdo,array $user,array $input): array
{
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];
    return cri_context_customer($pdo,$user,$context);
}

function cae_rewrite_to_crm(array $input,array $customer): array
{
    $input['pageContext']=['module'=>'crm','customerPublicId'=>(string)$customer['publicId']];
    return $input;
}

function cae_followup_proposal(PDO $pdo,array $user,array $customer,string $message): array
{
    if(!app_has_permission('crm.manage',$user))throw new CrmAgentPermissionException('CRM management permission is required to create a customer follow-up.');
    if(!app_has_permission('tasks.agent',$user)||!app_has_permission('tasks.view',$user)||!app_has_permission('tasks.manage',$user))throw new CrmAgentPermissionException('Task Agent and task management permission are required to create a customer follow-up task.');
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$reason=cae_followup_reason($message);$name=(string)$customer['displayName'];
    $title='Follow up with '.$name.' — '.$reason;
    $proposal=gac_pending_store('operations',$org,$uid,'task_create',[
        'items'=>[$title],
        'assigneeIds'=>[],
        'transcript'=>$message,
        'source'=>['type'=>'crm_customer','publicId'=>$customer['publicId']],
    ],'Proposed action: create the restaurant follow-up task “'.$title.'”.');
    app_audit($pdo,$org,$uid,'crm.agent_followup_handoff_proposed','crm_customer',(string)$customer['publicId'],null,['proposalId'=>$proposal['id'],'targetNode'=>'operations']);
    $result=gac_proposal_result($proposal,'operations.action_proposal',['Customer CRM','Restaurant Tasks']);
    $result['data']=array_merge((array)($result['data']??[]),['targetNode'=>'operations','customerPublicId'=>$customer['publicId']]);
    return ['ok'=>true]+$result;
}

function cae_opportunities_answer(PDO $pdo,array $user): array
{
    $rows=cri_global_opportunities($pdo,$user,12);
    if(!$rows)return ['ok'=>true,'skill'=>'crm.opportunities','answer'=>'I do not see a current CRM relationship opportunity that meets the configured evidence thresholds.','data'=>['opportunities'=>[]],'sources'=>['Customer CRM','POS Paid Visit History']];
    $parts=[];foreach(array_slice($rows,0,8) as $row)$parts[]=$row['title'].' — '.$row['detail'];
    return ['ok'=>true,'skill'=>'crm.opportunities','answer'=>"Current CRM opportunities:\n- ".implode("\n- ",$parts),'data'=>['opportunities'=>array_map(static fn(array $row):array=>['key'=>$row['key'],'type'=>$row['type'],'score'=>$row['score'],'customerPublicId'=>$row['customer']['publicId'],'customerName'=>$row['customer']['displayName'],'detail'=>$row['detail'],'prompt'=>$row['prompt']],$rows)],'sources'=>['Customer CRM','POS Paid Visit History','CRM Consent History']];
}

function customer_crm_agent_enhanced_handle(PDO $pdo,array $user,array $input): array
{
    if(!app_has_permission('crm.view',$user))return customer_crm_agent_handle($pdo,$user,$input);
    $message=trim((string)($input['message']??''));
    if($message==='')return customer_crm_agent_handle($pdo,$user,$input);

    if(cae_global_intent($message))return cae_opportunities_answer($pdo,$user);

    $resolved=cae_context_customer($pdo,$user,$input);$customer=$resolved['customer'];$context=is_array($input['pageContext']??null)?$input['pageContext']:[];$module=(string)($context['module']??'');
    $crossContext=in_array($module,['pos','table_service','kds'],true);

    if($crossContext&&(cae_relationship_intent($message)||cae_followup_intent($message))&&!$customer){
        $check=$resolved['check'];$label=$check?((string)$check['checkNumber']):'this check';
        return ['ok'=>true,'skill'=>'crm.context_unlinked','answer'=>'No CRM customer is attached to '.$label.' yet. Attach a customer first if you want relationship history, favorites, prior visits, birthday context, or consent-aware follow-up.','data'=>['check'=>$check,'customer'=>null],'sources'=>['POS Customer Link']];
    }

    if($customer){
        if(cae_followup_intent($message))return cae_followup_proposal($pdo,$user,$customer,$message);
        if(cae_relationship_intent($message)){
            $lower=mb_strtolower($message,'UTF-8');
            if(preg_match('/\b(?:favorite|favourite|usual(?:ly)?)\b/u',$lower)){
                return ['ok'=>true,'skill'=>'crm.relationship','answer'=>cac_favorites_answer($customer),'data'=>['customer'=>['publicId'=>$customer['publicId'],'displayName'=>$customer['displayName'],'metrics'=>$customer['metrics'],'tags'=>$customer['tags']]],'sources'=>['Customer CRM','POS Paid Visit History']];
            }
            if(preg_match('/\b(?:recent (?:orders?|checks?|visits?)|customer history|guest history|notes?|tags?)\b/u',$lower))return customer_crm_agent_handle($pdo,$user,cae_rewrite_to_crm($input,$customer));
            $answer=cri_relationship_answer($pdo,(int)$user['organization_id'],$customer,$message);
            return ['ok'=>true,'skill'=>'crm.relationship_intelligence','answer'=>$answer['answer'],'data'=>['customer'=>['publicId'=>$customer['publicId'],'displayName'=>$customer['displayName']],'relationship'=>$answer['relationship']],'sources'=>$answer['sources']];
        }
        if($crossContext)return customer_crm_agent_handle($pdo,$user,cae_rewrite_to_crm($input,$customer));
    }

    return customer_crm_agent_handle($pdo,$user,$input);
}
