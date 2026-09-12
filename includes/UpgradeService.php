<?php
declare(strict_types=1);

/**
 * One-click, forward-only database migration runner for Gelato.
 *
 * Production migrations live in /database as YYYYMMDD_name.sql. Demo/sample/
 * seed files are deliberately excluded. Applied migrations are immutable and
 * tracked by SHA-256 checksum.
 */
final class UpgradeService
{
    public function __construct(private PDO $pdo, private string $root)
    {
        $this->root = rtrim($this->root, '/\\');
    }

    public function ensureTrackingTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(190) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            statement_count INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            adopted_existing TINYINT(1) NOT NULL DEFAULT 0,
            applied_by BIGINT UNSIGNED NULL,
            applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_key (migration_key),
            KEY idx_schema_migrations_applied (applied_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upgrade_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(190) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'running',
            statement_count INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            run_by BIGINT UNSIGNED NULL,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            completed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_upgrade_runs_started (started_at),
            KEY idx_upgrade_runs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<int,array<string,mixed>> */
    public function migrations(): array
    {
        $files = glob($this->root . '/database/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql') ?: [];
        $files = array_values(array_filter($files, static function (string $path): bool {
            $name = strtolower(basename($path));
            return !str_contains($name, '_demo_')
                && !str_contains($name, '_sample_')
                && !str_contains($name, '_seed_');
        }));
        sort($files, SORT_NATURAL);
        $files = $this->applyKnownDependencyOrder($files);

        $out = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                throw new RuntimeException('Could not read migration ' . $filename . '.');
            }
            $out[] = [
                'key' => preg_replace('/\.sql$/i', '', $filename) ?: $filename,
                'filename' => $filename,
                'path' => $path,
                'checksum' => hash('sha256', $raw),
                'bytes' => strlen($raw),
            ];
        }
        return $out;
    }

    /** @param array<int,string> $files @return array<int,string> */
    private function applyKnownDependencyOrder(array $files): array
    {
        // The visibility repair grants permissions created by the Knowledge Center
        // migration. Both share the same date, so filename sorting alone is unsafe.
        $knowledge = '20260804_public_agent_knowledge_center.sql';
        $visibility = '20260804_public_agent_admin_visibility_fix.sql';
        $knowledgePath = null;
        $visibilityPath = null;
        foreach ($files as $path) {
            if (basename($path) === $knowledge) $knowledgePath = $path;
            if (basename($path) === $visibility) $visibilityPath = $path;
        }
        if ($knowledgePath !== null && $visibilityPath !== null) {
            $files = array_values(array_filter(
                $files,
                static fn(string $path): bool => $path !== $visibilityPath
            ));
            $knowledgeIndex = array_search($knowledgePath, $files, true);
            if ($knowledgeIndex !== false) {
                array_splice($files, $knowledgeIndex + 1, 0, [$visibilityPath]);
            }
        }
        return $files;
    }

    /** @return array<string,array<string,mixed>> */
    public function appliedMap(): array
    {
        $this->ensureTrackingTables();
        $rows = $this->pdo->query(
            'SELECT migration_key,filename,checksum_sha256,statement_count,execution_ms,adopted_existing,applied_by,applied_at FROM schema_migrations ORDER BY id'
        )->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['migration_key']] = $row;
        }
        return $map;
    }

    /**
     * Older Gelato installations predate schema_migrations. Reliable schema
     * signatures let us recognize already-installed historical migrations
     * without replaying their DDL. Unknown/future migrations are never guessed.
     */
    public function bootstrapLegacyHistory(?int $userId = null): int
    {
        $this->ensureTrackingTables();
        $applied = $this->appliedMap();
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO schema_migrations
             (migration_key,filename,checksum_sha256,statement_count,execution_ms,adopted_existing,applied_by,applied_at)
             VALUES (?,?,?,?,?,1,?,NOW(6))'
        );
        $marked = 0;
        foreach ($this->migrations() as $migration) {
            $key = (string)$migration['key'];
            if (isset($applied[$key]) || !$this->hasKnownSignature($key)) {
                continue;
            }
            if (!$this->migrationAlreadyPresent($key)) {
                continue;
            }
            $insert->execute([
                $key, $migration['filename'], $migration['checksum'], 0, 0, $userId,
            ]);
            if ($insert->rowCount() > 0) {
                $marked++;
            }
        }
        return $marked;
    }

    /** @return array<int,array<string,mixed>> */
    public function migrationStatus(): array
    {
        $applied = $this->appliedMap();
        $status = [];
        foreach ($this->migrations() as $migration) {
            $row = $applied[$migration['key']] ?? null;
            $migration['status'] = $row ? 'applied' : 'pending';
            $migration['applied_at'] = $row['applied_at'] ?? null;
            $migration['adopted_existing'] = !empty($row['adopted_existing']);
            $migration['checksum_changed'] = $row
                ? !hash_equals((string)$row['checksum_sha256'], (string)$migration['checksum'])
                : false;
            $status[] = $migration;
        }
        return $status;
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingMigrations(): array
    {
        return array_values(array_filter(
            $this->migrationStatus(),
            static fn(array $migration): bool => $migration['status'] === 'pending'
        ));
    }

    public function currentVersion(): string
    {
        $applied = array_values(array_filter(
            $this->migrationStatus(),
            static fn(array $row): bool => $row['status'] === 'applied'
        ));
        if (!$applied) return 'base install';
        $last = end($applied);
        return (string)$last['key'];
    }

    public function targetVersion(): string
    {
        $migrations = $this->migrations();
        if (!$migrations) return 'base install';
        $last = end($migrations);
        return (string)$last['key'];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentRuns(int $limit = 12): array
    {
        $this->ensureTrackingTables();
        $limit = max(1, min(50, $limit));
        return $this->pdo->query('SELECT * FROM upgrade_runs ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function applyPending(?int $userId = null): array
    {
        $this->ensureTrackingTables();
        $this->bootstrapLegacyHistory($userId);
        $this->assertAppliedChecksumsUnchanged();

        $lockName = 'gelato_schema_upgrade';
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another Gelato database upgrade is already running.');
        }

        try {
            $results = [];
            foreach ($this->pendingMigrations() as $migration) {
                $results[] = $this->applyMigration($migration, $userId);
            }
            return $results;
        } finally {
            try {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string,mixed> */
    public function applyMigration(array $migration, ?int $userId = null): array
    {
        $raw = file_get_contents((string)$migration['path']);
        if (!is_string($raw)) {
            throw new RuntimeException('Could not read ' . $migration['filename'] . '.');
        }
        $key = (string)$migration['key'];

        $run = $this->pdo->prepare(
            "INSERT INTO upgrade_runs (migration_key,filename,status,run_by) VALUES (?,?,'running',?)"
        );
        $run->execute([$key, $migration['filename'], $userId]);
        $runId = (int)$this->pdo->lastInsertId();
        $started = microtime(true);

        try {
            if ($this->isUnsafePartialMigration($key)) {
                throw new RuntimeException(
                    'This migration appears partially applied. Resolve the partial schema before continuing so the upgrader does not guess at DDL state.'
                );
            }

            $statements = self::executeSqlScript($this->pdo, $raw, true);
            $ms = max(0, (int)round((microtime(true) - $started) * 1000));

            // Current structural migrations have explicit postconditions. Repair-only
            // and future migrations are recorded after successful execution rather
            // than pretending we can infer their prior state from unrelated tables.
            if ($this->hasKnownSignature($key) && !$this->migrationAlreadyPresent($key)) {
                throw new RuntimeException('Migration finished without its expected schema signature.');
            }

            $record = $this->pdo->prepare(
                'INSERT INTO schema_migrations
                 (migration_key,filename,checksum_sha256,statement_count,execution_ms,adopted_existing,applied_by,applied_at)
                 VALUES (?,?,?,?,?,0,?,NOW(6))'
            );
            $record->execute([
                $key, $migration['filename'], $migration['checksum'], $statements, $ms, $userId,
            ]);
            $this->pdo->prepare(
                "UPDATE upgrade_runs SET status='success',statement_count=?,execution_ms=?,completed_at=NOW(6) WHERE id=?"
            )->execute([$statements, $ms, $runId]);

            return [
                'key' => $key,
                'filename' => $migration['filename'],
                'statements' => $statements,
                'execution_ms' => $ms,
            ];
        } catch (Throwable $error) {
            $ms = max(0, (int)round((microtime(true) - $started) * 1000));
            try {
                $this->pdo->prepare(
                    "UPDATE upgrade_runs SET status='failed',execution_ms=?,error_message=?,completed_at=NOW(6) WHERE id=?"
                )->execute([$ms, mb_substr($error->getMessage(), 0, 65000), $runId]);
            } catch (Throwable) {
            }
            throw new RuntimeException(
                'Upgrade failed in ' . $migration['filename'] . ': ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    public function assertAppliedChecksumsUnchanged(): void
    {
        foreach ($this->migrationStatus() as $migration) {
            if ($migration['status'] === 'applied' && $migration['checksum_changed']) {
                throw new RuntimeException(
                    'Applied migration ' . $migration['filename'] . ' has changed since it was recorded. Restore the original migration before running another upgrade.'
                );
            }
        }
    }

    /**
     * Execute a MySQL/MariaDB script, including DELIMITER blocks used by
     * stored triggers. Comments and quoted strings are handled so delimiter
     * characters inside them do not split statements.
     */
    public static function executeSqlScript(PDO $pdo, string $sql, bool $allowAlreadyAppliedDdl = false): int
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $delimiter = ';';
        $statement = '';
        $quote = null;
        $escape = false;
        $blockComment = false;
        $count = 0;

        foreach (explode("\n", $sql) as $line) {
            if ($quote === null && !$blockComment && trim($statement) === ''
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
                $delimiter = $match[1];
                continue;
            }

            $lineComment = false;
            $line .= "\n";
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $ch = $line[$i];
                $next = $i + 1 < $length ? $line[$i + 1] : '';

                if ($lineComment) {
                    if ($ch === "\n") {
                        $lineComment = false;
                        $statement .= $ch;
                    }
                    continue;
                }
                if ($blockComment) {
                    if ($ch === '*' && $next === '/') {
                        $blockComment = false;
                        $i++;
                    }
                    continue;
                }

                if ($quote === null) {
                    if ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($line[$i + 2]))) {
                        $lineComment = true;
                        $i++;
                        continue;
                    }
                    if ($ch === '#') {
                        $lineComment = true;
                        continue;
                    }
                    if ($ch === '/' && $next === '*') {
                        $blockComment = true;
                        $i++;
                        continue;
                    }
                    if ($ch === "'" || $ch === '"' || $ch === '`') {
                        $quote = $ch;
                        $statement .= $ch;
                        continue;
                    }
                    if ($delimiter !== '' && substr($line, $i, strlen($delimiter)) === $delimiter) {
                        $trimmed = trim($statement);
                        if ($trimmed !== '') {
                            self::executeStatement($pdo, $trimmed, $allowAlreadyAppliedDdl);
                            $count++;
                        }
                        $statement = '';
                        $i += strlen($delimiter) - 1;
                        continue;
                    }
                    $statement .= $ch;
                    continue;
                }

                $statement .= $ch;
                if ($quote === '`') {
                    if ($ch === '`') $quote = null;
                    continue;
                }
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === $quote) {
                    if ($next === $quote) {
                        $statement .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
            }
        }

        $trimmed = trim($statement);
        if ($trimmed !== '') {
            self::executeStatement($pdo, $trimmed, $allowAlreadyAppliedDdl);
            $count++;
        }
        return $count;
    }

    private static function executeStatement(PDO $pdo, string $sql, bool $allowAlreadyAppliedDdl): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $error) {
            if ($allowAlreadyAppliedDdl && self::isAlreadyAppliedDdlError($error, $sql)) {
                return;
            }
            throw $error;
        }
    }

    private static function isAlreadyAppliedDdlError(PDOException $error, string $sql): bool
    {
        $normalized = strtoupper(ltrim($sql));
        if (!str_starts_with($normalized, 'ALTER TABLE') && !str_starts_with($normalized, 'CREATE TABLE')) {
            return false;
        }
        $driverCode = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;
        return in_array($driverCode, [1050, 1060, 1061, 1826], true);
    }

    private function hasKnownSignature(string $key): bool
    {
        return in_array($key, [
            '20260803_brand_images_llm_keys',
            '20260804_public_agent_knowledge_center',
            '20260804_jobs_module',
            '20260912_floor_planner',
            '20260912_equipment_catalog_brain',
            '20260912_canonical_floor_equipment',
        ], true);
    }

    private function migrationAlreadyPresent(string $key): bool
    {
        return match ($key) {
            '20260803_brand_images_llm_keys' =>
                $this->columnExists('brand_settings', 'cover_file_id')
                && $this->tableExists('llm_api_credentials'),

            '20260804_public_agent_knowledge_center' =>
                $this->tableExists('public_agent_settings')
                && $this->tableExists('knowledge_documents')
                && $this->tableExists('knowledge_document_chunks'),

            '20260804_jobs_module' =>
                $this->tableExists('jobs')
                && $this->columnExists('resume_submissions', 'job_id')
                && $this->permissionExists('jobs.publish'),

            '20260912_floor_planner' =>
                $this->tableExists('floor_plans')
                && $this->permissionExists('floorplans.view')
                && $this->permissionExists('floorplans.edit'),

            '20260912_equipment_catalog_brain' =>
                $this->tableExists('equipment_assets')
                && $this->tableExists('equipment_service_contacts')
                && $this->tableExists('equipment_service_events')
                && $this->tableExists('agent_knowledge_records')
                && $this->permissionExists('agent.equipment_skills'),

            '20260912_canonical_floor_equipment' =>
                $this->columnExists('equipment_assets', 'floor_plan_x_ft')
                && $this->columnExists('equipment_assets', 'floor_plan_y_ft')
                && $this->columnExists('equipment_assets', 'floor_plan_rotation_deg')
                && $this->columnExists('equipment_assets', 'floor_plan_z_index')
                && $this->columnExists('equipment_assets', 'floor_plan_locked')
                && $this->columnExists('equipment_assets', 'floor_plan_placed_at')
                && $this->triggerExists('trg_equipment_assets_canonical_insert')
                && $this->triggerExists('trg_equipment_assets_canonical_update')
                && $this->triggerExists('trg_floor_plans_canonical_archive'),

            default => false,
        };
    }

    private function isUnsafePartialMigration(string $key): bool
    {
        if ($key === '20260803_brand_images_llm_keys') {
            $parts = [
                $this->columnExists('brand_settings', 'cover_file_id'),
                $this->tableExists('llm_api_credentials'),
            ];
            return in_array(true, $parts, true) && in_array(false, $parts, true);
        }
        if ($key === '20260912_canonical_floor_equipment') {
            $parts = [
                $this->columnExists('equipment_assets', 'floor_plan_x_ft'),
                $this->columnExists('equipment_assets', 'floor_plan_y_ft'),
                $this->columnExists('equipment_assets', 'floor_plan_rotation_deg'),
                $this->columnExists('equipment_assets', 'floor_plan_z_index'),
                $this->columnExists('equipment_assets', 'floor_plan_locked'),
                $this->columnExists('equipment_assets', 'floor_plan_placed_at'),
            ];
            return in_array(true, $parts, true) && in_array(false, $parts, true);
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function triggerExists(string $trigger): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?'
        );
        $statement->execute([$trigger]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function permissionExists(string $permission): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key=?');
        $statement->execute([$permission]);
        return (int)$statement->fetchColumn() > 0;
    }
}
