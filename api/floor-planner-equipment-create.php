<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';

$user = app_require_auth();
if (!app_has_permission('equipment.edit', $user)) {
    app_json_response(['ok' => false, 'message' => 'You do not have permission to create equipment.'], 403);
}

$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$userId = (int)$user['id'];
$input = app_json_input();
app_verify_request_csrf($input);

if (!equipment_brain_table_ready($pdo, 'equipment_assets')) {
    app_json_response([
        'ok' => false,
        'message' => 'Equipment Catalog migration is not installed. Import database/20260912_equipment_catalog_brain.sql first.',
    ], 503);
}

function fp_equipment_text(mixed $value, int $max): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, $max, 'UTF-8');
}

function fp_equipment_decimal(mixed $value): ?float
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Equipment dimensions must be numeric.');
    }
    $number = round((float)$value, 2);
    if ($number <= 0 || $number > 10000) {
        throw new InvalidArgumentException('Equipment dimensions are outside the supported range.');
    }
    return $number;
}

try {
    $asset = (array)($input['asset'] ?? []);
    $name = trim((string)($asset['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 180) {
        throw new InvalidArgumentException('Equipment name is required and must be 180 characters or fewer.');
    }

    $assetType = trim((string)($asset['assetType'] ?? 'other')) ?: 'other';
    $assetType = preg_replace('/[^a-z0-9_-]/i', '', $assetType) ?: 'other';
    $publicId = 'asset-' . bin2hex(random_bytes(10));

    $values = [
        'organization_id' => $organizationId,
        'public_id' => $publicId,
        'name' => $name,
        'asset_type' => $assetType,
        'purpose' => fp_equipment_text($asset['purpose'] ?? null, 4000),
        'brand' => fp_equipment_text($asset['brand'] ?? null, 160),
        'model' => fp_equipment_text($asset['model'] ?? null, 160),
        'width_inches' => fp_equipment_decimal($asset['widthInches'] ?? null),
        'depth_inches' => fp_equipment_decimal($asset['depthInches'] ?? null),
        'created_by' => $userId,
        'updated_by' => $userId,
    ];

    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        "INSERT INTO equipment_assets
         (organization_id, public_id, name, asset_type, purpose, brand, model, width_inches, depth_inches,
          operational_status, condition_status, criticality, created_by, updated_by)
         VALUES
         (:organization_id, :public_id, :name, :asset_type, :purpose, :brand, :model, :width_inches, :depth_inches,
          'active', 'good', 'medium', :created_by, :updated_by)"
    );
    $statement->execute($values);
    $assetDbId = (int)$pdo->lastInsertId();

    equipment_brain_sync_asset($pdo, $organizationId, $assetDbId, $userId);
    app_audit(
        $pdo,
        $organizationId,
        $userId,
        'equipment.created',
        'equipment_asset',
        $publicId,
        null,
        ['name' => $name, 'asset_type' => $assetType, 'source' => 'floor_planner']
    );
    $pdo->commit();

    app_json_response([
        'ok' => true,
        'message' => 'Equipment asset created.',
        'asset' => [
            'id' => $publicId,
            'name' => $name,
            'assetType' => $assetType,
            'widthInches' => $values['width_inches'],
            'depthInches' => $values['depth_inches'],
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Floor Planner equipment create failed: ' . $exception->getMessage());
    app_json_response([
        'ok' => false,
        'message' => 'Equipment could not be created. Refresh the planner and try again.',
    ], 500);
}
