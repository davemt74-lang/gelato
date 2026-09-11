<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = app_pdo();
    $organizationId = (int)($pdo->query(
        "SELECT organization_id FROM brand_settings ORDER BY id ASC LIMIT 1"
    )->fetchColumn() ?: 0);

    if ($organizationId <= 0) {
        $organizationId = (int)($pdo->query(
            "SELECT id FROM organizations WHERE status = 'active' ORDER BY id ASC LIMIT 1"
        )->fetchColumn() ?: 0);
    }

    if ($organizationId <= 0) {
        app_json_response(['ok' => true, 'jobs' => []]);
    }

    $statement = $pdo->prepare(
        "SELECT public_id, slug, title, department, location_name, employment_type, schedule_text,
                pay_range, summary, description, responsibilities_json, requirements_json,
                benefits_json, published_at, closes_at
         FROM jobs
         WHERE organization_id = ?
           AND status = 'published'
           AND archived_at IS NULL
           AND (closes_at IS NULL OR closes_at >= CURDATE())
         ORDER BY sort_order, published_at DESC, title"
    );
    $statement->execute([$organizationId]);

    $jobs = array_map(static function (array $row): array {
        return [
            'id' => $row['public_id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'department' => $row['department'] ?? '',
            'location' => $row['location_name'] ?? '',
            'employmentType' => $row['employment_type'] ?? '',
            'schedule' => $row['schedule_text'] ?? '',
            'payRange' => $row['pay_range'] ?? '',
            'summary' => $row['summary'] ?? '',
            'description' => $row['description'] ?? '',
            'responsibilities' => json_decode((string)($row['responsibilities_json'] ?? '[]'), true) ?: [],
            'requirements' => json_decode((string)($row['requirements_json'] ?? '[]'), true) ?: [],
            'benefits' => json_decode((string)($row['benefits_json'] ?? '[]'), true) ?: [],
            'publishedAt' => $row['published_at'],
            'closesAt' => $row['closes_at'],
            'status' => 'published',
        ];
    }, $statement->fetchAll());

    app_json_response(['ok' => true, 'jobs' => $jobs]);
} catch (Throwable) {
    app_json_response([
        'ok' => false,
        'message' => 'Published jobs are not available yet.',
        'jobs' => [],
    ], 503);
}
