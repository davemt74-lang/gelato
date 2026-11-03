<?php
declare(strict_types=1);

require_once __DIR__ . '/private-storage.php';

final class FoodMonsterInstallationError extends RuntimeException {}

function fmi_owner(array $user): bool
{
    return (int)($user['id'] ?? 0) > 0 && (int)($user['organization_id'] ?? 0) > 0
        && (int)($user['is_owner_role'] ?? 0) === 1;
}

function fmi_version(): ?string
{
    $path = dirname(__DIR__) . '/GELATO_VERSION.txt';
    $version = is_file($path) ? trim((string)file_get_contents($path)) : '';
    return preg_match('/^(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})(?:-(?:dev|alpha|beta|rc)\.(?:0|[1-9][0-9]{0,8}))?$/D', $version)
        ? $version : null;
}

function fmi_domain(string $url): string
{
    $parts = parse_url($url);
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
        || !str_contains($host, '.') || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        || filter_var($host, FILTER_VALIDATE_IP)) {
        throw new FoodMonsterInstallationError('Set Gelato’s configured site URL to the registered HTTPS domain.');
    }
    return $host;
}

function fmi_read_connection(string $file): ?array
{
    $path = app_private_storage_existing(app_private_storage_root(false), 'food-monster/' . $file);
    if ($path === null) {
        return null;
    }
    $value = json_decode((string)file_get_contents($path, false, null, 0, 2049), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($value)
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($value['installation_id'] ?? ''))
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($value['instance_id'] ?? ''))
        || !preg_match('/^fm1_[a-f0-9]{64}$/D', (string)($value['key'] ?? ''))
        || (int)($value['organization_id'] ?? 0) < 1) {
        throw new FoodMonsterInstallationError('Gelato could not read the saved Food Monster connection.');
    }
    return $value;
}

function fmi_save_connection(string $directory, string $file, array $connection): void
{
    $temporary = $directory . '/.connection-' . bin2hex(random_bytes(16));
    $target = $directory . '/' . $file;
    $json = json_encode($connection, JSON_THROW_ON_ERROR);
    $handle = fopen($temporary, 'xb');
    if ($handle === false) {
        throw new FoodMonsterInstallationError('Gelato could not create the private connection file.');
    }
    try {
        $written = chmod($temporary, 0600) && fwrite($handle, $json) === strlen($json) && fflush($handle);
    } finally {
        fclose($handle);
    }
    if (!$written || is_link($target) || !rename($temporary, $target)) {
        @unlink($temporary);
        throw new FoodMonsterInstallationError('Gelato could not save the private Food Monster connection.');
    }
}

function fmi_activate(array $user, string $installationId, string $key): array
{
    if (!fmi_owner($user)) {
        throw new DomainException('Only the protected Gelato owner can activate a license.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $installationId) || !preg_match('/^fm1_[a-f0-9]{64}$/D', $key)) {
        throw new FoodMonsterInstallationError('Enter the installation ID and activation key from the Food Monster portal.');
    }
    $organizationId = (int)$user['organization_id'];
    $root = app_private_storage_root();
    $directory = app_private_storage_directory($root, 'food-monster/connection.json');
    $lockPath = $directory . '/.connection.lock';
    $lock = is_link($lockPath) ? false : fopen($lockPath, 'c+b');
    if ($lock === false) {
        throw new FoodMonsterInstallationError('Gelato could not lock its Food Monster connection.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new FoodMonsterInstallationError('Gelato could not lock its Food Monster connection.');
        }
        $current = fmi_read_connection('connection.json');
        $pending = fmi_read_connection('pending.json');
        $owner = $current['organization_id'] ?? $pending['organization_id'] ?? $organizationId;
        if ($owner !== $organizationId) {
            throw new FoodMonsterInstallationError('This Food Monster connection is managed by another organization.');
        }
        $connection = [
            'organization_id' => $organizationId,
            'installation_id' => $installationId,
            'instance_id' => $current['instance_id'] ?? $pending['instance_id'] ?? bin2hex(random_bytes(16)),
            'key' => $key,
        ];
        // Keep a stable instance ID even if central activation succeeds and its response is lost.
        fmi_save_connection($directory, 'pending.json', $connection);
        try {
            $status = fmi_request($connection, 'activate');
        } catch (FoodMonsterInstallationError $error) {
            if (in_array($error->getCode(), [401, 403, 409, 422], true)) {
                @unlink($directory . '/pending.json');
            }
            throw $error;
        }
        fmi_save_connection($directory, 'connection.json', $connection);
        @unlink($directory . '/pending.json');
        return $status;
    } finally {
        fclose($lock);
    }
}

function fmi_request(array $connection, string $action, array $fields = [], ?callable $transport = null): array
{
    $version = fmi_version();
    $body = [
        'action' => $action, 'installation_id' => $connection['installation_id'], 'instance_id' => $connection['instance_id'],
        'domain' => fmi_domain((string)(app_config()['app']['url'] ?? '')), 'installed_version' => $version,
    ] + $fields;
    $transport ??= fmi_http(...);
    [$code, $response] = $transport($connection['key'], $body);
    $decoded = json_decode($response, true, 16);
    if (!is_array($decoded) || $code !== 200 || ($decoded['ok'] ?? null) !== true) {
        $reason = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;
        throw new FoodMonsterInstallationError(match ($reason) {
            'invalid_credential' => 'Food Monster rejected the saved credential. Generate a new key in the portal and reconnect.',
            'domain_mismatch' => 'Gelato’s configured hostname does not match the Food Monster registration.',
            'activation_required', 'activation_unavailable' => 'Confirm that the central license and verified installation are both active.',
            'attempt_not_found', 'attempt_finished' => 'This update attempt has already finished or belongs to a different connection.',
            'version_mismatch' => 'The deployed Gelato version does not match the approved update.',
            default => 'Food Monster licensing could not be verified right now.',
        }, $code);
    }
    $data = $decoded['data'] ?? null;
    if (!is_array($data) || ($data['installation_id'] ?? null) !== $connection['installation_id']
        || ($data['domain'] ?? null) !== $body['domain']
        || !in_array($data['license_state'] ?? null, ['pending', 'active', 'suspended', 'expired', 'revoked'], true)) {
        throw new FoodMonsterInstallationError('Food Monster returned an unrecognized installation status.');
    }
    return $data;
}

function fmi_http(string $key, array $body): array
{
    if (!function_exists('curl_init')) {
        throw new FoodMonsterInstallationError('Enable PHP cURL to connect to Food Monster licensing.');
    }
    $curl = curl_init('https://foodmonster.xyz/api/installation.php');
    if ($curl === false) {
        throw new FoodMonsterInstallationError('Food Monster licensing could not be reached.');
    }
    $result = '';
    try {
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$result): int {
                if (strlen($result) + strlen($chunk) > 131072) {
                    return 0;
                }
                $result .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (curl_exec($curl) === false) {
            throw new FoodMonsterInstallationError('Food Monster licensing could not be reached right now.');
        }
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $result];
    } finally {
        curl_close($curl);
    }
}

function fmi_status(array $user): array
{
    if (!fmi_owner($user)) {
        throw new DomainException('Only the protected Gelato owner can review this installation.');
    }
    $connection = fmi_read_connection('connection.json');
    if ($connection === null || $connection['organization_id'] !== (int)$user['organization_id']) {
        return ['connection' => 'unconfigured', 'status' => null];
    }
    try {
        return ['connection' => 'connected', 'status' => fmi_request($connection, 'status')];
    } catch (FoodMonsterInstallationError $error) {
        return ['connection' => 'unavailable', 'status' => null, 'message' => $error->getMessage()];
    }
}

function fmi_report(array $user, string $attemptId, string $state, string $failureCode): array
{
    if (!fmi_owner($user)) {
        throw new DomainException('Only the protected Gelato owner can report a release outcome.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $attemptId) || !in_array($state, ['completed', 'failed'], true)) {
        throw new FoodMonsterInstallationError('Select a valid update attempt and outcome.');
    }
    $connection = fmi_read_connection('connection.json');
    if ($connection === null || $connection['organization_id'] !== (int)$user['organization_id']) {
        throw new FoodMonsterInstallationError('Connect this Gelato installation to Food Monster first.');
    }
    return fmi_request($connection, 'report', ['attempt_id' => $attemptId, 'state' => $state, 'failure_code' => $failureCode]);
}
