<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/menu-sync.php';

try {
    $user = app_require_auth();
    $pdo = app_pdo();
    $organizationId = (int)$user['organization_id'];
    $sections = menu_database_sections($pdo, $organizationId);
    app_json_response([
        'ok' => true,
        'source' => 'database',
        'organizationId' => $organizationId,
        'sections' => $sections,
        'notes' => menu_database_notes($sections),
        'summary' => [
            'sections' => count($sections),
            'items' => array_sum(array_map(static fn(array $section): int => count($section['items'] ?? []), $sections)),
        ],
    ]);
} catch (Throwable $exception) {
    app_json_response(['ok' => false, 'message' => $exception->getMessage()], 500);
}
