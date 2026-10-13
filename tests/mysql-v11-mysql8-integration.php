<?php
declare(strict_types=1);

require __DIR__ . '/../includes/UpgradeService.php';

function checkMysqlV11(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("MySQL v11 failed: {$label}");
    }
}

$db = new PDO(
    sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', getenv('MYSQL_HOST') ?: '127.0.0.1', getenv('MYSQL_DATABASE') ?: 'gelato_v11_fk_ci'),
    getenv('MYSQL_USER') ?: 'root',
    getenv('MYSQL_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$schema = [
    'organizations' => 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
    'users' => 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
    'glasses_vision_training_media' => 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
    'glasses_vision_training_samples' => 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
    'glasses_vision_sample_reviews' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, sample_id BIGINT UNSIGNED NOT NULL, reviewer_user_id BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), UNIQUE KEY uq_glasses_vision_sample_review_user (sample_id,reviewer_user_id)',
];
foreach ($schema as $table => $columns) {
    $db->exec("CREATE TABLE {$table} ({$columns}) ENGINE=InnoDB");
}
$migration = dirname(__DIR__) . '/database/20261129_v11_annotation_corrections.sql';
checkMysqlV11(hash_file('sha256', $migration) === 'ed0acce6920dca3705814e2898713fc192112f5ef9c074f0db2b15d2f020b1c4', 'historical migration checksum');
$sql = (string)file_get_contents($migration);
checkMysqlV11(UpgradeService::executeSqlScript($db, $sql, true) === 3, 'fresh install complete');

function verifyMysqlV11(PDO $db, string $scenario): void
{
    $query = $db->query("SELECT extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='glasses_vision_sample_reviews' AND column_name='correction_scope_id'");
    checkMysqlV11(str_contains(strtoupper((string)$query->fetchColumn()), 'VIRTUAL GENERATED'), "{$scenario}: virtual scope");
    $query = $db->query("SELECT delete_rule FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND constraint_name='fk_glasses_vision_sample_review_correction'");
    checkMysqlV11(strtoupper((string)$query->fetchColumn()) === 'CASCADE', "{$scenario}: delete cascade");
    $query = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='glasses_vision_sample_reviews' AND index_name='uq_glasses_vision_sample_review_scope_user'");
    checkMysqlV11((int)$query->fetchColumn() === 3, "{$scenario}: scoped 3-column unique key");
}
verifyMysqlV11($db, 'fresh');

$db->exec("INSERT INTO organizations VALUES (1)");
$db->exec("INSERT INTO users VALUES (1)");
$db->exec("INSERT INTO glasses_vision_training_media VALUES (1)");
$db->exec("INSERT INTO glasses_vision_training_samples VALUES (1)");
$db->exec("INSERT INTO glasses_vision_annotation_corrections (organization_id, public_id, media_id, sample_id, correction_key, correction_type, reason, submitted_by, immutable_hash) VALUES (1, 'c1', 1, 1, 'k1', 'label', 'reason', 1, REPEAT('a',64))");
$db->exec("INSERT INTO glasses_vision_sample_reviews (organization_id,sample_id,reviewer_user_id,correction_id) VALUES (1,1,1,1)");
checkMysqlV11((int)$db->query('SELECT COUNT(*) FROM glasses_vision_sample_reviews')->fetchColumn() === 1, 'review inserted');

// Simulate the precise production partial state: STORED generated column
// and scoped unique index exist, but the cascade FK failed to apply.
$db->exec("ALTER TABLE glasses_vision_sample_reviews DROP FOREIGN KEY fk_glasses_vision_sample_review_correction");
$db->exec("ALTER TABLE glasses_vision_sample_reviews DROP INDEX uq_glasses_vision_sample_review_scope_user, DROP COLUMN correction_scope_id, ADD COLUMN correction_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(correction_id,0)) STORED, ADD UNIQUE KEY uq_glasses_vision_sample_review_scope_user (sample_id,correction_scope_id,reviewer_user_id)");
checkMysqlV11(UpgradeService::executeSqlScript($db, $sql, true) === 3, 'partial STORED recovery succeeds');
verifyMysqlV11($db, 'partial STORED');
checkMysqlV11((int)$db->query('SELECT COUNT(*) FROM glasses_vision_sample_reviews')->fetchColumn() === 1, 'existing review preserved');

checkMysqlV11(UpgradeService::executeSqlScript($db, $sql, true) === 3, 'repeat upgrade succeeds');
verifyMysqlV11($db, 'retry');
$db->exec('DELETE FROM glasses_vision_annotation_corrections WHERE id=1');
checkMysqlV11((int)$db->query('SELECT COUNT(*) FROM glasses_vision_sample_reviews')->fetchColumn() === 0, 'foreign-key cascading delete is functional');
echo "mysql-v11-mysql8-integration=ok\n";
