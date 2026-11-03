<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** The workspace only receives records that the authenticated actor may read. */
function workspace_bootstrap(PDO $pdo, array $user): array
{
    $organizationId = (int)$user['organization_id'];
    if (empty($_SESSION['browser_state_id'])) $_SESSION['browser_state_id'] = bin2hex(random_bytes(16));
    $currentRole = [
        'id' => 'db-role-' . $user['role_id'],
        'name' => $user['role_name'],
        'slug' => $user['role_slug'],
        'description' => $user['role_description'] ?? '',
        'system' => (bool)$user['is_system_role'],
        'owner' => app_role_has_owner_authority($user),
        // Effective permissions include every active role, not just the display role.
        'permissions' => array_values($user['permissions']),
    ];
    $current = workspace_user_payload($user);
    $current['role'] = $currentRole;
    $roles = [$currentRole];
    if (app_has_permission('roles.view', $user)) {
        $statement = $pdo->prepare("SELECT r.*, GROUP_CONCAT(DISTINCT p.permission_key ORDER BY p.permission_key SEPARATOR ',') AS permission_keys
            FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.id
            LEFT JOIN permissions p ON p.id=rp.permission_id
            WHERE r.organization_id=? GROUP BY r.id ORDER BY r.is_owner_role DESC,r.name");
        $statement->execute([$organizationId]);
        $roles = array_map(static function (array $row): array {
            $owner = app_role_has_owner_authority($row);
            return ['id'=>'db-role-'.$row['id'], 'name'=>$row['name'], 'slug'=>$row['slug'],
                'description'=>$row['description'] ?? '', 'system'=>(bool)$row['is_system_role'],
                'owner'=>$owner, 'permissions'=>$owner ? ['*'] : array_values(array_filter(explode(',', (string)$row['permission_keys'])))];
        }, $statement->fetchAll());
    }
    $users = [workspace_user_payload($user)];
    if (app_has_permission('users.view', $user)) {
        $statement = $pdo->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.display_name,u.status,u.last_login_at,u.created_at,
                om.job_title,r.id AS role_id
            FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
            LEFT JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
            LEFT JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
            WHERE u.archived_at IS NULL
            GROUP BY u.id,u.email,u.first_name,u.last_name,u.display_name,u.status,u.last_login_at,u.created_at,om.job_title,r.id
            ORDER BY u.display_name");
        $statement->execute([$organizationId]);
        $users = array_map('workspace_user_payload', $statement->fetchAll());
    }
    $jobs = [];
    if (app_has_permission('jobs.view', $user)) {
        try {
            $statement = $pdo->prepare('SELECT public_id,slug,title,department,location_name,employment_type,schedule_text,pay_range,summary,description,responsibilities_json,requirements_json,benefits_json,status,published_at,closes_at FROM jobs WHERE organization_id=? AND archived_at IS NULL ORDER BY sort_order,created_at DESC');
            $statement->execute([$organizationId]);
            $jobs = array_map(static fn(array $row): array => [
                'id'=>$row['public_id'], 'slug'=>$row['slug'], 'title'=>$row['title'], 'department'=>$row['department'] ?? '',
                'location'=>$row['location_name'] ?? '', 'employmentType'=>$row['employment_type'] ?? '', 'schedule'=>$row['schedule_text'] ?? '',
                'payRange'=>$row['pay_range'] ?? '', 'summary'=>$row['summary'] ?? '', 'description'=>$row['description'] ?? '',
                'responsibilities'=>json_decode((string)($row['responsibilities_json'] ?? '[]'),true) ?: [],
                'requirements'=>json_decode((string)($row['requirements_json'] ?? '[]'),true) ?: [],
                'benefits'=>json_decode((string)($row['benefits_json'] ?? '[]'),true) ?: [],
                'status'=>$row['status'], 'publishedAt'=>$row['published_at'], 'closesAt'=>$row['closes_at'],
            ], $statement->fetchAll());
        } catch (PDOException $error) {
            // Older installations can still open the shell before the Jobs migration.
            if ((string)$error->getCode() !== '42S02') throw $error;
        }
    }
    $permissions = [];
    if (app_has_permission('roles.view', $user)) {
        $rows = $pdo->query('SELECT permission_key,category,name FROM permissions ORDER BY category,name')->fetchAll();
        $permissions = array_map(static fn(array $row): array => ['key'=>$row['permission_key'],'group'=>$row['category'],'name'=>$row['name']], $rows);
    }
    return ['organizationId'=>$organizationId, 'currentUser'=>$current, 'users'=>$users, 'roles'=>$roles,
        'permissions'=>$permissions, 'jobs'=>$jobs,
        'session'=>['userId'=>'db-user-'.$user['id'], 'identity'=>(string)$_SESSION['browser_state_id'], 'createdAt'=>date(DATE_ATOM)]];
}

function workspace_user_payload(array $row): array
{
    return ['id'=>'db-user-'.$row['id'], 'email'=>$row['email'], 'firstName'=>$row['first_name'],
        'lastName'=>$row['last_name'], 'displayName'=>$row['display_name'],
        'roleId'=>!empty($row['role_id']) ? 'db-role-'.$row['role_id'] : '',
        'position'=>$row['job_title'] ?: 'Employee', 'status'=>$row['status'],
        'initials'=>mb_strtoupper(mb_substr((string)$row['first_name'],0,1).mb_substr((string)$row['last_name'],0,1)),
        'lastLogin'=>$row['last_login_at'] ?? null, 'createdAt'=>$row['created_at'] ?? null,
        'progress'=>['quiz'=>0,'flashcards'=>0,'daily'=>0,'certifications'=>0,'overdue'=>0]];
}
