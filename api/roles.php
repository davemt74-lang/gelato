<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD'] === 'GET' ? 'roles.view' : 'roles.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !app_has_permission('roles.assign_permissions', $user)) {
    app_json_response(['ok' => false, 'message' => 'You do not have permission to assign account-type permissions.'], 403);
}
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function role_list(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare(
        "SELECT r.*, GROUP_CONCAT(DISTINCT p.permission_key ORDER BY p.permission_key SEPARATOR ',') AS permission_keys
         FROM roles r
         LEFT JOIN role_permissions rp ON rp.role_id = r.id
         LEFT JOIN permissions p ON p.id = rp.permission_id
         WHERE r.organization_id = :organization_id
         GROUP BY r.id ORDER BY r.is_owner_role DESC, r.name"
    );
    $statement->execute(['organization_id' => $organizationId]);
    return array_map(static fn(array $row): array => [
        'id' => 'db-role-' . $row['id'],
        'name' => $row['name'],
        'slug' => $row['slug'],
        'description' => $row['description'] ?? '',
        'system' => (bool)$row['is_system_role'],
        'owner' => (bool)$row['is_owner_role'],
        'permissions' => (int)$row['is_owner_role'] === 1 ? ['*'] : array_values(array_filter(explode(',', (string)$row['permission_keys']))),
    ], $statement->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    app_json_response(['ok' => true, 'roles' => role_list($pdo, $organizationId)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
$input = app_json_input();
app_verify_request_csrf($input);
$action = (string)($input['action'] ?? 'update');
$name = trim((string)($input['name'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$permissionKeys = array_values(array_unique(array_filter(array_map('strval', (array)($input['permissions'] ?? [])))));
if ($name === '') {
    app_json_response(['ok' => false, 'message' => 'Account type name is required.'], 422);
}

$validStatement = $pdo->prepare('SELECT permission_key FROM permissions WHERE permission_key IN (' . implode(',', array_fill(0, max(1, count($permissionKeys)), '?')) . ')');
$validStatement->execute($permissionKeys ?: ['__none__']);
$validKeys = $validStatement->fetchAll(PDO::FETCH_COLUMN);
if (count($validKeys) !== count($permissionKeys)) {
    app_json_response(['ok' => false, 'message' => 'One or more selected permissions are invalid.'], 422);
}

$pdo->beginTransaction();
try {
    if ($action === 'create') {
        if (!app_has_permission('roles.create', $user)) {
            app_json_response(['ok' => false, 'message' => 'You do not have permission to create account types.'], 403);
        }
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', $slug ?: $name), '_');
        $statement = $pdo->prepare("INSERT INTO roles (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable) VALUES (?, ?, ?, ?, 0, 0, 1)");
        $statement->execute([$organizationId, $name, $slug, $description ?: null]);
        $roleId = (int)$pdo->lastInsertId();
        $auditAction = 'role.created';
    } else {
        $roleId = (int)preg_replace('/\D+/', '', (string)($input['roleId'] ?? '0'));
        $statement = $pdo->prepare('SELECT * FROM roles WHERE id = ? AND organization_id = ? FOR UPDATE');
        $statement->execute([$roleId, $organizationId]);
        $role = $statement->fetch();
        if (!$role) {
            throw new InvalidArgumentException('Account type not found.');
        }
        if ((int)$role['is_owner_role'] === 1) {
            throw new InvalidArgumentException('The protected Super Admin account type cannot be edited.');
        }
        $statement = $pdo->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ? AND organization_id = ?');
        $statement->execute([$name, $description ?: null, $roleId, $organizationId]);
        $auditAction = 'role.updated';
    }
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    if ($validKeys) {
        $permissionIdStatement = $pdo->prepare('SELECT id FROM permissions WHERE permission_key = ?');
        $grantStatement = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
        foreach ($validKeys as $key) {
            $permissionIdStatement->execute([$key]);
            $permissionId = (int)$permissionIdStatement->fetchColumn();
            $grantStatement->execute([$roleId, $permissionId]);
        }
    }
    app_audit($pdo, $organizationId, (int)$user['id'], $auditAction, 'role', (string)$roleId, null, ['name' => $name, 'permissions' => $validKeys]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_json_response(['ok' => false, 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'The account type could not be saved.'], $error instanceof InvalidArgumentException ? 422 : 500);
}
app_json_response(['ok' => true, 'message' => $action === 'create' ? 'Account type created.' : 'Account type permissions updated.', 'roles' => role_list($pdo, $organizationId)]);
