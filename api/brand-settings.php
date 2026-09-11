<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD'] === 'GET' ? 'brand.view' : 'brand.edit');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function brand_payload(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare(
        "SELECT bs.*, logo.storage_path AS logo_path, cover.storage_path AS cover_path
         FROM brand_settings bs
         LEFT JOIN files logo ON logo.id = bs.logo_file_id AND logo.deleted_at IS NULL
         LEFT JOIN files cover ON cover.id = bs.cover_file_id AND cover.deleted_at IS NULL
         WHERE bs.organization_id = :organization_id
         LIMIT 1"
    );
    try {
        $statement->execute(['organization_id' => $organizationId]);
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'cover_file_id')) {
            app_json_response(['ok' => false, 'message' => 'The brand-image migration has not been imported. Import database/20260803_brand_images_llm_keys.sql once.'], 409);
        }
        throw $error;
    }
    $row = $statement->fetch() ?: [];
    $theme = json_decode((string)($row['theme_settings_json'] ?? '{}'), true) ?: [];
    $contact = json_decode((string)($row['contact_settings_json'] ?? '{}'), true) ?: [];
    return [
        'restaurantName' => (string)($row['restaurant_name'] ?? 'Restaurant'),
        'legalName' => (string)($row['legal_name'] ?? ''),
        'email' => (string)($contact['email'] ?? ''),
        'phone' => (string)($contact['phone'] ?? ''),
        'address' => (string)($contact['address'] ?? ''),
        'description' => (string)($theme['description'] ?? ''),
        'logoText' => (string)($theme['logo_text'] ?? 'FR'),
        'primary' => (string)($row['primary_color'] ?? '#d94a2b'),
        'secondary' => (string)($row['secondary_color'] ?? '#ff835f'),
        'dark' => (string)($theme['dark_color'] ?? '#171b1a'),
        'logoUrl' => app_public_asset_url($row['logo_path'] ?? null),
        'coverUrl' => app_public_asset_url($row['cover_path'] ?? null),
    ];
}

function save_brand_upload(PDO $pdo, array $file, int $organizationId, int $userId, string $kind): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('No file was uploaded.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The image upload failed.');
    }
    $limit = $kind === 'cover' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $limit) {
        throw new InvalidArgumentException($kind === 'cover' ? 'Cover images must be 10 MB or smaller.' : 'Logo images must be 5 MB or smaller.');
    }
    $temporary = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($temporary)) {
        throw new InvalidArgumentException('The uploaded file could not be verified.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Use a PNG, JPG, or WebP image.');
    }
    $directoryRelative = 'uploads/brand/' . $organizationId;
    $directory = RESTAURANT_APP_ROOT . '/' . $directoryRelative;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The brand upload directory could not be created.');
    }
    $storedName = $kind . '-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    $relativePath = $directoryRelative . '/' . $storedName;
    $destination = RESTAURANT_APP_ROOT . '/' . $relativePath;
    if (!move_uploaded_file($temporary, $destination)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }
    @chmod($destination, 0644);
    $statement = $pdo->prepare(
        "INSERT INTO files
         (organization_id, storage_driver, storage_path, original_name, stored_name, mime_type, file_size, checksum_sha256, uploaded_by, visibility)
         VALUES (:organization_id, 'local_public', :storage_path, :original_name, :stored_name, :mime_type, :file_size, :checksum, :uploaded_by, 'public')"
    );
    $statement->execute([
        'organization_id' => $organizationId,
        'storage_path' => $relativePath,
        'original_name' => mb_substr((string)($file['name'] ?? $storedName), 0, 255),
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'file_size' => $size,
        'checksum' => hash_file('sha256', $destination),
        'uploaded_by' => $userId,
    ]);
    return (int)$pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    app_json_response(['ok' => true, 'brand' => brand_payload($pdo, $organizationId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

app_verify_request_csrf();
$restaurantName = trim((string)($_POST['restaurant_name'] ?? ''));
if ($restaurantName === '') {
    app_json_response(['ok' => false, 'message' => 'Restaurant name is required.'], 422);
}
$hex = static function (string $value, string $fallback): string {
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback;
};

$pdo->beginTransaction();
try {
    $currentStatement = $pdo->prepare('SELECT * FROM brand_settings WHERE organization_id = ? FOR UPDATE');
    $currentStatement->execute([$organizationId]);
    $current = $currentStatement->fetch() ?: [];
    $logoId = isset($current['logo_file_id']) ? (int)$current['logo_file_id'] : null;
    $coverId = isset($current['cover_file_id']) ? (int)$current['cover_file_id'] : null;
    if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $logoId = save_brand_upload($pdo, $_FILES['logo'], $organizationId, (int)$user['id'], 'logo');
    }
    if (isset($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $coverId = save_brand_upload($pdo, $_FILES['cover'], $organizationId, (int)$user['id'], 'cover');
    }
    if (($_POST['remove_logo'] ?? '') === '1') {
        $logoId = null;
    }
    if (($_POST['remove_cover'] ?? '') === '1') {
        $coverId = null;
    }
    $theme = [
        'description' => trim((string)($_POST['description'] ?? '')),
        'logo_text' => mb_substr(mb_strtoupper(trim((string)($_POST['logo_text'] ?? 'FR'))), 0, 4),
        'dark_color' => $hex((string)($_POST['dark'] ?? ''), '#171b1a'),
    ];
    $contact = [
        'email' => trim((string)($_POST['email'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
    ];
    $statement = $pdo->prepare(
        "INSERT INTO brand_settings
         (organization_id, restaurant_name, legal_name, logo_file_id, cover_file_id, primary_color, secondary_color, accent_color, theme_settings_json, contact_settings_json, updated_by)
         VALUES (:organization_id, :restaurant_name, :legal_name, :logo_file_id, :cover_file_id, :primary, :secondary, :accent, :theme, :contact, :updated_by)
         ON DUPLICATE KEY UPDATE restaurant_name = VALUES(restaurant_name), legal_name = VALUES(legal_name),
           logo_file_id = VALUES(logo_file_id), cover_file_id = VALUES(cover_file_id), primary_color = VALUES(primary_color),
           secondary_color = VALUES(secondary_color), accent_color = VALUES(accent_color), theme_settings_json = VALUES(theme_settings_json),
           contact_settings_json = VALUES(contact_settings_json), updated_by = VALUES(updated_by)"
    );
    $statement->execute([
        'organization_id' => $organizationId,
        'restaurant_name' => $restaurantName,
        'legal_name' => trim((string)($_POST['legal_name'] ?? '')) ?: null,
        'logo_file_id' => $logoId ?: null,
        'cover_file_id' => $coverId ?: null,
        'primary' => $hex((string)($_POST['primary'] ?? ''), '#d94a2b'),
        'secondary' => $hex((string)($_POST['secondary'] ?? ''), '#ff835f'),
        'accent' => $hex((string)($_POST['primary'] ?? ''), '#d94a2b'),
        'theme' => json_encode($theme, JSON_THROW_ON_ERROR),
        'contact' => json_encode($contact, JSON_THROW_ON_ERROR),
        'updated_by' => (int)$user['id'],
    ]);
    app_audit($pdo, $organizationId, (int)$user['id'], 'brand.updated', 'brand_settings', (string)$organizationId, null, ['logo_file_id' => $logoId, 'cover_file_id' => $coverId]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_json_response(['ok' => false, 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Brand settings could not be saved.'], $error instanceof InvalidArgumentException ? 422 : 500);
}

app_json_response(['ok' => true, 'message' => 'Brand settings saved.', 'brand' => brand_payload($pdo, $organizationId)]);
