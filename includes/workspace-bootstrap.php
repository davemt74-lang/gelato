<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-accounts.php';

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
        $roles=array_map(static fn(array $row):array=>[
            'id'=>'db-role-'.$row['id'],'name'=>$row['name'],'slug'=>$row['slug'],
            'description'=>$row['description']??'','system'=>(bool)$row['is_system_role'],
            'owner'=>$row['owner'],'permissions'=>$row['permissions'],
        ],accounts_roles($pdo,$organizationId));
    }

    $users = [workspace_user_payload($user)];
    if (app_has_permission('users.view', $user)) {
        $users = accounts_list($pdo,$organizationId,(int)$user['id']);
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
        'lastName'=>$row['last_name'], 'displayName'=>$row['display_name'], 'phone'=>$row['phone']??'',
        'roleId'=>!empty($row['role_id']) ? 'db-role-'.$row['role_id'] : '',
        'position'=>$row['job_title'] ?: 'Employee', 'status'=>$row['status'],
        'initials'=>mb_strtoupper(mb_substr((string)$row['first_name'],0,1).mb_substr((string)$row['last_name'],0,1)),
        'lastLogin'=>$row['last_login_at'] ?? null, 'createdAt'=>$row['created_at'] ?? null,
        'progress'=>['quiz'=>0,'flashcards'=>0,'daily'=>0,'certifications'=>0,'overdue'=>0]];
}
