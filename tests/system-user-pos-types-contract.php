<?php
declare(strict_types=1);

function system_types_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/20261001_system_user_types_pos_order_types.sql');
$core = file_get_contents($root . '/includes/pos-core.php');
$pos = file_get_contents($root . '/pos.php');

if ($migration === false || $core === false || $pos === false) {
    throw new RuntimeException('Unable to read system user / POS order type sources.');
}

foreach (['Customer' => 'customer', 'Service' => 'service', 'Driver' => 'driver'] as $name => $slug) {
    system_types_assert(str_contains($migration, "'{$name}', '{$slug}'"), "Migration must seed {$name} ({$slug}) for existing organizations.");
}
system_types_assert(str_contains($migration, 'CREATE TRIGGER organizations_seed_operational_user_types'), 'Future organizations must automatically receive the new system user types.');
system_types_assert(str_contains($migration, "(NEW.id, 'Customer', 'customer'"), 'Future organizations must receive Customer.');
system_types_assert(str_contains($migration, "(NEW.id, 'Service', 'service'"), 'Future organizations must receive Service.');
system_types_assert(str_contains($migration, "(NEW.id, 'Driver', 'driver'"), 'Future organizations must receive Driver.');

system_types_assert(str_contains($migration, "WHEN 'bar' THEN 'dine_in'"), 'Legacy bar orders must normalize to dine_in.');
system_types_assert(str_contains($migration, "WHEN 'takeout' THEN 'pickup'"), 'Legacy takeout orders must normalize to pickup.');
system_types_assert(str_contains($core, "['dine_in','delivery','pickup']"), 'POS core must use only canonical dine_in, delivery, pickup order types.');
system_types_assert(str_contains($core, "'bar' => 'dine_in'"), 'POS core must accept legacy bar as dine_in during transition.');
system_types_assert(str_contains($core, "'takeout' => 'pickup'"), 'POS core must accept legacy takeout as pickup during transition.');
system_types_assert(str_contains($pos, '<span>Order type</span>'), 'POS create-check UI must call the field Order type.');
system_types_assert(str_contains($pos, '<option value="dine_in">Dine in</option>'), 'POS must offer Dine in.');
system_types_assert(str_contains($pos, '<option value="delivery">Delivery</option>'), 'POS must offer Delivery.');
system_types_assert(str_contains($pos, '<option value="pickup">Pickup</option>'), 'POS must offer Pickup.');
system_types_assert(!str_contains($pos, '<option value="bar">Bar</option>'), 'POS must not expose Bar as an order type.');
system_types_assert(!str_contains($pos, '<option value="takeout">Takeout</option>'), 'POS must not expose Takeout as an order type.');
system_types_assert(str_contains($pos, '<span>Default order type</span>'), 'POS settings must use Default order type wording.');

echo "system-user-pos-types-contract-ok\n";
