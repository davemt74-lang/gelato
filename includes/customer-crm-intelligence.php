<?php
declare(strict_types=1);

require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/operational-access.php';

function cri_clean_id(mixed $value,int $max=120): string
{
    $value=preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
    return mb_substr($value,0,$max,'UTF-8');
}

function cri_median(array $values): ?float
{
    $values=array_values(array_filter(array_map('floatval',$values),static fn(float $v):bool=>$v>0));
    if(!$values)return null;
    sort($values,SORT_NUMERIC);$count=count($values);$mid=intdiv($count,2);
    return $count%2?$values[$mid]:(($values[$mid-1]+$values[$mid])/2);
}

function cri_days_between(?string $from,?DateTimeImmutable $today=null): ?int
{
    if(!$from)return null;
    try{$date=new DateTimeImmutable($from);$today=$today??new DateTimeImmutable('today');return max(0,(int)$date->setTime(0,0)->diff($today->setTime(0,0))->format('%a'));}
    catch(Throwable){return null;}
}

function cri_timezone(PDO $pdo,int $org): DateTimeZone
{
    try{$q=$pdo->prepare('SELECT timezone FROM organizations WHERE id=? LIMIT 1');$q->execute([$org]);$name=trim((string)$q->fetchColumn());if($name!=='')return new DateTimeZone($name);}catch(Throwable){}
    return new DateTimeZone(date_default_timezone_get()?:'UTC');
}

function cri_birthday(PDO $pdo,int $org,array $customer): array
{
    $month=(int)($customer['birthdayMonth']??0);$day=(int)($customer['birthdayDay']??0);
    if($month<1||$day<1)return ['known'=>false,'daysUntil'=>null,'date'=>null,'today'=>false,'soon'=>false];
    $tz=cri_timezone($pdo,$org);$today=(new DateTimeImmutable('today',$tz))->setTime(0,0);$year=(int)$today->format('Y');
    $candidate=null;
    foreach([$year,$year+1,$year+2] as $y){
        if(!checkdate($month,$day,$y))continue;
        $d=DateTimeImmutable::createFromFormat('!Y-n-j',$y.'-'.$month.'-'.$day,$tz);
        if($d&&$d>=$today){$candidate=$d;break;}
    }
    if(!$candidate)return ['known'=>true,'daysUntil'=>null,'date'=>null,'today'=>false,'soon'=>false];
    $days=(int)$today->diff($candidate)->format('%a');
    return ['known'=>true,'daysUntil'=>$days,'date'=>$candidate->format('Y-m-d'),'today'=>$days===0,'soon'=>$days<=14];
}

function cri_contact_channels(array $customer): array
{
    $channels=[];
    foreach((array)($customer['consents']??[]) as $channel=>$row){
        $key=is_string($channel)?$channel:(string)($row['channel']??'');
        if($key!==''&&(string)($row['status']??'')==='opted_in')$channels[]=$key;
    }
    return array_values(array_unique($channels));
}

function cri_spend_percentile(PDO $pdo,int $org,int $customerId,float $customerSpend): ?float
{
    if($customerSpend<=0)return null;
    $q=$pdo->prepare("SELECT c.customer_id,SUM(GREATEST(0,c.total_amount-c.tip_amount)) spend FROM pos_checks c WHERE c.organization_id=? AND c.status='paid' AND c.customer_id IS NOT NULL GROUP BY c.customer_id HAVING spend>0");
    $q->execute([$org]);$rows=$q->fetchAll();if(!$rows)return null;
    $below=0;$eligible=0;$found=false;
    foreach($rows as $row){$spend=(float)$row['spend'];$eligible++;if((int)$row['customer_id']===$customerId)$found=true;if($spend<$customerSpend)$below++;}
    if(!$found||$eligible<2)return null;
    return round(($below/max(1,$eligible-1))*100,1);
}

function cri_relationship(PDO $pdo,int $org,array $customer): array
{
    $m=(array)($customer['metrics']??[]);$visits=(int)($m['visits']??0);$spend=(float)($m['lifetimeSpend']??0);$avg=(float)($m['averageCheck']??0);
    $row=crm_customer_row($pdo,$org,(string)$customer['publicId']);$customerId=(int)$row['id'];$tz=cri_timezone($pdo,$org);$today=new DateTimeImmutable('today',$tz);
    $dates=[];foreach((array)($m['recentChecks']??[]) as $check){$v=(string)($check['closedAt']??'');if($v!==''){try{$dates[]=(new DateTimeImmutable($v,$tz))->setTime(0,0);}catch(Throwable){}}}
    usort($dates,static fn(DateTimeImmutable $a,DateTimeImmutable $b):int=>$b<=>$a);$intervals=[];for($i=0;$i<count($dates)-1;$i++){$days=(int)$dates[$i+1]->diff($dates[$i])->format('%a');if($days>0)$intervals[]=$days;}
    $median=cri_median($intervals);$daysSinceLast=cri_days_between((string)($m['lastVisit']??''),$today);$reengageThreshold=max(45,(int)round(($median??24)*2.5));
    $stage=$visits>=5?'regular':($visits>=2?'returning':($visits===1?'early':'new'));
    $percentile=cri_spend_percentile($pdo,$org,$customerId,$spend);$topSpend=$percentile!==null&&$percentile>=90&&$visits>=2;
    $reengage=$visits>=3&&$daysSinceLast!==null&&$daysSinceLast>$reengageThreshold;
    $birthday=cri_birthday($pdo,$org,$customer);$channels=cri_contact_channels($customer);
    return [
        'stage'=>$stage,
        'stageLabel'=>match($stage){'regular'=>'Regular','returning'=>'Returning','early'=>'Early relationship',default=>'New'},
        'visits'=>$visits,'lifetimeSpend'=>round($spend,2),'averageCheck'=>round($avg,2),'lastVisit'=>$m['lastVisit']??null,
        'daysSinceLastVisit'=>$daysSinceLast,'typicalVisitIntervalDays'=>$median!==null?(int)round($median):null,'reengagementThresholdDays'=>$reengageThreshold,'reengagementOpportunity'=>$reengage,
        'spendPercentile'=>$percentile,'topSpendCohort'=>$topSpend,
        'birthday'=>$birthday,'contactChannels'=>$channels,'hasContactChannel'=>!empty($channels),
        'favoriteItems'=>array_slice((array)($m['favoriteItems']??[]),0,5),
    ];
}

function cri_context_customer(PDO $pdo,array $user,array $context): array
{
    $org=(int)$user['organization_id'];$module=(string)($context['module']??'');
    if($module==='crm'){
        $public=cri_clean_id($context['customerPublicId']??'');
        return ['customer'=>$public!==''?crm_profile($pdo,$org,$public):null,'check'=>null];
    }
    if(!in_array($module,['pos','table_service','kds'],true))return ['customer'=>null,'check'=>null];
    $checkPublic=cri_clean_id($context['checkPublicId']??'');if($checkPublic==='')return ['customer'=>null,'check'=>null];
    $q=$pdo->prepare('SELECT c.id,c.public_id,c.check_number,c.location_id,c.status,c.customer_id,l.name location_name FROM pos_checks c JOIN locations l ON l.id=c.location_id WHERE c.organization_id=? AND c.public_id=? LIMIT 1');$q->execute([$org,$checkPublic]);$check=$q->fetch();if(!$check)return ['customer'=>null,'check'=>null];
    $permission=match($module){'pos'=>'pos.use','table_service'=>'table_service.view','kds'=>'kds.view',default=>'crm.view'};
    if(!app_has_permission($permission,$user)||!operational_location_allowed($pdo,$user,$permission,(int)$check['location_id']))throw new DomainException('This customer context is outside your assigned restaurant location access.');
    if(empty($check['customer_id']))return ['customer'=>null,'check'=>['publicId'=>$checkPublic,'checkNumber'=>$check['check_number'],'locationId'=>(int)$check['location_id'],'locationName'=>$check['location_name']]];
    $q=$pdo->prepare('SELECT public_id FROM crm_customers WHERE organization_id=? AND id=? AND status=\'active\' LIMIT 1');$q->execute([$org,(int)$check['customer_id']]);$public=(string)$q->fetchColumn();
    return ['customer'=>$public!==''?crm_profile($pdo,$org,$public):null,'check'=>['publicId'=>$checkPublic,'checkNumber'=>$check['check_number'],'locationId'=>(int)$check['location_id'],'locationName'=>$check['location_name']]];
}

function cri_relationship_answer(PDO $pdo,int $org,array $customer,string $message): array
{
    $r=cri_relationship($pdo,$org,$customer);$name=(string)$customer['displayName'];$lower=mb_strtolower($message,'UTF-8');
    if(preg_match('/\b(?:birthday|birth day)\b/u',$lower)){
        $b=$r['birthday'];$answer=!$b['known']?$name.' does not have a birthday month/day recorded.':($b['today']?$name.' has a birthday today.':$name.' has a birthday in '.$b['daysUntil'].' day'.($b['daysUntil']===1?'':'s').' ('.$b['date'].').');
    }elseif(preg_match('/\b(?:consent|opt.?in|contact|email|text|sms|message)\b/u',$lower)){
        $answer=$r['hasContactChannel']?$name.' currently has recorded opt-in for '.implode(' and ',$r['contactChannels']).'.':$name.' does not currently have a recorded email or SMS opt-in. Do not send marketing outreach without the required consent record.';
    }elseif(preg_match('/\b(?:vip|high value|valuable|top spend|best customer)\b/u',$lower)){
        $answer=$name.' has '.$r['visits'].' paid visits and $'.number_format($r['lifetimeSpend'],2).' lifetime spend.';
        if($r['spendPercentile']!==null)$answer.=' That is approximately the '.$r['spendPercentile'].'th percentile of identified-customer lifetime spend for this restaurant account.';
        $answer.=$r['topSpendCohort']?' This places the customer in the current top-spend cohort.':' I would not label the customer high-value from spend rank alone.';
    }elseif(preg_match('/\b(?:lapsed|re.?engage|inactive|haven.?t seen|not been back)\b/u',$lower)){
        $answer=$name.' was last recorded on a paid visit '.($r['daysSinceLastVisit']===null?'at an unknown time':$r['daysSinceLastVisit'].' day(s) ago').'. ';
        $answer.=$r['reengagementOpportunity']?'Based on the customer’s own visit history, this is a re-engagement opportunity.':'The current history does not cross the re-engagement heuristic.';
    }else{
        $answer=$name.' is in the '.$r['stageLabel'].' relationship stage with '.$r['visits'].' paid visit'.($r['visits']===1?'':'s').', $'.number_format($r['lifetimeSpend'],2).' lifetime spend, and a $'.number_format($r['averageCheck'],2).' average check.';
        if($r['daysSinceLastVisit']!==null)$answer.=' Last paid visit was '.$r['daysSinceLastVisit'].' day(s) ago.';
        if($r['topSpendCohort'])$answer.=' The customer is currently in the top-spend cohort.';
        if($r['birthday']['soon'])$answer.=' Birthday is coming up in '.$r['birthday']['daysUntil'].' day(s).';
        if($r['reengagementOpportunity'])$answer.=' The customer also meets the current re-engagement heuristic.';
    }
    return ['answer'=>$answer,'relationship'=>$r,'sources'=>['Customer CRM','POS Paid Visit History','CRM Consent History']];
}

function cri_global_opportunities(PDO $pdo,array $user,int $limit=12): array
{
    if(!app_has_permission('crm.view',$user))return [];$org=(int)$user['organization_id'];$limit=max(1,min(30,$limit));
    $q=$pdo->prepare("SELECT cu.public_id,cu.display_name,COUNT(c.id) visits,COALESCE(SUM(GREATEST(0,c.total_amount-c.tip_amount)),0) spend,MAX(c.closed_at) last_visit FROM crm_customers cu LEFT JOIN pos_checks c ON c.organization_id=cu.organization_id AND c.customer_id=cu.id AND c.status='paid' WHERE cu.organization_id=? AND cu.status='active' GROUP BY cu.id,cu.public_id,cu.display_name HAVING visits>0 ORDER BY spend DESC,last_visit ASC LIMIT 120");$q->execute([$org]);$base=$q->fetchAll();
    $rows=[];$seen=[];
    $open=$pdo->prepare("SELECT DISTINCT cu.public_id FROM pos_checks c JOIN crm_customers cu ON cu.id=c.customer_id AND cu.organization_id=c.organization_id WHERE c.organization_id=? AND c.status='open' AND c.customer_id IS NOT NULL ORDER BY c.updated_at DESC LIMIT 30");$open->execute([$org]);
    foreach($open->fetchAll(PDO::FETCH_COLUMN) as $public){try{$customer=crm_profile($pdo,$org,(string)$public);$r=cri_relationship($pdo,$org,$customer);if($r['stage']!=='regular'&&!$r['topSpendCohort'])continue;$key='in-house:'.$public;$seen[$key]=true;$rows[]=['key'=>$key,'type'=>'in_house_relationship','severity'=>'normal','score'=>$r['topSpendCohort']?72:62,'customer'=>$customer,'relationship'=>$r,'title'=>'Customer relationship opportunity: '.$customer['displayName'].' is in house','detail'=>$r['visits'].' paid visits · $'.number_format($r['lifetimeSpend'],2).' lifetime spend','prompt'=>'Summarize the relationship history, favorites, and service notes for '.$customer['displayName'].'.'];}catch(Throwable){}}
    foreach($base as $baseRow){if(count($rows)>=$limit*3)break;try{$customer=crm_profile($pdo,$org,(string)$baseRow['public_id']);$r=cri_relationship($pdo,$org,$customer);$public=(string)$customer['publicId'];
        if($r['birthday']['soon']){$key='birthday:'.$public.':'.$r['birthday']['date'];if(!isset($seen[$key])){$seen[$key]=true;$rows[]=['key'=>$key,'type'=>'birthday','severity'=>'normal','score'=>$r['birthday']['today']?70:50,'customer'=>$customer,'relationship'=>$r,'title'=>'Upcoming customer birthday: '.$customer['displayName'],'detail'=>$r['birthday']['today']?'Birthday is today.':'Birthday is in '.$r['birthday']['daysUntil'].' day(s).','prompt'=>'Review '.$customer['displayName'].' relationship history and current marketing consent before any birthday outreach.'];}}
        if($r['reengagementOpportunity']&&($r['topSpendCohort']||$r['visits']>=5)){$key='reengage:'.$public;if(!isset($seen[$key])){$seen[$key]=true;$rows[]=['key'=>$key,'type'=>'reengagement','severity'=>'normal','score'=>$r['topSpendCohort']?68:58,'customer'=>$customer,'relationship'=>$r,'title'=>'Customer re-engagement opportunity: '.$customer['displayName'],'detail'=>$r['daysSinceLastVisit'].' days since last paid visit · '.$r['visits'].' visits · $'.number_format($r['lifetimeSpend'],2).' lifetime spend','prompt'=>'Review '.$customer['displayName'].' history and consent, then recommend an appropriate re-engagement follow-up.'];}}
    }catch(Throwable){}}
    usort($rows,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:strcmp((string)$a['title'],(string)$b['title']));
    return array_slice($rows,0,$limit);
}
