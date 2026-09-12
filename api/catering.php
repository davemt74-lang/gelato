<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/restaurant-brain.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD'] === 'GET' ? 'catering.view' : 'catering.manage');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$stages = ['new','qualified','tasting','proposal','deposit','booked','completed','lost'];
if (!restaurant_brain_table_ready($pdo,'catering_leads')) app_json_response(['ok'=>false,'message'=>'Catering pipeline migration is not installed. Run upgrade.php.'],503);

function catering_public_row(array $row): array
{
    return [
        'id'=>(string)$row['public_id'],'contactName'=>(string)$row['contact_name'],'email'=>(string)$row['email'],'phone'=>(string)($row['phone']??''),'companyName'=>(string)($row['company_name']??''),'eventName'=>(string)($row['event_name']??''),'eventType'=>(string)($row['event_type']??''),'eventDate'=>$row['event_date'],'eventStartTime'=>$row['event_start_time'],'eventEndTime'=>$row['event_end_time'],'guestCount'=>$row['guest_count']!==null?(int)$row['guest_count']:null,'venueName'=>(string)($row['venue_name']??''),'venueAddress'=>(string)($row['venue_address']??''),'serviceStyle'=>(string)($row['service_style']??''),'menuInterests'=>json_decode((string)($row['menu_interests_json']??'[]'),true)?:[],'beverageInterest'=>(string)($row['beverage_interest']??''),'dietaryNeeds'=>(string)($row['dietary_needs']??''),'staffingNeeds'=>(string)($row['staffing_needs']??''),'rentalNeeds'=>(string)($row['rental_needs']??''),'setupNotes'=>(string)($row['setup_notes']??''),'fulfillmentPreference'=>(string)($row['fulfillment_preference']??''),'budgetRange'=>(string)($row['budget_range']??''),'estimatedValue'=>$row['estimated_value']!==null?(float)$row['estimated_value']:null,'probability'=>(int)$row['probability_percent'],'stage'=>(string)$row['pipeline_stage'],'assignedTo'=>$row['assigned_to']!==null?(int)$row['assigned_to']:null,'assignedName'=>(string)($row['assigned_name']??''),'nextFollowupAt'=>$row['next_followup_at'],'lastContactAt'=>$row['last_contact_at'],'proposalSentAt'=>$row['proposal_sent_at'],'depositDueAt'=>$row['deposit_due_at'],'bookedAt'=>$row['booked_at'],'completedAt'=>$row['completed_at'],'lossReason'=>(string)($row['loss_reason']??''),'notes'=>(string)($row['notes']??''),'source'=>(string)$row['source'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'list');
    if ($action === 'list') {
        $q = trim((string)($_GET['q'] ?? ''));$stage=trim((string)($_GET['stage'] ?? ''));$params=[$organizationId];$where='c.organization_id=? AND c.archived_at IS NULL';
        if ($stage !== '' && in_array($stage,$stages,true)) {$where.=' AND c.pipeline_stage=?';$params[]=$stage;}
        if ($q !== '') {$where.=' AND (c.contact_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.event_name LIKE ? OR c.event_type LIKE ? OR c.venue_name LIKE ? OR c.venue_address LIKE ? OR c.notes LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like,$like,$like,$like,$like);}
        $statement=$pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE {$where} ORDER BY COALESCE(c.event_date,'9999-12-31') ASC, FIELD(c.pipeline_stage,'new','qualified','tasting','proposal','deposit','booked','completed','lost'), c.updated_at DESC LIMIT 300");$statement->execute($params);
        app_json_response(['ok'=>true,'stages'=>$stages,'leads'=>array_map('catering_public_row',$statement->fetchAll())]);
    }
    if ($action === 'detail') {
        $id=trim((string)($_GET['id']??''));$statement=$pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.organization_id=? AND c.public_id=? AND c.archived_at IS NULL LIMIT 1");$statement->execute([$organizationId,$id]);$row=$statement->fetch();if(!$row)app_json_response(['ok'=>false,'message'=>'Catering opportunity not found.'],404);
        $activities=$pdo->prepare("SELECT a.*,u.display_name AS createdByName FROM catering_lead_activities a LEFT JOIN users u ON u.id=a.created_by WHERE a.organization_id=? AND a.catering_lead_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 150");$activities->execute([$organizationId,(int)$row['id']]);
        app_json_response(['ok'=>true,'lead'=>catering_public_row($row),'activities'=>$activities->fetchAll()]);
    }
    if ($action === 'summary') {
        $users=$pdo->prepare("SELECT DISTINCT u.id,u.display_name FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name");$users->execute([$organizationId]);
        app_json_response(['ok'=>true,'summary'=>restaurant_brain_catering_summary($pdo,$organizationId),'users'=>$users->fetchAll(),'stages'=>$stages]);
    }
    if ($action === 'upcoming') {
        $days=max(1,min(365,(int)($_GET['days']??30)));$statement=$pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.organization_id=? AND c.archived_at IS NULL AND c.pipeline_stage IN ('qualified','tasting','proposal','deposit','booked') AND c.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL {$days} DAY) ORDER BY c.event_date,c.event_start_time LIMIT 100");$statement->execute([$organizationId]);app_json_response(['ok'=>true,'days'=>$days,'leads'=>array_map('catering_public_row',$statement->fetchAll())]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported catering action.'],422);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');$publicId=trim((string)($input['id']??''));$statement=$pdo->prepare('SELECT * FROM catering_leads WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$statement->execute([$organizationId,$publicId]);$lead=$statement->fetch();if(!$lead)app_json_response(['ok'=>false,'message'=>'Catering opportunity not found.'],404);$leadId=(int)$lead['id'];

if ($action === 'stage') {
    $stage=(string)($input['stage']??'');if(!in_array($stage,$stages,true))app_json_response(['ok'=>false,'message'=>'Invalid catering stage.'],422);
    $probMap=['new'=>10,'qualified'=>25,'tasting'=>40,'proposal'=>60,'deposit'=>80,'booked'=>100,'completed'=>100,'lost'=>0];$probability=$probMap[$stage];
    $update=$pdo->prepare("UPDATE catering_leads SET pipeline_stage=?,probability_percent=?,booked_at=IF(?='booked',COALESCE(booked_at,NOW(6)),booked_at),completed_at=IF(?='completed',COALESCE(completed_at,NOW(6)),completed_at),lost_at=IF(?='lost',COALESCE(lost_at,NOW(6)),NULL),updated_at=NOW(6) WHERE id=? AND organization_id=?");$update->execute([$stage,$probability,$stage,$stage,$stage,$leadId,$organizationId]);
    $activity=$pdo->prepare("INSERT INTO catering_lead_activities (organization_id,catering_lead_id,activity_type,summary,created_by) VALUES (?,?,'stage',?,?)");$activity->execute([$organizationId,$leadId,'Pipeline moved from '.$lead['pipeline_stage'].' to '.$stage,(int)$user['id']]);
    restaurant_brain_sync_catering($pdo,$organizationId,$leadId,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'catering.stage_changed','catering_lead',$publicId,['stage'=>$lead['pipeline_stage']],['stage'=>$stage]);app_json_response(['ok'=>true,'message'=>'Catering stage updated.']);
}

if ($action === 'update') {
    $estimatedValue=$input['estimatedValue']??null;if($estimatedValue!==null&&$estimatedValue!==''&&!is_numeric($estimatedValue))app_json_response(['ok'=>false,'message'=>'Estimated value must be numeric.'],422);
    $probability=max(0,min(100,(int)($input['probability']??$lead['probability_percent'])));$assignedTo=isset($input['assignedTo'])&&$input['assignedTo']!==''?(int)$input['assignedTo']:null;
    $nextFollowup=trim((string)($input['nextFollowupAt']??''));if($nextFollowup!==''){$dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$nextFollowup);if(!$dt)app_json_response(['ok'=>false,'message'=>'Follow-up date is invalid.'],422);$nextFollowup=$dt->format('Y-m-d H:i:s');}else $nextFollowup=null;
    $depositDue=trim((string)($input['depositDueAt']??''));if($depositDue!==''){$dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$depositDue);if(!$dt)app_json_response(['ok'=>false,'message'=>'Deposit due date is invalid.'],422);$depositDue=$dt->format('Y-m-d H:i:s');}else $depositDue=null;
    $eventDate=trim((string)($input['eventDate']??$lead['event_date']??''));if($eventDate!==''){$dt=DateTimeImmutable::createFromFormat('Y-m-d',$eventDate);if(!$dt||$dt->format('Y-m-d')!==$eventDate)app_json_response(['ok'=>false,'message'=>'Event date is invalid.'],422);}else $eventDate=null;
    $guestCount=isset($input['guestCount'])&&$input['guestCount']!==''?(int)$input['guestCount']:null;if($guestCount!==null&&($guestCount<1||$guestCount>100000))app_json_response(['ok'=>false,'message'=>'Guest count is invalid.'],422);
    $notes=mb_substr(trim((string)($input['notes']??$lead['notes']??'')),0,12000,'UTF-8');$lossReason=mb_substr(trim((string)($input['lossReason']??$lead['loss_reason']??'')),0,500,'UTF-8');
    if($assignedTo){$check=$pdo->prepare("SELECT COUNT(*) FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id WHERE u.id=? AND om.organization_id=? AND u.status='active' AND u.archived_at IS NULL");$check->execute([$assignedTo,$organizationId]);if(!(int)$check->fetchColumn())app_json_response(['ok'=>false,'message'=>'Assignee is not an active organization user.'],422);}
    $update=$pdo->prepare("UPDATE catering_leads SET estimated_value=?,probability_percent=?,assigned_to=?,next_followup_at=?,deposit_due_at=?,event_date=?,guest_count=?,notes=?,loss_reason=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");$update->execute([$estimatedValue===''?null:$estimatedValue,$probability,$assignedTo,$nextFollowup,$depositDue,$eventDate,$guestCount,$notes?:null,$lossReason?:null,$leadId,$organizationId]);
    restaurant_brain_sync_catering($pdo,$organizationId,$leadId,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'catering.updated','catering_lead',$publicId,null,['estimatedValue'=>$estimatedValue,'probability'=>$probability,'assignedTo'=>$assignedTo,'nextFollowupAt'=>$nextFollowup,'eventDate'=>$eventDate,'guestCount'=>$guestCount]);app_json_response(['ok'=>true,'message'=>'Catering opportunity updated.']);
}

if ($action === 'activity') {
    $type=trim((string)($input['activityType']??'note'));$allowed=['note','call','email','meeting','tasting','proposal','followup','deposit','site_visit'];if(!in_array($type,$allowed,true))$type='note';$summary=mb_substr(trim((string)($input['summary']??'')),0,500,'UTF-8');$details=mb_substr(trim((string)($input['details']??'')),0,12000,'UTF-8');if($summary==='')app_json_response(['ok'=>false,'message'=>'Enter an activity summary.'],422);
    $insert=$pdo->prepare("INSERT INTO catering_lead_activities (organization_id,catering_lead_id,activity_type,summary,details,created_by) VALUES (?,?,?,?,?,?)");$insert->execute([$organizationId,$leadId,$type,$summary,$details?:null,(int)$user['id']]);
    $extra=$type==='proposal'?',proposal_sent_at=COALESCE(proposal_sent_at,NOW(6))':'';$pdo->prepare("UPDATE catering_leads SET last_contact_at=NOW(6),updated_at=NOW(6){$extra} WHERE id=? AND organization_id=?")->execute([$leadId,$organizationId]);
    restaurant_brain_sync_catering($pdo,$organizationId,$leadId,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'catering.activity_added','catering_lead',$publicId,null,['activityType'=>$type,'summary'=>$summary]);app_json_response(['ok'=>true,'message'=>'Catering activity added.']);
}

if ($action === 'archive') {
    $pdo->prepare('UPDATE catering_leads SET archived_at=NOW(6),updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$leadId,$organizationId]);app_audit($pdo,$organizationId,(int)$user['id'],'catering.archived','catering_lead',$publicId);app_json_response(['ok'=>true,'message'=>'Catering opportunity archived.']);
}
app_json_response(['ok'=>false,'message'=>'Unsupported catering action.'],422);
