<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$user = app_require_auth();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !app_has_permission('jobs.view', $user)) { app_json_response(['ok'=>false,'message'=>'You do not have permission to view jobs.'],403); }
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function job_payload(array $row): array {
    return [
        'id' => $row['public_id'], 'slug' => $row['slug'], 'title' => $row['title'], 'department' => $row['department'] ?? '',
        'location' => $row['location_name'] ?? '', 'employmentType' => $row['employment_type'] ?? '', 'schedule' => $row['schedule_text'] ?? '',
        'payRange' => $row['pay_range'] ?? '', 'summary' => $row['summary'] ?? '', 'description' => $row['description'] ?? '',
        'responsibilities' => json_decode((string)($row['responsibilities_json'] ?? '[]'), true) ?: [],
        'requirements' => json_decode((string)($row['requirements_json'] ?? '[]'), true) ?: [],
        'benefits' => json_decode((string)($row['benefits_json'] ?? '[]'), true) ?: [],
        'status' => $row['status'], 'publishedAt' => $row['published_at'], 'closesAt' => $row['closes_at'],
    ];
}
function list_jobs(PDO $pdo, int $organizationId): array {
    $statement = $pdo->prepare('SELECT * FROM jobs WHERE organization_id = ? AND archived_at IS NULL ORDER BY sort_order, created_at DESC');
    $statement->execute([$organizationId]);
    return array_map('job_payload', $statement->fetchAll());
}

function ensure_demo_jobs(PDO $pdo, int $organizationId, int $userId): int {
    $jobs = [
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

    $statement = $pdo->prepare(
        "INSERT IGNORE INTO jobs
        (organization_id, public_id, slug, title, department, location_name, employment_type,
         schedule_text, pay_range, summary, description, responsibilities_json, requirements_json,
         benefits_json, status, sort_order, published_at, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, NOW(6), ?, ?)"
    );

    $inserted = 0;
    foreach ($jobs as $job) {
        [$publicId, $slug, $title, $department, $location, $employmentType, $schedule, $payRange,
         $summary, $description, $responsibilities, $requirements, $benefits, $sortOrder] = $job;
        $statement->execute([
            $organizationId, $publicId, $slug, $title, $department, $location, $employmentType,
            $schedule, $payRange, $summary, $description,
            json_encode($responsibilities, JSON_THROW_ON_ERROR),
            json_encode($requirements, JSON_THROW_ON_ERROR),
            json_encode($benefits, JSON_THROW_ON_ERROR),
            $sortOrder, $userId, $userId,
        ]);
        $inserted += $statement->rowCount();
    }

    return $inserted;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $inserted = ensure_demo_jobs($pdo, $organizationId, (int)$user['id']);
    if ($inserted > 0) {
        app_audit($pdo, $organizationId, (int)$user['id'], 'jobs.demo_seeded', 'job', null, null, ['count' => $inserted]);
    }
    app_json_response(['ok' => true, 'jobs' => list_jobs($pdo, $organizationId)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: GET, POST'); app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405); }
$input = app_json_input(); app_verify_request_csrf($input);
$job = (array)($input['job'] ?? []);
$existingPublicId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($job['id'] ?? ''));
$requiredPermission = $existingPublicId !== '' ? 'jobs.edit' : 'jobs.create';
if (!app_has_permission($requiredPermission, $user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to save this job.'],403);
$title = trim((string)($job['title'] ?? ''));
if ($title === '') app_json_response(['ok' => false, 'message' => 'Job title is required.'], 422);
$status = (string)($job['status'] ?? 'draft');
if (!in_array($status, ['draft','published','paused','archived'], true)) $status = 'draft';
if ($status === 'published' && !app_has_permission('jobs.publish', $user)) app_json_response(['ok' => false, 'message' => 'You do not have permission to publish jobs.'], 403);
$publicId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($job['id'] ?? '')) ?: 'job-' . bin2hex(random_bytes(8));
$slug = strtolower(trim((string)($job['slug'] ?? $title)));
$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-') ?: $publicId;
$statement = $pdo->prepare(
    "INSERT INTO jobs (organization_id, public_id, slug, title, department, location_name, employment_type, schedule_text, pay_range, summary, description, responsibilities_json, requirements_json, benefits_json, status, published_at, closes_at, created_by, updated_by)
     VALUES (:organization_id,:public_id,:slug,:title,:department,:location_name,:employment_type,:schedule_text,:pay_range,:summary,:description,:responsibilities,:requirements,:benefits,:status,:published_at,:closes_at,:user_id,:user_id)
     ON DUPLICATE KEY UPDATE slug=VALUES(slug),title=VALUES(title),department=VALUES(department),location_name=VALUES(location_name),employment_type=VALUES(employment_type),schedule_text=VALUES(schedule_text),pay_range=VALUES(pay_range),summary=VALUES(summary),description=VALUES(description),responsibilities_json=VALUES(responsibilities_json),requirements_json=VALUES(requirements_json),benefits_json=VALUES(benefits_json),status=VALUES(status),published_at=VALUES(published_at),closes_at=VALUES(closes_at),updated_by=VALUES(updated_by)"
);
$publishedAt = $status === 'published' ? (string)($job['publishedAt'] ?? date('Y-m-d H:i:s')) : null;
$statement->execute([
    'organization_id'=>$organizationId,'public_id'=>$publicId,'slug'=>$slug,'title'=>$title,'department'=>trim((string)($job['department'] ?? '')) ?: null,
    'location_name'=>trim((string)($job['location'] ?? '')) ?: null,'employment_type'=>trim((string)($job['employmentType'] ?? '')) ?: null,
    'schedule_text'=>trim((string)($job['schedule'] ?? '')) ?: null,'pay_range'=>trim((string)($job['payRange'] ?? '')) ?: null,
    'summary'=>trim((string)($job['summary'] ?? '')) ?: null,'description'=>trim((string)($job['description'] ?? '')) ?: null,
    'responsibilities'=>json_encode(array_values((array)($job['responsibilities'] ?? [])), JSON_THROW_ON_ERROR),
    'requirements'=>json_encode(array_values((array)($job['requirements'] ?? [])), JSON_THROW_ON_ERROR),
    'benefits'=>json_encode(array_values((array)($job['benefits'] ?? [])), JSON_THROW_ON_ERROR),
    'status'=>$status,'published_at'=>$publishedAt,'closes_at'=>trim((string)($job['closesAt'] ?? '')) ?: null,'user_id'=>(int)$user['id'],
]);
app_audit($pdo, $organizationId, (int)$user['id'], 'job.updated', 'job', $publicId, null, ['title'=>$title,'status'=>$status]);
app_json_response(['ok'=>true,'message'=>'Job saved.','jobs'=>list_jobs($pdo,$organizationId)]);
