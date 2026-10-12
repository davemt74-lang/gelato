<?php
declare(strict_types=1);
require __DIR__ . '/../includes/UpgradeService.php';

final class MigrationCompatPdo extends PDO
{
    public array $columns = [];
    public array $executed = [];
    public array $lookups = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MigrationCompatStatement($this, $query);
    }
    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        if (preg_match('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', $statement)) {
            throw new RuntimeException('Unnormalized SQL reached MySQL execution');
        }
        if (preg_match('/^ALTER\s+TABLE\s+\x60?([A-Za-z_][A-Za-z0-9_]*)\x60?\s+ADD\s+COLUMN\s+\x60?([A-Za-z_][A-Za-z0-9_]*)\x60?/i', $statement, $m)) {
            $this->columns[$m[1]][$m[2]] = true;
        }
        if (str_starts_with($statement, 'INVALID')) {
            throw new PDOException('Unsupported SQL is not silently skipped');
        }
        return 0;
    }
}

final class MigrationCompatStatement extends PDOStatement
{
    private array $params = [];
    public function __construct(private MigrationCompatPdo $pdo, private string $query) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        $this->pdo->lookups[] = [$this->query, $this->params];
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        return !empty($this->pdo->columns[$this->params[0] ?? ''][$this->params[1] ?? '']) ? 1 : 0;
    }
}

function assertMigration(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$sectionFile = $root . '/database/20261008_menu_manager_section_timestamp.sql';
$imagesFile = $root . '/database/20261009_canonical_media_images.sql';
assertMigration(hash_file('sha256', $sectionFile) === '808c4376b1c8e4b0b8f0146c232018edc3380210a01d5b02e937e801799cf88d', 'Original section migration checksum unchanged');
assertMigration(hash_file('sha256', $imagesFile) === 'a056db8fafac85176be48c69a610efe4bdc13d451d00b9be89f57690d5b49313', 'Original media migration checksum unchanged');

$pdo = new MigrationCompatPdo();
$count = UpgradeService::executeSqlScript($pdo, (string) file_get_contents($sectionFile), true);
assertMigration($count === 1, 'Section statement counted');
assertMigration(count($pdo->executed) === 1 && str_contains($pdo->executed[0], 'ADD COLUMN updated_at'), 'MySQL-compatible DDL emitted');
assertMigration(!str_contains($pdo->executed[0], 'IF NOT EXISTS'), 'Unsupported clause removed');
assertMigration(!empty($pdo->columns['menu_sections']['updated_at']), 'Column created');

$count = UpgradeService::executeSqlScript($pdo, (string) file_get_contents($sectionFile), true);
assertMigration($count === 1, 'Existing-column statement counted');
assertMigration(count($pdo->executed) === 1, 'Existing-column statement does not run');

$pdo = new MigrationCompatPdo();
$count = UpgradeService::executeSqlScript($pdo, (string) file_get_contents($imagesFile), true);
assertMigration($count === 7, 'All media statements counted');
foreach (['menu_items' => 'main_image_file_id', 'ingredients' => 'image_file_id', 'locations' => 'cover_image_file_id'] as $table => $column) {
    assertMigration(!empty($pdo->columns[$table][$column]), $table . ' column added');
}
assertMigration(count($pdo->lookups) === 3, 'Three precise metadata lookups used');
assertMigration(count(array_filter($pdo->executed, static fn(string $sql):bool => stripos($sql, 'IF NOT EXISTS') !== false)) === 0, 'No unsupported ADD COLUMN syntax executed');
$afterFirst = count($pdo->executed);
UpgradeService::executeSqlScript($pdo, (string) file_get_contents($imagesFile), true);
assertMigration(count($pdo->executed) === $afterFirst + 4, 'Retry skips all three column changes and runs ordinary SQL');
foreach ($pdo->lookups as [$query, $params]) {
    assertMigration(str_contains($query, 'table_schema=DATABASE()') && str_contains($query, 'table_name=?') && str_contains($query, 'column_name=?'), 'Metadata query is database-scoped and parameterized');
}
try {
    UpgradeService::executeSqlScript(new MigrationCompatPdo(), 'INVALID STATEMENT;', true);
    assertMigration(false, 'Invalid SQL must fail');
} catch (PDOException $expected) {}
echo "mysql-conditional-add-column-compatibility=ok\n";
