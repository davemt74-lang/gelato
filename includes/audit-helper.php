<?php
declare(strict_types=1);

function audit_marker(): string
{
    return 'operational-audit';
}

function audit_db_wrap(PDO $pdo, callable $callback): mixed
{
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }
    try {
        $result = $callback();
        if ($started) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
