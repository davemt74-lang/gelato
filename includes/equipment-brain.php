<?php
declare(strict_types=1);

function equipment_brain_table_ready(PDO $pdo, string $table): bool
{
    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function equipment_brain_uuid(): string
{
    return 'agent-' . bin2hex(random_bytes(12));
}

function equipment_brain_asset_row(PDO $pdo, int $organizationId, int $assetId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM equipment_assets WHERE id = ? AND organization_id = ? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$assetId, $organizationId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function equipment_brain_asset_contacts(PDO $pdo, int $organizationId, int $assetId): array
{
    $statement = $pdo->prepare(
        "SELECT c.*, link.contact_role, link.is_primary
         FROM equipment_asset_service_contacts link
         INNER JOIN equipment_service_contacts c ON c.id = link.service_contact_id
         WHERE link.equipment_asset_id = ? AND c.organization_id = ? AND c.archived_at IS NULL AND c.status = 'active'
         ORDER BY link.is_primary DESC, c.is_preferred DESC, c.company_name"
    );
    $statement->execute([$assetId, $organizationId]);
    return $statement->fetchAll();
}

function equipment_brain_asset_events(PDO $pdo, int $organizationId, int $assetId, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $statement = $pdo->prepare(
        "SELECT e.*, c.company_name AS service_company
         FROM equipment_service_events e
         LEFT JOIN equipment_service_contacts c ON c.id = e.service_contact_id
         WHERE e.equipment_asset_id = ? AND e.organization_id = ?
         ORDER BY e.serviced_on DESC, e.id DESC LIMIT {$limit}"
    );
    $statement->execute([$assetId, $organizationId]);
    return $statement->fetchAll();
}

function equipment_brain_age_text(array $asset): string
{
    $year = (int)($asset['manufacture_year'] ?? 0);
    if ($year > 1900 && $year <= (int)date('Y')) {
        $years = max(0, (int)date('Y') - $year);
        return $years . ' year' . ($years === 1 ? '' : 's') . ' old (manufactured ' . $year . ')';
    }
    if (!empty($asset['install_date'])) {
        try {
            $installed = new DateTimeImmutable((string)$asset['install_date']);
            $years = (int)$installed->diff(new DateTimeImmutable('today'))->y;
            return $years . ' year' . ($years === 1 ? '' : 's') . ' since installation';
        } catch (Throwable) {
        }
    }
    return 'age not recorded';
}

function equipment_brain_asset_text(PDO $pdo, int $organizationId, array $asset): string
{
    $contacts = equipment_brain_asset_contacts($pdo, $organizationId, (int)$asset['id']);
    $events = equipment_brain_asset_events($pdo, $organizationId, (int)$asset['id'], 8);
    $lines = [
        'Equipment asset: ' . $asset['name'],
        'Type: ' . ($asset['asset_type'] ?: 'other'),
        'Purpose: ' . ($asset['purpose'] ?: 'not recorded'),
        'Brand: ' . ($asset['brand'] ?: 'not recorded'),
        'Manufacturer: ' . ($asset['manufacturer'] ?: 'not recorded'),
        'Model: ' . ($asset['model'] ?: 'not recorded'),
        'Serial number: ' . ($asset['serial_number'] ?: 'not recorded'),
        'Asset tag: ' . ($asset['asset_tag'] ?: 'not recorded'),
        'Age: ' . equipment_brain_age_text($asset),
        'Operational status: ' . ($asset['operational_status'] ?: 'unknown'),
        'Condition: ' . ($asset['condition_status'] ?: 'unknown'),
        'Criticality: ' . ($asset['criticality'] ?: 'medium'),
        'Location: ' . ($asset['location_name'] ?: 'not recorded'),
        'Floor plan link: ' . (($asset['floor_plan_public_id'] ?? '') ?: 'not linked') . (($asset['floor_plan_component_id'] ?? '') ? ' / component ' . $asset['floor_plan_component_id'] : ''),
        'Dimensions: ' . (($asset['width_inches'] ?? '') !== '' ? $asset['width_inches'] . 'in W' : 'W not recorded') . ' × ' . (($asset['depth_inches'] ?? '') !== '' ? $asset['depth_inches'] . 'in D' : 'D not recorded') . ' × ' . (($asset['height_inches'] ?? '') !== '' ? $asset['height_inches'] . 'in H' : 'H not recorded'),
        'Capacity: ' . ($asset['capacity_text'] ?: 'not recorded'),
        'Utilities: ' . ($asset['utility_type'] ?: 'not recorded'),
        'Electrical: voltage ' . ($asset['voltage'] ?: 'n/a') . ', phase ' . ($asset['phase'] ?: 'n/a') . ', amperage ' . ($asset['amperage'] ?: 'n/a'),
        'Heat rating: ' . ($asset['btu_rating'] ?: 'n/a'),
        'Water requirement: ' . ($asset['water_requirement'] ?: 'n/a'),
        'Drain requirement: ' . ($asset['drain_requirement'] ?: 'n/a'),
        'Ventilation requirement: ' . ($asset['ventilation_requirement'] ?: 'n/a'),
        'Maintenance required: ' . ((int)$asset['maintenance_required'] === 1 ? 'yes' : 'no'),
        'Maintenance interval: ' . ($asset['maintenance_interval_days'] ? $asset['maintenance_interval_days'] . ' days' : 'not recorded'),
        'Last service: ' . ($asset['last_service_on'] ?: 'not recorded'),
        'Next service: ' . ($asset['next_service_on'] ?: 'not scheduled'),
        'Warranty expires: ' . ($asset['warranty_expires_on'] ?: 'not recorded'),
        'Expected life: ' . ($asset['expected_life_years'] ? $asset['expected_life_years'] . ' years' : 'not recorded'),
        'Purchase price: ' . ($asset['purchase_price'] !== null ? '$' . number_format((float)$asset['purchase_price'], 2) : 'not recorded'),
        'Replacement cost: ' . ($asset['replacement_cost'] !== null ? '$' . number_format((float)$asset['replacement_cost'], 2) : 'not recorded'),
        'Cleaning notes: ' . ($asset['cleaning_notes'] ?: 'none recorded'),
        'Maintenance notes: ' . ($asset['maintenance_notes'] ?: 'none recorded'),
        'Operating notes: ' . ($asset['operating_notes'] ?: 'none recorded'),
        'Safety notes: ' . ($asset['safety_notes'] ?: 'none recorded'),
        'Parts/consumables: ' . ($asset['parts_consumables'] ?: 'none recorded'),
        'Manual URL: ' . ($asset['manual_url'] ?: 'not recorded'),
        'General notes: ' . ($asset['notes'] ?: 'none recorded'),
    ];
    if ($contacts) {
        $lines[] = 'Service contacts:';
        foreach ($contacts as $contact) {
            $lines[] = '- ' . $contact['company_name'] . ($contact['contact_name'] ? ' / ' . $contact['contact_name'] : '') . '; role ' . $contact['contact_role'] . '; specialty ' . ($contact['specialty'] ?: 'not specified') . '; phone ' . ($contact['phone'] ?: 'not recorded') . '; emergency ' . ($contact['emergency_phone'] ?: 'not recorded') . '; email ' . ($contact['email'] ?: 'not recorded') . '; preferred ' . ((int)$contact['is_preferred'] === 1 ? 'yes' : 'no') . '; warranty provider ' . ((int)$contact['is_warranty_provider'] === 1 ? 'yes' : 'no') . '.';
        }
    }
    if ($events) {
        $lines[] = 'Recent service history:';
        foreach ($events as $event) {
            $lines[] = '- ' . $event['serviced_on'] . ' ' . $event['event_type'] . ': ' . $event['description'] . ($event['service_company'] ? ' (' . $event['service_company'] . ')' : '') . ($event['cost'] !== null ? '; cost $' . number_format((float)$event['cost'], 2) : '') . ($event['next_due_on'] ? '; next due ' . $event['next_due_on'] : '') . '.';
        }
    }
    return implode("\n", $lines);
}

function equipment_brain_sync_asset(PDO $pdo, int $organizationId, int $assetId, ?int $userId): void
{
    if (!equipment_brain_table_ready($pdo, 'agent_knowledge_records')) {
        return;
    }
    $asset = equipment_brain_asset_row($pdo, $organizationId, $assetId);
    if (!$asset) {
        return;
    }
    $content = equipment_brain_asset_text($pdo, $organizationId, $asset);
    $publicId = 'equipment-' . $asset['public_id'];
    $statement = $pdo->prepare(
        "INSERT INTO agent_knowledge_records
         (organization_id, public_id, source_type, source_public_id, title, content, content_sha256, visibility, status, version, updated_by)
         VALUES (?, ?, 'equipment_asset', ?, ?, ?, ?, 'internal', 'active', 1, ?)
         ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), content_sha256=VALUES(content_sha256),
           visibility='internal', status='active', version=version+1, updated_by=VALUES(updated_by), updated_at=NOW(6)"
    );
    $statement->execute([
        $organizationId,
        $publicId,
        $asset['public_id'],
        'Equipment: ' . $asset['name'],
        $content,
        hash('sha256', $content),
        $userId,
    ]);
}

function equipment_brain_archive_asset(PDO $pdo, int $organizationId, string $assetPublicId, ?int $userId): void
{
    if (!equipment_brain_table_ready($pdo, 'agent_knowledge_records')) {
        return;
    }
    $statement = $pdo->prepare("UPDATE agent_knowledge_records SET status='archived', version=version+1, updated_by=?, updated_at=NOW(6) WHERE organization_id=? AND source_type='equipment_asset' AND source_public_id=?");
    $statement->execute([$userId, $organizationId, $assetPublicId]);
}

function equipment_brain_search(PDO $pdo, int $organizationId, string $query, int $limit = 8): array
{
    $limit = max(1, min(20, $limit));
    $query = trim($query);
    if ($query === '' || !equipment_brain_table_ready($pdo, 'agent_knowledge_records')) {
        return [];
    }
    $rows = [];
    try {
        $statement = $pdo->prepare(
            "SELECT public_id, source_type, source_public_id, title, content,
                    MATCH(title, content) AGAINST (:query IN NATURAL LANGUAGE MODE) AS relevance
             FROM agent_knowledge_records
             WHERE organization_id=:organization_id AND visibility='internal' AND status='active'
             ORDER BY relevance DESC, updated_at DESC LIMIT {$limit}"
        );
        $statement->execute(['query' => $query, 'organization_id' => $organizationId]);
        foreach ($statement->fetchAll() as $row) {
            if ((float)($row['relevance'] ?? 0) > 0) {
                $rows[] = $row;
            }
        }
    } catch (Throwable) {
        $rows = [];
    }
    if ($rows) {
        return $rows;
    }
    $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query, 'UTF-8')) ?: [], static fn(string $token): bool => mb_strlen($token, 'UTF-8') >= 3));
    $statement = $pdo->prepare("SELECT public_id, source_type, source_public_id, title, content FROM agent_knowledge_records WHERE organization_id=? AND visibility='internal' AND status='active' ORDER BY updated_at DESC LIMIT 200");
    $statement->execute([$organizationId]);
    $ranked = [];
    foreach ($statement->fetchAll() as $row) {
        $haystack = mb_strtolower($row['title'] . "\n" . $row['content'], 'UTF-8');
        $score = 0;
        foreach ($tokens as $token) {
            $score += substr_count($haystack, $token);
        }
        if ($score > 0) {
            $row['relevance'] = $score;
            $ranked[] = $row;
        }
    }
    usort($ranked, static fn(array $a, array $b): int => ($b['relevance'] <=> $a['relevance']));
    return array_slice($ranked, 0, $limit);
}

function equipment_brain_maintenance_due(PDO $pdo, int $organizationId, int $days = 30): array
{
    $days = max(0, min(365, $days));
    $statement = $pdo->prepare(
        "SELECT public_id, name, asset_type, brand, model, operational_status, criticality, next_service_on, last_service_on, location_name
         FROM equipment_assets
         WHERE organization_id=? AND archived_at IS NULL AND maintenance_required=1
           AND next_service_on IS NOT NULL AND next_service_on <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
         ORDER BY next_service_on ASC, FIELD(criticality,'critical','high','medium','low'), name"
    );
    $statement->execute([$organizationId]);
    return $statement->fetchAll();
}

function equipment_brain_summary(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) total,
                SUM(operational_status='out_of_service') out_of_service,
                SUM(maintenance_required=1 AND next_service_on IS NOT NULL AND next_service_on < CURDATE()) overdue,
                SUM(maintenance_required=1 AND next_service_on IS NOT NULL AND next_service_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) due_30,
                SUM(criticality='critical') critical_assets
         FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL"
    );
    $statement->execute([$organizationId]);
    $row = $statement->fetch() ?: [];
    $contactStatement = $pdo->prepare("SELECT COUNT(*) FROM equipment_service_contacts WHERE organization_id=? AND archived_at IS NULL AND status='active'");
    $contactStatement->execute([$organizationId]);
    return [
        'assets' => (int)($row['total'] ?? 0),
        'outOfService' => (int)($row['out_of_service'] ?? 0),
        'overdueMaintenance' => (int)($row['overdue'] ?? 0),
        'dueWithin30Days' => (int)($row['due_30'] ?? 0),
        'criticalAssets' => (int)($row['critical_assets'] ?? 0),
        'serviceContacts' => (int)$contactStatement->fetchColumn(),
    ];
}
