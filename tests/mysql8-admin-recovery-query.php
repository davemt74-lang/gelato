<?php
declare(strict_types=1);

// Run after tests/mysql-v11-mysql8-integration.php in the MySQL 8 job.
$db = new PDO(
    sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', getenv('MYSQL_HOST') ?: '127.0.0.1', getenv('MYSQL_DATABASE') ?: 'gelato_v11_fk_ci'),
    getenv('MYSQL_USER') ?: 'root',
    getenv('MYSQL_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$source = (string)file_get_contents(__DIR__ . '/../admin-password-recovery.php');
if (!preg_match('/\\$adminStatement = \\$pdo->query\\(\\s*"([^"]+)"\\s*\\);/s', $source, $match)) {
    throw new RuntimeException('Recovery account selection query was not found');
}
$query = $match[1];
if (stripos($query, 'SELECT DISTINCT') !== false) {
    throw new RuntimeException('Recovery query reintroduced MySQL 8 DISTINCT ORDER BY failure');
}
$db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT 'owner@example.test', ADD COLUMN display_name VARCHAR(255) NOT NULL DEFAULT 'Owner', ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active', ADD COLUMN archived_at DATETIME NULL");
$db->exec("CREATE TABLE organization_memberships (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL)");
$db->exec("CREATE TABLE roles (id BIGINT UNSIGNED PRIMARY KEY, is_owner_role TINYINT NOT NULL, is_system_role TINYINT NOT NULL, slug VARCHAR(40) NOT NULL)");
$db->exec("CREATE TABLE user_roles (membership_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, revoked_at DATETIME NULL)");
$db->exec("INSERT INTO organization_memberships VALUES (1,1,1,'active')");
$db->exec("INSERT INTO roles VALUES (1,1,1,'super_admin')");
$db->exec("INSERT INTO user_roles VALUES (1,1,NULL)");
$record = $db->query($query)->fetch(PDO::FETCH_ASSOC);
if (!is_array($record) || (int)$record['id'] !== 1 || $record['email'] !== 'owner@example.test') {
    throw new RuntimeException('Admin recovery query failed to find active owner');
}
echo "mysql8-admin-recovery-page-query=ok\\n";
