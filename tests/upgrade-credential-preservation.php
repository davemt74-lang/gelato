<?php
declare(strict_types=1);
require __DIR__ . '/../includes/UpgradeService.php';

final class CredentialGuardTestPdo extends PDO {
    public array $executed = [];
    public function __construct() {}
    public function exec(string $statement): int|false {
        $this->executed[] = $statement;
        return 0;
    }
}
function credentialAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Credential safety: ' . $message);
}
$db = new CredentialGuardTestPdo();
$forbidden = [
    "UPDATE users SET password_hash='compromised' WHERE id=1;",
    "UPDATE `users` SET locked_until = NOW() WHERE id=1;",
    "INSERT INTO users(email,password_hash) VALUES ('a@b.c','bad');",
    "REPLACE INTO users(id,email,password_hash) VALUES (1,'a@b.c','bad');",
    "DELETE FROM users WHERE id=1;",
    "TRUNCATE TABLE users;",
    "DROP TABLE IF EXISTS users;",
    "ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(10);",
    "UPDATE `gelato_ci`.`users` SET password_hash='bad' WHERE id=1;",
];
foreach ($forbidden as $sql) {
    $before = count($db->executed);
    try {
        UpgradeService::executeSqlScript($db, $sql, true);
        throw new RuntimeException('Unsafe migration unexpectedly accepted: ' . $sql);
    } catch (RuntimeException $e) {
        credentialAssert(str_contains($e->getMessage(), 'Upgrade blocked:'), 'must reject credential change: ' . $sql);
    }
    credentialAssert(count($db->executed) === $before, 'unsafe statement reached PDO');
}
credentialAssert(UpgradeService::executeSqlScript($db, 'ALTER TABLE users ADD COLUMN preferred_locale VARCHAR(12) NULL;', true) === 1, 'non-auth users schema change allowed');
credentialAssert(UpgradeService::executeSqlScript($db, 'SELECT id,password_hash FROM users;', true) === 1, 'read-only credentials query allowed');
credentialAssert(count($db->executed) === 2, 'safe SQL executed');
$root = dirname(__DIR__);
$recovery = file_get_contents($root . '/admin-password-recovery.php');
credentialAssert(is_string($recovery), 'recovery form exists');
credentialAssert(str_contains($recovery, "admin_recovery_key_sha256"), 'recoverable only with operator key');
credentialAssert(!str_contains($recovery, '44306c84e8a891787d8c08006616a9ac0f03123ad1449497dac6dc5ef77c9e01'), 'historical static key must not be redeployed');
credentialAssert(str_contains($recovery, 'http_response_code(404)'), 'recovery disabled by default');
credentialAssert(str_contains($recovery, "password_hash = :password_hash"), 'password reset form still updates credentials after authorization');
credentialAssert(str_contains($recovery, 'app_verify_csrf'), 'CSRF required');
credentialAssert(str_contains($recovery, 'hash_equals'), 'timing-safe recovery key comparison');
credentialAssert(str_contains($recovery, "used_at"), 'single-use key ledger required');
echo "upgrade-credential-preservation=ok\n";
