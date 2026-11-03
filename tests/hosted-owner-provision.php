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

function hosted_probe(string $token, string $https): array
{
    $command = [getenv('GELATO_TEST_PHP') ?: PHP_BINARY, '-r',
        '$_SERVER += ["REQUEST_METHOD"=>"GET","HTTPS"=>$argv[1],"HTTP_X_FOOD_MONSTER_HEALTH"=>$argv[2]];
         register_shutdown_function(static function (): void {fwrite(STDERR, (string)(http_response_code() ?: 200));});
         require $argv[3];', $https, $token, RESTAURANT_APP_ROOT . '/food-monster-hosted-health.php'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    hosted_check(is_resource($process), 'Could not start the web-runtime probe fixture.');
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    hosted_check(proc_close($process) === 0, 'The web-runtime probe crashed.');
    return [(int)$status, $body];
}

$pdo = app_pdo();
hosted_check((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() === 0,
    'Use a new isolated database.');
$installation = app_config()['food_monster_hosted']['installation_id'];
$probe = str_repeat('3', 64);
hosted_check(hosted_probe($probe, 'on') === [503, ''], 'An empty database passed readiness.');
$password = 'Kitchen-Fixture!2026';
$payload = [
    'profile' => ['restaurant_name'=>'Fixture Pizza','legal_name'=>'Fixture Foods LLC','location_name'=>'North Kitchen',
        'first_name'=>'Alex','last_name'=>'Owner','timezone'=>'America/Phoenix','email'=>'owner@example.test','email_verified'=>false,
        'password_hash'=>app_password_hash($password)],
    'connection' => ['installation_id'=>$installation,'instance_id'=>str_repeat('2',32),'key'=>'fm1_' . str_repeat('a',64)],
];
$pdo->exec('CREATE TABLE gelato_hosted_bootstrap (installation_id CHAR(32) PRIMARY KEY, seed_complete TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
$pdo->prepare('INSERT INTO gelato_hosted_bootstrap (installation_id) VALUES (?)')->execute([str_repeat('9', 32)]);
[$code] = hosted_run($payload);
hosted_check($code !== 0 && !app_schema_is_installed($pdo), 'A different installation reused the bootstrap journal.');
$pdo->prepare('UPDATE gelato_hosted_bootstrap SET installation_id = ?')->execute([$installation]);
$baseline = file_get_contents(RESTAURANT_APP_ROOT . '/database/install.sql');
hosted_check(is_string($baseline) && preg_match('/CREATE TABLE organizations \(.*?;\n/s', $baseline, $firstTable) === 1,
    'Could not load the first baseline table for the interruption fixture.');
$pdo->exec($firstTable[0]);
[$code,$output,$error] = hosted_run($payload);
hosted_check($code === 0 && $error === '', 'Provisioning failed. ' . $error);
hosted_check((int)$pdo->query('SELECT seed_complete FROM gelato_hosted_bootstrap')->fetchColumn() === 1,
    'A partial baseline import did not finish its seed transaction.');
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
hosted_check(hosted_probe($probe, 'on') === [200, $installation . "\n" . fmi_version()], 'The initialized web runtime did not pass readiness.');
hosted_check(hosted_probe(str_repeat('4', 64), 'on') === [404, ''] && hosted_probe($probe, 'off') === [404, ''],
    'The readiness endpoint accepted a wrong credential or plaintext transport.');
$path = app_private_storage_root(false) . '/food-monster/connection.json';
hosted_check((fileperms($path) & 0777) === 0600, 'The connection file needs private permissions.');
$payload['profile']['password_hash'] = app_password_hash('Another-Fixture!2026');
[$code] = hosted_run($payload);
hosted_check($code !== 0 && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'A conflicting owner was accepted.');
echo "Hosted Gelato owner and license provisioning passed.\n";
