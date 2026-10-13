<?php
declare(strict_types=1);
require __DIR__ . '/../includes/UpgradeService.php';

final class FkDiagnosticPdo extends PDO {
    public array $metadata = [
        'organizations.id' => ['column_type' => 'int unsigned', 'collation_name' => null],
    ];
    public function __construct() {}
    public function exec(string $statement): int|false {
        $e = new PDOException('SQLSTATE[HY000]: General error: 1215 Cannot add foreign key constraint');
        $e->errorInfo = ['HY000', 1215, 'Cannot add foreign key constraint'];
        throw $e;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new FkDiagnosticStatement($this, $query);
    }
}
final class FkDiagnosticStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private FkDiagnosticPdo $db, private string $query) {}
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed {
        return $this->params[0] === 'organizations' ? 'InnoDB' : false;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->db->metadata[implode('.', $this->params)] ?? false;
    }
}
$sql = 'CREATE TABLE IF NOT EXISTS demo_child (id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_demo_org FOREIGN KEY (organization_id) REFERENCES organizations(id)) ENGINE=InnoDB;';
try {
    UpgradeService::executeSqlScript(new FkDiagnosticPdo(), $sql, true);
    throw new RuntimeException('Expected FK rejection');
} catch (PDOException $e) {
    if (!str_contains($e->getMessage(), 'fk_demo_org')
        || !str_contains(strtolower($e->getMessage()), 'bigint unsigned')
        || !str_contains($e->getMessage(), 'int unsigned')) {
        throw new RuntimeException('Missing actionable FK mismatch diagnostics: ' . $e->getMessage());
    }
}
echo "mysql-foreign-key-diagnostics=ok\n";
