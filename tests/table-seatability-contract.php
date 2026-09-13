<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-seatability.php';

$pdo=app_pdo();
function tsc(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function tsc_table(array $tables,string $publicId): ?array {foreach($tables as $table)if(($table['publicId']??null)===$publicId)return $table;return null;}
function tsc_has(array $availability,string $publicId): bool {foreach($availability as $candidate)if(in_array($publicId,(array)($candidate['tables']??[]),true))return true;return false;}

echo "table-seatability-scaffold\n";
