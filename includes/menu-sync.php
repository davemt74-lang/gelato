<?php
declare(strict_types=1);

/**
 * Gelato Spot menu source adapter.
 *
 * The public restaurant website currently consumes https://gelato.spot/api/v1/menu.
 * This adapter imports that same read-only REST payload into the local training database
 * and projects the database rows back into the MENU_SECTIONS shape used by the browser UI.
 */

function menu_source_settings(): array
{
    $config = app_config();
    $source = is_array($config['menu_source'] ?? null) ? $config['menu_source'] : [];
    return [
        'rest_url' => (string)($source['rest_url'] ?? 'https://gelato.spot/api/v1/menu'),
        'mcp_url' => (string)($source['mcp_url'] ?? 'https://gelato.spot/mcp'),
        'timeout_seconds' => max(5, min(60, (int)($source['timeout_seconds'] ?? 20))),
    ];
}

function menu_http_json(string $url, int $timeoutSeconds = 20): array
{
    $body = null;
    $status = 0;
    $userAgent = 'GelatoTrainingMenuSync/1.0 (+https://gelato.spot/developers/)';

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the menu-source HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $result = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($result)) {
            throw new RuntimeException('The live menu request failed' . ($error !== '' ? ': ' . $error : '.'));
        }
        $body = $result;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        if (!is_string($result)) {
            throw new RuntimeException('The live menu request failed. Enable cURL or HTTPS URL access for PHP.');
        }
        $body = $result;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                $status = (int)$matches[1];
            }
        }
    }

    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        throw new RuntimeException("The live menu endpoint returned HTTP {$status}.");
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The live menu endpoint returned invalid JSON.', 0, $exception);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('The live menu endpoint returned an unexpected payload.');
    }
    return $decoded;
}

function menu_slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'item';
    }
    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = strtolower($converted);
        } else {
            $value = strtolower($value);
        }
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 150) : 'item';
}

function menu_parse_price_entries(string $raw): array
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    if ($raw === '') {
        return [];
    }

    $segments = preg_split('/\s*[|·•;]\s*|\s+\/\s+(?=\$|[A-Za-z])/', $raw) ?: [$raw];
    $prices = [];
    $ordinal = 0;

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if (!preg_match_all('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/', $segment, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[1] as $matchIndex => $amountMatch) {
            $amountText = (string)$amountMatch[0];
            $fullMatch = $matches[0][$matchIndex] ?? null;
            if (!is_array($fullMatch)) {
                continue;
            }
            $token = (string)$fullMatch[0];
            $label = trim(str_replace($token, ' ', $segment));
            $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label, " \t\n\r\0\x0B-–—:,()[]");
            if ($label === '' || preg_match('/^and$/i', $label)) {
                $label = count($matches[1]) > 1 ? 'Option ' . ($matchIndex + 1) : 'Listed price';
            }
            $label = mb_substr($label, 0, 160, 'UTF-8');
            $amount = round((float)$amountText, 2);
            if ($amount < 0 || $amount > 100000) {
                continue;
            }
            $prices[] = [
                'label' => $label,
                'size_code' => menu_slugify($label),
                'amount' => $amount,
                'sort_order' => $ordinal++,
            ];
        }
    }

    if (!$prices && preg_match('/\b([0-9]+(?:\.[0-9]{1,2})?)\b/', $raw, $match)) {
        $prices[] = [
            'label' => 'Listed price',
            'size_code' => 'listed-price',
            'amount' => round((float)$match[1], 2),
            'sort_order' => 0,
        ];
    }
    return $prices;
}

function menu_guess_ingredients(string $description): array
{
    $description = trim(strip_tags($description));
    if ($description === '') {
        return [];
    }
    $description = preg_replace('/\([^)]*\)/', ' ', $description) ?? $description;
    $description = preg_replace('/\b(?:served|comes?)\s+with\b/i', ',', $description) ?? $description;
    $description = preg_replace('/\b(?:covered|topped|finished)\s+(?:in|with)\b/i', ',', $description) ?? $description;
    $description = preg_replace('/\b(?:choice of|add|additional|available)\b.*$/i', '', $description) ?? $description;
    $description = str_replace([' & ', ' + '], ', ', $description);
    $description = preg_replace('/\s+and\s+/i', ', ', $description) ?? $description;
    $parts = preg_split('/[,;]\s*/', $description) ?: [];
    $ingredients = [];
    foreach ($parts as $part) {
        $part = trim($part, " \t\n\r\0\x0B.-–—");
        $part = preg_replace('/^(?:with|and|plus)\s+/i', '', $part) ?? $part;
        $part = preg_replace('/^(?:freshly?|warm|ripe|marinated|roasted|aged|golden)\s+/i', '', $part) ?? $part;
        $part = trim($part);
        if ($part === '' || mb_strlen($part, 'UTF-8') < 2 || mb_strlen($part, 'UTF-8') > 80) {
            continue;
        }
        if (preg_match('/\$|\b(?:price|lunch|dinner|happy hour|available daily)\b/i', $part)) {
            continue;
        }
        $key = mb_strtolower($part, 'UTF-8');
        $ingredients[$key] = $part;
    }
    return array_values($ingredients);
}

function menu_normalize_source_payload(array $payload): array
{
    $version = trim((string)($payload['version'] ?? ''));
    $source = trim((string)($payload['source'] ?? ''));
    $sectionsInput = $payload['sections'] ?? null;
    if (!is_array($sectionsInput) || !$sectionsInput) {
        throw new RuntimeException('The menu payload does not contain any sections.');
    }

    $sections = [];
    $itemCount = 0;
    $seenSectionSlugs = [];
    $seenItemKeys = [];

    foreach ($sectionsInput as $sectionIndex => $section) {
        if (!is_array($section)) {
            throw new RuntimeException("Menu section {$sectionIndex} is invalid.");
        }
        $name = trim((string)($section['title'] ?? ''));
        $slug = trim((string)($section['slug'] ?? ''));
        $slug = $slug !== '' ? menu_slugify($slug) : menu_slugify($name);
        if ($name === '' || $slug === '') {
            throw new RuntimeException("Menu section {$sectionIndex} is missing a name or slug.");
        }
        if (isset($seenSectionSlugs[$slug])) {
            throw new RuntimeException("Duplicate menu section slug: {$slug}");
        }
        $seenSectionSlugs[$slug] = true;
        $itemsInput = $section['items'] ?? [];
        if (!is_array($itemsInput)) {
            throw new RuntimeException("Menu section {$name} has an invalid item list.");
        }
        $normalizedItems = [];
        foreach ($itemsInput as $itemIndex => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Menu item {$itemIndex} in {$name} is invalid.");
            }
            $itemName = trim((string)($item['name'] ?? ''));
            if ($itemName === '') {
                throw new RuntimeException("Menu item {$itemIndex} in {$name} is missing a name.");
            }
            $itemSlug = $slug . '--' . menu_slugify($itemName);
            if (isset($seenItemKeys[$itemSlug])) {
                $itemSlug .= '-' . ($itemIndex + 1);
            }
            $seenItemKeys[$itemSlug] = true;
            $description = trim((string)($item['description'] ?? ''));
            $rawPrice = trim((string)($item['price'] ?? ''));
            $tags = array_values(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                is_array($item['tags'] ?? null) ? $item['tags'] : []
            ), static fn(string $value): bool => $value !== ''));
            $metadata = [
                'source' => 'gelato.spot',
                'sourceVersion' => $version,
                'sourceUrl' => trim((string)($item['url'] ?? '')),
                'imageUrl' => trim((string)($item['imageUrl'] ?? '')),
                'rawPrice' => $rawPrice,
                'featured' => !empty($item['featured']),
                'tags' => $tags,
            ];
            $normalizedItems[] = [
                'name' => $itemName,
                'slug' => $itemSlug,
                'description' => $description,
                'prices' => menu_parse_price_entries($rawPrice),
                'ingredients' => menu_guess_ingredients($description),
                'metadata' => $metadata,
            ];
            $itemCount++;
        }
        $sections[] = [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string)($section['note'] ?? '')),
            'status' => trim((string)($section['status'] ?? 'active')) ?: 'active',
            'sort_order' => (int)$sectionIndex,
            'items' => $normalizedItems,
        ];
    }

    return [
        'version' => $version !== '' ? $version : 'unknown',
        'source' => $source !== '' ? $source : 'unknown',
        'sections' => $sections,
        'section_count' => count($sections),
        'item_count' => $itemCount,
        'fetched_at' => gmdate('c'),
    ];
}

function menu_resolve_organization_id(PDO $pdo, ?int $organizationId = null): int
{
    if ($organizationId !== null && $organizationId > 0) {
        $statement = $pdo->prepare("SELECT id FROM organizations WHERE id = ? AND status = 'active' LIMIT 1");
        $statement->execute([$organizationId]);
        if ((int)$statement->fetchColumn() === $organizationId) {
            return $organizationId;
        }
        throw new RuntimeException('The requested organization was not found or is inactive.');
    }

    $ids = $pdo->query("SELECT id FROM organizations WHERE status = 'active' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        return (int)$ids[0];
    }
    if (!$ids) {
        throw new RuntimeException('Create the first organization/owner before syncing the menu.');
    }
    throw new RuntimeException('Multiple active organizations exist. Supply --organization-id explicitly.');
}

function menu_sync_database(PDO $pdo, int $organizationId, array $normalized, ?int $actorUserId = null): array
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE menu_sections SET status = 'inactive' WHERE organization_id = ?")->execute([$organizationId]);
        $pdo->prepare("UPDATE menu_items SET is_active = 0 WHERE organization_id = ?")->execute([$organizationId]);

        $upsertSection = $pdo->prepare(
            "INSERT INTO menu_sections (organization_id, name, slug, description, sort_order, status)
             VALUES (:organization_id, :name, :slug, :description, :sort_order, 'active')
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
               sort_order = VALUES(sort_order), status = 'active'"
        );
        $findSection = $pdo->prepare('SELECT id FROM menu_sections WHERE organization_id = ? AND slug = ? LIMIT 1');

        $upsertItem = $pdo->prepare(
            "INSERT INTO menu_items
             (organization_id, section_id, name, slug, description, preparation_notes, behavior_tags_json, is_active, version)
             VALUES (:organization_id, :section_id, :name, :slug, :description, NULL, :metadata, 1, 1)
             ON DUPLICATE KEY UPDATE section_id = VALUES(section_id), name = VALUES(name),
               description = VALUES(description), behavior_tags_json = VALUES(behavior_tags_json),
               is_active = 1, version = version + 1"
        );
        $findItem = $pdo->prepare('SELECT id FROM menu_items WHERE organization_id = ? AND slug = ? LIMIT 1');
        $deletePrices = $pdo->prepare('DELETE FROM menu_item_prices WHERE menu_item_id = ?');
        $insertPrice = $pdo->prepare(
            "INSERT INTO menu_item_prices (menu_item_id, option_name, size_code, amount, currency, sort_order)
             VALUES (?, ?, ?, ?, 'USD', ?)"
        );
        $deleteIngredients = $pdo->prepare('DELETE FROM menu_item_ingredients WHERE menu_item_id = ?');
        $upsertIngredient = $pdo->prepare(
            "INSERT INTO ingredients (organization_id, canonical_name, slug, category, verification_status)
             VALUES (?, ?, ?, 'menu-description', 'unverified')
             ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)"
        );
        $findIngredient = $pdo->prepare('SELECT id FROM ingredients WHERE organization_id = ? AND slug = ? LIMIT 1');
        $linkIngredient = $pdo->prepare(
            'INSERT INTO menu_item_ingredients (menu_item_id, ingredient_id, display_name, is_optional, can_remove, sort_order) VALUES (?, ?, ?, 0, 1, ?)'
        );

        $sectionCount = 0;
        $itemCount = 0;
        $priceCount = 0;
        $ingredientLinkCount = 0;

        foreach ($normalized['sections'] as $section) {
            $upsertSection->execute([
                'organization_id' => $organizationId,
                'name' => $section['name'],
                'slug' => $section['slug'],
                'description' => $section['description'] !== '' ? $section['description'] : null,
                'sort_order' => $section['sort_order'],
            ]);
            $findSection->execute([$organizationId, $section['slug']]);
            $sectionId = (int)$findSection->fetchColumn();
            if ($sectionId < 1) {
                throw new RuntimeException('Unable to resolve imported menu section ' . $section['name']);
            }
            $sectionCount++;

            foreach ($section['items'] as $item) {
                $metadataJson = json_encode($item['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $upsertItem->execute([
                    'organization_id' => $organizationId,
                    'section_id' => $sectionId,
                    'name' => $item['name'],
                    'slug' => $item['slug'],
                    'description' => $item['description'] !== '' ? $item['description'] : null,
                    'metadata' => $metadataJson,
                ]);
                $findItem->execute([$organizationId, $item['slug']]);
                $itemId = (int)$findItem->fetchColumn();
                if ($itemId < 1) {
                    throw new RuntimeException('Unable to resolve imported menu item ' . $item['name']);
                }
                $itemCount++;

                $deletePrices->execute([$itemId]);
                foreach ($item['prices'] as $price) {
                    $insertPrice->execute([$itemId, $price['label'], $price['size_code'], $price['amount'], $price['sort_order']]);
                    $priceCount++;
                }

                $deleteIngredients->execute([$itemId]);
                foreach ($item['ingredients'] as $sortOrder => $ingredientName) {
                    $ingredientSlug = menu_slugify($ingredientName);
                    $upsertIngredient->execute([$organizationId, $ingredientName, $ingredientSlug]);
                    $findIngredient->execute([$organizationId, $ingredientSlug]);
                    $ingredientId = (int)$findIngredient->fetchColumn();
                    if ($ingredientId > 0) {
                        $linkIngredient->execute([$itemId, $ingredientId, $ingredientName, $sortOrder]);
                        $ingredientLinkCount++;
                    }
                }
            }
        }

        $summary = [
            'source' => 'gelato.spot',
            'sourceVersion' => $normalized['version'],
            'sections' => $sectionCount,
            'items' => $itemCount,
            'prices' => $priceCount,
            'ingredientLinks' => $ingredientLinkCount,
            'syncedAt' => gmdate('c'),
        ];

        if (function_exists('app_audit')) {
            try {
                app_audit($pdo, $organizationId, $actorUserId, 'menu.synced', 'menu_source', 'gelato.spot', null, $summary);
            } catch (Throwable) {
                // The menu data itself should not fail because optional audit/notification tables are unavailable.
            }
        }

        $pdo->commit();
        return $summary;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function menu_section_icon(string $slug): string
{
    return match ($slug) {
        'appetizers' => '◌',
        'panini' => '▤',
        'wood-fired-pizza' => '◉',
        'salads-and-pasta' => '◒',
        'gelato' => '◇',
        'beverages' => '◍',
        'beer-wine-and-cocktails' => '◫',
        'coffee' => '☕',
        'happy-hour' => '★',
        default => '•',
    };
}

function menu_database_sections(PDO $pdo, int $organizationId): array
{
    $sectionStatement = $pdo->prepare(
        "SELECT id, name, slug, description, sort_order
         FROM menu_sections
         WHERE organization_id = ? AND status = 'active'
         ORDER BY sort_order ASC, id ASC"
    );
    $sectionStatement->execute([$organizationId]);
    $sections = $sectionStatement->fetchAll();
    if (!$sections) {
        return [];
    }

    $itemStatement = $pdo->prepare(
        "SELECT id, section_id, name, slug, description, behavior_tags_json
         FROM menu_items
         WHERE organization_id = ? AND is_active = 1
         ORDER BY section_id ASC, id ASC"
    );
    $itemStatement->execute([$organizationId]);
    $items = $itemStatement->fetchAll();

    $priceStatement = $pdo->prepare(
        "SELECT p.menu_item_id, p.option_name, p.amount, p.sort_order
         FROM menu_item_prices p
         INNER JOIN menu_items i ON i.id = p.menu_item_id
         WHERE i.organization_id = ? AND i.is_active = 1
         ORDER BY p.menu_item_id ASC, p.sort_order ASC, p.id ASC"
    );
    $priceStatement->execute([$organizationId]);
    $pricesByItem = [];
    foreach ($priceStatement->fetchAll() as $row) {
        $pricesByItem[(int)$row['menu_item_id']][] = [
            'label' => (string)$row['option_name'],
            'price' => (float)$row['amount'],
        ];
    }

    $ingredientStatement = $pdo->prepare(
        "SELECT mi.menu_item_id, COALESCE(mi.display_name, ing.canonical_name) AS ingredient_name, mi.sort_order
         FROM menu_item_ingredients mi
         INNER JOIN ingredients ing ON ing.id = mi.ingredient_id
         INNER JOIN menu_items item ON item.id = mi.menu_item_id
         WHERE item.organization_id = ? AND item.is_active = 1
         ORDER BY mi.menu_item_id ASC, mi.sort_order ASC"
    );
    $ingredientStatement->execute([$organizationId]);
    $ingredientsByItem = [];
    foreach ($ingredientStatement->fetchAll() as $row) {
        $ingredientsByItem[(int)$row['menu_item_id']][] = (string)$row['ingredient_name'];
    }

    $itemsBySection = [];
    foreach ($items as $item) {
        $metadata = [];
        if (!empty($item['behavior_tags_json'])) {
            try {
                $decoded = json_decode((string)$item['behavior_tags_json'], true, 512, JSON_THROW_ON_ERROR);
                $metadata = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $metadata = [];
            }
        }
        $itemId = (int)$item['id'];
        $tags = array_values(array_filter(array_map('strval', is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [])));
        $facts = [];
        if (!empty($metadata['featured'])) {
            $facts[] = 'Featured on the current Gelato Spot menu';
        }
        if (!empty($metadata['rawPrice']) && empty($pricesByItem[$itemId])) {
            $facts[] = 'Listed price: ' . (string)$metadata['rawPrice'];
        }
        $itemsBySection[(int)$item['section_id']][] = [
            'name' => (string)$item['name'],
            'description' => (string)($item['description'] ?? ''),
            'ingredients' => $ingredientsByItem[$itemId] ?? [],
            'prices' => $pricesByItem[$itemId] ?? [],
            'facts' => $facts,
            'tags' => $tags,
            'sourceUrl' => (string)($metadata['sourceUrl'] ?? ''),
            'imageUrl' => (string)($metadata['imageUrl'] ?? ''),
            'rawPrice' => (string)($metadata['rawPrice'] ?? ''),
            'featured' => !empty($metadata['featured']),
        ];
    }

    $result = [];
    foreach ($sections as $section) {
        $sectionId = (int)$section['id'];
        $result[] = [
            'id' => (string)$section['slug'],
            'name' => (string)$section['name'],
            'icon' => menu_section_icon((string)$section['slug']),
            'intro' => (string)($section['description'] ?? ''),
            'facts' => [],
            'items' => $itemsBySection[$sectionId] ?? [],
        ];
    }
    return $result;
}

function menu_database_notes(array $sections): array
{
    $notes = [[
        'id' => 'allergen-boundary',
        'type' => 'allergen',
        'sectionId' => 'global',
        'title' => 'Never promise an item is allergy-safe',
        'summary' => 'Menu descriptions and tags are training references, not an allergy-safety guarantee.',
        'detail' => 'Escalate allergy questions and verify current recipes, supplier labels, substitutions, and cross-contact controls before answering.',
        'items' => ['All menu categories'],
        'priority' => 1,
    ]];
    foreach ($sections as $section) {
        if (trim((string)($section['intro'] ?? '')) === '') {
            continue;
        }
        $notes[] = [
            'id' => 'section-' . $section['id'],
            'type' => 'service',
            'sectionId' => $section['id'],
            'title' => $section['name'] . ' menu note',
            'summary' => $section['intro'],
            'detail' => 'This note comes from the current Gelato Spot menu source.',
            'items' => [$section['name']],
            'priority' => 2,
        ];
    }
    return $notes;
}
