<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';
require __DIR__ . '/../includes/floor-equipment-brain.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$userId = (int)$user['id'];

function placement_require(array $user, string $permission): void
{
    if (!app_has_permission($permission, $user)) {
        app_json_response(['ok' => false, 'message' => 'You do not have permission to complete this floor-plan equipment action.'], 403);
    }
}

function placement_clean_id(mixed $value): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$value)) ?: '';
}

function placement_table_ready(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'equipment_assets'
               AND column_name IN ('floor_plan_x_ft','floor_plan_y_ft','floor_plan_rotation_deg','floor_plan_z_index','floor_plan_locked')"
        );
        $statement->execute();
        return (int)$statement->fetchColumn() === 5;
    } catch (Throwable) {
        return false;
    }
}

function placement_plan(PDO $pdo, int $organizationId, string $publicId): ?array
{
    $statement = $pdo->prepare(
        "SELECT public_id, name, width_ft, depth_ft, scale_px_per_ft, version, is_default, updated_at
         FROM floor_plans
         WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1"
    );
    $statement->execute([$organizationId, $publicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function placement_asset_row(PDO $pdo, int $organizationId, string $publicId, bool $forUpdate = false): ?array
{
    $sql = "SELECT * FROM equipment_assets WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$organizationId, $publicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function placement_asset_payload(array $row): array
{
    return [
        'id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'assetType'=>(string)$row['asset_type'],
        'purpose'=>(string)($row['purpose']??''),'brand'=>(string)($row['brand']??''),'manufacturer'=>(string)($row['manufacturer']??''),
        'model'=>(string)($row['model']??''),'assetTag'=>(string)($row['asset_tag']??''),'serialNumber'=>(string)($row['serial_number']??''),
        'operationalStatus'=>(string)$row['operational_status'],'conditionStatus'=>(string)$row['condition_status'],'criticality'=>(string)$row['criticality'],
        'locationName'=>(string)($row['location_name']??''),'floorPlanId'=>(string)($row['floor_plan_public_id']??''),'componentId'=>(string)($row['floor_plan_component_id']??''),
        'xFt'=>$row['floor_plan_x_ft']!==null?(float)$row['floor_plan_x_ft']:null,'yFt'=>$row['floor_plan_y_ft']!==null?(float)$row['floor_plan_y_ft']:null,
        'rotationDeg'=>(float)($row['floor_plan_rotation_deg']??0),'zIndex'=>(int)($row['floor_plan_z_index']??1),'locked'=>(bool)($row['floor_plan_locked']??0),
        'placedAt'=>$row['floor_plan_placed_at']??null,'widthInches'=>$row['width_inches']!==null?(float)$row['width_inches']:null,
        'depthInches'=>$row['depth_inches']!==null?(float)$row['depth_inches']:null,'heightInches'=>$row['height_inches']!==null?(float)$row['height_inches']:null,
        'capacityText'=>(string)($row['capacity_text']??''),'utilityType'=>(string)($row['utility_type']??''),'voltage'=>(string)($row['voltage']??''),
        'amperage'=>(string)($row['amperage']??''),'ventilationRequirement'=>(string)($row['ventilation_requirement']??''),
        'waterRequirement'=>(string)($row['water_requirement']??''),'drainRequirement'=>(string)($row['drain_requirement']??''),
        'maintenanceRequired'=>(bool)$row['maintenance_required'],'lastServiceOn'=>$row['last_service_on'],'nextServiceOn'=>$row['next_service_on'],
        'warrantyExpiresOn'=>$row['warranty_expires_on'],'manualUrl'=>(string)($row['manual_url']??''),'updatedAt'=>(string)$row['updated_at'],
    ];
}

function placement_assets(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare(
        "SELECT * FROM equipment_assets
         WHERE organization_id = ? AND archived_at IS NULL AND operational_status <> 'retired'
         ORDER BY CASE WHEN floor_plan_public_id IS NULL OR floor_plan_public_id='' THEN 1 ELSE 0 END,
                  FIELD(criticality,'critical','high','medium','low'), name"
    );
    $statement->execute([$organizationId]);
    return array_map('placement_asset_payload', $statement->fetchAll());
}

function placement_number(mixed $value, string $name, float $minimum, float $maximum): float
{
    if (!is_numeric($value)) throw new InvalidArgumentException($name . ' must be numeric.');
    $number = round((float)$value, 3);
    if ($number < $minimum || $number > $maximum) throw new InvalidArgumentException($name . ' is outside the supported range.');
    return $number;
}

function placement_rotation(mixed $value): float
{
    if (!is_numeric($value)) return 0.0;
    $rotation = fmod((float)$value, 360.0);
    if ($rotation < 0) $rotation += 360.0;
    return round($rotation, 2);
}

function placement_sync_brain(PDO $pdo, int $organizationId, int $assetId, string $assetPublicId, ?int $userId): void
{
    equipment_brain_sync_asset($pdo, $organizationId, $assetId, $userId);
    floor_equipment_brain_sync_placement($pdo, $organizationId, $assetPublicId, $userId);
}

if (!placement_table_ready($pdo)) {
    app_json_response(['ok'=>false,'message'=>'Canonical floor-plan equipment migration is not installed. Import database/20260912_canonical_floor_equipment.sql first.'],503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    placement_require($user, 'floorplans.view');
    placement_require($user, 'equipment.view');
    $planId = placement_clean_id($_GET['plan'] ?? '');
    if ($planId === '') app_json_response(['ok'=>false,'message'=>'A floor plan id is required.'],422);
    $plan = placement_plan($pdo, $organizationId, $planId);
    if (!$plan) app_json_response(['ok'=>false,'message'=>'Floor plan not found.'],404);
    $assets = placement_assets($pdo, $organizationId);
    $placed = array_values(array_filter($assets, static fn(array $asset): bool => $asset['floorPlanId'] === $planId));
    $unplaced = array_values(array_filter($assets, static fn(array $asset): bool => $asset['floorPlanId'] === ''));
    $otherPlans = array_values(array_filter($assets, static fn(array $asset): bool => $asset['floorPlanId'] !== '' && $asset['floorPlanId'] !== $planId));
    app_json_response(['ok'=>true,'plan'=>[
        'id'=>(string)$plan['public_id'],'name'=>(string)$plan['name'],'widthFt'=>(float)$plan['width_ft'],'depthFt'=>(float)$plan['depth_ft'],
        'scale'=>(float)$plan['scale_px_per_ft'],'version'=>(int)$plan['version'],'isDefault'=>(bool)$plan['is_default'],'updatedAt'=>(string)$plan['updated_at'],
    ],'placed'=>$placed,'unplaced'=>$unplaced,'otherPlans'=>$otherPlans]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

placement_require($user, 'floorplans.edit');
placement_require($user, 'equipment.view');
$input = app_json_input();
app_verify_request_csrf($input);
$action = trim((string)($input['action'] ?? ''));
$assetPublicId = placement_clean_id($input['assetId'] ?? '');
if ($assetPublicId === '') app_json_response(['ok'=>false,'message'=>'An equipment asset id is required.'],422);

try {
    $pdo->beginTransaction();
    $asset = placement_asset_row($pdo, $organizationId, $assetPublicId, true);
    if (!$asset) throw new DomainException('Equipment asset not found.');

    if ($action === 'place' || $action === 'move') {
        $planId = placement_clean_id($input['planId'] ?? '');
        $plan = $planId !== '' ? placement_plan($pdo, $organizationId, $planId) : null;
        if (!$plan) throw new DomainException('Floor plan not found.');
        $xFt = placement_number($input['xFt'] ?? null, 'X position', 0, (float)$plan['width_ft']);
        $yFt = placement_number($input['yFt'] ?? null, 'Y position', 0, (float)$plan['depth_ft']);
        $rotation = placement_rotation($input['rotationDeg'] ?? 0);
        $zIndex = max(1, min(100000, (int)($input['zIndex'] ?? 1)));
        $componentId = 'asset:' . $assetPublicId;
        $statement = $pdo->prepare(
            "UPDATE equipment_assets
             SET floor_plan_public_id=?, floor_plan_component_id=?, floor_plan_x_ft=?, floor_plan_y_ft=?,
                 floor_plan_rotation_deg=?, floor_plan_z_index=?, floor_plan_placed_at=COALESCE(floor_plan_placed_at,NOW(6)),
                 updated_by=?, updated_at=NOW(6)
             WHERE id=? AND organization_id=?"
        );
        $statement->execute([$planId,$componentId,$xFt,$yFt,$rotation,$zIndex,$userId,(int)$asset['id'],$organizationId]);
        $auditAction = $asset['floor_plan_public_id'] ? 'floorplan.equipment_moved' : 'floorplan.equipment_placed';
        $auditNew = ['assetId'=>$assetPublicId,'planId'=>$planId,'xFt'=>$xFt,'yFt'=>$yFt,'rotationDeg'=>$rotation,'zIndex'=>$zIndex];
    } elseif ($action === 'unplace') {
        $statement = $pdo->prepare(
            "UPDATE equipment_assets SET floor_plan_public_id=NULL, floor_plan_component_id=NULL, floor_plan_x_ft=NULL, floor_plan_y_ft=NULL,
                 floor_plan_rotation_deg=0, floor_plan_z_index=1, floor_plan_locked=0, floor_plan_placed_at=NULL, updated_by=?, updated_at=NOW(6)
             WHERE id=? AND organization_id=?"
        );
        $statement->execute([$userId,(int)$asset['id'],$organizationId]);
        $auditAction='floorplan.equipment_unplaced';
        $auditNew=['assetId'=>$assetPublicId];
    } elseif ($action === 'resize') {
        placement_require($user, 'equipment.edit');
        $width=placement_number($input['widthInches']??null,'Equipment width',1,1200);
        $depth=placement_number($input['depthInches']??null,'Equipment depth',1,1200);
        $statement=$pdo->prepare("UPDATE equipment_assets SET width_inches=?, depth_inches=?, updated_by=?, updated_at=NOW(6) WHERE id=? AND organization_id=?");
        $statement->execute([$width,$depth,$userId,(int)$asset['id'],$organizationId]);
        $auditAction='floorplan.equipment_resized';
        $auditNew=['assetId'=>$assetPublicId,'widthInches'=>$width,'depthInches'=>$depth];
    } elseif ($action === 'lock') {
        $locked=!empty($input['locked'])?1:0;
        $statement=$pdo->prepare("UPDATE equipment_assets SET floor_plan_locked=?, updated_by=?, updated_at=NOW(6) WHERE id=? AND organization_id=?");
        $statement->execute([$locked,$userId,(int)$asset['id'],$organizationId]);
        $auditAction=$locked?'floorplan.equipment_locked':'floorplan.equipment_unlocked';
        $auditNew=['assetId'=>$assetPublicId,'locked'=>(bool)$locked];
    } else {
        throw new InvalidArgumentException('Unsupported equipment placement action.');
    }

    placement_sync_brain($pdo,$organizationId,(int)$asset['id'],$assetPublicId,$userId);
    app_audit($pdo,$organizationId,$userId,$auditAction,'equipment_asset',$assetPublicId,[
        'floorPlanId'=>$asset['floor_plan_public_id']??null,'xFt'=>$asset['floor_plan_x_ft']??null,'yFt'=>$asset['floor_plan_y_ft']??null,
        'rotationDeg'=>$asset['floor_plan_rotation_deg']??0,'widthInches'=>$asset['width_inches']??null,'depthInches'=>$asset['depth_inches']??null,
    ],$auditNew);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status=$error instanceof DomainException?404:($error instanceof InvalidArgumentException?422:500);
    app_json_response(['ok'=>false,'message'=>$status===500?'The equipment placement could not be saved.':$error->getMessage()],$status);
}

$updated=placement_asset_row($pdo,$organizationId,$assetPublicId,false);
app_json_response(['ok'=>true,'message'=>'Equipment placement saved.','asset'=>placement_asset_payload($updated)]);
