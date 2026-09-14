<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function location_day_names(): array
{
    return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
}

function location_slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'location';
    }
    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($converted) && $converted !== '' ? strtolower($converted) : strtolower($value);
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';
    return substr(trim($value, '-'), 0, 150) ?: 'location';
}

function location_format_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime('2000-01-01 ' . $value);
    return $timestamp === false ? $value : date('g:i A', $timestamp);
}

function location_hours_text(array $hours): string
{
    if (!$hours) {
        return '';
    }
    $byDay = [];
    foreach ($hours as $row) {
        $byDay[(int)($row['day_of_week'] ?? -1)][] = $row;
    }
    $lines = [];
    foreach (location_day_names() as $day => $name) {
        $rows = $byDay[$day] ?? [];
        if (!$rows) {
            continue;
        }
        $parts = [];
        foreach ($rows as $row) {
            if (!empty($row['is_closed'])) {
                $parts[] = 'Closed';
                continue;
            }
            $open = location_format_time($row['opens_at'] ?? null);
            $close = location_format_time($row['closes_at'] ?? null);
            if ($open !== '' && $close !== '') {
                $parts[] = $open . '–' . $close;
            }
        }
        if ($parts) {
            $lines[] = substr($name, 0, 3) . ' ' . implode(', ', $parts);
        }
    }
    return implode("\n", $lines);
}

function location_list(PDO $pdo, int $organizationId, bool $includeArchived = false): array
{
    $sql = "SELECT id, organization_id, name, public_slug, address_line_1, address_line_2, city, state,
                   postal_code, country_code, phone, email, timezone, status, is_primary, sort_order,
                   dine_in_enabled, pickup_enabled, delivery_enabled, online_ordering_enabled,
                   delivery_radius_miles, delivery_minimum, delivery_fee, pickup_lead_minutes,
                   delivery_lead_minutes, latitude, longitude, created_at, updated_at
            FROM locations
            WHERE organization_id = ?" . ($includeArchived ? '' : " AND status = 'active'") . "
            ORDER BY is_primary DESC, sort_order ASC, name ASC, id ASC";
    $statement = $pdo->prepare($sql);
    $statement->execute([$organizationId]);
    $locations = $statement->fetchAll() ?: [];
    if (!$locations) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $locations);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $hoursStatement = $pdo->prepare(
        "SELECT id, organization_id, location_id, day_of_week, slot_order, opens_at, closes_at, is_closed, label
         FROM location_hours
         WHERE organization_id = ? AND location_id IN ({$placeholders})
         ORDER BY day_of_week ASC, slot_order ASC, id ASC"
    );
    $hoursStatement->execute(array_merge([$organizationId], $ids));
    $hoursByLocation = [];
    foreach ($hoursStatement->fetchAll() as $row) {
        $hoursByLocation[(int)$row['location_id']][] = $row;
    }

    foreach ($locations as &$location) {
        $location['id'] = (int)$location['id'];
        $location['organization_id'] = (int)$location['organization_id'];
        foreach (['is_primary','dine_in_enabled','pickup_enabled','delivery_enabled','online_ordering_enabled'] as $key) {
            $location[$key] = (bool)$location[$key];
        }
        $location['sort_order'] = (int)$location['sort_order'];
        $location['pickup_lead_minutes'] = (int)$location['pickup_lead_minutes'];
        $location['delivery_lead_minutes'] = (int)$location['delivery_lead_minutes'];
        $location['hours'] = $hoursByLocation[(int)$location['id']] ?? [];
        $location['hoursText'] = location_hours_text($location['hours']);
    }
    unset($location);
    return $locations;
}

function location_get(PDO $pdo, int $organizationId, int $locationId): ?array
{
    foreach (location_list($pdo, $organizationId, true) as $location) {
        if ((int)$location['id'] === $locationId) {
            return $location;
        }
    }
    return null;
}

function location_decimal(array $payload, string $key, float $minimum, float $maximum): ?float
{
    $raw = trim((string)($payload[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must be a number.');
    }
    $value = (float)$raw;
    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . " must be between {$minimum} and {$maximum}.");
    }
    return round($value, 7);
}

function location_unique_slug(PDO $pdo, int $organizationId, string $requested, int $excludeId = 0): string
{
    $base = location_slugify($requested);
    $candidate = $base;
    $suffix = 2;
    $statement = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE organization_id = ? AND public_slug = ? AND id <> ?');
    while (true) {
        $statement->execute([$organizationId, $candidate, $excludeId]);
        if ((int)$statement->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = substr($base, 0, 145) . '-' . $suffix++;
    }
}

function location_normalize_hours(array $hours): array
{
    $result = [];
    foreach (location_day_names() as $day => $_name) {
        $source = is_array($hours[$day] ?? null) ? $hours[$day] : [];
        $closed = !empty($source['is_closed']) || !empty($source['closed']);
        $open = trim((string)($source['opens_at'] ?? ''));
        $close = trim((string)($source['closes_at'] ?? ''));
        if (!$closed) {
            if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $open) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $close)) {
                throw new InvalidArgumentException(location_day_names()[$day] . ' needs both an opening and closing time, or mark the day closed.');
            }
            if (substr($open, 0, 5) === substr($close, 0, 5)) {
                throw new InvalidArgumentException(location_day_names()[$day] . ' opening and closing times cannot be the same.');
            }
        }
        $result[$day] = [
            'day_of_week' => $day,
            'slot_order' => 0,
            'opens_at' => $closed ? null : substr($open, 0, 5),
            'closes_at' => $closed ? null : substr($close, 0, 5),
            'is_closed' => $closed ? 1 : 0,
            'label' => null,
        ];
    }
    return $result;
}

function location_save(PDO $pdo, int $organizationId, ?int $actorUserId, array $payload): array
{
    $locationId = max(0, (int)($payload['id'] ?? 0));
    $existing = $locationId > 0 ? location_get($pdo, $organizationId, $locationId) : null;
    if ($locationId > 0 && !$existing) {
        throw new RuntimeException('Location not found.');
    }

    $name = mb_substr(trim((string)($payload['name'] ?? '')), 0, 160, 'UTF-8');
    if ($name === '') {
        throw new InvalidArgumentException('Location name is required.');
    }
    $email = mb_substr(strtolower(trim((string)($payload['email'] ?? ''))), 0, 254, 'UTF-8');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid location email address.');
    }
    $timezone = mb_substr(trim((string)($payload['timezone'] ?? '')), 0, 64, 'UTF-8');
    if ($timezone !== '' && !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidArgumentException('Choose a valid timezone.');
    }
    $countryCode = strtoupper(substr(trim((string)($payload['country_code'] ?? 'US')), 0, 2));
    if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
        throw new InvalidArgumentException('Country code must contain two letters.');
    }

    $pickupEnabled = !empty($payload['pickup_enabled']);
    $deliveryEnabled = !empty($payload['delivery_enabled']);
    $onlineEnabled = !empty($payload['online_ordering_enabled']);
    if ($onlineEnabled && !$pickupEnabled && !$deliveryEnabled) {
        throw new InvalidArgumentException('Online ordering needs Pickup and/or Delivery enabled.');
    }

    $status = $existing ? (string)$existing['status'] : 'active';
    if (isset($payload['status']) && in_array((string)$payload['status'], ['active','archived'], true)) {
        $status = (string)$payload['status'];
    }
    $requestedPrimary = $status === 'active' && !empty($payload['is_primary']);
    $slug = location_unique_slug($pdo, $organizationId, (string)($payload['public_slug'] ?? $name), $locationId);
    $deliveryRadius = location_decimal($payload, 'delivery_radius_miles', 0, 200);
    $deliveryMinimum = location_decimal($payload, 'delivery_minimum', 0, 10000);
    $deliveryFee = location_decimal($payload, 'delivery_fee', 0, 10000);
    $latitude = location_decimal($payload, 'latitude', -90, 90);
    $longitude = location_decimal($payload, 'longitude', -180, 180);
    $pickupLead = max(0, min(1440, (int)($payload['pickup_lead_minutes'] ?? 20)));
    $deliveryLead = max(0, min(1440, (int)($payload['delivery_lead_minutes'] ?? 45)));
    $sortOrder = max(-100000, min(100000, (int)($payload['sort_order'] ?? 0)));
    $hours = isset($payload['hours']) && is_array($payload['hours']) ? location_normalize_hours($payload['hours']) : null;

    $values = [
        'name' => $name,
        'public_slug' => $slug,
        'address_line_1' => mb_substr(trim((string)($payload['address_line_1'] ?? '')), 0, 200, 'UTF-8'),
        'address_line_2' => mb_substr(trim((string)($payload['address_line_2'] ?? '')), 0, 200, 'UTF-8'),
        'city' => mb_substr(trim((string)($payload['city'] ?? '')), 0, 100, 'UTF-8'),
        'state' => mb_substr(trim((string)($payload['state'] ?? '')), 0, 100, 'UTF-8'),
        'postal_code' => mb_substr(trim((string)($payload['postal_code'] ?? '')), 0, 30, 'UTF-8'),
        'country_code' => $countryCode,
        'phone' => mb_substr(trim((string)($payload['phone'] ?? '')), 0, 40, 'UTF-8'),
        'email' => $email,
        'timezone' => $timezone !== '' ? $timezone : null,
        'status' => $status,
        'is_primary' => $requestedPrimary ? 1 : 0,
        'sort_order' => $sortOrder,
        'dine_in_enabled' => !empty($payload['dine_in_enabled']) ? 1 : 0,
        'pickup_enabled' => $pickupEnabled ? 1 : 0,
        'delivery_enabled' => $deliveryEnabled ? 1 : 0,
        'online_ordering_enabled' => $onlineEnabled ? 1 : 0,
        'delivery_radius_miles' => $deliveryRadius,
        'delivery_minimum' => $deliveryMinimum,
        'delivery_fee' => $deliveryFee,
        'pickup_lead_minutes' => $pickupLead,
        'delivery_lead_minutes' => $deliveryLead,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ];

    $pdo->beginTransaction();
    try {
        if ($requestedPrimary) {
            $pdo->prepare('UPDATE locations SET is_primary = 0 WHERE organization_id = ?')->execute([$organizationId]);
        }
        if ($existing) {
            $sql = "UPDATE locations SET
                    name=:name, public_slug=:public_slug, address_line_1=:address_line_1, address_line_2=:address_line_2,
                    city=:city, state=:state, postal_code=:postal_code, country_code=:country_code, phone=:phone,
                    email=:email, timezone=:timezone, status=:status, is_primary=:is_primary, sort_order=:sort_order,
                    dine_in_enabled=:dine_in_enabled, pickup_enabled=:pickup_enabled, delivery_enabled=:delivery_enabled,
                    online_ordering_enabled=:online_ordering_enabled, delivery_radius_miles=:delivery_radius_miles,
                    delivery_minimum=:delivery_minimum, delivery_fee=:delivery_fee, pickup_lead_minutes=:pickup_lead_minutes,
                    delivery_lead_minutes=:delivery_lead_minutes, latitude=:latitude, longitude=:longitude
                    WHERE organization_id=:organization_id AND id=:id";
            $statement = $pdo->prepare($sql);
            $statement->execute([...$values, 'organization_id' => $organizationId, 'id' => $locationId]);
        } else {
            $sql = "INSERT INTO locations
                    (organization_id,name,public_slug,address_line_1,address_line_2,city,state,postal_code,country_code,phone,email,timezone,status,is_primary,sort_order,
                     dine_in_enabled,pickup_enabled,delivery_enabled,online_ordering_enabled,delivery_radius_miles,delivery_minimum,delivery_fee,pickup_lead_minutes,delivery_lead_minutes,latitude,longitude)
                    VALUES
                    (:organization_id,:name,:public_slug,:address_line_1,:address_line_2,:city,:state,:postal_code,:country_code,:phone,:email,:timezone,:status,:is_primary,:sort_order,
                     :dine_in_enabled,:pickup_enabled,:delivery_enabled,:online_ordering_enabled,:delivery_radius_miles,:delivery_minimum,:delivery_fee,:pickup_lead_minutes,:delivery_lead_minutes,:latitude,:longitude)";
            $statement = $pdo->prepare($sql);
            $statement->execute(['organization_id' => $organizationId, ...$values]);
            $locationId = (int)$pdo->lastInsertId();
        }

        if ($status === 'active') {
            $primaryStatement = $pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND status='active' AND is_primary=1 ORDER BY id LIMIT 1");
            $primaryStatement->execute([$organizationId]);
            if (!(int)$primaryStatement->fetchColumn()) {
                $pdo->prepare('UPDATE locations SET is_primary=1 WHERE organization_id=? AND id=?')->execute([$organizationId, $locationId]);
            }
        }

        if ($hours !== null) {
            $pdo->prepare('DELETE FROM location_hours WHERE organization_id=? AND location_id=?')->execute([$organizationId, $locationId]);
            $insertHour = $pdo->prepare(
                'INSERT INTO location_hours (organization_id,location_id,day_of_week,slot_order,opens_at,closes_at,is_closed,label) VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($hours as $row) {
                $insertHour->execute([$organizationId,$locationId,$row['day_of_week'],$row['slot_order'],$row['opens_at'],$row['closes_at'],$row['is_closed'],$row['label']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $saved = location_get($pdo, $organizationId, $locationId);
    try {
        app_audit($pdo, $organizationId, $actorUserId, $existing ? 'location.updated' : 'location.created', 'location', (string)$locationId, $existing, $saved);
    } catch (Throwable) {
    }
    if (!$saved) {
        throw new RuntimeException('Location could not be reloaded after save.');
    }
    return $saved;
}

function location_set_primary(PDO $pdo, int $organizationId, int $locationId, ?int $actorUserId): void
{
    $location = location_get($pdo, $organizationId, $locationId);
    if (!$location || $location['status'] !== 'active') {
        throw new RuntimeException('Only an active location can be primary.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE locations SET is_primary=0 WHERE organization_id=?')->execute([$organizationId]);
        $pdo->prepare('UPDATE locations SET is_primary=1 WHERE organization_id=? AND id=?')->execute([$organizationId,$locationId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    try { app_audit($pdo,$organizationId,$actorUserId,'location.primary_changed','location',(string)$locationId,$location,location_get($pdo,$organizationId,$locationId)); } catch(Throwable) {}
}

function location_archive(PDO $pdo, int $organizationId, int $locationId, ?int $actorUserId): void
{
    $location = location_get($pdo, $organizationId, $locationId);
    if (!$location) throw new RuntimeException('Location not found.');
    if ($location['status'] === 'archived') return;
    $countStatement = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE organization_id=? AND status='active'");
    $countStatement->execute([$organizationId]);
    if ((int)$countStatement->fetchColumn() <= 1) {
        throw new RuntimeException('At least one active location must remain.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE locations SET status='archived', is_primary=0 WHERE organization_id=? AND id=?")->execute([$organizationId,$locationId]);
        if (!empty($location['is_primary'])) {
            $replacement = $pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND status='active' ORDER BY sort_order,name,id LIMIT 1");
            $replacement->execute([$organizationId]);
            $replacementId = (int)$replacement->fetchColumn();
            if ($replacementId > 0) {
                $pdo->prepare('UPDATE locations SET is_primary=1 WHERE organization_id=? AND id=?')->execute([$organizationId,$replacementId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    try { app_audit($pdo,$organizationId,$actorUserId,'location.archived','location',(string)$locationId,$location,location_get($pdo,$organizationId,$locationId)); } catch(Throwable) {}
}

function location_restore(PDO $pdo, int $organizationId, int $locationId, ?int $actorUserId): void
{
    $location = location_get($pdo, $organizationId, $locationId);
    if (!$location) throw new RuntimeException('Location not found.');
    $pdo->prepare("UPDATE locations SET status='active' WHERE organization_id=? AND id=?")->execute([$organizationId,$locationId]);
    $primary = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE organization_id=? AND status='active' AND is_primary=1");
    $primary->execute([$organizationId]);
    if ((int)$primary->fetchColumn() === 0) {
        $pdo->prepare('UPDATE locations SET is_primary=1 WHERE organization_id=? AND id=?')->execute([$organizationId,$locationId]);
    }
    try { app_audit($pdo,$organizationId,$actorUserId,'location.restored','location',(string)$locationId,$location,location_get($pdo,$organizationId,$locationId)); } catch(Throwable) {}
}

function location_selected(array $locations): ?array
{
    if (!$locations) return null;
    $wanted = trim((string)($_COOKIE[location_public_selection_cookie_name()] ?? ''));
    if ($wanted !== '') {
        foreach ($locations as $location) {
            if (($location['status'] ?? 'active') === 'active' && hash_equals((string)$location['public_slug'], $wanted)) return $location;
        }
    }
    foreach ($locations as $location) {
        if (($location['status'] ?? 'active') === 'active' && !empty($location['is_primary'])) return $location;
    }
    foreach ($locations as $location) {
        if (($location['status'] ?? 'active') === 'active') return $location;
    }
    return null;
}

function location_public_selection_cookie_name(): string
{
    return 'stonefellows_location';
}

function location_set_public_selection(array $location): void
{
    if (($location['status'] ?? '') !== 'active' || empty($location['public_slug'])) {
        throw new RuntimeException('That location is not available.');
    }
    $security = app_config()['security'] ?? [];
    setcookie(location_public_selection_cookie_name(), (string)$location['public_slug'], [
        'expires' => time() + 60 * 60 * 24 * 60,
        'path' => '/',
        'secure' => (bool)($security['cookie_secure'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[location_public_selection_cookie_name()] = (string)$location['public_slug'];
}

function location_service_labels(array $location): array
{
    $labels = [];
    if (!empty($location['dine_in_enabled'])) $labels[] = 'Dine In';
    if (!empty($location['pickup_enabled'])) $labels[] = 'Pickup';
    if (!empty($location['delivery_enabled'])) $labels[] = 'Delivery';
    if (!empty($location['online_ordering_enabled'])) $labels[] = 'Online Ordering';
    return $labels;
}
