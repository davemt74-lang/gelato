<?php
declare(strict_types=1);

function floor_equipment_plan_row(PDO $pdo, int $organizationId, string $planPublicId): ?array
{
    $statement = $pdo->prepare(
        "SELECT public_id, name, width_ft, depth_ft, version, updated_at
         FROM floor_plans
         WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1"
    );
    $statement->execute([$organizationId, $planPublicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function floor_equipment_asset_placement(PDO $pdo, int $organizationId, string $assetPublicId): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, public_id, name, asset_type, brand, model, operational_status, criticality,
                location_name, floor_plan_public_id, floor_plan_component_id, floor_plan_x_ft, floor_plan_y_ft,
                floor_plan_rotation_deg, floor_plan_z_index, floor_plan_locked, width_inches, depth_inches,
                maintenance_required, next_service_on
         FROM equipment_assets
         WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1"
    );
    $statement->execute([$organizationId, $assetPublicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function floor_equipment_placement_text(PDO $pdo, int $organizationId, array $asset): string
{
    $planId = (string)($asset['floor_plan_public_id'] ?? '');
    $plan = $planId !== '' ? floor_equipment_plan_row($pdo, $organizationId, $planId) : null;
    $planName = $plan ? (string)$plan['name'] : ($planId ?: 'not placed');
    $width = $asset['width_inches'] !== null ? (float)$asset['width_inches'] : null;
    $depth = $asset['depth_inches'] !== null ? (float)$asset['depth_inches'] : null;
    $x = $asset['floor_plan_x_ft'] !== null ? (float)$asset['floor_plan_x_ft'] : null;
    $y = $asset['floor_plan_y_ft'] !== null ? (float)$asset['floor_plan_y_ft'] : null;
    return implode("\n", [
        'Floor-plan equipment placement: ' . $asset['name'],
        'Canonical equipment asset id: ' . $asset['public_id'],
        'Equipment type: ' . ($asset['asset_type'] ?: 'other'),
        'Brand/model: ' . trim(($asset['brand'] ?: '') . ' ' . ($asset['model'] ?: '')),
        'Operational status: ' . ($asset['operational_status'] ?: 'unknown'),
        'Criticality: ' . ($asset['criticality'] ?: 'medium'),
        'Operational location label: ' . ($asset['location_name'] ?: 'not recorded'),
        'Floor plan: ' . $planName . ($planId !== '' ? ' (' . $planId . ')' : ''),
        'Plan position: ' . ($x !== null ? number_format($x, 2) . ' ft from left' : 'x not recorded') . ', ' . ($y !== null ? number_format($y, 2) . ' ft from top' : 'y not recorded'),
        'Plan rotation: ' . number_format((float)($asset['floor_plan_rotation_deg'] ?? 0), 0) . ' degrees',
        'Position locked: ' . ((int)($asset['floor_plan_locked'] ?? 0) === 1 ? 'yes' : 'no'),
        'Equipment footprint: ' . ($width !== null ? number_format($width, 2) . ' in wide' : 'width not recorded') . ' x ' . ($depth !== null ? number_format($depth, 2) . ' in deep' : 'depth not recorded'),
        'Maintenance required: ' . ((int)($asset['maintenance_required'] ?? 0) === 1 ? 'yes' : 'no'),
        'Next service: ' . (($asset['next_service_on'] ?? '') ?: 'not scheduled'),
    ]);
}

function floor_equipment_brain_sync_placement(PDO $pdo, int $organizationId, string $assetPublicId, ?int $userId): void
{
    if (!equipment_brain_table_ready($pdo, 'agent_knowledge_records')) return;
    $asset = floor_equipment_asset_placement($pdo, $organizationId, $assetPublicId);
    if (!$asset) return;
    $planId = (string)($asset['floor_plan_public_id'] ?? '');
    if ($planId === '') {
        $statement = $pdo->prepare(
            "UPDATE agent_knowledge_records
             SET status='archived', version=version+1, updated_by=?, updated_at=NOW(6)
             WHERE organization_id=? AND source_type='floor_plan_placement' AND source_public_id=?"
        );
        $statement->execute([$userId, $organizationId, $assetPublicId]);
        return;
    }
    $content = floor_equipment_placement_text($pdo, $organizationId, $asset);
    $statement = $pdo->prepare(
        "INSERT INTO agent_knowledge_records
         (organization_id, public_id, source_type, source_public_id, title, content, content_sha256, visibility, status, version, updated_by)
         VALUES (?, ?, 'floor_plan_placement', ?, ?, ?, ?, 'internal', 'active', 1, ?)
         ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), content_sha256=VALUES(content_sha256),
           visibility='internal', status='active', version=version+1, updated_by=VALUES(updated_by), updated_at=NOW(6)"
    );
    $statement->execute([
        $organizationId,
        'placement-' . $assetPublicId,
        $assetPublicId,
        'Floor placement: ' . $asset['name'],
        $content,
        hash('sha256', $content),
        $userId,
    ]);
}

function floor_equipment_plan_assets(PDO $pdo, int $organizationId, string $planPublicId = ''): array
{
    $params = [$organizationId];
    $where = "organization_id=? AND archived_at IS NULL AND floor_plan_public_id IS NOT NULL AND floor_plan_public_id<>''";
    if ($planPublicId !== '') {
        $where .= ' AND floor_plan_public_id=?';
        $params[] = $planPublicId;
    }
    $statement = $pdo->prepare(
        "SELECT public_id, name, asset_type, purpose, brand, manufacturer, model, operational_status,
                condition_status, criticality, location_name, floor_plan_public_id, floor_plan_component_id,
                floor_plan_x_ft, floor_plan_y_ft, floor_plan_rotation_deg, floor_plan_z_index, floor_plan_locked,
                width_inches, depth_inches, maintenance_required, last_service_on, next_service_on
         FROM equipment_assets
         WHERE {$where}
         ORDER BY floor_plan_public_id, floor_plan_z_index, name LIMIT 500"
    );
    $statement->execute($params);
    return array_map(static fn(array $row): array => [
        'id'=>(string)$row['public_id'],
        'name'=>(string)$row['name'],
        'assetType'=>(string)$row['asset_type'],
        'purpose'=>(string)($row['purpose']??''),
        'brand'=>(string)($row['brand']??''),
        'manufacturer'=>(string)($row['manufacturer']??''),
        'model'=>(string)($row['model']??''),
        'operationalStatus'=>(string)$row['operational_status'],
        'conditionStatus'=>(string)$row['condition_status'],
        'criticality'=>(string)$row['criticality'],
        'locationName'=>(string)($row['location_name']??''),
        'floorPlanId'=>(string)$row['floor_plan_public_id'],
        'componentId'=>(string)($row['floor_plan_component_id']??''),
        'xFt'=>$row['floor_plan_x_ft']!==null?(float)$row['floor_plan_x_ft']:null,
        'yFt'=>$row['floor_plan_y_ft']!==null?(float)$row['floor_plan_y_ft']:null,
        'rotationDeg'=>(float)($row['floor_plan_rotation_deg']??0),
        'zIndex'=>(int)($row['floor_plan_z_index']??1),
        'locked'=>(bool)($row['floor_plan_locked']??0),
        'widthInches'=>$row['width_inches']!==null?(float)$row['width_inches']:null,
        'depthInches'=>$row['depth_inches']!==null?(float)$row['depth_inches']:null,
        'maintenanceRequired'=>(bool)$row['maintenance_required'],
        'lastServiceOn'=>$row['last_service_on'],
        'nextServiceOn'=>$row['next_service_on'],
    ], $statement->fetchAll());
}
