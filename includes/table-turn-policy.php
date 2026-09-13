<?php
declare(strict_types=1);

require_once __DIR__.'/host-stand-core.php';

function table_turn_policy_ready(PDO $pdo): bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='table_turn_policies'");
    return (int)$q->fetchColumn()===1;
}

function table_turn_policy_defaults(): array
{
    return ['resetTargetMinutes'=>15,'readyBufferMinutes'=>10,'urgentThresholdMinutes'=>10,'configured'=>false];
}

function table_turn_policy(PDO $pdo,int $org,int $locationId): array
{
    table_service_location($pdo,$org,$locationId);
    $default=table_turn_policy_defaults();
    if(!table_turn_policy_ready($pdo))return $default;
    $q=$pdo->prepare('SELECT reset_target_minutes,ready_buffer_minutes,urgent_threshold_minutes,created_at,updated_at FROM table_turn_policies WHERE organization_id=? AND location_id=? LIMIT 1');
    $q->execute([$org,$locationId]);$r=$q->fetch();
    if(!$r)return $default;
    return [
        'resetTargetMinutes'=>(int)$r['reset_target_minutes'],
        'readyBufferMinutes'=>(int)$r['ready_buffer_minutes'],
        'urgentThresholdMinutes'=>(int)$r['urgent_threshold_minutes'],
        'configured'=>true,
        'createdAt'=>(string)$r['created_at'],
        'updatedAt'=>(string)$r['updated_at'],
    ];
}

function table_turn_policy_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    if(!table_turn_policy_ready($pdo))throw new RuntimeException('Table turn policy migration is not installed. Run upgrade.php.');
    table_service_location($pdo,$org,$locationId);
    $reset=(int)($input['resetTargetMinutes']??15);
    $buffer=(int)($input['readyBufferMinutes']??10);
    $urgent=(int)($input['urgentThresholdMinutes']??10);
    if($reset<1||$reset>120)throw new InvalidArgumentException('Reset target must be between 1 and 120 minutes.');
    if($buffer<0||$buffer>60)throw new InvalidArgumentException('Ready buffer must be between 0 and 60 minutes.');
    if($urgent<1||$urgent>120)throw new InvalidArgumentException('Urgent threshold must be between 1 and 120 minutes.');
    $pdo->prepare("INSERT INTO table_turn_policies (organization_id,location_id,reset_target_minutes,ready_buffer_minutes,urgent_threshold_minutes,created_by,updated_by)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE reset_target_minutes=VALUES(reset_target_minutes),ready_buffer_minutes=VALUES(ready_buffer_minutes),urgent_threshold_minutes=VALUES(urgent_threshold_minutes),updated_by=VALUES(updated_by),updated_at=NOW(6)")
        ->execute([$org,$locationId,$reset,$buffer,$urgent,$userId,$userId]);
    return table_turn_policy($pdo,$org,$locationId);
}

function table_turn_reset_minutes(?PDO $pdo=null,?int $org=null,?int $locationId=null): int
{
    if(!$pdo||!$org||!$locationId)return 15;
    return (int)table_turn_policy($pdo,$org,$locationId)['resetTargetMinutes'];
}

function table_turn_ready_buffer_minutes(?PDO $pdo=null,?int $org=null,?int $locationId=null): int
{
    if(!$pdo||!$org||!$locationId)return 10;
    return (int)table_turn_policy($pdo,$org,$locationId)['readyBufferMinutes'];
}
