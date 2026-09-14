<?php
declare(strict_types=1);

function location_contract_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/20261003_location_foundation.sql') ?: '';
$core = file_get_contents($root . '/includes/location-core.php') ?: '';
$admin = file_get_contents($root . '/locations-admin.php') ?: '';
$public = file_get_contents($root . '/locations.php') ?: '';
foreach (['public_slug','location_hours','is_primary','pickup_enabled','delivery_enabled','online_ordering_enabled','delivery_radius_miles','latitude','longitude','locations.manage','locations_seed_canonical_defaults'] as $needle) {
    location_contract_assert(str_contains($migration, $needle), 'Location migration missing: ' . $needle);
}
location_contract_assert(!str_contains($admin, 'DELETE FROM locations'), 'Location admin must archive rather than delete locations.');
location_contract_assert(str_contains($admin, 'location_archive'), 'Location admin must expose archive behavior.');
location_contract_assert(str_contains($public, 'location_set_public_selection'), 'Public location page must persist a validated location selection.');
location_contract_assert(str_contains($public, 'Use this location'), 'Public location page must expose location selection UI.');

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/location-core.php';
$pdo = app_pdo();

$columnCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='locations' AND column_name IN ('public_slug','is_primary','pickup_enabled','delivery_enabled','online_ordering_enabled','latitude','longitude')");
location_contract_assert((int)$columnCheck->fetchColumn() === 7, 'Location migration columns were not applied.');
location_contract_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='location_hours'")->fetchColumn() === 1, 'location_hours table is missing.');
location_contract_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='locations_seed_canonical_defaults'")->fetchColumn() === 1, 'Legacy location default trigger is missing.');
location_contract_assert((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('locations.view','locations.manage')")->fetchColumn() === 2, 'Location permissions are missing.');

$token = bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,legal_name,status,timezone) VALUES (?,?,'active','America/Phoenix')")->execute(['Location Contract ' . $token, 'Location Contract ' . $token]);
$organizationId = (int)$pdo->lastInsertId();

try {
    $hours = [];
    foreach (location_day_names() as $day => $_name) {
        $hours[$day] = $day === 0
            ? ['is_closed'=>true,'opens_at'=>'','closes_at'=>'']
            : ['is_closed'=>false,'opens_at'=>'11:00','closes_at'=>'21:00'];
    }
    $first = location_save($pdo,$organizationId,null,[
        'name'=>'Central Store','public_slug'=>'central-store','address_line_1'=>'100 Test Ave','city'=>'Phoenix','state'=>'AZ','postal_code'=>'85001','country_code'=>'US',
        'phone'=>'602-555-0100','email'=>'central@example.test','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,
        'delivery_enabled'=>true,'online_ordering_enabled'=>true,'delivery_radius_miles'=>'6.5','delivery_minimum'=>'20','delivery_fee'=>'3.5',
        'pickup_lead_minutes'=>20,'delivery_lead_minutes'=>45,'latitude'=>'33.4484','longitude'=>'-112.0740','hours'=>$hours,
    ]);
    location_contract_assert($first['is_primary'] === true, 'First active location must automatically become primary.');
    location_contract_assert($first['public_slug'] === 'central-store', 'Requested public slug was not preserved.');
    location_contract_assert(count($first['hours']) === 7, 'Weekly hours must persist seven day records.');
    location_contract_assert(str_contains($first['hoursText'], 'Mon 11:00 AM–9:00 PM'), 'Per-location hours text was not generated.');
    location_contract_assert(location_service_labels($first) === ['Dine In','Pickup','Delivery','Online Ordering'], 'Location service capabilities are incorrect.');

    // setup-first-user.php and older internal paths historically inserted only org/name/status.
    // The migration trigger must keep those inserts valid after public_slug becomes required.
    $pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$organizationId,'Legacy Insert']);
    $legacyId = (int)$pdo->lastInsertId();
    $legacy = location_get($pdo,$organizationId,$legacyId);
    location_contract_assert($legacy !== null && str_starts_with((string)$legacy['public_slug'],'location-'), 'Legacy location insert must receive a canonical public slug.');
    location_contract_assert($legacy['is_primary'] === false, 'Legacy insert must not create a second primary location.');
    location_archive($pdo,$organizationId,$legacyId,null);

    $second = location_save($pdo,$organizationId,null,[
        'name'=>'Central Store','public_slug'=>'central-store','address_line_1'=>'200 Test Ave','city'=>'Phoenix','state'=>'AZ','postal_code'=>'85002','country_code'=>'US',
        'timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,
        'pickup_lead_minutes'=>15,'delivery_lead_minutes'=>45,
    ]);
    location_contract_assert($second['public_slug'] === 'central-store-2', 'Duplicate public slugs must receive a deterministic suffix.');
    location_contract_assert($second['is_primary'] === false, 'Second location must not replace the primary unless requested.');

    location_set_primary($pdo,$organizationId,(int)$second['id'],null);
    $list = location_list($pdo,$organizationId,false);
    location_contract_assert((int)$list[0]['id'] === (int)$second['id'] && $list[0]['is_primary'] === true, 'Set-primary must make the selected active location the default.');

    $_COOKIE[location_public_selection_cookie_name()] = (string)$first['public_slug'];
    $selected = location_selected($list);
    location_contract_assert($selected !== null && (int)$selected['id'] === (int)$first['id'], 'Public selection cookie must override the primary fallback.');
    unset($_COOKIE[location_public_selection_cookie_name()]);

    location_archive($pdo,$organizationId,(int)$second['id'],null);
    $afterArchive = location_list($pdo,$organizationId,false);
    location_contract_assert(count($afterArchive) === 1 && (int)$afterArchive[0]['id'] === (int)$first['id'] && $afterArchive[0]['is_primary'] === true, 'Archiving the primary must reassign primary to another active location.');

    $blocked = false;
    try { location_archive($pdo,$organizationId,(int)$first['id'],null); } catch (RuntimeException $expected) { $blocked = str_contains($expected->getMessage(),'At least one active location'); }
    location_contract_assert($blocked, 'The final active location must not be archivable.');

    location_restore($pdo,$organizationId,(int)$second['id'],null);
    location_contract_assert(count(location_list($pdo,$organizationId,false)) === 2, 'Archived location must be restorable.');

    $invalid = false;
    try {
        location_save($pdo,$organizationId,null,['name'=>'Bad Online','pickup_enabled'=>false,'delivery_enabled'=>false,'online_ordering_enabled'=>true]);
    } catch (InvalidArgumentException $expected) { $invalid = true; }
    location_contract_assert($invalid, 'Online ordering cannot be enabled without pickup or delivery.');
} finally {
    $pdo->prepare('DELETE FROM location_hours WHERE organization_id=?')->execute([$organizationId]);
    $pdo->prepare('DELETE FROM locations WHERE organization_id=?')->execute([$organizationId]);
    $pdo->prepare('DELETE FROM audit_log WHERE organization_id=?')->execute([$organizationId]);
    $pdo->prepare('DELETE rp FROM role_permissions rp INNER JOIN roles r ON r.id=rp.role_id WHERE r.organization_id=?')->execute([$organizationId]);
    $pdo->prepare('DELETE FROM roles WHERE organization_id=?')->execute([$organizationId]);
    $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$organizationId]);
}

echo "location-foundation-contract-ok\n";
