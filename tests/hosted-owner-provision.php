<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/food-monster-installation.php';

function hosted_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hosted_run(array $payload): array
{
    $command = [getenv('GELATO_TEST_PHP') ?: PHP_BINARY, RESTAURANT_APP_ROOT . '/scripts/food-monster-provision.php'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    hosted_check(is_resource($process), 'Could not start the CLI fixture.');
    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

$pdo = app_pdo();
hosted_check((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() === 0,
    'Use a new isolated database.');
$installation = app_config()['food_monster_hosted']['installation_id'];
$password = 'Kitchen-Fixture!2026';
$payload = [
    'profile' => ['restaurant_name'=>'Fixture Pizza','legal_name'=>'Fixture Foods LLC','location_name'=>'North Kitchen',
        'first_name'=>'Alex','last_name'=>'Owner','timezone'=>'America/Phoenix','email'=>'owner@example.test','email_verified'=>false,
        'password_hash'=>app_password_hash($password)],
    'connection' => ['installation_id'=>$installation,'instance_id'=>str_repeat('2',32),'key'=>'fm1_' . str_repeat('a',64)],
];
[$code,$output,$error] = hosted_run($payload);
hosted_check($code === 0 && $error === '', 'Provisioning failed. ' . $error);
$first = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
hosted_check(($first['ok'] ?? false) === true && ($first['version'] ?? null) === fmi_version(), 'A supported version was not reported.');
hosted_check(!str_contains($output, $password) && !str_contains($output, $payload['profile']['password_hash'])
    && !str_contains($output, $payload['connection']['key']), 'The worker exposed credentials.');
[$code,$retry] = hosted_run($payload);
hosted_check($code === 0 && $retry === $output, 'A retry created a different owner.');
$user = $pdo->query('SELECT email, password_hash, email_verified_at FROM users')->fetch();
hosted_check($user['email'] === 'owner@example.test' && password_verify($password, $user['password_hash']) && $user['email_verified_at'] === null,
    'The supplied owner identity or verified-email state did not persist.');
hosted_check(app_owner_exists($pdo) && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Expected one protected owner.');
$organization = $pdo->query('SELECT name, timezone FROM organizations')->fetch();
hosted_check($organization === ['name'=>'Fixture Pizza','timezone'=>'America/Phoenix'], 'Restaurant identity or timezone did not persist.');
hosted_check($pdo->query('SELECT name FROM locations')->fetchColumn() === 'North Kitchen', 'First location did not persist.');
$connection = fmi_read_connection('connection.json');
hosted_check($connection === $payload['connection'] + ['organization_id'=>1], 'The scoped license connection did not persist.');
$path = app_private_storage_root(false) . '/food-monster/connection.json';
hosted_check((fileperms($path) & 0777) === 0600, 'The connection file needs private permissions.');
$payload['profile']['password_hash'] = app_password_hash('Another-Fixture!2026');
[$code] = hosted_run($payload);
hosted_check($code !== 0 && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'A conflicting owner was accepted.');
echo "Hosted Gelato owner and license provisioning passed.\n";
