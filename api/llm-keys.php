<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD'] === 'GET' ? 'llm_keys.view' : 'llm_keys.edit');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
$providers = [
    'anthropic' => 'Anthropic Claude Developer API',
    'openai' => 'OpenAI / ChatGPT API',
];

function llm_statuses(PDO $pdo, int $organizationId, array $providers): array
{
    try {
        $statement = $pdo->prepare('SELECT provider, display_name, key_last_four, status, last_verified_at, updated_at FROM llm_api_credentials WHERE organization_id = ?');
        $statement->execute([$organizationId]);
    } catch (PDOException $error) {
        app_json_response(['ok' => false, 'message' => 'The LLM-key migration has not been imported. Import database/20260803_brand_images_llm_keys.sql once.'], 409);
    }
    $records = [];
    foreach ($statement->fetchAll() as $row) {
        $records[$row['provider']] = $row;
    }
    return array_map(static function (string $displayName, string $provider) use ($records): array {
        $row = $records[$provider] ?? null;
        return [
            'provider' => $provider,
            'displayName' => $displayName,
            'configured' => (bool)$row,
            'maskedKey' => $row ? '••••••••••••' . $row['key_last_four'] : '',
            'status' => $row['status'] ?? 'not_configured',
            'lastVerifiedAt' => $row['last_verified_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }, $providers, array_keys($providers));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    app_json_response(['ok' => true, 'providers' => llm_statuses($pdo, $organizationId, $providers)]);
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
$input = app_json_input();
app_verify_request_csrf($input);
$provider = strtolower(trim((string)($input['provider'] ?? '')));
if (!isset($providers[$provider])) {
    app_json_response(['ok' => false, 'message' => 'Unsupported LLM provider.'], 422);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $statement = $pdo->prepare('DELETE FROM llm_api_credentials WHERE organization_id = ? AND provider = ?');
    $statement->execute([$organizationId, $provider]);
    app_audit($pdo, $organizationId, (int)$user['id'], 'llm_key.removed', 'llm_api_credentials', $provider);
    app_json_response(['ok' => true, 'message' => $providers[$provider] . ' key removed.', 'providers' => llm_statuses($pdo, $organizationId, $providers)]);
}

$apiKey = trim((string)($input['apiKey'] ?? ''));
if (strlen($apiKey) < 20 || strlen($apiKey) > 500) {
    app_json_response(['ok' => false, 'message' => 'Enter a valid provider API key.'], 422);
}
try {
    $encrypted = app_encrypt_secret($apiKey);
    $lastFour = substr($apiKey, -4);
    sodium_memzero($apiKey);
    $statement = $pdo->prepare(
        "INSERT INTO llm_api_credentials
         (organization_id, provider, display_name, encrypted_key, nonce, encryption_method, key_last_four, status, updated_by)
         VALUES (:organization_id, :provider, :display_name, :encrypted_key, :nonce, :method, :last_four, 'configured', :updated_by)
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), encrypted_key = VALUES(encrypted_key), nonce = VALUES(nonce),
           encryption_method = VALUES(encryption_method), key_last_four = VALUES(key_last_four), status = 'configured',
           last_verified_at = NULL, updated_by = VALUES(updated_by)"
    );
    $statement->execute([
        'organization_id' => $organizationId,
        'provider' => $provider,
        'display_name' => $providers[$provider],
        'encrypted_key' => $encrypted['ciphertext'],
        'nonce' => $encrypted['nonce'],
        'method' => $encrypted['method'],
        'last_four' => $lastFour,
        'updated_by' => (int)$user['id'],
    ]);
    app_audit($pdo, $organizationId, (int)$user['id'], 'llm_key.updated', 'llm_api_credentials', $provider, null, ['provider' => $provider, 'last_four' => $lastFour]);
} catch (Throwable $error) {
    app_json_response(['ok' => false, 'message' => 'The API key could not be encrypted and saved. Confirm that PHP Sodium is available and storage/ is writable.'], 500);
}
app_json_response(['ok' => true, 'message' => $providers[$provider] . ' key saved securely.', 'providers' => llm_statuses($pdo, $organizationId, $providers)]);
