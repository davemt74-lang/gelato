<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';

function catering_brain_ready(PDO $pdo): bool
{
    return restaurant_brain_table_ready($pdo, 'catering_leads');
}

function catering_brain_row(PDO $pdo, int $organizationId, int $leadId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM catering_leads WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$leadId, $organizationId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function catering_brain_activities(PDO $pdo, int $organizationId, int $leadId, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $statement = $pdo->prepare("SELECT a.*,u.display_name AS created_by_name FROM catering_lead_activities a LEFT JOIN users u ON u.id=a.created_by WHERE a.organization_id=? AND a.catering_lead_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}");
    $statement->execute([$organizationId, $leadId]);
    return $statement->fetchAll();
}

function catering_brain_text(PDO $pdo, int $organizationId, array $lead): string
{
    $lines = [
        'Catering opportunity: ' . (($lead['company_name'] ?? '') ?: $lead['contact_name']),
        'Pipeline stage: ' . $lead['pipeline_stage'],
        'Contact: ' . $lead['contact_name'] . ' / ' . $lead['email'] . (($lead['phone'] ?? '') ? ' / ' . $lead['phone'] : ''),
        'Event type: ' . (($lead['event_type'] ?? '') ?: 'not recorded'),
        'Event date: ' . (($lead['event_date'] ?? '') ?: 'not recorded'),
        'Event time: ' . (($lead['start_time'] ?? '') ?: 'not recorded') . (($lead['end_time'] ?? '') ? ' - ' . $lead['end_time'] : ''),
        'Guest count: ' . (($lead['guest_count'] ?? null) !== null ? (int)$lead['guest_count'] : 'not recorded'),
        'Venue: ' . (($lead['venue_name'] ?? '') ?: 'not recorded'),
        'Venue address: ' . (($lead['venue_address'] ?? '') ?: 'not recorded'),
        'Service style: ' . (($lead['service_style'] ?? '') ?: 'not recorded'),
        'Menu interests: ' . (($lead['menu_interests'] ?? '') ?: 'not recorded'),
        'Dietary requirements: ' . (($lead['dietary_requirements'] ?? '') ?: 'none recorded'),
        'Beverage service: ' . (($lead['beverage_service'] ?? '') ?: 'not recorded'),
        'Staffing needs: ' . (($lead['staffing_needs'] ?? '') ?: 'not recorded'),
        'Rentals/equipment: ' . (($lead['rentals_needs'] ?? '') ?: 'not recorded'),
        'Budget: ' . (($lead['budget_range'] ?? '') ?: 'not recorded'),
        'Fulfillment: ' . (($lead['fulfillment_preference'] ?? '') ?: 'not recorded'),
        'Estimated value: ' . ($lead['estimated_value'] !== null ? '$' . number_format((float)$lead['estimated_value'], 2) : 'not recorded'),
        'Probability: ' . (int)($lead['probability_percent'] ?? 0) . '%',
        'Next follow-up: ' . (($lead['next_followup_at'] ?? '') ?: 'not scheduled'),
        'Notes: ' . (($lead['notes'] ?? '') ?: 'none recorded'),
    ];
    $activities = catering_brain_activities($pdo, $organizationId, (int)$lead['id'], 8);
    if ($activities) {
        $lines[] = 'Recent catering activity:';
        foreach ($activities as $activity) {
            $lines[] = '- ' . $activity['created_at'] . ' ' . $activity['activity_type'] . ': ' . $activity['summary'] . (($activity['details'] ?? '') ? ' — ' . $activity['details'] : '');
        }
    }
    return implode("\n", $lines);
}

function catering_brain_sync(PDO $pdo, int $organizationId, int $leadId, ?int $userId): void
{
    if (!catering_brain_ready($pdo)) return;
    $lead = catering_brain_row($pdo, $organizationId, $leadId);
    if (!$lead) return;
    $title = 'Catering: ' . (($lead['company_name'] ?? '') ?: $lead['contact_name']) . ' — ' . ($lead['event_type'] ?? 'Event');
    restaurant_brain_write_knowledge($pdo, $organizationId, 'catering_lead', (string)$lead['public_id'], $title, catering_brain_text($pdo, $organizationId, $lead), $userId);
}

function catering_brain_search(PDO $pdo, int $organizationId, string $query = '', int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $query = trim($query);
    $like = '%' . $query . '%';
    $statement = $pdo->prepare("SELECT c.*,u.display_name AS assigned_name FROM catering_leads c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.organization_id=? AND c.archived_at IS NULL AND (?='' OR c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.event_type LIKE ? OR c.venue_name LIKE ? OR c.menu_interests LIKE ? OR c.dietary_requirements LIKE ? OR c.notes LIKE ?) ORDER BY CASE c.pipeline_stage WHEN 'new' THEN 1 WHEN 'qualified' THEN 2 WHEN 'menu_proposal' THEN 3 WHEN 'tasting' THEN 4 WHEN 'quoted' THEN 5 WHEN 'contracted' THEN 6 WHEN 'deposit_paid' THEN 7 WHEN 'confirmed' THEN 8 WHEN 'completed' THEN 9 WHEN 'lost' THEN 10 ELSE 11 END,c.event_date IS NULL,c.event_date,c.updated_at DESC LIMIT {$limit}");
    $statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like,$like,$like]);
    return $statement->fetchAll();
}

function catering_brain_summary(PDO $pdo, int $organizationId): array
{
    if (!catering_brain_ready($pdo)) return [];
    $statement = $pdo->prepare("SELECT pipeline_stage,COUNT(*) AS lead_count,COALESCE(SUM(estimated_value),0) AS value_total,COALESCE(SUM(estimated_value * probability_percent / 100),0) AS weighted_value FROM catering_leads WHERE organization_id=? AND archived_at IS NULL GROUP BY pipeline_stage");
    $statement->execute([$organizationId]);
    return $statement->fetchAll();
}

function catering_brain_upcoming(PDO $pdo, int $organizationId, int $days = 30): array
{
    $days = max(1, min(365, $days));
    $statement = $pdo->prepare("SELECT public_id,company_name,contact_name,event_type,event_date,start_time,guest_count,venue_name,pipeline_stage,estimated_value FROM catering_leads WHERE organization_id=? AND archived_at IS NULL AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL {$days} DAY) AND pipeline_stage NOT IN ('lost','completed') ORDER BY event_date,start_time LIMIT 50");
    $statement->execute([$organizationId]);
    return $statement->fetchAll();
}

function catering_brain_answer(PDO $pdo, int $organizationId, string $message): array
{
    $normalized = mb_strtolower(trim($message), 'UTF-8');
    if (preg_match('/\b(upcoming|next|calendar|events|this week|this month)\b/u', $normalized)) {
        $days = preg_match('/\b(\d{1,3})\s*days?\b/u', $normalized, $m) ? max(1, min(365, (int)$m[1])) : 30;
        $rows = catering_brain_upcoming($pdo, $organizationId, $days);
        if (!$rows) return ['skill'=>'catering.upcoming','answer'=>"No active catering events are scheduled in the next {$days} days.",'data'=>[],'sources'=>[]];
        $lines=[];foreach($rows as $row)$lines[]=$row['event_date'].' — '.$row['event_type'].' — '.(($row['company_name']??'')?:$row['contact_name']).' — '.($row['guest_count'] ? $row['guest_count'].' guests' : 'guest count TBD').' — '.$row['pipeline_stage'];
        return ['skill'=>'catering.upcoming','answer'=>"Upcoming catering opportunities/events:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    if (preg_match('/\b(pipeline|forecast|weighted|summary|opportunities|stages)\b/u', $normalized)) {
        $summary=catering_brain_summary($pdo,$organizationId);
        if(!$summary)return ['skill'=>'catering.pipeline','answer'=>'There are no catering opportunities in the pipeline yet.','data'=>[],'sources'=>[]];
        $lines=[];$open=0;$weighted=0;foreach($summary as $row){$lines[]=ucwords(str_replace('_',' ',(string)$row['pipeline_stage'])).': '.(int)$row['lead_count'].' — $'.number_format((float)$row['value_total'],0);if(!in_array($row['pipeline_stage'],['lost','completed'],true)){$open+=(float)$row['value_total'];$weighted+=(float)$row['weighted_value'];}}
        return ['skill'=>'catering.pipeline','answer'=>"Catering pipeline:\n- ".implode("\n- ",$lines).'\nOpen value: $'.number_format($open,0).'; weighted value: $'.number_format($weighted,0).'.','data'=>$summary,'sources'=>[]];
    }
    if (preg_match('/\b(follow.?up|overdue|due today|next contact)\b/u', $normalized)) {
        $statement=$pdo->prepare("SELECT public_id,company_name,contact_name,event_type,event_date,pipeline_stage,next_followup_at FROM catering_leads WHERE organization_id=? AND archived_at IS NULL AND pipeline_stage NOT IN ('lost','completed') AND next_followup_at IS NOT NULL AND next_followup_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) ORDER BY next_followup_at LIMIT 25");
        $statement->execute([$organizationId]);$rows=$statement->fetchAll();
        if(!$rows)return ['skill'=>'catering.followups','answer'=>'No catering follow-ups are due in the next seven days.','data'=>[],'sources'=>[]];
        $lines=[];foreach($rows as $row)$lines[]=(($row['company_name']??'')?:$row['contact_name']).' — '.$row['event_type'].' — follow up '.$row['next_followup_at'];
        return ['skill'=>'catering.followups','answer'=>"Catering follow-ups due soon:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    $rows=catering_brain_search($pdo,$organizationId,$message,12);
    if($rows){if(count($rows)===1)return ['skill'=>'catering.context','answer'=>catering_brain_text($pdo,$organizationId,$rows[0]),'data'=>$rows[0],'sources'=>[(string)$rows[0]['public_id']]];$lines=[];foreach($rows as $row)$lines[]=(($row['company_name']??'')?:$row['contact_name']).' — '.$row['event_type'].' — '.($row['event_date']?:$row['event_date']:'date TBD').' — '.$row['pipeline_stage'];return ['skill'=>'catering.search','answer'=>"Matching catering opportunities:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];}
    return ['skill'=>'catering.search','answer'=>'I could not find a matching catering opportunity. New public catering inquiries appear in the catering pipeline automatically.','data'=>[],'sources'=>[]];
}
