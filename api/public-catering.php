<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/restaurant-brain.php';
require __DIR__ . '/../includes/catering-brain.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405); }
app_boot_session();
$input=app_json_input();app_verify_request_csrf($input);
$now=time();$window=array_values(array_filter((array)($_SESSION['catering_submit_times']??[]),static fn($ts):bool=>(int)$ts>$now-3600));if(count($window)>=5){header('Retry-After: 3600');app_json_response(['ok'=>false,'message'=>'Too many catering requests were submitted from this browser. Please try again later.'],429);}if(trim((string)($input['website_check']??''))!=='')app_json_response(['ok'=>true,'message'=>'Thank you. Your catering request was received.']);

$company=mb_substr(trim((string)($input['companyName']??'')),0,200,'UTF-8');
$contact=mb_substr(trim((string)($input['contactName']??'')),0,180,'UTF-8');
$email=strtolower(trim((string)($input['email']??'')));
$phone=mb_substr(trim((string)($input['phone']??'')),0,50,'UTF-8');
$eventType=mb_substr(trim((string)($input['eventType']??'')),0,100,'UTF-8');
$eventDate=trim((string)($input['eventDate']??''));
$startTime=trim((string)($input['startTime']??''));$endTime=trim((string)($input['endTime']??''));
$guestCount=($input['guestCount']??'')!==''?(int)$input['guestCount']:null;
$venueName=mb_substr(trim((string)($input['venueName']??'')),0,220,'UTF-8');
$venueAddress=mb_substr(trim((string)($input['venueAddress']??'')),0,500,'UTF-8');
$serviceStyle=mb_substr(trim((string)($input['serviceStyle']??'')),0,120,'UTF-8');
$menuInterests=mb_substr(trim((string)($input['menuInterests']??'')),0,5000,'UTF-8');
$dietary=mb_substr(trim((string)($input['dietaryRequirements']??'')),0,4000,'UTF-8');
$beverage=mb_substr(trim((string)($input['beverageService']??'')),0,160,'UTF-8');
$staffing=mb_substr(trim((string)($input['staffingNeeds']??'')),0,180,'UTF-8');
$rentals=mb_substr(trim((string)($input['rentalsNeeds']??'')),0,3000,'UTF-8');
$budget=mb_substr(trim((string)($input['budgetRange']??'')),0,120,'UTF-8');
$fulfillment=mb_substr(trim((string)($input['fulfillmentPreference']??'')),0,100,'UTF-8');
$notes=mb_substr(trim((string)($input['notes']??'')),0,5000,'UTF-8');
$consent=!empty($input['consent']);
$errors=[];
if($contact==='')$errors[]='Enter a contact name.';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)$errors[]='Enter a valid email address.';
if($eventType==='')$errors[]='Select an event type.';
if(!$consent)$errors[]='Confirm that we may contact you about the catering request.';
if($guestCount!==null&&($guestCount<1||$guestCount>10000))$errors[]='Guest count must be between 1 and 10,000.';
if($eventDate!==''){$date=DateTimeImmutable::createFromFormat('Y-m-d',$eventDate);if(!$date||$date->format('Y-m-d')!==$eventDate)$errors[]='Event date is invalid.';}
foreach([['start time',$startTime],['end time',$endTime]] as [$label,$value]){if($value!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))$errors[]='The '.$label.' is invalid.';}
if($errors)app_json_response(['ok'=>false,'message'=>implode(' ',array_unique($errors))],422);

try{
    $pdo=app_pdo();if(!catering_brain_ready($pdo))app_json_response(['ok'=>false,'message'=>'Catering intake is being upgraded. Please try again shortly.'],503);
    $orgId=(int)$pdo->query("SELECT id FROM organizations WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();if($orgId<1)throw new RuntimeException('No active restaurant organization is configured.');
    $publicId=restaurant_brain_public_id('catering');
    $statement=$pdo->prepare("INSERT INTO catering_leads (organization_id,public_id,company_name,contact_name,email,phone,event_type,event_date,start_time,end_time,guest_count,venue_name,venue_address,service_style,menu_interests,dietary_requirements,beverage_service,staffing_needs,rentals_needs,budget_range,fulfillment_preference,notes,source,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'public_catering_form','new',10)");
    $statement->execute([$orgId,$publicId,$company?:null,$contact,$email,$phone?:null,$eventType,$eventDate?:null,$startTime?:null,$endTime?:null,$guestCount,$venueName?:null,$venueAddress?:null,$serviceStyle?:null,$menuInterests?:null,$dietary?:null,$beverage?:null,$staffing?:null,$rentals?:null,$budget?:null,$fulfillment?:null,$notes?:null]);
    $leadId=(int)$pdo->lastInsertId();
    $activity=$pdo->prepare("INSERT INTO catering_lead_activities (organization_id,catering_lead_id,activity_type,summary,details) VALUES (?,?,'submission','Public catering inquiry submitted',?)");
    $activity->execute([$orgId,$leadId,'Source: catering form']);
    catering_brain_sync($pdo,$orgId,$leadId,null);
    app_audit($pdo,$orgId,null,'catering.submitted','catering_lead',$publicId,null,['eventType'=>$eventType,'eventDate'=>$eventDate,'guestCount'=>$guestCount,'pipelineStage'=>'new']);
    $window[]=$now;$_SESSION['catering_submit_times']=$window;
    app_json_response(['ok'=>true,'id'=>$publicId,'message'=>'Thanks — your catering request is in our event planning pipeline. We will follow up with you directly.'],201);
}catch(PDOException $error){app_json_response(['ok'=>false,'message'=>'Catering intake is unavailable until the latest database upgrade is installed.'],503);}catch(Throwable $error){app_json_response(['ok'=>false,'message'=>'Your catering request could not be saved. Please try again.'],500);}
