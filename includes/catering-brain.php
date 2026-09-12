<?php
declare(strict_types=1);

function catering_brain_row(PDO $pdo,int $organizationId,int $leadId):?array
{
    $statement=$pdo->prepare('SELECT * FROM catering_leads WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$leadId,$organizationId]);$row=$statement->fetch();return $row?:null;
}

function catering_brain_activities(PDO $pdo,int $organizationId,int $leadId,int $limit=12):array
{
    $limit=max(1,min(50,$limit));
    $statement=$pdo->prepare("SELECT a.*,u.display_name AS created_by_name FROM catering_lead_activities a LEFT JOIN users u ON u.id=a.created_by WHERE a.organization_id=? AND a.catering_lead_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}");
    $statement->execute([$organizationId,$leadId]);return $statement->fetchAll();
}

function catering_brain_text(PDO $pdo,int $organizationId,array $lead):string
{
    $menu=json_decode((string)($lead['menu_interests_json']??'[]'),true)?:[];
    $lines=[
        'Catering opportunity: '.(($lead['event_name']??'')?:($lead['company_name']??'')?:$lead['contact_name']),
        'Pipeline stage: '.$lead['pipeline_stage'],
        'Contact: '.$lead['contact_name'].' / '.$lead['email'].(($lead['phone']??'')?' / '.$lead['phone']:''),
        'Company: '.(($lead['company_name']??'')?:'not recorded'),
        'Event type: '.(($lead['event_type']??'')?:'not recorded'),
        'Event date: '.(($lead['event_date']??'')?:'not recorded'),
        'Event time: '.(($lead['event_start_time']??'')?:'not recorded').(($lead['event_end_time']??'')?' to '.$lead['event_end_time']:''),
        'Guest count: '.(($lead['guest_count']??'')?:'not recorded'),
        'Venue: '.(($lead['venue_name']??'')?:'not recorded'),
        'Venue address: '.(($lead['venue_address']??'')?:'not recorded'),
        'Service style: '.(($lead['service_style']??'')?:'not recorded'),
        'Menu interests: '.($menu?implode(', ',array_map('strval',$menu)):'not recorded'),
        'Beverage interest: '.(($lead['beverage_interest']??'')?:'not recorded'),
        'Dietary/allergy needs: '.(($lead['dietary_needs']??'')?:'none recorded'),
        'Staffing needs: '.(($lead['staffing_needs']??'')?:'not recorded'),
        'Rental/equipment needs: '.(($lead['rental_needs']??'')?:'not recorded'),
        'Setup notes: '.(($lead['setup_notes']??'')?:'none recorded'),
        'Fulfillment: '.(($lead['fulfillment_preference']??'')?:'not recorded'),
        'Budget range: '.(($lead['budget_range']??'')?:'not recorded'),
        'Estimated value: '.($lead['estimated_value']!==null?'$'.number_format((float)$lead['estimated_value'],2):'not recorded'),
        'Probability: '.(int)($lead['probability_percent']??0).'%',
        'Next follow-up: '.(($lead['next_followup_at']??'')?:'not scheduled'),
        'Deposit due: '.(($lead['deposit_due_at']??'')?:'not scheduled'),
        'Notes: '.(($lead['notes']??'')?:'none recorded')
    ];
    $activities=catering_brain_activities($pdo,$organizationId,(int)$lead['id'],10);
    if($activities){$lines[]='Recent catering activity:';foreach($activities as $activity)$lines[]='- '.$activity['created_at'].' '.$activity['activity_type'].': '.$activity['summary'].(($activity['details']??'')?' — '.$activity['details']:'');}
    return implode("\n",$lines);
}

function restaurant_brain_sync_catering(PDO $pdo,int $organizationId,int $leadId,?int $userId):void
{
    if(!restaurant_brain_table_ready($pdo,'catering_leads'))return;
    $lead=catering_brain_row($pdo,$organizationId,$leadId);if(!$lead)return;
    restaurant_brain_write_knowledge($pdo,$organizationId,'catering_lead',(string)$lead['public_id'],'Catering: '.(($lead['event_name']??'')?:($lead['company_name']??'')?:$lead['contact_name']),catering_brain_text($pdo,$organizationId,$lead),$userId);
}

function restaurant_brain_catering_search(PDO $pdo,int $organizationId,string $query='',int $limit=20):array
{
    if(!restaurant_brain_table_ready($pdo,'catering_leads'))return [];
    $limit=max(1,min(50,$limit));$query=trim($query);$like='%'.$query.'%';
    $statement=$pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.organization_id=? AND c.archived_at IS NULL AND (?='' OR c.contact_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.event_name LIKE ? OR c.event_type LIKE ? OR c.venue_name LIKE ? OR c.venue_address LIKE ? OR c.dietary_needs LIKE ? OR c.notes LIKE ?) ORDER BY COALESCE(c.event_date,'9999-12-31'), FIELD(c.pipeline_stage,'new','qualified','tasting','proposal','deposit','booked','completed','lost'),c.updated_at DESC LIMIT {$limit}");
    $statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like,$like,$like,$like]);return $statement->fetchAll();
}

function restaurant_brain_catering_summary(PDO $pdo,int $organizationId):array
{
    if(!restaurant_brain_table_ready($pdo,'catering_leads'))return [];
    $statement=$pdo->prepare("SELECT pipeline_stage,COUNT(*) AS lead_count,COALESCE(SUM(estimated_value),0) AS value_total,COALESCE(SUM(estimated_value * probability_percent / 100),0) AS weighted_value FROM catering_leads WHERE organization_id=? AND archived_at IS NULL GROUP BY pipeline_stage");$statement->execute([$organizationId]);return $statement->fetchAll();
}

function restaurant_brain_catering_upcoming(PDO $pdo,int $organizationId,int $days=30):array
{
    if(!restaurant_brain_table_ready($pdo,'catering_leads'))return [];
    $days=max(1,min(365,$days));
    $statement=$pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.organization_id=? AND c.archived_at IS NULL AND c.pipeline_stage IN ('qualified','tasting','proposal','deposit','booked') AND c.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL {$days} DAY) ORDER BY c.event_date,c.event_start_time LIMIT 100");$statement->execute([$organizationId]);return $statement->fetchAll();
}
