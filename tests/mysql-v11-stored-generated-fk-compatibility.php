<?php
declare(strict_types=1);
require __DIR__ . '/../includes/UpgradeService.php';

final class V11FkTestPdo extends PDO
{
    public array $columns = ['correction_id' => ['extra' => '', 'generation_expression' => '']];
    public array $indexes = ['uq_glasses_vision_sample_review_user' => true];
    public array $fks = [];
    public array $sql = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new V11FkTestStatement($this, $query);
    }
    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS')) return 0;
        if (!str_starts_with($statement, 'ALTER TABLE glasses_vision_sample_reviews')) {
            throw new RuntimeException('Unexpected SQL: ' . $statement);
        }
        // Stage the full ALTER, and commit only after FK checks succeed.
        $columns = $this->columns;
        $indexes = $this->indexes;
        $fks = $this->fks;
        if (str_contains($statement, 'DROP INDEX uq_glasses_vision_sample_review_scope_user')) {
            unset($indexes['uq_glasses_vision_sample_review_scope_user']);
        }
        if (str_contains($statement, 'DROP INDEX uq_glasses_vision_sample_review_user')) {
            unset($indexes['uq_glasses_vision_sample_review_user']);
        }
        if (str_contains($statement, 'DROP COLUMN correction_scope_id')) {
            unset($columns['correction_scope_id']);
        }
        if (preg_match('/ADD COLUMN correction_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS \(COALESCE\(correction_id,0\)\) (STORED|VIRTUAL)/i', $statement, $m)) {
            $columns['correction_scope_id'] = ['extra' => strtoupper($m[1]) . ' GENERATED', 'generation_expression' => 'coalesce('.chr(96).'correction_id'.chr(96).',0)'];
        }
        if (str_contains($statement, 'ADD UNIQUE KEY uq_glasses_vision_sample_review_scope_user')) {
            if (!isset($columns['correction_scope_id'])) throw new RuntimeException('Scope index before column');
            $indexes['uq_glasses_vision_sample_review_scope_user'] = true;
        }
        if (str_contains($statement, 'ADD KEY idx_glasses_vision_sample_review_correction')) {
            $indexes['idx_glasses_vision_sample_review_correction'] = true;
        }
        if (str_contains($statement, 'ADD CONSTRAINT fk_glasses_vision_sample_review_correction')) {
            if (($columns['correction_scope_id']['extra'] ?? '') === 'STORED GENERATED') {
                $error = new PDOException('SQLSTATE[HY000]: General error: 1215 Cannot add foreign key constraint');
                $error->errorInfo = ['HY000',1215,'Cannot add foreign key constraint'];
                throw $error;
            }
            $fks['fk_glasses_vision_sample_review_correction'] = true;
        }
        $this->columns = $columns;
        $this->indexes = $indexes;
        $this->fks = $fks;
        return 0;
    }
}
final class V11FkTestStatement extends PDOStatement
{
    private array $params = [];
    public function __construct(private V11FkTestPdo $db, private string $query) {}
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed
    {
        $name = $this->params[1] ?? '';
        if (str_contains($this->query, 'information_schema.statistics')) return isset($this->db->indexes[$name]) ? 1 : 0;
        if (str_contains($this->query, 'information_schema.table_constraints')) return isset($this->db->fks[$name]) ? 1 : 0;
        if (str_contains($this->query, 'information_schema.columns')) return isset($this->db->columns[$name]) ? 1 : 0;
        throw new RuntimeException('Unexpected metadata query');
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->columns['correction_scope_id'] ?? false;
    }
}
function v11Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$file = dirname(__DIR__) . '/database/20261129_v11_annotation_corrections.sql';
$historicalSql = (string)file_get_contents($file);
v11Assert(str_contains($historicalSql, 'STORED AFTER correction_id'), 'Historical migration SQL must remain unchanged');
foreach (['fresh','stored','stored_indexed','virtual_indexed'] as $scenario) {
    $db = new V11FkTestPdo();
    if (str_starts_with($scenario, 'stored')) {
        $db->columns['correction_scope_id'] = ['extra' => 'STORED GENERATED', 'generation_expression' => 'coalesce('.chr(96).'correction_id'.chr(96).',0)'];
    }
    if (str_starts_with($scenario, 'virtual')) {
        $db->columns['correction_scope_id'] = ['extra' => 'VIRTUAL GENERATED', 'generation_expression' => 'coalesce('.chr(96).'correction_id'.chr(96).',0)'];
    }
    if (str_ends_with($scenario, '_indexed')) {
        $db->indexes['uq_glasses_vision_sample_review_scope_user'] = true;
    }
    v11Assert(UpgradeService::executeSqlScript($db, $historicalSql, true) === 3, "$scenario statement count");
    v11Assert(($db->columns['correction_scope_id']['extra'] ?? '') === 'VIRTUAL GENERATED', "$scenario virtual scope");
    v11Assert(isset($db->indexes['uq_glasses_vision_sample_review_scope_user']), "$scenario new unique index");
    v11Assert(!isset($db->indexes['uq_glasses_vision_sample_review_user']), "$scenario old unique index retired");
    v11Assert(isset($db->fks['fk_glasses_vision_sample_review_correction']), "$scenario cascade foreign key");
    $previous = count($db->sql);
    v11Assert(UpgradeService::executeSqlScript($db, $historicalSql, true) === 3, "$scenario retry count");
    v11Assert(count($db->sql) === $previous + 2, "$scenario retry skips all review DDL");
}
$unexpected = new V11FkTestPdo();
$unexpected->columns['correction_scope_id'] = ['extra' => '', 'generation_expression' => ''];
try {
    UpgradeService::executeSqlScript($unexpected, $historicalSql, true);
    throw new RuntimeException('Unexpected scope definition incorrectly accepted');
} catch (RuntimeException $e) {
    v11Assert(str_contains($e->getMessage(), 'Unexpected correction_scope_id'), 'Unexpected schema fails safely');
}
echo "mysql-v11-stored-generated-fk-compatibility=ok\n";
