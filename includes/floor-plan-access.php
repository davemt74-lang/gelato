<?php
declare(strict_types=1);

require_once __DIR__ . '/operational-access.php';
require_once __DIR__ . '/pos-floor-plan.php';

/** Keep layout data and its location authorization on the same database snapshot. */
function floor_plan_read_snapshot(PDO $pdo, callable $read): ?array
{
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    return operational_db_wrap($pdo, $read);
}

/**
 * Map each plan to the restaurant locations it serves. Explicit assignments
 * remain in scope while a location is inactive. Only active, unconfigured
 * locations use the same default selection as POS.
 */
function floor_plan_location_map(PDO $pdo, int $organizationId): array
{
    if (!pos_floor_plan_ready($pdo)) return [];

    $fallbackId = pos_floor_plan_default_id($pdo, $organizationId);

    $locations = $pdo->prepare(
        "SELECT l.id, l.status, s.id AS setting_id, s.floor_plan_public_id
         FROM locations l LEFT JOIN pos_floor_plan_settings s ON s.location_id=l.id AND s.organization_id=l.organization_id
         WHERE l.organization_id=? AND (l.status='active' OR s.floor_plan_public_id IS NOT NULL)"
    );
    $locations->execute([$organizationId]);
    $map = [];
    foreach ($locations->fetchAll() as $location) {
        $publicId = $location['setting_id'] === null && $location['status'] === 'active'
            ? $fallbackId : $location['floor_plan_public_id'];
        if ($publicId !== null && $publicId !== '') {
            $map[(string)$publicId][] = (int)$location['id'];
        }
    }
    return $map;
}

function floor_plan_can_view(?array $scope, array $locations): bool
{
    return $scope === null || array_intersect($locations, $scope) !== [];
}

/** Editing requires every affected location. Only organization-wide editors can edit shared templates. */
function floor_plan_can_edit(?array $scope, array $locations): bool
{
    return $scope === null || ($locations !== [] && array_diff($locations, $scope) === []);
}
