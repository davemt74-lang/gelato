<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/restaurant-brain.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
app_boot_session();
$input = app_json_input();
app_verify_request_csrf($input);

$now = time();
$window = array_values(array_filter((array)($_SESSION['catering_submit_times'] ?? []), static fn($ts): bool => (int)$ts > $now - 3600));
if (count($window) >= 5) {
    header('Retry-After: 3600');
    app_json_response(['ok'=>false,'message'=>'Too many catering requests were submitted from this browser. Please try again later.'],429);
}
if (trim((string)($input['website_check'] ?? '')) !== '') {
    app_json_response(['ok'=>true,'message'=>'Thank you. Your catering request was received.']);
}

$contactName = trim((string)($input['contactName'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$phone = trim((string)($input['phone'] ?? ''));
$companyName = trim((string)($input['companyName'] ?? ''));
$eventName = trim((string)($input['eventName'] ?? ''));
$eventType = trim((string)($input['eventType'] ?? ''));
$eventDate = trim((string)($input['eventDate'] ?? ''));
$eventStartTime = trim((string)($input['eventStartTime'] ?? ''));
$eventEndTime = trim((string)($input['eventEndTime'] ?? ''));
$guestCount = isset($input['guestCount']) && $input['guestCount'] !== '' ? (int)$input['guestCount'] : null;
$venueName = trim((string)($input['venueName'] ?? ''));
$venueAddress = trim((string)($input['venueAddress'] ?? ''));
$serviceStyle = trim((string)($input['serviceStyle'] ?? ''));
$menuInterests = array_values(array_unique(array_filter(array_map(static fn($value): string => mb_substr(trim((string)$value),0,100,'UTF-8'), (array)($input['menuInterests'] ?? [])))));
$beverageInterest = trim((string)($input['beverageInterest'] ?? ''));
$dietaryNeeds = trim((string)($input['dietaryNeeds'] ?? ''));
$staffingNeeds = trim((string)($input['staffingNeeds'] ?? ''));
$rentalNeeds = trim((string)($input['rentalNeeds'] ?? ''));
$setupNotes = trim((string)($input['setupNotes'] ?? ''));
$fulfillment = trim((string)($input['fulfillmentPreference'] ?? ''));
$budgetRange = trim((string)($input['budgetRange'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));
$consent = !empty($input['consent']);

$errors = [];
if ($contactName === '' || mb_strlen($contactName,'UTF-8') > 180) $errors[] = 'Enter a contact name.';
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $errors[] = 'Enter a valid email address.';
if ($phone !== '' && mb_strlen($phone,'UTF-8') > 50) $errors[] = 'Phone number is too long.';
if ($companyName !== '' && mb_strlen($companyName,'UTF-8') > 200) $errors[] = 'Company name is too long.';
if ($eventName !== '' && mb_strlen($eventName,'UTF-8') > 220) $errors[] = 'Event name is too long.';
if ($eventDate === '') $errors[] = 'Select the event date.';
else {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d',$eventDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $eventDate) $errors[] = 'Event date is invalid.';
}
foreach (['eventStartTime'=>$eventStartTime,'eventEndTime'=>$eventEndTime] as $label=>$value) {
    if ($value !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)) $errors[] = 'Event time is invalid.';
}
if ($guestCount !== null && ($guestCount < 1 || $guestCount > 100000)) $errors[] = 'Guest count must be between 1 and 100,000.';
if (count($menuInterests) > 20) $errors[] = 'Too many menu interests were selected.';
if (!$consent) $errors[] = 'Confirm that we may contact you about this catering request.';
if ($errors) app_json_response(['ok'=>false,'message'=>implode(' ',array_unique($errors))],422);

try {
    $pdo = app_pdo();
    if (!restaurant_brain_table_ready($pdo,'catering_leads')) {
        app_json_response(['ok'=>false,'message'=>'Catering intake is being upgraded. Please try again shortly.'],503);
    }
    $orgId = (int)$pdo->query("SELECT id FROM organizations WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($orgId < 1) throw new RuntimeException('No active restaurant organization is configured.');
    $publicId = restaurant_brain_public_id('catering');
    $statement = $pdo->prepare(
        "INSERT INTO catering_leads
        (organization_id,public_id,contact_name,email,phone,company_name,event_name,event_type,event_date,event_start_time,event_end_time,guest_count,venue_name,venue_address,service_style,menu_interests_json,beverage_interest,dietary_needs,staffing_needs,rental_needs,setup_notes,fulfillment_preference,budget_range,notes,source,pipeline_stage,probability_percent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'public_catering_form','new',10)"
    );
    $statement->execute([
        $orgId,$publicId,$contactName,$email,$phone?:null,$companyName?:null,$eventName?:null,$eventType?:null,$eventDate?:null,
        $eventStartTime?:null,$eventEndTime?:null,$guestCount,$venueName?:null,$venueAddress?:null,$serviceStyle?:null,
        json_encode($menuInterests,JSON_THROW_ON_ERROR),$beverageInterest?:null,$dietaryNeeds?:null,$staffingNeeds?:null,$rentalNeeds?:null,
        $setupNotes?:null,$fulfillment?:null,$budgetRange?:null,$notes?:null
    ]);
    $leadId = (int)$pdo->lastInsertId();
    $activity = $pdo->prepare("INSERT INTO catering_lead_activities (organization_id,catering_lead_id,activity_type,summary,details) VALUES (?,?,'submission','Public catering inquiry submitted',?)");
    $activity->execute([$orgId,$leadId,'Source: catering form']);
    restaurant_brain_sync_catering($pdo,$orgId,$leadId,null);
    app_audit($pdo,$orgId,null,'catering.submitted','catering_lead',$publicId,null,['eventType'=>$eventType,'eventDate'=>$eventDate,'guestCount'=>$guestCount,'pipelineStage'=>'new']);
    $window[] = $now;
    $_SESSION['catering_submit_times'] = $window;
    app_json_response(['ok'=>true,'id'=>$publicId,'message'=>'Thanks — your catering request is in our event planning pipeline. We will follow up with you directly.'],201);
} catch (PDOException) {
    app_json_response(['ok'=>false,'message'=>'Catering intake is unavailable until the latest database upgrade is installed.'],503);
} catch (Throwable) {
    app_json_response(['ok'=>false,'message'=>'Your catering request could not be saved. Please try again.'],500);
}
