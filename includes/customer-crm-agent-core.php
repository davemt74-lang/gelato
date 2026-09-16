<?php
declare(strict_types=1);
require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class CrmAgentPermissionException extends RuntimeException {}

function cac_require(array $user,string $permission,string $message): void
{
    if(!app_has_permission($permission,$user))throw new CrmAgentPermissionException($message);
}

function cac_clean_public_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function cac_context_customer(PDO $pdo,int $org,array $context): ?array
{
    if((string)($context['module']??'')!=='crm')return null;
    $public=cac_clean_public_id($context['customerPublicId']??'');
    if($public==='')return null;
    try{return crm_profile($pdo,$org,$public);}catch(Throwable){return null;}
}

function cac_extract_customer_query(string $message): string
{
    $value=trim($message);
    $patterns=[
        '/^\s*(?:find|search(?:\s+for)?|look\s+up|show(?:\s+me)?|open)\s+(?:customer\s+)?(.+?)\s*[?.!]*$/iu',
        '/^\s*(?:customer|guest)\s+(.+?)\s*[?.!]*$/iu',
        '/\b(?:about|for)\s+customer\s+(.+?)\s*[?.!]*$/iu',
    ];
    foreach($patterns as $pattern)if(preg_match($pattern,$value,$m))return trim((string)$m[1]," \t\n\r\0\x0B?.!");
    return '';
}

function cac_find_customer(PDO $pdo,int $org,string $message,array $context): array
{
    $contextCustomer=cac_context_customer($pdo,$org,$context);
    if($contextCustomer)return ['customer'=>$contextCustomer,'matches'=>[$contextCustomer]];
    $query=cac_extract_customer_query($message);
    if($query==='')return ['customer'=>null,'matches'=>[]];
    $matches=crm_search($pdo,$org,$query,8,false);
    if(count($matches)!==1)return ['customer'=>null,'matches'=>$matches];
    return ['customer'=>crm_profile($pdo,$org,(string)$matches[0]['publicId']),'matches'=>$matches];
}

function cac_customer_summary(array $customer): string
{
    $m=(array)($customer['metrics']??[]);
    $parts=[
        (string)$customer['displayName'],
        (int)($m['visits']??0).' paid visit'.((int)($m['visits']??0)===1?'':'s'),
        '$'.number_format((float)($m['lifetimeSpend']??0),2).' lifetime spend',
        '$'.number_format((float)($m['averageCheck']??0),2).' average check',
    ];
    if(!empty($m['lastVisit']))$parts[]='last visit '.(string)$m['lastVisit'];
    return implode(' · ',$parts).'.';
}

function cac_favorites_answer(array $customer): string
{
    $rows=(array)($customer['metrics']['favoriteItems']??[]);
    if(!$rows)return (string)$customer['displayName'].' has no paid-item history yet.';
    $parts=[];
    foreach(array_slice($rows,0,6) as $row)$parts[]=(string)$row['itemName'].' ('.rtrim(rtrim(number_format((float)$row['quantity'],1),'0'),'.').' ordered)';
    return (string)$customer['displayName'].' favorites: '.implode('; ',$parts).'.';
}

function cac_recent_answer(array $customer): string
{
    $rows=(array)($customer['metrics']['recentChecks']??[]);
    if(!$rows)return (string)$customer['displayName'].' has no paid visits yet.';
    $parts=[];
    foreach(array_slice($rows,0,5) as $row)$parts[]=(string)$row['checkNumber'].' $'.number_format(max(0,(float)$row['totalAmount']-(float)$row['tipAmount']),2).' at '.(string)$row['locationName'].' on '.(string)$row['closedAt'];
    return 'Recent paid visits for '.(string)$customer['displayName'].': '.implode('; ',$parts).'.';
}

function cac_notes_answer(array $customer): string
{
    $rows=(array)($customer['notes']??[]);
    if(!$rows)return (string)$customer['displayName'].' has no internal CRM notes.';
    $parts=[];
    foreach(array_slice($rows,0,6) as $row)$parts[]=(string)$row['createdAt'].' — '.(string)$row['noteBody'];
    return 'Internal notes for '.(string)$customer['displayName'].': '.implode('; ',$parts).'.';
}

function cac_tags_answer(array $customer): string
{
    $tags=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['name']??'')),(array)($customer['tags']??[]))));
    return $tags?((string)$customer['displayName'].' tags: '.implode(', ',$tags).'.'):((string)$customer['displayName'].' has no CRM tags.');
}

function cac_customer_version(PDO $pdo,int $org,string $publicId): array
{
    $row=crm_customer_row($pdo,$org,$publicId);
    return ['status'=>(string)$row['status'],'updatedAt'=>(string)$row['updated_at']];
}

function cac_guard_customer(PDO $pdo,int $org,string $publicId,array $expected): array
{
    $row=crm_customer_row($pdo,$org,$publicId);
    if((string)$row['status']!==(string)($expected['status']??'')||(string)$row['updated_at']!==(string)($expected['updatedAt']??'')){
        throw new InvalidArgumentException('That customer profile changed after I proposed the action. Review the current profile and ask again.');
    }
    if((string)$row['status']!=='active')throw new InvalidArgumentException('That customer profile is no longer active.');
    return $row;
}

function cac_extract_note(string $message): string
{
    if(preg_match('/\b(?:add|leave|record)\s+(?:an?\s+)?(?:internal\s+)?note(?:\s+(?:for|to)\s+(?:this\s+)?customer)?\s*[:,-]?\s*(.+)$/iu',$message,$m))return trim((string)$m[1]);
    return '';
}

function cac_extract_tag(string $message,string $verb='add'): string
{
    $verbPattern=$verb==='remove'?'(?:remove|delete|clear)':'(?:add|apply|set)';
    if(preg_match('/\b'.$verbPattern.'\s+(?:the\s+)?tag\s+[“"\']?([^”"\']+?)[”"\']?(?:\s+(?:from|to|for)\s+(?:this\s+)?customer)?\s*[.!]?$/iu',$message,$m))return trim((string)$m[1]," \t\n\r\0\x0B?.!");
    return '';
}

function cac_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new CrmAgentPermissionException('This CRM Agent proposal does not belong to your session.');
    cac_require($user,'crm.manage','CRM management permission is required for this action.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];$public=(string)($payload['customerPublicId']??'');
    $row=cac_guard_customer($pdo,$org,$public,(array)($payload['expected']??[]));

    if($type==='note_add'){
        $notes=crm_note_add($pdo,$org,$public,(string)$payload['note'],$uid);
        app_audit($pdo,$org,$uid,'crm.agent_note_added','crm_customer',$public,null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'crm.action_confirmed','answer'=>'Confirmed. Added the internal note to '.(string)$row['display_name'].'.','data'=>['action'=>'note_add','customerPublicId'=>$public,'notes'=>$notes],'sources'=>['Customer CRM → Internal Notes']];
    }
    if($type==='tag_add'){
        $tags=crm_tag_add($pdo,$org,$public,(string)$payload['tag'],$uid);
        app_audit($pdo,$org,$uid,'crm.agent_tag_added','crm_customer',$public,null,['proposalId'=>$proposal['id'],'tag'=>$payload['tag']]);
        return ['skill'=>'crm.action_confirmed','answer'=>'Confirmed. Added the CRM tag “'.(string)$payload['tag'].'” to '.(string)$row['display_name'].'.','data'=>['action'=>'tag_add','customerPublicId'=>$public,'tags'=>$tags],'sources'=>['Customer CRM → Tags']];
    }
    if($type==='tag_remove'){
        $slug=crm_tag_slug((string)$payload['tag']);$tagId=0;
        foreach(crm_tags($pdo,$org,(int)$row['id']) as $tag)if((string)$tag['slug']===$slug){$tagId=(int)$tag['id'];break;}
        if(!$tagId)throw new InvalidArgumentException('That tag is no longer assigned to the customer.');
        $tags=crm_tag_remove($pdo,$org,$public,$tagId);
        app_audit($pdo,$org,$uid,'crm.agent_tag_removed','crm_customer',$public,null,['proposalId'=>$proposal['id'],'tag'=>$payload['tag']]);
        return ['skill'=>'crm.action_confirmed','answer'=>'Confirmed. Removed the CRM tag “'.(string)$payload['tag'].'” from '.(string)$row['display_name'].'.','data'=>['action'=>'tag_remove','customerPublicId'=>$public,'tags'=>$tags],'sources'=>['Customer CRM → Tags']];
    }
    if($type==='archive_customer'){
        crm_customer_archive($pdo,$org,$public,$uid);
        app_audit($pdo,$org,$uid,'crm.agent_customer_archived','crm_customer',$public,null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'crm.action_confirmed','answer'=>'Confirmed. Archived the customer profile for '.(string)$row['display_name'].'.','data'=>['action'=>'archive_customer','customerPublicId'=>$public],'sources'=>['Customer CRM']];
    }
    throw new InvalidArgumentException('That pending CRM Agent action is no longer supported.');
}

function customer_crm_agent_handle(PDO $pdo,array $user,array $input): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    cac_require($user,'crm.view','CRM view permission is required.');
    $message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato a CRM question no longer than 2,400 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];
    $pending=gac_pending_get('crm',$org,$uid);

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending CRM Agent action to confirm.');
        try{$result=cac_execute_pending($pdo,$org,$uid,$user,$pending);}catch(Throwable $e){gac_pending_clear('crm',$org,$uid);throw $e;}
        gac_pending_clear('crm',$org,$uid);
        app_audit($pdo,$org,$uid,'crm.agent_action_confirmed','crm_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('crm',$org,$uid);
        app_audit($pdo,$org,$uid,'crm.agent_action_discarded','crm_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true,'skill'=>'crm.action_cancelled','answer'=>'Cancelled. I did not change the customer profile.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Customer CRM']];
    }

    $resolved=cac_find_customer($pdo,$org,$message,$context);$customer=$resolved['customer'];
    if(!$customer&&count($resolved['matches'])>1){
        $names=array_slice(array_map(static fn($row)=>(string)($row['displayName']??$row['display_name']??''),$resolved['matches']),0,6);
        return ['ok'=>true,'skill'=>'crm.search','answer'=>'I found multiple matching customers: '.implode(', ',$names).'. Select a profile or be more specific.','data'=>['matches'=>$resolved['matches']],'sources'=>['Customer CRM']];
    }
    if(!$customer&&cac_extract_customer_query($message)!=='')return ['ok'=>true,'skill'=>'crm.search','answer'=>'I did not find an active customer matching that search.','data'=>['matches'=>[]],'sources'=>['Customer CRM']];

    $lower=mb_strtolower($message,'UTF-8');
    if(!$customer){
        $rows=crm_search($pdo,$org,'',12,false);
        return ['ok'=>true,'skill'=>'crm.overview','answer'=>'Customer CRM is available. I can find a customer, summarize relationship history, show favorites/recent paid visits, or propose an internal note, tag, or archive action.','data'=>['recentCustomers'=>$rows],'sources'=>['Customer CRM']];
    }

    if(preg_match('/\b(?:consent|opt.?in|opt.?out|marketing permission|sms permission|email permission)\b/u',$lower))return ['ok'=>true,'skill'=>'crm.consent_boundary','answer'=>'Marketing consent changes stay in the explicit CRM consent controls so staff must record the channel, status, source, and evidence. I can summarize the current profile, but I will not change consent through Agent chat.','data'=>['customerPublicId'=>$customer['publicId']],'sources'=>['Customer CRM → Marketing Consent']];

    $note=cac_extract_note($message);
    if($note!==''){
        cac_require($user,'crm.manage','CRM management permission is required to add customer notes.');
        $expected=cac_customer_version($pdo,$org,(string)$customer['publicId']);
        $proposal=gac_pending_store('crm',$org,$uid,'note_add',['customerPublicId'=>$customer['publicId'],'note'=>$note,'expected'=>$expected],'Proposed action: add this internal CRM note to '.$customer['displayName'].': “'.mb_substr($note,0,240,'UTF-8').'”');
        return gac_proposal_result($proposal,'crm.action_proposal',['Customer CRM → Internal Notes']);
    }
    $addTag=cac_extract_tag($message,'add');
    if($addTag!==''){
        cac_require($user,'crm.manage','CRM management permission is required to add customer tags.');
        $proposal=gac_pending_store('crm',$org,$uid,'tag_add',['customerPublicId'=>$customer['publicId'],'tag'=>$addTag,'expected'=>cac_customer_version($pdo,$org,(string)$customer['publicId'])],'Proposed action: add the CRM tag “'.$addTag.'” to '.$customer['displayName'].'.');
        return gac_proposal_result($proposal,'crm.action_proposal',['Customer CRM → Tags']);
    }
    $removeTag=cac_extract_tag($message,'remove');
    if($removeTag!==''){
        cac_require($user,'crm.manage','CRM management permission is required to remove customer tags.');
        $proposal=gac_pending_store('crm',$org,$uid,'tag_remove',['customerPublicId'=>$customer['publicId'],'tag'=>$removeTag,'expected'=>cac_customer_version($pdo,$org,(string)$customer['publicId'])],'Proposed action: remove the CRM tag “'.$removeTag.'” from '.$customer['displayName'].'.');
        return gac_proposal_result($proposal,'crm.action_proposal',['Customer CRM → Tags']);
    }
    if(preg_match('/\barchive\s+(?:this|selected|the)?\s*(?:customer|profile)\b/u',$lower)){
        cac_require($user,'crm.manage','CRM management permission is required to archive customer profiles.');
        $proposal=gac_pending_store('crm',$org,$uid,'archive_customer',['customerPublicId'=>$customer['publicId'],'expected'=>cac_customer_version($pdo,$org,(string)$customer['publicId'])],'Proposed action: archive the CRM profile for '.$customer['displayName'].'. This removes it from the active customer list but preserves historical records.');
        return gac_proposal_result($proposal,'crm.action_proposal',['Customer CRM']);
    }

    if(preg_match('/\b(?:favorite|favourite|usual|top items?|likes?)\b/u',$lower))$answer=cac_favorites_answer($customer);
    elseif(preg_match('/\b(?:recent|last|previous)\s+(?:paid\s+)?(?:visits?|orders?|checks?)\b/u',$lower))$answer=cac_recent_answer($customer);
    elseif(preg_match('/\b(?:notes?|service notes?)\b/u',$lower))$answer=cac_notes_answer($customer);
    elseif(preg_match('/\btags?\b/u',$lower))$answer=cac_tags_answer($customer);
    else $answer=cac_customer_summary($customer);

    return ['ok'=>true,'skill'=>'crm.relationship','answer'=>$answer,'data'=>['customer'=>['publicId'=>$customer['publicId'],'displayName'=>$customer['displayName'],'metrics'=>$customer['metrics'],'tags'=>$customer['tags']]],'sources'=>['Customer CRM','POS Paid Visit History']];
}
