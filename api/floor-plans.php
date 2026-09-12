<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$userId = (int)$user['id'];

function floor_plan_table_ready(PDO $pdo): bool
{
    try {
        $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'floor_plans'");
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function floor_plan_equipment_ready(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'equipment_assets'
               AND column_name IN ('floor_plan_public_id','floor_plan_x_ft','floor_plan_y_ft','floor_plan_rotation_deg')"
        );
        $statement->execute();
        return (int)$statement->fetchColumn() === 4;
    } catch (Throwable) {
        return false;
    }
}

function floor_plan_summary(array $row): array
{
    return [
        'id' => (string)$row['public_id'],
        'name' => (string)$row['name'],
        'widthFt' => (float)$row['width_ft'],
        'depthFt' => (float)$row['depth_ft'],
        'scale' => (float)$row['scale_px_per_ft'],
        'version' => (int)$row['version'],
        'isDefault' => (bool)$row['is_default'],
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function floor_plan_payload(array $row): array
{
    $payload = floor_plan_summary($row);
    $decoded = json_decode((string)$row['plan_json'], true);
    $payload['data'] = is_array($decoded) ? $decoded : [];
    return $payload;
}

function floor_plan_linked_equipment(PDO $pdo, int $organizationId, string $publicId): array
{
    if (!floor_plan_equipment_ready($pdo)) return [];
    $statement = $pdo->prepare(
        "SELECT public_id, name, asset_type, width_inches, depth_inches, floor_plan_x_ft, floor_plan_y_ft, floor_plan_rotation_deg
         FROM equipment_assets
         WHERE organization_id=? AND floor_plan_public_id=? AND archived_at IS NULL AND operational_status<>'retired'
         ORDER BY name"
    );
    $statement->execute([$organizationId, $publicId]);
    return $statement->fetchAll();
}

function floor_plan_equipment_default_footprint(string $assetType): array
{
    return match ($assetType) {
        'oven' => [60.0, 48.0],
        'mixer' => [30.0, 36.0],
        'refrigeration' => [72.0, 34.0],
        'gelato_machine' => [36.0, 30.0],
        'dishwasher' => [30.0, 30.0],
        'sink' => [36.0, 24.0],
        'utensil', 'smallware' => [18.0, 18.0],
        'bar_equipment' => [36.0, 24.0],
        'pos' => [24.0, 18.0],
        default => [36.0, 30.0],
    };
}

function floor_plan_equipment_bounds_conflicts(PDO $pdo, int $organizationId, string $publicId, float $widthFt, float $depthFt): array
{
    $conflicts = [];
    foreach (floor_plan_linked_equipment($pdo, $organizationId, $publicId) as $asset) {
        if ($asset['floor_plan_x_ft'] === null || $asset['floor_plan_y_ft'] === null) continue;
        $x = (float)$asset['floor_plan_x_ft'];
        $y = (float)$asset['floor_plan_y_ft'];
        [$defaultWidthInches, $defaultDepthInches] = floor_plan_equipment_default_footprint((string)($asset['asset_type'] ?? 'other'));
        $equipmentWidthFt = ((float)($asset['width_inches'] ?? $defaultWidthInches)) / 12.0;
        $equipmentDepthFt = ((float)($asset['depth_inches'] ?? $defaultDepthInches)) / 12.0;
        $angle = deg2rad(fmod((float)($asset['floor_plan_rotation_deg'] ?? 0), 360.0));
        $boundWidthFt = abs($equipmentWidthFt * cos($angle)) + abs($equipmentDepthFt * sin($angle));
        $boundDepthFt = abs($equipmentWidthFt * sin($angle)) + abs($equipmentDepthFt * cos($angle));
        $outside = $x < 0 || $y < 0
            || $x + $boundWidthFt > $widthFt + 0.001
            || $y + $boundDepthFt > $depthFt + 0.001;
        if ($outside) {
            $conflicts[] = ['id'=>(string)$asset['public_id'],'name'=>(string)$asset['name']];
        }
    }
    return $conflicts;
}

if (!floor_plan_table_ready($pdo)) {
    app_json_response([
        'ok' => false,
        'message' => 'Floor Planner database migration is not installed. Import database/20260912_floor_planner.sql first.',
    ], 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!app_has_permission('floorplans.view', $user)) {
        app_json_response(['ok' => false, 'message' => 'You do not have permission to view floor plans.'], 403);
    }
    $publicId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? ''));
    if ($publicId !== '') {
        $statement = $pdo->prepare(
            'SELECT * FROM floor_plans WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1'
        );
        $statement->execute([$organizationId, $publicId]);
        $row = $statement->fetch();
        if (!$row) {
            app_json_response(['ok' => false, 'message' => 'Floor plan not found.'], 404);
        }
        app_json_response(['ok' => true, 'plan' => floor_plan_payload($row)]);
    }

    $statement = $pdo->prepare(
        'SELECT public_id, name, width_ft, depth_ft, scale_px_per_ft, version, is_default, created_at, updated_at
         FROM floor_plans
         WHERE organization_id = ? AND archived_at IS NULL
         ORDER BY is_default DESC, updated_at DESC, id DESC'
    );
    $statement->execute([$organizationId]);
    app_json_response(['ok' => true, 'plans' => array_map('floor_plan_summary', $statement->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

if (!app_has_permission('floorplans.edit', $user)) {
    app_json_response(['ok' => false, 'message' => 'You do not have permission to edit floor plans.'], 403);
}

$input = app_json_input();
app_verify_request_csrf($input);
$action = (string)($input['action'] ?? 'save');

if ($action === 'archive') {
    $publicId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['id'] ?? ''));
    if ($publicId === '') {
        app_json_response(['ok' => false, 'message' => 'A floor plan id is required.'], 422);
    }
    $linked = floor_plan_linked_equipment($pdo, $organizationId, $publicId);
    if ($linked) {
        $names = array_slice(array_column($linked, 'name'), 0, 5);
        app_json_response([
            'ok'=>false,
            'message'=>'Move or unplace the linked equipment before archiving this floor plan: ' . implode(', ', $names) . (count($linked) > 5 ? '…' : ''),
            'linkedEquipment'=>count($linked),
        ], 409);
    }
    $statement = $pdo->prepare(
        'UPDATE floor_plans SET archived_at = NOW(6), updated_by = ?, updated_at = NOW(6)
         WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL'
    );
    $statement->execute([$userId, $organizationId, $publicId]);
    if ($statement->rowCount() < 1) {
        app_json_response(['ok' => false, 'message' => 'Floor plan not found.'], 404);
    }
    app_audit($pdo, $organizationId, $userId, 'floorplan.archived', 'floor_plan', $publicId, null, ['archived' => true]);
    app_json_response(['ok' => true, 'message' => 'Floor plan archived.']);
}

if ($action !== 'save') {
    app_json_response(['ok' => false, 'message' => 'Unsupported floor plan action.'], 422);
}

$plan = (array)($input['plan'] ?? []);
$name = trim((string)($plan['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 160) {
    app_json_response(['ok' => false, 'message' => 'Floor plan name is required and must be 160 characters or fewer.'], 422);
}
$data = $plan['data'] ?? null;
if (!is_array($data)) {
    app_json_response(['ok' => false, 'message' => 'Floor plan data must be a JSON object.'], 422);
}
$items = $data['items'] ?? [];
if (!is_array($items) || count($items) > 1500) {
    app_json_response(['ok' => false, 'message' => 'Floor plan contains too many components.'], 422);
}
$widthFt = (float)($data['planWft'] ?? 0);
$depthFt = (float)($data['planHft'] ?? 0);
$scale = (float)($data['scale'] ?? 0);
if ($widthFt < 5 || $widthFt > 1000 || $depthFt < 5 || $depthFt > 1000 || $scale < 4 || $scale > 100) {
    app_json_response(['ok' => false, 'message' => 'Floor plan dimensions or scale are outside the supported range.'], 422);
}
try {
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    app_json_response(['ok' => false, 'message' => 'Floor plan data could not be encoded.'], 422);
}
if (strlen($encoded) > 2000000) {
    app_json_response(['ok' => false, 'message' => 'Floor plan data is too large to save.'], 413);
}

$publicId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($plan['id'] ?? ''));
$isNew = $publicId === '';
if ($isNew) {
    $publicId = 'floorplan-' . bin2hex(random_bytes(10));
} else {
    $conflicts = floor_plan_equipment_bounds_conflicts($pdo, $organizationId, $publicId, $widthFt, $depthFt);
    if ($conflicts) {
        $names = array_slice(array_column($conflicts, 'name'), 0, 5);
        app_json_response([
            'ok'=>false,
            'message'=>'The new plan dimensions would place equipment outside the floor-plan boundary: ' . implode(', ', $names) . (count($conflicts) > 5 ? '…' : ''),
            'equipmentConflicts'=>count($conflicts),
        ], 409);
    }
}

$pdo->beginTransaction();
try {
    if ($isNew) {
        $defaultStatement = $pdo->prepare('SELECT COUNT(*) FROM floor_plans WHERE organization_id = ? AND archived_at IS NULL');
        $defaultStatement->execute([$organizationId]);
        $isDefault = (int)$defaultStatement->fetchColumn() === 0 ? 1 : 0;
        $statement = $pdo->prepare(
            'INSERT INTO floor_plans
             (organization_id, public_id, name, plan_json, width_ft, depth_ft, scale_px_per_ft, version, is_default, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $statement->execute([$organizationId, $publicId, $name, $encoded, $widthFt, $depthFt, $scale, $isDefault, $userId, $userId]);
        $auditAction = 'floorplan.created';
    } else {
        $lock = $pdo->prepare('SELECT id, version FROM floor_plans WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL FOR UPDATE');
        $lock->execute([$organizationId, $publicId]);
        $existing = $lock->fetch();
        if (!$existing) {
            throw new InvalidArgumentException('Floor plan not found.');
        }
        $statement = $pdo->prepare(
            'UPDATE floor_plans
             SET name = ?, plan_json = ?, width_ft = ?, depth_ft = ?, scale_px_per_ft = ?, version = version + 1, updated_by = ?, updated_at = NOW(6)
             WHERE id = ? AND organization_id = ?'
        );
        $statement->execute([$name, $encoded, $widthFt, $depthFt, $scale, $userId, (int)$existing['id'], $organizationId]);
        $auditAction = 'floorplan.updated';
    }

    $fetch = $pdo->prepare('SELECT * FROM floor_plans WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1');
    $fetch->execute([$organizationId, $publicId]);
    $row = $fetch->fetch();
    if (!$row) {
        throw new RuntimeException('Saved floor plan could not be reloaded.');
    }
    app_audit($pdo, $organizationId, $userId, $auditAction, 'floor_plan', $publicId, null, [
        'name' => $name,
        'version' => (int)$row['version'],
        'width_ft' => $widthFt,
        'depth_ft' => $depthFt,
        'component_count' => count($items),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $error instanceof InvalidArgumentException ? 404 : 500;
    app_json_response(['ok' => false, 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'The floor plan could not be saved.'], $status);
}

app_json_response(['ok' => true, 'message' => 'Floor plan saved.', 'plan' => floor_plan_payload($row)]);
