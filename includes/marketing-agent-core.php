<?php
declare(strict_types=1);

require_once __DIR__.'/package-deals-core.php';
require_once __DIR__.'/public-site.php';
require_once __DIR__.'/customer-crm-intelligence.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class MarketingAgentPermissionException extends RuntimeException {}

function mac_can_read(array $user): bool
{
    return app_has_permission('packages.view',$user)
        || app_has_permission('public_pages.edit',$user)
        || app_has_permission('settings.organization_edit',$user)
        || app_has_permission('sales.view',$user)
        || app_has_permission('crm.view',$user);
}

function mac_require_read(array $user): void
{
    if(!mac_can_read($user))throw new MarketingAgentPermissionException('Marketing or public-site access is required.');
}

function mac_require_package_manage(array $user): void
{
    if(!app_has_permission('packages.manage',$user))throw new MarketingAgentPermissionException('Package management permission is required for this promotional change.');
}

function mac_require_public_edit(array $user): void
{
    if(!app_has_permission('public_pages.edit',$user)&&!app_has_permission('settings.organization_edit',$user)){
        throw new MarketingAgentPermissionException('Public-site edit permission is required for this change.');
    }
}

function mac_clean_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function mac_context_package(PDO $pdo,int $org,array $context): ?array
{
    if((string)($context['module']??'')!=='marketing')return null;
    $public=mac_clean_id($context['selectedPackagePublicId']??'');
    if($public==='')return null;
    try{return package_deal_payload($pdo,$org,package_deal_row($pdo,$org,$public));}catch(Throwable){return null;}
}

function mac_packages(PDO $pdo,int $org): array
{
    return package_deals_ready($pdo)?package_deal_list($pdo,$org,true):[];
}

function mac_find_package(PDO $pdo,int $org,string $message,array $context): ?array
{
    if($selected=mac_context_package($pdo,$org,$context))return $selected;
    $packages=mac_packages($pdo,$org);
    if(!$packages)return null;
    $lower=mb_strtolower($message,'UTF-8');
    $matches=[];
    foreach($packages as $package){
        $name=mb_strtolower(trim((string)$package['name']),'UTF-8');
        $slug=mb_strtolower(trim((string)$package['slug']),'UTF-8');
        if(($name!==''&&str_contains($lower,$name))||($slug!==''&&str_contains($lower,$slug)))$matches[]=$package;
    }
    return count($matches)===1?$matches[0]:null;
}

function mac_site_version(PDO $pdo,int $org): array
{
    $settings=public_site_load_settings($pdo,$org);
    $updatedAt=null;$storedTagline=null;
    try{
        $q=$pdo->prepare('SELECT tagline,updated_at FROM public_site_settings WHERE organization_id=? LIMIT 1');
        $q->execute([$org]);$row=$q->fetch();
        if($row){$storedTagline=$row['tagline'];$updatedAt=(string)$row['updated_at'];}
    }catch(Throwable){}
    return ['effectiveTagline'=>(string)($settings['tagline']??''),'storedTagline'=>$storedTagline,'updatedAt'=>$updatedAt];
}

function mac_sales_snapshot(PDO $pdo,int $org,array $user): array
{
    if(!app_has_permission('sales.view',$user))return [];
    try{
        $q=$pdo->prepare("SELECT COUNT(*) checks,COALESCE(SUM(GREATEST(0,total_amount-tip_amount)),0) revenue,COALESCE(AVG(GREATEST(0,total_amount-tip_amount)),0) average_check FROM pos_checks WHERE organization_id=? AND status='paid' AND closed_at>=DATE_SUB(NOW(6),INTERVAL 30 DAY)");
        $q->execute([$org]);$row=$q->fetch()?:[];
        return ['windowDays'=>30,'paidChecks'=>(int)($row['checks']??0),'revenue'=>round((float)($row['revenue']??0),2),'averageCheck'=>round((float)($row['average_check']??0),2)];
    }catch(Throwable){return [];}
}

function mac_crm_opportunities(PDO $pdo,array $user): array
{
    if(!app_has_permission('crm.view',$user)||!crm_ready($pdo))return [];
    try{return cri_global_opportunities($pdo,$user,8);}catch(Throwable){return [];}
}

function mac_snapshot(PDO $pdo,array $user): array
{
    mac_require_read($user);$org=(int)$user['organization_id'];$packages=mac_packages($pdo,$org);
    $counts=['active'=>0,'paused'=>0,'draft'=>0,'ended'=>0,'archived'=>0];$featured=[];$revenue=0.0;$redemptions=0;
    foreach($packages as $package){$status=(string)$package['status'];if(isset($counts[$status]))$counts[$status]++;if(!empty($package['featured']))$featured[]=$package;$revenue+=(float)($package['stats']['revenue']??0);$redemptions+=(int)($package['stats']['redemptions']??0);}
    $active=array_values(array_filter($packages,static fn(array $p):bool=>(string)$p['status']==='active'));
    usort($active,static fn(array $a,array $b):int=>((float)($b['stats']['revenue']??0)<=>(float)($a['stats']['revenue']??0))?:((int)($b['stats']['redemptions']??0)<=>(int)($a['stats']['redemptions']??0)));
    $settings=public_site_load_settings($pdo,$org);
    return [
        'packages'=>$packages,'counts'=>$counts,'activePackages'=>$active,'featuredPackages'=>$featured,
        'packageRevenue'=>round($revenue,2),'packageRedemptions'=>$redemptions,
        'publicSite'=>['tagline'=>(string)($settings['tagline']??''),'phone'=>(string)($settings['phone']??''),'email'=>(string)($settings['email']??'')],
        'sales'=>mac_sales_snapshot($pdo,$org,$user),'crmOpportunities'=>mac_crm_opportunities($pdo,$user),
    ];
}

function mac_package_answer(array $package): string
{
    $stats=(array)($package['stats']??[]);$discount=(string)$package['discountMethod']==='percent'?rtrim(rtrim(number_format((float)$package['discountValue'],2,'.',''),'0'),'.').'%':'$'.number_format((float)$package['discountValue'],2);
    return (string)$package['name'].' is '.(string)$package['status'].', offers '.$discount.' off, has '.(int)($stats['redemptions']??0).' redemption'.((int)($stats['redemptions']??0)===1?'':'s').' and $'.number_format((float)($stats['revenue']??0),2).' package revenue. '.(!empty($package['featured'])?'It is marked Featured on the public Specials page.':'It is not currently marked Featured.');
}

function mac_overview_answer(array $snapshot): string
{
    $counts=$snapshot['counts'];$answer='Marketing snapshot: '.$counts['active'].' active package'.($counts['active']===1?'':'s').', '.$counts['paused'].' paused, '.$counts['draft'].' draft, and '.$snapshot['packageRedemptions'].' total package redemption'.($snapshot['packageRedemptions']===1?'':'s').' generating $'.number_format((float)$snapshot['packageRevenue'],2).' package revenue.';
    $activeFeatured=array_values(array_filter($snapshot['featuredPackages'],static fn(array $p):bool=>(string)$p['status']==='active'));
    $answer.=$activeFeatured?' Public Specials currently feature '.implode(', ',array_map(static fn(array $p):string=>(string)$p['name'],array_slice($activeFeatured,0,3))).'.':' No active package is currently marked Featured on the public Specials page.';
    if($snapshot['sales'])$answer.=' Paid POS sales over the last 30 days: $'.number_format((float)$snapshot['sales']['revenue'],2).' across '.(int)$snapshot['sales']['paidChecks'].' checks.';
    if($snapshot['crmOpportunities'])$answer.=' CRM currently has '.count($snapshot['crmOpportunities']).' relationship opportunit'.(count($snapshot['crmOpportunities'])===1?'y':'ies').' worth reviewing; any outreach still requires recorded channel consent.';
    return $answer;
}

function mac_recommendation_answer(array $snapshot): string
{
    $active=$snapshot['activePackages'];
    if(!$active){
        $drafts=array_values(array_filter($snapshot['packages'],static fn(array $p):bool=>(string)$p['status']==='draft'));
        return $drafts?'There is no active package promotion right now. '.$drafts[0]['name'].' is currently draft; review its dates, menu availability and economics before proposing activation.':'There is no active package promotion to recommend yet. Build a Package Deal first, then I can compare its performance and public visibility.';
    }
    $top=$active[0];$answer='The strongest currently active package by realized package revenue is '.$top['name'].' with '.(int)($top['stats']['redemptions']??0).' order'.((int)($top['stats']['redemptions']??0)===1?'':'s').' and $'.number_format((float)($top['stats']['revenue']??0),2).' revenue.';
    if(empty($top['featured']))$answer.=' It is not currently Featured, so featuring it on the public Specials page is a reasonable change to review.';
    if($snapshot['crmOpportunities'])$answer.=' CRM also has '.count($snapshot['crmOpportunities']).' current relationship opportunities. I can summarize them, but I will not send marketing outreach or change consent through this node.';
    return $answer;
}

function mac_extract_tagline(string $message): string
{
    if(!preg_match('/\b(?:set|update|change)\s+(?:the\s+)?(?:(?:public\s+site|website|home\s*page)\s+)?tagline\s+(?:to\s+)?[“"\']?(.+?)[”"\']?\s*[.!]?$/iu',$message,$m))return '';
    return mb_substr(trim((string)$m[1]," \t\n\r\0\x0B\"'“”"),0,255,'UTF-8');
}

function mac_package_status_action(string $message): ?string
{
    $lower=mb_strtolower($message,'UTF-8');
    if(!preg_match('/\b(package|package deal|deal|specials?)\b/u',$lower))return null;
    foreach(['activate','pause','resume','end','archive'] as $action)if(preg_match('/\b'.preg_quote($action,'/').'\b/u',$lower))return $action;
    return null;
}

function mac_feature_action(string $message): ?bool
{
    $lower=mb_strtolower($message,'UTF-8');
    if(!preg_match('/\b(package|deal|specials?|homepage|home page|public site)\b/u',$lower))return null;
    if(preg_match('/\b(unfeature|remove\s+(?:it\s+)?from\s+(?:the\s+)?(?:homepage|home page|public site)|stop\s+featuring)\b/u',$lower))return false;
    if(preg_match('/\b(feature|featured|feature\s+(?:it\s+)?on\s+(?:the\s+)?(?:homepage|home page|public site))\b/u',$lower))return true;
    return null;
}

function mac_guard_package(PDO $pdo,int $org,array $payload): array
{
    $public=mac_clean_id($payload['packagePublicId']??'');if($public==='')throw new InvalidArgumentException('The proposed package is missing.');
    $row=package_deal_row($pdo,$org,$public,true);
    if((string)$row['status']!==(string)($payload['expectedStatus']??'')||(string)$row['updated_at']!==(string)($payload['expectedUpdatedAt']??''))throw new InvalidArgumentException('That package changed after I proposed the action. Review it and ask again.');
    return $row;
}

function mac_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new MarketingAgentPermissionException('This Marketing Agent proposal does not belong to your session.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    if($type==='package_status'){
        mac_require_package_manage($user);$row=mac_guard_package($pdo,$org,$payload);$action=(string)$payload['action'];
        $package=package_deal_set_status($pdo,$org,(string)$row['public_id'],$action,$uid);
        app_audit($pdo,$org,$uid,'marketing.agent_package_status','package_deal',(string)$row['public_id'],null,['proposalId'=>$proposal['id'],'action'=>$action,'status'=>$package['status']]);
        return ['skill'=>'marketing.action_confirmed','answer'=>'Confirmed. '.$package['name'].' is now '.$package['status'].'.','data'=>['action'=>'package_status','package'=>$package],'sources'=>['Package Deals']];
    }
    if($type==='package_feature'){
        mac_require_package_manage($user);$row=mac_guard_package($pdo,$org,$payload);$featured=!empty($payload['featured']);
        $q=$pdo->prepare('UPDATE package_deals SET featured=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status=? AND updated_at=?');
        $q->execute([$featured?1:0,$uid,$org,(int)$row['id'],$payload['expectedStatus'],$payload['expectedUpdatedAt']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('That package changed while I was confirming it. Review it and ask again.');
        $package=package_deal_payload($pdo,$org,package_deal_row($pdo,$org,(string)$row['public_id']));
        app_audit($pdo,$org,$uid,'marketing.agent_package_featured','package_deal',(string)$row['public_id'],null,['proposalId'=>$proposal['id'],'featured'=>$featured]);
        $visibility=$featured&&$package['status']!=='active'?' It will appear publicly once the package is active and inside its configured date window.':'';
        return ['skill'=>'marketing.action_confirmed','answer'=>'Confirmed. '.$package['name'].' is '.($featured?'now Featured':'no longer Featured').' on the public Specials experience.'.$visibility,'data'=>['action'=>'package_feature','package'=>$package],'sources'=>['Package Deals','Public Specials']];
    }
    if($type==='public_tagline'){
        mac_require_public_edit($user);$current=mac_site_version($pdo,$org);
        if((string)$current['effectiveTagline']!==(string)($payload['expectedTagline']??'')||(string)($current['updatedAt']??'')!==(string)($payload['expectedUpdatedAt']??''))throw new InvalidArgumentException('Public-site settings changed after I proposed the tagline update. Review them and ask again.');
        $tagline=mb_substr(trim((string)$payload['tagline']),0,255,'UTF-8');if($tagline==='')throw new InvalidArgumentException('The proposed public tagline is empty.');
        $pdo->prepare('INSERT INTO public_site_settings (organization_id,tagline,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE tagline=VALUES(tagline),updated_by=VALUES(updated_by),updated_at=NOW(6)')->execute([$org,$tagline,$uid]);
        app_audit($pdo,$org,$uid,'marketing.agent_public_tagline','public_site_settings',(string)$org,['tagline'=>$current['effectiveTagline']],['tagline'=>$tagline,'proposalId'=>$proposal['id']]);
        return ['skill'=>'marketing.action_confirmed','answer'=>'Confirmed. Updated the public-site tagline to “'.$tagline.'”.','data'=>['action'=>'public_tagline','tagline'=>$tagline],'sources'=>['Public Site Settings']];
    }
    throw new InvalidArgumentException('That pending Marketing Agent action is no longer supported.');
}

function marketing_agent_handle(PDO $pdo,array $user,array $input): array
{
    mac_require_read($user);$org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato a marketing question no longer than 2,400 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$pending=gac_pending_get('marketing',$org,$uid);
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Marketing Agent action to confirm.');
        try{$result=mac_execute_pending($pdo,$org,$uid,$user,$pending);}catch(Throwable $e){gac_pending_clear('marketing',$org,$uid);throw $e;}
        gac_pending_clear('marketing',$org,$uid);app_audit($pdo,$org,$uid,'marketing.agent_action_confirmed','marketing_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('marketing',$org,$uid);app_audit($pdo,$org,$uid,'marketing.agent_action_discarded','marketing_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true,'skill'=>'marketing.action_cancelled','answer'=>'Cancelled. I did not change the promotion or public site.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Marketing Agent']];
    }

    $tagline=mac_extract_tagline($message);
    if($tagline!==''){
        mac_require_public_edit($user);$version=mac_site_version($pdo,$org);
        if($tagline===(string)$version['effectiveTagline'])return ['ok'=>true,'skill'=>'marketing.public_site','answer'=>'The public-site tagline is already “'.$tagline.'”.','data'=>['tagline'=>$tagline],'sources'=>['Public Site Settings']];
        $proposal=gac_pending_store('marketing',$org,$uid,'public_tagline',['tagline'=>$tagline,'expectedTagline'=>$version['effectiveTagline'],'expectedUpdatedAt'=>$version['updatedAt']],'Proposed action: change the public-site tagline from “'.$version['effectiveTagline'].'” to “'.$tagline.'”.');
        return gac_proposal_result($proposal,'marketing.action_proposal',['Public Site Settings']);
    }

    $package=mac_find_package($pdo,$org,$message,$context);$statusAction=mac_package_status_action($message);
    if($statusAction!==null){
        mac_require_package_manage($user);if(!$package)throw new InvalidArgumentException('Select the package on the Package Deals page or name the package you want to change.');
        if(($statusAction==='activate'&&$package['status']==='active')||($statusAction==='pause'&&$package['status']==='paused')||($statusAction==='resume'&&$package['status']==='active')||($statusAction==='end'&&$package['status']==='ended')||($statusAction==='archive'&&$package['status']==='archived'))return ['ok'=>true,'skill'=>'marketing.package','answer'=>$package['name'].' is already '.$package['status'].'.','data'=>['package'=>$package],'sources'=>['Package Deals']];
        $row=package_deal_row($pdo,$org,(string)$package['id']);
        $proposal=gac_pending_store('marketing',$org,$uid,'package_status',['packagePublicId'=>$package['id'],'action'=>$statusAction,'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at']],'Proposed action: '.$statusAction.' the Package Deal “'.$package['name'].'” (currently '.$package['status'].').');
        return gac_proposal_result($proposal,'marketing.action_proposal',['Package Deals']);
    }

    $featureAction=mac_feature_action($message);
    if($featureAction!==null){
        mac_require_package_manage($user);if(!$package)throw new InvalidArgumentException('Select the package on the Package Deals page or name the package you want to feature.');
        if((bool)$package['featured']===$featureAction)return ['ok'=>true,'skill'=>'marketing.package','answer'=>$package['name'].' is already '.($featureAction?'Featured':'not Featured').'.','data'=>['package'=>$package],'sources'=>['Package Deals','Public Specials']];
        $row=package_deal_row($pdo,$org,(string)$package['id']);
        $proposal=gac_pending_store('marketing',$org,$uid,'package_feature',['packagePublicId'=>$package['id'],'featured'=>$featureAction,'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at']],'Proposed action: '.($featureAction?'feature':'unfeature').' the Package Deal “'.$package['name'].'” on the public Specials experience.');
        return gac_proposal_result($proposal,'marketing.action_proposal',['Package Deals','Public Specials']);
    }

    $snapshot=mac_snapshot($pdo,$user);$lower=mb_strtolower($message,'UTF-8');
    if($package&&preg_match('/\b(this|selected|package|deal|special|performance|orders?|revenue|status|featured)\b/u',$lower))return ['ok'=>true,'skill'=>'marketing.package','answer'=>mac_package_answer($package),'data'=>['package'=>$package],'sources'=>['Package Deals']];
    if(preg_match('/\b(what should (?:we|i) promote|recommend.*promotion|best promotion|marketing opportunity|promotion opportunity|what should be featured)\b/u',$lower))return ['ok'=>true,'skill'=>'marketing.recommendation','answer'=>mac_recommendation_answer($snapshot),'data'=>$snapshot,'sources'=>['Package Deals','POS Paid Sales','Customer CRM']];
    if(preg_match('/\b(send|email|text|sms|message|blast|campaign send|marketing consent|opt.?in|opt.?out)\b/u',$lower))return ['ok'=>true,'skill'=>'marketing.consent_boundary','answer'=>'I can identify promotion and CRM opportunities, but this Marketing node does not send email/SMS or change consent. Outreach must use the explicit consent-aware campaign controls and the customer’s recorded channel permissions.','data'=>['crmOpportunities'=>$snapshot['crmOpportunities']],'sources'=>['Customer CRM → Marketing Consent']];
    if(preg_match('/\b(public site|website|homepage|home page|tagline|specials page)\b/u',$lower)){
        $featured=array_values(array_filter($snapshot['featuredPackages'],static fn(array $p):bool=>(string)$p['status']==='active'));
        $answer='Public-site tagline: “'.$snapshot['publicSite']['tagline'].'”. '.($featured?'Active Featured package'.(count($featured)===1?'':'s').': '.implode(', ',array_map(static fn(array $p):string=>(string)$p['name'],$featured)).'.':'No active package is currently Featured on the public Specials page.');
        return ['ok'=>true,'skill'=>'marketing.public_site','answer'=>$answer,'data'=>['publicSite'=>$snapshot['publicSite'],'featuredPackages'=>$featured],'sources'=>['Public Site Settings','Package Deals']];
    }
    return ['ok'=>true,'skill'=>'marketing.overview','answer'=>mac_overview_answer($snapshot),'data'=>$snapshot,'sources'=>['Package Deals','Public Site Settings','POS Paid Sales','Customer CRM']];
}
