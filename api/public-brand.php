<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = app_pdo();
    $statement = $pdo->query(
        "SELECT bs.*, logo.storage_path AS logo_path, cover.storage_path AS cover_path
         FROM brand_settings bs
         INNER JOIN organizations o ON o.id = bs.organization_id AND o.status = 'active'
         LEFT JOIN files logo ON logo.id = bs.logo_file_id AND logo.deleted_at IS NULL
         LEFT JOIN files cover ON cover.id = bs.cover_file_id AND cover.deleted_at IS NULL
         ORDER BY bs.id ASC LIMIT 1"
    );
    $row = $statement->fetch() ?: [];
    $theme = json_decode((string)($row['theme_settings_json'] ?? '{}'), true) ?: [];
    $contact = json_decode((string)($row['contact_settings_json'] ?? '{}'), true) ?: [];
    header('Access-Control-Allow-Origin: same-origin');
    app_json_response(['ok' => true, 'brand' => [
        'restaurantName' => (string)($row['restaurant_name'] ?? 'Restaurant'),
        'legalName' => (string)($row['legal_name'] ?? ''),
        'email' => (string)($contact['email'] ?? ''),
        'phone' => (string)($contact['phone'] ?? ''),
        'address' => (string)($contact['address'] ?? ''),
        'description' => (string)($theme['description'] ?? 'Careers and training'),
        'logoText' => (string)($theme['logo_text'] ?? 'FR'),
        'primary' => (string)($row['primary_color'] ?? '#d94a2b'),
        'secondary' => (string)($row['secondary_color'] ?? '#ff835f'),
        'dark' => (string)($theme['dark_color'] ?? '#171b1a'),
        'logoUrl' => app_public_asset_url($row['logo_path'] ?? null),
        'coverUrl' => app_public_asset_url($row['cover_path'] ?? null),
    ]]);
} catch (Throwable) {
    app_json_response(['ok' => true, 'brand' => null]);
}
