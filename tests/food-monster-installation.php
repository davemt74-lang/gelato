<?php
declare(strict_types=1);

define('RESTAURANT_APP_ROOT', dirname(__DIR__));
define('TEST_PRIVATE_ROOT', sys_get_temp_dir() . '/gelato-license-' . bin2hex(random_bytes(8)));

function app_config(): array
{
    return [
        'app' => ['url' => 'https://restaurant.example.test/gelato'],
        'storage' => ['private_root' => TEST_PRIVATE_ROOT, 'public_root' => RESTAURANT_APP_ROOT],
    ];
}

require __DIR__ . '/../includes/food-monster-installation-tools.php';

$count = 0;
function check_installation(bool $result, string $description): void
{
    global $count;
    if (!$result) {
        throw new RuntimeException($description);
    }
    ++$count;
}

$owner = ['id' => 3, 'organization_id' => 4, 'is_owner_role' => 1];
$employee = ['id' => 5, 'organization_id' => 4, 'is_owner_role' => 0, 'permissions' => ['*']];
check_installation(!fmi_owner($employee), 'Wildcard employee cannot access installation data.');
check_installation(fmi_agent_catalogue($employee, []) === [], 'Employee has no tools.');
check_installation(fmi_agent_catalogue($owner, ['channel' => 'voice']) === [], 'Unapproved voice has no tools.');
$tools = fmi_agent_catalogue($owner, ['channel' => 'voice', 'privateVoiceApproved' => true]);
check_installation(count($tools) === 2 && $tools['get_gelato_update_status']['impact'] === 'read', 'Only owner read tools are available.');
check_installation(!$tools['get_gelato_update_status']['authorize']($employee, []), 'Execution checks fresh owner authority.');
check_installation($tools['get_gelato_update_status']['execute']([], $employee)['ok'] === false, 'Execute also enforces owner authority.');
check_installation(fmi_version() !== null, 'Build version file is valid.');
foreach (['http://restaurant.example.test', 'https://127.0.0.1', 'https://user@restaurant.example.test'] as $url) {
    try {
        fmi_domain($url);
        check_installation(false, 'An insecure or unsuitable site URL was accepted.');
    } catch (FoodMonsterInstallationError) {
        check_installation(true, 'An insecure or unsuitable site URL was rejected.');
    }
}
$id = str_repeat('a', 32);
$key = 'fm1_' . str_repeat('c', 64);
$connection = ['installation_id' => $id, 'instance_id' => str_repeat('b', 32), 'key' => $key];
$directory = app_private_storage_directory(app_private_storage_root(), 'food-monster/connection.json');
$stored = ['organization_id' => 4] + $connection;
try {
    fmi_save_connection($directory, 'connection.json', $stored);
    check_installation(fmi_read_connection('connection.json') === $stored, 'Reads the saved private connection.');
    check_installation((fileperms($directory . '/connection.json') & 0777) === 0600, 'Saved credential has restrictive permissions.');
    check_installation(fmi_status(['organization_id' => 99] + $owner)['connection'] === 'unconfigured', 'A different organization cannot read the saved connection.');
} finally {
    unlink($directory . '/connection.json');
    rmdir($directory);
    rmdir(TEST_PRIVATE_ROOT);
}
$status = [
    'installation_id' => $id, 'domain' => 'restaurant.example.test', 'license_state' => 'active',
    'expires_at' => 1900000000, 'auto_update' => 'stable', 'available_release' => [
        'version' => '1.1.0', 'notes' => 'New release notes.',
    ], 'last_update' => ['state' => 'failed', 'error_summary' => 'Checksum mismatch.'],
];
$result = fmi_request($connection, 'status', [], static function (string $credential, array $payload) use ($key, $connection, $status): array {
    check_installation($credential === $key && !isset($payload['key']), 'Credential is separate from the JSON body.');
    check_installation($payload['installation_id'] === $connection['installation_id'] && $payload['instance_id'] === $connection['instance_id'], 'Bound installation and instance.');
    check_installation($payload['domain'] === 'restaurant.example.test' && $payload['installed_version'] === fmi_version(), 'Use configured domain and local build version.');
    return [200, json_encode(['ok' => true, 'data' => $status], JSON_THROW_ON_ERROR)];
});
check_installation($result === $status, 'Consumes the real central response envelope.');
foreach ([['domain' => 'different.example.test'], ['installation_id' => str_repeat('f', 32)], ['license_state' => 'unknown']] as $override) {
    try {
        fmi_request($connection, 'status', [], static fn () => [200, json_encode(['ok' => true, 'data' => $override + $status], JSON_THROW_ON_ERROR)]);
        check_installation(false, 'Accepted a mismatched status.');
    } catch (FoodMonsterInstallationError) {
        check_installation(true, 'Rejected a mismatched status.');
    }
}
$update = fmi_agent_answer('updates', '1.0.0', ['connection' => 'connected', 'status' => $status]);
check_installation(str_contains($update, '1.0.0') && str_contains($update, '1.1.0'), 'Answer distinguishes installed and available versions.');
check_installation(str_contains($update, 'New release notes') && str_contains($update, 'Checksum mismatch'), 'Answer explains notes and last failure.');
$expired = fmi_agent_answer('license', '1.0.0', ['connection' => 'connected', 'status' => ['license_state' => 'expired'] + $status]);
check_installation(str_contains($expired, 'operations remain available') && str_contains($expired, 'updates are paused'), 'License expiry preserves operational availability.');
$offline = fmi_agent_answer('updates', '1.0.0', ['connection' => 'unavailable', 'status' => null]);
check_installation(str_contains($offline, '1.0.0') && str_contains($offline, 'currently unavailable'), 'Offline status keeps the verified local version.');
echo "Gelato Food Monster installation: $count assertions passed.\n";
