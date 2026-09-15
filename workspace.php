<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/admin-control-core.php';
require_once __DIR__ . '/includes/admin-dashboard-core.php';
$user = app_require_auth();
if (($user['role_slug'] ?? '') === 'wholesale_customer') {
    app_redirect('wholesale-portal.php');
}
$adminControlAllowed = admin_control_allowed($user);
$adminDashboardAllowed = admin_dashboard_allowed($user);
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

$roleRows = $pdo->prepare(
    "SELECT r.*, GROUP_CONCAT(DISTINCT p.permission_key ORDER BY p.permission_key SEPARATOR ',') AS permission_keys
     FROM roles r
     LEFT JOIN role_permissions rp ON rp.role_id = r.id
     LEFT JOIN permissions p ON p.id = rp.permission_id
     WHERE r.organization_id = :organization_id
     GROUP BY r.id
     ORDER BY r.is_owner_role DESC, r.name"
);
$roleRows->execute(['organization_id' => $organizationId]);
$roles = [];
foreach ($roleRows->fetchAll() as $role) {
    $permissions = (int)$role['is_owner_role'] === 1 ? ['*'] : array_values(array_filter(explode(',', (string)$role['permission_keys'])));
    $roles[] = [
        'id' => 'db-role-' . $role['id'],
        'name' => $role['name'],
        'slug' => $role['slug'],
        'description' => $role['description'] ?? '',
        'system' => (bool)$role['is_system_role'],
        'owner' => (bool)$role['is_owner_role'],
        'permissions' => $permissions,
    ];
}

$userRows = $pdo->prepare(
    "SELECT u.id, u.email, u.first_name, u.last_name, u.display_name, u.status, u.last_login_at, u.created_at,
            om.job_title, r.id AS role_id
     FROM users u
     INNER JOIN organization_memberships om ON om.user_id = u.id AND om.organization_id = :organization_id
     LEFT JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
     LEFT JOIN roles r ON r.id = ur.role_id
     WHERE u.archived_at IS NULL
     GROUP BY u.id, u.email, u.first_name, u.last_name, u.display_name, u.status, u.last_login_at, u.created_at, om.job_title, r.id
     ORDER BY u.display_name"
);
$userRows->execute(['organization_id' => $organizationId]);
$users = [];
foreach ($userRows->fetchAll() as $row) {
    $initials = mb_strtoupper(mb_substr((string)$row['first_name'], 0, 1) . mb_substr((string)$row['last_name'], 0, 1));
    $users[] = [
        'id' => 'db-user-' . $row['id'],
        'email' => $row['email'],
        'password' => '',
        'firstName' => $row['first_name'],
        'lastName' => $row['last_name'],
        'displayName' => $row['display_name'],
        'roleId' => $row['role_id'] ? 'db-role-' . $row['role_id'] : '',
        'position' => $row['job_title'] ?: 'Employee',
        'status' => $row['status'],
        'initials' => $initials,
        'lastLogin' => $row['last_login_at'],
        'createdAt' => $row['created_at'],
        'progress' => ['quiz' => 0, 'flashcards' => 0, 'daily' => 0, 'certifications' => 0, 'overdue' => 0],
    ];
}

$starterJobs = [
    [
        'job-server', 'server', 'Server', 'Front of House', 'Phoenix, Arizona',
        'Full-time or part-time', 'Day, evening, and weekend shifts', 'Hourly wage plus tips',
        'Guide guests through the menu, deliver accurate service, and maintain a welcoming dining room.',
        'Servers create a clear, friendly guest experience while protecting menu accuracy and food-safety communication.',
        ['Learn the complete menu and current specials', 'Enter orders accurately and communicate modifications', 'Follow allergen escalation and service procedures', 'Maintain clean, stocked service areas'],
        ['Reliable attendance', 'Clear communication', 'Ability to work standing shifts', 'Food Handler Card or ability to obtain one'],
        ['Structured menu training', 'Position certification path', 'Employee meal benefits'],
        10,
    ],
    [
        'job-pizza-cook', 'pizza-cook', 'Pizza Cook', 'Kitchen', 'Phoenix, Arizona',
        'Full-time or part-time', 'Evening and weekend availability preferred', 'Competitive hourly wage',
        'Prepare pizzas to specification, manage oven timing, and protect recipe consistency.',
        'Pizza cooks own dough, sauce, cheese, topping, and oven accuracy during high-volume service.',
        ['Build pizzas from approved recipes', 'Verify modifiers and allergen-sensitive tickets', 'Maintain station sanitation and ingredient rotation', 'Coordinate timing with expo and the line'],
        ['Kitchen experience preferred', 'Ability to lift restaurant supplies', 'Strong attention to detail', 'Food Handler Card or ability to obtain one'],
        ['Paid menu training', 'Kitchen certification pathway', 'Growth into trainer or shift lead'],
        20,
    ],
];
try {
    $starterJobInsert = $pdo->prepare(
        "INSERT IGNORE INTO jobs
        (organization_id, public_id, slug, title, department, location_name, employment_type,
         schedule_text, pay_range, summary, description, responsibilities_json, requirements_json,
         benefits_json, status, sort_order, published_at, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, NOW(6), ?, ?)"
    );
    foreach ($starterJobs as $starterJob) {
        [$publicId, $slug, $title, $department, $location, $employmentType, $schedule, $payRange,
         $summary, $description, $responsibilities, $requirements, $benefits, $sortOrder] = $starterJob;
        $starterJobInsert->execute([
            $organizationId, $publicId, $slug, $title, $department, $location, $employmentType,
            $schedule, $payRange, $summary, $description,
            json_encode($responsibilities, JSON_THROW_ON_ERROR),
            json_encode($requirements, JSON_THROW_ON_ERROR),
            json_encode($benefits, JSON_THROW_ON_ERROR),
            $sortOrder, (int)$user['id'], (int)$user['id'],
        ]);
    }
} catch (Throwable) {
    // The Jobs migration may not have been imported yet. The existing empty-state handling remains available.
}

$jobRows = $pdo->prepare("SELECT public_id, slug, title, department, location_name, employment_type, schedule_text, pay_range, summary, description, responsibilities_json, requirements_json, benefits_json, status, published_at, closes_at FROM jobs WHERE organization_id = :organization_id AND archived_at IS NULL ORDER BY sort_order, created_at DESC");
try {
    $jobRows->execute(['organization_id' => $organizationId]);
    $jobs = array_map(static fn(array $row): array => [
        'id' => $row['public_id'], 'slug' => $row['slug'], 'title' => $row['title'], 'department' => $row['department'] ?? '',
        'location' => $row['location_name'] ?? '', 'employmentType' => $row['employment_type'] ?? '', 'schedule' => $row['schedule_text'] ?? '',
        'payRange' => $row['pay_range'] ?? '', 'summary' => $row['summary'] ?? '', 'description' => $row['description'] ?? '',
        'responsibilities' => json_decode((string)($row['responsibilities_json'] ?? '[]'), true) ?: [],
        'requirements' => json_decode((string)($row['requirements_json'] ?? '[]'), true) ?: [],
        'benefits' => json_decode((string)($row['benefits_json'] ?? '[]'), true) ?: [],
        'status' => $row['status'], 'publishedAt' => $row['published_at'], 'closesAt' => $row['closes_at'],
    ], $jobRows->fetchAll());
} catch (Throwable) {
    $jobs = [];
}

$permissionRows = $pdo->query("SELECT permission_key, category, name FROM permissions ORDER BY category, name")->fetchAll();
$permissions = array_map(static fn(array $row): array => ['key' => $row['permission_key'], 'group' => $row['category'], 'name' => $row['name']], $permissionRows);

$bootstrap = [
    'users' => $users,
    'roles' => $roles,
    'permissions' => $permissions,
    'jobs' => $jobs,
    'session' => ['userId' => 'db-user-' . $user['id'], 'createdAt' => date(DATE_ATOM)],
];

$html = file_get_contents(__DIR__ . '/index.html');
if (!is_string($html)) {
    http_response_code(500);
    exit('The workspace template could not be loaded.');
}
$script = '<script>window.RESTAURANT_SERVER_SESSION=true;window.RESTAURANT_CSRF_TOKEN=' . json_encode(app_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';window.RESTAURANT_CURRENT_USER_ID=' . (int)$user['id'] . ';window.RESTAURANT_ADMIN_CONTROL_ALLOWED=' . ($adminControlAllowed ? 'true' : 'false') . ';window.RESTAURANT_ADMIN_DASHBOARD_ALLOWED=' . ($adminDashboardAllowed ? 'true' : 'false') . ';(function(d){localStorage.setItem("restaurant-admin-users-v1",JSON.stringify(d.users));localStorage.setItem("restaurant-admin-roles-v1",JSON.stringify(d.roles));localStorage.setItem("restaurant-admin-permissions-v1",JSON.stringify(d.permissions));localStorage.setItem("restaurant-jobs-v1",JSON.stringify(d.jobs||[]));localStorage.setItem("restaurant-admin-session-v1",JSON.stringify(d.session));})(' . json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ');if(window.RESTAURANT_ADMIN_CONTROL_ALLOWED){document.addEventListener("DOMContentLoaded",function(){var settings=document.getElementById("profileSettingsLink");if(!settings||document.getElementById("restaurantAdminControlLink"))return;var link=document.createElement("a");link.id="restaurantAdminControlLink";link.href="admin.php";link.textContent="▦ Restaurant Admin";settings.insertAdjacentElement("afterend",link);});}</script>';
$html = str_replace('<script src="js/auth.js"></script>', $script . "\n  <script src=\"js/auth.js\"></script>\n  <script src=\"js/floor-planner-module.js\"></script>", $html);
if ($adminDashboardAllowed) {
    $html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/admin-command-dashboard.css?v=20260914-command1\">\n</head>", $html);
    $html = str_replace('</body>', "  <script src=\"js/admin-command-dashboard.js?v=20260914-command1\"></script>\n</body>", $html);
}
if ($adminControlAllowed) {
    $html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/admin-tech-theme.css?v=20260915-tech1\">\n</head>", $html);
    $html = str_replace('<body>', '<body class="gelato-admin-tech-theme">', $html);
}
$html = str_replace('</body>', "  <script src=\"js/workspace-shell-sync.js?v=20260915-shell1\"></script>\n</body>", $html);
echo $html;