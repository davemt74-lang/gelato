<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/first-owner-setup.php';
require_once __DIR__ . '/../includes/UpgradeService.php';
require_once __DIR__ . '/../includes/food-monster-installation.php';

try {
    $bytes = stream_get_contents(STDIN, 16385);
    if ($bytes === false || strlen($bytes) > 16384) {
        throw new InvalidArgumentException('Invalid provisioning request.');
    }
    $input = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    $profile = $input['profile'] ?? null;
    $connection = $input['connection'] ?? null;
    $expected = app_config()['food_monster_hosted']['installation_id'] ?? '';
    if (!is_array($profile) || !is_array($connection) || !is_string($expected)
        || !preg_match('/^[a-f0-9]{32}$/D', $expected) || ($connection['installation_id'] ?? null) !== $expected
        || !is_string($connection['instance_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $connection['instance_id'])
        || !is_string($connection['key'] ?? null) || !preg_match('/^fm1_[a-f0-9]{64}$/D', $connection['key'])) {
        throw new InvalidArgumentException('This command requires a managed installation and its scoped credential.');
    }
    foreach (['restaurant_name' => 145, 'legal_name' => 160, 'location_name' => 120, 'first_name' => 60, 'last_name' => 60, 'timezone' => 80, 'email' => 254] as $key => $max) {
        $value = $profile[$key] ?? null;
        if (!is_string($value) || mb_strlen($value) > $max || ($value === '' && $key !== 'legal_name')) {
            throw new InvalidArgumentException('A required owner profile field is invalid.');
        }
    }
    if (!filter_var($profile['email'], FILTER_VALIDATE_EMAIL) || !in_array($profile['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
        || !is_string($profile['password_hash'] ?? null) || !in_array(password_get_info($profile['password_hash'])['algoName'], ['argon2id', 'bcrypt'], true)) {
        throw new InvalidArgumentException('The owner identity or password hash is invalid.');
    }

    $pdo = app_pdo();
    if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() === 0) {
        $sql = file_get_contents(RESTAURANT_APP_ROOT . '/database/install.sql');
        if ($sql === false) {
            throw new RuntimeException('The Gelato database archive is incomplete.');
        }
        UpgradeService::executeSqlScript($pdo, $sql);
    }
    if (!app_schema_is_installed($pdo)) {
        throw new RuntimeException('The target database has an incomplete Gelato schema.');
    }

    $upgrader = new UpgradeService($pdo, RESTAURANT_APP_ROOT);
    $upgrader->ensureTrackingTables();
    $upgrader->bootstrapLegacyHistory(null);
    $upgrader->applyPending(null);
    $upgrader->assertAppliedChecksumsUnchanged();

    $owner = gelato_seed_first_owner($pdo, $profile, $profile['password_hash'], ($profile['email_verified'] ?? false) === true, true);
    $connection['organization_id'] = $owner['organization_id'];
    $private = app_private_storage_root();
    $directory = app_private_storage_directory($private, 'food-monster/connection.json');
    $existing = fmi_read_connection('connection.json');
    if ($existing !== null && $existing !== $connection) {
        throw new RuntimeException('This installation already has a different Food Monster connection.');
    }
    if ($existing === null) {
        fmi_save_connection($directory, 'connection.json', $connection);
    }
    echo json_encode(['ok' => true, 'version' => fmi_version(), 'owner_id' => $owner['user_id']], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Gelato hosted provisioning failed (" . get_class($error) . "). Check the isolated target database and package.\n");
    exit(1);
}
