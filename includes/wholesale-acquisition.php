<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';

function wholesale_acquisition_ready(PDO $pdo): bool
{
    return restaurant_brain_table_ready($pdo,'wholesale_leads') && restaurant_brain_table_ready($pdo,'wholesale_acquisition_profiles');
}

function wholesale_acquisition_completion(array $input): int
{
    $keys=['businessName','contactName','email','businessType','location','estimatedMonthlyVolume','orderFrequency','flavorsInterest','fulfillmentPreference','buyerRole','painPoints','buyingProcess','nextStep','nextFollowupAt'];
    $filled=0;foreach($keys as $key)if(trim((string)($input[$key]??''))!=='')$filled++;
    return (int)round(100*$filled/count($keys));
}

function wholesale_acquisition_stage(string $qualification): string
{
    return match($qualification){'qualified'=>'qualified','sample'=>'sample','quoted'=>'quoted','not_fit'=>'lost',default=>'new'};
}

function wholesale_acquisition_probability(string $stage): int
{
    return ['new'=>10,'qualified'=>30,'sample'=>45,'quoted'=>60,'negotiation'=>75,'won'=>100,'lost'=>0][$stage]??10;
}

function wholesale_acquisition_validate_assignee(PDO $pdo,int $org,mixed $value): ?int
{
    if($value===null||$value==='')return null;$id=(int)$value;if($id<1)return null;
    $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL");$q->execute([$org,$id]);
    if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Assigned user is not an active organization member.');
    return $id;
}

function wholesale_acquisition_save(PDO $pdo,int $org,array $input,int $userId,bool $complete=false): array
{
    if(!wholesale_acquisition_ready($pdo))throw new RuntimeException('Wholesale acquisition migration is not installed.');
    $business=mb_substr(trim((string)($input['businessName']??'')),0,200,'UTF-8');$contact=mb_substr(trim((string)($input['contactName']??'')),0,180,'UTF-8');$email=strtolower(trim((string)($input['email']??'')));
    if($business===''||$contact===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Business name, contact name, and a valid email are required.');
    $public=trim((string)($input['id']??''));$qualification=(string)($input['qualificationStatus']??'prospect');if(!in_array($qualification,['prospect','qualified','sample','quoted','not_fit'],true))$qualification='prospect';
    $stage=$complete?wholesale_acquisition_stage($qualification):'new';$prob=wholesale_acquisition_probability($stage);
    $packages=array_values(array_unique(array_filter(array_map(static fn($v)=>mb_substr(trim((string)$v),0,80,'UTF-8'),(array)($input['packageSizes']??[])))));$assigned=wholesale_acquisition_validate_assignee($pdo,$org,$input['assignedTo']??null);
    $follow=trim((string)($input['nextFollowupAt']??''));if($follow!==''){$d=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$follow);if(!$d)$d=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$follow);if(!$d)throw new InvalidArgumentException('Next follow-up date is invalid.');$follow=$d->format('Y-m-d H:i:s');}else $follow=null;
    $start=trim((string)($input['desiredStartDate']??''));if($start!==''){$d=DateTimeImmutable::createFromFormat('Y-m-d',$start);if(!$d||$d->format('Y-m-d')!==$start)throw new InvalidArgumentException('Desired start date is invalid.');}
    $estimatedValue=isset($input['estimatedValue'])&&$input['estimatedValue']!==''?max(0,(float)$input['estimatedValue']):null;$completion=wholesale_acquisition_completion($input);
    $pdo->beginTransaction();
    try{
        if($public===''){
            $public=restaurant_brain_public_id('wholesale');
            $q=$pdo->prepare("INSERT INTO wholesale_leads (organization_id,public_id,business_name,contact_name,email,phone,website,business_type,location_text,estimated_monthly_volume,order_frequency,package_sizes_json,flavors_interest,private_label_interest,freezer_capacity,fulfillment_preference,desired_start_date,current_supplier,notes,source,pipeline_stage,estimated_value,probability_percent,assigned_to,next_followup_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'staff_acquisition_worksheet',?,?,?,?,?)");
            $q->execute([$org,$public,$business,$contact,$email,mb_substr(trim((string)($input['phone']??'')),0,50,'UTF-8')?:null,mb_substr(trim((string)($input['website']??'')),0,500,'UTF-8')?:null,mb_substr(trim((string)($input['businessType']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['location']??'')),0,300,'UTF-8')?:null,mb_substr(trim((string)($input['estimatedMonthlyVolume']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['orderFrequency']??'')),0,120,'UTF-8')?:null,json_encode($packages,JSON_THROW_ON_ERROR),mb_substr(trim((string)($input['flavorsInterest']??'')),0,10000,'UTF-8')?:null,!empty($input['privateLabelInterest'])?1:0,mb_substr(trim((string)($input['freezerCapacity']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['fulfillmentPreference']??'')),0,80,'UTF-8')?:null,$start?:null,mb_substr(trim((string)($input['currentSupplier']??'')),0,200,'UTF-8')?:null,mb_substr(trim((string)($input['notes']??'')),0,10000,'UTF-8')?:null,$stage,$estimatedValue,$prob,$assigned,$follow]);
            $leadId=(int)$pdo->lastInsertId();
        }else{
            $q=$pdo->prepare('SELECT * FROM wholesale_leads WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');$q->execute([$org,$public]);$lead=$q->fetch();if(!$lead)throw new InvalidArgumentException('Wholesale lead not found.');$leadId=(int)$lead['id'];
            $q=$pdo->prepare("UPDATE wholesale_leads SET business_name=?,contact_name=?,email=?,phone=?,website=?,business_type=?,location_text=?,estimated_monthly_volume=?,order_frequency=?,package_sizes_json=?,flavors_interest=?,private_label_interest=?,freezer_capacity=?,fulfillment_preference=?,desired_start_date=?,current_supplier=?,notes=?,pipeline_stage=?,estimated_value=?,probability_percent=?,assigned_to=?,next_followup_at=?,updated_at=NOW(6) WHERE organization_id=? AND id=?");
            $q->execute([$business,$contact,$email,mb_substr(trim((string)($input['phone']??'')),0,50,'UTF-8')?:null,mb_substr(trim((string)($input['website']??'')),0,500,'UTF-8')?:null,mb_substr(trim((string)($input['businessType']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['location']??'')),0,300,'UTF-8')?:null,mb_substr(trim((string)($input['estimatedMonthlyVolume']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['orderFrequency']??'')),0,120,'UTF-8')?:null,json_encode($packages,JSON_THROW_ON_ERROR),mb_substr(trim((string)($input['flavorsInterest']??'')),0,10000,'UTF-8')?:null,!empty($input['privateLabelInterest'])?1:0,mb_substr(trim((string)($input['freezerCapacity']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['fulfillmentPreference']??'')),0,80,'UTF-8')?:null,$start?:null,mb_substr(trim((string)($input['currentSupplier']??'')),0,200,'UTF-8')?:null,mb_substr(trim((string)($input['notes']??'')),0,10000,'UTF-8')?:null,$stage,$estimatedValue,$prob,$assigned,$follow,$org,$leadId]);
        }
        $q=$pdo->prepare("INSERT INTO wholesale_acquisition_profiles (organization_id,wholesale_lead_id,acquisition_channel,qualification_status,buyer_role,location_count,estimated_monthly_units,estimated_monthly_revenue,sample_interest,decision_timeline,pain_points,buying_process,storage_notes,pricing_notes,next_step,completion_percent,completed_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?,NOW(6),NULL),?,?) ON DUPLICATE KEY UPDATE acquisition_channel=VALUES(acquisition_channel),qualification_status=VALUES(qualification_status),buyer_role=VALUES(buyer_role),location_count=VALUES(location_count),estimated_monthly_units=VALUES(estimated_monthly_units),estimated_monthly_revenue=VALUES(estimated_monthly_revenue),sample_interest=VALUES(sample_interest),decision_timeline=VALUES(decision_timeline),pain_points=VALUES(pain_points),buying_process=VALUES(buying_process),storage_notes=VALUES(storage_notes),pricing_notes=VALUES(pricing_notes),next_step=VALUES(next_step),completion_percent=VALUES(completion_percent),completed_at=IF(VALUES(completed_at) IS NOT NULL,VALUES(completed_at),completed_at),updated_by=VALUES(updated_by),updated_at=NOW(6)");
        $q->execute([$org,$leadId,mb_substr(trim((string)($input['acquisitionChannel']??'')),0,100,'UTF-8')?:null,$qualification,mb_substr(trim((string)($input['buyerRole']??'')),0,120,'UTF-8')?:null,isset($input['locationCount'])&&$input['locationCount']!==''?max(0,(int)$input['locationCount']):null,isset($input['estimatedMonthlyUnits'])&&$input['estimatedMonthlyUnits']!==''?max(0,(float)$input['estimatedMonthlyUnits']):null,isset($input['estimatedMonthlyRevenue'])&&$input['estimatedMonthlyRevenue']!==''?max(0,(float)$input['estimatedMonthlyRevenue']):null,mb_substr(trim((string)($input['sampleInterest']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['decisionTimeline']??'')),0,160,'UTF-8')?:null,mb_substr(trim((string)($input['painPoints']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['buyingProcess']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['storageNotes']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['pricingNotes']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['nextStep']??'')),0,10000,'UTF-8')?:null,$completion,$complete?1:0,$userId,$userId]);
        $activity=$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,metadata_json,created_by) VALUES (?,?,'worksheet',?,?,?,?)");
        $activity->execute([$org,$leadId,$complete?'Acquisition worksheet completed':'Acquisition worksheet saved',$complete?'Qualification: '.$qualification.'. Pipeline stage: '.$stage.'.':'Draft acquisition worksheet updated.',json_encode(['completionPercent'=>$completion,'qualificationStatus'=>$qualification,'stage'=>$stage],JSON_THROW_ON_ERROR),$userId]);
        $pdo->commit();
        restaurant_brain_sync_wholesale($pdo,$org,$leadId,$userId);
        app_audit($pdo,$org,$userId,$complete?'wholesale.acquisition_completed':'wholesale.acquisition_saved','wholesale_lead',$public,null,['completionPercent'=>$completion,'qualificationStatus'=>$qualification,'stage'=>$stage]);
        return wholesale_acquisition_get($pdo,$org,$public)??[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function wholesale_acquisition_get(PDO $pdo,int $org,string $public): ?array
{
    $q=$pdo->prepare("SELECT l.*,u.display_name assigned_name,p.acquisition_channel,p.qualification_status,p.buyer_role,p.location_count,p.estimated_monthly_units,p.estimated_monthly_revenue,p.sample_interest,p.decision_timeline,p.pain_points,p.buying_process,p.storage_notes,p.pricing_notes,p.next_step,p.completion_percent,p.completed_at FROM wholesale_leads l LEFT JOIN wholesale_acquisition_profiles p ON p.wholesale_lead_id=l.id AND p.organization_id=l.organization_id LEFT JOIN users u ON u.id=l.assigned_to WHERE l.organization_id=? AND l.public_id=? AND l.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$public]);$row=$q->fetch();if(!$row)return null;
    $row['package_sizes']=json_decode((string)($row['package_sizes_json']??'[]'),true)?:[];unset($row['package_sizes_json']);return $row;
}
