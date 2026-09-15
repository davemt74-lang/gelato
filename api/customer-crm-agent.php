<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-crm-core.php';
require_once __DIR__.'/../includes/service-visit-core.php';
require_once __DIR__.'/../includes/service-visit-extensions.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!app_has_permission('crm.view',$user))app_json_response(['ok'=>false,'message'=>'Customer CRM view permission required.'],403);
if(!crm_ready($pdo))app_json_response(['ok'=>false,'message'=>'Customer CRM migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$message=trim((string)($in['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>5000)app_json_response(['ok'=>false,'message'=>'Enter a CRM request no longer than 5,000 characters.'],422);

try{
    $customerId=trim((string)($in['customerId']??''));$profile=null;
    if($customerId!=='')$profile=service_visit_crm_profile($pdo,$org,$customerId);
    if(!$profile){
        $query=trim((string)preg_replace('/\[Current page context:.*$/su','',$message));
        $matches=crm_search($pdo,$org,mb_substr($query,0,240,'UTF-8'),5,false);
        if(count($matches)===1){$public=(string)($matches[0]['publicId']??$matches[0]['public_id']??'');if($public!=='')$profile=service_visit_crm_profile($pdo,$org,$public);}
    }
    if(!$profile)app_json_response(['ok'=>true,'skill'=>'crm.customer_context','answer'=>'Select a customer in Customer CRM, then ask me about this customer. I will use the selected profile without exposing customers outside your organization.','data'=>null,'sources'=>[]]);

    $metrics=is_array($profile['metrics']??null)?$profile['metrics']:[];$name=(string)($profile['displayName']??'Customer');
    $lower=mb_strtolower($message,'UTF-8');$answer='';$skill='crm.customer_context';
    if(preg_match('/\b(favorite|favourite|items?|products?|orders? most)\b/u',$lower)){
        $items=array_slice(is_array($metrics['favoriteItems']??null)?$metrics['favoriteItems']:[],0,8);$skill='crm.favorite_items';
        if(!$items)$answer=$name.' has no paid-item history yet.';
        else{$parts=[];foreach($items as $item)$parts[]=(string)($item['itemName']??'Item').' — '.number_format((float)($item['quantity']??0),1).' ordered, $'.number_format((float)($item['netSpend']??0),2).' spend';$answer=$name.' favorite items: '.implode('; ',$parts).'.';}
    }elseif(preg_match('/\b(recent|visits?|checks?|purchase|history)\b/u',$lower)){
        $checks=array_slice(is_array($metrics['recentChecks']??null)?$metrics['recentChecks']:[],0,8);$skill='crm.recent_visits';
        if(!$checks)$answer=$name.' has no paid visits recorded yet.';
        else{$parts=[];foreach($checks as $check)$parts[]=(string)($check['checkNumber']??'Check').' — $'.number_format((float)($check['totalAmount']??0)-(float)($check['tipAmount']??0),2).' — '.(string)($check['locationName']??'location').' — '.(string)($check['closedAt']??'');$answer=$name.' recent paid visits: '.implode('; ',$parts).'.';}
    }else{
        $tags=array_map(static fn(array $tag):string=>(string)($tag['name']??''),is_array($profile['tags']??null)?$profile['tags']:[]);$tags=array_values(array_filter($tags));
        $emailConsent=(string)($profile['consents']['email']['status']??'unknown');$smsConsent=(string)($profile['consents']['sms']['status']??'unknown');
        $answer=$name.': '.(int)($metrics['visits']??0).' visit(s), $'.number_format((float)($metrics['lifetimeSpend']??0),2).' lifetime spend, $'.number_format((float)($metrics['averageCheck']??0),2).' average check'.(!empty($metrics['lastVisit'])?', last visit '.$metrics['lastVisit']:'').'. Marketing consent: email '.$emailConsent.', SMS '.$smsConsent.'.'.($tags?' Tags: '.implode(', ',$tags).'.':'');
    }
    $public=(string)($profile['publicId']??$customerId);app_audit($pdo,$org,$uid,'crm.agent_skill_used','crm_customer',$public,null,['skill'=>$skill]);
    app_json_response(['ok'=>true,'skill'=>$skill,'answer'=>$answer,'data'=>['publicId'=>$public,'displayName'=>$name,'metrics'=>$metrics],'sources'=>$public!==''?[$public]:[]]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$e instanceof InvalidArgumentException?422:500);}
