<?php
declare(strict_types=1);

function audit_marker(): string
{
    return 'operational-audit';
}

function audit_db_wrap(PDO $pdo, callable $callback): mixed
{
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        $result = $callback();
        if ($started) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function audit_location_scope(PDO $pdo, array $user, string $permission): ?array
{
    if (in_array('*', $user['permissions'] ?? [], true)) {
        return null;
    }
    if (!in_array($permission, $user['permissions'] ?? [], true)) {
        return [];
    }
    $statement = $pdo->prepare(
        'SELECT ur.location_id
         FROM user_roles ur
         INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE ur.membership_id = ? AND ur.revoked_at IS NULL AND p.permission_key = ?'
    );
    $statement->execute([(int)$user['membership_id'], $permission]);
    $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        return [];
    }
    $locationIds = [];
    foreach ($rows as $locationId) {
        if ($locationId === null) {
            return null;
        }
        $id = (int)$locationId;
        if ($id > 0) {
            $locationIds[$id] = $id;
        }
    }
    return array_values($locationIds);
}

function audit_location_allowed(PDO $pdo, array $user, string $permission, int $locationId): bool
{
    if ($locationId < 1) {
        return false;
    }
    $scope = audit_location_scope($pdo, $user, $permission);
    return $scope === null || in_array($locationId, $scope, true);
}
